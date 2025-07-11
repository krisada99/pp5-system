// /assets/js/main.js - ฉบับแก้ไขสมบูรณ์

// =================================================================
// ช่วงที่ 1: Global State, Helper Functions, and Initialization
// =================================================================

// --- Global State & Variables ---
const appState = {
    user: null,
    settings: {},
    subjects: [],
    students: [],
    teachers: [],
    attendance: [],
    attendanceMap: {},
    scoreComponents: [],
    scores: [],
    characteristicsItems: [],
    characteristicsScores: [],
    activityComponents: [],
    activityScores: [],
    valuesCompetenciesItems: [],
    valuesCompetenciesScores: [],
    isDataLoaded: false,
    currentAttendance: {}
};

let pieChart = null;
let barChart = null;
let gradeDistChart = null;
let componentAvgChart = null;

// --- Helper Functions ---
function showLoading() { $('#loading-overlay').show(); }
function hideLoading() { $('#loading-overlay').hide(); }

function handleError(error) {
    hideLoading();
    console.error('API Error:', error);
    let errorMessage = 'เกิดข้อผิดพลาด ไม่สามารถดำเนินการได้';
    if (error && typeof error.message === 'string') {
        errorMessage = error.message;
    }
    Swal.fire({
        icon: 'error',
        title: 'เกิดข้อผิดพลาด',
        text: errorMessage,
        confirmButtonColor: 'var(--primary-color)'
    });
}

const api = {
    async call(endpoint, method = 'GET', body = null) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
        };
        if (body) {
            options.body = JSON.stringify(body);
        }
        
        try {
            const response = await fetch(`api/${endpoint}`, options);
            if (!response.ok) {
                try {
                    const errData = await response.json();
                    throw new Error(errData.message || `HTTP error! status: ${response.status}`);
                } catch (e) {
                     throw new Error(`HTTP error! status: ${response.status}`);
                }
            }
            const responseText = await response.text();
            try {
                return JSON.parse(responseText);
            } catch (e) {
                console.error("Invalid JSON response:", responseText);
                throw new Error("เซิร์ฟเวอร์มีการตอบสนองที่ไม่ถูกต้อง (Invalid JSON)");
            }
        } catch (error) {
            console.error(`API call failed for ${endpoint}:`, error);
            throw error;
        }
    }
};

// --- Initialization ---
$(document).ready(function() {
    const userJson = sessionStorage.getItem('user');
    if (!userJson) {
        window.location.href = 'login.php';
        return;
    }
    
    appState.user = JSON.parse(userJson);
    
    loadInitialData().then(() => {
        initializeUI();
        initializeNavigation();
        
        if (appState.user.role === 'teacher') {
            const teacherMode = sessionStorage.getItem('teacherChoiceMode');
            if (!teacherMode) {
                const teacherName = appState.user.name;
                $('#teacher-choice-modal h2').text(`ยินดีต้อนรับ, คุณครู ${teacherName}`);
                $('#pre-choice-overlay').show();
                openModal('teacher-choice-modal');
            } else {
                loadContentForTab($('#nav-tabs .nav-tab.active').data('tab') || 'dashboard');
            }
        } else if (appState.user.role === 'student') {
             loadContentForTab('student-view');
        } else {
            loadContentForTab('dashboard');
        }
    }).catch(error => {
        handleError(error);
    });

    setupEventHandlers();
});

async function loadInitialData(isRefresh = false) {
    showLoading();
    try {
        const result = await api.call('data_loader.php');
        if (result.success) {
            Object.assign(appState, result.initialData);
            appState.attendanceMap = (appState.attendance || []).reduce((acc, record) => {
                const key = `${record.student_id}-${record.subject_id}-${record.attendance_date}`;
                acc[key] = record;
                return acc;
            }, {});
            appState.isDataLoaded = true;
            if (isRefresh) {
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: 'ข้อมูลอัพเดทแล้ว', showConfirmButton: false, timer: 1500
                });
            }
        } else {
            throw new Error(result.message);
        }
    } finally {
        hideLoading();
    }
}

// =================================================================
// ช่วงที่ 2: UI Initialization, Navigation, and Core Event Handlers
// =================================================================

function initializeUI() {
    const settings = appState.settings || {};
    applyTheme(settings.theme_color?.setting_value || '#FF69B4');
    
    let roleDisplay = '';
    const userRole = appState.user.role;
    if (userRole === 'admin') roleDisplay = 'ผู้ดูแลระบบ';
    else if (userRole === 'teacher') roleDisplay = 'ครู';
    else if (userRole === 'student') roleDisplay = 'นักเรียน';
    
    $('#user-info').html(`${appState.user.name} (${roleDisplay})`);
    $('title').text(settings.system_name?.setting_value || 'ระบบ ปพ.5');
    $('header h1').text(settings.header_text?.setting_value || 'ระบบ ปพ.5');
    $('header img').attr('src', settings.logo_url?.setting_value || '');
    $('footer p').text(settings.footer_text?.setting_value || '© Power by KruJakkrapong');

    if (userRole === 'teacher') {
        $('#back-to-choice-btn').removeClass('hidden');
    } else {
        $('#back-to-choice-btn').addClass('hidden');
    }
}

function applyTheme(primaryColor) {
    if (!primaryColor) return;
    const themeMap = {
        '#FF69B4': { light: '#fbcfe8' }, '#2196F3': { light: '#bbdefb' },
        '#3F51B5': { light: '#c5cae9' }, '#4CAF50': { light: '#c8e6c9' },
        '#FFC107': { light: '#fff9c4' }, '#9C27B0': { light: '#e1bee7' },
        '#FF9800': { light: '#ffe0b2' }, '#F44336': { light: '#ffcdd2' }
    };
    const lightColor = themeMap[primaryColor]?.light || '#fbcfe8';
    document.documentElement.style.setProperty('--primary-color', primaryColor);
    $('header, footer').css('background-color', lightColor);
}

