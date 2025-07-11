// assets/js/login.js - Improved Error Handling Version

$(document).ready(function() {
    function showLoading() { $('#loading-overlay').show(); }
    function hideLoading() { $('#loading-overlay').hide(); }
    
    function handleError(title, text) {
        hideLoading();
        console.error(title, text);
        Swal.fire({
            icon: 'error',
            title: title || 'เกิดข้อผิดพลาด',
            text: text || 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่',
            confirmButtonColor: 'var(--primary-color)'
        });
    }

    // --- การตั้งค่า UI พื้นฐาน ---
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim();
    $('input').on('focus', function() { $(this).css('border-color', primaryColor).css('box-shadow', `0 0 0 2px ${primaryColor}60`); });
    $('input').on('blur', function() { $(this).css('border-color', '').css('box-shadow', 'none'); });

    // --- การสลับ Tab ---
    $('#teacher-tab, #student-tab').click(function() {
        const targetId = $(this).attr('id');
        $('.login-tab').removeClass('active');
        $('.login-form-content').removeClass('active');
        $(this).addClass('active');
        if (targetId === 'teacher-tab') {
            $('#teacher-form-content').addClass('active');
        } else {
            $('#student-form-content').addClass('active');
        }
    });
    
    // --- ฟังก์ชันสำหรับเรียก API ---
    async function apiCall(action, data) {
        try {
            const response = await fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}`);
            }
            
            // --- ส่วนที่ปรับปรุง ---
            // อ่าน response เป็น text ก่อน เพื่อดูว่ามีอักขระแปลกปลอมหรือไม่
            const responseText = await response.text();
            try {
                // พยายามแปลง text เป็น JSON
                return JSON.parse(responseText);
            } catch (e) {
                // ถ้าแปลงไม่ได้ แสดงว่า response ไม่ใช่ JSON ที่ถูกต้อง
                console.error("Invalid JSON response received from server.");
                console.error("Raw response:", responseText);
                throw new Error("เซิร์ฟเวอร์มีการตอบสนองที่ไม่ถูกต้อง");
            }

        } catch (error) {
            console.error('API Call Failed:', error);
            // ส่งต่อ error ไปให้ส่วนที่เรียกใช้จัดการ
            throw error;
        }
    }


    // --- การล็อกอินสำหรับครู/Admin ---
    $('#login-form').validate({
        rules: { username: { required: true }, password: { required: true } },
        messages: { username: "กรุณากรอกชื่อผู้ใช้", password: "กรุณากรอกรหัสผ่าน" },
        errorElement: 'div', 
        errorClass: 'error',
        submitHandler: function(form) {
            const username = $('#username').val().trim();
            const password = $('#password').val().trim();
            showLoading();
            
            apiCall('login', { username, password })
                .then(result => {
                    hideLoading();
                    if (result.success) {
                        sessionStorage.setItem('user', JSON.stringify(result.user));
                        window.location.href = 'index.php';
                    } else {
                        handleError('ล็อกอินไม่สำเร็จ', result.message);
                    }
                })
                .catch(error => {
                    handleError('เกิดข้อผิดพลาดในการเชื่อมต่อ', error.message);
                });
        }
    });

    // --- การตรวจสอบข้อมูลสำหรับนักเรียน ---
    $('#student-login-form').validate({
        rules: { 'student-id': { required: true } },
        messages: { 'student-id': "กรุณากรอกรหัสนักเรียน" },
        errorElement: 'div',
        errorClass: 'error',
        submitHandler: function(form) {
            const studentCode = $('#student-id').val().trim();
            showLoading();
            
            apiCall('studentLogin', { studentCode })
                .then(result => {
                    hideLoading();
                    if (result.success) {
                        sessionStorage.setItem('user', JSON.stringify(result.user));
                        window.location.href = 'index.php';
                    } else {
                        handleError('ไม่พบข้อมูล', result.message);
                    }
                })
                .catch(error => {
                    handleError('เกิดข้อผิดพลาด', error.message);
                });
        }
    });
});