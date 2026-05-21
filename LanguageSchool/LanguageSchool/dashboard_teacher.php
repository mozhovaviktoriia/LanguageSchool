<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    die("Доступ заборонено");
}

$teacherId = $_SESSION['user_id'];

// Fetch teacher data
$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $teacherId]);
$teacherUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

$teacherName = trim(($teacherUser['first_name'] ?? '') . ' ' . ($teacherUser['last_name'] ?? '')) ?: 'Викладач';
$avatarUrl   = $teacherUser['avatar_url'] ?? '';
$initials    = strtoupper(
    (substr($teacherUser['first_name'] ?? '', 0, 1) ?: '') .
    (substr($teacherUser['last_name']  ?? '', 0, 1) ?: '')
) ?: '👨‍🏫';

// Fetch teacher's courses with enrollment count
$sqlCourses = "
SELECT
    c.id,
    c.title,
    c.level,
    c.price,
    l.name_ua AS language,
    COUNT(DISTINCT e.student_id) AS students_count
FROM courses c
LEFT JOIN languages l ON c.language_id = l.id
LEFT JOIN enrollments e ON e.course_id = c.id AND e.status = 'active'
WHERE c.teacher_id = :teacher_id
GROUP BY c.id, c.title, c.level, c.price, l.name_ua
ORDER BY c.title
";
$stmtC = $pdo->prepare($sqlCourses);
$stmtC->execute(['teacher_id' => $teacherId]);
$courses = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Fetch teacher's students through active enrollments
$sqlStudents = "
SELECT DISTINCT
    u.id,
    u.first_name,
    u.last_name,
    u.avatar_url,
    c.title AS course_title,
    e.status AS enroll_status
FROM enrollments e
JOIN courses c ON e.course_id = c.id
JOIN users u ON e.student_id = u.id
WHERE c.teacher_id = :teacher_id
AND e.status = 'active'
ORDER BY u.last_name, u.first_name
LIMIT 10
";
$stmtS = $pdo->prepare($sqlStudents);
$stmtS->execute(['teacher_id' => $teacherId]);
$students = $stmtS->fetchAll(PDO::FETCH_ASSOC);

// Fetch upcoming lessons for teacher - without groups
$sqlLessons = "
SELECT l.id, l.title, l.scheduled_at,
       (
           SELECT c.title FROM courses c
           JOIN enrollments e ON e.course_id = c.id
           WHERE c.teacher_id = l.teacher_id
           LIMIT 1
       ) AS course_title
FROM lessons l
WHERE l.teacher_id = :teacher_id
  AND l.scheduled_at >= NOW()
ORDER BY l.scheduled_at ASC
LIMIT 5
";
$stmtL = $pdo->prepare($sqlLessons);
$stmtL->execute(['teacher_id' => $teacherId]);
$lessons = $stmtL->fetchAll(PDO::FETCH_ASSOC);

$totalCourses  = count($courses);
$totalStudents = count($students);
$totalLessons  = count($lessons);

// Update last activity timestamp
$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = :id")
    ->execute(['id' => $teacherId]);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Кабінет викладача</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
}

body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 8% 10%, rgba(99,102,241,.12) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 90% 85%, rgba(34,211,238,.09) 0%, transparent 55%);
    pointer-events: none; z-index: 0;
}

