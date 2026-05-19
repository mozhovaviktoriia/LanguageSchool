<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacherId = $_SESSION['user_id'];

// Fetch teacher info and avatar
$stmtMe = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtMe->execute(['id' => $teacherId]);
$me = $stmtMe->fetch(PDO::FETCH_ASSOC);
$teacherName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Викладач';
$myAvatar    = $me['avatar_url'] ?? '';
$myInitials  = strtoupper((substr($me['first_name'] ?? '', 0, 1) ?: '') . (substr($me['last_name'] ?? '', 0, 1) ?: '')) ?: 'T';

// Get search and filter parameters
$search   = trim($_GET['search']  ?? '');
$filterSt  = trim($_GET['status'] ?? '');



// Query: students enrolled in teacher's courses
$sql = "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.avatar_url,
        u.status,
        u.last_activity,
        c.id   AS group_id,
        c.title AS group_name,
        c.title AS course_title
    FROM enrollments e
    JOIN courses c   ON e.course_id  = c.id
    JOIN users u    ON e.student_id = u.id
    WHERE c.teacher_id = :tid AND e.status = 'active'
";

$params = ['tid' => $teacherId];

// Apply status and search filters
if ($filterSt !== '') {
    $sql .= " AND u.status = :st";
    $params['st'] = $filterSt;
}

if ($search !== '') {
    $sql .= " AND (u.first_name ILIKE :s OR u.last_name ILIKE :s OR u.email ILIKE :s)";
    $params['s'] = '%' . $search . '%';
}

$sql .= " ORDER BY u.last_name, u.first_name";

$stmtStudents = $pdo->prepare($sql);
$stmtStudents->execute($params);
$students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

$totalStudents = count($students);

// Fetch student statistics
$stmtStat = $pdo->prepare("
    SELECT
        COUNT(DISTINCT e.student_id) AS total,
        COUNT(DISTINCT CASE WHEN u.status = 'active' THEN e.student_id END) AS active,
        COUNT(DISTINCT c.id) AS groups
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    JOIN users  u ON e.student_id = u.id
    WHERE c.teacher_id = :tid AND e.status = 'active'
");
$stmtStat->execute(['tid' => $teacherId]);
$stat = $stmtStat->fetch(PDO::FETCH_ASSOC);

// Update last activity timestamp
$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = :id")
    ->execute(['id' => $teacherId]);

// Helper: format time ago (e.g. '2 hours ago')
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return 'щойно';
    if ($diff < 3600)      return (int)($diff/60) . ' хв тому';
    if ($diff < 86400)     return (int)($diff/3600) . ' год тому';
    if ($diff < 2592000)   return (int)($diff/86400) . ' дн тому';
    return (new DateTime($dt))->format('d.m.Y');
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мої учні — EduSpace</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:      #07080f;
    --surface: #0d1117;
    --card:    #111827;
    --border:  #1e293b;
    --accent:  #6366f1;
    --teal:    #22d3ee;
    --green:   #22c55e;
    --amber:   #f59e0b;
    --red:     #ef4444;
    --text:    #e2e8f0;
    --muted:   #64748b;
    --radius:  14px;
    --font:    'Syne', sans-serif;
    --mono:    'JetBrains Mono', monospace;
    --sidebar: 220px;
}

body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 8% 10%, rgba(99,102,241,.12) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 90% 85%, rgba(34,211,238,.09) 0%, transparent 55%);
    pointer-events: none; z-index: 0;
}

