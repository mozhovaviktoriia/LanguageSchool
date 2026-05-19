<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit;
}

// Всі студенти з їх курсами
$students = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email,
           COUNT(DISTINCT e.course_id) AS courses_count,
           COUNT(DISTINCT ts.id) AS submissions_count,
           ROUND(AVG(ts.score), 1) AS avg_score
    FROM users u
    LEFT JOIN enrollments e ON e.student_id = u.id AND e.status = 'active'
    LEFT JOIN task_submissions ts ON ts.student_id = u.id
    WHERE u.role = 'student' AND u.status = 'active'
    GROUP BY u.id, u.first_name, u.last_name, u.email
    ORDER BY u.last_name, u.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Курси завершені студентом (для диплому)
// Завершений = enrolled + всі завдання здані
$completedEnrollments = $pdo->query("
    SELECT
        e.student_id,
        u.first_name, u.last_name,
        c.id AS course_id, c.title AS course_title,
        l.name_ua AS language,
        c.level,
        e.enrolled_at
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    JOIN courses c ON e.course_id = c.id
    JOIN languages l ON c.language_id = l.id
    WHERE e.status = 'active' AND u.role = 'student'
    ORDER BY u.last_name, c.title
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Звіти — Адмін</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }
body::before { content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background: radial-gradient(ellipse 70% 50% at 15% 10%, rgba(99,102,241,.09) 0%, transparent 60%),
                radial-gradient(ellipse 50% 40% at 85% 85%, rgba(34,211,238,.06) 0%, transparent 55%); }

header { position:relative; z-index:10; display:flex; align-items:center; justify-content:space-between;
    padding:16px 36px; border-bottom:1px solid var(--border);
    background:rgba(7,8,15,.92); backdrop-filter:blur(20px); }
