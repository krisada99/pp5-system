<?php
// /api/generate_pdf.php
header("Content-Type: application/json; charset=UTF-8"); // <--- เพิ่มบรรทัดนี้
// --- การตั้งค่าเบื้องต้น ---
require_once '../vendor/autoload.php'; // เรียกใช้ Autoloader ของ Composer
require_once '../config/database.php';   // เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล

checkLogin(['teacher']); // ตรวจสอบสิทธิ์ (เฉพาะครู)

// --- รับข้อมูลจาก Client ---
$input = json_decode(file_get_contents('php://input'), true);
$reportType = $input['reportType'] ?? null;
$params = $input['params'] ?? [];
$conn = getDbConnection();
$user = $_SESSION['user'];

// --- ฟังก์ชันสำหรับสร้าง HTML ของแต่ละรายงาน ---

/**
 * สร้าง HTML สำหรับรายงานสรุปคะแนน (Score Report)
 */
function generateScoreReportHtml($data, $conn) {
    // ดึงข้อมูลรายละเอียดจาก $data
    $reportData = $data['reportData'];
    $components = $data['components'];
    $details = $data['reportDetails'];

    // ดึงชื่อโรงเรียนและโลโก้จากการตั้งค่า
    $school_name_result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'school_name'")->fetch_assoc();
    $school_name = $school_name_result['setting_value'] ?? 'โรงเรียนตัวอย่าง';
    $logo_url_result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'logo_url'")->fetch_assoc();
    $logo_url = $logo_url_result['setting_value'] ?? '';

    // เริ่มสร้างโค้ด HTML
    $html = '
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "sarabun"; font-size: 10pt; }
            .report-container { width: 100%; border: 1px solid #333; padding: 15px; }
            .header { text-align: center; line-height: 1.4; }
            .header img { max-height: 60px; }
            .header h2 { font-size: 16pt; margin: 5px 0; }
            .header h3 { font-size: 14pt; margin: 5px 0; font-weight: normal; }
            .details { font-size: 11pt; margin-top: 15px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border: 1px solid #666; padding: 5px; text-align: center; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .text-left { text-align: left; }
            .total-score { font-weight: bold; color: #0056b3; }
            .grade { font-weight: bold; color: #28a745; }
            tfoot td { font-weight: bold; background-color: #f8f9fa; }
        </style>
    </head>
    <body>
        <div class="report-container">
            <div class="header">
                ' . ($logo_url ? '<img src="' . $logo_url . '" alt="Logo">' : '') . '
                <h2>รายงานสรุปผลการเรียน</h2>
                <h3>' . htmlspecialchars($school_name) . '</h3>
            </div>
            <div class="details">
                <strong>รายวิชา:</strong> ' . htmlspecialchars($details['subjectInfo']['code'] . ' - ' . $details['subjectInfo']['name']) . '<br>
                <strong>ระดับชั้น:</strong> ' . htmlspecialchars($details['classLevel']) . ' &nbsp;&nbsp;
                <strong>ห้อง:</strong> ' . htmlspecialchars($details['classroom']) . '<br>
                <strong>ครูผู้สอน:</strong> ' . htmlspecialchars($details['teacherName']) . '
            </div>
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">ลำดับ</th>
                        <th rowspan="2">รหัสนักเรียน</th>
                        <th rowspan="2" class="text-left">ชื่อ-นามสกุล</th>';

    $totalMaxScorePossible = 0;
    foreach ($components as $c) {
        $html .= '<th>' . htmlspecialchars($c['componentName']) . '</th>';
        $totalMaxScorePossible += (float)$c['maxScore'];
    }
    $html .= '<th rowspan="2">รวม</th><th rowspan="2">เกรด</th></tr><tr>';
    foreach ($components as $c) {
        $html .= '<th>(' . htmlspecialchars($c['maxScore']) . ')</th>';
    }
    $html .= '</tr></thead><tbody>';

    // วนลูปสร้างแถวข้อมูลนักเรียน
    foreach ($reportData as $index => $student) {
        $html .= '<tr>
                    <td>' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars($student['studentCode']) . '</td>
                    <td class="text-left">' . htmlspecialchars($student['studentName']) . '</td>';
        foreach ($components as $c) {
            $html .= '<td>' . ($student['scores'][$c['id']] ?? '-') . '</td>';
        }
        $html .= '<td class="total-score">' . number_format($student['totalScore'], 2) . '</td>
                  <td class="grade">' . htmlspecialchars($student['finalGrade']) . '</td>
                  </tr>';
    }

    $html .= '</tbody><tfoot><tr>
                <td colspan="3" class="text-left"><strong>ค่าเฉลี่ยของห้อง</strong></td>';
    
    // คำนวณค่าเฉลี่ยแต่ละช่อง
    $componentAverages = [];
    $totalStudentsWithScores = count(array_filter($reportData, fn($s) => $s['totalScore'] > 0));

    foreach ($components as $c) {
        $sum = array_sum(array_column(array_column($reportData, 'scores'), $c['id']));
        $avg = $totalStudentsWithScores > 0 ? ($sum / $totalStudentsWithScores) : 0;
        $html .= '<td>' . number_format($avg, 2) . '</td>';
    }
    $totalAvg = $totalStudentsWithScores > 0 ? (array_sum(array_column($reportData, 'totalScore')) / $totalStudentsWithScores) : 0;

    $html .= '<td>' . number_format($totalAvg, 2) . '</td><td>-</td></tr></tfoot></table></div></body></html>';
    
    return $html;
}

// คุณสามารถสร้างฟังก์ชัน generate...Html() อื่นๆ สำหรับรายงานแต่ละประเภทได้ที่นี่
// generateCharacteristicsReportHtml(), generateP5CoverHtml(), etc.


// --- ส่วนประมวลผลหลัก ---

$htmlContent = '';
$filename = 'report.pdf';
$reportData = [];

// ใช้ switch เพื่อเลือกประเภทรายงานและดึงข้อมูล
switch ($reportType) {
    case 'scoreReport':
        // ดึงข้อมูลสำหรับ Score Report (สมมติว่ามีฟังก์ชันนี้ใน reports.php หรือจะเขียน logic ที่นี่ก็ได้)
        // เพื่อความสะอาดของโค้ด ควรเรียกใช้ API อื่น หรือมี Logic การดึงข้อมูลที่นี่
        require_once 'reports.php'; // สมมติว่า Logic การดึงข้อมูลอยู่ในไฟล์นี้
        $reportData = getScoreReportData($conn, $params, $user);
        
        if ($reportData['success']) {
            $htmlContent = generateScoreReportHtml($reportData['data'], $conn);
            $subjectCode = $reportData['data']['reportDetails']['subjectInfo']['code'];
            $filename = "ScoreReport-{$subjectCode}.pdf";
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลสำหรับสร้างรายงานสรุปคะแนน']);
            exit;
        }
        break;

    // เพิ่ม case 'characteristicsReport', 'p5Cover' ฯลฯ ที่นี่
    // case 'characteristicsReport':
    //     // ... ดึงข้อมูลและเรียกฟังก์ชัน generateCharacteristicsReportHtml() ...
    //     break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ประเภทของรายงานไม่ถูกต้อง']);
        exit;
}

// --- การสร้าง PDF ด้วย mPDF ---
if (!empty($htmlContent)) {
    try {
        // ตั้งค่าพื้นฐานสำหรับ mPDF
        $defaultConfig = (new Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4', // สามารถเปลี่ยนเป็น 'A4-L' สำหรับแนวนอน
            'fontDir' => array_merge($fontDirs, [
                __DIR__ . '/custom-fonts', // หากมีฟอนต์ที่ต้องการติดตั้งเพิ่มเติม
            ]),
            'fontdata' => $fontData + [
                'sarabun' => [ // ตั้งชื่อฟอนต์
                    'R' => 'Sarabun-Regular.ttf',
                    'I' => 'Sarabun-Italic.ttf',
                    'B' => 'Sarabun-Bold.ttf',
                    'BI' => 'Sarabun-BoldItalic.ttf',
                ]
            ],
            'default_font' => 'sarabun' // กำหนดฟอนต์ปริยาย
        ]);

        $mpdf->WriteHTML($htmlContent);

        // ส่งไฟล์ PDF ให้ผู้ใช้ดาวน์โหลด
        $mpdf->Output($filename, 'D'); // 'D' คือการบังคับดาวน์โหลด

    } catch (\Mpdf\MpdfException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'mPDF Error: ' . $e->getMessage()]);
    }
}

$conn->close();

?>