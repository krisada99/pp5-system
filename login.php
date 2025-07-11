<?php
// /login.php

// 1. เริ่ม Session
session_start();

// 2. ตรวจสอบว่ามี Session ของผู้ใช้อยู่หรือไม่
// ถ้ามี (เคยล็อกอินแล้ว) ให้ redirect ไปยังหน้าหลักของระบบ (index.php) ทันที
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}

// 3. เรียกใช้ไฟล์ config เพื่อเชื่อมต่อฐานข้อมูลและดึงข้อมูลการตั้งค่า
// แม้จะอยู่หน้า login ก็จำเป็นต้องดึงข้อมูลเพื่อแสดงผลให้ถูกต้อง
require_once 'config/database.php';
$conn = getDbConnection(); // ฟังก์ชันนี้มาจาก config/database.php

$settings = [];
try {
    $result = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // หากฐานข้อมูลมีปัญหา ให้ใช้ค่าเริ่มต้นแทน
    $settings = [
        'system_name' => 'ระบบ ปพ.5',
        'logo_url' => '',
        'header_text' => 'กรุณาตั้งค่าระบบ',
        'footer_text' => '© System',
        'theme_color' => '#FF69B4'
    ];
}
$conn->close();

// 4. อ่านเนื้อหาจากไฟล์ login.html ต้นฉบับ
// เราจะใช้เนื้อหาจากไฟล์นี้เป็น Template
$template = file_get_contents('views/partials/login.html'); // สมมติว่าย้าย login.html ไปไว้ที่นี่

// 5. เตรียมข้อมูลที่จะนำไปแทนที่ Placeholder ใน Template
$theme_color = $settings['theme_color'] ?? '#FF69B4';

// สร้างสีธีมโทนสว่างจากสีหลัก
$themeMap = [
    '#FF69B4' => '#fbcfe8', '#2196F3' => '#bbdefb', '#3F51B5' => '#c5cae9',
    '#4CAF50' => '#c8e6c9', '#FFC107' => '#fff9c4', '#9C27B0' => '#e1bee7',
    '#FF9800' => '#ffe0b2', '#F44336' => '#ffcdd2'
];
$light_theme_color = $themeMap[$theme_color] ?? '#fbcfe8';

$replacements = [
    '{{SYSTEM_NAME}}' => htmlspecialchars($settings['system_name'] ?? 'ระบบ ปพ.5'),
    '{{LOGO_URL}}' => htmlspecialchars($settings['logo_url'] ?? ''),
    '{{HEADER_TEXT}}' => htmlspecialchars($settings['header_text'] ?? 'ระบบ ปพ.5'),
    '{{FOOTER_TEXT}}' => htmlspecialchars($settings['footer_text'] ?? '© System'),
    '{{THEME_COLOR}}' => htmlspecialchars($theme_color),
    '{{THEME_LIGHT_COLOR}}' => htmlspecialchars($light_theme_color)
];

// 6. ทำการแทนที่ค่าใน Template
$output = str_replace(array_keys($replacements), array_values($replacements), $template);

// 7. แก้ไขส่วนของ script ใน template ให้เรียกใช้ไฟล์ภายนอก
// ลบ inline script เดิมออก แล้วใส่ link ไปยัง assets/js/login.js
$output = str_replace('<script></script>', '<script src="assets/js/login.js"></script>', $output);


// 8. แสดงผล HTML สุดท้ายออกไปที่ Browser
echo $output;

?>