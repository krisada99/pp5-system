<?php
// /api/students.php
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
    // --- CASE GET: ดึงข้อมูลนักเรียนทั้งหมด (สำหรับแอดมิน) ---
    case 'GET':
        checkLogin(['admin']); // ตรวจสอบสิทธิ์ (เฉพาะแอดมิน)

        $sql = "SELECT id, student_code, name, class_level, classroom FROM users WHERE role = 'student' ORDER BY class_level, classroom, student_code";
        $result = $conn->query($sql);
        $students = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $students]);
        break;

    // --- CASE POST: เพิ่มนักเรียนใหม่ ---
    case 'POST':
        checkLogin(['admin']);
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (!isset($input['code'], $input['name'], $input['class'], $input['classroom'])) {
            http_response_code(400); // Bad Request
            $response['message'] = 'กรุณากรอกข้อมูลนักเรียนให้ครบถ้วน';
            echo json_encode($response);
            exit;
        }

        $code = trim($input['code']);
        $name = trim($input['name']);
        $class = trim($input['class']);
        $classroom = trim($input['classroom']);

        $stmt = $conn->prepare("
            INSERT INTO users (student_code, name, class_level, classroom, role) 
            VALUES (?, ?, ?, ?, 'student')
        ");
        $stmt->bind_param("ssss", $code, $name, $class, $classroom);

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $response = ['success' => true, 'message' => 'เพิ่มข้อมูลนักเรียนสำเร็จ', 'id' => $new_id];
        } else {
            http_response_code(500);
            if ($conn->errno == 1062) { // Error for duplicate entry
                $response['message'] = 'ไม่สามารถเพิ่มได้: รหัสนักเรียนนี้มีอยู่แล้วในระบบ';
            } else {
                $response['message'] = 'เกิดข้อผิดพลาดในการเพิ่มข้อมูลนักเรียน: ' . $stmt->error;
            }
        }
        $stmt->close();
        break;

    // --- CASE PUT: อัปเดตข้อมูลนักเรียน ---
    case 'PUT':
        checkLogin(['admin']);
        
        $id = $input['id'] ?? null;
        if (!$id || !isset($input['code'], $input['name'], $input['class'], $input['classroom'])) {
            http_response_code(400);
            $response['message'] = 'ข้อมูลสำหรับอัปเดตไม่ครบถ้วน';
            echo json_encode($response);
            exit;
        }

        $code = trim($input['code']);
        $name = trim($input['name']);
        $class = trim($input['class']);
        $classroom = trim($input['classroom']);

        $stmt = $conn->prepare("UPDATE users SET student_code = ?, name = ?, class_level = ?, classroom = ? WHERE id = ? AND role = 'student'");
        $stmt->bind_param("ssssi", $code, $name, $class, $classroom, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'อัปเดตข้อมูลนักเรียนสำเร็จ'];
            } else {
                $response = ['success' => false, 'message' => 'ไม่พบข้อมูลนักเรียน หรือข้อมูลไม่มีการเปลี่ยนแปลง'];
            }
        } else {
            http_response_code(500);
            if ($conn->errno == 1062) {
                $response['message'] = 'ไม่สามารถอัปเดตได้: รหัสนักเรียนซ้ำกับคนอื่น';
            } else {
                $response['message'] = 'การอัปเดตล้มเหลว: ' . $stmt->error;
            }
        }
        $stmt->close();
        break;

    // --- CASE DELETE: ลบข้อมูลนักเรียน ---
    case 'DELETE':
        checkLogin(['admin']);

        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            $response['message'] = 'ไม่ได้ระบุ ID ของนักเรียนที่ต้องการลบ';
            echo json_encode($response);
            exit;
        }

        // การตั้งค่า Foreign Key Constraint เป็น ON DELETE CASCADE ในฐานข้อมูล
        // จะทำให้ข้อมูลทั้งหมดที่เกี่ยวข้องกับ student_id นี้ (เช่น attendance, scores) ถูกลบไปด้วยโดยอัตโนมัติ
        // ซึ่งเป็นวิธีที่ถูกต้องและปลอดภัยที่สุด

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
             if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'ลบข้อมูลนักเรียนสำเร็จ'];
            } else {
                $response = ['success' => false, 'message' => 'ไม่พบข้อมูลนักเรียนที่ต้องการลบ'];
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