function initializeNavigation() {
    const navTabs = $('#nav-tabs');
    navTabs.empty();
    let tabsHtml = '';
    const userRole = appState.user.role;

    if (userRole === 'admin') {
        tabsHtml = `
            <div class="nav-tab active" data-tab="dashboard">แดชบอร์ด</div>
            <div class="nav-tab" data-tab="class-levels">จัดการระดับชั้น</div>
            <div class="nav-tab" data-tab="subjects">จัดการรายวิชา</div>
            <div class="nav-tab" data-tab="students">จัดการนักเรียน</div>
            <div class="nav-tab" data-tab="teachers">จัดการครูผู้สอน</div>
            <div class="nav-tab" data-tab="settings">ตั้งค่าระบบ</div>
        `;
    } else if (userRole === 'teacher') {
        const mode = sessionStorage.getItem('teacherChoiceMode');
        switch (mode) {
            case 'scoring': tabsHtml = `<div class="nav-tab active" data-tab="dashboard">แดชบอร์ด</div><div class="nav-tab" data-tab="students">ดูข้อมูลนักเรียน</div><div class="nav-tab" data-tab="scoring">จัดการคะแนน</div><div class="nav-tab" data-tab="score-report">รายงานสรุปคะแนน</div>`; break;
            case 'characteristics': tabsHtml = `<div class="nav-tab active" data-tab="characteristics">ประเมินคุณลักษณะฯ</div><div class="nav-tab" data-tab="characteristics-report">รายงานคุณลักษณะฯ</div>`; break;
            case 'values-competencies': tabsHtml = `<div class="nav-tab active" data-tab="values-competencies">ประเมินค่านิยมฯ</div><div class="nav-tab" data-tab="values-competencies-report">รายงานค่านิยมฯ</div>`; break;
            case 'activities': tabsHtml = `<div class="nav-tab active" data-tab="activities">ประเมินกิจกรรมฯ</div><div class="nav-tab" data-tab="activities-report">รายงานกิจกรรมฯ</div>`; break;
            case 'p5-cover': tabsHtml = `<div class="nav-tab active" data-tab="p5-cover">พิมพ์ปก ปพ.5</div>`; break;
            default:
                tabsHtml = `
                    <div class="nav-tab active" data-tab="dashboard">แดชบอร์ด</div>
                    <div class="nav-tab" data-tab="students">ดูข้อมูลนักเรียน</div>
                    <div class="nav-tab" data-tab="attendance">จัดการเช็คเวลาเรียน</div>
                    <div class="nav-tab" data-tab="reports">รายงานรายบุคคล</div>
                `;
        }
    } else if (userRole === 'student') {
        navTabs.hide();
    }
    navTabs.html(tabsHtml);
}

function setupEventHandlers() {
    $('#logout-btn').on('click', async () => {
        try { await api.call('auth.php', 'POST', { action: 'logout' }); } 
        catch (error) { console.error("Logout failed but clearing session anyway.", error); } 
        finally { sessionStorage.clear(); window.location.href = 'login.php'; }
    });

    $('#nav-tabs').on('click', '.nav-tab', function() {
        if ($(this).hasClass('active')) return;
        const tabId = $(this).data('tab');
        $('.nav-tab').removeClass('active');
        $(this).addClass('active');
        loadContentForTab(tabId);
    });

    $('#teacher-choice-modal').on('click', 'button', function() {
        const id = $(this).attr('id');
        let choice = 'attendance';
        if (id.includes('scoring')) choice = 'scoring';
        else if (id.includes('characteristics')) choice = 'characteristics';
        else if (id.includes('values-competencies')) choice = 'values-competencies';
        else if (id.includes('activities')) choice = 'activities';
        else if (id.includes('p5-cover')) choice = 'p5-cover';
        
        sessionStorage.setItem('teacherChoiceMode', choice);
        $('#pre-choice-overlay').hide();
        closeModal('teacher-choice-modal');
        initializeNavigation();
        const firstTabOfMode = $('#nav-tabs .nav-tab:first').data('tab');
        loadContentForTab(firstTabOfMode);
    });

    $('#back-to-choice-btn').on('click', function() {
        Swal.fire({
            title: 'กลับไปหน้าเลือกเมนู?', icon: 'question', showCancelButton: true,
            confirmButtonText: 'ใช่, กลับไป', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: 'var(--primary-color)'
        }).then((result) => { if (result.isConfirmed) { sessionStorage.removeItem('teacherChoiceMode'); window.location.reload(); } });
    });

    $(document).on('click', '.close, .close-modal', function() { $(this).closest('.modal').hide(); });
    $(window).on('click', function(event) { if ($(event.target).is('.modal') && !$(event.target).hasClass('non-dismissible')) { $(event.target).hide(); } });
}

function openModal(modalId) { const modal = document.getElementById(modalId); if (modal) $(modal).show(); }
function closeModal(modalId) { const modal = document.getElementById(modalId); if (modal) $(modal).hide(); }

// =================================================================
// ช่วงที่ 3: Content Loading, Page Initializers, and Renderers
// =================================================================

function loadContentForTab(tabId) {
    // --- ส่วนที่แก้ไข ---
    // แยกประเภทของหน้าเพจ
    const partialPages = ['class-levels', 'subjects', 'students', 'teachers', 'settings', 'dashboard', 'student-view'];
    const teacherModePages = ['scoring', 'characteristics', 'values-competencies', 'activities', 'p5-cover', 'attendance'];
    const reportPages = [ /* ... report page ids ... */ ];
    
    $('.tab-content').removeClass('active');
    showLoading();

    let viewPath = '';
    const targetDiv = $('#' + tabId);

    // กำหนด path ของไฟล์ HTML ตามประเภทของหน้า
    if (partialPages.includes(tabId)) {
        viewPath = `views/partials/${tabId}.html`;
    } else if (teacherModePages.includes(tabId)) {
        viewPath = `views/teacher_modes/${tabId}.html`;
    } else if (reportPages.includes(tabId)) {
        viewPath = `views/reports/report_${tabId.replace('-report', '')}.html`;
    }

    if (viewPath) {
        // ถ้ามี path ให้ fetch ไฟล์ HTML เข้ามาใส่ใน div
        fetch(viewPath)
            .then(response => response.ok ? response.text() : Promise.reject(`ไม่พบไฟล์: ${viewPath}`))
            .then(html => {
                targetDiv.html(html).addClass('active');
                runTabInitializer(tabId); // เรียก initializer หลังโหลดเสร็จ
            })
            .catch(error => handleError({ message: `ไม่สามารถโหลดหน้า ${tabId} ได้` }))
            .finally(hideLoading);
    } else {
        // ถ้าเป็นหน้าที่ไม่มีไฟล์ HTML (อาจเป็น div ว่างๆ ที่รอการสร้าง element ด้วย JS)
        targetDiv.addClass('active');
        runTabInitializer(tabId);
        hideLoading();
    }
}


function runTabInitializer(tabId) {
    const initializers = {
        'dashboard': loadDashboard,
        'class-levels': loadClassLevelSettings,
        'subjects': loadSubjects,
        'students': loadStudents,
        'teachers': loadTeachers,
        'settings': loadSettings,
        'student-view': renderStudentView,
        'attendance': populateAttendanceFilters,
        // ... other initializers ...
    };
    if (initializers[tabId]) {
        initializers[tabId]();
    } else {
        console.warn(`No initializer function found for tab: ${tabId}`);
    }
}

// =================================================================
// ช่วงที่ 4: Dashboard และ Student View Functions
// =================================================================

