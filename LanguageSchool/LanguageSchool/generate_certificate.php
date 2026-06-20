<?php
session_start();
require 'config.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit;
}

$studentId = $_GET['student_id'] ?? null;
$courseId  = $_GET['course_id']  ?? null;
if (!$studentId || !$courseId) die('Параметри не вказані');

// Дані студента
$stmtU = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
$stmtU->execute(['id' => $studentId]);
$student = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$student) die('Студента не знайдено');

// Дані курсу + викладач
$stmtC = $pdo->prepare("
    SELECT c.title, c.level, l.name_ua AS language,
           u.first_name AS teacher_first, u.last_name AS teacher_last,
           e.enrolled_at
    FROM courses c
    JOIN languages l ON c.language_id = l.id
    LEFT JOIN users u ON c.teacher_id = u.id
    JOIN enrollments e ON e.course_id = c.id AND e.student_id = :sid
    WHERE c.id = :cid
");
$stmtC->execute(['sid' => $studentId, 'cid' => $courseId]);
$course = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$course) die('Курс не знайдено');

$fullName    = $student['first_name'] . ' ' . $student['last_name'];
$teacherName = trim(($course['teacher_first'] ?? '') . ' ' . ($course['teacher_last'] ?? '')) ?: 'Адміністрація';
$today       = date('d.m.Y');
$certificateNum  = strtoupper(substr(md5($studentId . $courseId), 0, 8));

// -------------------------------------------------------
// TCPDF — A4 альбомна (297 x 210 мм), без полів
// -------------------------------------------------------
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// -------------------------------------------------------
// 1. ФОН — розтягуємо на весь аркуш 297×210 мм
// -------------------------------------------------------
$bgPath = __DIR__ . '/images/certificate_bg.jpg';
if (!file_exists($bgPath)) die('Фон не знайдено: ' . $bgPath);
$pdf->Image($bgPath, 0, 0, 297, 210, 'JPG', '', '', false, 300, '', false, false, 0);

// -------------------------------------------------------
// Хелпер: виводить рядок по центру сторінки
// -------------------------------------------------------
function centerText(TCPDF $pdf, float $y, string $text, string $font, string $style, float $size, array $rgb): void {
    $pdf->SetFont($font, $style, $size);
    $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->SetXY(0, $y);
    $pdf->Cell(297, 0, $text, 0, 1, 'C');
}

// -------------------------------------------------------
// 2. "CERTIFICATE" — великий заголовок (вже на фоні, тому
//    якщо фон вже має напис — цей блок можна закоментувати)
// -------------------------------------------------------
// centerText($pdf, 18, 'CERTIFICATE', 'dejavusans', 'B', 48, [70, 80, 120]);

// -------------------------------------------------------
// 3. "of appreciation" — підзаголовок
// -------------------------------------------------------
// centerText($pdf, 52, 'of appreciation', 'dejavuserif', '', 16, [40, 40, 40]);

// -------------------------------------------------------
// 5. Ім'я студента — великий курсив
// -------------------------------------------------------
$pdf->SetFont('dejavuserif', 'I', 34);
$pdf->SetTextColor(20, 20, 20);
$pdf->SetXY(0, 96);
$pdf->Cell(297, 14, $fullName, 0, 1, 'C');

// -------------------------------------------------------
// 6. Текст про курс
// -------------------------------------------------------
$pdf->SetFont('dejavusans', '', 11);
$pdf->SetTextColor(60, 60, 60);
$pdf->SetXY(0, 115);
$pdf->Cell(297, 7, 'For successful completion of the course', 0, 1, 'C');

$courseText = $course['title'] . ' · ' . $course['language'] . ' · Level ' . $course['level'];
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetTextColor(40, 40, 100);
$pdf->SetXY(0, 123);
$pdf->Cell(297, 7, $courseText, 0, 1, 'C');

// -------------------------------------------------------
// 7. Дата
// -------------------------------------------------------
$pdf->SetFont('dejavusans', '', 10);
$pdf->SetTextColor(120, 120, 120);
$pdf->SetXY(0, 135);
$pdf->Cell(297, 6, $today, 0, 1, 'C');

// -------------------------------------------------------
// 8. Підписи — лівий (викладач) і правий (школа)
//    Рядок підпису Y≈162, назва Y≈168, посада Y≈174
// -------------------------------------------------------

// Лінія підпису зліва
$pdf->Line(70, 148, 140, 148);
$pdf->Line(157, 148, 227, 148);

$pdf->SetXY(60, 150);  // было 164
$pdf->Cell(90, 6, $teacherName, 0, 0, 'C');
$pdf->SetXY(147, 150);  // было 164
$pdf->Cell(90, 6, 'LinguaSchool', 0, 0, 'C');

// Назва школи внизу по центру
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->SetTextColor(40, 40, 100);
$pdf->SetXY(0, 185);
$pdf->Cell(297, 6, 'LinguaSchool', 0, 1, 'C');

// Підпис роль
$pdf->SetXY(60, 157);  // было 171
$pdf->Cell(90, 5, 'TEACHER', 0, 0, 'C');
$pdf->SetXY(147, 157);  // было 171
$pdf->Cell(90, 5, 'ADMINISTRATION', 0, 0, 'C');

// -------------------------------------------------------
// 9. Номер сертифіката внизу праворуч
// -------------------------------------------------------
$pdf->SetFont('dejavusans', '', 8);
$pdf->SetTextColor(160, 160, 160);
$pdf->SetXY(220, 200);
$pdf->Cell(70, 5, 'No. ' . $certificateNum, 0, 0, 'R');

// -------------------------------------------------------
// 10. Видати PDF на скачування
// -------------------------------------------------------
$filename = 'сертифікат_' . $student['last_name'] . '.pdf';
$pdf->Output($filename, 'D');