/* ══ SIDEBAR ══ */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    padding: 28px 18px; z-index: 100; gap: 6px;
}
.logo { display: flex; align-items: center; gap: 10px; padding: 0 6px 24px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
.logo-icon { width: 36px; height: 36px; background: linear-gradient(135deg, var(--accent), var(--teal)); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
.logo-text { font-size: 16px; font-weight: 800; background: linear-gradient(90deg, #a5b4fc, var(--teal)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.nav-label { font-family: var(--mono); font-size: 9px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; padding: 0 8px; margin: 10px 0 4px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: var(--muted); font-size: 13px; font-weight: 600; transition: .2s; border: 1px solid transparent; }
.nav-item:hover, .nav-item.active { background: rgba(99,102,241,.12); color: var(--text); border-color: rgba(99,102,241,.25); }
.nav-item.active { color: #a5b4fc; }
.nav-icon { font-size: 15px; width: 20px; text-align: center; }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.logout-side { display: block; padding: 9px 12px; background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2); border-radius: 10px; color: #fca5a5; font-family: var(--mono); font-size: 11px; font-weight: 600; text-decoration: none; text-align: center; transition: .2s; }
.logout-side:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }

/* ══ PAGE ══ */
.page { margin-left: var(--sidebar); flex: 1; position: relative; z-index: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ── TOPBAR ── */
.topbar { display: flex; align-items: center; gap: 12px; padding: 18px 32px; border-bottom: 1px solid var(--border); background: rgba(13,17,23,.88); backdrop-filter: blur(20px); position: sticky; top: 0; z-index: 50; }
.topbar-back { display: flex; align-items: center; gap: 8px; text-decoration: none; color: var(--muted); font-family: var(--mono); font-size: 12px; padding: 8px 14px; border: 1px solid var(--border); border-radius: 9px; transition: .2s; white-space: nowrap; }
.topbar-back:hover { color: var(--text); border-color: var(--accent); }
.topbar-title { font-size: 16px; font-weight: 800; background: linear-gradient(90deg, var(--text), #a5b4fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; white-space: nowrap; }
.topbar-date { font-family: var(--mono); font-size: 11px; color: var(--muted); margin-left: auto; white-space: nowrap; }

/* ── CONTENT ── */
.content { padding: 28px 32px; flex: 1; }

/* ── STATS ROW ── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    transition: .2s;
    animation: fadeUp .4s ease both;
    position: relative; overflow: hidden;
}

.stat-card::after {
    content: ''; position: absolute;
    bottom: 0; left: 0; right: 0; height: 2px;
    opacity: 0; transition: opacity .25s;
}

.stat-card.c-purple::after { background: linear-gradient(90deg,var(--accent),#818cf8); }
.stat-card.c-teal::after   { background: linear-gradient(90deg,var(--teal),#67e8f9); }
.stat-card.c-green::after  { background: linear-gradient(90deg,var(--green),#86efac); }

.stat-card:hover::after { opacity: 1; }

.stat-icon-box {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}

.c-purple .stat-icon-box { background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.25); }
.c-teal   .stat-icon-box { background: rgba(34,211,238,.12);  border: 1px solid rgba(34,211,238,.22); }
.c-green  .stat-icon-box { background: rgba(34,197,94,.10);   border: 1px solid rgba(34,197,94,.20); }

.stat-num  { font-size: 28px; font-weight: 800; line-height: 1; }
.c-purple .stat-num { color: #a5b4fc; }
.c-teal   .stat-num { color: var(--teal); }
.c-green  .stat-num { color: var(--green); }
.stat-label { font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 3px; }

/* ── FILTERS ── */
.filters-bar {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.search-wrap {
    display: flex; align-items: center; gap: 10px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 9px 14px;
    flex: 1; min-width: 200px; max-width: 340px;
}

.search-wrap input { background: none; border: none; outline: none; color: var(--text); font-family: var(--font); font-size: 13px; width: 100%; }
.search-wrap input::placeholder { color: var(--muted); }
.search-icon { color: var(--muted); font-size: 14px; }

.filter-select {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 9px 14px;
    color: var(--text);
    font-family: var(--mono); font-size: 11px;
    outline: none; cursor: pointer;
    transition: border-color .2s;
}

.filter-select:focus { border-color: var(--accent); }

.filter-select option { background: var(--card); }

.btn-filter {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px;
    background: linear-gradient(135deg, var(--accent), #818cf8);
    color: white;
    border: none; border-radius: 10px;
    font-family: var(--font); font-size: 12px; font-weight: 700;
    cursor: pointer; transition: .2s;
    box-shadow: 0 3px 12px rgba(99,102,241,.3);
    text-decoration: none;
}

.btn-filter:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(99,102,241,.4); }

.btn-reset {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 14px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--muted);
    font-family: var(--mono); font-size: 11px;
    cursor: pointer; transition: .2s;
    text-decoration: none;
}

.btn-reset:hover { color: var(--text); border-color: var(--accent); }

/* ── RESULTS LINE ── */
.results-line {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 16px;
}

.results-count {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
}

.results-count strong { color: var(--text); }

.sec-line { flex: 1; height: 1px; background: var(--border); }

/* ── VIEW TOGGLE ── */
.view-toggle { display: flex; gap: 4px; }

.view-btn {
    width: 32px; height: 32px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--card);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--muted); font-size: 14px;
    transition: .15s;
}

.view-btn.active, .view-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(99,102,241,.1); }

/* ══════════ GRID VIEW ══════════ */
.students-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
}

.student-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    display: flex; flex-direction: column; gap: 12px;
    transition: .2s;
    animation: fadeUp .35s ease both;
    position: relative;
    overflow: hidden;
}

.student-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--teal));
    opacity: 0; transition: opacity .2s;
}