function loadDashboard() {
    const { user, students, attendance } = appState;
    const today = new Date().toISOString().slice(0, 10);
    
    let filteredStudents = students;
    if (user.role === 'teacher' && user.subjectClassPairs) {
        try {
            const pairs = JSON.parse(user.subjectClassPairs);
            const validClasses = [...new Set(pairs.flatMap(p => p.classLevels))];
            const validClassrooms = [...new Set(pairs.flatMap(p => p.classrooms))];
            filteredStudents = students.filter(s => validClasses.includes(s.class_level) && validClassrooms.includes(s.classroom));
        } catch(e) { console.error("Could not parse teacher assignments for dashboard", e); }
    }
    
    const totalStudents = filteredStudents.length;
    const todayAttendance = (attendance || []).filter(a => a.attendance_date === today);

    const presentCount = todayAttendance.filter(a => a.status === 'present').length;
    const absentCount = todayAttendance.filter(a => a.status === 'absent').length;
    const leaveCount = todayAttendance.filter(a => a.status === 'leave').length;
    const lateCount = todayAttendance.filter(a => a.status === 'late').length;

    animateCountUp('total-students', totalStudents);
    animateCountUp('present-students', presentCount);
    animateCountUp('absent-students', absentCount);
    animateCountUp('leave-students', leaveCount);
    animateCountUp('late-students', lateCount);
}

function animateCountUp(elementId, endValue, duration = 1500) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    let startValue = 0;
    const startTime = performance.now();

    function update(currentTime) {
        const elapsedTime = currentTime - startTime;
        if (elapsedTime > duration) {
            element.textContent = endValue.toLocaleString();
            return;
        }
        const progress = elapsedTime / duration;
        const currentValue = Math.floor(endValue * progress);
        element.textContent = currentValue.toLocaleString();
        requestAnimationFrame(update);
    }
    requestAnimationFrame(update);
}

function renderStudentView() {
    // ... logic for student view ...
}

// =================================================================
// ช่วงที่ 4: Event Handlers for Forms and Actions (ในโค้ดเดิม)
// =================================================================

// --- CRUD and Action Buttons (using event delegation) ---
const mainContainer = $('main');

// ** Delete Subject **
mainContainer.on('click', '.delete-subject', function() {
    const subjectId = $(this).data('id');
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: "ข้อมูลรายวิชาและข้อมูลทั้งหมดที่เกี่ยวข้อง (การเช็คชื่อ, คะแนน) จะถูกลบอย่างถาวร!",
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33',
        confirmButtonText: 'ใช่, ลบเลย!', cancelButtonText: 'ยกเลิก'
    }).then(async (result) => {
        if (result.isConfirmed) {
            showLoading();
            try {
                await api.call(`subjects.php?id=${subjectId}`, 'DELETE');
                await loadInitialData(true);
                loadSubjects(); // รีเฟรชตารางวิชา
            } catch(error) {
                handleError(error);
            } finally {
                hideLoading();
            }
        }
    });
});

// ** Delete Teacher **
mainContainer.on('click', '.delete-teacher', function() {
    const teacherId = $(this).data('id');
    Swal.fire({
        title: 'ยืนยันการลบครู?',
        text: "ข้อมูลครูและข้อมูลทั้งหมดที่เกี่ยวข้องจะถูกลบอย่างถาวร!",
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33',
        confirmButtonText: 'ใช่, ลบเลย!', cancelButtonText: 'ยกเลิก'
    }).then(async (result) => {
        if (result.isConfirmed) {
            showLoading();
            try {
                await api.call(`teachers.php?id=${teacherId}`, 'DELETE');
                await loadInitialData(true);
                loadTeachers(); // รีเฟรชตารางครู
            } catch(error) {
                handleError(error);
            } finally {
                hideLoading();
            }
        }
    });
});

// ** Delete Student **
mainContainer.on('click', '.delete-student', function() {
    const studentId = $(this).data('id');
    Swal.fire({
        title: 'ยืนยันการลบนักเรียน?',
        text: "ข้อมูลนักเรียนและข้อมูลทั้งหมดที่เกี่ยวข้องจะถูกลบอย่างถาวร!",
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33',
        confirmButtonText: 'ใช่, ลบเลย!', cancelButtonText: 'ยกเลิก'
    }).then(async (result) => {
        if (result.isConfirmed) {
            showLoading();
            try {
                await api.call(`students.php?id=${studentId}`, 'DELETE');
                await loadInitialData(true);
                loadStudents(); // รีเฟรชตารางนักเรียน
            } catch(error) {
                handleError(error);
            } finally {
                hideLoading();
            }
        }
    });
});

// ** Reset Teacher Password **
mainContainer.on('click', '.reset-password', function() {
    const teacherId = $(this).data('id');
    Swal.fire({
        title: 'รีเซ็ตรหัสผ่านใหม่',
        input: 'password',
        inputPlaceholder: 'กรอกรหัสผ่านใหม่อย่างน้อย 6 ตัวอักษร',
        inputAttributes: { minlength: 6, autocapitalize: 'off', autocorrect: 'off' },
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        inputValidator: (value) => !value || value.length < 6 ? 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร' : null
    }).then(async (result) => {
        if (result.isConfirmed) {
            showLoading();
            try {
                await api.call('teachers.php', 'PUT', {
                    action: 'resetPassword',
                    id: teacherId,
                    newPassword: result.value
                });
                await loadInitialData(true);
                loadTeachers(); // รีเฟรชตารางครูเพื่ออัปเดตสถานะ
            } catch(error) {
                handleError(error);
            } finally {
                hideLoading();
            }
        }
    });
});

// Event handlers for forms like student-form, subject-form, settings-form would also go here.
// For example:
$(document).on('submit', '#student-form', async function(e) {
    e.preventDefault();
    // Logic to handle student form submission...
});

// ===============================================================
// ช่วงที่ 5: ATTENDANCE PAGE SPECIFIC FUNCTIONS
// ===============================================================

function populateAttendanceFilters() {
    const { user, subjects } = appState;
    if (!user || !user.subjectClassPairs) {
        handleError({ message: "ข้อมูลการสอนของครูไม่ถูกต้อง" });
        return;
    }
    try {
        const assignments = JSON.parse(user.subjectClassPairs);
        const subjectSelect = $('#attendance-subject').empty().append('<option value="">-- เลือรายวิชา --</option>');
        const classSelect = $('#attendance-class').empty().append('<option value="">-- เลือกระดับชั้น --</option>');
        const classroomSelect = $('#attendance-classroom').empty().append('<option value="">-- เลือกห้องเรียน --</option>');

        [...new Set(assignments.map(a => a.subjectId))].forEach(id => {
            const subject = subjects.find(s => s.id == id);
            if (subject) subjectSelect.append(`<option value="${subject.id}">${subject.code} - ${subject.name}</option>`);
        });
        [...new Set(assignments.flatMap(a => a.classLevels))].sort().forEach(level => classSelect.append(`<option value="${level}">${level}</option>`));
        [...new Set(assignments.flatMap(a => a.classrooms))].sort().forEach(room => classroomSelect.append(`<option value="${room}">${room}</option>`));
        
        $('#attendance-date').val(new Date().toISOString().slice(0, 10));
        $('#start-attendance').off('click').on('click', renderAttendanceTable);
    } catch (e) {
        handleError({ message: "ไม่สามารถโหลดข้อมูลการสอนของครูได้" });
    }
}