/* Sidebar panel */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    padding: 28px 18px;
    z-index: 100; gap: 6px;
}
.logo {
    display: flex; align-items: center; gap: 10px;
    padding: 0 6px 24px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 14px;
}
.logo-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.logo-text {
    font-size: 16px; font-weight: 800;
    background: linear-gradient(90deg, #a5b4fc, var(--teal));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.nav-label {
    font-family: var(--mono);
    font-size: 9px; color: var(--muted);
    letter-spacing: 2px; text-transform: uppercase;
    padding: 0 8px; margin: 10px 0 4px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    text-decoration: none; color: var(--muted);
    font-size: 13px; font-weight: 600;
    transition: .2s; border: 1px solid transparent;
}
.nav-item:hover, .nav-item.active {
    background: rgba(99,102,241,.12);
    color: var(--text); border-color: rgba(99,102,241,.25);
}
.nav-item.active { color: #a5b4fc; }
.nav-icon { font-size: 15px; width: 20px; text-align: center; }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.help-box {
    background: rgba(99,102,241,.08);
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 12px; padding: 14px; text-align: center;
}
.help-box .help-icon { font-size: 28px; margin-bottom: 8px; }
.help-box .help-title { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
.help-box .help-sub { font-family: var(--mono); font-size: 10px; color: var(--muted); line-height: 1.4; }

/* Main content area */
.page {
    margin-left: var(--sidebar);
    margin-right: var(--right);
    flex: 1; position: relative; z-index: 1;
    min-height: 100vh; display: flex; flex-direction: column;
}
.topbar {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 32px;
    border-bottom: 1px solid var(--border);
    background: rgba(13,17,23,.88);
    backdrop-filter: blur(20px);
    position: sticky; top: 0; z-index: 50;
}
.search-wrap {
    flex: 1; display: flex; align-items: center; gap: 10px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 10px; padding: 9px 14px; max-width: 420px;
}
.search-wrap input {
    background: none; border: none; outline: none;
    color: var(--text); font-family: var(--font); font-size: 13px; width: 100%;
}
.search-wrap input::placeholder { color: var(--muted); }
.search-icon { color: var(--muted); font-size: 14px; }
.topbar-date { font-family: var(--mono); font-size: 11px; color: var(--muted); margin-left: auto; }
.content { padding: 28px 32px; flex: 1; }

/* Welcome section */
.welcome-banner {
    background: linear-gradient(135deg, rgba(99,102,241,.18) 0%, rgba(34,211,238,.10) 100%);
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 18px; padding: 28px 32px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 28px; position: relative; overflow: hidden;
    animation: fadeUp .4s ease;
}
.welcome-banner::before {
    content: ''; position: absolute; top: -40px; right: 120px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(99,102,241,.2), transparent 70%);
    pointer-events: none;
}
.welcome-title {
    font-size: 22px; font-weight: 800; margin-bottom: 6px;
    background: linear-gradient(90deg, #e2e8f0, #a5b4fc);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.welcome-sub { font-family: var(--mono); font-size: 11px; color: var(--muted); line-height: 1.5; }
.welcome-sub a { color: var(--teal); text-decoration: none; }
.welcome-sub a:hover { text-decoration: underline; }
.welcome-illo { font-size: 72px; line-height: 1; opacity: .85; }

/* Statistics display */
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px; }
.stat-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px;
    position: relative; overflow: hidden; transition: .2s;
    animation: fadeUp .4s ease both;
}
.stat-card::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
    opacity: 0; transition: opacity .25s;
}
.stat-card.c-purple::after { background: linear-gradient(90deg,var(--accent),#818cf8); }
.stat-card.c-teal::after   { background: linear-gradient(90deg,var(--teal),#67e8f9); }
.stat-card.c-green::after  { background: linear-gradient(90deg,var(--green),#86efac); }
.stat-card:hover { border-color: var(--accent); transform: translateY(-3px); }
.stat-card:hover::after { opacity: 1; }
.stat-icon { font-size: 20px; margin-bottom: 12px; display: block; }
.stat-num  { font-size: 34px; font-weight: 800; letter-spacing: -1px; line-height: 1; }
.c-purple .stat-num { color: #a5b4fc; }
.c-teal   .stat-num { color: var(--teal); }
.c-green  .stat-num { color: var(--green); }
.stat-label { font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 7px; }

/* Section header with title */
.sec-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.sec-title { font-size: 16px; font-weight: 800; }
.sec-line { flex: 1; height: 1px; background: var(--border); }
.sec-action {
    font-family: var(--mono); font-size: 10px; color: var(--teal);
    text-decoration: none; display: flex; align-items: center; gap: 4px; transition: .15s;
}
.sec-action:hover { color: #67e8f9; }
.sec-count {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
    background: var(--border); padding: 2px 9px; border-radius: 99px;
}

/* Courses grid layout */
.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px; margin-bottom: 28px;
}
.course-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px;
    display: flex; flex-direction: column; gap: 10px;
    transition: .2s; animation: fadeUp .4s ease both; cursor: pointer;
}
.course-card:hover { border-color: rgba(99,102,241,.5); transform: translateY(-3px); }
.course-card:nth-child(1) { animation-delay:.05s; }
.course-card:nth-child(2) { animation-delay:.10s; }
.course-card:nth-child(3) { animation-delay:.15s; }
.course-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 10px;
    color: var(--accent); text-transform: uppercase; letter-spacing: .5px;
}
.course-name { font-size: 15px; font-weight: 700; }
.course-meta-row {
    display: flex; gap: 8px; flex-wrap: wrap;
}
.course-tag {
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); background: var(--border);
    padding: 2px 8px; border-radius: 6px;
}
.course-students {
    font-family: var(--mono); font-size: 10px; color: var(--green);
}
.course-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 10px; color: var(--muted);
    text-decoration: none; padding: 6px 10px;
    background: rgba(255,255,255,.04); border: 1px solid var(--border);
    border-radius: 8px; margin-top: auto; transition: .15s; width: fit-content;
}
.course-btn:hover { color: var(--text); border-color: var(--accent); }

/* Students table */
.students-table {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden; margin-bottom: 28px;
}
.t-head {
    display: grid; grid-template-columns: 2fr 2fr 1fr;
    padding: 12px 20px; border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.03);
}
.t-head span {
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
}
.t-row {
    display: grid; grid-template-columns: 2fr 2fr 1fr;
    padding: 14px 20px; border-bottom: 1px solid rgba(30,41,59,.5);
    align-items: center; transition: background .15s;
}
.t-row:last-child { border-bottom: none; }
.t-row:hover { background: rgba(255,255,255,.03); }
.student-info { display: flex; align-items: center; gap: 10px; }
.student-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: white; flex-shrink: 0;
    overflow: hidden;
}
.student-avatar img { width: 100%; height: 100%; object-fit: cover; }
.student-name { font-size: 13px; font-weight: 600; }
.t-course { font-family: var(--mono); font-size: 11px; color: var(--muted); }
.status-badge {
    font-family: var(--mono); font-size: 10px;
    padding: 3px 8px; border-radius: 6px; width: fit-content;
}
.status-active    { background: rgba(34,197,94,.12);  color: var(--green); }
.status-pending   { background: rgba(245,158,11,.12); color: var(--amber); }
.status-completed { background: rgba(99,102,241,.12); color: #a5b4fc; }

/* Lessons table */
.lessons-table {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden; margin-bottom: 12px;
}
.l-head {
    display: grid; grid-template-columns: 1fr 1fr 110px 80px;
    padding: 12px 20px; border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.03);
}
.l-head span {
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
}
.l-row {
    display: grid; grid-template-columns: 1fr 1fr 110px 80px;
    padding: 14px 20px; border-bottom: 1px solid rgba(30,41,59,.5);
    align-items: center; transition: background .15s;
}
.l-row:last-child { border-bottom: none; }
.l-row:hover { background: rgba(255,255,255,.03); }
.l-group {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
    background: var(--border); padding: 3px 8px; border-radius: 6px; width: fit-content;
}
.l-title  { font-size: 13px; font-weight: 600; }
.l-course { font-family: var(--mono); font-size: 11px; color: var(--muted); }
.l-date   { font-family: var(--mono); font-size: 11px; color: var(--text); }
.l-time   { font-family: var(--mono); font-size: 12px; font-weight: 600; color: var(--accent); }

.empty-state {
    background: var(--card); border: 1px dashed var(--border);
    border-radius: var(--radius); padding: 36px; text-align: center;
    font-family: var(--mono); font-size: 12px; color: var(--muted);
    margin-bottom: 28px;
}

/* Right sidebar panel */
.right-panel {
    position: fixed; top: 0; right: 0;
    width: var(--right); height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-left: 1px solid var(--border);
    padding: 28px 18px;
    display: flex; flex-direction: column; gap: 20px;
    z-index: 100; overflow-y: auto;
}
.profile-block { text-align: center; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.profile-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent) 0%, var(--teal) 100%);
    margin: 0 auto 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; color: white;
    border: 3px solid rgba(99,102,241,.3);
    box-shadow: 0 0 24px rgba(99,102,241,.25);
    overflow: hidden; position: relative;
}
.profile-avatar img {
    width: 100%; height: 100%; object-fit: cover;
    position: absolute; inset: 0; border-radius: 50%;
}
.profile-name { font-size: 15px; font-weight: 800; margin-bottom: 4px; }
.profile-role { font-family: var(--mono); font-size: 10px; color: var(--teal); text-transform: uppercase; letter-spacing: 1px; }
.profile-btn {
    display: block; margin: 12px auto 0; padding: 8px 20px;
    background: linear-gradient(135deg, var(--accent), #818cf8);
    color: white; text-decoration: none; border-radius: 10px;
    font-size: 12px; font-weight: 700; text-align: center; width: fit-content;
    transition: .2s; box-shadow: 0 4px 16px rgba(99,102,241,.3);
}
.profile-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,.4); }

