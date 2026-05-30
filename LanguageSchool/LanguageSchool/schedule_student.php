<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php"); exit;
}

$studentId = $_SESSION['user_id'];

$stmtUser = $pdo->prepare("SELECT first_name, last_name, email, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $studentId]);
$me = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Студент';
$initials = strtoupper(substr($me['first_name'] ?? '', 0, 1) . substr($me['last_name'] ?? '', 0, 1)) ?: 'СТ';

$view        = in_array($_GET['view'] ?? '', ['week','month']) ? $_GET['view'] : 'week';
$weekOffset  = (int)($_GET['week']  ?? 0);
$monthOffset = (int)($_GET['month'] ?? 0);

$today    = new DateTime();
$todayStr = $today->format('Y-m-d');

$dow    = (int)$today->format('N');
$monday = (clone $today)->modify('-' . ($dow - 1) . ' days')->modify($weekOffset . ' weeks');
$sunday = (clone $monday)->modify('+6 days');
$weekStart = $monday->format('Y-m-d');
$weekEnd   = $sunday->format('Y-m-d');

$monthDt       = (clone $today)->modify($monthOffset . ' months');
$monthYear     = (int)$monthDt->format('Y');
$monthNum      = (int)$monthDt->format('m');
$monthStart    = new DateTime("$monthYear-$monthNum-01");
$monthEnd      = (clone $monthStart)->modify('last day of this month');
$monthStartStr = $monthStart->format('Y-m-d');
$monthEndStr   = $monthEnd->format('Y-m-d');

$rangeStart = $view === 'week' ? $weekStart : $monthStartStr;
$rangeEnd   = $view === 'week' ? $weekEnd   : $monthEndStr;

/* ── Lessons ── */
$stmtLessons = $pdo->prepare("
    SELECT
        l.id, l.title, l.scheduled_at, l.lesson_type, l.meeting_url,
        c.title      AS course_title,
        c.id         AS course_id,
        lang.code    AS lang_code,
        lang.name_ua AS lang_name,
        u.first_name AS teacher_first,
        u.last_name  AS teacher_last,
        c.title      AS group_name
    FROM enrollments e
    JOIN courses        c    ON c.id         = e.course_id
    JOIN languages      lang ON lang.id      = c.language_id
    JOIN lessons        l    ON l.teacher_id = c.teacher_id
    JOIN users          u    ON u.id         = l.teacher_id
    WHERE e.student_id = :sid
      AND e.status = 'active'
      AND DATE(l.scheduled_at AT TIME ZONE 'UTC') BETWEEN :ws AND :we
    ORDER BY l.scheduled_at ASC
");
$stmtLessons->execute([':sid' => $studentId, ':ws' => $rangeStart, ':we' => $rangeEnd]);
$lessons = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);

/* ── Tasks (homework deadlines) ── */
$stmtTasks = $pdo->prepare("
    SELECT
        t.id, t.title, t.deadline, t.max_score,
        COALESCE(c.title, c2.title) AS course_title,
        COALESCE(l.course_id, t.course_id) AS course_id,
        ts.status AS sub_status
    FROM tasks t
    LEFT JOIN lessons l   ON t.lesson_id = l.id
    LEFT JOIN courses c   ON c.id = l.course_id
    LEFT JOIN courses c2  ON c2.id = t.course_id
    LEFT JOIN task_submissions ts ON ts.task_id = t.id AND ts.student_id = :sid
    WHERE (t.assigned_to = :sid2 OR t.assigned_to IS NULL)
      AND t.deadline IS NOT NULL
      AND DATE(t.deadline) BETWEEN :ws AND :we
      AND COALESCE(l.course_id, t.course_id) IN (
          SELECT course_id FROM enrollments WHERE student_id = :sid3 AND status = 'active'
      )
    ORDER BY t.deadline ASC
");
$stmtTasks->execute([':sid' => $studentId, ':sid2' => $studentId, ':sid3' => $studentId, ':ws' => $rangeStart, ':we' => $rangeEnd]);
$taskEvents = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

/* ── Color map ── */
$palette  = ['#6366f1','#22d3ee','#22c55e','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#f97316'];
$colorMap = [];
foreach ($lessons as $l) {
    if (!isset($colorMap[$l['course_id']]))
        $colorMap[$l['course_id']] = $palette[count($colorMap) % count($palette)];
}

/* ── Group by day ── */
$byDay    = [];
$byDayIdx = array_fill(0, 7, []);

foreach ($lessons as $l) {
    $l['_type'] = 'lesson';
    $dt  = new DateTime($l['scheduled_at']);
    $key = $dt->format('Y-m-d');
    $byDay[$key][] = $l;
    if ($view === 'week') {
        $idx = (int)$dt->format('N') - 1;
        if ($idx >= 0 && $idx < 7) $byDayIdx[$idx][] = $l;
    }
}

foreach ($taskEvents as $t) {
    $t['_type'] = 'task';
    $dt  = new DateTime($t['deadline']);
    $key = $dt->format('Y-m-d');
    $byDay[$key][] = $t;
    if ($view === 'week') {
        $idx = (int)$dt->format('N') - 1;
        if ($idx >= 0 && $idx < 7) $byDayIdx[$idx][] = $t;
    }
}

/* ── Stats ── */
$totalLessons  = count($lessons);
$completedLess = 0;
$upcomingLess  = count($lessons);
$totalMin      = count($lessons) * 60;
$pendingTasks  = count(array_filter($taskEvents, fn($t) => !in_array($t['sub_status'], ['submitted','reviewed'])));

/* ── Hour range ── */
$minHour = 8; $maxHour = 21; $pxPerHour = 64;
if ($view === 'week' && $lessons) {
    $sh = array_map(fn($l) => (int)(new DateTime($l['scheduled_at']))->format('H'), $lessons);
    $eh = array_map(fn($l) => (int)ceil(
        ((new DateTime($l['scheduled_at']))->format('H') * 60 +
         (new DateTime($l['scheduled_at']))->format('i') + 60) / 60), $lessons);
    $minHour = max(7, min($sh) - 1);
    $maxHour = min(23, max($eh) + 1);
}
$totalPx = ($maxHour - $minHour) * $pxPerHour;

$allCoursesCount = $pdo->query("SELECT COUNT(*) FROM courses WHERE is_active = TRUE")->fetchColumn();

$UA_MONTHS = ['','Січень','Лютий','Березень','Квітень','Травень','Червень','Липень','Серпень','Вересень','Жовтень','Листопад','Грудень'];
$UA_DAYS   = ['Пн','Вт','Ср','Чт','Пт','Сб','Нд'];

function lessonJson(array $l, array $colorMap): string {
    $dt  = new DateTime($l['scheduled_at']);
    $dur = 60;
    $end = (clone $dt)->modify("+$dur minutes");
    return json_encode([
        '_type'   => 'lesson',
        'title'   => $l['title'],
        'course'  => $l['course_title'],
        'teacher' => trim(($l['teacher_first'] ?? '') . ' ' . ($l['teacher_last'] ?? '')),
        'group'   => $l['group_name'],
        'start'   => $dt->format('H:i'),
        'end'     => $end->format('H:i'),
        'date'    => $dt->format('d.m.Y'),
        'dur'     => $dur,
        'status'  => 'scheduled',
        'url'     => $l['meeting_url'] ?? '',
        'color'   => $colorMap[$l['course_id']] ?? '#6366f1',
        'lang'    => $l['lang_name'],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function taskJson(array $t): string {
    $dt = new DateTime($t['deadline']);
    $statusMap = [
        'submitted' => 'submitted',
        'reviewed'  => 'reviewed',
        'assigned'  => 'returned',
    ];
    $status = $statusMap[$t['sub_status'] ?? ''] ?? 'pending';
    return json_encode([
        '_type'      => 'task',
        'id'         => $t['id'],
        'title'      => $t['title'],
        'course'     => $t['course_title'] ?? '',
        'date'       => $dt->format('d.m.Y'),
        'time'       => $dt->format('H:i'),
        'score'      => $t['max_score'],
        'status'     => $status,
        'sub_status' => $t['sub_status'],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Розклад — LinguaHub</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

html, body { height:100%; }
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    display: flex;
    overflow: hidden;
    transition: background .25s, color .25s;
}

body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(ellipse 80% 50% at 5% 0%,  var(--glow-1) 0%, transparent 55%),
        radial-gradient(ellipse 55% 40% at 95% 90%, var(--glow-2) 0%, transparent 55%);
}

/* ══ SIDEBAR ══ */
.sidebar {
    position:fixed; top:0; left:0; bottom:0; width:var(--sidebar);
    background: var(--sidebar-bg);
    border-right:1px solid var(--border);
    display:flex; flex-direction:column; z-index:20;
    transition: background .25s, border-color .25s;
}
.sidebar-logo { padding:22px 20px 18px; border-bottom:1px solid var(--border); }
.logo-text { font-size:20px; font-weight:800; letter-spacing:-.5px; color: var(--text); }
.logo-text span { color:var(--teal); }
.sidebar-profile {
    display:flex; align-items:center; gap:11px;
    padding:14px 20px; border-bottom:1px solid var(--border);
}
.s-avatar {
    width:38px; height:38px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--teal));
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:13px; color:#fff;
    flex-shrink:0; border:2px solid rgba(99,102,241,.4); overflow:hidden;
}
.s-avatar img { width:100%; height:100%; object-fit:cover; }
.profile-name { font-size:13px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
.profile-role { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-top:2px; }

.sidebar-nav { flex:1; padding:12px 10px; display:flex; flex-direction:column; gap:2px; overflow-y:auto; }
.nav-label { font-family:var(--mono); font-size:9px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; padding:10px 10px 4px; margin-top:4px; }
.nav-item {
    display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:10px;
    text-decoration:none; color:var(--muted); font-size:13px; font-weight:600;
    transition:.18s; border:1px solid transparent;
}
.nav-item svg { width:15px; height:15px; flex-shrink:0; }
.nav-item:hover { color:var(--text); background:var(--hover-nav); }
.nav-item.active { color:var(--accent); background:rgba(99,102,241,.13); border-color:rgba(99,102,241,.3); }
.nav-badge { margin-left:auto; font-family:var(--mono); font-size:9px; font-weight:700; padding:2px 7px; border-radius:99px; background:rgba(99,102,241,.2); color:var(--accent); }
.nav-badge.green { background:rgba(34,197,94,.15); color:var(--green); }
.nav-badge.amber { background:rgba(245,158,11,.15); color:var(--amber); }

.sidebar-footer { padding:12px 10px; border-top:1px solid var(--border); }
.logout-btn {
    display:flex; align-items:center; gap:10px; width:100%; padding:10px 12px; border-radius:10px;
    background:rgba(239,68,68,.07); border:1px solid rgba(239,68,68,.2); color:var(--red);
    font-family:var(--font); font-size:13px; font-weight:600; cursor:pointer; transition:.18s; text-decoration:none;
}
.logout-btn:hover { background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.4); }
.logout-btn svg { width:14px; height:14px; }

/* ══ MAIN ══ */
.main { margin-left:var(--sidebar); flex:1; display:flex; flex-direction:column; height:100vh; overflow:hidden; position:relative; z-index:1; }

/* ══ TOPBAR ══ */
.topbar {
    flex-shrink:0; display:flex; align-items:center; justify-content:space-between;
    padding:11px 22px; border-bottom:1px solid var(--border);
    background: var(--topbar-bg); backdrop-filter:blur(20px); gap:12px;
    transition: background .25s, border-color .25s;
}
.topbar-left  { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.topbar-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.page-title   { font-size:16px; font-weight:800; letter-spacing:-.3px; white-space:nowrap; color:var(--text); }
.page-title span { color:var(--teal); }

.nav-group  { display:flex; align-items:center; gap:4px; }
.nav-arrow  {
    width:28px; height:28px; border-radius:7px; border:1px solid var(--border);
    background:var(--surface); color:var(--muted); cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:.18s; font-size:12px; text-decoration:none;
}
.nav-arrow:hover { color:var(--text); border-color:rgba(99,102,241,.4); background:rgba(99,102,241,.08); }
.range-label {
    font-family:var(--mono); font-size:10px; color:var(--text); white-space:nowrap;
    padding:0 8px; min-width:180px; text-align:center;
}
.today-btn {
    padding:5px 11px; border-radius:7px; border:1px solid rgba(99,102,241,.3);
    background:rgba(99,102,241,.10); color:var(--accent); font-family:var(--mono);
    font-size:10px; font-weight:600; cursor:pointer; transition:.18s; text-decoration:none; white-space:nowrap;
}
.today-btn:hover { background:rgba(99,102,241,.18); }

.view-toggle { display:flex; background:var(--surface); border:1px solid var(--border); border-radius:9px; overflow:hidden; }
.vt-btn {
    padding:6px 13px; font-family:var(--mono); font-size:10px; font-weight:600;
    color:var(--muted); border:none; background:none; cursor:pointer; transition:.18s;
    display:flex; align-items:center; gap:5px; text-decoration:none; white-space:nowrap;
}
.vt-btn:hover { color:var(--text); }
.vt-btn.active { background:rgba(99,102,241,.18); color:var(--accent); }

/* ══ STATS STRIP ══ */
.stats-strip { flex-shrink:0; display:flex; gap:1px; border-bottom:1px solid var(--border); background:var(--border); }
.stat-chip { flex:1; padding:8px 14px; background:var(--surface); display:flex; align-items:center; gap:8px; transition: background .25s; }
.stat-chip-icon { font-size:14px; }
.stat-chip-val  { font-size:15px; font-weight:800; }
.stat-chip-lbl  { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; margin-top:1px; }
.c-purple .stat-chip-val { color:var(--accent); }
.c-teal   .stat-chip-val { color:var(--teal); }
.c-green  .stat-chip-val { color:var(--green); }
.c-amber  .stat-chip-val { color:var(--amber); }

/* ══ WEEK VIEW ══ */
.cal-wrap  { flex:1; overflow:hidden; display:flex; flex-direction:column; }
.cal-header {
    flex-shrink:0; display:flex; border-bottom:1px solid var(--border);
    background: var(--panel-bg);
    transition: background .25s;
}
.time-gutter { width:50px; flex-shrink:0; border-right:1px solid var(--border); }
.cal-day-hd { flex:1; min-width:0; padding:8px 4px; text-align:center; border-right:1px solid var(--border); }
.cal-day-hd:last-child { border-right:none; }
.cal-day-name { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }
.cal-day-num  { font-size:17px; font-weight:800; margin-top:1px; line-height:1; color:var(--text); }
.cal-day-hd.is-today .cal-day-num  { color:var(--accent); }
.cal-day-hd.is-today .cal-day-name { color:var(--accent); }
.cal-day-hd.has-ev .cal-day-name::after { content:'·'; color:var(--teal); margin-left:2px; font-size:14px; vertical-align:middle; }

.cal-body { flex:1; overflow-y:auto; display:flex; }
.cal-body::-webkit-scrollbar { width:4px; }
.cal-body::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

.time-col  { width:50px; flex-shrink:0; position:relative; border-right:1px solid var(--border); }
.time-lbl  { position:absolute; right:5px; font-family:var(--mono); font-size:9px; color:var(--muted); transform:translateY(-50%); white-space:nowrap; }
.days-grid { flex:1; display:grid; grid-template-columns:repeat(7,1fr); position:relative; }
.day-col   { border-right:1px solid var(--border); position:relative; }
.day-col:last-child { border-right:none; }
.hr-line   { position:absolute; left:0; right:0; border-top:1px solid var(--border); pointer-events:none; }
.hf-line   { position:absolute; left:0; right:0; border-top:1px dashed var(--border); opacity:.5; pointer-events:none; }
.now-line  { position:absolute; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--red),rgba(239,68,68,.2)); z-index:5; pointer-events:none; }
.now-dot   { position:absolute; left:-4px; top:-4px; width:9px; height:9px; border-radius:50%; background:var(--red); box-shadow:0 0 8px var(--red); }

/* ── Lesson block ── */
.lb {
    position:absolute; left:2px; right:2px; border-radius:8px; padding:5px 7px; overflow:hidden;
    cursor:pointer; transition:transform .13s,box-shadow .13s; display:flex; flex-direction:column;
    z-index:2; border-left-width:3px; border-left-style:solid;
}
.lb:hover { transform:scale(1.03) translateY(-1px); z-index:10; box-shadow:0 6px 22px rgba(0,0,0,.3); }
.lb-lang  { font-family:var(--mono); font-size:8px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.lb-title { font-size:11px; font-weight:700; line-height:1.3; margin-top:1px; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lb-sub   { font-family:var(--mono); font-size:9px; opacity:.65; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lb-time  { font-family:var(--mono); font-size:8px; opacity:.7; margin-top:auto; padding-top:3px; }

/* ── Task deadline block ── */
.lb-task {
    position:absolute; left:2px; right:2px; border-radius:8px; padding:5px 7px;
    cursor:pointer; transition:transform .13s, box-shadow .13s;
    display:flex; flex-direction:column; z-index:3;
    border-left:3px solid #f59e0b;
    background: rgba(245,158,11,.13);
    border-top:1px solid rgba(245,158,11,.3);
    border-right:1px solid rgba(245,158,11,.15);
    border-bottom:1px solid rgba(245,158,11,.15);
}
.lb-task:hover { transform:scale(1.03) translateY(-1px); z-index:10; box-shadow:0 6px 22px rgba(0,0,0,.3); }
.lb-task.done {
    border-left-color:#22c55e;
    background:rgba(34,197,94,.09);
    border-top-color:rgba(34,197,94,.25);
    opacity:.7;
}
.lb-task.submitted {
    border-left-color:#22d3ee;
    background:rgba(34,211,238,.09);
    border-top-color:rgba(34,211,238,.25);
    opacity:.8;
}
.lb-task-badge { font-family:var(--mono); font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#f59e0b; margin-bottom:2px; }
.lb-task.done  .lb-task-badge { color:#22c55e; }
.lb-task.submitted .lb-task-badge { color:#22d3ee; }
.lb-task-title { font-size:11px; font-weight:700; line-height:1.3; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lb-task-time  { font-family:var(--mono); font-size:8px; color:#f59e0b; margin-top:auto; padding-top:2px; }
.lb-task.done  .lb-task-time { color:#22c55e; }

/* ══ MONTH VIEW ══ */
.month-wrap { flex:1; overflow-y:auto; padding:14px 18px 20px; }
.month-wrap::-webkit-scrollbar { width:4px; }
.month-wrap::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.month-grid-hd { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; margin-bottom:5px; }
.month-dh { text-align:center; font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; padding:3px 0; }
.month-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.month-cell {
    min-height:92px; background:var(--card); border:1px solid var(--border);
    border-radius:10px; padding:6px; display:flex; flex-direction:column; gap:3px;
    transition:border-color .18s, background .25s;
}
.month-cell:hover { border-color:rgba(99,102,241,.35); }
.month-cell.other-month { opacity:.28; pointer-events:none; }
.month-cell.is-today { border-color:rgba(99,102,241,.5); background:rgba(99,102,241,.06); }
.cell-num {
    font-family:var(--mono); font-size:10px; font-weight:700; color:var(--muted);
    align-self:flex-start; width:22px; height:22px; display:flex; align-items:center;
    justify-content:center; border-radius:6px; flex-shrink:0;
}
.month-cell.is-today .cell-num { background:var(--accent); color:#fff; }

/* lesson pill */
.month-ev {
    font-size:10px; font-weight:600; padding:2px 6px; border-radius:5px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer;
    transition:.12s; line-height:1.45; border-left-width:2px; border-left-style:solid;
}
.month-ev:hover { filter:brightness(1.2); transform:scale(1.02); }

/* task pill */
.month-task {
    font-size:10px; font-weight:600; padding:2px 6px; border-radius:5px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer;
    transition:.12s; line-height:1.45;
    border-left:2px solid #f59e0b;
    background:rgba(245,158,11,.13); color:#f59e0b;
    display:flex; align-items:center; gap:3px;
}
.month-task.done {
    border-left-color:#22c55e;
    background:rgba(34,197,94,.09);
    color:#22c55e; opacity:.75;
}
.month-task.submitted {
    border-left-color:#22d3ee;
    background:rgba(34,211,238,.09);
    color:#22d3ee; opacity:.85;
}
.month-task:hover { filter:brightness(1.18); transform:scale(1.02); }

.month-more { font-family:var(--mono); font-size:9px; color:var(--muted); padding:1px 4px; cursor:pointer; transition:.14s; }
.month-more:hover { color:var(--text); }

/* ══ POPUP ══ */
.popup {
    display:none; position:fixed; z-index:300; width:280px;
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:15px;
    box-shadow:0 20px 50px rgba(0,0,0,.35);
    animation:popIn .16s ease both;
    transition: background .25s, border-color .25s;
}
.popup.show { display:block; }
.pop-bar   { height:3px; border-radius:99px; margin-bottom:11px; }
.pop-head  { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:9px; }
.pop-title { font-size:13px; font-weight:800; line-height:1.35; flex:1; margin-right:8px; color:var(--text); }
.pop-x     { background:none; border:none; color:var(--muted); cursor:pointer; font-size:15px; line-height:1; padding:2px 4px; transition:.13s; flex-shrink:0; }
.pop-x:hover { color:var(--text); }
.pop-row   { display:flex; align-items:center; gap:6px; margin-bottom:5px; font-family:var(--mono); font-size:10px; color:var(--muted); }
.pop-row svg { width:11px; height:11px; flex-shrink:0; }
.pop-row span { color:var(--text); }
.pop-badge { display:inline-flex; padding:3px 9px; border-radius:99px; font-family:var(--mono); font-size:9px; font-weight:700; margin-top:5px; }
.pop-badge.scheduled  { background:rgba(99,102,241,.15);  color:var(--accent); border:1px solid rgba(99,102,241,.3); }
.pop-badge.completed  { background:rgba(34,197,94,.12);   color:var(--green);  border:1px solid rgba(34,197,94,.3); }
.pop-badge.cancelled  { background:rgba(239,68,68,.10);   color:var(--red);    border:1px solid rgba(239,68,68,.25); }
.pop-badge.hw-pending { background:rgba(245,158,11,.12);  color:var(--amber);  border:1px solid rgba(245,158,11,.3); }
.pop-badge.hw-sent    { background:rgba(34,211,238,.12);  color:var(--teal);   border:1px solid rgba(34,211,238,.3); }
.pop-badge.hw-done    { background:rgba(34,197,94,.12);   color:var(--green);  border:1px solid rgba(34,197,94,.3); }
.pop-badge.hw-return  { background:rgba(239,68,68,.10);   color:var(--red);    border:1px solid rgba(239,68,68,.25); }
.pop-actions { margin-top:10px; display:flex; gap:6px; }
.pop-btn {
    flex:1; padding:7px; border-radius:8px; font-family:var(--font); font-size:11px;
    font-weight:700; cursor:pointer; transition:.14s; text-align:center; text-decoration:none;
    display:flex; align-items:center; justify-content:center; gap:4px; border:none;
}
.pop-join      { background:linear-gradient(135deg,var(--accent),#818cf8); color:#fff; }
.pop-join:hover { opacity:.85; }
.pop-hw        { background:linear-gradient(135deg,#f59e0b,#fbbf24); color:#000; font-weight:800; }
.pop-hw:hover  { opacity:.88; }
.pop-close-btn { background:var(--input-bg); color:var(--muted); border:1px solid var(--border) !important; }
.pop-close-btn:hover { color:var(--text); border-color:rgba(99,102,241,.3) !important; }

/* ══ EMPTY ══ */
.empty-state {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    flex:1; gap:10px; padding:50px 24px; text-align:center; opacity:.5;
}
.empty-icon  { font-size:44px; }
.empty-title { font-size:14px; font-weight:800; color:var(--muted); }
.empty-sub   { font-family:var(--mono); font-size:10px; color:var(--muted); line-height:1.7; max-width:240px; }

/* ══ LIGHT THEME ══ */
body.light-theme .lb-title { color: #1e293b; }
body.light-theme .lb-task-title { color: #1e293b; }
body.light-theme .lb:hover { box-shadow: 0 6px 22px rgba(0,0,0,.12); }
body.light-theme .lb-task:hover { box-shadow: 0 6px 22px rgba(0,0,0,.12); }
body.light-theme .month-cell.is-today { background: rgba(79,70,229,.07); }

@keyframes popIn { from{opacity:0;transform:scale(.93) translateY(4px)} to{opacity:1;transform:none} }
</style>
</head>
<body>

<!-- POPUP -->
<div class="popup" id="popup">
    <div class="pop-bar" id="popBar"></div>
    <div class="pop-head">
        <div class="pop-title" id="popTitle"></div>
        <button class="pop-x" onclick="closePopup()">✕</button>
    </div>
    <div class="pop-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span id="popDate"></span></div>
    <div class="pop-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span id="popTime"></span></div>
    <div class="pop-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg><span id="popCourse"></span></div>
    <div class="pop-row" id="popRow4"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg><span id="popExtra"></span></div>
    <span class="pop-badge" id="popStatus"></span>
    <div class="pop-actions" id="popActions"></div>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo"><div class="logo-text">Lingua<span>Hub</span></div></div>
    <div class="sidebar-profile">
        <div class="s-avatar">
            <?php if (!empty($me['avatar_url']) && file_exists($me['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($me['avatar_url']) ?>" alt="">
            <?php else: ?><?= $initials ?><?php endif; ?>
        </div>
        <div>
            <div class="profile-name"><?= htmlspecialchars($studentName) ?></div>
            <div class="profile-role">Студент</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Навігація</div>
        <a class="nav-item" href="dashboard_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Дашборд
        </a>
        <a class="nav-item" href="courses_catalog.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>Всі курси
            <span class="nav-badge"><?= $allCoursesCount ?></span>
        </a>
        <a class="nav-item active" href="schedule_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Розклад
        </a>
        <a class="nav-item" href="grades_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>Оцінки
            <span class="nav-badge green">0</span>
        </a>
        <a class="nav-item" href="homework_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Домашні завдання
            <?php if ($pendingTasks > 0): ?>
            <span class="nav-badge amber"><?= $pendingTasks ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-item" href="chat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>Чат з викладачем
        </a>
        <a class="nav-item" href="https://meet.google.com" target="_blank">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>Відеоурок
        </a>
        <div class="nav-label">Акаунт</div>
        <a class="nav-item" href="profile_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>Налаштування
        </a>
    </nav>
    <div class="sidebar-footer">
        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px;display:flex;align-items:center;justify-content:center">
            <span class="theme-toggle-icon">☀️</span>
        </button>
        <a class="logout-btn" href="logout.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Вийти
        </a>
    </div>
</aside>

<!-- MAIN -->
<main class="main">
    <div class="topbar">
        <div class="topbar-left">
            <div class="page-title">📅 <span>РОЗКЛАД</span></div>
            <div class="nav-group">
                <?php if ($view === 'week'): ?>
                    <a class="nav-arrow" href="?view=week&week=<?= $weekOffset-1 ?>">‹</a>
                    <span class="range-label">
                        <?= $monday->format('d') ?>&nbsp;<?= strtolower($UA_MONTHS[(int)$monday->format('m')]) ?>
                        &nbsp;—&nbsp;
                        <?= $sunday->format('d') ?>&nbsp;<?= strtolower($UA_MONTHS[(int)$sunday->format('m')]) ?>
                        &nbsp;<?= $sunday->format('Y') ?>
                    </span>
                    <a class="nav-arrow" href="?view=week&week=<?= $weekOffset+1 ?>">›</a>
                    <?php if ($weekOffset !== 0): ?>
                        <a class="today-btn" href="?view=week&week=0">Сьогодні</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="nav-arrow" href="?view=month&month=<?= $monthOffset-1 ?>">‹</a>
                    <span class="range-label"><?= $UA_MONTHS[$monthNum] ?>&nbsp;<?= $monthYear ?></span>
                    <a class="nav-arrow" href="?view=month&month=<?= $monthOffset+1 ?>">›</a>
                    <?php if ($monthOffset !== 0): ?>
                        <a class="today-btn" href="?view=month&month=0">Сьогодні</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-right">
            <div class="view-toggle">
                <a class="vt-btn <?= $view==='week'?'active':'' ?>" href="?view=week&week=<?= $weekOffset ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/><line x1="15" y1="9" x2="15" y2="21"/></svg>
                    Тиждень
                </a>
                <a class="vt-btn <?= $view==='month'?'active':'' ?>" href="?view=month&month=<?= $monthOffset ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Місяць
                </a>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-strip">
        <div class="stat-chip c-purple">
            <span class="stat-chip-icon">📚</span>
            <div><div class="stat-chip-val"><?= $totalLessons ?></div>
            <div class="stat-chip-lbl"><?= $view==='week'?'Занять тижня':'Занять місяця' ?></div></div>
        </div>
        <div class="stat-chip c-green">
            <span class="stat-chip-icon">✅</span>
            <div><div class="stat-chip-val"><?= $completedLess ?></div><div class="stat-chip-lbl">Завершено</div></div>
        </div>
        <div class="stat-chip c-teal">
            <span class="stat-chip-icon">🔜</span>
            <div><div class="stat-chip-val"><?= $upcomingLess ?></div><div class="stat-chip-lbl">Заплановано</div></div>
        </div>
        <div class="stat-chip c-amber">
            <span class="stat-chip-icon">📋</span>
            <div><div class="stat-chip-val"><?= $pendingTasks ?></div>
            <div class="stat-chip-lbl">ДЗ до здачі</div></div>
        </div>
    </div>

    <?php if ($view === 'week'): ?>
    <!-- ═══════════════ WEEK VIEW ═══════════════ -->
    <div class="cal-wrap">
        <div class="cal-header">
            <div class="time-gutter"></div>
            <?php for ($d = 0; $d < 7; $d++):
                $dd = (clone $monday)->modify("+$d days");
                $isT = $dd->format('Y-m-d') === $todayStr;
            ?>
            <div class="cal-day-hd<?= $isT?' is-today':'' ?><?= !empty($byDayIdx[$d])?' has-ev':'' ?>">
                <div class="cal-day-name"><?= $UA_DAYS[$d] ?></div>
                <div class="cal-day-num"><?= $dd->format('j') ?></div>
            </div>
            <?php endfor; ?>
        </div>

        <div class="cal-body" id="calBody">
            <div class="time-col" style="height:<?= $totalPx ?>px">
                <?php for ($h = $minHour; $h <= $maxHour; $h++): ?>
                <div class="time-lbl" style="top:<?= ($h-$minHour)*$pxPerHour ?>px"><?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:00</div>
                <?php endfor; ?>
            </div>

            <div class="days-grid" style="height:<?= $totalPx ?>px">
                <?php for ($d = 0; $d < 7; $d++):
                    $dd  = (clone $monday)->modify("+$d days");
                    $isT = $dd->format('Y-m-d') === $todayStr;
                ?>
                <div class="day-col" style="height:<?= $totalPx ?>px;<?= $isT?'background:rgba(99,102,241,.025)':'' ?>">
                    <?php for ($h = $minHour; $h <= $maxHour; $h++): ?>
                    <div class="hr-line" style="top:<?= ($h-$minHour)*$pxPerHour ?>px"></div>
                    <div class="hf-line" style="top:<?= ($h-$minHour)*$pxPerHour+$pxPerHour/2 ?>px"></div>
                    <?php endfor; ?>

                    <?php if ($isT):
                        $nm  = (int)$today->format('H')*60+(int)$today->format('i');
                        $nt  = ($nm/60-$minHour)*$pxPerHour;
                        if ($nt>=0 && $nt<=$totalPx): ?>
                    <div class="now-line" style="top:<?= round($nt) ?>px"><div class="now-dot"></div></div>
                    <?php endif; endif; ?>

                    <?php foreach ($byDayIdx[$d] as $ev):
                        if (($ev['_type'] ?? 'lesson') === 'task'):
                            /* ── TASK block ── */
                            $dt     = new DateTime($ev['deadline']);
                            $top    = ((int)$dt->format('H')*60+(int)$dt->format('i'))/60*$pxPerHour - $minHour*$pxPerHour;
                            $ht     = round($pxPerHour * 0.6);
                            $isDone = $ev['sub_status'] === 'reviewed';
                            $isSent = $ev['sub_status'] === 'submitted';
                            $cls    = $isDone ? 'done' : ($isSent ? 'submitted' : '');
                            $td     = taskJson($ev);
                            $badge  = $isDone ? '✅ Оцінено' : ($isSent ? '🕐 Здано' : '📋 ДЗ');
                    ?>
                    <div class="lb-task <?= $cls ?>"
                         style="top:<?= round($top) ?>px;height:<?= $ht ?>px"
                         onclick="showTaskPopup(event,<?= htmlspecialchars($td,ENT_QUOTES) ?>)">
                        <div class="lb-task-badge"><?= $badge ?></div>
                        <div class="lb-task-title"><?= htmlspecialchars($ev['title']) ?></div>
                        <?php if ($ht > 46): ?>
                        <div class="lb-task-time">до <?= $dt->format('H:i') ?></div>
                        <?php endif; ?>
                    </div>

                    <?php else:
                            /* ── LESSON block ── */
                            $l   = $ev;
                            $dt  = new DateTime($l['scheduled_at']);
                            $dur = 60;
                            $top = ((int)$dt->format('H')*60+(int)$dt->format('i'))/60*$pxPerHour - $minHour*$pxPerHour;
                            $ht  = $dur/60*$pxPerHour - 4;
                            $col = $colorMap[$l['course_id']] ?? '#6366f1';
                            $ld  = lessonJson($l, $colorMap);
                    ?>
                    <div class="lb"
                         style="top:<?= round($top) ?>px;height:<?= round($ht) ?>px;background:<?= $col ?>18;border-left-color:<?= $col ?>;border-top:1px solid <?= $col ?>30;border-right:1px solid <?= $col ?>20;border-bottom:1px solid <?= $col ?>20"
                         onclick="showLessonPopup(event,<?= htmlspecialchars($ld,ENT_QUOTES) ?>)">
                        <div class="lb-lang" style="color:<?= $col ?>"><?= htmlspecialchars($l['lang_name']) ?></div>
                        <div class="lb-title"><?= htmlspecialchars($l['title']) ?></div>
                        <?php if ($ht>44): ?><div class="lb-sub"><?= htmlspecialchars(trim(($l['teacher_first']??'').' '.($l['teacher_last']??''))) ?></div><?php endif; ?>
                        <?php if ($ht>62): ?><div class="lb-time" style="color:<?= $col ?>"><?= $dt->format('H:i') ?> · <?= $dur ?> хв</div><?php endif; ?>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <?php if (empty($byDayIdx[0]) && empty($byDayIdx[1]) && empty($byDayIdx[2]) && empty($byDayIdx[3]) && empty($byDayIdx[4]) && empty($byDayIdx[5]) && empty($byDayIdx[6])): ?>
        <div class="empty-state">
            <div class="empty-icon">🗓</div>
            <div class="empty-title">Цього тижня нічого немає</div>
            <div class="empty-sub"><?= $weekOffset===0?'Запишіться на курси щоб бачити розклад':($weekOffset<0?'На цьому тижні нічого не було':'Заняття ще не заплановані') ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ═══════════════ MONTH VIEW ═══════════════ -->
    <div class="month-wrap">
        <div class="month-grid-hd">
            <?php foreach ($UA_DAYS as $d): ?><div class="month-dh"><?= $d ?></div><?php endforeach; ?>
        </div>
        <?php
        $firstDow    = (int)$monthStart->format('N') - 1;
        $daysInMonth = (int)$monthEnd->format('j');
        $prevEnd     = (clone $monthStart)->modify('-1 day');
        $prevLast    = (int)$prevEnd->format('j');
        $totalCells  = (int)ceil(($firstDow + $daysInMonth) / 7) * 7;
        ?>
        <div class="month-grid">
        <?php for ($cell = 0; $cell < $totalCells; $cell++):
            $dayNum = $cell - $firstDow + 1;
            if ($dayNum < 1) {
                $cellDay = $prevLast + $dayNum;
                $cellDt  = (clone $monthStart)->modify(($dayNum-1).' days');
                $otherMo = true;
            } elseif ($dayNum > $daysInMonth) {
                $cellDay = $dayNum - $daysInMonth;
                $cellDt  = (clone $monthEnd)->modify(($dayNum-$daysInMonth).' days');
                $otherMo = true;
            } else {
                $cellDay = $dayNum;
                $cellDt  = new DateTime("$monthYear-$monthNum-$cellDay");
                $otherMo = false;
            }
            $cellStr    = $cellDt->format('Y-m-d');
            $isToday    = $cellStr === $todayStr;
            $dayEvents  = $byDay[$cellStr] ?? [];
        ?>
        <div class="month-cell<?= $otherMo?' other-month':'' ?><?= $isToday?' is-today':'' ?>">
            <div class="cell-num"><?= $cellDay ?></div>
            <?php
            $shown = 0;
            foreach ($dayEvents as $ev):
                if ($shown >= 3) break; $shown++;
                if (($ev['_type'] ?? 'lesson') === 'task'):
                    $isDone = $ev['sub_status'] === 'reviewed';
                    $isSent = $ev['sub_status'] === 'submitted';
                    $cls    = $isDone ? 'done' : ($isSent ? 'submitted' : '');
                    $td     = taskJson($ev);
                    $dt2    = new DateTime($ev['deadline']);
            ?>
            <div class="month-task <?= $cls ?>"
                 onclick="showTaskPopup(event,<?= htmlspecialchars($td,ENT_QUOTES) ?>)">
                📋 <?= $dt2->format('H:i') ?> <?= htmlspecialchars(mb_substr($ev['title'],0,12)) ?>
            </div>
            <?php else:
                    $l   = $ev;
                    $col = $colorMap[$l['course_id']] ?? '#6366f1';
                    $dt2 = new DateTime($l['scheduled_at']);
                    $ld  = lessonJson($l, $colorMap);
            ?>
            <div class="month-ev"
                 style="background:<?= $col ?>1e;color:<?= $col ?>;border-left-color:<?= $col ?>"
                 onclick="showLessonPopup(event,<?= htmlspecialchars($ld,ENT_QUOTES) ?>)">
                <?= $dt2->format('H:i') ?> <?= htmlspecialchars($l['title']) ?>
            </div>
            <?php endif; endforeach; ?>
            <?php if (count($dayEvents)>3): ?>
            <div class="month-more">+<?= count($dayEvents)-3 ?> ще</div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
        </div>

        <?php if (empty($byDay)): ?>
        <div class="empty-state" style="margin-top:40px">
            <div class="empty-icon">🗓</div>
            <div class="empty-title">Цього місяця нічого немає</div>
            <div class="empty-sub"><?= $monthOffset===0?'Запишіться на курси щоб бачити розклад':($monthOffset<0?'Цього місяця нічого не було':'Заняття ще не заплановані') ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<script>
const popup = document.getElementById('popup');

/* ── Lesson popup ── */
function showLessonPopup(e, data) {
    e.stopPropagation();
    document.getElementById('popBar').style.background = data.color;
    document.getElementById('popTitle').textContent    = data.title;
    document.getElementById('popDate').textContent     = data.date;
    document.getElementById('popTime').textContent     = data.start + ' — ' + data.end + ' (' + data.dur + ' хв)';
    document.getElementById('popCourse').textContent   = data.course;
    document.getElementById('popExtra').textContent    = data.teacher;

    const sb = document.getElementById('popStatus');
    const labels = {scheduled:'Заплановано', completed:'Завершено', cancelled:'Скасовано'};
    sb.textContent = labels[data.status] || data.status;
    sb.className   = 'pop-badge ' + (data.status || 'scheduled');

    const acts = document.getElementById('popActions');
    acts.innerHTML = '';
    if (data.url && data.status === 'scheduled') {
        acts.innerHTML += `<a class="pop-btn pop-join" href="${data.url}" target="_blank">📹 Приєднатись</a>`;
    }
    acts.innerHTML += `<button class="pop-btn pop-close-btn" onclick="closePopup()">Закрити</button>`;

    positionAndShow(e);
}

/* ── Task popup ── */
function showTaskPopup(e, data) {
    e.stopPropagation();

    const statusCfg = {
        pending:   { label: '⏳ Не здано',      cls: 'hw-pending', bar: '#f59e0b' },
        submitted: { label: '🕐 На перевірці',  cls: 'hw-sent',    bar: '#22d3ee' },
        reviewed:  { label: '✅ Оцінено',        cls: 'hw-done',    bar: '#22c55e' },
        returned:  { label: '↩ Повернено',       cls: 'hw-return',  bar: '#ef4444' },
    };
    const sc = statusCfg[data.status] || statusCfg.pending;

    document.getElementById('popBar').style.background = sc.bar;
    document.getElementById('popTitle').textContent    = '📋 ' + data.title;
    document.getElementById('popDate').textContent     = 'Дедлайн: ' + data.date + ' ' + data.time;
    document.getElementById('popTime').textContent     = 'Макс. балів: ' + data.score;
    document.getElementById('popCourse').textContent   = data.course;
    document.getElementById('popExtra').textContent    = sc.label;

    const sb = document.getElementById('popStatus');
    sb.textContent = 'Домашнє завдання';
    sb.className   = 'pop-badge ' + sc.cls;

    const acts = document.getElementById('popActions');
    acts.innerHTML = `<a class="pop-btn pop-hw" href="homework_student.php?task_id=${data.id}">📝 Перейти до завдання</a>`;
    acts.innerHTML += `<button class="pop-btn pop-close-btn" onclick="closePopup()">Закрити</button>`;

    positionAndShow(e);
}

function positionAndShow(e) {
    const vw=window.innerWidth, vh=window.innerHeight, pw=285, ph=300;
    let x=e.clientX+14, y=e.clientY-20;
    if (x+pw>vw-10) x=e.clientX-pw-14;
    if (y+ph>vh-10) y=vh-ph-10;
    if (y<10) y=10;
    popup.style.left=x+'px'; popup.style.top=y+'px';
    popup.classList.add('show');
}

function closePopup() { popup.classList.remove('show'); }
document.addEventListener('click', e => { if (!popup.contains(e.target)) closePopup(); });
document.addEventListener('keydown', e => { if (e.key==='Escape') closePopup(); });

document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('calBody');
    if (!body) return;
    const nowLine = body.querySelector('.now-line');
    body.scrollTop = nowLine
        ? Math.max(0, parseInt(nowLine.style.top) - 100)
        : Math.max(0, (9 - <?= $minHour ?>) * <?= $pxPerHour ?> - 40);
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>