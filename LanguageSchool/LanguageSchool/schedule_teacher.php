<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit;
}

$teacherId = $_SESSION['user_id'];

// Fetch teacher data
$stmtUser = $pdo->prepare("SELECT first_name, last_name, email, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $teacherId]);
$me = $stmtUser->fetch(PDO::FETCH_ASSOC);
$teacherName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Викладач';
$initials = strtoupper(substr($me['first_name'] ?? '', 0, 1) . substr($me['last_name'] ?? '', 0, 1)) ?: 'ВЛ';

// Fetch teacher's courses
$stmtCourses = $pdo->prepare("
    SELECT c.id, c.title
    FROM courses c
    WHERE c.teacher_id = :teacher_id
    ORDER BY c.title
");
$stmtCourses->execute(['teacher_id' => $teacherId]);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

// Fetch students enrolled in teacher's courses, grouped by course
$stmtStudents = $pdo->prepare("
    SELECT DISTINCT
        u.id,
        u.first_name,
        u.last_name,
        e.course_id
    FROM users u
    JOIN enrollments e ON e.student_id = u.id
    JOIN courses c ON c.id = e.course_id
    WHERE c.teacher_id = :teacher_id AND e.status = 'active'
    ORDER BY u.first_name, u.last_name
");
$stmtStudents->execute(['teacher_id' => $teacherId]);
$allStudents = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

$studentsByCourse = [];
foreach ($allStudents as $s) {
    $courseId = $s['course_id'];
    if (!isset($studentsByCourse[$courseId])) {
        $studentsByCourse[$courseId] = [];
    }
    $studentsByCourse[$courseId][] = [
        'id'   => $s['id'],
        'name' => trim($s['first_name'] . ' ' . $s['last_name'])
    ];
}

/* ── Режим перегляду ── */
$view        = in_array($_GET['view'] ?? '', ['week', 'month']) ? $_GET['view'] : 'week';
$weekOffset  = (int)($_GET['week']  ?? 0);
$monthOffset = (int)($_GET['month'] ?? 0);

$today    = new DateTime();
$todayStr = $today->format('Y-m-d');

/* ── Тижневий діапазон ── */
$dow    = (int)$today->format('N');
$monday = (clone $today)->modify('-' . ($dow - 1) . ' days')->modify($weekOffset . ' weeks');
$sunday = (clone $monday)->modify('+6 days');
$weekStart = $monday->format('Y-m-d');
$weekEnd   = $sunday->format('Y-m-d');

/* ── Місячний діапазон ── */
$monthDt       = (clone $today)->modify($monthOffset . ' months');
$monthYear     = (int)$monthDt->format('Y');
$monthNum      = (int)$monthDt->format('m');
$monthStart    = new DateTime("$monthYear-$monthNum-01");
$monthEnd      = (clone $monthStart)->modify('last day of this month');
$monthStartStr = $monthStart->format('Y-m-d');
$monthEndStr   = $monthEnd->format('Y-m-d');

/* ── Запит занять з іменами студентів через subquery ── */
$rangeStart = $view === 'week' ? $weekStart : $monthStartStr;
$rangeEnd   = $view === 'week' ? $weekEnd   : $monthEndStr;

$stmtLessons = $pdo->prepare("
    SELECT
    l.id,
    l.title,
    l.lesson_type,
    l.scheduled_at,
    l.meeting_url,
    l.course_id,
    l.status,
    c.title AS course_title,
        (
            SELECT STRING_AGG(u2.first_name || ' ' || u2.last_name, ', ' ORDER BY u2.first_name)
            FROM lesson_students ls2
            JOIN users u2 ON u2.id = ls2.student_id
            WHERE ls2.lesson_id = l.id
        ) AS student_names,
        'Українська' AS lang_name
    FROM lessons l
    JOIN courses c ON c.id = l.course_id
    WHERE l.teacher_id = :tid
      AND DATE(l.scheduled_at AT TIME ZONE 'UTC') BETWEEN :ws AND :we
    ORDER BY l.scheduled_at ASC
");
$stmtLessons->execute([':tid' => $teacherId, ':ws' => $rangeStart, ':we' => $rangeEnd]);
$lessons = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);

/* ── Кольори по курсах ── */
$palette  = ['#6366f1', '#22d3ee', '#22c55e', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316'];
$colorMap = [];
foreach ($lessons as $l) {
    if (!isset($colorMap[$l['course_id']])) {
        $colorMap[$l['course_id']] = $palette[count($colorMap) % count($palette)];
    }
}

/* ── Групування по датах ── */
$byDay    = [];
$byDayIdx = array_fill(0, 7, []);
foreach ($lessons as $l) {
    $dt  = new DateTime($l['scheduled_at']);
    $key = $dt->format('Y-m-d');
    $byDay[$key][] = $l;
    if ($view === 'week') {
        $dayNum = (int)$dt->format('N') - 1;
        if ($dayNum >= 0 && $dayNum < 7) {
            $byDayIdx[$dayNum][] = $l;
        }
    }
}

/* ── Статистика ── */
$totalLessons  = count($lessons);
$completedLess = 0;
$upcomingLess  = count($lessons);

/* ── Діапазон годин ── */
$minHour   = 8;
$maxHour   = 21;
$pxPerHour = 85;
if ($view === 'week' && $lessons) {
    $sh = array_map(fn($l) => (int)(new DateTime($l['scheduled_at']))->format('H'), $lessons);
    $eh = array_map(fn($l) => (int)ceil((new DateTime($l['scheduled_at']))->format('H') + 1), $lessons);
    $minHour = max(7, min($sh) - 1);
    $maxHour = min(23, max($eh) + 1);
}
$totalPx = ($maxHour - $minHour) * $pxPerHour;

// ── ВИПРАВЛЕННЯ: місяці вже в нижньому регістрі — не потрібен mb_strtolower ──
$UA_MONTHS = ['', 'січень', 'лютий', 'березень', 'квітень', 'травень', 'червень', 'липень', 'серпень', 'вересень', 'жовтень', 'листопад', 'грудень'];
// Для заголовка місяця з великої літери — окремий масив
$UA_MONTHS_CAP = ['', 'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'];
$UA_DAYS   = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];

function lessonJson(array $l, array $colorMap): string {
    $dt  = new DateTime($l['scheduled_at']);
    $dur = 60;
    $end = (clone $dt)->modify("+$dur minutes");
    $studentDisplay = !empty($l['student_names']) ? $l['student_names'] : 'Студент не призначений';
    return json_encode([
        'id'     => $l['id'],
        'title'  => $l['title'],
        'desc'   => '',
        'start'  => $dt->format('H:i'),
        'end'    => $end->format('H:i'),
        'date'   => $dt->format('d.m.Y'),
        'dur'    => $dur,
        'color'  => $colorMap[$l['course_id']] ?? '#6366f1',
        'group'  => $studentDisplay,
        'course' => $l['course_title'],
        'status' => $l['status'] ?? 'scheduled',
        'lang'   => $l['lang_name'],
        'url'    => $l['meeting_url'] ?? '',
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #07080f; --surface: #0d1117; --card: #111827; --border: #1e293b;
    --accent: #6366f1; --teal: #22d3ee; --green: #22c55e; --amber: #f59e0b;
    --red: #ef4444; --text: #e2e8f0; --muted: #64748b;
    --font: 'Syne', sans-serif; --mono: 'JetBrains Mono', monospace;
    --sidebar: 230px;
}
html, body { height: 100%; }
body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; overflow: hidden; }
body::before {
    content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(ellipse 80% 50% at 5% 0%, rgba(99,102,241,.13) 0%, transparent 55%),
        radial-gradient(ellipse 60% 60% at 95% 90%, rgba(34,211,238,.10) 0%, transparent 55%);
}

/* ══ SIDEBAR ══ */
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar);
    background: rgba(13,17,23,.97); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; z-index: 20;
}
.sidebar-logo { padding: 22px 20px 18px; border-bottom: 1px solid var(--border); }
.logo-text { font-size: 20px; font-weight: 800; letter-spacing: -.5px; }
.logo-text span { color: var(--teal); }
.sidebar-profile {
    display: flex; align-items: center; gap: 11px;
    padding: 14px 20px; border-bottom: 1px solid var(--border);
}
.s-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0;
    border: 2px solid rgba(99,102,241,.4); overflow: hidden;
}
.s-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
.profile-role { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }
.nav-label { font-family: var(--mono); font-size: 9px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; padding: 10px 10px 4px; margin-top: 4px; }
.nav-item {
    display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 10px;
    text-decoration: none; color: var(--muted); font-size: 13px; font-weight: 600;
    transition: .18s; border: 1px solid transparent;
}
.nav-item svg { width: 15px; height: 15px; flex-shrink: 0; }
.nav-item:hover { color: var(--text); background: rgba(255,255,255,.04); }
.nav-item.active { color: #fff; background: rgba(99,102,241,.15); border-color: rgba(99,102,241,.3); }
.sidebar-footer { padding: 12px 10px; border-top: 1px solid var(--border); }
.logout-btn {
    display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; border-radius: 10px;
    background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.2); color: #fca5a5;
    font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer;
    transition: .18s; text-decoration: none;
}
.logout-btn:hover { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.4); }
.logout-btn svg { width: 14px; height: 14px; }