/* Calendar widget */
.cal-header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cal-month { font-size: 14px; font-weight: 700; }
.cal-nav { display: flex; gap: 4px; }
.cal-nav-btn {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 7px; width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--muted); font-size: 12px; transition: .15s;
}
.cal-nav-btn:hover { color: var(--text); border-color: var(--accent); }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; text-align: center; }
.cal-dow { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; padding: 4px 0; }
.cal-day { padding: 6px 3px; border-radius: 7px; font-size: 11px; font-weight: 500; cursor: pointer; color: #94a3b8; transition: .15s; }
.cal-day:hover { background: rgba(99,102,241,.15); color: var(--text); }
.cal-day.today { background: linear-gradient(135deg, var(--accent), #818cf8); color: white; font-weight: 800; }

/* Reminders section */
.reminders-title { font-size: 14px; font-weight: 800; margin-bottom: 16px; text-transform: uppercase; letter-spacing: .5px; }
.reminder-item { display: flex; flex-direction: column; gap: 8px; padding: 14px; margin-bottom: 10px; background: rgba(99,102,241,.06); border: 1px solid rgba(99,102,241,.2); border-radius: 12px; transition: .2s; }
.reminder-item:hover { background: rgba(99,102,241,.12); border-color: rgba(99,102,241,.4); }
.reminder-bell {
    width: 32px; height: 32px; background: rgba(99,102,241,.15);
    border: 1px solid rgba(99,102,241,.3); border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.reminder-text { font-size: 13px; font-weight: 700; line-height: 1.4; color: #e2e8f0; }
.reminder-date { font-size: 15px; font-weight: 800; color: #a5b4fc; margin-top: 2px; }
.reminder-course { font-family: var(--mono); font-size: 10px; color: var(--teal); text-transform: uppercase; letter-spacing: .3px; margin-top: 4px; }
.reminder-time { font-family: var(--mono); font-size: 11px; color: #fca5a5; font-weight: 600; }

.logout {
    display: block; padding: 9px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
    border-radius: 10px; color: #fca5a5;
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    text-decoration: none; text-align: center; transition: .2s; margin-top: auto;
}
.logout:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">👨‍🏫</div>
        <div class="logo-text">EduSpace</div>
    </div>
    <span class="nav-label">Меню</span>
    <a class="nav-item active" href="dashboard_teacher.php"><span class="nav-icon">📚</span> Мої курси</a>
    <a class="nav-item" href="students.php"><span class="nav-icon">👨‍🎓</span> Мої учні</a>
    <a class="nav-item" href="schedule_teacher.php"><span class="nav-icon">📅</span> Розклад</a>
    <a class="nav-item" href="tasks.php"><span class="nav-icon">✓</span> Завдання</a>
    <a class="nav-item" href="chat.php"><span class="nav-icon">💬</span> Чат</a>
    <a class="nav-item" href="https://meet.google.com" target="_blank"><span class="nav-icon">📞</span> Meet</a>
    <div class="sidebar-bottom">
        <div class="help-box">
            <div class="help-icon">🎯</div>
            <div class="help-title">Потрібна допомога?</div>
            <div class="help-sub">Зверніться до підтримки у будь-який час</div>
        </div>
    </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="page">
    <div class="topbar">
        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" placeholder="Пошук курсів, учнів...">
        </div>
        <button class="theme-toggle" title="Змінити тему">
            <span class="theme-toggle-icon">☀️</span>
        </button>
        <div class="topbar-date" id="dateLabel"></div>
    </div>

    <div class="content">

        <!-- Welcome -->
        <div class="welcome-banner">
            <div>
                <div class="welcome-title">Вітаємо, <?= htmlspecialchars($teacherName) ?>!</div>
                <div class="welcome-sub">
                    У вас <?= $totalCourses ?> курсів та <?= $totalStudents ?> учнів.<br>
                    Перевірте <a href="schedule_teacher.php">розклад</a> або додайте новий урок.
                </div>
            </div>
            <div class="welcome-illo">📖</div>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card c-purple" style="animation-delay:.05s">
                <span class="stat-icon">📚</span>
                <div class="stat-num"><?= $totalCourses ?></div>
                <div class="stat-label">Моїх курсів</div>
            </div>
            <div class="stat-card c-teal" style="animation-delay:.10s">
                <span class="stat-icon">👨‍🎓</span>
                <div class="stat-num"><?= $totalStudents ?></div>
                <div class="stat-label">Учнів</div>
            </div>
            <div class="stat-card c-green" style="animation-delay:.15s">
                <span class="stat-icon">🗓</span>
                <div class="stat-num"><?= $totalLessons ?></div>
                <div class="stat-label">Найближчих занять</div>
            </div>
        </div>

        <!-- Courses -->
        <div class="sec-head">
            <div class="sec-title">Мої курси</div>
            <div class="sec-line"></div>
            <span class="sec-count"><?= $totalCourses ?></span>
        </div>

        <?php if ($courses): ?>
        <div class="courses-grid">
            <?php foreach ($courses as $c): ?>
            <div class="course-card">
                <div class="course-badge">📘 Курс</div>
                <div class="course-name"><?= htmlspecialchars($c['title']) ?></div>
                <div class="course-meta-row">
                    <?php if ($c['language']): ?>
                    <span class="course-tag"><?= htmlspecialchars($c['language']) ?></span>
                    <?php endif; ?>
                    <span class="course-tag"><?= htmlspecialchars($c['level']) ?></span>
                </div>
                <div class="course-students">👥 <?= (int)$c['students_count'] ?> учнів</div>
                <a href="course.php?id=<?= $c['id'] ?>" class="course-btn">📂 Відкрити</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">У вас ще немає курсів. Зверніться до адміністратора.</div>
        <?php endif; ?>

        <!-- Students -->
        <div class="sec-head">
            <div class="sec-title">Мої учні</div>
            <div class="sec-line"></div>
            <a class="sec-action" href="students.php">Всі →</a>
            <span class="sec-count"><?= $totalStudents ?></span>
        </div>

        <?php if ($students): ?>
        <div class="students-table">
            <div class="t-head">
                <span>Учень</span>
                <span>Курс</span>
                <span>Статус</span>
            </div>
            <?php foreach ($students as $s):
                $sInitials = strtoupper(
                    substr($s['first_name'] ?? '', 0, 1) .
                    substr($s['last_name']  ?? '', 0, 1)
                );
                $statusClass = match($s['enroll_status']) {
                    'active'    => 'status-active',
                    'completed' => 'status-completed',
                    default     => 'status-pending',
                };
                $statusLabel = match($s['enroll_status']) {
                    'active'    => 'Активний',
                    'completed' => 'Завершив',
                    'pending'   => 'Очікує',
                    default     => htmlspecialchars($s['enroll_status']),
                };
            ?>
            <div class="t-row">
                <div class="student-info">
                    <div class="student-avatar">
                        <?php if (!empty($s['avatar_url']) && file_exists($s['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($s['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <?= $sInitials ?>
                        <?php endif; ?>
                    </div>
                    <div class="student-name"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></div>
                </div>
                <div class="t-course"><?= htmlspecialchars($s['course_title']) ?></div>
                <div><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Немає записаних учнів</div>
        <?php endif; ?>

        <!-- Lessons -->
        <div class="sec-head">
            <div class="sec-title">Найближчі заняття</div>
            <div class="sec-line"></div>
            <a class="sec-action" href="schedule.php">Всі →</a>
            <span class="sec-count"><?= $totalLessons ?></span>
        </div>

        <?php if ($lessons): ?>
        <div class="lessons-table">
            <div class="l-head">
                <span>Урок</span>
                <span>Курс</span>
                <span>Дата</span>
                <span>Час</span>
            </div>
            <?php foreach ($lessons as $ls):
                $dt   = new DateTime($ls['scheduled_at']);
                $date = $dt->format('d.m.Y');
                $time = $dt->format('H:i');
            ?>
            <div class="l-row">
                <div class="l-title"><?= htmlspecialchars($ls['title']) ?></div>
                <div class="l-course"><?= htmlspecialchars($ls['course_title'] ?? '—') ?></div>
                <div class="l-date"><?= $date ?></div>
                <div class="l-time"><?= $time ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Немає запланованих занять</div>
        <?php endif; ?>

    </div>
</div>

<!-- ════ RIGHT PANEL ════ -->
<aside class="right-panel">
    <div class="profile-block">
        <div class="profile-avatar">
            <?php if (!empty($avatarUrl) && file_exists($avatarUrl)): ?>
                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Аватар">
            <?php else: ?>
                <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
        </div>
        <div class="profile-name"><?= htmlspecialchars($teacherName) ?></div>
        <div class="profile-role">Викладач</div>
        <a class="profile-btn" href="profile.php">Профіль</a>
    </div>

    <div class="cal-block">
        <div class="cal-header-row">
            <div class="cal-month" id="calMonthLabel"></div>
            <div class="cal-nav">
                <button class="cal-nav-btn" onclick="changeMonth(-1)">‹</button>
                <button class="cal-nav-btn" onclick="changeMonth(1)">›</button>
            </div>
        </div>
        <div class="cal-grid" id="calGrid"></div>
    </div>

    <div class="reminders-block">
        <div class="reminders-title">📋 Нагадування</div>
        <?php foreach (array_slice($lessons, 0, 4) as $ls):
            $dt = new DateTime($ls['scheduled_at']);
            $isToday = $dt->format('Y-m-d') === (new DateTime())->format('Y-m-d');
            $isTomorrow = $dt->format('Y-m-d') === (new DateTime())->modify('+1 day')->format('Y-m-d');
        ?>
        <div class="reminder-item">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="reminder-bell">🔔</div>
                <div style="flex:1;">
                    <div class="reminder-text"><?= htmlspecialchars($ls['title']) ?></div>
                    <div style="font-size:11px; color:var(--muted); margin-top:2px;"><?= htmlspecialchars($ls['course_title']) ?></div>
                </div>
            </div>
            <div style="display:flex; align-items:baseline; gap:8px; margin-top:6px;">
                <div class="reminder-date"><?php 
                    if ($isToday) echo ' Сьогодні';
                    else if ($isTomorrow) echo ' Завтра';
                    else echo $dt->format('d.m.Y');
                ?></div>
                <div class="reminder-time"><?= $dt->format('H:i') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$lessons): ?>
        <div style="text-align:center; padding:20px; font-family:var(--mono); font-size:11px; color:var(--muted); background:rgba(255,255,255,.03); border-radius:10px; border:1px dashed var(--border);">Немає зустрічей на найближчі дні</div>
        <?php endif; ?>
    </div>

    <a class="logout" href="logout.php">🚪 Вийти</a>
</aside>

<script>
(function(){
    const opt = { day:'numeric', month:'long', year:'numeric', weekday:'long' };
    document.getElementById('dateLabel').textContent = new Date().toLocaleDateString('uk-UA', opt);
})();

let calYear, calMonth;
(function(){
    const n = new Date();
    calYear = n.getFullYear();
    calMonth = n.getMonth();
    renderCal();
})();

function changeMonth(dir){
    calMonth += dir;
    if(calMonth < 0){ calMonth = 11; calYear--; }
    if(calMonth > 11){ calMonth = 0; calYear++; }
    renderCal();
}

function renderCal(){
    const monthNames = ['Січень','Лютий','Березень','Квітень','Травень','Червень',
                        'Липень','Серпень','Вересень','Жовтень','Листопад','Грудень'];
    const dows = ['Пн','Вт','Ср','Чт','Пт','Сб','Нд'];
    document.getElementById('calMonthLabel').textContent = monthNames[calMonth] + ' ' + calYear;
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';
    dows.forEach(d => {
        const el = document.createElement('div');
        el.className = 'cal-dow'; el.textContent = d;
        grid.appendChild(el);
    });
    const firstDay = (new Date(calYear, calMonth, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
    const today = new Date();
    const isCurrentMonth = today.getFullYear() === calYear && today.getMonth() === calMonth;
    for(let i = 0; i < firstDay; i++){
        grid.appendChild(document.createElement('div'));
    }
    for(let d = 1; d <= daysInMonth; d++){
        const el = document.createElement('div');
        el.className = 'cal-day';
        el.textContent = d;
        if(isCurrentMonth && d === today.getDate()) el.classList.add('today');
        grid.appendChild(el);
    }
}
</script>
<script src="theme-switcher.js"></script>
</body>
</html>