function renderAttendanceTable() {
    const subjectId = $('#attendance-subject').val();
    const classLevel = $('#attendance-class').val();
    const classroom = $('#attendance-classroom').val();
    const date = $('#attendance-date').val();

    if (!subjectId || !classLevel || !classroom || !date) {
        Swal.fire('ข้อมูลไม่ครบ', 'กรุณาเลือกข้อมูลให้ครบทุกช่อง', 'warning');
        return;
    }

    const { students, subjects, attendanceMap } = appState;
    const subjectInfo = subjects.find(s => s.id == subjectId);
    const filteredStudents = students.filter(s => s.class_level === classLevel && s.classroom === classroom);

    if (filteredStudents.length === 0) {
        $('#attendance-list').hide();
        Swal.fire('ไม่พบนักเรียน', 'ไม่พบข้อมูลนักเรียนในห้องเรียนที่เลือก', 'info');
        return;
    }

    const tableBody = $('#attendance-table tbody').empty();
    appState.currentAttendance = {};

    filteredStudents.forEach((student, index) => {
        const key = `${student.id}-${subjectId}-${date}`;
        const record = attendanceMap[key] || {};
        const status = record.status || 'none';
        const remark = record.remark || '';

        appState.currentAttendance[student.id] = { studentId: student.id, subjectId: parseInt(subjectId), date, status, remark };
        
        const statusText = { present: 'มาเรียน', absent: 'ขาด', leave: 'ลา', late: 'สาย', none: 'ยังไม่เช็ค' };
        const row = `
            <tr data-student-id="${student.id}">
                <td class="p-3 text-sm text-center">${index + 1}</td>
                <td class="p-3 text-sm">${student.student_code}</td>
                <td class="p-3 text-sm text-left">${student.name}</td>
                <td class="status-cell p-3 text-sm text-center">
                    <span class="px-2 py-1 font-semibold text-xs rounded-full attendance-${status}">${statusText[status]}</span>
                </td>
                <td class="p-2"><input type="text" class="remark-input border rounded px-2 py-1 w-full text-sm" value="${remark}"></td>
                <td class="action-cell p-2 text-center whitespace-nowrap">
                    <button class="mark-present bg-green-500 text-white px-2 py-1 rounded text-xs">มา</button>
                    <button class="mark-absent bg-red-500 text-white px-2 py-1 rounded text-xs">ขาด</button>
                    <button class="mark-leave bg-yellow-500 text-black px-2 py-1 rounded text-xs">ลา</button>
                    <button class="mark-late bg-pink-500 text-white px-2 py-1 rounded text-xs">สาย</button>
                </td>
            </tr>`;
        tableBody.append(row);
    });
    $('#attendance-list').show();
}

$('main').on('click', '#attendance-table .action-cell button', function() {
    const button = $(this);
    const studentId = button.closest('tr').data('student-id');
    let status = 'none';
    if (button.hasClass('mark-present')) status = 'present';
    if (button.hasClass('mark-absent')) status = 'absent';
    if (button.hasClass('mark-leave')) status = 'leave';
    if (button.hasClass('mark-late')) status = 'late';
    
    const statusText = { present: 'มาเรียน', absent: 'ขาด', leave: 'ลา', late: 'สาย' };
    button.closest('tr').find('.status-cell').html(`<span class="px-2 py-1 font-semibold text-xs rounded-full attendance-${status}">${statusText[status]}</span>`);
    if(appState.currentAttendance[studentId]) appState.currentAttendance[studentId].status = status;
});

$('main').on('input', '#attendance-table .remark-input', function() {
    const input = $(this);
    const studentId = input.closest('tr').data('student-id');
    if(appState.currentAttendance[studentId]) appState.currentAttendance[studentId].remark = input.val();
});

$('main').on('click', '#save-attendance', async function() {
    const records = Object.values(appState.currentAttendance).filter(r => r.status !== 'none');
    if (records.length === 0) {
        Swal.fire('ไม่มีข้อมูล', 'กรุณาเช็คชื่อนักเรียนอย่างน้อย 1 คน', 'info');
        return;
    }
    showLoading();
    try {
        const result = await api.call('attendance.php', 'POST', { records });
        Swal.fire('สำเร็จ', result.message, 'success');
        await loadInitialData(true); // โหลดข้อมูลใหม่ทั้งหมดหลังบันทึก
        renderAttendanceTable(); // รีเฟรชตาราง
    } catch (error) {
        handleError(error);
    } finally {
        hideLoading();
    }
});

// ===============================================================
// ช่วงที่ 6: Admin/Teacher Management Functions
// ===============================================================

function loadSubjects() {
    const tableBody = $('#subjects-table tbody').empty();
    const subjects = appState.subjects || [];
    if (subjects.length === 0) {
        tableBody.html('<tr><td colspan="5" class="p-4 text-center text-gray-400">ไม่มีข้อมูลรายวิชา</td></tr>');
        return;
    }
    const rows = subjects.map((subject, index) => `
        <tr>
            <td class="p-3 text-sm text-center">${index + 1}</td>
            <td class="p-3 text-sm">${subject.code}</td>
            <td class="p-3 text-sm text-left">${subject.name}</td>
            <td class="p-3 text-sm text-center">${subject.credits || '-'}</td>
            <td class="p-3 text-sm text-center">
                <button class="edit-subject bg-yellow-500 text-white px-2 py-1 rounded text-xs">แก้ไข</button>
                <button class="delete-subject bg-red-500 text-white px-2 py-1 rounded text-xs">ลบ</button>
            </td>
        </tr>
    `).join('');
    tableBody.html(rows);
}

