<?php
session_start();
require 'config.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit;
}

$studentId = $_GET['student_id'] ?? null;
if (!$studentId) die('Студента не вказано');

// Дані студента
$stmtU = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
$stmtU->execute(['id' => $studentId]);
$student = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$student) die('Студента не знайдено');

$fullName = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);

// Курси студента
$stmtC = $pdo->prepare("
    SELECT c.title, l.name_ua AS language, c.level, e.enrolled_at, e.status
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    JOIN languages l ON c.language_id = l.id
    WHERE e.student_id = :id
    ORDER BY e.enrolled_at DESC
");
$stmtC->execute(['id' => $studentId]);
$courses = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Оцінки
$stmtG = $pdo->prepare("
    SELECT t.title AS task_title, c.title AS course_title,
           ts.score, ts.submitted_at, ts.status
    FROM task_submissions ts
    JOIN tasks t ON ts.task_id = t.id
    JOIN lessons l ON t.lesson_id = l.id
    JOIN courses c ON l.course_id = c.id
    WHERE ts.student_id = :id
    ORDER BY ts.submitted_at DESC
");
$stmtG->execute(['id' => $studentId]);
$grades = $stmtG->fetchAll(PDO::FETCH_ASSOC);

// Статистика
$stmtS = $pdo->prepare("
    SELECT COUNT(*) AS total,
           COUNT(CASE WHEN score IS NOT NULL THEN 1 END) AS graded,
           ROUND(AVG(score), 1) AS avg_score,
           MAX(score) AS max_score,
           MIN(score) AS min_score
    FROM task_submissions WHERE student_id = :id
");
$stmtS->execute(['id' => $studentId]);
$stats = $stmtS->fetch(PDO::FETCH_ASSOC);

// Відвідуваність
$stmtA = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN attended THEN 1 ELSE 0 END) AS attended
    FROM lesson_attendance la
    JOIN lessons l ON la.lesson_id = l.id
    WHERE la.student_id = :id
");
$stmtA->execute(['id' => $studentId]);
$attendance = $stmtA->fetch(PDO::FETCH_ASSOC);
$attendPct = $attendance['total'] > 0
    ? round(($attendance['attended'] / $attendance['total']) * 100)
    : 0;

$today = date('d.m.Y');

// HTML для PDF
$html = "
<html><head><meta charset='UTF-8'></head><body>
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #1e293b; margin: 0; padding: 0; }
    .header { background: linear-gradient(135deg, #1e1b4b, #312e81); color: white; padding: 32px 40px; }
    .header h1 { font-size: 22px; margin: 0 0 4px; }
    .header p  { font-size: 11px; opacity: .7; margin: 0; }
    .header .name { font-size: 28px; font-weight: bold; margin: 12px 0 4px; }
    .header .email { font-size: 12px; opacity: .75; }
    .meta { font-size: 10px; opacity: .6; margin-top: 8px; }

    .body { padding: 28px 40px; }

    .stats-row { display: table; width: 100%; margin-bottom: 28px; }
    .stat-box { display: table-cell; width: 25%; padding: 16px 12px; text-align: center;
        background: #f8faff; border: 1px solid #e2e8f0; border-radius: 10px; }
    .stat-num { font-size: 26px; font-weight: bold; color: #6366f1; }
    .stat-lbl { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }

    h2 { font-size: 14px; color: #334155; border-bottom: 2px solid #6366f1;
         padding-bottom: 6px; margin: 24px 0 14px; text-transform: uppercase; letter-spacing: 1px; }

    table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 10px; }
    thead th { background: #f1f5f9; color: #475569; font-size: 9px; text-transform: uppercase;
               letter-spacing: .8px; padding: 8px 10px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:nth-child(even) td { background: #f8faff; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: bold; }
    .badge-green { background: #dcfce7; color: #166534; }
    .badge-amber { background: #fef3c7; color: #92400e; }
    .badge-red   { background: #fee2e2; color: #991b1b; }
    .badge-gray  { background: #f1f5f9; color: #475569; }

    .footer { margin-top: 32px; padding-top: 14px; border-top: 1px solid #e2e8f0;
        font-size: 9px; color: #94a3b8; text-align: center; }
</style>

<div class='header'>
    <h1>LinguaSchool — Звіт успішності</h1>
    <p>Сформовано: {$today}</p>
    <div class='name'>{$fullName}</div>
    <div class='email'>" . htmlspecialchars($student['email']) . "</div>
</div>

<div class='body'>

<div class='stats-row'>
    <table><tr>
    <td style='width:25%;text-align:center;padding:16px;background:#f8faff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='font-size:26px;font-weight:bold;color:#6366f1;'>" . count($courses) . "</div>
        <div style='font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-top:4px;'>Курсів</div>
    </td>
    <td style='width:5%;'></td>
    <td style='width:25%;text-align:center;padding:16px;background:#f8faff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='font-size:26px;font-weight:bold;color:#22c55e;'>" . (int)$stats['graded'] . "</div>
        <div style='font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-top:4px;'>Оцінених робіт</div>
    </td>
    <td style='width:5%;'></td>
    <td style='width:25%;text-align:center;padding:16px;background:#f8faff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='font-size:26px;font-weight:bold;color:#f59e0b;'>" . ($stats['avg_score'] ?? '—') . "</div>
        <div style='font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-top:4px;'>Середній бал</div>
    </td>
    <td style='width:5%;'></td>
    <td style='width:25%;text-align:center;padding:16px;background:#f8faff;border:1px solid #e2e8f0;border-radius:8px;'>
        <div style='font-size:26px;font-weight:bold;color:#22d3ee;'>{$attendPct}%</div>
        <div style='font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-top:4px;'>Відвідуваність</div>
    </td>
    </tr></table>
</div>

<h2>Курси</h2>
<table>
    <thead><tr><th>Назва курсу</th><th>Мова</th><th>Рівень</th><th>Дата запису</th><th>Статус</th></tr></thead>
    <tbody>";

foreach ($courses as $c) {
    $statusLabel = $c['status'] === 'active' ? "<span class='badge badge-green'>Активний</span>" : "<span class='badge badge-gray'>{$c['status']}</span>";
    $html .= "<tr>
        <td>" . htmlspecialchars($c['title']) . "</td>
        <td>" . htmlspecialchars($c['language']) . "</td>
        <td><span class='badge badge-amber'>" . htmlspecialchars($c['level']) . "</span></td>
        <td>" . date('d.m.Y', strtotime($c['enrolled_at'])) . "</td>
        <td>{$statusLabel}</td>
    </tr>";
}

$html .= "</tbody></table>

<h2>Оцінки за завдання</h2>";

if (empty($grades)) {
    $html .= "<p style='color:#94a3b8;font-size:11px;font-style:italic;'>Завдань ще немає</p>";
} else {
    $html .= "<table>
        <thead><tr><th>Завдання</th><th>Курс</th><th>Дата</th><th>Оцінка</th></tr></thead>
        <tbody>";
    foreach ($grades as $g) {
        $score = $g['score'];
        if ($score === null) { $cls = 'badge-gray'; $lbl = 'Не оцінено'; }
        elseif ($score >= 80) { $cls = 'badge-green'; $lbl = $score . '/100'; }
        elseif ($score >= 60) { $cls = 'badge-amber'; $lbl = $score . '/100'; }
        else                  { $cls = 'badge-red';   $lbl = $score . '/100'; }
        $html .= "<tr>
            <td>" . htmlspecialchars($g['task_title']) . "</td>
            <td>" . htmlspecialchars($g['course_title']) . "</td>
            <td>" . date('d.m.Y', strtotime($g['submitted_at'])) . "</td>
            <td><span class='badge {$cls}'>{$lbl}</span></td>
        </tr>";
    }
    $html .= "</tbody></table>";
}

$html .= "
</div>
<div class='footer'>LinguaSchool · Звіт сформовано автоматично · {$today}</div>
</body></html>";

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('LinguaSchool');
$pdf->SetAuthor('LinguaSchool');
$pdf->SetTitle('Звіт успішності');
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output("звіт_{$student['last_name']}.pdf", 'D');