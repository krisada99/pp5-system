<?php
// /hash_checker.php

// ---- กรุณาแก้ไขค่านี้ ----
$password_to_test = 'admin1234'; // <--- ใส่รหัสผ่านจริงที่คุณใช้ล็อกอินตรงนี้

echo "<!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><title>Password Hash Checker</title>";
echo "<style>body { font-family: sans-serif; line-height: 1.6; } pre { background:#eee; padding:10px; border:1px solid #ccc; word-wrap: break-word; } li { margin-bottom: 10px; }</style>";
echo "</head><body>";
echo "<h1>เครื่องมือตรวจสอบการเข้ารหัสรหัสผ่าน</h1>";
echo "<p>กำลังทดสอบรหัสผ่าน: <strong>" . htmlspecialchars($password_to_test) . "</strong></p>";

// สร้าง hash จากรหัสผ่านด้านบน
$hashed_password = password_hash($password_to_test, PASSWORD_DEFAULT);

echo "<p>รหัสผ่านที่ถูกเข้ารหัส (Hash):</p>";
echo "<pre>" . htmlspecialchars($hashed_password) . "</pre>";
echo "<p>ความยาวของ Hash: <strong>" . strlen($hashed_password) . "</strong> ตัวอักษร</p>";

echo "<hr>";
echo "<h2>สิ่งที่ต้องทำ:</h2>";
echo "<ol>";
echo "<li>ไปที่ฐานข้อมูลของคุณ (phpMyAdmin) และเปิดตาราง `users`</li>";
echo "<li>ค้นหา user ที่คุณใช้ทดสอบ และคัดลอกค่าจากคอลัมน์ `password` มา</li>";
echo "<li><strong>เปรียบเทียบ:</strong> ค่าที่คัดลอกมากับ 'รหัสผ่านที่ถูกเข้ารหัส' ด้านบน เหมือนกันทุกประการหรือไม่?</li>";
echo "<li><strong>ตรวจสอบความยาว:</strong> Hash ที่ถูกต้องจะมีความยาว <strong>60 ตัวอักษร</strong>. หากค่าในฐานข้อมูลของคุณสั้นกว่านี้ แสดงว่ามันถูกตัดออก และนี่คือสาเหตุของปัญหา</li>";
echo "<li><strong>วิธีแก้:</strong> หากค่าในฐานข้อมูลสั้นกว่า ให้เปลี่ยนชนิดของคอลัมน์ `password` เป็น `VARCHAR(255)` จากนั้นลองเพิ่มผู้ใช้งานใหม่และทดสอบอีกครั้ง</li>";
echo "</ol>";
echo "</body></html>";
?>