.logo { font-size:18px; font-weight:800; }
.logo span { color:var(--teal); }
.header-sub { font-family:var(--mono); font-size:10px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
.nav-link { font-family:var(--mono); font-size:11px; color:var(--muted); text-decoration:none;
    padding:7px 14px; border:1px solid var(--border); border-radius:8px; transition:.2s; }
.nav-link:hover { color:var(--text); border-color:var(--accent); background:rgba(99,102,241,.1); }

.wrap { position:relative; z-index:1; max-width:1100px; margin:0 auto; padding:36px 28px; }

/* Stats */
.stats { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:36px; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:20px; }
.stat-val { font-size:32px; font-weight:800; line-height:1; margin-bottom:4px; }
.stat-label { font-family:var(--mono); font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }
.stat-card:nth-child(1) .stat-val { color:var(--accent); }
.stat-card:nth-child(2) .stat-val { color:var(--green); }
.stat-card:nth-child(3) .stat-val { color:var(--teal); }

/* Section */
.sec-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
.sec-title { font-size:17px; font-weight:800; }
.pill { font-family:var(--mono); font-size:10px; padding:3px 10px; border-radius:99px; background:var(--border); color:var(--muted); }

/* Table */
.tbl-wrap { border-radius:var(--radius); border:1px solid var(--border); overflow:hidden; margin-bottom:48px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead tr { background:rgba(255,255,255,.03); }
thead th { font-family:var(--mono); font-size:9px; font-weight:600; letter-spacing:1.2px;
    text-transform:uppercase; color:var(--muted); padding:11px 16px; text-align:left;
    border-bottom:1px solid var(--border); }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:rgba(99,102,241,.04); }
tbody td { padding:12px 16px; vertical-align:middle; }
.nm { font-weight:700; font-size:14px; }
.em { font-family:var(--mono); font-size:10px; color:var(--muted); margin-top:2px; }

.score-chip { font-family:var(--mono); font-size:11px; font-weight:700;
    padding:3px 10px; border-radius:6px; }
.score-high { background:rgba(34,197,94,.12); color:var(--green); }
.score-mid  { background:rgba(245,158,11,.10); color:var(--amber); }
.score-low  { background:rgba(239,68,68,.10);  color:var(--red); }
.score-none { background:var(--border); color:var(--muted); }

.actions { display:flex; gap:6px; flex-wrap:wrap; }
.btn-report { padding:6px 12px; border-radius:7px; font-family:var(--mono); font-size:10px;
    font-weight:600; text-decoration:none; transition:.15s; white-space:nowrap; }
.btn-pdf   { background:rgba(99,102,241,.12); color:#a5b4fc; border:1px solid rgba(99,102,241,.25); }
.btn-pdf:hover { background:rgba(99,102,241,.25); }

/* Diploma table */
.course-chip { display:inline-block; background:rgba(34,211,238,.08); border:1px solid rgba(34,211,238,.15);
    border-radius:5px; padding:2px 8px; font-family:var(--mono); font-size:10px; color:var(--teal); }
.btn-diploma { background:rgba(245,158,11,.10); color:var(--amber); border:1px solid rgba(245,158,11,.2); }
.btn-diploma:hover { background:rgba(245,158,11,.22); }

.divider { height:1px; background:linear-gradient(90deg,transparent,var(--border),transparent); margin:40px 0; }
</style>
</head>
<body>
<header>
    <div>
        <div class="logo">Lingua<span>School</span></div>
        <div class="header-sub">Звіти та дипломи</div>
    </div>
    <div style="display:flex;gap:10px;">
        <button class="theme-toggle nav-link" title="Тема"><span class="theme-toggle-icon">☀️</span></button>
        <a class="nav-link" href="admin.php">← Панель</a>
    </div>
</header>

<div class="wrap">

    <!-- Статистика -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-val"><?= count($students) ?></div>
            <div class="stat-label">Активних студентів</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= count($completedEnrollments) ?></div>
            <div class="stat-label">Записів на курси</div>
        </div>
        <div class="stat-card">
            <div class="stat-val"><?= $pdo->query("SELECT ROUND(AVG(score),1) FROM task_submissions WHERE score IS NOT NULL")->fetchColumn() ?: '—' ?></div>
            <div class="stat-label">Середній бал</div>
        </div>
    </div>

    <!-- Звіти студентів -->
    <div class="sec-head">
        <div class="sec-title">Звіти успішності</div>
        <span class="pill"><?= count($students) ?> студентів</span>
    </div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Студент</th>
                    <th>Курсів</th>
                    <th>Завдань здано</th>
                    <th>Середній бал</th>
                    <th>Дії</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($students)): ?>
                <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted);font-family:var(--mono);font-size:12px;">Немає студентів</td></tr>
            <?php else: ?>
            <?php foreach ($students as $s):
                $score = $s['avg_score'];
                if ($score === null) { $cls = 'score-none'; $lbl = '—'; }
                elseif ($score >= 80) { $cls = 'score-high'; $lbl = $score; }
                elseif ($score >= 60) { $cls = 'score-mid';  $lbl = $score; }
                else                  { $cls = 'score-low';  $lbl = $score; }
            ?>
            <tr>
                <td>
                    <div class="nm"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                    <div class="em"><?= htmlspecialchars($s['email']) ?></div>
                </td>
                <td style="font-family:var(--mono);font-size:12px;"><?= (int)$s['courses_count'] ?></td>
                <td style="font-family:var(--mono);font-size:12px;"><?= (int)$s['submissions_count'] ?></td>
                <td><span class="score-chip <?= $cls ?>"><?= $lbl ?></span></td>
                <td>
                    <div class="actions">
                        <a class="btn-report btn-pdf"
                           href="generate_report.php?student_id=<?= $s['id'] ?>"
                           target="_blank">
                            📄 PDF Звіт
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="divider"></div>

    <!-- Дипломи -->
    <div class="sec-head">
        <div class="sec-title">Дипломи про закінчення курсу</div>
        <span class="pill"><?= count($completedEnrollments) ?> записів</span>
    </div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Студент</th>
                    <th>Курс</th>
                    <th>Мова / Рівень</th>
                    <th>Дата запису</th>
                    <th>Дії</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($completedEnrollments)): ?>
                <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted);font-family:var(--mono);font-size:12px;">Немає записів</td></tr>
            <?php else: ?>
            <?php foreach ($completedEnrollments as $e): ?>
            <tr>
                <td>
                    <div class="nm"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></div>
                </td>
                <td><?= htmlspecialchars($e['course_title']) ?></td>
                <td>
                    <span class="course-chip"><?= htmlspecialchars($e['language']) ?></span>
                    <span style="font-family:var(--mono);font-size:10px;color:var(--amber);margin-left:6px;"><?= htmlspecialchars($e['level']) ?></span>
                </td>
                <td style="font-family:var(--mono);font-size:11px;color:var(--muted);">
                    <?= date('d.m.Y', strtotime($e['enrolled_at'])) ?>
                </td>
                <td>
                    <div class="actions">
                        <a class="btn-report btn-diploma"
                           href="generate_diploma.php?student_id=<?= $e['student_id'] ?>&course_id=<?= $e['course_id'] ?>"
                           target="_blank">
                            🎓 Диплом PDF
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
<script src="theme-switcher.js"></script>
</body>
</html>