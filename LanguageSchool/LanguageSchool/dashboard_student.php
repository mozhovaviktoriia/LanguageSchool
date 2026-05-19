<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = $_SESSION['user_id'];

/* ── Записатись на курс ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_course_id'])) {
    $courseId = $_POST['enroll_course_id'];
    $check = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = :s AND course_id = :c");
    $check->execute([':s' => $studentId, ':c' => $courseId]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, status) VALUES (:s, :c, 'active')");
        $ins->execute([':s' => $studentId, ':c' => $courseId]);
    }
    header("Location: dashboard_student.php");
    exit;
}

/* ── Покинути курс ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_course_id'])) {
    $courseId = $_POST['leave_course_id'];
    $del = $pdo->prepare("DELETE FROM enrollments WHERE student_id = :s AND course_id = :c");
    $del->execute([':s' => $studentId, ':c' => $courseId]);
    header("Location: dashboard_student.php");
    exit;
}

/* ── Дані студента ── */
$stmtUser = $pdo->prepare("SELECT first_name, last_name, email, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $studentId]);
$me = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Студент';
$initials = strtoupper(substr($me['first_name'] ?? '', 0, 1) . substr($me['last_name'] ?? '', 0, 1)) ?: 'СТ';

/* ── Мої курси ── */
$enrolled = $pdo->prepare("
    SELECT c.id, c.title, c.level, c.description, l.name_ua, l.code,
           e.status AS enroll_status, e.enrolled_at
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    JOIN languages l ON c.language_id = l.id
    WHERE e.student_id = :s AND e.status = 'active'
    ORDER BY e.enrolled_at DESC
");
$enrolled->execute([':s' => $studentId]);
$myCourses = $enrolled->fetchAll(PDO::FETCH_ASSOC);
$enrolledIds = array_column($myCourses, 'id');

/* ── Всі доступні курси ── */
$all = $pdo->prepare("
    SELECT c.id, c.title, c.level, c.description, c.price, l.name_ua, l.code,
           u.first_name AS teacher_first, u.last_name AS teacher_last
    FROM courses c
    JOIN languages l ON c.language_id = l.id
    LEFT JOIN users u ON c.teacher_id = u.id
    WHERE c.is_active = TRUE
    ORDER BY l.id, c.title
");
$all->execute();
$allCourses = $all->fetchAll(PDO::FETCH_ASSOC);

/* ── Найближчі заняття (нагадування) ── */
$upcoming = $pdo->prepare("
    SELECT
        l.id, l.title, l.scheduled_at, l.lesson_type, l.meeting_url,
        c.id AS course_id, c.title AS course_title, l.teacher_id,
        u.first_name AS teacher_first, u.last_name AS teacher_last,
        lang.code AS lang_code, lang.name_ua AS lang_name
    FROM lessons l
    JOIN courses c ON l.course_id = c.id
    JOIN languages lang ON c.language_id = lang.id
    JOIN users u ON l.teacher_id = u.id
    WHERE l.course_id IN (SELECT course_id FROM enrollments WHERE student_id = :s AND status = 'active')
    AND l.scheduled_at >= NOW()
    AND DATE(l.scheduled_at AT TIME ZONE 'UTC') <= CURRENT_DATE + INTERVAL '7 days'
    ORDER BY l.scheduled_at ASC
    LIMIT 5
");
$upcoming->execute([':s' => $studentId]);
$upcomingLessons = $upcoming->fetchAll(PDO::FETCH_ASSOC);

/* ── Статистика завдань та оцінок ── */
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_submissions,
        COUNT(CASE WHEN ts.score IS NOT NULL THEN 1 END) AS graded_count,
        ROUND(AVG(ts.score), 1) AS avg_score
    FROM task_submissions ts
    WHERE ts.student_id = :s
");
$stmtStats->execute([':s' => $studentId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
$submittedCount = (int)($stats['total_submissions'] ?? 0);
$gradedCount = (int)($stats['graded_count'] ?? 0);
$avgScore = $stats['avg_score'] ?? null;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мій кабінет — LinguaHub</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:#07080f; --surface:#0d1117; --card:#111827; --border:#1e293b;
    --accent:#6366f1; --teal:#22d3ee; --green:#22c55e; --amber:#f59e0b;
    --red:#ef4444; --text:#e2e8f0; --muted:#64748b;
    --radius:14px; --font:'Syne',sans-serif; --mono:'JetBrains Mono',monospace;
    --sidebar:230px;
}
body { font-family:var(--font); background:var(--bg); color:var(--text); min-height:100vh; display:flex; overflow:hidden; }
body::before { content:''; position:fixed; inset:0; background: radial-gradient(ellipse 80% 50% at 5% 0%,rgba(99,102,241,.13) 0%,transparent 55%), radial-gradient(ellipse 55% 40% at 95% 90%,rgba(34,211,238,.09) 0%,transparent 55%); pointer-events:none; z-index:0; }

/* Sidebar panel */
.sidebar { position:fixed; top:0; left:0; bottom:0; width:var(--sidebar); background:rgba(13,17,23,.97); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:20; }
.sidebar-logo { padding:22px 20px 18px; border-bottom:1px solid var(--border); }
.logo-text { font-size:20px; font-weight:800; letter-spacing:-.5px; }
.logo-text span { color:var(--teal); }
.sidebar-profile { display:flex; align-items:center; gap:11px; padding:14px 20px; border-bottom:1px solid var(--border); }
.s-avatar { width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal)); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; border:2px solid rgba(99,102,241,.4); overflow:hidden; }
.s-avatar img { width:100%; height:100%; object-fit:cover; }
.profile-name { font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
.profile-role { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-top:2px; }

.sidebar-nav { flex:1; padding:12px 10px; display:flex; flex-direction:column; gap:2px; overflow-y:auto; }
.nav-label { font-family:var(--mono); font-size:9px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; padding:10px 10px 4px; margin-top:4px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:10px; text-decoration:none; color:var(--muted); font-size:13px; font-weight:600; transition:.18s; border:1px solid transparent; }
.nav-item svg { width:15px; height:15px; flex-shrink:0; }
.nav-item .nav-emoji { font-size:14px; width:15px; text-align:center; flex-shrink:0; }
.nav-item:hover { color:var(--text); background:rgba(255,255,255,.04); }
.nav-item.active { color:#fff; background:rgba(99,102,241,.15); border-color:rgba(99,102,241,.3); }
.nav-badge { margin-left:auto; font-family:var(--mono); font-size:9px; font-weight:700; padding:2px 7px; border-radius:99px; background:rgba(99,102,241,.25); color:#a5b4fc; }
.nav-badge.green { background:rgba(34,197,94,.2); color:var(--green); }
.nav-badge.amber { background:rgba(245,158,11,.2); color:var(--amber); }

.sidebar-footer { padding:12px 10px; border-top:1px solid var(--border); }
.logout-btn { display:flex; align-items:center; gap:10px; width:100%; padding:10px 12px; border-radius:10px; background:rgba(239,68,68,.07); border:1px solid rgba(239,68,68,.2); color:#fca5a5; font-family:var(--font); font-size:13px; font-weight:600; cursor:pointer; transition:.18s; text-decoration:none; }
.logout-btn:hover { background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.4); }
.logout-btn svg { width:14px; height:14px; }

/* Main content area */
.main { margin-left:var(--sidebar); flex:1; display:flex; flex-direction:column; min-height:100vh; position:relative; z-index:1; overflow-y:auto; height:100vh; }
.topbar { position:sticky; top:0; z-index:10; display:flex; align-items:center; justify-content:space-between; padding:14px 30px; border-bottom:1px solid var(--border); background:rgba(7,8,15,.9); backdrop-filter:blur(20px); }
.topbar-title { font-size:19px; font-weight:800; letter-spacing:-.4px; }
.topbar-title span { color:var(--teal); }
.topbar-right { display:flex; align-items:center; gap:10px; }
.topbar-btn { display:flex; align-items:center; gap:7px; padding:8px 14px; border-radius:9px; border:1px solid var(--border); background:var(--surface); color:var(--muted); font-family:var(--mono); font-size:11px; text-decoration:none; transition:.18s; font-weight:600; }
.topbar-btn:hover { color:var(--text); border-color:rgba(99,102,241,.4); background:rgba(99,102,241,.08); }

.content { padding:24px 30px; display:flex; flex-direction:column; gap:20px; }

/* Statistics display */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:18px 16px; position:relative; overflow:hidden; transition:border-color .2s,transform .2s; }
.stat-card:hover { transform:translateY(-2px); }
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; opacity:0; transition:opacity .25s; }
.stat-card.c-purple::after { background:linear-gradient(90deg,var(--accent),#818cf8); }
.stat-card.c-teal::after   { background:linear-gradient(90deg,var(--teal),#67e8f9); }
.stat-card.c-green::after  { background:linear-gradient(90deg,var(--green),#86efac); }
.stat-card.c-amber::after  { background:linear-gradient(90deg,var(--amber),#fcd34d); }
.stat-card:hover::after { opacity:1; }
.stat-num { font-size:30px; font-weight:800; letter-spacing:-1px; line-height:1; margin:8px 0 5px; }
.c-purple .stat-num { color:#a5b4fc; } .c-teal .stat-num { color:var(--teal); } .c-green .stat-num { color:var(--green); } .c-amber .stat-num { color:var(--amber); }
.stat-label { font-family:var(--mono); font-size:10px; color:var(--muted); letter-spacing:.5px; }

/* Navigation links */
.quick-links { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.quick-link { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:16px 10px; border-radius:12px; border:1px solid var(--border); background:var(--card); text-decoration:none; color:var(--muted); font-size:12px; font-weight:700; transition:.2s; text-align:center; }
.quick-link:hover { color:var(--text); border-color:rgba(99,102,241,.4); background:rgba(99,102,241,.07); transform:translateY(-2px); }
.quick-link-icon { font-size:22px; }

/* Course card */
.card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:20px; }
.card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.card-title { font-size:14px; font-weight:800; }

/* Courses grid layout */
.courses-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:13px; }
.course-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:border-color .2s,transform .2s; display:flex; flex-direction:column; }
.course-card:hover { border-color:rgba(99,102,241,.4); transform:translateY(-3px); }
.course-flag { width:100%; height:66px; display:flex; align-items:center; justify-content:center; font-size:28px; }
.flag-en { background:linear-gradient(135deg,#1a237e,#283593); }
.flag-de { background:linear-gradient(135deg,#1a1a1a,#7f0000); }
.flag-ja { background:linear-gradient(135deg,#0d0d1a,#1a0a0a); }
.flag-fr { background:linear-gradient(135deg,#001f5b,#8b0000); }
.course-body { padding:12px 13px 13px; flex:1; display:flex; flex-direction:column; }
.course-lang-tag { font-family:var(--mono); font-size:9px; color:var(--teal); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.course-name { font-size:12px; font-weight:700; margin-bottom:4px; line-height:1.35; }
.course-desc { font-size:10px; color:var(--muted); margin-bottom:7px; line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.course-teacher { font-family:var(--mono); font-size:9px; color:var(--muted); margin-bottom:5px; }
.course-teacher span { color:#a5b4fc; }
.course-level { display:inline-flex; font-family:var(--mono); font-size:9px; padding:2px 8px; border-radius:99px; background:rgba(99,102,241,.12); color:#a5b4fc; margin-bottom:9px; }
.course-footer { display:flex; align-items:center; justify-content:space-between; margin-top:auto; padding-top:7px; }
.course-price { font-family:var(--mono); font-size:11px; font-weight:700; color:var(--amber); }

.enroll-btn { font-family:var(--font); font-size:10px; font-weight:700; padding:6px 12px; border-radius:8px; background:rgba(99,102,241,.15); color:#a5b4fc; border:1px solid rgba(99,102,241,.3); cursor:pointer; transition:.18s; }
.enroll-btn:hover { background:rgba(99,102,241,.3); border-color:rgba(99,102,241,.6); color:#fff; }
.enrolled-badge { display:inline-flex; align-items:center; gap:4px; font-family:var(--mono); font-size:9px; padding:5px 10px; border-radius:8px; background:rgba(34,197,94,.1); color:var(--green); border:1px solid rgba(34,197,94,.2); }
.leave-btn { font-family:var(--font); font-size:10px; font-weight:700; padding:6px 12px; border-radius:8px; background:rgba(239,68,68,.1); color:#fca5a5; border:1px solid rgba(239,68,68,.2); cursor:pointer; transition:.18s; }
.leave-btn:hover { background:rgba(239,68,68,.25); border-color:rgba(239,68,68,.5); color:#fff; }

/* Grid layout structure */
.two-col { display:grid; grid-template-columns:1fr 220px; gap:20px; align-items:start; }
.right-col { display:flex; flex-direction:column; gap:14px; }

/* Calendar widget */
.cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.cal-month { font-family:var(--mono); font-size:11px; font-weight:500; }
.cal-arrow { background:none; border:none; color:var(--muted); cursor:pointer; padding:2px 6px; border-radius:5px; font-size:12px; transition:.15s; }
.cal-arrow:hover { color:var(--teal); }
.cal-dh-row { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; margin-bottom:3px; }
.cal-dh { font-family:var(--mono); font-size:9px; color:var(--muted); text-align:center; }
.cal-days { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.cal-day { height:26px; display:flex; align-items:center; justify-content:center; border-radius:6px; font-family:var(--mono); font-size:10px; color:var(--muted); cursor:pointer; transition:.15s; }
.cal-day:hover { background:rgba(255,255,255,.05); color:var(--text); }
.cal-day.today { background:var(--accent); color:#fff; font-weight:700; }
.cal-day.other { opacity:.25; }

/* Modal dialog */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(6px); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--card); border:1px solid var(--border); border-radius:18px; padding:32px; max-width:360px; width:90%; text-align:center; animation:fadeUp .25s ease; }
.modal-icon { font-size:40px; margin-bottom:12px; }
.modal-title { font-size:17px; font-weight:800; margin-bottom:8px; }
.modal-sub { font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:24px; line-height:1.6; }
.modal-actions { display:flex; gap:10px; justify-content:center; }
.modal-cancel { padding:10px 24px; border-radius:10px; background:var(--border); color:var(--muted); border:none; font-family:var(--font); font-size:13px; font-weight:600; cursor:pointer; transition:.18s; }
.modal-cancel:hover { color:var(--text); }
.modal-confirm { padding:10px 24px; border-radius:10px; background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.3); font-family:var(--font); font-size:13px; font-weight:700; cursor:pointer; transition:.18s; }
.modal-confirm:hover { background:rgba(239,68,68,.3); color:#fff; }

.empty-state { display:flex; flex-direction:column; align-items:center; padding:32px 24px; gap:10px; text-align:center; }
.empty-icon { font-size:36px; opacity:.5; }
.empty-title { font-size:13px; font-weight:700; }
.empty-sub { font-family:var(--mono); font-size:10px; color:var(--muted); max-width:240px; line-height:1.6; }

@keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

/* Reminders styling */
.reminders-block { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:20px; }
.reminders-title { font-size:14px; font-weight:800; margin-bottom:16px; text-transform:uppercase; letter-spacing:.5px; color:var(--text); }
.reminders-list { display:flex; flex-direction:column; gap:10px; }
.reminder-item { display:flex; flex-direction:column; gap:6px; padding:14px; background:rgba(99,102,241,.06); border:1px solid rgba(99,102,241,.2); border-radius:12px; transition:.2s; }
.reminder-item:hover { background:rgba(99,102,241,.12); border-color:rgba(99,102,241,.4); }
.reminder-title { font-size:12px; font-weight:700; color:var(--text); }
.reminder-course { font-size:10px; color:#a5b4fc; font-family:var(--mono); margin-bottom:2px; }
.reminder-datetime { display:flex; align-items:center; gap:6px; font-size:11px; }
.reminder-date { font-weight:800; color:var(--teal); }
.reminder-time { font-family:var(--mono); color:#fca5a5; font-weight:600; }
.empty-reminders { text-align:center; padding:24px; opacity:.5; }
.empty-reminders-icon { font-size:32px; margin-bottom:8px; }
.empty-reminders-text { font-size:11px; color:var(--muted); }
</style>
</head>
<body>

<!-- Модальне вікно підтвердження -->
<div class="modal-overlay" id="leaveModal">
    <div class="modal-box">
        <div class="modal-icon">⚠️</div>
        <div class="modal-title">Покинути курс?</div>
        <div class="modal-sub">Ви впевнені? Ваш прогрес може бути втрачено.<br>Ви зможете записатись знову пізніше.</div>
        <div class="modal-actions">
            <button class="modal-cancel" onclick="closeModal()">Скасувати</button>
            <button class="modal-confirm" id="modalConfirmBtn">Покинути</button>
        </div>
    </div>
</div>

<aside class="sidebar">
    <div class="sidebar-logo"><div class="logo-text">Lingua<span>Hub</span></div></div>
    <div class="sidebar-profile">
        <div class="s-avatar">
            <?php if (!empty($me['avatar_url']) && file_exists($me['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($me['avatar_url']) ?>" alt="">
            <?php else: ?>
                <?= $initials ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="profile-name"><?= htmlspecialchars($studentName) ?></div>
            <div class="profile-role">Студент</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Навігація</div>

        <a class="nav-item active" href="dashboard_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Дашборд
        </a>

        <a class="nav-item" href="courses_catalog.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            Всі курси
            <span class="nav-badge"><?= count($allCourses) ?></span>
        </a>

        <a class="nav-item" href="schedule_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Розклад
        </a>

        <a class="nav-item" href="grades_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            Оцінки
            <?php if ($gradedCount > 0): ?>
            <span class="nav-badge green"><?= $gradedCount ?></span>
            <?php else: ?>
            <span class="nav-badge">0</span>
            <?php endif; ?>
        </a>

        <a class="nav-item" href="homework_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Домашні завдання
            <span class="nav-badge amber">!</span>
        </a>

        <a class="nav-item" href="chat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Чат з викладачем
        </a>

        <a class="nav-item" href="https://meet.google.com" target="_blank">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
            Відеоурок
        </a>

        <div class="nav-label">Акаунт</div>

        <a class="nav-item" href="profile_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Налаштування
        </a>
    </nav>

    <div class="sidebar-footer">
        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px;display:flex;align-items:center;justify-content:center">
            <span class="theme-toggle-icon">☀️</span>
        </button>
        <a class="logout-btn" href="logout.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Вийти
        </a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div class="topbar-title">ВІТАЄМО, <span><?= strtoupper(htmlspecialchars($me['first_name'] ?? 'СТУДЕНТ')) ?></span>!</div>
    </div>

    <div class="content">

        <!-- Статистика -->
        <div class="stats-row">
            <div class="stat-card c-purple">
                <div style="font-size:16px">📚</div>
                <div class="stat-num"><?= count($myCourses) ?></div>
                <div class="stat-label">Активні курси</div>
            </div>
            <div class="stat-card c-teal">
                <div style="font-size:16px">🗓</div>
                <div class="stat-num"><?= count($upcomingLessons) ?></div>
                <div class="stat-label">Найближчі заняття</div>
            </div>
            <div class="stat-card c-green">
                <div style="font-size:16px">⭐</div>
                <div class="stat-num"><?= $avgScore !== null ? $avgScore : '—' ?></div>
                <div class="stat-label">Середній бал</div>
            </div>
            <div class="stat-card c-amber">
                <div style="font-size:16px">📝</div>
                <div class="stat-num"><?= $submittedCount ?></div>
                <div class="stat-label">Завдань здано</div>
            </div>
        </div>

        <div class="two-col">
            <div style="display:flex;flex-direction:column;gap:20px;">

                <!-- МОЇ КУРСИ -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">Мої курси</div>
                        <span style="font-family:var(--mono);font-size:10px;color:var(--muted)"><?= count($myCourses) ?> активних</span>
                    </div>
                    <?php if (empty($myCourses)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <div class="empty-title">Ви ще не записані на жоден курс</div>
                            <div class="empty-sub">Оберіть курс з каталогу нижче та натисніть "Записатись"</div>
                        </div>
                    <?php else: ?>
                        <div class="courses-grid">
                            <?php foreach ($myCourses as $c):
                                $flags = ['en'=>'🇬🇧','de'=>'🇩🇪','ja'=>'🇯🇵','fr'=>'🇫🇷'];
                                $flag = $flags[$c['code']] ?? '🌐';
                            ?>
                            <div class="course-card">
                                <div class="course-flag flag-<?= htmlspecialchars($c['code']) ?>"><?= $flag ?></div>
                                <div class="course-body">
                                    <div class="course-lang-tag"><?= htmlspecialchars($c['name_ua']) ?></div>
                                    <div class="course-name"><?= htmlspecialchars($c['title']) ?></div>
                                    <div class="course-desc"><?= htmlspecialchars($c['description']) ?></div>
                                    <div class="course-level"><?= htmlspecialchars($c['level']) ?></div>
                                    <div class="course-footer">
                                        <span class="enrolled-badge">✓ Записаний</span>
                                        <button class="leave-btn"
                                            onclick="confirmLeave('<?= htmlspecialchars($c['id']) ?>', '<?= htmlspecialchars(addslashes($c['title'])) ?>')">
                                            Покинути
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- КАТАЛОГ -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">Каталог курсів</div>
                        <span style="font-family:var(--mono);font-size:10px;color:var(--muted)"><?= count($allCourses) ?> курсів</span>
                    </div>
                    <div class="courses-grid">
                        <?php foreach ($allCourses as $c):
                            $flags = ['en'=>'🇬🇧','de'=>'🇩🇪','ja'=>'🇯🇵','fr'=>'🇫🇷'];
                            $flag = $flags[$c['code']] ?? '🌐';
                            $isEnrolled = in_array($c['id'], $enrolledIds);
                            $teacherName = trim(($c['teacher_first'] ?? '') . ' ' . ($c['teacher_last'] ?? ''));
                        ?>
                        <div class="course-card">
                            <div class="course-flag flag-<?= htmlspecialchars($c['code']) ?>"><?= $flag ?></div>
                            <div class="course-body">
                                <div class="course-lang-tag"><?= htmlspecialchars($c['name_ua']) ?></div>
                                <div class="course-name"><?= htmlspecialchars($c['title']) ?></div>
                                <div class="course-desc"><?= htmlspecialchars($c['description']) ?></div>
                                <?php if ($teacherName): ?>
                                <div class="course-teacher">Викладач: <span><?= htmlspecialchars($teacherName) ?></span></div>
                                <?php endif; ?>
                                <div class="course-level"><?= htmlspecialchars($c['level']) ?></div>
                                <div class="course-footer">
                                    <span class="course-price"><?= number_format($c['price'], 0) ?> грн</span>
                                    <?php if ($isEnrolled): ?>
                                        <span class="enrolled-badge">✓ Записаний</span>
                                    <?php else: ?>
                                        <form method="POST" style="margin:0">
                                            <input type="hidden" name="enroll_course_id" value="<?= htmlspecialchars($c['id']) ?>">
                                            <button type="submit" class="enroll-btn">Записатись →</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <!-- ПРАВА КОЛОНКА -->
            <div class="right-col">
                <!-- НАГАДУВАННЯ -->
                <div class="reminders-block">
                    <div class="reminders-title">🔔 Найближчі заняття</div>
                    <?php if (empty($upcomingLessons)): ?>
                        <div class="empty-reminders">
                            <div class="empty-reminders-icon">📭</div>
                            <div class="empty-reminders-text">Немає заплановано занять</div>
                        </div>
                    <?php else: ?>
                        <div class="reminders-list">
                            <?php foreach ($upcomingLessons as $lesson):
                                $lessonDt = new DateTime($lesson['scheduled_at']);
                                $today = new DateTime();
                                $tomorrow = (clone $today)->modify('+1 day');
                                
                                if ($lessonDt->format('Y-m-d') === $today->format('Y-m-d')) {
                                    $dateLabel = '🟢 Сьогодні';
                                } elseif ($lessonDt->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
                                    $dateLabel = '🔜 Завтра';
                                } else {
                                    $dateLabel = $lessonDt->format('d.m.Y');
                                }
                                $timeLabel = $lessonDt->format('H:i');
                            ?>
                            <div class="reminder-item">
                                <div class="reminder-title"><?= htmlspecialchars($lesson['title']) ?></div>
                                <div class="reminder-course">📚 <?= htmlspecialchars($lesson['course_title']) ?></div>
                                <div class="reminder-datetime">
                                    <span class="reminder-date"><?= $dateLabel ?></span>
                                    <span class="reminder-time">⏰ <?= $timeLabel ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Календар -->
                <div class="card" style="padding:16px;">
                    <div class="cal-nav">
                        <button class="cal-arrow" onclick="prevMonth()">‹</button>
                        <div class="cal-month" id="calLabel"></div>
                        <button class="cal-arrow" onclick="nextMonth()">›</button>
                    </div>
                    <div class="cal-dh-row">
                        <div class="cal-dh">Пн</div><div class="cal-dh">Вт</div><div class="cal-dh">Ср</div>
                        <div class="cal-dh">Чт</div><div class="cal-dh">Пт</div><div class="cal-dh">Сб</div><div class="cal-dh">Нд</div>
                    </div>
                    <div class="cal-days" id="calDays"></div>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- Форма покинути курс (прихована) -->
<form method="POST" id="leaveForm" style="display:none">
    <input type="hidden" name="leave_course_id" id="leaveCourseId">
</form>

<script>
function confirmLeave(courseId, courseName) {
    document.getElementById('leaveCourseId').value = courseId;
    document.getElementById('leaveModal').classList.add('open');
    document.getElementById('modalConfirmBtn').onclick = function() {
        document.getElementById('leaveForm').submit();
    };
}
function closeModal() {
    document.getElementById('leaveModal').classList.remove('open');
}
document.getElementById('leaveModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

const UA_M = ['Січень','Лютий','Березень','Квітень','Травень','Червень','Липень','Серпень','Вересень','Жовтень','Листопад','Грудень'];
let cy = new Date().getFullYear(), cm = new Date().getMonth();
function renderCal() {
    document.getElementById('calLabel').textContent = UA_M[cm] + ' ' + cy;
    const g = document.getElementById('calDays'); g.innerHTML = '';
    const first = new Date(cy,cm,1).getDay(), off = first===0?6:first-1;
    const total = new Date(cy,cm+1,0).getDate(), prev = new Date(cy,cm,0).getDate();
    const now = new Date(), itm = now.getFullYear()===cy && now.getMonth()===cm;
    for(let i=0;i<off;i++){const d=document.createElement('div');d.className='cal-day other';d.textContent=prev-off+1+i;g.appendChild(d);}
    for(let d=1;d<=total;d++){const el=document.createElement('div');el.textContent=d;el.className='cal-day'+(itm&&d===now.getDate()?' today':'');g.appendChild(el);}
}
function prevMonth(){cm--;if(cm<0){cm=11;cy--;}renderCal();}
function nextMonth(){cm++;if(cm>11){cm=0;cy++;}renderCal();}
renderCal();
</script>
<script src="theme-switcher.js"></script>
</body>
</html>