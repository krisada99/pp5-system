<?php
// /api/settings.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'admin' เท่านั้น
checkLogin(['admin']);

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อ
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$admin_id = $_SESSION['user']['id'];

// 4. ตรวจสอบว่าเป็นการร้องขอแบบ POST หรือไม่
if ($method === 'POST') {
    $action = $input['action'] ?? null;
    $params = $input['params'] ?? [];
    
    // ตรวจสอบว่ามีการส่งรหัสผ่านแอดมินมาหรือไม่
    $adminPassword = $params['admin_password'] ?? null;
    if (empty($adminPassword)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณากรอกรหัสผ่านผู้ดูแลระบบเพื่อยืนยัน']);
        exit;
    }

    // --- ตรวจสอบรหัสผ่านของผู้ดูแลระบบ ---
    $stmt_pass = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt_pass->bind_param("i", $admin_id);
    $stmt_pass->execute();
    $result_pass = $stmt_pass->get_result();
    
    if ($result_pass->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลผู้ดูแลระบบ']);
        exit;
    }
    
    $admin_hashed_password = $result_pass->fetch_assoc()['password'];
    $stmt_pass->close();

    if (!password_verify($adminPassword, $admin_hashed_password)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'รหัสผ่านผู้ดูแลระบบไม่ถูกต้อง']);
        exit;
    }
    
    // --- ถ้าตรวจสอบรหัสผ่านผ่านแล้ว ให้เริ่มทำงานตาม action ที่ร้องขอ ---
    switch ($action) {
        // --- CASE 1: บันทึกการตั้งค่าระบบทั่วไป ---
        case 'saveAppSettings':
            $settingsData = $params['settings'] ?? [];
            if (empty($settingsData)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลการตั้งค่าให้บันทึก']);
                exit;
            }

            $stmt_update = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $conn->begin_transaction();
            try {
                foreach ($settingsData as $key => $value) {
                    $stmt_update->bind_param("ss", $key, $value);
                    $stmt_update->execute();
                }
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่าระบบสำเร็จ']);
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage()]);
            }
            $stmt_update->close();
            break;

        // --- CASE 2: บันทึกการตั้งค่าระดับชั้นเรียน ---
        case 'saveClassLevelSettings':
            $schoolTypeName = $params['school_type_name'] ?? null;
            $enabledLevels = $params['enabled_class_levels'] ?? null; // ควรเป็น JSON string

            if (empty($schoolTypeName) || empty($enabledLevels)) {
                 http_response_code(400);
                 echo json_encode(['success' => false, 'message' => 'ข้อมูลการตั้งค่าระดับชั้นไม่ครบถ้วน']);
                 exit;
            }
            
            $stmt_update = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $conn->begin_transaction();
            try {
                // บันทึกชื่อประเภทสถานศึกษา
                $key1 = 'school_type_name';
                $stmt_update->bind_param("ss", $key1, $schoolTypeName);
                $stmt_update->execute();

                // บันทึกระดับชั้นที่เปิดสอน (ในรูปแบบ JSON)
                $key2 = 'enabled_class_levels';
                $stmt_update->bind_param("ss", $key2, $enabledLevels);
                $stmt_update->execute();

                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่าระดับชั้นสำเร็จ']);
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage()]);
            }
            $stmt_update->close();
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

$conn->close();
?>