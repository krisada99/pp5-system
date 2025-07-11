<?php
// /api/subjects.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. รับ HTTP Method, ข้อมูล Input, และเตรียมการเชื่อมต่อ
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$response = ['success' => false, 'message' => 'An error occurred.']; // ข้อความเริ่มต้น

// 3. แยกการทำงานตาม HTTP Method ที่ร้องขอ
switch ($method) {
    // --- CASE GET: ดึงข้อมูลรายวิชาทั้งหมด ---
    case 'GET':
        // การดึงข้อมูลสามารถทำได้โดยผู้ใช้ที่ล็อกอินเข้าระบบแล้ว
        checkLogin(); 
        
        $result = $conn->query("SELECT id, code, name, credits FROM subjects ORDER BY code ASC");
        $subjects = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $subjects]);
        break;

    // --- CASE POST: เพิ่มรายวิชาใหม่ ---
    case 'POST':
        checkLogin(['admin']); // ตรวจสอบสิทธิ์ (เฉพาะแอดมิน)
        
        if (!isset($input['code'], $input['name'])) {
            http_response_code(400);
            $response['message'] = 'กรุณากรอกรหัสวิชาและชื่อวิชา';
            break;
        }

        $code = trim($input['code']);
        $name = trim($input['name']);
        $credits = isset($input['credits']) && is_numeric($input['credits']) ? (float)$input['credits'] : 0.0;

        $stmt = $conn->prepare("INSERT INTO subjects (code, name, credits) VALUES (?, ?, ?)");
        $stmt->bind_param("ssd", $code, $name, $credits);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $response = ['success' => true, 'message' => 'เพิ่มรายวิชาสำเร็จ', 'id' => $new_id];
        } else {
            http_response_code(500);
            // ตรวจสอบ lỗi duplicate entry
            if ($conn->errno == 1062) {
                $response['message'] = 'ไม่สามารถเพิ่มรายวิชาได้: รหัสวิชานี้มีอยู่แล้วในระบบ';
            } else {
                $response['message'] = 'ไม่สามารถเพิ่มรายวิชาได้: ' . $stmt->error;
            }
        }
        $stmt->close();
        break;

    // --- CASE PUT: อัปเดตข้อมูลรายวิชา ---
    case 'PUT':
        checkLogin(['admin']);
        
        // สำหรับ PUT, id มักจะถูกส่งมาทาง URL parameter แต่เราจะใช้จาก JSON body เพื่อความง่าย
        $id = $input['id'] ?? null;
        if (!$id || !isset($input['code'], $input['name'])) {
            http_response_code(400);
            $response['message'] = 'ข้อมูลสำหรับอัปเดตไม่ครบถ้วน (ต้องการ id, code, name)';
            break;
        }

        $code = trim($input['code']);
        $name = trim($input['name']);
        $credits = isset($input['credits']) && is_numeric($input['credits']) ? (float)$input['credits'] : 0.0;

        $stmt = $conn->prepare("UPDATE subjects SET code = ?, name = ?, credits = ? WHERE id = ?");
        $stmt->bind_param("ssdi", $code, $name, $credits, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'อัปเดตรายวิชาสำเร็จ'];
            } else {
                $response = ['success' => false, 'message' => 'ไม่พบรายวิชาที่ต้องการอัปเดต หรือข้อมูลไม่มีการเปลี่ยนแปลง'];
            }
        } else {
            http_response_code(500);
             if ($conn->errno == 1062) {
                $response['message'] = 'ไม่สามารถอัปเดตได้: รหัสวิชาซ้ำกับที่มีอยู่แล้ว';
            } else {
                $response['message'] = 'การอัปเดตล้มเหลว: ' . $stmt->error;
            }
        }
        $stmt->close();
        break;

    // --- CASE DELETE: ลบรายวิชา ---
    case 'DELETE':
        checkLogin(['admin']);

        // สำหรับ DELETE, id มักจะถูกส่งมาทาง URL parameter เช่น /api/subjects.php?id=123
        // เราจะอ่านจาก $_GET เพื่อให้สอดคล้องกับ RESTful practices
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            $response['message'] = 'ไม่ได้ระบุ ID ของรายวิชาที่ต้องการลบ';
            break;
        }
        
        // การลบข้อมูลที่เชื่อมโยงกัน (Cascading Delete) ควรตั้งค่าที่ระดับฐานข้อมูล (ON DELETE CASCADE)
        // เพื่อให้เมื่อลบรายวิชาแล้ว ข้อมูลในตาราง teacher_assignments, attendance, scores ที่อ้างอิงถึงรายวิชานี้จะถูกลบไปด้วย
        // หากไม่ได้ตั้งค่าไว้ที่ฐานข้อมูล จะต้องทำการลบจากตารางอื่นก่อนที่นี่
        
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'ลบรายวิชาสำเร็จ'];
            } else {
                $response = ['success' => false, 'message' => 'ไม่พบรายวิชาที่ต้องการลบ'];
            }
        } else {
            http_response_code(500);
            $response['message'] = 'การลบล้มเหลว: ' . $stmt->error;
        }
        $stmt->close();
        break;
        
    default:
        http_response_code(405);
        $response['message'] = 'Method ที่ร้องขอไม่ได้รับอนุญาต';
        break;
}

// 4. ส่งผลลัพธ์กลับในรูปแบบ JSON
echo json_encode($response);
$conn->close();

?>