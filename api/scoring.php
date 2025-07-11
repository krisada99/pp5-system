<?php
// /api/scoring.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'teacher'
checkLogin(['teacher']);

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อฐานข้อมูล
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$teacher_id = $_SESSION['user']['id'];

// 4. แยกการทำงานตาม HTTP Method ที่ร้องขอมา (POST)
if ($method === 'POST') {
    $action = $input['action'] ?? null;

    switch ($action) {
        // --- CASE 1: บันทึก/อัปเดตองค์ประกอบคะแนน ---
        case 'saveComponent':
            // ตรวจสอบข้อมูลที่จำเป็น
            if (!isset($input['componentName'], $input['maxScore'], $input['subjectId'], $input['classLevel'], $input['classroom'])) {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'ข้อมูลสำหรับสร้างองค์ประกอบคะแนนไม่ครบถ้วน']);
                exit;
            }

            $id = $input['id'] ?? null;
            $componentName = trim($input['componentName']);
            $maxScore = (float)$input['maxScore'];
            $subjectId = $input['subjectId'];
            $classLevel = $input['classLevel'];
            $classroom = $input['classroom'];

            // --- Business Logic: ตรวจสอบว่าคะแนนรวมเกิน 100 หรือไม่ ---
            $stmt_check = $conn->prepare("SELECT SUM(max_score) as total FROM score_components WHERE subject_id = ? AND class_level = ? AND classroom = ? AND id != ?");
            $check_id = $id ?: 0; // ถ้าเป็น component ใหม่ ให้ id เป็น 0 เพื่อไม่ให้ตัดตัวเองออก
            $stmt_check->bind_param("issi", $subjectId, $classLevel, $classroom, $check_id);
            $stmt_check->execute();
            $currentTotalScore = (float)$stmt_check->get_result()->fetch_assoc()['total'];
            $stmt_check->close();
            
            if (($currentTotalScore + $maxScore) > 100) {
                http_response_code(400); // Bad Request
                echo json_encode(['success' => false, 'message' => 'คะแนนรวมของทุกองค์ประกอบจะเกิน 100 คะแนน ไม่สามารถบันทึกได้']);
                exit;
            }

            if ($id) { // ถ้ามี ID หมายถึงการ "อัปเดต"
                $stmt = $conn->prepare("UPDATE score_components SET component_name = ?, max_score = ? WHERE id = ? AND teacher_id = ?");
                $stmt->bind_param("sdii", $componentName, $maxScore, $id, $teacher_id);
            } else { // ถ้าไม่มี ID หมายถึงการ "เพิ่มใหม่"
                $stmt = $conn->prepare("INSERT INTO score_components (component_name, max_score, subject_id, class_level, classroom, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sdisssi", $componentName, $maxScore, $subjectId, $classLevel, $classroom, $teacher_id);
            }

            if ($stmt->execute()) {
                $new_id = $id ?: $conn->insert_id;
                echo json_encode(['success' => true, 'message' => 'บันทึกองค์ประกอบคะแนนสำเร็จ', 'id' => $new_id]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกองค์ประกอบคะแนนได้: ' . $stmt->error]);
            }
            $stmt->close();
            break;

        // --- CASE 2: ลบองค์ประกอบคะแนน ---
        case 'deleteComponent':
            $id = $input['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ไม่ได้ระบุ ID ขององค์ประกอบคะแนน']);
                exit;
            }

            $conn->begin_transaction();
            try {
                // ลบคะแนนของนักเรียนที่ผูกกับ component นี้ก่อน
                $stmt1 = $conn->prepare("DELETE FROM scores WHERE component_id = ?");
                $stmt1->bind_param("i", $id);
                $stmt1->execute();
                $stmt1->close();

                // ลบ component หลัก
                $stmt2 = $conn->prepare("DELETE FROM score_components WHERE id = ? AND teacher_id = ?");
                $stmt2->bind_param("ii", $id, $teacher_id);
                $stmt2->execute();
                
                if($stmt2->affected_rows > 0) {
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'ลบองค์ประกอบคะแนนสำเร็จ']);
                } else {
                    throw new Exception("ไม่พบองค์ประกอบคะแนน หรือคุณไม่มีสิทธิ์ลบ");
                }
                $stmt2->close();
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'การลบข้อมูลล้มเหลว: ' . $e->getMessage()]);
            }
            break;

        // --- CASE 3: บันทึกคะแนนและหมายเหตุของนักเรียน ---
        case 'saveScores':
            $scores = $input['scores'] ?? [];
            if (empty($scores)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลคะแนนให้บันทึก']);
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO scores (student_id, component_id, score, remark) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE score = VALUES(score), remark = VALUES(remark)
            ");
            
            $count = 0;
            $savedRecords = [];
            $conn->begin_transaction();
            try {
                foreach ($scores as $score_data) {
                    // ตรวจสอบข้อมูลก่อนบันทึก
                    if (isset($score_data['studentId'], $score_data['componentId'])) {
                        $studentId = $score_data['studentId'];
                        $componentId = $score_data['componentId'];
                        // ถ้าเป็นคะแนนปกติ ให้ score เป็นตัวเลข, remark เป็น null
                        // ถ้าเป็นหมายเหตุ ให้ score เป็น null, remark เป็นตัวอักษร
                        $score_value = isset($score_data['score']) && is_numeric($score_data['score']) ? (float)$score_data['score'] : null;
                        $remark_value = $score_data['remark'] ?? null;

                        $stmt->bind_param("iids", $studentId, $componentId, $score_value, $remark_value);
                        if($stmt->execute()){
                           $count++;
                           // เตรียมข้อมูลส่งกลับเพื่ออัปเดต State
                           $savedRecords[] = $score_data;
                        }
                    }
                }
                $conn->commit();
                echo json_encode(['success' => true, 'count' => $count, 'message' => "บันทึกข้อมูลคะแนน {$count} รายการสำเร็จ", 'savedRecords' => $savedRecords]);
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'การบันทึกคะแนนล้มเหลว: ' . $e->getMessage()]);
            }
            $stmt->close();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action ที่ร้องขอไม่ถูกต้อง']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method ที่ร้องขอไม่ได้รับอนุญาต']);
}

$conn->close();
?>