function loadStudents() {
    // ตรวจสอบสิทธิ์ผู้ใช้เพื่อแสดง/ซ่อนปุ่มสำหรับ Admin
    const isAdmin = appState.user && appState.user.role === 'admin';

    if (isAdmin) {
        // ถ้าเป็น admin ให้แสดงปุ่มและฟิลเตอร์
        $('#download-students-template, #import-students-btn, #add-student-btn, #student-filters').removeClass('hidden');
    } else {
        // ถ้าไม่ใช่ admin ให้ซ่อนไว้
        $('#download-students-template, #import-students-btn, #add-student-btn, #student-filters').addClass('hidden');
    }

    const tableBody = $('#students-table tbody').empty();
    const students = appState.students || [];

    // แก้ไข Header ของตารางให้สอดคล้องกับ Role
    if(!isAdmin) {
        $('#students-table th:last-child').hide(); // ซ่อนคอลัมน์ "จัดการ"
    } else {
        $('#students-table th:last-child').show(); // แสดงคอลัมน์ "จัดการ"
    }

    const headerColCount = $('#students-table thead th').length;
    if (students.length === 0) {
        tableBody.html(`<tr><td colspan="${headerColCount}" class="p-4 text-center text-gray-400">ไม่มีข้อมูลนักเรียน</td></tr>`);
        return;
    }
    
    const rows = students.map((student, index) => {
        // ส่วนนี้จะสร้างปุ่ม แก้ไข/ลบ เฉพาะเมื่อเป็น Admin เท่านั้น และใส่ data-id ให้ถูกต้อง
        const actionButtons = isAdmin 
            ? `<td class="p-3 text-sm text-center">
                <button class="edit-student bg-yellow-500 text-white px-2 py-1 rounded text-xs" data-id="${student.id}">แก้ไข</button>
                <button class="delete-student bg-red-500 text-white px-2 py-1 rounded text-xs" data-id="${student.id}">ลบ</button>
            </td>`
            : '';

        return `
            <tr>
                <td class="p-3 text-sm text-center">${index + 1}</td>
                <td class="p-3 text-sm">${student.student_code}</td>
                <td class="p-3 text-sm text-left">${student.name}</td>
                <td class="p-3 text-sm text-center">${student.class_level}</td>
                <td class="p-3 text-sm text-center">${student.classroom}</td>
                ${actionButtons}
            </tr>
        `;
    }).join('');

    tableBody.html(rows);
}

function loadTeachers() {
    // ค้นหา Element ที่จะใช้แสดงผลตารางครู
    const container = $('#teachers');
    if (!container.length) return; // ออกจากฟังก์ชันถ้าไม่เจอ Element

    // ดึงข้อมูลครูและรายวิชาจาก State ส่วนกลาง
    const teachers = appState.teachers || [];
    const subjects = appState.subjects || [];
    const tableBody = container.find('#teachers-table tbody');
    tableBody.empty(); // ล้างข้อมูลเก่าในตารางก่อน

    // กรณีไม่มีข้อมูลครู
    if (teachers.length === 0) {
        tableBody.html('<tr><td colspan="8" class="p-4 text-center text-gray-400">ไม่มีข้อมูลครูผู้สอน</td></tr>');
        return;
    }

    let rows = []; // สร้าง Array เพื่อเก็บ HTML ของแต่ละแถว
    teachers.forEach((teacher, index) => {
        let subjectDisplay = '-';
        let classDisplay = '-';
        let classroomDisplay = '-';

        // พยายามแปลงข้อมูลการสอน (subjectClassPairs) ที่เป็น JSON string
        try {
            const pairs = JSON.parse(teacher.subjectClassPairs || '[]');
            
            // ดึงชื่อวิชาที่ไม่ซ้ำกัน
            const subjectNames = [...new Set(pairs.map(p => {
                const subj = subjects.find(s => s.id == p.subjectId);
                return subj ? subj.name : '';
            }).filter(Boolean))].join(', ');

            // ดึงระดับชั้นที่ไม่ซ้ำกัน
            const classLevels = [...new Set(pairs.flatMap(p => p.classLevels))].sort().join(', ');
            
            // ดึงห้องเรียนที่ไม่ซ้ำกัน
            const classrooms = [...new Set(pairs.flatMap(p => p.classrooms))].sort().join(', ');

            subjectDisplay = subjectNames || '-';
            classDisplay = classLevels || '-';
            classroomDisplay = classrooms || '-';
        } catch (e) {
            console.error("Error parsing subjectClassPairs for teacher:", teacher.name, e);
        }
        
        // สร้าง Badge แสดงสถานะการขอรีเซ็ตรหัสผ่าน
        const statusBadge = teacher.reset_requested 
            ? '<span class="px-2 py-1 font-semibold leading-tight text-red-700 bg-red-100 rounded-full text-xs">รอรีเซ็ต</span>' 
            : '<span class="px-2 py-1 font-semibold leading-tight text-green-700 bg-green-100 rounded-full text-xs">ปกติ</span>';

        // สร้าง HTML สำหรับแถวของครูแต่ละคน
        rows.push(`
            <tr>
                <td class="p-3 text-sm text-center">${index + 1}</td>
                <td class="p-3 text-sm">${teacher.name}</td>
                <td class="p-3 text-sm">${teacher.username}</td>
                <td class="p-3 text-sm">${subjectDisplay}</td>
                <td class="p-3 text-sm">${classDisplay}</td>
                <td class="p-3 text-sm">${classroomDisplay}</td>
                <td class="p-3 text-sm text-center">${statusBadge}</td>
                <td class="p-3 text-sm text-center whitespace-nowrap">
                    <button class="edit-teacher bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded mr-1 text-xs" data-id="${teacher.id}">แก้ไข</button>
                    <button class="delete-teacher bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded mr-1 text-xs" data-id="${teacher.id}">ลบ</button>
                    <button class="reset-password bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-xs" data-id="${teacher.id}">รีเซ็ตรหัสผ่าน</button>
                </td>
            </tr>
        `);
    });

    // เรียกใช้ฟังก์ชัน Pagination เพื่อจัดการการแบ่งหน้าของตาราง
    setupPagination('teachers-table', rows);
}

function loadSettings() {
    // ดึงข้อมูลการตั้งค่าจาก State ส่วนกลาง
    const settings = appState.settings || {};

    // นำค่าไปใส่ใน input field แต่ละช่อง
    // ใช้ ?. เพื่อป้องกัน error กรณีที่ key นั้นไม่มีอยู่ และใช้ || '' เพื่อให้ค่า default เป็นสตริงว่าง
    $('#system-name').val(settings.system_name?.setting_value || '');
    $('#logo-url').val(settings.logo_url?.setting_value || '');
    $('#header-text').val(settings.header_text?.setting_value || '');
    $('#footer-text').val(settings.footer_text?.setting_value || '');
    $('#school-name').val(settings.school_name?.setting_value || '');
    $('#school-area').val(settings.school_area?.setting_value || '');
    $('#director-name').val(settings.director_name?.setting_value || '');
    
    // ตั้งค่าสีธีมที่เลือกไว้
    const themeColor = settings.theme_color?.setting_value || '#FF69B4'; // กำหนดสีชมพูเป็นค่าเริ่มต้น
    
    // เก็บค่าสีที่เลือกไว้ใน input hidden
    $('#theme-color').val(themeColor);
    
    // อัปเดต UI ของปุ่มเลือกสี
    $('.theme-button').removeClass('active'); // ลบคลาส active ออกจากทุกปุ่ม
    $(`.theme-button[data-color="${themeColor}"]`).addClass('active'); // เพิ่มคลาส active ให้กับปุ่มที่มีสีตรงกัน
}

