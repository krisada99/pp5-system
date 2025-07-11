<?php
// config/database.php

session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // User ที่สร้างไว้
define('DB_PASS', 'CTC@dmin01'); // รหัสผ่านของ User
define('DB_NAME', 'pp5_db');

function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        // ใช้ ob_clean() เพื่อล้าง output ที่อาจเกิดขึ้นก่อนหน้า
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
        exit();
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

function checkLogin($roles = []) {
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Authorization is required.']);
        exit();
    }
    if (!empty($roles) && !in_array($_SESSION['user']['role'], $roles)) {
        http_response_code(403);
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
        exit();
    }
}