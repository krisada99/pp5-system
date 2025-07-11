<?php
// /api/characteristics.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'teacher' หรือไม่
checkLogin(['teacher']);

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อฐานข้อมูล
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$teacher_id = $_SESSION['user']['id'];

// 4. ตรวจสอบว่าเป็นการร้องขอแบบ POST หรือไม่
if ($method === 'POST') {
    // ในโค้ด JavaScript เราจะส่งข้อมูลมาใน key ชื่อ 'scores'
    $scores = $input['scores'] ?? [];

    if (empty($scores)) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'message' => 'ไม่มีข้อมูลผลการประเมินให้บันทึก']);
        exit;
    }

    // 5. เตรียมคำสั่ง SQL ที่มีประสิทธิภาพ
    // ใช้ "INSERT ... ON DUPLICATE KEY UPDATE" เพื่อจัดการทั้งการเพิ่มและอัปเดตในคำสั่งเดียว
    // คำสั่งนี้จะทำงานได้เพราะเราได้สร้าง UNIQUE KEY `unique_eval_score` (`student_id`, `item_id`) ไว้ในตาราง `evaluation_scores` แล้ว
    $stmt = $conn->prepare("
        INSERT INTO evaluation_scores (student_id, item_id, teacher_id, score) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            score = VALUES(score), 
            teacher_id = VALUES(teacher_id)
    ");

    $count = 0;
    $savedRecords = [];
    $conn->begin_transaction(); // เริ่ม Transaction เพื่อความปลอดภัย

    try {
        // 6. วนลูปเพื่อบันทึกข้อมูลแต่ละรายการ
        foreach ($scores as $score_data) {
            // ตรวจสอบความครบถ้วนและความถูกต้องของข้อมูลที่จำเป็น
            if (
                isset($score_data['studentId'], $score_data['itemId'], $score_data['score']) &&
                is_numeric($score_data['score']) && // ตรวจสอบว่าเป็นตัวเลข
                $score_data['score'] >= 0 && $score_data['score'] <= 3 // ตรวจสอบว่าคะแนนอยู่ในช่วง 0-3
            ) {
                $studentId = $score_data['studentId'];
                $itemId = $score_data['itemId'];
                $score = $score_data['score'];

                // 7. Bind Parameters และ Execute
                $stmt->bind_param("iiii", $studentId, $itemId, $teacher_id, $score);
                
                if ($stmt->execute()) {
                    $count++;
                    // เตรียมข้อมูลส่งกลับเพื่ออัปเดต State ฝั่ง Client
                    $savedRecords[] = [
                        'studentId' => $studentId,
                        'itemId' => $itemId,
                        'score' => $score
                    ];
                }
            }
        }
        
        $conn->commit(); // ยืนยันการเปลี่ยนแปลงข้อมูล

        // 8. ส่งผลลัพธ์กลับไปให้ Client
        echo json_encode([
            'success' => true,
            'count' => $count,
            'message' => "บันทึกผลการประเมิน {$count} รายการสำเร็จ",
            'savedRecords' => $savedRecords
        ]);

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback(); // หากเกิดข้อผิดพลาด ให้ยกเลิกการเปลี่ยนแปลงทั้งหมด
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $exception->getMessage()
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