.student-card:hover { border-color: rgba(99,102,241,.4); transform: translateY(-3px); }
.student-card:hover::before { opacity: 1; }

.card-top { display: flex; align-items: center; gap: 12px; }

.s-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 800; color: white;
    flex-shrink: 0; overflow: hidden; position: relative;
}

.s-avatar img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; border-radius: 50%; }

.s-info { flex: 1; min-width: 0; }
.s-name { font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.s-email { font-family: var(--mono); font-size: 10px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }

.s-status {
    display: inline-flex; align-items: center; gap: 5px;
    font-family: var(--mono); font-size: 9px; font-weight: 600;
    padding: 3px 8px; border-radius: 99px;
    text-transform: uppercase; letter-spacing: .5px;
}

.s-status.active  { background: rgba(34,197,94,.12);  color: var(--green);  border: 1px solid rgba(34,197,94,.25); }
.s-status.inactive{ background: rgba(100,116,139,.1); color: var(--muted);  border: 1px solid rgba(100,116,139,.2); }
.s-status.blocked { background: rgba(239,68,68,.1);   color: var(--red);    border: 1px solid rgba(239,68,68,.2); }

.s-status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

.s-group {
    display: flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 10px; color: var(--muted);
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 10px;
}

.s-group-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--teal); flex-shrink: 0; }

.s-meta { display: flex; justify-content: space-between; align-items: center; }

.s-activity { font-family: var(--mono); font-size: 9px; color: var(--muted); }

.s-actions { display: flex; gap: 6px; }

.s-btn {
    width: 28px; height: 28px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: rgba(255,255,255,.04);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; cursor: pointer; color: var(--muted);
    text-decoration: none;
    transition: .15s;
}

.s-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(99,102,241,.1); }

/* ── Phone button ── */
.s-phone {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; border-radius: 8px;
    background: rgba(34,211,238,.08);
    border: 1px solid rgba(34,211,238,.2);
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    color: var(--teal);
    cursor: pointer; transition: .15s;
    white-space: nowrap;
}

.s-phone:hover { background: rgba(34,211,238,.15); border-color: var(--teal); }
.s-phone-icon { font-size: 13px; }

/* ══════════ TABLE VIEW ══════════ */
.students-table {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}

.t-head {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 80px;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.03);
}

.t-head span { font-family: var(--mono); font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }

.t-row {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr 80px;
    padding: 13px 20px;
    border-bottom: 1px solid rgba(30,41,59,.5);
    align-items: center;
    transition: background .15s;
    animation: fadeUp .35s ease both;
}