function loadClassLevelSettings() {
    // ดึงข้อมูลการตั้งค่าจาก State ส่วนกลาง
    const settings = appState.settings || {};
    
    // ดึงค่าที่ตั้งไว้ปัจจุบัน
    const schoolTypeName = settings.school_type_name?.setting_value || '';
    const enabledLevels = settings.enabled_class_levels?.setting_value || [];
    
    // นำชื่อประเภทสถานศึกษาไปแสดงในฟอร์ม
    $('#school-type-name').val(schoolTypeName);

    // ค้นหา Element ที่จะใช้แสดง Checkbox ทั้งหมด
    const container = $('#class-levels-checkbox-container');
    container.empty(); // ล้างข้อมูลเก่าทิ้งก่อน
    
    // โครงสร้างของระดับชั้นทั้งหมดที่มีในระบบ
    const allLevels = {
        'อนุบาล': ['อนุบาล 1', 'อนุบาล 2', 'อนุบาล 3'],
        'ประถมศึกษา': ['ป.1', 'ป.2', 'ป.3', 'ป.4', 'ป.5', 'ป.6'],
        'มัธยมศึกษาตอนต้น': ['ม.1', 'ม.2', 'ม.3'],
        'มัธยมศึกษาตอนปลาย': ['ม.4', 'ม.5', 'ม.6'],
        'ปวช.': ['ปวช. 1', 'ปวช. 2', 'ปวช. 3'],
        'ปวส.': ['ปวส. 1', 'ปวส. 2']
    };

    // วนลูปเพื่อสร้างกลุ่มของ Checkbox
    Object.entries(allLevels).forEach(([groupName, levels]) => {
        // สร้างส่วนหัวของกลุ่ม
        let groupHtml = `<div class="mb-4">
                            <h3 class="font-semibold text-gray-700 mb-2 border-b pb-1">${groupName}</h3>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2 pt-2">`;
        
        // วนลูปเพื่อสร้าง Checkbox ของแต่ละระดับชั้นในกลุ่มนั้นๆ
        levels.forEach(level => {
            // ตรวจสอบว่าระดับชั้นนี้ถูกเลือกไว้แล้วหรือไม่
            const isChecked = enabledLevels.includes(level);
            
            groupHtml += `
                <div class="flex items-center">
                    <input type="checkbox" id="level-${level.replace(/\./g, '')}" name="class_level" value="${level}" ${isChecked ? 'checked' : ''} 
                           class="h-4 w-4 text-pink-600 focus:ring-pink-500 border-gray-300 rounded">
                    <label for="level-${level.replace(/\./g, '')}" class="ml-2 block text-sm text-gray-900">
                        ${level}
                    </label>
                </div>
            `;
        });

        groupHtml += `</div></div>`;
        container.append(groupHtml); // นำ HTML ที่สร้างเสร็จไปต่อใน Container
    });
}

