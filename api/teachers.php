<?php
// /api/teachers.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. รับ HTTP Method, ข้อมูล Input, และเตรียมการเชื่อมต่อ
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$response = ['success' => false, 'message' => 'An error occurred.'];

// 3. แยกการทำงานตาม HTTP Method ที่ร้องขอ
switch ($method) {
    // --- CASE GET: ดึงข้อมูลครูทั้งหมด ---
    case 'GET':
        checkLogin(['admin']); // เฉพาะแอดมินที่ดูรายชื่อครูทั้งหมดได้

        // ดึงข้อมูลครูทั้งหมด
        $teachers_result = $conn->query("SELECT id, username, name, reset_requested FROM users WHERE role = 'teacher' ORDER BY name ASC");
        $teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);

        // ดึงข้อมูลการสอนทั้งหมดเพื่อมาจับคู่กับครูแต่ละคน
        $assignments_result = $conn->query("SELECT teacher_id, subject_id, class_level, classroom FROM teacher_assignments");
        $all_assignments = [];
        while ($row = $assignments_result->fetch_assoc()) {
            $all_assignments[$row['teacher_id']][] = $row;
        }

        // วนลูปเพื่อสร้าง JSON string ของ `subjectClassPairs` ให้เหมือนระบบเดิม
        foreach ($teachers as &$teacher) {
            $teacher_assignments = $all_assignments[$teacher['id']] ?? [];
            $pairs = [];
            foreach ($teacher_assignments as $assign) {
                $key = $assign['subject_id'];
                if (!isset($pairs[$key])) {
                    $pairs[$key] = ['subjectId' => $assign['subject_id'], 'classLevels' => [], 'classrooms' => []];
                }
                $pairs[$key]['classLevels'][] = $assign['class_level'];
                $pairs[$key]['classrooms'][] = $assign['classroom'];
            }
             foreach ($pairs as &$pair) {
                $pair['classLevels'] = array_values(array_unique($pair['classLevels']));
                sort($pair['classLevels']);
                $pair['classrooms'] = array_values(array_unique($pair['classrooms']));
                sort($pair['classrooms']);
            }
            $teacher['subjectClassPairs'] = json_encode(array_values($pairs));
        }
        unset($teacher); // ล้าง reference

        $response = ['success' => true, 'data' => $teachers];
        break;

    // --- CASE POST: เพิ่มครูใหม่ ---
    case 'POST':
        checkLogin(['admin']);
        
        $name = $input['name'] ?? null;
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;
        $assignments = $input['assignments'] ?? []; // ควรส่งมาเป็น array of objects

        if (!$name || !$username || !$password || empty($assignments)) {
            http_response_code(400);
            $response['message'] = 'ข้อมูลสำหรับเพิ่มครูไม่ครบถ้วน (ต้องการ name, username, password, assignments)';
            break;
        }

        // Hash รหัสผ่านก่อนบันทึก
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            // 1. เพิ่มข้อมูลในตาราง users
            $stmt_user = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'teacher')");
            $stmt_user->bind_param("sss", $name, $username, $hashed_password);
            $stmt_user->execute();
            $teacher_id = $conn->insert_id;
            $stmt_user->close();
            
            // 2. เพิ่มข้อมูลการสอนในตาราง teacher_assignments
            $stmt_assign = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, subject_id, class_level, classroom) VALUES (?, ?, ?, ?)");
            foreach ($assignments as $assign) {
                foreach($assign['classLevels'] as $level) {
                    foreach($assign['classrooms'] as $room) {
                        $stmt_assign->bind_param("iiss", $teacher_id, $assign['subjectId'], $level, $room);
                        $stmt_assign->execute();
                    }
                }
            }
            $stmt_assign->close();

            $conn->commit();
            $response = ['success' => true, 'message' => 'เพิ่มข้อมูลครูสำเร็จ', 'id' => $teacher_id];

        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            if ($conn->errno == 1062) {
                $response['message'] = 'ไม่สามารถเพิ่มได้: ชื่อผู้ใช้ (username) นี้มีอยู่แล้ว';
            } else {
                $response['message'] = 'เกิดข้อผิดพลาดในการเพิ่มข้อมูลครู: ' . $e->getMessage();
            }
        }
        break;

    // --- CASE PUT: อัปเดตข้อมูลครู หรือ รีเซ็ตรหัสผ่าน ---
    case 'PUT':
        checkLogin(['admin']);
        $action = $input['action'] ?? 'update';

        if ($action === 'resetPassword') {
            // Logic สำหรับรีเซ็ตรหัสผ่าน
            $id = $input['id'] ?? null;
            $newPassword = $input['newPassword'] ?? null;

            if (!$id || !$newPassword) {
                http_response_code(400);
                $response['message'] = 'ข้อมูลสำหรับรีเซ็ตรหัสผ่านไม่ครบถ้วน';
                break;
            }
            
            $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
            // อัปเดตรหัสผ่านใหม่ และตั้งค่า reset_requested กลับเป็น false
            $stmt = $conn->prepare("UPDATE users SET password = ?, reset_requested = FALSE WHERE id = ? AND role = 'teacher'");
            $stmt->bind_param("si", $hashed_password, $id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'รีเซ็ตรหัสผ่านสำเร็จ'];
            } else {
                $response['message'] = 'ไม่พบข้อมูลครูที่ต้องการรีเซ็ตรหัสผ่าน';
            }
            $stmt->close();

        } else {
            // Logic สำหรับอัปเดตข้อมูลทั่วไป
            $id = $input['id'] ?? null;
            $name = $input['name'] ?? null;
            $username = $input['username'] ?? null;
            $assignments = $input['assignments'] ?? [];
            $password = $input['password'] ?? null; // รหัสผ่าน (optional)

            if (!$id || !$name || !$username || empty($assignments)) {
                http_response_code(400);
                $response['message'] = 'ข้อมูลสำหรับอัปเดตครูไม่ครบถ้วน';
                break;
            }

            $conn->begin_transaction();
            try {
                // 1. อัปเดตตาราง users
                $sql_user = "UPDATE users SET name = ?, username = ?";
                $types = "ss";
                $params = [$name, $username];
                if ($password) {
                    $sql_user .= ", password = ?";
                    $types .= "s";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql_user .= " WHERE id = ? AND role = 'teacher'";
                $types .= "i";
                $params[] = $id;

                $stmt_user = $conn->prepare($sql_user);
                $stmt_user->bind_param($types, ...$params);
                $stmt_user->execute();
                $stmt_user->close();
                
                // 2. ลบ assignments เก่าทั้งหมดของครูคนนี้
                $stmt_delete = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ?");
                $stmt_delete->bind_param("i", $id);
                $stmt_delete->execute();
                $stmt_delete->close();
                
                // 3. เพิ่ม assignments ใหม่
                $stmt_assign = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, subject_id, class_level, classroom) VALUES (?, ?, ?, ?)");
                foreach ($assignments as $assign) {
                     foreach($assign['classLevels'] as $level) {
                        foreach($assign['classrooms'] as $room) {
                            $stmt_assign->bind_param("iiss", $id, $assign['subjectId'], $level, $room);
                            $stmt_assign->execute();
                        }
                    }
                }
                $stmt_assign->close();

                $conn->commit();
                $response = ['success' => true, 'message' => 'อัปเดตข้อมูลครูสำเร็จ'];

            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                if ($conn->errno == 1062) {
                    $response['message'] = 'ไม่สามารถอัปเดตได้: ชื่อผู้ใช้ (username) ซ้ำกับคนอื่น';
                } else {
                    $response['message'] = 'เกิดข้อผิดพลาดในการอัปเดตข้อมูลครู: ' . $e->getMessage();
                }
            }
        }
        break;

    // --- CASE DELETE: ลบข้อมูลครู ---
    case 'DELETE':
        checkLogin(['admin']);
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            $response['message'] = 'ไม่ได้ระบุ ID ของครูที่ต้องการลบ';
            break;
        }

        // การตั้งค่า ON DELETE CASCADE ในฐานข้อมูลจะช่วยลบข้อมูลที่เกี่ยวข้องในตารางอื่น ๆ อัตโนมัติ
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->bind_param("i", $id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $response = ['success' => true, 'message' => 'ลบข้อมูลครูสำเร็จ'];
        } else {
            $response['message'] = 'ไม่พบข้อมูลครูที่ต้องการลบ หรือการล้มเหลว';
        }
        $stmt->close();
        break;

    default:
        http_response_code(405);
        $response['message'] = 'Method ที่ร้องขอไม่ได้รับอนุญาต';
        break;
}

echo json_encode($response);
$conn->close();
?>