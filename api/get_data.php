<?php
// api/get_data.php
header("Content-Type: application/json; charset=UTF-8"); // <--- 
require_once '../config/database.php';
checkLogin(); // Ensure user is logged in

$conn = getDbConnection();
$user = $_SESSION['user'];
$initialData = [];

// 1. Get Settings
$settings_result = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$initialData['settings'] = $settings;

// Fetch data based on user role
if ($user['role'] === 'admin') {
    // Admin gets all data
    $initialData['subjects'] = $conn->query("SELECT * FROM subjects ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    $initialData['students'] = $conn->query("SELECT id, student_code, name, class_level, classroom FROM users WHERE role = 'student' ORDER BY class_level, classroom, student_code")->fetch_all(MYSQLI_ASSOC);
    // Add more queries for teachers, etc.
    
} elseif ($user['role'] === 'teacher') {
    // Teacher gets data relevant to them
    $teacher_id = $user['id'];
    
    // Get assigned subjects, classes, classrooms
    $stmt = $conn->prepare("SELECT DISTINCT subject_id, class_level, classroom FROM teacher_assignments WHERE teacher_id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $subject_ids = array_unique(array_column($assignments, 'subject_id'));
    $class_levels = array_unique(array_column($assignments, 'class_level'));
    
    // Fetch relevant subjects
    if (!empty($subject_ids)) {
        $ids = implode(',', $subject_ids);
        $initialData['subjects'] = $conn->query("SELECT * FROM subjects WHERE id IN ($ids) ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    }
    
    // Fetch relevant students
    if (!empty($class_levels)) {
        $levels = "'" . implode("','", $class_levels) . "'";
        $initialData['students'] = $conn->query("SELECT id, student_code, name, class_level, classroom FROM users WHERE role = 'student' AND class_level IN ($levels) ORDER BY class_level, classroom, student_code")->fetch_all(MYSQLI_ASSOC);
    }
    //... Fetch other relevant data like scores, attendance for their students
    
} elseif ($user['role'] === 'student') {
    // Student gets their own data
    $student_id = $user['id'];
    $stmt = $conn->prepare("SELECT * FROM attendance WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $initialData['attendance'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    // ... Fetch scores, evaluations etc.
}

echo json_encode(['success' => true, 'initialData' => $initialData]);
$conn->close();
?>