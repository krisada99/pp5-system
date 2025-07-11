<?php
// /api/activities.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'teacher' หรือไม่
// ฟังก์ชัน checkLogin() จะอยู่ในไฟล์ database.php
checkLogin(['teacher']); 

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อฐานข้อมูล
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$user_id = $_SESSION['user']['id'];

// 4. แยกการทำงานตาม HTTP Method ที่ร้องขอมา (POST)
if ($method === 'POST') {
    $action = $input['action'] ?? null;

    switch ($action) {
        // --- CASE 1: บันทึก/อัปเดตรายการกิจกรรม ---
        case 'saveComponent':
            // ตรวจสอบว่ามีข้อมูลที่จำเป็นครบหรือไม่
            if (!isset($input['componentName'], $input['classLevel'], $input['classroom'])) {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
                exit;
            }

            $id = $input['id'] ?? null;
            $componentName = trim($input['componentName']);
            $classLevel = $input['classLevel'];
            $classroom = $input['classroom'];

            // --- Business Logic: ตรวจสอบว่ามีกิจกรรมเกิน 4 รายการหรือยัง (เฉพาะตอนเพิ่มใหม่) ---
            if (!$id) { // ถ้าเป็นการเพิ่มใหม่ (ไม่มี id ส่งมา)
                $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM activity_components WHERE class_level = ? AND classroom = ?");
                $stmt_check->bind_param("ss", $classLevel, $classroom);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result()->fetch_assoc();
                if ($result_check['count'] >= 4) {
                    http_response_code(400); // Bad Request
                    echo json_encode(['success' => false, 'message' => 'สามารถเพิ่มรายการกิจกรรมได้สูงสุด 4 รายการต่อห้องเรียนเท่านั้น']);
                    exit;
                }
                $stmt_check->close();
            }


            if ($id) { // ถ้ามี ID หมายถึงการ "อัปเดต"
                $stmt = $conn->prepare("UPDATE activity_components SET component_name = ? WHERE id = ? AND teacher_id = ?");
                $stmt->bind_param("sii", $componentName, $id, $user_id);
            } else { // ถ้าไม่มี ID หมายถึงการ "เพิ่มใหม่"
                $stmt = $conn->prepare("INSERT INTO activity_components (component_name, class_level, classroom, teacher_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $componentName, $classLevel, $classroom, $user_id);
            }

            if ($stmt->execute()) {
                $new_id = $id ?: $conn->insert_id;
                echo json_encode(['success' => true, 'message' => 'บันทึกรายการกิจกรรมสำเร็จ', 'id' => $new_id]);
            } else {
                http_response_code(500); // Internal Server Error
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกรายการกิจกรรมได้: ' . $stmt->error]);
            }
            $stmt->close();
            break;

        // --- CASE 2: ลบรายการกิจกรรม ---
        case 'deleteComponent':
            $id = $input['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ไม่ได้ระบุ ID ของรายการกิจกรรม']);
                exit;
            }

            // เริ่ม Transaction เพื่อให้แน่ใจว่าลบข้อมูลทั้ง 2 ตารางพร้อมกัน
            $conn->begin_transaction();
            try {
                // ลบผลการประเมินที่เกี่ยวข้องก่อน
                $stmt1 = $conn->prepare("DELETE FROM activity_scores WHERE component_id = ?");
                $stmt1->bind_param("i", $id);
                $stmt1->execute();
                $stmt1->close();

                // ลบรายการกิจกรรมหลัก
                $stmt2 = $conn->prepare("DELETE FROM activity_components WHERE id = ? AND teacher_id = ?");
                $stmt2->bind_param("ii", $id, $user_id);
                $stmt2->execute();
                $stmt2->close();
                
                // ถ้าทุกอย่างสำเร็จ
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'ลบรายการกิจกรรมและผลประเมินที่เกี่ยวข้องสำเร็จ']);

            } catch (mysqli_sql_exception $exception) {
                $conn->rollback(); // ถ้ามีข้อผิดพลาด ให้ย้อนกลับ
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'การลบข้อมูลล้มเหลว: ' . $exception->getMessage()]);
            }
            break;

        // --- CASE 3: บันทึกผลการประเมินของนักเรียน ---
        case 'saveScores':
            $scores = $input['scores'] ?? [];
            if (empty($scores)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลผลการประเมินให้บันทึก']);
                exit;
            }

            // ใช้ INSERT ... ON DUPLICATE KEY UPDATE เพื่อเพิ่มหรืออัปเดตข้อมูลในครั้งเดียว
            $stmt = $conn->prepare("
                INSERT INTO activity_scores (student_id, component_id, teacher_id, result) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE result = VALUES(result), teacher_id = VALUES(teacher_id)
            ");

            $count = 0;
            $savedRecords = [];
            foreach ($scores as $score) {
                // ตรวจสอบข้อมูลก่อนบันทึก
                if (isset($score['studentId'], $score['componentId'], $score['result']) && in_array($score['result'], ['ผ่าน', 'ไม่ผ่าน'])) {
                    $stmt->bind_param("iiss", $score['studentId'], $score['componentId'], $user_id, $score['result']);
                    if($stmt->execute()){
                        $count++;
                        // ส่งข้อมูลที่บันทึกสำเร็จกลับไปเพื่ออัปเดต State ฝั่ง Client
                        $savedRecords[] = [
                            'studentId' => $score['studentId'],
                            'componentId' => $score['componentId'],
                            'result' => $score['result']
                        ];
                    }
                }
            }
            
            if ($count > 0) {
                echo json_encode(['success' => true, 'count' => $count, 'message' => "บันทึกผลการประเมิน {$count} รายการสำเร็จ", 'savedRecords' => $savedRecords]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกผลการประเมินได้ หรือข้อมูลไม่ถูกต้อง']);
            }
            $stmt->close();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action ที่ร้องขอไม่ถูกต้อง']);
            break;
    }
} else {
    // ถ้าไม่ใช่ POST request
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method ที่ร้องขอไม่ได้รับอนุญาต']);
}

// 5. ปิดการเชื่อมต่อฐานข้อมูล
$conn->close();
?>