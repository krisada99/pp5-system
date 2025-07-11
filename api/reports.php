<?php
// /api/reports.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// 1. เรียกใช้งานไฟล์กำหนดค่าการเชื่อมต่อฐานข้อมูลและฟังก์ชันพื้นฐาน
require_once '../config/database.php';

// 2. ตรวจสอบว่าผู้ใช้ล็อกอินและมีสิทธิ์เป็น 'teacher' หรือ 'admin'
// (แอดมินก็ควรจะดูรายงานได้)
checkLogin(['teacher', 'admin']);

// 3. รับข้อมูลจาก Client และเตรียมการเชื่อมต่อ
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$conn = getDbConnection();
$user = $_SESSION['user'];

// --- ส่วนของฟังก์ชันย่อยสำหรับดึงข้อมูลแต่ละรายงาน ---

/**
 * ดึงข้อมูลสำหรับ "รายงานรายบุคคล (Individual Report)"
 */
function getIndividualReportData($conn, $params, $user) {
    // ดึงพารามิเตอร์ที่จำเป็น
    $startDate = $params['startDate'];
    $endDate = $params['endDate'];
    $classLevel = $params['classLevel'];
    $classroom = $params['classroom'];
    $subjectId = $params['subjectId'];
    $statusFilter = $params['status'];

    // เริ่มสร้างคำสั่ง SQL หลัก
    $sql = "
        SELECT 
            a.id, a.attendance_date, a.status, a.remark,
            s.id as studentId, s.student_code, s.name as studentName, s.class_level, s.classroom,
            sub.id as subjectId, sub.code as subjectCode, sub.name as subjectName,
            t.name as teacherName
        FROM attendance a
        JOIN users s ON a.student_id = s.id AND s.role = 'student'
        JOIN subjects sub ON a.subject_id = sub.id
        JOIN users t ON a.teacher_id = t.id
        WHERE a.attendance_date BETWEEN ? AND ?
    ";
    
    // พารามิเตอร์สำหรับ bind_param
    $types = "ss";
    $sql_params = [$startDate, $endDate];

    // เพิ่มเงื่อนไขตาม filter
    if ($classLevel && $classLevel !== 'all') {
        $sql .= " AND s.class_level = ?";
        $types .= "s";
        $sql_params[] = $classLevel;
    }
    if ($classroom) {
        $sql .= " AND s.classroom = ?";
        $types .= "s";
        $sql_params[] = $classroom;
    }
    if ($subjectId) {
        $sql .= " AND a.subject_id = ?";
        $types .= "i";
        $sql_params[] = $subjectId;
    }
    if ($statusFilter) {
        $sql .= " AND a.status = ?";
        $types .= "s";
        $sql_params[] = $statusFilter;
    }

    // จำกัดข้อมูลให้เห็นเฉพาะของครูที่ login (ถ้าไม่ใช่ admin)
    if ($user['role'] === 'teacher') {
        $sql .= " AND a.teacher_id = ?";
        $types .= "i";
        $sql_params[] = $user['id'];
    }
    
    $sql .= " ORDER BY s.class_level, s.classroom, s.student_code, a.attendance_date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$sql_params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return ['success' => true, 'data' => $result];
}

/**
 * ดึงข้อมูลสำหรับ "รายงานตามห้องเรียน (Classroom Report)"
 */
function getClassroomReportData($conn, $params, $user) {
    $startDate = $params['startDate'];
    $endDate = $params['endDate'];
    
    // ใช้ GROUP BY เพื่อสรุปข้อมูลการมาเรียนในแต่ละวัน/ห้อง/วิชา
    $sql = "
        SELECT
            a.attendance_date,
            s.class_level,
            s.classroom,
            sub.id as subjectId,
            sub.code as subjectCode,
            sub.name as subjectName,
            COUNT(DISTINCT a.student_id) as totalStudents,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) as leave,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late
        FROM attendance a
        JOIN users s ON a.student_id = s.id
        JOIN subjects sub ON a.subject_id = sub.id
        WHERE a.attendance_date BETWEEN ? AND ?
    ";
    
    $types = "ss";
    $sql_params = [$startDate, $endDate];

    // เพิ่มเงื่อนไขจาก Filters
    if (!empty($params['classLevel']) && $params['classLevel'] !== 'all') {
        $sql .= " AND s.class_level = ?";
        $types .= "s";
        $sql_params[] = $params['classLevel'];
    }
    // ... (เพิ่มเงื่อนไข classroom, subjectId ที่นี่ถ้าต้องการ) ...

     if ($user['role'] === 'teacher') {
        $sql .= " AND a.teacher_id = ?";
        $types .= "i";
        $sql_params[] = $user['id'];
    }
    
    $sql .= " GROUP BY a.attendance_date, s.class_level, s.classroom, sub.id ORDER BY a.attendance_date DESC, s.class_level, s.classroom";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$sql_params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['success' => true, 'data' => $result];
}

// --- (สามารถเพิ่มฟังก์ชันสำหรับรายงานอื่นๆ ได้ที่นี่ เช่น getSchoolReportData) ---
// --- (Logic การดึงข้อมูลสำหรับ Score Report, Characteristics Report จะอยู่ที่นี่เช่นกัน) ---

// --- ส่วนประมวลผลหลัก (Main Logic) ---

if ($method === 'POST') {
    $reportType = $input['reportType'] ?? null;
    $params = $input['params'] ?? [];
    $response = null;

    // ตรวจสอบว่ามี reportType ส่งมาหรือไม่
    if (!$reportType) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ไม่ได้ระบุประเภทของรายงาน']);
        exit;
    }
    
    // ใช้ switch เพื่อเรียกฟังก์ชันที่ถูกต้อง
    try {
        switch ($reportType) {
            case 'individual':
                $response = getIndividualReportData($conn, $params, $user);
                break;

            case 'classroom':
                $response = getClassroomReportData($conn, $params, $user);
                break;

            // เพิ่ม case สำหรับรายงานอื่นๆ ที่นี่
            // case 'school':
            //     $response = getSchoolReportData($conn, $params, $user);
            //     break;

            // case 'scoreReport':
            //      $response = getScoreReportData($conn, $params, $user); // ต้องสร้างฟังก์ชันนี้
            //      break;
            
            // case 'characteristicsReport':
            //      $response = getCharacteristicsReportData($conn, $params, $user); // ต้องสร้างฟังก์ชันนี้
            //      break;

            default:
                http_response_code(400);
                $response = ['success' => false, 'message' => "ไม่รู้จักประเภทรายงาน '{$reportType}'"];
                break;
        }

        if ($response && $response['success']) {
            echo json_encode($response);
        } else if ($response) {
            // ถ้าฟังก์ชัน return error มา
             http_response_code(404);
             echo json_encode(['success' => false, 'message' => $response['message'] ?? 'ไม่พบข้อมูลสำหรับสร้างรายงาน']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method ที่ร้องขอไม่ได้รับอนุญาต']);
}

$conn->close();
?>