<?php
// /api/auth.php - ฉบับแก้ไขสมบูรณ์
require_once '../config/database.php';
header("Content-Type: application/json; charset=UTF-8");

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$conn = getDbConnection();
$response = ['success' => false, 'message' => 'Invalid action specified.'];

switch ($action) {
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($username) || empty($password)) {
            $response['message'] = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
            http_response_code(400);
            break;
        }

        $stmt = $conn->prepare("SELECT id, username, password, role, name FROM users WHERE username = ? AND role IN ('admin', 'teacher')");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result(); // เปลี่ยนมาใช้ get_result() เพื่อความเสถียร

        if ($result->num_rows === 1) {
            $user_data = $result->fetch_assoc(); // ดึงข้อมูลผู้ใช้มาทั้งแถว
            $hashed_password_from_db = $user_data['password'];

            // ตรวจสอบรหัสผ่านที่ผู้ใช้กรอก กับค่าที่แฮชไว้ในฐานข้อมูล
            if (password_verify($password, $hashed_password_from_db)) {
                // ถ้าสำเร็จ สร้างข้อมูลสำหรับ Session
                $user_session_data = [
                    'id' => $user_data['id'],
                    'username' => $user_data['username'],
                    'role' => $user_data['role'],
                    'name' => $user_data['name']
                ];
                $_SESSION['user'] = $user_session_data;
                $response = ['success' => true, 'user' => $user_session_data];
            } else {
                // ถ้าไม่สำเร็จ แจ้งว่ารหัสผ่านไม่ถูกต้อง
                $response['message'] = 'รหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $response['message'] = 'ไม่พบชื่อผู้ใช้นี้ในระบบ';
        }
        $stmt->close();
        break;

    case 'studentLogin':
        $studentCode = $input['studentCode'] ?? '';
        if (empty($studentCode)) {
            $response['message'] = 'กรุณากรอกรหัสนักเรียน';
            http_response_code(400);
            break;
        }

        $stmt = $conn->prepare("SELECT id, student_code, name, class_level, classroom, role FROM users WHERE student_code = ? AND role = 'student'");
        $stmt->bind_param("s", $studentCode);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $_SESSION['user'] = $user;
            $response = ['success' => true, 'user' => $user];
        } else {
            $response['message'] = 'ไม่พบรหัสนักเรียนนี้ในระบบ';
        }
        $stmt->close();
        break;

    case 'requestReset':
        $username = trim($input['username'] ?? '');
        if (empty($username)) {
            $response['message'] = 'กรุณากรอกชื่อผู้ใช้';
            http_response_code(400);
            break;
        }

        $stmt = $conn->prepare("UPDATE users SET reset_requested = TRUE WHERE username = ? AND role = 'teacher'");
        $stmt->bind_param("s", $username);
        if ($stmt->execute()) {
            $response = [
                'success' => $stmt->affected_rows > 0,
                'message' => $stmt->affected_rows > 0 ? 'ส่งคำขอรีเซ็ตรหัสผ่านสำเร็จ กรุณาติดต่อผู้ดูแลระบบ' : 'ไม่พบชื่อผู้ใช้ของครูท่านนี้ในระบบ'
            ];
        } else {
            $response['message'] = 'เกิดข้อผิดพลาดในการส่งคำขอ';
            http_response_code(500);
        }
        $stmt->close();
        break;

    case 'logout':
        session_unset();
        session_destroy();
        $response = ['success' => true, 'message' => 'ออกจากระบบสำเร็จ'];
        break;

    default:
        http_response_code(400);
        break;
}

$conn->close();
echo json_encode($response);
?>