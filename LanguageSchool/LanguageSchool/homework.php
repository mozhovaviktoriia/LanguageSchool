<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacherId = $_SESSION['user_id'];
$taskId    = $_GET['task_id'] ?? '';

if (!$taskId) {
    header('Location: tasks.php');
    exit;
}

/* ── Teacher info ── */
$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $teacherId]);
$teacherUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$teacherName = trim(($teacherUser['first_name'] ?? '') . ' ' . ($teacherUser['last_name'] ?? '')) ?: 'Викладач';
$initials    = strtoupper(substr($teacherUser['first_name'] ?? '', 0, 1) . substr($teacherUser['last_name'] ?? '', 0, 1)) ?: '👨‍🏫';

/* ── Task details ── */
$stmtTask = $pdo->prepare("
    SELECT t.*,
        l.title        AS lesson_title,
        l.scheduled_at AS lesson_date,
        c.id           AS course_id,
        c.title        AS course_title,
        lang.name_ua   AS lang_name,
        lang.code      AS lang_code
    FROM tasks t
    LEFT JOIN lessons l      ON t.lesson_id   = l.id
    LEFT JOIN courses c      ON c.id = l.course_id
    LEFT JOIN languages lang ON c.language_id = lang.id
    WHERE t.id = :id AND t.created_by = :tid
    LIMIT 1
");
$stmtTask->execute(['id' => $taskId, 'tid' => $teacherId]);
$task = $stmtTask->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header('Location: tasks.php');
    exit;
}

/* ── Handle POST: grade / return ── */
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $submissionId = $_POST['submission_id'] ?? '';
    $score        = $_POST['score'] !== '' ? (float)$_POST['score'] : null;
    $feedback     = trim($_POST['feedback'] ?? '');
    $statusRaw    = $_POST['status'] ?? 'reviewed';
    $status       = ($statusRaw === 'returned') ? 'assigned' : 'reviewed';

    if ($action === 'grade' && $submissionId) {
        $chk = $pdo->prepare("
            SELECT ts.id, t.max_score FROM task_submissions ts
            JOIN tasks t ON ts.task_id = t.id
            WHERE ts.id = :sid AND t.id = :tid AND t.created_by = :teacher
        ");
        $chk->execute(['sid' => $submissionId, 'tid' => $taskId, 'teacher' => $teacherId]);
        $sub = $chk->fetch(PDO::FETCH_ASSOC);

        if ($sub) {
            if ($score !== null && ($score < 0 || $score > $sub['max_score'])) {
                $flashError = 'Оцінка виходить за межі допустимого діапазону (0–' . $sub['max_score'] . ').';
            } else {
                $pdo->prepare("
                    UPDATE task_submissions
                    SET score = :score, feedback = :fb,
                        status = :status::task_status,
                        reviewed_by = :rev, reviewed_at = NOW()
                    WHERE id = :sid
                ")->execute([
                    'score'  => $score,
                    'fb'     => $feedback,
                    'status' => $status,
                    'rev'    => $teacherId,
                    'sid'    => $submissionId,
                ]);
                $flashSuccess = $status === 'assigned'
                    ? 'Роботу повернено учню на доопрацювання.'
                    : 'Оцінку збережено.';
            }
        } else {
            $flashError = 'Відповідь не знайдена.';
        }
    }
    header("Location: homework.php?task_id=" . urlencode($taskId) .
           ($flashSuccess ? '&ok=1' : '&err=' . urlencode($flashError)));
    exit;
}

if (isset($_GET['ok']))  $flashSuccess = 'Збережено успішно.';
if (isset($_GET['err'])) $flashError   = htmlspecialchars($_GET['err']);

