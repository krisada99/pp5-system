<?php
// /index.php

// 1. เริ่ม Session เพื่อเข้าถึงข้อมูลการล็อกอิน
session_start();

// 2. ตรวจสอบว่ามีข้อมูล 'user' ใน Session หรือไม่
// ถ้าไม่มี (ยังไม่ได้ล็อกอิน) ให้ redirect กลับไปหน้า login.php
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit(); // หยุดการทำงานของสคริปต์ทันที
}

// 3. ดึงข้อมูลผู้ใช้จาก Session มาเก็บในตัวแปร
$user = $_SESSION['user'];

// 4. (Optional) ดึงการตั้งค่าพื้นฐานจากฐานข้อมูลเพื่อใช้แสดงผลทันที
// ส่วนนี้ช่วยให้ Title และ Theme สี ถูกต้องตั้งแต่โหลดหน้าครั้งแรก
require_once 'config/database.php';
$conn = getDbConnection();
$settings_result = $conn->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$conn->close();

$theme_color = $settings['theme_color'] ?? '#FF69B4';
$system_name = $settings['system_name'] ?? 'ระบบ ปพ.5 ออนไลน์';

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($system_name); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.19/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* ตั้งค่า Theme สีหลักจาก PHP */
        :root {
            --primary-color: <?php echo htmlspecialchars($theme_color); ?>;
        }
    </style>
</head>
<body>

<div id="pre-choice-overlay" class="fixed top-0 left-0 w-full h-full bg-white z-20" style="display: none;"></div>

<header class="bg-pink-200 py-2 shadow-md">
    <div class="container mx-auto px-4 flex justify-between items-center">
        <div class="flex items-center space-x-3">
            <img src="" alt="โลโก้" class="h-12 w-auto"> <h1 class="text-xl md:text-2xl font-bold text-gray-800 hidden sm:block"></h1> </div>

        <div class="flex items-center space-x-4">
            <div class="version-display">Version 2.0 (PHP)</div>
            <button id="back-to-choice-btn" title="กลับไปหน้าเลือกเมนูหลัก" class="hidden bg-white text-gray-600 hover:bg-gray-200 rounded-full p-2 focus:outline-none focus:ring-2 focus:ring-offset-2" style="--tw-ring-color: var(--primary-color);">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            </button>
            <span id="user-info" class="text-gray-700 hidden md:inline"></span>
            <button id="logout-btn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">ออกจากระบบ</button>
        </div>
    </div>
</header>

<nav class="bg-white shadow-md">
    <div class="container mx-auto px-4">
        <div id="nav-tabs" class="flex flex-wrap justify-center md:justify-start space-x-1 md:space-x-6 py-4">
            </div>
    </div>
</nav>

<main class="container mx-auto px-4 py-6 pb-20">
    <div id="dashboard" class="tab-content">
        <div id="attendance-dashboard-view"></div>
        <div id="scoring-dashboard-view" class="hidden"></div>
    </div>

    <div id="class-levels" class="tab-content"></div>
    <div id="subjects" class="tab-content"></div>
    <div id="students" class="tab-content"></div>
    <div id="teachers" class="tab-content"></div>
    <div id="settings" class="tab-content"></div>

    <div id="attendance" class="tab-content"></div>
    <div id="scoring" class="tab-content"></div>
    <div id="characteristics" class="tab-content"></div>
    <div id="values-competencies" class="tab-content"></div>
    <div id="activities" class="tab-content"></div>
    <div id="p5-cover" class="tab-content"></div>

    <div id="reports" class="tab-content"></div>
    <div id="classroom-reports" class="tab-content"></div>
    <div id="school-report" class="tab-content"></div>
    <div id="monthly-report" class="tab-content"></div>
    <div id="score-report" class="tab-content"></div>
    <div id="characteristics-report" class="tab-content"></div>
    <div id="values-competencies-report" class="tab-content"></div>
    <div id="activities-report" class="tab-content"></div>

    <div id="student-view" class="tab-content"></div>
</main>

<footer class="bg-pink-200 py-4 border-t fixed bottom-0 left-0 w-full">
    <div class="container mx-auto px-4 text-center text-gray-600">
        <p></p> </div>
</footer>

<?php
    // ใช้ PHP include เพื่อนำเข้าไฟล์ HTML ของ Modal ต่างๆ
    if (file_exists('views/modals/subject_modal.html')) include 'views/modals/subject_modal.html';
    if (file_exists('views/modals/teacher_modal.html')) include 'views/modals/teacher_modal.html';
    if (file_exists('views/modals/student_modal.html')) include 'views/modals/student_modal.html';
    if (file_exists('views/modals/import_modal.html')) include 'views/modals/import_modal.html';
    if (file_exists('views/modals/settings_modal.html')) include 'views/modals/settings_modal.html';
    if (file_exists('views/modals/score_component_modal.html')) include 'views/modals/score_component_modal.html';
    if (file_exists('views/modals/activity_component_modal.html')) include 'views/modals/activity_component_modal.html';
    if (file_exists('views/modals/teacher_choice_modal.html')) include 'views/modals/teacher_choice_modal.html';
?>

<div id="loading-overlay" class="loading">
    <div class="loading-content">
        <div class="spinner"></div>
        <p class="mt-2">กำลังประมวลผล...</p>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.19.3/dist/jquery.validate.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.19/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    // ส่งข้อมูลผู้ใช้จาก PHP Session ไปเก็บใน sessionStorage ของ Browser
    // เพื่อให้ main.js สามารถเข้าถึงได้ทันทีโดยไม่ต้องเรียก API อีก
    sessionStorage.setItem('user', JSON.stringify(<?php echo json_encode($user); ?>));
</script>

<script src="assets/js/main.js"></script>

</body>
</html>