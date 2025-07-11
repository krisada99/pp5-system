<?php
// api/login.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();

$action = $data['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action.'];

if ($action === 'teacherLogin') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password, role, name FROM users WHERE username = ? AND role IN ('admin', 'teacher')");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            unset($user['password']); // Don't send password hash to client
            $_SESSION['user'] = $user;
            $response = ['success' => true, 'user' => $user];
        } else {
            $response['message'] = 'รหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $response['message'] = 'ไม่พบชื่อผู้ใช้นี้ในระบบ';
    }
    $stmt->close();
} elseif ($action === 'studentLogin') {
    $studentCode = $data['studentCode'] ?? '';

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
}

$conn->close();
echo json_encode($response);
?>