/* ── Submissions ── */
$stmtSubs = $pdo->prepare("
    SELECT
        ts.id, ts.student_id, ts.content_text, ts.file_url,
        ts.audio_url, ts.video_url,
        ts.score, ts.feedback, ts.status,
        ts.submitted_at, ts.reviewed_at,
        u.first_name, u.last_name, u.avatar_url, u.email
    FROM task_submissions ts
    JOIN users u ON ts.student_id = u.id
    WHERE ts.task_id = :tid
    ORDER BY
        CASE ts.status WHEN 'submitted' THEN 0 WHEN 'assigned' THEN 1 ELSE 2 END,
        ts.submitted_at ASC
");
$stmtSubs->execute(['tid' => $taskId]);
$submissions = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

$totalSubs    = count($submissions);
$pendingSubs  = count(array_filter($submissions, fn($s) => $s['status'] === 'submitted'));
$gradedSubs   = count(array_filter($submissions, fn($s) => $s['status'] === 'reviewed'));
$returnedSubs = count(array_filter($submissions, fn($s) => $s['status'] === 'assigned'));
$scores       = array_filter(array_column($submissions, 'score'), fn($v) => $v !== null);
$avgScore     = $scores ? round(array_sum($scores) / count($scores), 1) : null;

/* Active submission from URL */
$activeSubId = (int)($_GET['sub'] ?? 0);
if (!$activeSubId && $pendingSubs > 0) {
    foreach ($submissions as $s) {
        if ($s['status'] === 'submitted') { $activeSubId = $s['id']; break; }
    }
}

/* Active submission data */
$activeSub = null;
foreach ($submissions as $s) {
    if ($s['id'] == $activeSubId) { $activeSub = $s; break; }
}

/* helpers */
function typeIcon(string $t): string {
    return match($t) { 'homework'=>'📋','classwork'=>'✏️','project'=>'🗂️','essay'=>'📝','test'=>'📊','quiz'=>'🧩', default=>'📄' };
}
function typeLabel(string $t): string {
    return match($t) { 'homework'=>'Д/З','classwork'=>'Класна','project'=>'Проект','essay'=>'Есе','test'=>'Тест','quiz'=>'Квіз', default=>ucfirst($t) };
}
function statusLabel(string $s): string {
    return match($s) { 'submitted'=>'На перевірці','reviewed'=>'Оцінено','assigned'=>'Повернено', default=>ucfirst($s) };
}
function statusClass(string $s): string {
    return match($s) { 'reviewed'=>'s-graded','assigned'=>'s-returned', default=>'s-pending' };
}
function gradeColor(float $pct): string {
    return match(true) { $pct>=90=>'#22c55e', $pct>=75=>'#22d3ee', $pct>=60=>'#f59e0b', $pct>=40=>'#fca5a5', default=>'#ef4444' };
}
function gradeLetter(float $pct): string {
    return match(true) { $pct>=90=>'A', $pct>=75=>'B', $pct>=60=>'C', $pct>=40=>'D', default=>'F' };
}
$flags = ['en'=>'🇬🇧','de'=>'🇩🇪','ja'=>'🇯🇵','fr'=>'🇫🇷'];
$flag  = $flags[$task['lang_code'] ?? ''] ?? '🌐';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($task['title']) ?> — Перевірка</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root {
    --bg:#07080f; --surface:#0d1117; --card:#111827; --border:#1e293b;
    --accent:#6366f1; --teal:#22d3ee; --green:#22c55e; --amber:#f59e0b;
    --red:#ef4444; --text:#e2e8f0; --muted:#64748b;
    --font:'Syne',sans-serif; --mono:'JetBrains Mono',monospace;
    --sidebar:220px;
}
body { font-family:var(--font); background:var(--bg); color:var(--text); min-height:100vh; display:flex; overflow:hidden; }
body::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 70% 50% at 8% 10%,rgba(99,102,241,.12) 0%,transparent 55%),radial-gradient(ellipse 50% 40% at 90% 85%,rgba(34,211,238,.09) 0%,transparent 55%); pointer-events:none; z-index:0; }