// --- Pagination Helper ---
function setupPagination(tableId, rows, rowsPerPage = 20) {
    const tableBody = $(`#${tableId} tbody`);
    // ค้นหา container ของตาราง เพื่อที่จะเพิ่มปุ่ม pagination เข้าไปได้ถูกที่
    const container = tableBody.closest('.overflow-x-auto').parent();
    
    // ลบ pagination ของเก่าออกไปก่อน เผื่อมีการเรียกซ้ำ
    container.find('.pagination-container').remove();
    
    // ถ้าจำนวนแถวทั้งหมดน้อยกว่าจำนวนที่กำหนดให้แสดงต่อหน้า ก็ไม่ต้องสร้างปุ่ม
    if (rows.length <= rowsPerPage) {
        tableBody.html(rows.join(''));
        return;
    }
    
    // สร้าง div สำหรับครอบปุ่ม pagination
    const paginationContainer = $('<div class="pagination-container mt-4 flex justify-between items-center"></div>');
    container.append(paginationContainer);
    
    let currentPage = 1;
    const totalPages = Math.ceil(rows.length / rowsPerPage);

    function renderPage(page) {
        currentPage = page;
        const start = (page - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        tableBody.html(rows.slice(start, end).join('')); // แสดงผลข้อมูลเฉพาะของหน้านั้นๆ
        
        // สร้างปุ่ม "ก่อนหน้า" และ "ถัดไป"
        paginationContainer.html(`
            <button id="prev-page" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-l" ${page === 1 ? 'disabled' : ''}>
                ก่อนหน้า
            </button>
            <span class="px-4 text-sm">หน้าที่ ${page} จาก ${totalPages}</span>
            <button id="next-page" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-r" ${page === totalPages ? 'disabled' : ''}>
                ถัดไป
            </button>
        `);
    }
    
    // ผูก event click ให้กับปุ่ม
    paginationContainer.on('click', '#prev-page:not(:disabled)', () => renderPage(currentPage - 1));
    paginationContainer.on('click', '#next-page:not(:disabled)', () => renderPage(currentPage + 1));
    
    renderPage(1); // แสดงผลหน้าแรกเป็นค่าเริ่มต้น
}

// =================================================================
// ส่วนที่เหลือของ Event Handlers for Forms and Actions
// =================================================================

// --- Form Submissions (using event delegation on document) ---

// ** Subject Form **
$(document).on('submit', '#subject-form', async function(e) {
    e.preventDefault();
    const form = $(this);
    if (!form.valid()) return;

    const subjectId = form.find('#subject-id').val();
    const data = {
        id: subjectId,
        code: form.find('#subject-code').val(),
        name: form.find('#subject-name').val(),
        credits: form.find('#subject-credits').val()
    };
    
    const method = subjectId ? 'PUT' : 'POST';
    const endpoint = 'subjects.php'; // POST and PUT go to the same file

    showLoading();
    try {
        const result = await api.call(endpoint, method, data);
        if (result.success) {
            closeModal('subject-modal');
            await loadInitialData(true); // โหลดข้อมูลทั้งหมดใหม่
            loadSubjects(); // รีเฟรชตาราง
        }
    } catch (error) {
        handleError(error);
    } finally {
        hideLoading();
    }
});

// ** Teacher Form **
$(document).on('submit', '#teacher-form', async function(e) {
    e.preventDefault();
    const form = $(this);
    if (!form.valid()) return;
    
    const assignments = [];
    let isAssignmentValid = true;
    form.find('#subject-class-table tbody tr').each(function() {
        const row = $(this);
        const subjectId = row.find('.subject-select').val();
        const classLevels = row.find('.class-select').val();
        const classrooms = row.find('.classroom-select').val();
        if(subjectId && classLevels && classLevels.length > 0 && classrooms && classrooms.length > 0){
            assignments.push({ subjectId, classLevels, classrooms });
        } else {
            // Allow empty rows, but if a row has some data, it must be complete
            if (subjectId || (classLevels && classLevels.length > 0) || (classrooms && classrooms.length > 0)) {
               isAssignmentValid = false;
            }
        }
    });

    if (!isAssignmentValid) {
        Swal.fire('ข้อมูลไม่ครบ', 'กรุณาเลือก วิชา, ระดับชั้น และห้องเรียน ให้ครบทุกแถวที่มีการเลือกข้อมูล', 'warning');
        return;
    }

    const teacherId = form.find('#teacher-id').val();
    const data = {
        id: teacherId,
        name: form.find('#teacher-name').val(),
        username: form.find('#teacher-username').val(),
        password: form.find('#teacher-password').val(), // ส่งไปเลย ถ้าว่าง PHP จะไม่นำไปใช้
        assignments: assignments
    };

    const method = teacherId ? 'PUT' : 'POST';
    const endpoint = 'teachers.php';

    showLoading();
    try {
        const result = await api.call(endpoint, method, data);
         if (result.success) {
            closeModal('teacher-modal');
            await loadInitialData(true);
            loadTeachers();
        }
    } catch (error) {
        handleError(error);
    } finally {
        hideLoading();
    }
});

// ** Student Form (Admin) **
$(document).on('submit', '#student-form', async function(e) {
    e.preventDefault();
    const form = $(this);
    if (!form.valid()) return;

    const studentId = form.find('#student-id').val();
    const data = {
        id: studentId,
        code: form.find('#student-code').val(),
        name: form.find('#student-name').val(),
        class: form.find('#student-class').val(),
        classroom: form.find('#student-classroom').val()
    };

    const method = studentId ? 'PUT' : 'POST';
    const endpoint = 'students.php';

    showLoading();
    try {
         const result = await api.call(endpoint, method, data);
         if (result.success) {
            closeModal('student-modal');
            await loadInitialData(true);
            loadStudents();
        }
    } catch (error) {
        handleError(error);
    } finally {
        hideLoading();
    }
});

// ** Settings Form **
$(document).on('submit', '#settings-form', async function(e) {
    e.preventDefault();
    
    // Use a separate modal for password confirmation
    Swal.fire({
        title: 'ยืนยันการบันทึกการตั้งค่า',
        text: 'กรุณากรอกรหัสผ่านผู้ดูแลระบบของคุณเพื่อดำเนินการต่อ',
        input: 'password',
        inputAttributes: {
            autocapitalize: 'off'
        },
        showCancelButton: true,
        confirmButtonText: 'ยืนยันและบันทึก',
        cancelButtonText: 'ยกเลิก',
        showLoaderOnConfirm: true,
        preConfirm: async (adminPassword) => {
            if (!adminPassword) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return;
            }
            try {
                // รวบรวมข้อมูลการตั้งค่า
                const appSettings = {
                    system_name: $('#system-name').val(),
                    logo_url: $('#logo-url').val(),
                    header_text: $('#header-text').val(),
                    footer_text: $('#footer-text').val(),
                    school_name: $('#school-name').val(),
                    school_area: $('#school-area').val(),
                    director_name: $('#director-name').val(),
                    theme_color: $('#theme-color').val()
                };
                
                const classLevelSettings = {
                    school_type_name: $('#school-type-name').val(),
                    enabled_class_levels: JSON.stringify(
                        $('input[name="class_level"]:checked').map(function() { return $(this).val(); }).get()
                    )
                };

                // ส่ง API call ทั้งสองอย่าง
                await api.call('settings.php', 'POST', {
                    action: 'saveAppSettings',
                    params: { admin_password: adminPassword, settings: appSettings }
                });
    
                await api.call('settings.php', 'POST', {
                    action: 'saveClassLevelSettings',
                    params: { admin_password: adminPassword, ...classLevelSettings }
                });

                return { success: true };
            } catch (error) {
                Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
            }
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'บันทึกสำเร็จ!',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
            await loadInitialData(true);
            initializeUI(); // อัปเดต UI ทันที
        }
    });
});

// ** Class Levels Settings Form **
$(document).on('submit', '#class-levels-form', async function(e) {
    e.preventDefault();

    Swal.fire({
        title: 'ยืนยันการบันทึก',
        text: 'กรุณากรอกรหัสผ่านผู้ดูแลระบบเพื่อยืนยัน',
        input: 'password',
        inputAttributes: { autocapitalize: 'off' },
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        showLoaderOnConfirm: true,
        preConfirm: async (adminPassword) => {
            if (!adminPassword) {
                Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                return;
            }
            try {
                // รวบรวมข้อมูลจากฟอร์ม
                const classLevelSettings = {
                    school_type_name: $('#school-type-name').val(),
                    enabled_class_levels: JSON.stringify(
                        $('input[name="class_level"]:checked').map(function() { return $(this).val(); }).get()
                    )
                };

                // เรียก API เพื่อบันทึก
                const result = await api.call('settings.php', 'POST', {
                    action: 'saveClassLevelSettings',
                    params: { admin_password: adminPassword, ...classLevelSettings }
                });

                if (!result.success) {
                    throw new Error(result.message);
                }

                return result; // ส่งผลลัพธ์กลับไปให้ Swal
            } catch (error) {
                Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
            }
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'บันทึกสำเร็จ!',
                text: 'การตั้งค่าระดับชั้นถูกบันทึกแล้ว',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
            // โหลดข้อมูลใหม่และรีเฟรช UI
            await loadInitialData(true);
            loadClassLevelSettings(); 
        }
    });
});

// ===============================================================
// START: SUBJECTS PAGE SPECIFIC FUNCTIONS
// ===============================================================

// --- Event Handlers for Subject Page ---

// เปิด Modal สำหรับ "เพิ่มรายวิชา"
$(document).on('click', '#add-subject-btn', function() {
    // รีเซ็ตฟอร์มใน Modal ก่อนเปิดใช้งาน
    $('#subject-form')[0].reset();
    $('#subject-id').val(''); // ล้าง ID ที่ซ่อนไว้
    $('#subject-modal-title').text('เพิ่มรายวิชาใหม่'); // เปลี่ยนหัวข้อ Modal
    $('.modal-content-wrapper').scrollTop(0); // Scroll to top
    openModal('subject-modal');
});

