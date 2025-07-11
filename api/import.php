<?php
// /api/import.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'admin' เท่านั้น
checkLogin(['admin']);

// 3. ตรวจสอบว่าเป็นการร้องขอแบบ POST และมีไฟล์ส่งมาหรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method ที่ร้องขอไม่ได้รับอนุญาต']);
    exit;
}

if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ที่อัปโหลด หรือเกิดข้อผิดพลาดในการอัปโหลด']);
    exit;
}

// 4. รับประเภทการนำเข้าข้อมูล (subjects หรือ students)
$importType = $_POST['importType'] ?? null;
if (!$importType || !in_array($importType, ['subjects', 'students'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ไม่ได้ระบุประเภทการนำเข้าข้อมูลที่ถูกต้อง']);
    exit;
}

// 5. ประมวลผลไฟล์ CSV
$csvFile = $_FILES['csvFile']['tmp_name'];
$fileHandle = fopen($csvFile, 'r');

if ($fileHandle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเปิดไฟล์ CSV ได้']);
    exit;
}

// --- เริ่มการเชื่อมต่อและ Transaction กับฐานข้อมูล ---
$conn = getDbConnection();
$conn->begin_transaction();

$count = 0;
$response = ['success' => false];

try {
    // ข้ามแถวแรก (Header)
    fgetcsv($fileHandle); 
    
    // 6. เตรียมคำสั่ง SQL ตามประเภทของการนำเข้า
    $stmt = null;
    if ($importType === 'subjects') {
        // สำหรับรายวิชา: ถ้ามี "รหัสวิชา" ซ้ำ จะทำการ "อัปเดต" ชื่อและหน่วยกิต
        $stmt = $conn->prepare("
            INSERT INTO subjects (code, name, credits) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), credits = VALUES(credits)
        ");
    } elseif ($importType === 'students') {
        // สำหรับนักเรียน: ถ้ามี "รหัสนักเรียน" ซ้ำ จะทำการ "อัปเดต" ข้อมูลอื่นๆ
        $stmt = $conn->prepare("
            INSERT INTO users (student_code, name, class_level, classroom, role) 
            VALUES (?, ?, ?, ?, 'student')
            ON DUPLICATE KEY UPDATE name = VALUES(name), class_level = VALUES(class_level), classroom = VALUES(classroom)
        ");
    }

    if (!$stmt) {
        throw new Exception("ไม่สามารถเตรียมคำสั่ง SQL ได้");
    }
    
    // 7. วนลูปอ่านข้อมูลแต่ละแถวใน CSV
    while (($row = fgetcsv($fileHandle)) !== false) {
        // ตรวจสอบว่าแถวไม่ว่าง
        if (empty(implode('', $row))) {
            continue;
        }
        
        // กำหนดค่าจากแถว CSV และ Bind ไปยัง Statement ที่เตรียมไว้
        if ($importType === 'subjects') {
            $code = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            $credits = !empty(trim($row[2] ?? '')) ? (float)trim($row[2]) : 0.0;
            
            if (!empty($code) && !empty($name)) {
                $stmt->bind_param("ssd", $code, $name, $credits);
                $stmt->execute();
                $count++;
            }
        } elseif ($importType === 'students') {
            $code = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            $class = trim($row[2] ?? '');
            $classroom = trim($row[3] ?? '');

            if (!empty($code) && !empty($name) && !empty($class) && !empty($classroom)) {
                $stmt->bind_param("ssss", $code, $name, $class, $classroom);
                $stmt->execute();
                $count++;
            }
        }
    }
    
    // 8. ยืนยันการเปลี่ยนแปลงข้อมูลและสร้าง Response
    $conn->commit();
    $response = [
        'success' => true,
        'count' => $count,
        'message' => "นำเข้าข้อมูลประเภท '{$importType}' จำนวน {$count} รายการสำเร็จ"
    ];

} catch (Exception $e) {
    // 9. หากเกิดข้อผิดพลาด ให้ยกเลิกการเปลี่ยนแปลงทั้งหมด
    $conn->rollback();
    http_response_code(500);
    $response = ['success' => false, 'message' => 'เกิดข้อผิดพลาดระหว่างการนำเข้าข้อมูล: ' . $e->getMessage()];
} finally {
    // 10. ปิดการเชื่อมต่อและไฟล์
    if ($stmt) {
        $stmt->close();
    }
    fclose($fileHandle);
    $conn->close();
}

echo json_encode($response);
?>