/* ── Sidebar ── */
.sidebar { position:fixed; top:0; left:0; width:var(--sidebar); height:100vh; background:rgba(13,17,23,.92); backdrop-filter:blur(20px); border-right:1px solid var(--border); display:flex; flex-direction:column; padding:28px 18px; z-index:100; gap:6px; }
.logo { display:flex; align-items:center; gap:10px; padding:0 6px 24px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.logo-icon { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--teal)); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; }
.logo-text { font-size:16px; font-weight:800; background:linear-gradient(90deg,#a5b4fc,var(--teal)); background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.nav-label { font-family:var(--mono); font-size:9px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; padding:0 8px; margin:10px 0 4px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; text-decoration:none; color:var(--muted); font-size:13px; font-weight:600; transition:.2s; border:1px solid transparent; }
.nav-item:hover, .nav-item.active { background:rgba(99,102,241,.12); color:var(--text); border-color:rgba(99,102,241,.25); }
.nav-item.active { color:#a5b4fc; }
.nav-icon { font-size:15px; width:20px; text-align:center; }
.sidebar-bottom { margin-top:auto; padding-top:16px; border-top:1px solid var(--border); }
.logout { display:block; padding:9px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.2); border-radius:10px; color:#fca5a5; font-family:var(--mono); font-size:11px; font-weight:600; text-decoration:none; text-align:center; transition:.2s; }
.logout:hover { background:rgba(239,68,68,.18); }

/* ── Layout ── */
.page { margin-left:var(--sidebar); flex:1; display:grid; grid-template-rows:auto 1fr; min-height:100vh; position:relative; z-index:1; overflow:hidden; }
.topbar { display:flex; align-items:center; gap:12px; padding:14px 24px; border-bottom:1px solid var(--border); background:rgba(13,17,23,.9); backdrop-filter:blur(20px); z-index:50; }
.topbar-back { display:flex; align-items:center; gap:7px; padding:7px 14px; border-radius:9px; background:var(--surface); border:1px solid var(--border); color:var(--muted); font-size:12px; font-weight:700; text-decoration:none; transition:.2s; }
.topbar-back:hover { color:var(--text); border-color:rgba(99,102,241,.4); }
.topbar-breadcrumb { font-family:var(--mono); font-size:11px; color:var(--muted); }
.topbar-breadcrumb span { color:var(--text); }
.topbar-right { margin-left:auto; display:flex; align-items:center; gap:8px; }

/* ── Three-column body ── */
.body-wrap { display:grid; grid-template-columns:300px 1fr 340px; height:calc(100vh - 57px); overflow:hidden; }

/* ── LEFT: submission list ── */
.sub-list-panel { border-right:1px solid var(--border); overflow-y:auto; background:rgba(13,17,23,.5); }
.sub-list-header { padding:16px 16px 12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(13,17,23,.95); backdrop-filter:blur(10px); z-index:5; }
.sub-list-title { font-size:13px; font-weight:800; margin-bottom:10px; }
.sub-counts-row { display:flex; gap:6px; flex-wrap:wrap; }
.s-badge { font-family:var(--mono); font-size:9px; font-weight:700; padding:3px 8px; border-radius:99px; }
.s-badge.pending  { background:rgba(245,158,11,.15); color:var(--amber); }
.s-badge.graded   { background:rgba(34,197,94,.15);  color:var(--green); }
.s-badge.returned { background:rgba(239,68,68,.15);  color:#fca5a5; }
.s-badge.neutral  { background:rgba(255,255,255,.06); color:var(--muted); }

.sub-list-item { display:flex; align-items:center; gap:11px; padding:13px 16px; border-bottom:1px solid rgba(30,41,59,.5); cursor:pointer; transition:.15s; text-decoration:none; color:inherit; }
.sub-list-item:hover { background:rgba(255,255,255,.03); }
.sub-list-item.active { background:rgba(99,102,241,.1); border-left:3px solid var(--accent); }
.sub-list-item.active .sli-name { color:#a5b4fc; }
.sli-avatar { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal)); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:12px; color:#fff; flex-shrink:0; overflow:hidden; border:2px solid transparent; }
.sub-list-item.active .sli-avatar { border-color:rgba(99,102,241,.5); }
.sli-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
.sli-name { font-size:12px; font-weight:700; }
.sli-date { font-family:var(--mono); font-size:9px; color:var(--muted); margin-top:2px; }
.sli-right { margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
.sli-score { font-family:var(--mono); font-size:11px; font-weight:800; }
.status-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.dot-pending  { background:var(--amber); box-shadow:0 0 5px rgba(245,158,11,.6); }
.dot-graded   { background:var(--green); box-shadow:0 0 5px rgba(34,197,94,.6); }
.dot-returned { background:var(--red);   box-shadow:0 0 5px rgba(239,68,68,.6); }

.sub-list-empty { padding:32px 16px; text-align:center; font-family:var(--mono); font-size:11px; color:var(--muted); }

/* ── MIDDLE: task desc + student answer ── */
.middle-panel { overflow-y:auto; display:flex; flex-direction:column; }

.task-info-block { padding:20px 24px; border-bottom:1px solid var(--border); background:rgba(99,102,241,.04); }
.task-info-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
.task-info-title  { font-size:17px; font-weight:800; }
.task-info-badges { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
.task-badge { display:inline-flex; align-items:center; gap:4px; font-family:var(--mono); font-size:9px; font-weight:700; padding:3px 9px; border-radius:99px; }
.tb-type     { background:rgba(99,102,241,.12); color:#a5b4fc; }
.tb-course   { background:rgba(34,211,238,.10); color:var(--teal); }
.tb-deadline { background:rgba(245,158,11,.12); color:var(--amber); }
.tb-deadline.overdue { background:rgba(239,68,68,.12); color:#fca5a5; }
.tb-score    { background:rgba(34,197,94,.1);   color:var(--green); }
.task-description { font-size:12px; color:var(--muted); line-height:1.75; }
.task-file-link { display:inline-flex; align-items:center; gap:8px; margin-top:12px; padding:8px 14px; border-radius:9px; background:rgba(34,211,238,.07); border:1px solid rgba(34,211,238,.2); color:var(--teal); font-family:var(--mono); font-size:10px; text-decoration:none; transition:.2s; }
.task-file-link:hover { background:rgba(34,211,238,.14); }

/* Student answer panel */
.student-answer-panel { flex:1; padding:20px 24px; }
.panel-label { font-family:var(--mono); font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; }
.panel-label span { color:var(--text); }

.no-sub-state { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; height:200px; text-align:center; }
.no-sub-icon  { font-size:36px; opacity:.3; }
.no-sub-text  { font-family:var(--mono); font-size:11px; color:var(--muted); }

.answer-text-block { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:18px; font-size:13px; line-height:1.8; white-space:pre-wrap; color:var(--text); min-height:120px; margin-bottom:14px; }

.student-file-card { display:flex; align-items:center; gap:12px; padding:14px 16px; background:var(--surface); border:1px solid var(--border); border-radius:12px; text-decoration:none; color:inherit; transition:.2s; margin-bottom:10px; }
.student-file-card:hover { border-color:rgba(99,102,241,.4); background:rgba(99,102,241,.05); }
.file-icon-big { font-size:28px; }
.file-meta-name { font-size:13px; font-weight:700; }
.file-meta-sub  { font-family:var(--mono); font-size:10px; color:var(--muted); margin-top:2px; }
.file-dl { margin-left:auto; font-family:var(--mono); font-size:10px; color:var(--teal); }

.submitted-info { display:flex; align-items:center; gap:12px; margin-top:8px; padding:10px 14px; background:rgba(255,255,255,.03); border-radius:10px; }
.submitted-info-item { font-family:var(--mono); font-size:10px; color:var(--muted); }
.submitted-info-item strong { color:var(--text); }

/* ── RIGHT: grading panel ── */
.grade-panel { border-left:1px solid var(--border); overflow-y:auto; background:rgba(13,17,23,.5); }
.grade-panel-header { padding:16px 18px 12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(13,17,23,.95); backdrop-filter:blur(10px); z-index:5; }
.grade-panel-title { font-size:13px; font-weight:800; margin-bottom:4px; }
.grade-panel-sub { font-family:var(--mono); font-size:10px; color:var(--muted); }

.grade-form { padding:16px 18px; }
.gf-section { margin-bottom:20px; }
.gf-label { font-family:var(--mono); font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; display:block; }

/* Score input with visual ring */
.score-row { display:flex; align-items:center; gap:14px; margin-bottom:6px; }
.score-input-wrap { position:relative; }
.score-input { width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,.05); border:2px solid var(--border); color:var(--text); font-family:var(--mono); font-size:22px; font-weight:800; text-align:center; outline:none; transition:.2s; }
.score-input:focus { border-color:var(--accent); box-shadow:0 0 0 4px rgba(99,102,241,.15); }
.score-ring-label { font-family:var(--mono); font-size:10px; color:var(--muted); margin-top:4px; text-align:center; }
.score-max-info { flex:1; }
.score-max-num { font-size:26px; font-weight:800; color:var(--muted); line-height:1; }
.score-max-label { font-family:var(--mono); font-size:10px; color:var(--muted); }
.score-pct-display { font-family:var(--mono); font-size:13px; font-weight:700; margin-top:6px; }

.feedback-textarea { width:100%; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:10px; padding:12px 14px; color:var(--text); font-family:var(--font); font-size:12px; line-height:1.65; resize:vertical; min-height:110px; outline:none; transition:.2s; }
.feedback-textarea:focus { border-color:var(--accent); background:rgba(99,102,241,.05); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.feedback-textarea::placeholder { color:var(--muted); }

/* Quick feedback chips */
.quick-chips { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:10px; }
.chip { font-family:var(--mono); font-size:9px; padding:4px 10px; border-radius:99px; border:1px solid var(--border); color:var(--muted); cursor:pointer; transition:.15s; background:none; }
.chip:hover { border-color:rgba(99,102,241,.5); color:#a5b4fc; background:rgba(99,102,241,.08); }

.grade-actions { display:flex; flex-direction:column; gap:8px; }
.btn-save { display:flex; align-items:center; justify-content:center; gap:8px; padding:11px; border-radius:11px; background:linear-gradient(135deg,var(--accent),#818cf8); color:#fff; font-family:var(--font); font-size:13px; font-weight:800; border:none; cursor:pointer; transition:.2s; box-shadow:0 4px 16px rgba(99,102,241,.3); }
.btn-save:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,.4); }
.btn-return { display:flex; align-items:center; justify-content:center; gap:8px; padding:10px; border-radius:11px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.25); color:#fca5a5; font-family:var(--font); font-size:12px; font-weight:700; cursor:pointer; transition:.2s; }
.btn-return:hover { background:rgba(239,68,68,.16); border-color:rgba(239,68,68,.5); }

/* Previous score display */
.prev-score-block { padding:12px 14px; background:rgba(34,197,94,.06); border:1px solid rgba(34,197,94,.18); border-radius:10px; margin-bottom:16px; }
.prev-score-label { font-family:var(--mono); font-size:9px; color:var(--green); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.prev-score-val { font-family:var(--mono); font-size:22px; font-weight:800; color:var(--green); }

/* No submission selected state */
.no-grade-state { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:12px; padding:32px; text-align:center; }
.no-grade-icon { font-size:40px; opacity:.25; }
.no-grade-text { font-family:var(--mono); font-size:11px; color:var(--muted); line-height:1.7; }

/* Flash messages */
.flash { display:flex; align-items:center; gap:10px; padding:10px 16px; border-radius:9px; font-family:var(--mono); font-size:11px; margin:12px 18px 0; }
.flash.ok  { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.25); color:var(--green); }
.flash.err { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#fca5a5; }

/* Stats mini-bar */
.stats-bar { display:flex; gap:0; border-bottom:1px solid var(--border); }
.stat-mini { flex:1; padding:12px 16px; text-align:center; border-right:1px solid var(--border); }
.stat-mini:last-child { border-right:none; }
.sm-num   { font-family:var(--mono); font-size:18px; font-weight:800; line-height:1; }
.sm-label { font-family:var(--mono); font-size:8px; color:var(--muted); margin-top:3px; text-transform:uppercase; letter-spacing:.5px; }

@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.student-answer-panel, .grade-form { animation:fadeIn .2s ease; }
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">👨‍🏫</div>
        <div class="logo-text">EduSpace</div>
    </div>
    <span class="nav-label">Меню</span>
    <a class="nav-item" href="dashboard_teacher.php"><span class="nav-icon">📚</span> Мої курси</a>
    <a class="nav-item" href="students.php"><span class="nav-icon">👨‍🎓</span> Мої учні</a>
    <a class="nav-item" href="schedule_teacher.php"><span class="nav-icon">📅</span> Розклад</a>
    <a class="nav-item active" href="tasks.php"><span class="nav-icon">✓</span> Завдання</a>
    <a class="nav-item" href="tests.php"><span class="nav-icon">📝</span> Тести</a>
    <a class="nav-item" href="chat.php"><span class="nav-icon">💬</span> Чат</a>
    <a class="nav-item" href="https://meet.google.com" target="_blank"><span class="nav-icon">📞</span> Meet</a>
    <div class="sidebar-bottom">        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px">
            <span class="theme-toggle-icon">☀️</span>
        </button>        <a class="logout" href="logout.php">🚪 Вийти</a>
    </div>
</aside>

<!-- ── Page ── -->
<div class="page">

    <!-- Topbar -->
    <div class="topbar">
        <a class="topbar-back" href="tasks.php">← Назад</a>
        <div class="topbar-breadcrumb">
            Завдання / <span><?= htmlspecialchars($task['title']) ?></span>
        </div>
        <div class="topbar-right">
            <span style="font-family:var(--mono);font-size:10px;color:var(--muted)">
                <?= $flag ?> <?= htmlspecialchars($task['lang_name'] ?? $task['course_title'] ?? '') ?>
            </span>
        </div>
    </div>

    <!-- Three-column body -->
    <div class="body-wrap">

        <!-- ═══ LEFT: Submission list ═══ -->
        <div class="sub-list-panel">
            <div class="sub-list-header">
                <div class="sub-list-title">Відповіді учнів</div>
                <div class="sub-counts-row">
                    <span class="s-badge pending"><?= $pendingSubs ?> нових</span>
                    <span class="s-badge graded"><?= $gradedSubs ?> оцінено</span>
                    <?php if ($returnedSubs): ?>
                    <span class="s-badge returned"><?= $returnedSubs ?> повернено</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats mini-bar -->
            <div class="stats-bar">
                <div class="stat-mini">
                    <div class="sm-num" style="color:#a5b4fc"><?= $totalSubs ?></div>
                    <div class="sm-label">Всього</div>
                </div>
                <div class="stat-mini">
                    <div class="sm-num" style="color:var(--amber)"><?= $pendingSubs ?></div>
                    <div class="sm-label">Нових</div>
                </div>
                <div class="stat-mini">
                    <div class="sm-num" style="color:var(--green)"><?= $avgScore ?? '—' ?></div>
                    <div class="sm-label">Серед. бал</div>
                </div>
            </div>

            <?php if ($flashSuccess): ?>
            <div class="flash ok">✓ <?= htmlspecialchars($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
            <div class="flash err">✕ <?= htmlspecialchars($flashError) ?></div>
            <?php endif; ?>

            <?php if (empty($submissions)): ?>
            <div class="sub-list-empty">📭<br>Відповідей ще немає</div>
            <?php else: ?>
            <?php foreach ($submissions as $s):
                $sInit  = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
                $sDate  = (new DateTime($s['submitted_at']))->format('d.m H:i');
                $dotCls = match($s['status']) { 'reviewed'=>'dot-graded','assigned'=>'dot-returned', default=>'dot-pending' };
                $pct    = ($s['score'] !== null && $task['max_score'] > 0) ? round($s['score'] / $task['max_score'] * 100) : null;
                $scoreColor = $pct !== null ? gradeColor($pct) : 'var(--muted)';
                $isActive = ($s['id'] == $activeSubId);
            ?>
            <a href="homework.php?task_id=<?= urlencode($taskId) ?>&sub=<?= $s['id'] ?>"
               class="sub-list-item <?= $isActive ? 'active' : '' ?>">
                <div class="sli-avatar">
                    <?php if (!empty($s['avatar_url']) && file_exists($s['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($s['avatar_url']) ?>" alt="">
                    <?php else: ?>
                        <?= $sInit ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="sli-name"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                    <div class="sli-date"><?= $sDate ?></div>
                </div>
                <div class="sli-right">
                    <?php if ($s['score'] !== null): ?>
                    <div class="sli-score" style="color:<?= $scoreColor ?>">
                        <?= $s['score'] ?>/<?= (int)$task['max_score'] ?>
                    </div>
                    <?php endif; ?>
                    <div class="status-dot <?= $dotCls ?>"></div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ═══ MIDDLE: Task + Answer ═══ -->
        <div class="middle-panel">

            <!-- Task description -->
            <div class="task-info-block">
                <div class="task-info-header">
                    <div>
                        <div class="task-info-title">
                            <?= typeIcon($task['task_type']) ?> <?= htmlspecialchars($task['title']) ?>
                        </div>
                    </div>
                </div>
                <div class="task-info-badges">
                    <span class="task-badge tb-type"><?= typeLabel($task['task_type']) ?></span>
                    <?php if ($task['course_title']): ?>
                    <span class="task-badge tb-course"><?= $flag ?> <?= htmlspecialchars($task['course_title']) ?></span>
                    <?php endif; ?>
                    <?php if ($task['lesson_title']): ?>
                    <span class="task-badge tb-course" style="background:rgba(99,102,241,.1);color:#a5b4fc">
                        📖 <?= htmlspecialchars($task['lesson_title']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="task-badge tb-score">макс. <?= (int)$task['max_score'] ?> б.</span>
                    <?php if ($task['deadline']):
                        $isOverdue = strtotime($task['deadline']) < time();
                    ?>
                    <span class="task-badge tb-deadline <?= $isOverdue ? 'overdue' : '' ?>">
                        <?= $isOverdue ? '⚠' : '⏱' ?>
                        Дедлайн: <?= (new DateTime($task['deadline']))->format('d.m.Y H:i') ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($task['description']): ?>
                <div class="task-description"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                <?php endif; ?>

                <?php if (!empty($task['file_url'])): ?>
                <a href="<?= htmlspecialchars($task['file_url']) ?>" class="task-file-link" target="_blank">
                    📎 Матеріали до завдання
                </a>
                <?php endif; ?>
            </div>

            <!-- Student answer -->
            <div class="student-answer-panel">
                <?php if (!$activeSub): ?>
                <div class="no-sub-state">
                    <div class="no-sub-icon">👈</div>
                    <div class="no-sub-text">Оберіть учня зліва,<br>щоб переглянути відповідь</div>
                </div>
                <?php else:
                    $subDt  = new DateTime($activeSub['submitted_at']);
                    $revDt  = $activeSub['reviewed_at'] ? new DateTime($activeSub['reviewed_at']) : null;
                    $pct    = ($activeSub['score'] !== null && $task['max_score'] > 0)
                              ? round($activeSub['score'] / $task['max_score'] * 100) : null;
                ?>
                <div class="panel-label">
                    Відповідь:
                    <span><?= htmlspecialchars($activeSub['first_name'].' '.$activeSub['last_name']) ?></span>
                    <span class="s-badge <?= match($activeSub['status']) { 'reviewed'=>'graded','assigned'=>'returned', default=>'pending' } ?>">
                        <?= statusLabel($activeSub['status']) ?>
                    </span>
                </div>

                <!-- Text answer -->
                <?php if ($activeSub['content_text']): ?>
                <div class="answer-text-block"><?= htmlspecialchars($activeSub['content_text']) ?></div>
                <?php else: ?>
                <div class="answer-text-block" style="color:var(--muted);font-style:italic;font-size:12px;">
                    Текстова відповідь відсутня
                </div>
                <?php endif; ?>

                <!-- Attached file -->
                <?php if ($activeSub['file_url']):
                    $fname = basename($activeSub['file_url']);
                    $ext   = strtoupper(pathinfo($fname, PATHINFO_EXTENSION));
                    $ficon = match($ext) { 'PDF'=>'📄','DOC','DOCX'=>'📝','ZIP','RAR'=>'🗜️','JPG','JPEG','PNG'=>'🖼️', default=>'📎' };
                ?>
                <a href="<?= htmlspecialchars($activeSub['file_url']) ?>" class="student-file-card" target="_blank">
                    <div class="file-icon-big"><?= $ficon ?></div>
                    <div>
                        <div class="file-meta-name"><?= htmlspecialchars($fname) ?></div>
                        <div class="file-meta-sub"><?= $ext ?> · Прикріплений файл</div>
                    </div>
                    <div class="file-dl">↓ Завантажити</div>
                </a>
                <?php endif; ?>

                <!-- Audio / Video -->
                <?php if (!empty($activeSub['audio_url'])): ?>
                <div style="margin-bottom:10px;">
                    <div class="panel-label" style="margin-bottom:6px">🎙 Аудіо-відповідь</div>
                    <audio controls src="<?= htmlspecialchars($activeSub['audio_url']) ?>"
                           style="width:100%;border-radius:10px;"></audio>
                </div>
                <?php endif; ?>
                <?php if (!empty($activeSub['video_url'])): ?>
                <a href="<?= htmlspecialchars($activeSub['video_url']) ?>" class="student-file-card" target="_blank">
                    <div class="file-icon-big">🎬</div>
                    <div><div class="file-meta-name">Відео-відповідь</div><div class="file-meta-sub">Натисніть для перегляду</div></div>
                    <div class="file-dl">▶ Дивитись</div>
                </a>
                <?php endif; ?>

                <!-- Submission meta -->
                <div class="submitted-info">
                    <div class="submitted-info-item">📅 Здано: <strong><?= $subDt->format('d.m.Y H:i') ?></strong></div>
                    <?php if ($revDt): ?>
                    <div class="submitted-info-item">✓ Перевірено: <strong><?= $revDt->format('d.m.Y H:i') ?></strong></div>
                    <?php endif; ?>
                    <?php if ($activeSub['score'] !== null && $pct !== null): ?>
                    <div class="submitted-info-item" style="color:<?= gradeColor($pct) ?>;font-weight:800">
                        <?= gradeLetter($pct) ?> · <?= $pct ?>%
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ RIGHT: Grading panel ═══ -->
        <div class="grade-panel">
            <?php if (!$activeSub): ?>
            <div class="no-grade-state">
                <div class="no-grade-icon">📋</div>
                <div class="no-grade-text">Оберіть відповідь<br>для оцінювання</div>
            </div>
            <?php else: ?>

            <div class="grade-panel-header">
                <div class="grade-panel-title">Оцінювання</div>
                <div class="grade-panel-sub"><?= htmlspecialchars($activeSub['first_name'].' '.$activeSub['last_name']) ?></div>
            </div>

            <?php if ($activeSub['score'] !== null):
                $prevPct = round($activeSub['score'] / max($task['max_score'], 1) * 100);
            ?>
            <div style="padding:12px 18px 0">
                <div class="prev-score-block">
                    <div class="prev-score-label">Поточна оцінка</div>
                    <div class="prev-score-val"><?= $activeSub['score'] ?> / <?= (int)$task['max_score'] ?></div>
                    <div style="font-family:var(--mono);font-size:10px;color:var(--muted);margin-top:2px">
                        <?= gradeLetter($prevPct) ?> · <?= $prevPct ?>% · <?= statusLabel($activeSub['status']) ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" class="grade-form"
                  action="homework.php?task_id=<?= urlencode($taskId) ?>&sub=<?= $activeSub['id'] ?>">
                <input type="hidden" name="action"        value="grade">
                <input type="hidden" name="submission_id" value="<?= $activeSub['id'] ?>">

                <!-- Score -->
                <div class="gf-section">
                    <label class="gf-label">Оцінка</label>
                    <div class="score-row">
                        <div class="score-input-wrap">
                            <input class="score-input" type="number" name="score" id="scoreInput"
                                   min="0" max="<?= (int)$task['max_score'] ?>" step="0.5"
                                   value="<?= $activeSub['score'] !== null ? htmlspecialchars($activeSub['score']) : '' ?>"
                                   placeholder="0"
                                   oninput="updateScorePct(this.value)">
                            <div class="score-ring-label">балів</div>
                        </div>
                        <div class="score-max-info">
                            <div class="score-max-num">/ <?= (int)$task['max_score'] ?></div>
                            <div class="score-max-label">максимум</div>
                            <div class="score-pct-display" id="scorePctDisplay">
                                <?php if ($activeSub['score'] !== null):
                                    $sp = round($activeSub['score'] / max($task['max_score'],1) * 100);
                                    echo '<span style="color:' . gradeColor($sp) . '">' . gradeLetter($sp) . ' · ' . $sp . '%</span>';
                                endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick score buttons -->
                <div class="gf-section">
                    <label class="gf-label">Швидко</label>
                    <div class="quick-chips">
                        <?php
                        $max = (int)$task['max_score'];
                        $pcts = [100, 90, 75, 60, 50, 0];
                        foreach ($pcts as $p):
                            $v = round($max * $p / 100, 1);
                        ?>
                        <button type="button" class="chip"
                                onclick="setScore(<?= $v ?>, <?= $max ?>)">
                            <?= $p ?>% (<?= $v ?>)
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Feedback -->
                <div class="gf-section">
                    <label class="gf-label">Відгук для учня</label>
                    <div class="quick-chips">
                        <?php
                        $chips = [
                            'Чудова робота! 🌟',
                            'Добре, але є помилки',
                            'Потрібно доопрацювати',
                            'Не вистачає деталей',
                            'Правильна структура ✓',
                            'Зверни увагу на граматику',
                        ];
                        foreach ($chips as $chip): ?>
                        <button type="button" class="chip"
                                onclick="appendFeedback(<?= json_encode($chip) ?>)">
                            <?= htmlspecialchars($chip) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <textarea class="feedback-textarea" name="feedback" id="feedbackArea"
                              placeholder="Напишіть зворотній зв'язок для учня..."><?= htmlspecialchars($activeSub['feedback'] ?? '') ?></textarea>
                </div>

                <!-- Actions -->
                <div class="grade-actions">
                    <button type="submit" name="status" value="reviewed" class="btn-save">
                        ✓ Зберегти оцінку
                    </button>
                    <button type="submit" name="status" value="returned" class="btn-return"
                            onclick="return checkReturn()">
                        ↩ Повернути на доопрацювання
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

    </div><!-- /body-wrap -->
</div><!-- /page -->

<script>
const maxScore = <?= (int)$task['max_score'] ?>;

function updateScorePct(val) {
    const el = document.getElementById('scorePctDisplay');
    if (!el) return;
    const v = parseFloat(val);
    if (isNaN(v) || val === '') { el.innerHTML = ''; return; }
    const pct = Math.round(v / maxScore * 100);
    const colors = [[90,'#22c55e'],[75,'#22d3ee'],[60,'#f59e0b'],[40,'#fca5a5'],[0,'#ef4444']];
    const letters = [[90,'A'],[75,'B'],[60,'C'],[40,'D'],[0,'F']];
    const color  = (colors.find(([t]) => pct >= t) || ['','#ef4444'])[1];
    const letter = (letters.find(([t]) => pct >= t) || ['','F'])[1];
    el.innerHTML = `<span style="color:${color}">${letter} · ${pct}%</span>`;
}

function setScore(val, max) {
    const inp = document.getElementById('scoreInput');
    if (inp) { inp.value = val; updateScorePct(val); }
}

function appendFeedback(text) {
    const ta = document.getElementById('feedbackArea');
    if (!ta) return;
    ta.value = ta.value ? ta.value + '\n' + text : text;
    ta.focus();
}

function checkReturn() {
    const ta = document.getElementById('feedbackArea');
    if (!ta || !ta.value.trim()) {
        alert('Будь ласка, напишіть коментар — що саме потрібно виправити учню.');
        ta && ta.focus();
        return false;
    }
    return confirm('Повернути роботу на доопрацювання?\nУчень отримає ваш коментар.');
}
</script>
<script src="theme-switcher.js"></script>
</body>
</html>