<?php
// /api/data_loader.php
ob_start(); // <--- เริ่มดักจับ Output ทั้งหมด

header("Content-Type: application/json; charset=UTF-8");
require_once '../config/database.php';
checkLogin(); // ตรวจสอบว่ามีการล็อกอินหรือยัง

$conn = getDbConnection();
$user = $_SESSION['user'];
$response = ['success' => false, 'message' => 'Could not load data.'];
$initialData = [];

try {
    // --- 1. ดึงข้อมูลที่ใช้ร่วมกันทั้งหมด (Common Data) ---
    $settings_result = $conn->query("SELECT setting_key, setting_value FROM settings");
    $initialData['settings'] = [];
    while ($row = $settings_result->fetch_assoc()) {
        $initialData['settings'][$row['setting_key']] = ['setting_value' => $row['setting_value']];
    }

    // แปลงค่าที่เป็น JSON string ให้เป็น array จริง (พร้อมตรวจสอบก่อน)
    if (isset($initialData['settings']['enabled_class_levels']['setting_value'])) {
        $decoded_levels = json_decode($initialData['settings']['enabled_class_levels']['setting_value'], true);
        // ตรวจสอบว่า json_decode สำเร็จหรือไม่
        $initialData['settings']['enabled_class_levels']['setting_value'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded_levels : [];
    }

    $initialData['subjects'] = $conn->query("SELECT * FROM subjects ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    
    $initialData['characteristicsItems'] = $conn->query("SELECT * FROM evaluation_items WHERE item_group IN ('คุณลักษณะอันพึงประสงค์', 'การอ่าน คิดวิเคราะห์ และเขียน') ORDER BY item_order, id")->fetch_all(MYSQLI_ASSOC);
    $initialData['valuesCompetenciesItems'] = $conn->query("SELECT * FROM evaluation_items WHERE item_group IN ('ค่านิยมหลักของคนไทย 12 ประการ', 'สมรรถนะสำคัญของผู้เรียน') ORDER BY item_order, id")->fetch_all(MYSQLI_ASSOC);
    
    // --- 2. ดึงข้อมูลตาม Role ของผู้ใช้ ---
    switch ($user['role']) {
        case 'admin':
            $initialData['teachers'] = $conn->query("SELECT id, username, name, reset_requested FROM users WHERE role = 'teacher'")->fetch_all(MYSQLI_ASSOC);
            $initialData['students'] = $conn->query("SELECT id, student_code, name, class_level, classroom FROM users WHERE role = 'student' ORDER BY class_level, classroom, student_code")->fetch_all(MYSQLI_ASSOC);
            
            $assignments_result = $conn->query("SELECT teacher_id, subject_id, class_level, classroom FROM teacher_assignments");
            $assignments = [];
            while ($row = $assignments_result->fetch_assoc()) {
                $assignments[$row['teacher_id']][] = $row;
            }

            foreach ($initialData['teachers'] as &$teacher) {
                $teacher_assignments_raw = $assignments[$teacher['id']] ?? [];
                $pairs = [];
                foreach ($teacher_assignments_raw as $assign) {
                    $key = $assign['subject_id'];
                    if (!isset($pairs[$key])) {
                        $pairs[$key] = ['subjectId' => $assign['subject_id'], 'classLevels' => [], 'classrooms' => []];
                    }
                    $pairs[$key]['classLevels'][] = $assign['class_level'];
                    $pairs[$key]['classrooms'][] = $assign['classroom'];
                }
                foreach ($pairs as &$pair) {
                    $pair['classLevels'] = array_values(array_unique($pair['classLevels'])); sort($pair['classLevels']);
                    $pair['classrooms'] = array_values(array_unique($pair['classrooms'])); sort($pair['classrooms']);
                }
                $teacher['subjectClassPairs'] = json_encode(array_values($pairs));
            }
            unset($teacher);

            break;

        case 'teacher':
            $teacher_id = $user['id'];
            
            $stmt = $conn->prepare("SELECT subject_id, class_level, classroom FROM teacher_assignments WHERE teacher_id = ?");
            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();
            $assignments_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $user['subjectClassPairs'] = [];
            $class_levels = array_unique(array_column($assignments_raw, 'class_level'));
            $classrooms = array_unique(array_column($assignments_raw, 'classroom'));

            $pairs = [];
            foreach ($assignments_raw as $assign) {
                $key = $assign['subject_id'];
                if (!isset($pairs[$key])) { $pairs[$key] = ['subjectId' => $assign['subject_id'], 'classLevels' => [], 'classrooms' => []]; }
                $pairs[$key]['classLevels'][] = $assign['class_level'];
                $pairs[$key]['classrooms'][] = $assign['classroom'];
            }
             foreach ($pairs as &$pair) {
                $pair['classLevels'] = array_values(array_unique($pair['classLevels'])); sort($pair['classLevels']);
                $pair['classrooms'] = array_values(array_unique($pair['classrooms'])); sort($pair['classrooms']);
            }
            $user['subjectClassPairs'] = json_encode(array_values($pairs));
            $_SESSION['user']['subjectClassPairs'] = $user['subjectClassPairs'];

            if (!empty($class_levels) && !empty($classrooms)) {
                $placeholders_levels = implode(',', array_fill(0, count($class_levels), '?'));
                $placeholders_classrooms = implode(',', array_fill(0, count($classrooms), '?'));

                $sql_students = "SELECT id, student_code, name, class_level, classroom FROM users WHERE role = 'student' AND class_level IN ($placeholders_levels) AND classroom IN ($placeholders_classrooms)";
                $stmt_students = $conn->prepare($sql_students);
                $types = str_repeat('s', count($class_levels) + count($classrooms));
                $params = array_merge($class_levels, $classrooms);
                $stmt_students->bind_param($types, ...$params);
                $stmt_students->execute();
                $initialData['students'] = $stmt_students->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_students->close();

                $student_ids = array_column($initialData['students'], 'id');
                if (!empty($student_ids)) {
                    $student_ids_placeholders = implode(',', array_map('intval', $student_ids));
                    $initialData['attendance'] = $conn->query("SELECT * FROM attendance WHERE student_id IN ($student_ids_placeholders)")->fetch_all(MYSQLI_ASSOC);
                }
            } else {
                 $initialData['students'] = [];
            }
            
            $initialData['scoreComponents'] = $conn->query("SELECT * FROM score_components WHERE teacher_id = $teacher_id")->fetch_all(MYSQLI_ASSOC);
            $initialData['activityComponents'] = $conn->query("SELECT * FROM activity_components WHERE teacher_id = $teacher_id")->fetch_all(MYSQLI_ASSOC);
            
            break;
            
        case 'student':
            $student_id = $user['id'];
            $initialData['attendance'] = $conn->query("SELECT * FROM attendance WHERE student_id = $student_id")->fetch_all(MYSQLI_ASSOC);
            $initialData['scores'] = $conn->query("SELECT s.*, sc.subject_id FROM scores s JOIN score_components sc ON s.component_id = sc.id WHERE s.student_id = $student_id")->fetch_all(MYSQLI_ASSOC);
            $initialData['characteristicsScores'] = $conn->query("SELECT es.* FROM evaluation_scores es JOIN evaluation_items ei ON es.item_id = ei.id WHERE es.student_id = $student_id AND ei.item_group IN ('คุณลักษณะอันพึงประสงค์', 'การอ่าน คิดวิเคราะห์ และเขียน')")->fetch_all(MYSQLI_ASSOC);
            $initialData['valuesCompetenciesScores'] = $conn->query("SELECT es.* FROM evaluation_scores es JOIN evaluation_items ei ON es.item_id = ei.id WHERE es.student_id = $student_id AND ei.item_group IN ('ค่านิยมหลักของคนไทย 12 ประการ', 'สมรรถนะสำคัญของผู้เรียน')")->fetch_all(MYSQLI_ASSOC);
            $initialData['activityScores'] = $conn->query("SELECT * FROM activity_scores WHERE student_id = $student_id")->fetch_all(MYSQLI_ASSOC);
            break;
    }

    $response = ['success' => true, 'initialData' => $initialData];

} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Server Error: ' . $e->getMessage();
}

$conn->close();

ob_end_clean(); // <--- ล้าง Output ที่ไม่ต้องการทั้งหมดทิ้งไป
echo json_encode($response); // <--- ส่งเฉพาะข้อมูล JSON ที่ถูกต้องออกไป
?>