// เปิด Modal สำหรับ "แก้ไขรายวิชา"
$(document).on('click', '.edit-subject', function() {
    const row = $(this).closest('tr');
    // ดึงข้อมูลจากตาราง (สมมติว่ามี data attribute เก็บข้อมูลไว้)
    // เพื่อความแม่นยำ เราจะดึงข้อมูลจาก appState ที่โหลดมาล่าสุด
    const subjectCode = row.find('td:nth-child(2)').text();
    const subject = appState.subjects.find(s => s.code === subjectCode);

    if (subject) {
        // กรอกข้อมูลลงในฟอร์มของ Modal
        $('#subject-id').val(subject.id);
        $('#subject-code').val(subject.code);
        $('#subject-name').val(subject.name);
        $('#subject-credits').val(subject.credits);
        $('#subject-modal-title').text('แก้ไขรายวิชา'); // เปลี่ยนหัวข้อ Modal
        $('.modal-content-wrapper').scrollTop(0); // Scroll to top
        openModal('subject-modal');
    } else {
        handleError({ message: 'ไม่พบข้อมูลรายวิชาที่ต้องการแก้ไข' });
    }
});

// เปิด Modal สำหรับ "นำเข้ารายวิชา"
$(document).on('click', '#import-subjects-btn', function() {
    // ตั้งค่า Modal สำหรับการนำเข้ารายวิชาโดยเฉพาะ
    $('#import-modal-title').text('นำเข้ารายวิชาจากไฟล์ CSV/Excel');
    $('#import-instructions').html(`
        <p class="text-sm text-gray-600 mb-2">1. ไฟล์ต้องมี header ในแถวแรก และมีข้อมูลตามลำดับต่อไปนี้:</p>
        <ul class="list-disc list-inside text-sm text-gray-500">
            <li>คอลัมน์ A: <strong>code</strong> (รหัสวิชา)</li>
            <li>คอลัมน์ B: <strong>name</strong> (ชื่อรายวิชา)</li>
            <li>คอลัมน์ C: <strong>credits</strong> (หน่วยกิต)</li>
        </ul>
        <p class="text-sm text-red-500 mt-2">* หากมีรหัสวิชาซ้ำในไฟล์ ระบบจะทำการอัปเดตข้อมูลเป็นรายการล่าสุด</p>
    `);
    $('#hidden-import-type').val('subjects'); // ระบุประเภทการ import
    $('#import-form')[0].reset(); // รีเซ็ตฟอร์ม
    $('#file-name-display').text('ยังไม่ได้เลือกไฟล์');
    openModal('import-modal');
});

// ดาวน์โหลดไฟล์เทมเพลต CSV สำหรับรายวิชา
$(document).on('click', '#download-subjects-template', function() {
    const csvContent = "data:text/csv;charset=utf-8,"
        + "code,name,credits\n"
        + "ว21101,วิทยาการคำนวณ 1,1.5\n"
        + "อ21101,ภาษาอังกฤษพื้นฐาน 1,1.5\n";

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "subjects_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
});

// ===============================================================
// END: SUBJECTS PAGE SPECIFIC FUNCTIONS
// ===============================================================

// ===============================================================
// START: STUDENT MANAGEMENT PAGE SPECIFIC FUNCTIONS
// ===============================================================

// เปิด Modal สำหรับ "เพิ่มนักเรียน"
$(document).on('click', '#add-student-btn', function() {
    $('#student-form')[0].reset();
    $('#student-id').val(''); // ล้าง ID ที่ซ่อนไว้
    $('#student-modal-title').text('เพิ่มนักเรียนใหม่');
    
    // ตั้งค่า dropdown ระดับชั้น
    const classSelect = $('#student-class');
    classSelect.empty().append('<option value="">-- เลือกระดับชั้น --</option>');
    const enabledLevels = appState.settings.enabled_class_levels?.setting_value || [];
    enabledLevels.forEach(level => {
        classSelect.append(`<option value="${level}">${level}</option>`);
    });
    
    openModal('student-modal');
});

// เปิด Modal สำหรับ "แก้ไขข้อมูลนักเรียน"
$(document).on('click', '.edit-student', function() {
    const studentId = $(this).data('id');
    const student = appState.students.find(s => s.id == studentId);

    if (student) {
        $('#student-form')[0].reset();
        $('#student-modal-title').text('แก้ไขข้อมูลนักเรียน');
        
        $('#student-id').val(student.id);
        $('#student-code').val(student.student_code);
        $('#student-name').val(student.name);

        // ตั้งค่า dropdown ระดับชั้น และเลือกค่าที่ถูกต้อง
        const classSelect = $('#student-class');
        classSelect.empty().append('<option value="">-- เลือกระดับชั้น --</option>');
        const enabledLevels = appState.settings.enabled_class_levels?.setting_value || [];
        enabledLevels.forEach(level => {
            classSelect.append(`<option value="${level}">${level}</option>`);
        });
        classSelect.val(student.class_level); 

        // ตั้งค่าห้องเรียน
        $('#student-classroom').val(student.classroom);

        openModal('student-modal');
    } else {
        handleError({ message: 'ไม่พบข้อมูลนักเรียนที่ต้องการแก้ไข' });
    }
});

// เปิด Modal สำหรับ "นำเข้านักเรียน"
$(document).on('click', '#import-students-btn', function() {
    $('#import-modal-title').text('นำเข้านักเรียนจากไฟล์ CSV/Excel');
    $('#import-instructions').html(`
        <p class="text-sm text-gray-600 mb-2">1. ไฟล์ต้องมี header ในแถวแรก และมีข้อมูลตามลำดับต่อไปนี้:</p>
        <ul class="list-disc list-inside text-sm text-gray-500">
            <li>คอลัมน์ A: <strong>code</strong> (รหัสนักเรียน)</li>
            <li>คอลัมน์ B: <strong>name</strong> (ชื่อ-นามสกุล)</li>
            <li>คอลัมน์ C: <strong>class</strong> (ระดับชั้น เช่น ป.1, ม.6)</li>
            <li>คอลัมน์ D: <strong>classroom</strong> (ห้องเรียน เช่น 1, 2)</li>
        </ul>
        <p class="text-sm text-red-500 mt-2">* หากมีรหัสนักเรียนซ้ำในไฟล์ ระบบจะทำการอัปเดตข้อมูลเป็นรายการล่าสุด</p>
    `);
    $('#hidden-import-type').val('students');
    $('#import-form')[0].reset();
    $('#file-name-display').text('ยังไม่ได้เลือกไฟล์');
    openModal('import-modal');
});

// ดาวน์โหลดไฟล์เทมเพลต CSV สำหรับนักเรียน
$(document).on('click', '#download-students-template', function() {
    const csvContent = "data:text/csv;charset=utf-8,"
        + "code,name,class,classroom\\n"
        + "67001,เด็กชายสมชาย ใจดี,ป.1,1\\n"
        + "67002,เด็กหญิงสมศรี มีสุข,ป.1,1\\n";

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "students_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
});
// ===============================================================
// END: STUDENT MANAGEMENT PAGE SPECIFIC FUNCTIONS
// ===============================================================