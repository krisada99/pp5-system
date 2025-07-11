<?php
// /api/attendance.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'teacher' หรือไม่
checkLogin(['teacher']);

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อฐานข้อมูล
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$teacher_id = $_SESSION['user']['id']; // ดึง ID ของครูที่ล็อกอินอยู่จาก Session

// 4. ตรวจสอบว่าเป็นการร้องขอแบบ POST หรือไม่
if ($method === 'POST') {
    // Client ควรจะส่งข้อมูลมาใน key ชื่อ 'records'
    $records = $input['records'] ?? [];

    if (empty($records)) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลการเช็คชื่อให้บันทึก']);
        exit;
    }

    // 5. เตรียมคำสั่ง SQL ที่มีประสิทธิภาพสูง
    // ใช้ "INSERT ... ON DUPLICATE KEY UPDATE" เพื่อจัดการทั้งการเพิ่มข้อมูลใหม่และอัปเดตข้อมูลเก่าในคำสั่งเดียว
    // เราได้สร้าง UNIQUE KEY `unique_attendance` (`student_id`, `subject_id`, `attendance_date`) ไว้ในตารางแล้ว
    // เมื่อมีการ INSERT ข้อมูลที่ซ้ำกันใน 3 คอลัมน์นี้ คำสั่ง ON DUPLICATE KEY UPDATE จะทำงานแทน
    $stmt = $conn->prepare("
        INSERT INTO attendance (student_id, subject_id, teacher_id, attendance_date, status, remark) 
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            teacher_id = VALUES(teacher_id), 
            status = VALUES(status), 
            remark = VALUES(remark)
    ");

    $count = 0;
    $savedRecords = [];
    $conn->begin_transaction(); // เริ่ม Transaction เพื่อความปลอดภัยของข้อมูล

    try {
        // 6. วนลูปเพื่อบันทึกข้อมูลแต่ละรายการ
        foreach ($records as $record) {
            // ตรวจสอบความครบถ้วนของข้อมูลที่จำเป็น
            if (
                isset($record['studentId'], $record['subjectId'], $record['date'], $record['status']) &&
                !empty($record['date'])
            ) {
                $studentId = $record['studentId'];
                $subjectId = $record['subjectId'];
                $date = $record['date'];
                $status = $record['status'];
                $remark = $record['remark'] ?? ''; // ถ้าไม่มี remark ให้เป็นค่าว่าง

                // 7. Bind Parameters เพื่อป้องกัน SQL Injection และ Execute
                $stmt->bind_param("iissss", $studentId, $subjectId, $teacher_id, $date, $status, $remark);
                
                if ($stmt->execute()) {
                    $count++;
                    // เตรียมข้อมูลส่งกลับไปให้ Client เพื่ออัปเดต State
                    // เราจะ Query ข้อมูลที่เพิ่งบันทึก/อัปเดตกลับไป เพื่อให้มี ID ที่ถูกต้องจากฐานข้อมูล
                    $last_id = $conn->insert_id;
                    if ($last_id == 0) { // กรณีเป็นการ UPDATE, insert_id จะเป็น 0
                         $find_stmt = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND subject_id = ? AND attendance_date = ?");
                         $find_stmt->bind_param("iis", $studentId, $subjectId, $date);
                         $find_stmt->execute();
                         $result = $find_stmt->get_result();
                         if($result->num_rows > 0) {
                            $last_id = $result->fetch_assoc()['id'];
                         }
                         $find_stmt->close();
                    }
                    
                    $savedRecords[] = [
                        'id' => $last_id,
                        'studentId' => $studentId,
                        'subjectId' => $subjectId,
                        'teacherName' => $_SESSION['user']['name'],
                        'date' => $date,
                        'status' => $status,
                        'remark' => $remark
                    ];
                }
            }
        }
        
        $conn->commit(); // ยืนยันการเปลี่ยนแปลงข้อมูลทั้งหมด

        // 8. ส่งผลลัพธ์กลับไปให้ Client
        echo json_encode([
            'success' => true,
            'count' => $count,
            'message' => "บันทึกข้อมูลการเข้าเรียน {$count} รายการสำเร็จ",
            'savedRecords' => $savedRecords // ส่งข้อมูลที่บันทึกแล้วกลับไปด้วย
        ]);

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback(); // หากเกิดข้อผิดพลาด ให้ยกเลิกการเปลี่ยนแปลงทั้งหมด
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล: ' . $exception->getMessage()
        ]);
    }
    
    $stmt->close();

} else {
    // ถ้าไม่ใช่ POST request ให้ส่งข้อความแจ้งเตือน
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method ที่ร้องขอไม่ได้รับอนุญาต']);
}

// 9. ปิดการเชื่อมต่อฐานข้อมูล
$conn->close();
?>