/* ══ MAIN ══ */
.main { margin-left: var(--sidebar); flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; position: relative; z-index: 1; }

/* ══ TOPBAR ══ */
.topbar {
    flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;
    padding: 11px 22px; border-bottom: 1px solid var(--border);
    background: var(--topbar-bg); backdrop-filter: blur(20px); gap: 12px;
    transition: background .25s, border-color .25s;
}
.topbar-left  { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.topbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.page-title   { font-size: 16px; font-weight: 800; letter-spacing: -.3px; white-space: nowrap; }
.page-title span { color: var(--teal); }
.nav-group  { display: flex; align-items: center; gap: 4px; }
.nav-arrow  {
    width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--border);
    background: var(--surface); color: var(--muted); cursor: pointer; display: flex;
    align-items: center; justify-content: center; transition: .18s; font-size: 12px; text-decoration: none;
}
.nav-arrow:hover { color: var(--text); border-color: rgba(99,102,241,.4); background: rgba(99,102,241,.08); }
.range-label {
    font-family: var(--mono); font-size: 10px; color: var(--text); white-space: nowrap;
    padding: 0 8px; min-width: 200px; text-align: center;
}
.today-btn {
    padding: 5px 11px; border-radius: 7px; border: 1px solid rgba(99,102,241,.3);
    background: rgba(99,102,241,.1); color: #a5b4fc; font-family: var(--mono);
    font-size: 10px; font-weight: 600; cursor: pointer; transition: .18s;
    text-decoration: none; white-space: nowrap;
}
.today-btn:hover { background: rgba(99,102,241,.2); }
.view-toggle { display: flex; background: var(--surface); border: 1px solid var(--border); border-radius: 9px; overflow: hidden; }
.vt-btn {
    padding: 6px 13px; font-family: var(--mono); font-size: 10px; font-weight: 600;
    color: var(--muted); border: none; background: none; cursor: pointer; transition: .18s;
    display: flex; align-items: center; gap: 5px; text-decoration: none; white-space: nowrap;
}
.vt-btn:hover { color: var(--text); }
.vt-btn.active { background: rgba(99,102,241,.18); color: #000000; }
.add-btn {
    padding: 6px 14px; border-radius: 7px; border: 1px solid rgba(34,197,94,.3);
    background: rgba(34,197,94,.1); color: var(--green); font-family: var(--mono);
    font-size: 10px; font-weight: 600; cursor: pointer; transition: .18s;
    display: flex; align-items: center; gap: 5px;
}
.add-btn:hover { background: rgba(34,197,94,.2); }

/* ══ STATS STRIP ══ */
.stats-strip { flex-shrink: 0; display: flex; gap: 1px; border-bottom: 1px solid var(--border); background: var(--border); }
.stat-chip { flex: 1; padding: 8px 14px; background: var(--surface); display: flex; align-items: center; gap: 8px; }
.stat-chip-icon { font-size: 14px; }
.stat-chip-val  { font-size: 15px; font-weight: 800; }
.stat-chip-lbl  { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; margin-top: 1px; }
.c-purple .stat-chip-val { color: #a5b4fc; }
.c-teal   .stat-chip-val { color: var(--teal); }
.c-green  .stat-chip-val { color: var(--green); }

/* ══ WEEK VIEW ══ */
.cal-wrap  { flex: 1; overflow: hidden; display: flex; flex-direction: column; }
.cal-header {
    flex-shrink: 0; display: flex; border-bottom: 1px solid var(--border);
    background: var(--panel-bg);
    transition: background .25s;
}
.time-gutter { width: 50px; flex-shrink: 0; border-right: 1px solid var(--border); }
.cal-day-hd {
    flex: 1; min-width: 0; padding: 8px 4px; text-align: center;
    border-right: 1px solid var(--border);
}
.cal-day-hd:last-child { border-right: none; }
.cal-day-name { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
.cal-day-num  { font-size: 17px; font-weight: 800; margin-top: 1px; line-height: 1; }
.cal-day-hd.is-today .cal-day-num  { color: var(--accent); }
.cal-day-hd.is-today .cal-day-name { color: var(--accent); }
.cal-day-hd.has-ev .cal-day-name::after { content: '·'; color: var(--teal); margin-left: 2px; font-size: 14px; vertical-align: middle; }

.cal-body { flex: 1; overflow-y: auto; display: flex; }
.cal-body::-webkit-scrollbar { width: 4px; }
.cal-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

.time-col  { width: 50px; flex-shrink: 0; position: relative; border-right: 1px solid var(--border); }
.time-lbl  { position: absolute; right: 5px; font-family: var(--mono); font-size: 9px; color: var(--muted); transform: translateY(-50%); white-space: nowrap; }
.days-grid { flex: 1; display: grid; grid-template-columns: repeat(7,1fr); position: relative; }
.day-col   { border-right: 1px solid var(--border); position: relative; }
.day-col:last-child { border-right: none; }
.hr-line   { position: absolute; left: 0; right: 0; border-top: 1px solid rgba(30,41,59,.5); pointer-events: none; }
.hf-line   { position: absolute; left: 0; right: 0; border-top: 1px dashed rgba(30,41,59,.28); pointer-events: none; }
.now-line  { position: absolute; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--red), rgba(239,68,68,.2)); z-index: 5; pointer-events: none; }
.now-dot   { position: absolute; left: -4px; top: -4px; width: 9px; height: 9px; border-radius: 50%; background: var(--red); box-shadow: 0 0 8px var(--red); }

/* ── Lesson block ── */
.lb {
    position: absolute; left: 3px; right: 3px; border-radius: 10px; padding: 7px 9px;
    overflow: hidden; cursor: pointer; z-index: 2;
    border-left-width: 3px; border-left-style: solid;
    transition: transform .13s, box-shadow .13s;
    display: flex; flex-direction: column; gap: 2px;
}
.lb:hover { transform: scale(1.03) translateY(-1px); z-index: 10; box-shadow: 0 6px 22px rgba(0,0,0,.55); }
.lb-lang    { font-family: var(--mono); font-size: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.lb-title   { font-size: 11px; font-weight: 700; line-height: 1.3; color: #e2e8f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lb-course  { font-size: 10px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lb-student { font-size: 10px; color: #a5b4fc; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.lb-time    { font-family: var(--mono); font-size: 9px; opacity: .7; margin-top: auto; padding-top: 2px; white-space: nowrap; overflow: hidden; }
.lb-actions { position: absolute; top: 4px; right: 5px; display: none; gap: 3px; }
.lb:hover .lb-actions { display: flex; }
.lb-action  {
    width: 18px; height: 18px; border-radius: 4px; border: none;
    color: #fff; cursor: pointer; font-size: 10px;
    display: flex; align-items: center; justify-content: center; transition: .12s;
}
.lb-action.edit   { background: rgba(99,102,241,.5); }
.lb-action.edit:hover { background: rgba(99,102,241,.85); }
.lb-action.del    { background: rgba(239,68,68,.4); }
.lb-action.del:hover  { background: rgba(239,68,68,.8); }

/* ══ MONTH VIEW ══ */
.month-wrap { flex: 1; overflow-y: auto; padding: 14px 18px 20px; }
.month-wrap::-webkit-scrollbar { width: 4px; }
.month-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.month-grid-hd { display: grid; grid-template-columns: repeat(7,1fr); gap: 4px; margin-bottom: 5px; }
.month-dh { text-align: center; font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; padding: 3px 0; }
.month-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 4px; }
.month-cell {
    min-height: 92px; background: var(--card); border: 1px solid var(--border);
    border-radius: 10px; padding: 6px; display: flex; flex-direction: column;
    gap: 3px; transition: border-color .18s;
}
.month-cell:hover { border-color: rgba(99,102,241,.3); }
.month-cell.other-month { opacity: .28; pointer-events: none; }
.month-cell.is-today { border-color: rgba(99,102,241,.5); background: rgba(99,102,241,.06); }
.cell-num {
    font-family: var(--mono); font-size: 10px; font-weight: 700; color: var(--muted);
    align-self: flex-start; width: 22px; height: 22px; display: flex; align-items: center;
    justify-content: center; border-radius: 6px; flex-shrink: 0;
}
.month-cell.is-today .cell-num { background: var(--accent); color: #fff; }
.month-ev {
    font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 5px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer;
    transition: .12s; line-height: 1.45; border-left-width: 2px; border-left-style: solid;
    display: flex; align-items: center; justify-content: space-between; gap: 4px;
}
.month-ev:hover { filter: brightness(1.25); }
.month-ev-text { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.month-ev-actions {
    display: none; gap: 3px; flex-shrink: 0;
}
.month-ev:hover .month-ev-actions { display: flex; }
.month-ev-btn {
    width: 16px; height: 16px; border-radius: 3px; border: none;
    color: #fff; cursor: pointer; font-size: 9px; padding: 0;
    display: flex; align-items: center; justify-content: center; transition: .12s;
}
.month-ev-edit { background: rgba(99,102,241,.6); }
.month-ev-edit:hover { background: rgba(99,102,241,.9); }
.month-ev-del { background: rgba(239,68,68,.5); }
.month-ev-del:hover { background: rgba(239,68,68,.9); }
.month-more { font-family: var(--mono); font-size: 9px; color: var(--muted); padding: 1px 4px; cursor: pointer; transition: .14s; }
.month-more:hover { color: var(--text); }

/* ══ POPUP ══ */
.popup {
    display: none; position: fixed; z-index: 300; width: 300px;
    background: var(--card); border: 1px solid var(--border); border-radius: 14px;
    padding: 16px; box-shadow: 0 20px 50px rgba(0,0,0,.65);
    animation: popIn .16s ease both;
}
.popup.show { display: block; }
.pop-bar    { height: 3px; border-radius: 99px; margin-bottom: 12px; }
.pop-head   { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; }
.pop-title  { font-size: 14px; font-weight: 800; line-height: 1.35; flex: 1; margin-right: 8px; }
.pop-x      { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 15px; line-height: 1; padding: 2px 4px; transition: .13s; flex-shrink: 0; }
.pop-x:hover { color: var(--text); }
.pop-row    { display: flex; align-items: flex-start; gap: 7px; margin-bottom: 6px; font-family: var(--mono); font-size: 10px; color: var(--muted); }
.pop-row svg { width: 12px; height: 12px; flex-shrink: 0; margin-top: 1px; }
.pop-row span { color: var(--text); line-height: 1.4; }
.pop-badge  { display: inline-flex; padding: 3px 9px; border-radius: 99px; font-family: var(--mono); font-size: 9px; font-weight: 700; margin-top: 6px; }
.pop-badge.scheduled { background: rgba(99,102,241,.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,.3); }
.pop-badge.completed { background: rgba(34,197,94,.12); color: var(--green); border: 1px solid rgba(34,197,94,.3); }
.pop-actions { margin-top: 12px; display: flex; gap: 6px; }
.pop-btn {
    flex: 1; padding: 8px; border-radius: 8px; font-family: var(--font); font-size: 11px;
    font-weight: 700; cursor: pointer; transition: .14s; text-align: center;
    text-decoration: none; display: flex; align-items: center; justify-content: center;
    gap: 4px; border: none;
}
.pop-edit   { background: linear-gradient(135deg, var(--accent), #818cf8); color: #fff; }
.pop-edit:hover { opacity: .85; }
.pop-delete { background: rgba(239,68,68,.12); color: #fca5a5; border: 1px solid rgba(239,68,68,.3) !important; }
.pop-delete:hover { background: rgba(239,68,68,.22); }
.pop-join   { background: linear-gradient(135deg, var(--teal), #06b6d4); color: var(--bg); }
.pop-join:hover { opacity: .85; }
.pop-hint { margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,.1); font-size: 9px; color: var(--muted); line-height: 1.5; }

/* ══ MODAL ══ */
.modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7);
    z-index: 500; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
    background: var(--card); border: 1px solid var(--border); border-radius: 18px;
    padding: 28px; max-width: 480px; width: 90%; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,.8);
}
.modal-title { font-size: 17px; font-weight: 800; margin-bottom: 20px; color: #a5b4fc; }
.form-group  { margin-bottom: 15px; }
.form-label  { display: block; font-family: var(--mono); font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: 10px 12px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 8px; color: var(--text);
    font-family: var(--font); font-size: 13px; transition: border-color .18s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--accent); }
.form-select option { background: var(--card); }
.form-textarea { resize: vertical; min-height: 70px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-actions { display: flex; gap: 10px; margin-top: 20px; }
.form-btn { flex: 1; padding: 10px; border-radius: 10px; font-family: var(--font); font-size: 13px; font-weight: 700; cursor: pointer; transition: .18s; border: none; }
.btn-save   { background: linear-gradient(135deg, var(--green), #86efac); color: var(--bg); }
.btn-save:hover { opacity: .9; }
.btn-cancel { background: rgba(255,255,255,.05); color: var(--muted); border: 1px solid var(--border) !important; }
.btn-cancel:hover { color: var(--text); border-color: var(--accent) !important; }

/* ══ EMPTY ══ */
.empty-state {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    flex: 1; gap: 10px; padding: 50px 24px; text-align: center; opacity: .5;
}
.empty-icon  { font-size: 44px; }
.empty-title { font-size: 14px; font-weight: 800; color: var(--muted); }
.empty-sub   { font-family: var(--mono); font-size: 10px; color: var(--muted); line-height: 1.7; max-width: 240px; }

/* ══ TOAST ══ */
.toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 999;
    padding: 12px 18px; border-radius: 12px; font-size: 13px; font-weight: 700;
    display: none; animation: slideInRight .25s ease;
}
.toast.show { display: block; }
.toast.success { background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.3); color: var(--green); }
.toast.error   { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }

@keyframes popIn     { from { opacity: 0; transform: scale(.93) translateY(4px); } to { opacity: 1; transform: none; } }
@keyframes slideInRight { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }
body.light-theme .sidebar { background: rgba(255,255,255,.98) !important; border-right-color: #e2e8f0 !important; }
body.light-theme .sidebar-logo { border-bottom-color: #e2e8f0 !important; }
body.light-theme .logo-text { color: #1e293b; }
body.light-theme .logo-text span { color: #0891b2; }
body.light-theme .sidebar-profile { border-bottom-color: #e2e8f0 !important; }
body.light-theme .profile-name { color: #0f172a; }
body.light-theme .profile-role { color: #64748b; }
body.light-theme .nav-label { color: #94a3b8; }
body.light-theme .nav-item { color: #475569; }
body.light-theme .nav-item:hover { color: #1e293b; background: rgba(79,70,229,.05) !important; }
body.light-theme .nav-item.active { color: #4f46e5 !important; background: rgba(79,70,229,.12) !important; border-color: rgba(79,70,229,.3) !important; }
body.light-theme .sidebar-footer { border-top-color: #e2e8f0 !important; }
body.light-theme .logout-btn { background: rgba(220,38,38,.06) !important; border-color: rgba(220,38,38,.2) !important; color: #dc2626 !important; }
body.light-theme .logout-btn:hover { background: rgba(220,38,38,.12) !important; }
 
/* Topbar */
body.light-theme .topbar { background: rgba(241,245,249,.97) !important; border-bottom-color: #e2e8f0 !important; }
body.light-theme .page-title { color: #0f172a; }
body.light-theme .page-title span { color: #0891b2; }
body.light-theme .nav-arrow { background: #fff !important; border-color: #e2e8f0 !important; color: #475569 !important; }
body.light-theme .nav-arrow:hover { color: #4f46e5 !important; border-color: rgba(79,70,229,.4) !important; background: rgba(79,70,229,.06) !important; }
body.light-theme .range-label { color: #1e293b !important; }
body.light-theme .today-btn { background: rgba(79,70,229,.1) !important; border-color: rgba(79,70,229,.3) !important; color: #4f46e5 !important; }
body.light-theme .view-toggle { background: #fff !important; border-color: #e2e8f0 !important; }
body.light-theme .vt-btn { color: #64748b !important; }
body.light-theme .vt-btn:hover { color: #1e293b !important; }
body.light-theme .vt-btn.active { background: rgba(79,70,229,.15) !important; color: #4f46e5 !important; }
body.light-theme .add-btn { background: rgba(22,163,74,.1) !important; border-color: rgba(22,163,74,.3) !important; color: #16a34a !important; }
body.light-theme .add-btn:hover { background: rgba(22,163,74,.18) !important; }
 
/* Stats strip */
body.light-theme .stats-strip { background: #e2e8f0 !important; border-bottom-color: #e2e8f0 !important; }
body.light-theme .stat-chip { background: #f8fafc !important; }
body.light-theme .stat-chip-lbl { color: #64748b !important; }
body.light-theme .c-purple .stat-chip-val { color: #4f46e5 !important; }
body.light-theme .c-teal   .stat-chip-val { color: #0891b2 !important; }
body.light-theme .c-green  .stat-chip-val { color: #16a34a !important; }
 
/* Calendar header (week days) */
body.light-theme .cal-header { background: #f8fafc !important; border-bottom-color: #e2e8f0 !important; }
body.light-theme .time-gutter { border-right-color: #e2e8f0 !important; }
body.light-theme .cal-day-hd { border-right-color: #e2e8f0 !important; }
body.light-theme .cal-day-name { color: #64748b !important; }
body.light-theme .cal-day-num  { color: #0f172a !important; }
body.light-theme .cal-day-hd.is-today .cal-day-num  { color: #4f46e5 !important; }
body.light-theme .cal-day-hd.is-today .cal-day-name { color: #4f46e5 !important; }
 
/* Calendar body */
body.light-theme .cal-body { background: #fff; }
body.light-theme .time-col { border-right-color: #e2e8f0 !important; }
body.light-theme .time-lbl { color: #94a3b8 !important; }
body.light-theme .day-col  { border-right-color: #e2e8f0 !important; }
body.light-theme .hr-line  { border-top-color: rgba(148,163,184,.35) !important; }
body.light-theme .hf-line  { border-top-color: rgba(148,163,184,.2)  !important; }
 
/* Lesson blocks — головна проблема: lb-title hardcoded color:#e2e8f0 */
body.light-theme .lb-title   { color: #0f172a !important; }
body.light-theme .lb-course  { color: #475569 !important; }
body.light-theme .lb-student { color: #4f46e5 !important; }
body.light-theme .lb-time    { color: #64748b !important; opacity: 1 !important; }
 
/* Month view */
body.light-theme .month-wrap { background: #f1f5f9; }
body.light-theme .month-dh   { color: #64748b !important; }
body.light-theme .month-cell { background: #fff !important; border-color: #e2e8f0 !important; }
body.light-theme .month-cell:hover { border-color: rgba(79,70,229,.3) !important; }
body.light-theme .month-cell.is-today { background: rgba(79,70,229,.05) !important; border-color: rgba(79,70,229,.4) !important; }
body.light-theme .cell-num   { color: #64748b !important; }
body.light-theme .month-cell.is-today .cell-num { background: #4f46e5 !important; color: #fff !important; }
body.light-theme .month-more { color: #94a3b8 !important; }
body.light-theme .month-more:hover { color: #475569 !important; }
 
/* Popup */
body.light-theme .popup { background: #fff !important; border-color: #e2e8f0 !important; box-shadow: 0 12px 40px rgba(0,0,0,.12) !important; }
body.light-theme .pop-title  { color: #0f172a !important; }
body.light-theme .pop-x      { color: #94a3b8 !important; }
body.light-theme .pop-x:hover { color: #0f172a !important; }
body.light-theme .pop-row    { color: #64748b !important; }
body.light-theme .pop-row span { color: #1e293b !important; }
body.light-theme .pop-badge.scheduled { background: rgba(79,70,229,.1) !important; color: #4f46e5 !important; border-color: rgba(79,70,229,.25) !important; }
body.light-theme .pop-badge.completed { background: rgba(22,163,74,.1) !important; color: #16a34a !important; border-color: rgba(22,163,74,.25) !important; }
body.light-theme .pop-hint   { border-top-color: #e2e8f0 !important; color: #94a3b8 !important; }
 
/* Modal */
body.light-theme .modal-box   { background: #fff !important; border-color: #e2e8f0 !important; }
body.light-theme .modal-title { color: #4f46e5 !important; }
body.light-theme .form-label  { color: #64748b !important; }
body.light-theme .form-input,
body.light-theme .form-select,
body.light-theme .form-textarea { background: #f8fafc !important; border-color: #e2e8f0 !important; color: #0f172a !important; }
body.light-theme .form-input:focus,
body.light-theme .form-select:focus,
body.light-theme .form-textarea:focus { border-color: #4f46e5 !important; }
body.light-theme .form-select option { background: #fff; color: #0f172a; }
body.light-theme .btn-cancel { background: rgba(0,0,0,.04) !important; color: #475569 !important; border-color: #e2e8f0 !important; }
 
/* Empty state */
body.light-theme .empty-title { color: #64748b !important; }
body.light-theme .empty-sub   { color: #94a3b8 !important; }
</style>
</head>
<body>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<!-- POPUP -->
<div class="popup" id="popup">
    <div class="pop-bar" id="popBar"></div>
    <div class="pop-head">
        <div class="pop-title" id="popTitle"></div>
        <button class="pop-x" onclick="closePopup()">✕</button>
    </div>
    <div class="pop-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span id="popDate"></span>
    </div>
    <div class="pop-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="popTime"></span>
    </div>
    <div class="pop-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
        <span id="popCourse"></span>
    </div>
    <div class="pop-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span id="popStudent"></span>
    </div>
    <span class="pop-badge" id="popStatus"></span>
    <div class="pop-actions" id="popActions"></div>
    <div class="pop-hint">
        <div>🎯 <strong>Гарячі клавіші:</strong></div>
        <div>E — редагувати | Del — видалити | Esc — закрити</div>
    </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-title" id="modalTitle">Додати заняття</div>
        <div id="lessonForm">
            <input type="hidden" id="lessonId">
            <div class="form-group">
                <label class="form-label">Назва заняття</label>
                <input type="text" id="lessonTitle" class="form-input" placeholder="Наприклад: Present Simple — введення">
            </div>
            <div class="form-group">
                <label class="form-label">Опис (необов'язково)</label>
                <textarea id="lessonDesc" class="form-textarea" placeholder="Додаткова інформація..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Курс</label>
                <select id="lessonCourse" class="form-select">
                    <option value="">— Оберіть курс —</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Студент (необов'язково)</label>
                <select id="lessonStudent" class="form-select">
                    <option value="">— Всі студенти курсу —</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Статус</label>
                <select id="lessonStatus" class="form-select">
                    <option value="scheduled">📌 Заплановано</option>
                    <option value="completed">✅ Завершено</option>
                    <option value="cancelled">❌ Скасовано</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Дата</label>
                    <input type="date" id="lessonDate" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Час початку</label>
                    <input type="time" id="lessonStart" class="form-input">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Посилання на відеозустріч (необов'язково)</label>
                <input type="url" id="lessonUrl" class="form-input" placeholder="https://meet.google.com/...">
            </div>
            <div class="form-actions">
                <button class="form-btn btn-save" onclick="saveLesson()">💾 Зберегти</button>
                <button class="form-btn btn-cancel" onclick="closeModal()">Скасувати</button>
            </div>
        </div>
    </div>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo"><div class="logo-text">Lingua<span>Hub</span></div></div>
    <div class="sidebar-profile">
        <div class="s-avatar">
            <?php if (!empty($me['avatar_url']) && file_exists($me['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($me['avatar_url']) ?>" alt="">
            <?php else: ?><?= htmlspecialchars($initials) ?><?php endif; ?>
        </div>
        <div>
            <div class="profile-name"><?= htmlspecialchars($teacherName) ?></div>
            <div class="profile-role">Викладач</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Меню</div>
        <a class="nav-item" href="dashboard_teacher.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Курси
        </a>
        <a class="nav-item active" href="schedule_teacher.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Розклад
        </a>
        <a class="nav-item" href="chat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Чат
        </a>
        <div class="nav-label">Акаунт</div>
        <a class="nav-item" href="profile_teacher.php">
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

<!-- MAIN -->
<main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <div class="page-title">📅 <span>РОЗКЛАД</span></div>
            <div class="nav-group">
                <?php if ($view === 'week'): ?>
                    <a class="nav-arrow" href="?view=week&week=<?= $weekOffset - 1 ?>">‹</a>
                    <span class="range-label">
                        <?= $monday->format('d') ?>&nbsp;<?= $UA_MONTHS[(int)$monday->format('m')] ?>
                        &nbsp;—&nbsp;
                        <?= $sunday->format('d') ?>&nbsp;<?= $UA_MONTHS[(int)$sunday->format('m')] ?>
                        &nbsp;<?= $sunday->format('Y') ?>
                    </span>
                    <a class="nav-arrow" href="?view=week&week=<?= $weekOffset + 1 ?>">›</a>
                    <?php if ($weekOffset !== 0): ?>
                        <a class="today-btn" href="?view=week&week=0">Сьогодні</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="nav-arrow" href="?view=month&month=<?= $monthOffset - 1 ?>">‹</a>
                    <span class="range-label"><?= $UA_MONTHS_CAP[$monthNum] ?>&nbsp;<?= $monthYear ?></span>
                    <a class="nav-arrow" href="?view=month&month=<?= $monthOffset + 1 ?>">›</a>
                    <?php if ($monthOffset !== 0): ?>
                        <a class="today-btn" href="?view=month&month=0">Сьогодні</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-right">
            <button class="add-btn" onclick="openAddModal()">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Додати заняття
            </button>
            <div class="view-toggle">
                <a class="vt-btn <?= $view === 'week' ? 'active' : '' ?>" href="?view=week&week=<?= $weekOffset ?>">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/><line x1="15" y1="9" x2="15" y2="21"/></svg>
                    Тиждень
                </a>
                <a class="vt-btn <?= $view === 'month' ? 'active' : '' ?>" href="?view=month&month=<?= $monthOffset ?>">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Місяць
                </a>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-strip">
        <div class="stat-chip c-purple">
            <span class="stat-chip-icon">📚</span>
            <div>
                <div class="stat-chip-val"><?= $totalLessons ?></div>
                <div class="stat-chip-lbl"><?= $view === 'week' ? 'Занять тижня' : 'Занять місяця' ?></div>
            </div>
        </div>
        <div class="stat-chip c-green">
            <span class="stat-chip-icon">✅</span>
            <div>
                <div class="stat-chip-val"><?= $completedLess ?></div>
                <div class="stat-chip-lbl">Завершено</div>
            </div>
        </div>
        <div class="stat-chip c-teal">
            <span class="stat-chip-icon">🔜</span>
            <div>
                <div class="stat-chip-val"><?= $upcomingLess ?></div>
                <div class="stat-chip-lbl">Заплановано</div>
            </div>
        </div>
    </div>

    <?php if ($view === 'week'): ?>
    <!-- ═══════════════ WEEK VIEW ═══════════════ -->
    <div class="cal-wrap">
        <div class="cal-header">
            <div class="time-gutter"></div>
            <?php for ($i = 0; $i < 7; $i++):
                $dayDt  = (clone $monday)->modify("+$i days");
                $dayStr = $dayDt->format('Y-m-d');
                $isToday   = $dayStr === $todayStr;
                $hasEvents = !empty($byDayIdx[$i]);
            ?>
            <div class="cal-day-hd<?= $isToday ? ' is-today' : '' ?><?= $hasEvents ? ' has-ev' : '' ?>">
                <div class="cal-day-name"><?= $UA_DAYS[$i] ?></div>
                <div class="cal-day-num"><?= $dayDt->format('j') ?></div>
            </div>
            <?php endfor; ?>
        </div>

        <div class="cal-body" id="calBody">
            <div class="time-col" style="height:<?= $totalPx ?>px">
                <?php for ($h = $minHour; $h <= $maxHour; $h++): ?>
                <div class="time-lbl" style="top:<?= ($h - $minHour) * $pxPerHour ?>px"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</div>
                <?php endfor; ?>
            </div>

            <div class="days-grid" style="height:<?= $totalPx ?>px">
                <?php for ($i = 0; $i < 7; $i++):
                    $dayDt  = (clone $monday)->modify("+$i days");
                    $isToday = $dayDt->format('Y-m-d') === $todayStr;
                ?>
                <div class="day-col" style="<?= $isToday ? 'background:rgba(99,102,241,.025)' : '' ?>">
                    <?php for ($h = $minHour; $h <= $maxHour; $h++): ?>
                    <div class="hr-line"  style="top:<?= ($h - $minHour) * $pxPerHour ?>px"></div>
                    <div class="hf-line"  style="top:<?= ($h - $minHour) * $pxPerHour + $pxPerHour / 2 ?>px"></div>
                    <?php endfor; ?>

                    <?php if ($isToday):
                        $nm = (int)$today->format('H') * 60 + (int)$today->format('i');
                        $nt = ($nm / 60 - $minHour) * $pxPerHour;
                        if ($nt >= 0 && $nt <= $totalPx): ?>
                    <div class="now-line" style="top:<?= round($nt) ?>px"><div class="now-dot"></div></div>
                    <?php endif; endif; ?>

                    <?php foreach ($byDayIdx[$i] as $l):
                        $dt     = new DateTime($l['scheduled_at']);
                        $dur    = 60;
                        $topPx  = ((int)$dt->format('H') * 60 + (int)$dt->format('i')) / 60 * $pxPerHour - $minHour * $pxPerHour;
                        $htPx   = $dur / 60 * $pxPerHour - 4;
                        $col    = $colorMap[$l['course_id']] ?? '#6366f1';
                        $ld     = lessonJson($l, $colorMap);
                        $student = !empty($l['student_names']) ? $l['student_names'] : '';
                    ?>
                    <div class="lb"
                         style="top:<?= round($topPx) ?>px;height:<?= round($htPx) ?>px;background:<?= $col ?>1a;border-left-color:<?= $col ?>;border-top:1px solid <?= $col ?>30;border-right:1px solid <?= $col ?>20;border-bottom:1px solid <?= $col ?>20"
                         onclick="showPopup(event, <?= htmlspecialchars($ld, ENT_QUOTES) ?>)"
                         ondblclick="event.stopPropagation(); editLesson(<?= (int)$l['id'] ?>)">
                        <div class="lb-title"><?= htmlspecialchars($l['title']) ?></div>
                        <?php if ($htPx > 40): ?>
                        <div class="lb-course" style="color:<?= $col ?>"><?= htmlspecialchars($l['course_title']) ?></div>
                        <?php endif; ?>
                        <?php if ($htPx > 55 && $student): ?>
                        <div class="lb-student">👤 <?= htmlspecialchars($student) ?></div>
                        <?php endif; ?>
                        <?php if ($htPx > 65): ?>
                        <div class="lb-time" style="color:<?= $col ?>"><?= $dt->format('H:i') ?> · <?= $dur ?> хв</div>
                        <?php endif; ?>
                        <div class="lb-actions">
                            <button class="lb-action edit" title="Редагувати (Клік)"
                                onclick="event.stopPropagation(); editLesson('<?= htmlspecialchars($l['id']) ?>')">✎</button>
                            <button class="lb-action del" title="Видалити"
                                onclick="event.stopPropagation(); deleteLesson('<?= htmlspecialchars($l['id']) ?>')">✕</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <?php if (empty($lessons)): ?>
        <div class="empty-state">
            <div class="empty-icon">🗓</div>
            <div class="empty-title">Цього тижня занять немає</div>
            <div class="empty-sub">
                <?php
                if ($weekOffset === 0)      echo 'Натисніть «Додати заняття» щоб запланувати урок';
                elseif ($weekOffset < 0)    echo 'На цьому тижні занять не було';
                else                        echo 'Заняття ще не заплановані';
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ═══════════════ MONTH VIEW ═══════════════ -->
    <div class="month-wrap">
        <div class="month-grid-hd">
            <?php foreach ($UA_DAYS as $d): ?>
            <div class="month-dh"><?= $d ?></div>
            <?php endforeach; ?>
        </div>

        <?php
        $firstDow    = (int)$monthStart->format('N') - 1;
        $daysInMonth = (int)$monthEnd->format('j');
        $totalCells  = (int)ceil(($firstDow + $daysInMonth) / 7) * 7;
        ?>
        <div class="month-grid">
        <?php for ($cell = 0; $cell < $totalCells; $cell++):
            $dayNum = $cell - $firstDow + 1;
            if ($dayNum < 1) {
                $cellDay = (int)(clone $monthStart)->modify(($dayNum - 1) . ' days')->format('j');
                $otherMo = true;
                $cellStr = (clone $monthStart)->modify(($dayNum - 1) . ' days')->format('Y-m-d');
            } elseif ($dayNum > $daysInMonth) {
                $cellDay = $dayNum - $daysInMonth;
                $otherMo = true;
                $cellStr = (clone $monthEnd)->modify('+' . ($dayNum - $daysInMonth) . ' days')->format('Y-m-d');
            } else {
                $cellDay = $dayNum;
                $otherMo = false;
                $cellStr = sprintf('%04d-%02d-%02d', $monthYear, $monthNum, $cellDay);
            }
            $isToday    = $cellStr === $todayStr;
            $dayLessons = $byDay[$cellStr] ?? [];
        ?>
        <div class="month-cell<?= $otherMo ? ' other-month' : '' ?><?= $isToday ? ' is-today' : '' ?>">
            <div class="cell-num"><?= $cellDay ?></div>
            <?php
            $shown = 0;
            foreach ($dayLessons as $l):
                if ($shown >= 3) break; $shown++;
                $col = $colorMap[$l['course_id']] ?? '#6366f1';
                $dt2 = new DateTime($l['scheduled_at']);
                $ld  = lessonJson($l, $colorMap);
            ?>
            <div class="month-ev"
                 style="background:<?= $col ?>20;color:<?= $col ?>;border-left-color:<?= $col ?>"
                 onclick="showPopup(event, <?= htmlspecialchars($ld, ENT_QUOTES) ?>)">
                <span class="month-ev-text"><?= $dt2->format('H:i') ?> <?= htmlspecialchars(substr($l['title'], 0, 14)) ?></span>
                <div class="month-ev-actions">
                    <button class="month-ev-btn month-ev-edit" title="Редагувати" 
                        onclick="event.stopPropagation(); editLesson('<?= htmlspecialchars($l['id']) ?>')">✎</button>
                    <button class="month-ev-btn month-ev-del" title="Видалити"
                        onclick="event.stopPropagation(); deleteLesson('<?= htmlspecialchars($l['id']) ?>')">✕</button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($dayLessons) > 3): ?>
            <div class="month-more">+<?= count($dayLessons) - 3 ?> ще</div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
        </div>

        <?php if (empty($lessons)): ?>
        <div class="empty-state" style="margin-top:40px">
            <div class="empty-icon">🗓</div>
            <div class="empty-title">Цього місяця занять немає</div>
            <div class="empty-sub">
                <?php
                if ($monthOffset === 0)     echo 'Натисніть «Додати заняття» щоб запланувати урок';
                elseif ($monthOffset < 0)   echo 'Цього місяця занять не було';
                else                        echo 'Заняття ще не заплановані';
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<script>
const studentsByCourse = <?= json_encode($studentsByCourse, JSON_HEX_TAG) ?>;
const popup  = document.getElementById('popup');
const modal  = document.getElementById('modalOverlay');
let currentLessonId = null;

/* ── Popup ── */
function showPopup(e, data) {
    e.stopPropagation();
    currentLessonId = data.id;
    document.getElementById('popBar').style.background    = data.color;
    document.getElementById('popTitle').textContent       = data.title;
    document.getElementById('popDate').textContent        = data.date;
    document.getElementById('popTime').textContent        = data.start + ' — ' + data.end + ' (' + data.dur + ' хв)';
    document.getElementById('popCourse').textContent      = data.course;
    document.getElementById('popStudent').textContent     = data.group || 'Студент не призначений';

    const sb = document.getElementById('popStatus');
    const labels = { scheduled: 'Заплановано', completed: 'Завершено', cancelled: 'Скасовано' };
    sb.textContent = labels[data.status] || data.status;
    sb.className   = 'pop-badge ' + (data.status || 'scheduled');

    const acts = document.getElementById('popActions');
    let html = '';
    if (data.url) {
        html += `<a class="pop-btn pop-join" href="${data.url}" target="_blank">📹 Приєднатись</a>`;
    }
    acts.innerHTML = html;

    /* Позиціонування */
    const vw = window.innerWidth, vh = window.innerHeight, pw = 305, ph = 320;
    let x = e.clientX + 14, y = e.clientY - 20;
    if (x + pw > vw - 10) x = e.clientX - pw - 14;
    if (y + ph > vh - 10) y = vh - ph - 10;
    if (y < 10) y = 10;
    popup.style.left = x + 'px';
    popup.style.top  = y + 'px';
    popup.classList.add('show');
}

function closePopup() { popup.classList.remove('show'); }
document.addEventListener('click', e => { if (!popup.contains(e.target)) closePopup(); });

/* ── Гарячі клавіші для popup ── */
document.addEventListener('keydown', e => {
    if (!popup.classList.contains('show')) return;
    if (e.key === 'Escape') closePopup();
    if (e.key === 'e' || e.key === 'E') { e.preventDefault(); editLesson(currentLessonId); }
    if (e.key === 'Delete') { e.preventDefault(); deleteLesson(currentLessonId); }
});

/* ── Toast ── */
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3000);
}

/* ── Modal ── */
function openAddModal(prefillDate = null) {
    document.getElementById('modalTitle').textContent = '➕ Додати заняття';
    document.getElementById('lessonId').value    = '';
    document.getElementById('lessonTitle').value = '';
    document.getElementById('lessonDesc').value  = '';
    document.getElementById('lessonCourse').value = '';
    document.getElementById('lessonDate').value  = prefillDate || new Date().toISOString().split('T')[0];
    document.getElementById('lessonStart').value = '';
    document.getElementById('lessonUrl').value   = '';
    document.getElementById('lessonStatus').value = 'scheduled';
    document.getElementById('lessonStudent').innerHTML = '<option value="">— Всі студенти курсу —</option>';
    modal.classList.add('show');
    // Фокусуємось на полі з назвою
    setTimeout(() => document.getElementById('lessonTitle').focus(), 100);
}

function closeModal() { modal.classList.remove('show'); }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
});

/* ── Оновлення списку студентів при зміні курсу ── */
document.getElementById('lessonCourse').addEventListener('change', function () {
    const sel = document.getElementById('lessonStudent');
    sel.innerHTML = '<option value="">— Всі студенти курсу —</option>';
    const students = studentsByCourse[this.value] || [];
    students.forEach(s => {
        const o = document.createElement('option');
        o.value = s.id;
        o.textContent = s.name;
        sel.appendChild(o);
    });
});

/* ── Edit ── */
async function editLesson(id) {
    closePopup();
    try {
        const res  = await fetch(`api_lesson.php?action=get&id=${id}`);
        const text = await res.text();
        let l;
        try { l = JSON.parse(text); } catch { showToast('Помилка: неправильна відповідь сервера', 'error'); return; }
        if (!l || l.error) { showToast('Помилка: ' + (l?.error || 'Заняття не знайдено'), 'error'); return; }

        // ВИПРАВЛЕННЯ: парсимо рядок напряму, без конвертації в UTC через Date()
        // "2026-05-04 13:00:00" або "2026-05-04T13:00:00"
        const rawDt   = (l.scheduled_at || '').replace(' ', 'T');
        const dateStr = rawDt.slice(0, 10);   // "2026-05-04"
        const timeStr = rawDt.slice(11, 16);  // "13:00"

        document.getElementById('modalTitle').textContent = '✎ Редагувати заняття';
        document.getElementById('lessonId').value         = l.id;
        document.getElementById('lessonTitle').value      = l.title     || '';
        document.getElementById('lessonDesc').value       = l.description || '';
        document.getElementById('lessonUrl').value        = l.meeting_url || '';
        document.getElementById('lessonDate').value       = dateStr;
        document.getElementById('lessonStart').value      = timeStr;
        document.getElementById('lessonStatus').value     = l.status || 'scheduled';

        /* Виставити курс і оновити студентів */
        const studentId = l.student_id || null;
        document.getElementById('lessonCourse').value = l.course_id || '';
        
        // Оновлюємо список студентів для цього курсу
        const sel = document.getElementById('lessonStudent');
        sel.innerHTML = '<option value="">— Всі студенти курсу —</option>';
        const students = studentsByCourse[l.course_id] || [];
        students.forEach(s => {
            const o = document.createElement('option');
            o.value = s.id;
            o.textContent = s.name;
            sel.appendChild(o);
        });
        
        // Встановлюємо вибраного студента (якщо він був обраний)
        if (studentId) {
            document.getElementById('lessonStudent').value = studentId;
        }

        modal.classList.add('show');
        // Фокусуємось на полі з назвою
        setTimeout(() => document.getElementById('lessonTitle').focus(), 100);
    } catch (err) {
        showToast('Помилка: ' + err.message, 'error');
    }
}

/* ── Delete ── */
async function deleteLesson(id) {
    if (!confirm('Видалити це заняття?')) return;
    closePopup();
    try {
        const res  = await fetch(`api_lesson.php?action=delete&id=${id}`, { method: 'POST' });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch { showToast('Помилка: неправильна відповідь сервера', 'error'); return; }
        if (res.ok && data.success) {
            showToast('Заняття видалено');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Помилка: ' + (data.error || 'Невідома помилка'), 'error');
        }
    } catch (err) {
        showToast('Мережева помилка: ' + err.message, 'error');
    }
}

/* ── Save ── */
async function saveLesson() {
    const id     = document.getElementById('lessonId').value;
    const title  = document.getElementById('lessonTitle').value.trim();
    const course = document.getElementById('lessonCourse').value;
    const date   = document.getElementById('lessonDate').value;
    const start  = document.getElementById('lessonStart').value;

    if (!title)  { showToast('Введіть назву заняття', 'error'); return; }
    if (!course) { showToast('Оберіть курс', 'error'); return; }
    if (!date)   { showToast('Оберіть дату', 'error'); return; }
    if (!start)  { showToast('Вкажіть час початку', 'error'); return; }

    const fd = new FormData();
    fd.append('action',      id ? 'update' : 'add');
    fd.append('id',          id);
    fd.append('title',       title);
    fd.append('description', document.getElementById('lessonDesc').value);
    fd.append('course_id',   course);
    fd.append('student_id',  document.getElementById('lessonStudent').value);
    fd.append('date',        date);
    fd.append('start_time',  start);
    fd.append('meeting_url', document.getElementById('lessonUrl').value.trim());
    fd.append('status',      document.getElementById('lessonStatus').value);

    try {
        const res  = await fetch('api_lesson.php', { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch { showToast('Помилка сервера: неправильна відповідь', 'error'); return; }
        if (res.ok && data.success) {
            closeModal();
            showToast(id ? 'Заняття оновлено ✓' : 'Заняття додано ✓');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Помилка: ' + (data.error || 'Помилка збереження'), 'error');
        }
    } catch (err) {
        showToast('Мережева помилка: ' + err.message, 'error');
    }
}

/* ── Scroll to now ── */
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('calBody');
    if (!body) return;
    const nowLine = body.querySelector('.now-line');
    body.scrollTop = nowLine
        ? Math.max(0, parseInt(nowLine.style.top) - 120)
        : Math.max(0, (9 - <?= $minHour ?>) * <?= $pxPerHour ?> - 60);
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>