.t-row:last-child { border-bottom: none; }
.t-row:hover { background: rgba(255,255,255,.03); }

.t-student { display: flex; align-items: center; gap: 10px; }

.t-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: white;
    flex-shrink: 0; overflow: hidden; position: relative;
}

.t-avatar img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; border-radius: 50%; }

.t-name { font-size: 13px; font-weight: 600; }
.t-email-cell { font-family: var(--mono); font-size: 11px; color: var(--muted); }
.t-group-cell { font-family: var(--mono); font-size: 11px; color: var(--text); }
.t-course-cell { font-family: var(--mono); font-size: 10px; color: var(--muted); }
.t-activity-cell { font-family: var(--mono); font-size: 10px; color: var(--muted); }
.t-actions { display: flex; gap: 5px; }

/* ── EMPTY ── */
.empty-state {
    background: var(--card);
    border: 1px dashed var(--border);
    border-radius: var(--radius);
    padding: 60px 36px;
    text-align: center;
}

.empty-icon { font-size: 48px; margin-bottom: 14px; opacity: .5; }
.empty-title { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
.empty-sub { font-family: var(--mono); font-size: 11px; color: var(--muted); }

/* ── HIDDEN ── */
.view-grid .students-table { display: none; }
.view-table .students-grid { display: none; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
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
    <a class="nav-item" href="dashboard_teacher.php"><span class="nav-icon">📚</span> Мої курси</a>
    <a class="nav-item active" href="students.php"><span class="nav-icon">👨‍🎓</span> Мої учні</a>
    <a class="nav-item" href="schedule_teacher.php"><span class="nav-icon">📅</span> Розклад</a>
    <a class="nav-item" href="tasks.php"><span class="nav-icon">✓</span> Завдання</a>
    <a class="nav-item" href="tests.php"><span class="nav-icon">📝</span> Тести</a>
    <a class="nav-item" href="chat.php"><span class="nav-icon">💬</span> Чат</a>
    <a class="nav-item" href="https://meet.google.com" target="_blank"><span class="nav-icon">📞</span> Meet</a>

    <div class="sidebar-bottom">        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px">
            <span class="theme-toggle-icon">☀️</span>
        </button>        <a class="logout-side" href="logout.php">🚪 Вийти</a>
    </div>
</aside>

<!-- ════ PAGE ════ -->
<div class="page">

    <!-- TOPBAR -->
    <div class="topbar">
        <a class="topbar-back" href="teacher.php">← Назад</a>
        <div class="topbar-title">Мої учні</div>
        <div class="topbar-date" id="dateLabel"></div>
    </div>

    <div class="content">

        <!-- STATS -->
        <div class="stats-row">
            <div class="stat-card c-purple" style="animation-delay:.05s">
                <div class="stat-icon-box">👨‍🎓</div>
                <div>
                    <div class="stat-num"><?= (int)$stat['total'] ?></div>
                    <div class="stat-label">Всього учнів</div>
                </div>
            </div>
            <div class="stat-card c-green" style="animation-delay:.10s">
                <div class="stat-icon-box">✅</div>
                <div>
                    <div class="stat-num"><?= (int)$stat['active'] ?></div>
                    <div class="stat-label">Активних</div>
                </div>
            </div>
            <div class="stat-card c-teal" style="animation-delay:.15s">
                <div class="stat-icon-box">👥</div>
                <div>
                    <div class="stat-num"><?= (int)$stat['groups'] ?></div>
                    <div class="stat-label">Груп</div>
                </div>
            </div>
        </div>

        <!-- FILTERS -->
        <form method="GET" action="students.php">
            <div class="filters-bar">
                <div class="search-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" name="search" placeholder="Пошук за ім'ям або email..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <select name="status" class="filter-select">
                    <option value="">Будь-який статус</option>
                    <option value="active"   <?= $filterSt === 'active'   ? 'selected' : '' ?>>Активний</option>
                    <option value="inactive" <?= $filterSt === 'inactive' ? 'selected' : '' ?>>Неактивний</option>
                    <option value="blocked"  <?= $filterSt === 'blocked'  ? 'selected' : '' ?>>Заблокований</option>
                </select>

                <button type="submit" class="btn-filter">🔎 Застосувати</button>
                <a href="students.php" class="btn-reset">✕ Скинути</a>
            </div>
        </form>

        <!-- RESULTS LINE -->
        <div class="results-line">
            <div class="results-count">Знайдено: <strong><?= $totalStudents ?></strong> учнів</div>
            <div class="sec-line"></div>
            <div class="view-toggle">
                <button class="view-btn active" id="btnGrid" onclick="setView('grid')" title="Картки">⊞</button>
                <button class="view-btn" id="btnTable" onclick="setView('table')" title="Таблиця">☰</button>
            </div>
        </div>

        <!-- VIEWS WRAPPER -->
        <div id="viewWrap" class="view-grid">

            <?php if ($students): ?>

            <!-- ── GRID ── -->
            <div class="students-grid">
                <?php foreach ($students as $i => $s):
                    $fn  = $s['first_name'] ?? '';
                    $ln  = $s['last_name']  ?? '';
                    $ini = strtoupper((substr($fn,0,1)?:'').(substr($ln,0,1)?:'')) ?: '?';
                    $fullN = trim("$fn $ln") ?: $s['email'];
                    $status = $s['status'] ?? 'inactive';
                    $statusLabel = ['active'=>'Активний','inactive'=>'Неактивний','blocked'=>'Заблокований'][$status] ?? $status;
                    $ago = $s['last_activity'] ? timeAgo($s['last_activity']) : 'невідомо';
                    $delay = min($i * 0.04, 0.4);
                ?>
                <div class="student-card" style="animation-delay:<?= $delay ?>s">
                    <div class="card-top">
                        <div class="s-avatar">
                            <?php if (!empty($s['avatar_url']) && file_exists($s['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($s['avatar_url']) ?>" alt="">
                            <?php else: ?>
                            <?= htmlspecialchars($ini) ?>
                            <?php endif; ?>
                        </div>
                        <div class="s-info">
                            <div class="s-name"><?= htmlspecialchars($fullN) ?></div>
                            <div class="s-email"><?= htmlspecialchars($s['email']) ?></div>
                        </div>
                    </div>

                    <span class="s-status <?= htmlspecialchars($status) ?>">
                        <?= htmlspecialchars($statusLabel) ?>
                    </span>

                    <div class="s-group">
                        <div class="s-group-dot"></div>
                        <span><?= htmlspecialchars($s['course_title']) ?></span>
                    </div>

                    <div class="s-meta">
                        <div class="s-activity">🕐 <?= $ago ?></div>
                        <div class="s-actions">
                            <a class="s-btn" href="chat.php?open_user=<?= $s['id'] ?>" title="Написати">💬</a>
                            <?php if (!empty($s['phone'])): ?>
                            <button class="s-phone" onclick="copyPhone('<?= htmlspecialchars($s['phone']) ?>')" title="Копіювати номер">
                                <span class="s-phone-icon">☎️</span>
                                <span><?= htmlspecialchars($s['phone']) ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ── TABLE ── -->
            <div class="students-table">
                <div class="t-head">
                    <span>Учень</span>
                    <span>Email</span>
                    <span>Курс</span>
                    <span>Активність</span>
                    <span>Дії</span>
                </div>
                <?php foreach ($students as $i => $s):
                    $fn  = $s['first_name'] ?? '';
                    $ln  = $s['last_name']  ?? '';
                    $ini = strtoupper((substr($fn,0,1)?:'').(substr($ln,0,1)?:'')) ?: '?';
                    $fullN = trim("$fn $ln") ?: $s['email'];
                    $status = $s['status'] ?? 'inactive';
                    $ago = $s['last_activity'] ? timeAgo($s['last_activity']) : '—';
                    $delay = min($i * 0.04, 0.4);
                ?>
                <div class="t-row" style="animation-delay:<?= $delay ?>s">
                    <div class="t-student">
                        <div class="t-avatar">
                            <?php if (!empty($s['avatar_url']) && file_exists($s['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($s['avatar_url']) ?>" alt="">
                            <?php else: ?>
                            <?= htmlspecialchars($ini) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="t-name"><?= htmlspecialchars($fullN) ?></div>
                            <span class="s-status <?= htmlspecialchars($status) ?>" style="font-size:8px;padding:2px 6px">
                                <?= ['active'=>'Активний','inactive'=>'Неактивний','blocked'=>'Заблокований'][$status] ?? $status ?>
                            </span>
                        </div>
                    </div>
                    <div class="t-email-cell"><?= htmlspecialchars($s['email']) ?></div>
                    <div class="t-course-cell"><?= htmlspecialchars($s['course_title']) ?></div>
                    <div class="t-activity-cell"><?= $ago ?></div>
                    <div class="t-actions">
                        <a class="s-btn" href="chat.php?open_user=<?= $s['id'] ?>" title="Написати">💬</a>
                        <?php if (!empty($s['phone'])): ?>
                        <button class="s-phone" onclick="copyPhone('<?= htmlspecialchars($s['phone']) ?>')" title="Копіювати номер" style="padding:5px 8px;font-size:10px">
                            <span class="s-phone-icon">☎️</span>
                            <span><?= htmlspecialchars($s['phone']) ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">👨‍🎓</div>
                <div class="empty-title">Учнів не знайдено</div>
                <div class="empty-sub">
                    <?= ($search || $filterSt) ? 'Спробуйте змінити фільтри пошуку' : 'У ваших групах поки немає учнів' ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /viewWrap -->

    </div><!-- /content -->
</div><!-- /page -->

<script>
/* Date */
(function(){
    const opt = { day:'numeric', month:'long', year:'numeric', weekday:'long' };
    document.getElementById('dateLabel').textContent = new Date().toLocaleDateString('uk-UA', opt);
})();

/* Copy phone to clipboard */
function copyPhone(phone) {
    navigator.clipboard.writeText(phone).then(() => {
        // Show brief feedback
        const btn = event.target.closest('.s-phone');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="s-phone-icon">✓</span><span>Скопійовано!</span>';
        setTimeout(() => {
            btn.innerHTML = originalText;
        }, 2000);
    }).catch(() => {
        alert('Помилка при копіюванні');
    });
}

/* Live search (filter on type) */
document.querySelector('.search-wrap input').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.student-card').forEach(card => {
        const name  = card.querySelector('.s-name')?.textContent.toLowerCase()  || '';
        const email = card.querySelector('.s-email')?.textContent.toLowerCase() || '';
        card.style.display = (name.includes(q) || email.includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('.t-row').forEach(row => {
        const name  = row.querySelector('.t-name')?.textContent.toLowerCase()       || '';
        const email = row.querySelector('.t-email-cell')?.textContent.toLowerCase() || '';
        row.style.display = (name.includes(q) || email.includes(q)) ? '' : 'none';
    });
});

/* View toggle */
function setView(v) {
    const wrap = document.getElementById('viewWrap');
    wrap.className = v === 'grid' ? 'view-grid' : 'view-table';
    document.getElementById('btnGrid').classList.toggle('active', v === 'grid');
    document.getElementById('btnTable').classList.toggle('active', v === 'table');
    localStorage.setItem('studentsView', v);
}

/* Restore preferred view */
(function(){
    const saved = localStorage.getItem('studentsView');
    if (saved === 'table') setView('table');
})();
</script>
<script src="theme-switcher.js"></script>
</body>
</html>