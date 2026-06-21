<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = $_SESSION['user_id'];

/* ── Дані студента ── */
$stmtUser = $pdo->prepare("SELECT first_name, last_name, email, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $studentId]);
$me = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Студент';
$initials = strtoupper(substr($me['first_name'] ?? '', 0, 1) . substr($me['last_name'] ?? '', 0, 1)) ?: 'СТ';

/* ── Всі завдання та оцінки студента ── */
$stmtGrades = $pdo->prepare("
    SELECT
        ts.id AS submission_id,
        ts.score,
        ts.status,
        ts.feedback,
        ts.submitted_at,
        ts.reviewed_at,
        t.title AS task_title,
        t.task_type,
        t.max_score,
        t.deadline,
        c.id   AS course_id,
        c.title AS course_title,
        l_lang.name_ua AS lang_name,
        l_lang.code    AS lang_code,
        u.first_name AS teacher_first,
        u.last_name  AS teacher_last
    FROM task_submissions ts
    JOIN tasks t        ON ts.task_id    = t.id
    LEFT JOIN lessons les    ON t.lesson_id   = les.id
    LEFT JOIN courses c      ON les.course_id = c.id
    LEFT JOIN languages l_lang ON c.language_id = l_lang.id
    LEFT JOIN users u   ON t.created_by = u.id
    WHERE ts.student_id = :s
    ORDER BY ts.submitted_at DESC
");
$stmtGrades->execute([':s' => $studentId]);
$allSubmissions = $stmtGrades->fetchAll(PDO::FETCH_ASSOC);

/* ── Статистика оцінок ── */
$scoredSubmissions = array_filter($allSubmissions, fn($s) => $s['score'] !== null);
$totalSubmissions  = count($allSubmissions);
$reviewedCount     = count($scoredSubmissions);
$pendingCount      = count(array_filter($allSubmissions, fn($s) => $s['status'] === 'submitted'));

/* Обраховувати середній бал як середній відсоток, а не середню абсолютну оцінку */
$percentages = [];
foreach ($scoredSubmissions as $s) {
    if ($s['max_score'] > 0) {
        $percentages[] = ($s['score'] / $s['max_score']) * 100;
    }
}
$avgScore = count($percentages) > 0
    ? round(array_sum($percentages) / count($percentages), 1)
    : null;

/* Знайти макс відсоток серед переглянутих */
$maxScoreAchieved = count($percentages) > 0
    ? round(max($percentages), 0)
    : null;

/* Підрахунок оцінок по курсах */
$courseStats = [];
foreach ($allSubmissions as $s) {
    $cid = $s['course_id'] ?? '';
    if (empty($cid)) continue;
    if (!isset($courseStats[$cid])) {
        $courseStats[$cid] = [
            'title'    => $s['course_title'],
            'lang'     => $s['lang_name'],
            'code'     => $s['lang_code'],
            'total'    => 0,
            'reviewed' => 0,
            'sum'      => 0,
            'max'      => 0,
        ];
    }
    $courseStats[$cid]['total']++;
    if ($s['score'] !== null) {
        $courseStats[$cid]['reviewed']++;
        $courseStats[$cid]['sum'] += $s['score'];
        if ($s['score'] > $courseStats[$cid]['max']) $courseStats[$cid]['max'] = $s['score'];
    }
}



/* ── Кількість курсів ── */
$stmtEnrolled = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = :s AND status = 'active'");
$stmtEnrolled->execute([':s' => $studentId]);
$activeCoursesCount = (int)$stmtEnrolled->fetchColumn();
/* ── Відвідуваність уроків ── */
$stmtAttendance = $pdo->prepare("
    SELECT
        l.id,
        l.title AS lesson_title,
        l.scheduled_at,
        l.status,
        c.title AS course_title,
        lang.code AS lang_code,
        lang.name_ua AS lang_name
    FROM lessons l
    JOIN courses c ON l.course_id = c.id
    JOIN languages lang ON c.language_id = lang.id
    WHERE l.course_id IN (
        SELECT course_id FROM enrollments WHERE student_id = :s AND status = 'active'
    )
    AND l.status IN ('completed', 'cancelled')
    ORDER BY l.scheduled_at DESC
    LIMIT 50
");
$stmtAttendance->execute([':s' => $studentId]);
$attendanceLessons = $stmtAttendance->fetchAll(PDO::FETCH_ASSOC);

$completedLessons = count(array_filter($attendanceLessons, fn($l) => $l['status'] === 'completed'));
$canceledLessons = count(array_filter($attendanceLessons, fn($l) => $l['status'] === 'cancelled'));
$totalLessons = count($attendanceLessons);
$attendanceRate = $totalLessons > 0 ? round($completedLessons / $totalLessons * 100) : 0;
/* Розподіл оцінок по шкалі (для графіку) */
$gradeDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
foreach ($scoredSubmissions as $s) {
    $pct = $s['max_score'] > 0 ? ($s['score'] / $s['max_score'] * 100) : 0;
    if ($pct >= 90)      $gradeDistribution['A']++;
    elseif ($pct >= 75)  $gradeDistribution['B']++;
    elseif ($pct >= 60)  $gradeDistribution['C']++;
    elseif ($pct >= 40)  $gradeDistribution['D']++;
    else                 $gradeDistribution['F']++;
}

/* Активність по місяцях (останні 6 міс.) */
$monthlyActivity = [];
for ($i = 5; $i >= 0; $i--) {
    $dt = new DateTime("first day of -$i month");
    $key = $dt->format('Y-m');
    $monthlyActivity[$key] = ['label' => $dt->format('M'), 'count' => 0];
}
foreach ($allSubmissions as $s) {
    $key = substr($s['submitted_at'], 0, 7);
    if (isset($monthlyActivity[$key])) $monthlyActivity[$key]['count']++;
}
$maxActivity = max(array_column(array_values($monthlyActivity), 'count')) ?: 1;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мої оцінки — LinguaHub</title>
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

/* ── Sidebar ── */
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
.nav-item:hover { color:var(--text); background:rgba(255,255,255,.04); }
.nav-item.active { color:#fff; background:rgba(99,102,241,.15); border-color:rgba(99,102,241,.3); }
.nav-badge { margin-left:auto; font-family:var(--mono); font-size:9px; font-weight:700; padding:2px 7px; border-radius:99px; background:rgba(99,102,241,.25); color:#a5b4fc; }
.nav-badge.green { background:rgba(34,197,94,.2); color:var(--green); }
.nav-badge.amber { background:rgba(245,158,11,.2); color:var(--amber); }
.sidebar-footer { padding:12px 10px; border-top:1px solid var(--border); }
.logout-btn { display:flex; align-items:center; gap:10px; width:100%; padding:10px 12px; border-radius:10px; background:rgba(239,68,68,.07); border:1px solid rgba(239,68,68,.2); color:#fca5a5; font-family:var(--font); font-size:13px; font-weight:600; cursor:pointer; transition:.18s; text-decoration:none; }
.logout-btn:hover { background:rgba(239,68,68,.15); border-color:rgba(239,68,68,.4); }
.logout-btn svg { width:14px; height:14px; }

/* ── Main ── */
.main { margin-left:var(--sidebar); flex:1; display:flex; flex-direction:column; min-height:100vh; position:relative; z-index:1; overflow-y:auto; height:100vh; }
.topbar { position:sticky; top:0; z-index:10; display:flex; align-items:center; justify-content:space-between; padding:14px 30px; border-bottom:1px solid var(--border); background:rgba(7,8,15,.9); backdrop-filter:blur(20px); }
.topbar-title { font-size:19px; font-weight:800; letter-spacing:-.4px; }
.topbar-title span { color:var(--teal); }
.topbar-tabs { display:flex; gap:4px; }
.tab-btn { padding:7px 14px; border-radius:8px; border:1px solid transparent; background:none; color:var(--muted); font-family:var(--font); font-size:12px; font-weight:700; cursor:pointer; transition:.18s; }
.tab-btn:hover { color:var(--text); background:rgba(255,255,255,.04); }
.tab-btn.active { color:#fff; background:rgba(99,102,241,.15); border-color:rgba(99,102,241,.3); }
.content { padding:24px 30px; display:flex; flex-direction:column; gap:20px; }

/* ── Stats row ── */
.stats-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:18px 16px; position:relative; overflow:hidden; transition:border-color .2s,transform .2s; cursor:default; }
.stat-card:hover { transform:translateY(-2px); }
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; opacity:0; transition:opacity .25s; }
.stat-card.c-purple::after { background:linear-gradient(90deg,var(--accent),#818cf8); }
.stat-card.c-teal::after   { background:linear-gradient(90deg,var(--teal),#67e8f9); }
.stat-card.c-green::after  { background:linear-gradient(90deg,var(--green),#86efac); }
.stat-card.c-amber::after  { background:linear-gradient(90deg,var(--amber),#fcd34d); }
.stat-card.c-red::after    { background:linear-gradient(90deg,var(--red),#fca5a5); }
.stat-card:hover::after { opacity:1; }
.stat-icon { font-size:16px; margin-bottom:8px; }
.stat-num { font-size:28px; font-weight:800; letter-spacing:-1px; line-height:1; margin-bottom:5px; }
.c-purple .stat-num { color:#a5b4fc; }
.c-teal   .stat-num { color:var(--teal); }
.c-green  .stat-num { color:var(--green); }
.c-amber  .stat-num { color:var(--amber); }
.c-red    .stat-num { color:#fca5a5; }
.stat-label { font-family:var(--mono); font-size:10px; color:var(--muted); letter-spacing:.5px; }
.stat-sub { font-family:var(--mono); font-size:9px; color:var(--muted); margin-top:3px; opacity:.6; }

/* ── Grid layout ── */
.grid-2-1 { display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start; }
.grid-3   { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }

/* ── Card ── */
.card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:20px; }
.card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.card-title { font-size:14px; font-weight:800; letter-spacing:.3px; }
.card-sub { font-family:var(--mono); font-size:10px; color:var(--muted); }

/* ── Tab panels ── */
.tab-panel { display:none; }
.tab-panel.active { display:flex; flex-direction:column; gap:20px; }

/* ── Grade table ── */
.grade-table { width:100%; border-collapse:collapse; }
.grade-table th { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; padding:8px 12px; text-align:left; border-bottom:1px solid var(--border); }
.grade-table td { padding:11px 12px; border-bottom:1px solid rgba(30,41,59,.5); font-size:12px; vertical-align:middle; }
.grade-table tr:last-child td { border-bottom:none; }
.grade-table tr:hover td { background:rgba(255,255,255,.02); }
.grade-task-name { font-weight:700; color:var(--text); }
.grade-course-tag { font-family:var(--mono); font-size:9px; color:var(--teal); margin-top:2px; }
.grade-type-tag { display:inline-flex; font-family:var(--mono); font-size:8px; padding:2px 7px; border-radius:99px; font-weight:600; }
.type-homework { background:rgba(99,102,241,.12); color:#a5b4fc; }
.type-test     { background:rgba(245,158,11,.12); color:var(--amber); }
.type-quiz     { background:rgba(34,211,238,.12); color:var(--teal); }
.type-project  { background:rgba(34,197,94,.12); color:var(--green); }
.score-display { display:flex; align-items:center; gap:8px; }
.score-val { font-family:var(--mono); font-size:13px; font-weight:700; }
.score-max { font-family:var(--mono); font-size:10px; color:var(--muted); }
.score-bar-wrap { flex:1; height:4px; background:rgba(255,255,255,.07); border-radius:99px; overflow:hidden; min-width:50px; }
.score-bar-fill { height:100%; border-radius:99px; transition:width .5s ease; }
.score-grade { font-family:var(--mono); font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px; }
.grade-A { background:rgba(34,197,94,.15); color:var(--green); }
.grade-B { background:rgba(34,211,238,.15); color:var(--teal); }
.grade-C { background:rgba(245,158,11,.15); color:var(--amber); }
.grade-D { background:rgba(239,68,68,.15); color:#fca5a5; }
.grade-F { background:rgba(239,68,68,.25); color:var(--red); }
.status-badge { display:inline-flex; align-items:center; gap:4px; font-family:var(--mono); font-size:9px; padding:3px 8px; border-radius:6px; font-weight:600; }
.status-reviewed  { background:rgba(34,197,94,.1); color:var(--green); border:1px solid rgba(34,197,94,.2); }
.status-submitted { background:rgba(245,158,11,.1); color:var(--amber); border:1px solid rgba(245,158,11,.2); }
.status-pending   { background:rgba(99,102,241,.1); color:#a5b4fc; border:1px solid rgba(99,102,241,.2); }
.date-text { font-family:var(--mono); font-size:10px; color:var(--muted); }

/* ── Course stats cards ── */
.course-stat-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px; transition:.2s; }
.course-stat-card:hover { border-color:rgba(99,102,241,.4); transform:translateY(-2px); }
.cs-header { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.cs-flag { font-size:22px; }
.cs-lang { font-family:var(--mono); font-size:9px; color:var(--teal); text-transform:uppercase; letter-spacing:.5px; }
.cs-title { font-size:12px; font-weight:700; margin-top:2px; }
.cs-avg { font-size:26px; font-weight:800; color:#a5b4fc; letter-spacing:-1px; margin-bottom:2px; }
.cs-avg-label { font-family:var(--mono); font-size:9px; color:var(--muted); margin-bottom:10px; }
.cs-progress { height:5px; background:rgba(255,255,255,.07); border-radius:99px; overflow:hidden; margin-bottom:8px; }
.cs-progress-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--accent),var(--teal)); }
.cs-meta { display:flex; justify-content:space-between; }
.cs-meta span { font-family:var(--mono); font-size:9px; color:var(--muted); }

/* ── Grade distribution chart ── */
.dist-chart { display:flex; flex-direction:column; gap:8px; }
.dist-row { display:flex; align-items:center; gap:10px; }
.dist-label { font-family:var(--mono); font-size:11px; font-weight:800; width:18px; text-align:center; }
.dist-bar-wrap { flex:1; height:20px; background:rgba(255,255,255,.04); border-radius:6px; overflow:hidden; }
.dist-bar-fill { height:100%; border-radius:6px; display:flex; align-items:center; padding-left:8px; font-family:var(--mono); font-size:9px; font-weight:700; color:rgba(255,255,255,.8); transition:width .6s ease; min-width:24px; }
.dist-count { font-family:var(--mono); font-size:10px; color:var(--muted); width:20px; text-align:right; }
.dist-A .dist-label { color:var(--green); } .dist-A .dist-bar-fill { background:linear-gradient(90deg,rgba(34,197,94,.5),rgba(34,197,94,.25)); }
.dist-B .dist-label { color:var(--teal);  } .dist-B .dist-bar-fill { background:linear-gradient(90deg,rgba(34,211,238,.5),rgba(34,211,238,.25)); }
.dist-C .dist-label { color:var(--amber); } .dist-C .dist-bar-fill { background:linear-gradient(90deg,rgba(245,158,11,.5),rgba(245,158,11,.25)); }
.dist-D .dist-label { color:#fca5a5;      } .dist-D .dist-bar-fill { background:linear-gradient(90deg,rgba(239,68,68,.4),rgba(239,68,68,.2)); }
.dist-F .dist-label { color:var(--red);   } .dist-F .dist-bar-fill { background:linear-gradient(90deg,rgba(239,68,68,.7),rgba(239,68,68,.35)); }

/* ── Activity chart ── */
.activity-chart { display:flex; align-items:flex-end; gap:8px; height:80px; padding-top:8px; }
.act-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; }
.act-bar { width:100%; border-radius:4px 4px 0 0; background:linear-gradient(180deg,rgba(99,102,241,.6),rgba(99,102,241,.25)); transition:height .5s ease; min-height:3px; }
.act-label { font-family:var(--mono); font-size:8px; color:var(--muted); }
.act-count { font-family:var(--mono); font-size:8px; color:#a5b4fc; font-weight:700; }

/* ── Attendance ── */
.attend-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:10px; }
.attend-item { display:flex; align-items:flex-start; gap:10px; padding:12px; background:var(--surface); border-radius:10px; border:1px solid var(--border); transition:.2s; }
.attend-item:hover { border-color:rgba(99,102,241,.3); }
.attend-item.status-completed { border-left:3px solid var(--green); }
.attend-item.status-canceled { border-left:3px solid var(--red); }
.attend-dot { width:8px; height:8px; border-radius:50%; margin-top:4px; flex-shrink:0; }
.attend-dot.present { background:var(--green); box-shadow:0 0 6px rgba(34,197,94,.5); }
.attend-dot.absent  { background:var(--red);   box-shadow:0 0 6px rgba(239,68,68,.4); }
.attend-lesson { font-size:11px; font-weight:700; margin-bottom:2px; }
.attend-course { font-family:var(--mono); font-size:9px; color:var(--teal); margin-bottom:3px; }
.attend-date   { font-family:var(--mono); font-size:9px; color:var(--muted); }
.attend-status { display:flex; gap:4px; }
.status-completed-badge { background:rgba(34,197,94,.1); color:var(--green); border:1px solid rgba(34,197,94,.2); }
.status-canceled-badge { background:rgba(239,68,68,.1); color:var(--red); border:1px solid rgba(239,68,68,.2); }

/* ── Attend rate ring ── */
.attend-ring { display:flex; flex-direction:column; align-items:center; gap:6px; padding:12px; }
.ring-svg { transform:rotate(-90deg); }
.ring-bg  { fill:none; stroke:rgba(255,255,255,.06); stroke-width:8; }
.ring-fg  { fill:none; stroke:var(--teal); stroke-width:8; stroke-linecap:round; transition:stroke-dashoffset .8s ease; }
.ring-text { font-family:var(--mono); font-size:22px; font-weight:800; color:var(--teal); }
.ring-label { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }

/* ── Feedback card ── */
.feedback-list { display:flex; flex-direction:column; gap:10px; }
.feedback-item { padding:14px; background:var(--surface); border-radius:12px; border:1px solid var(--border); border-left:3px solid var(--accent); }
.fb-task { font-size:12px; font-weight:700; margin-bottom:3px; }
.fb-course { font-family:var(--mono); font-size:9px; color:var(--teal); margin-bottom:6px; }
.fb-text { font-size:11px; color:var(--muted); line-height:1.6; font-style:italic; }
.fb-score { font-family:var(--mono); font-size:10px; color:var(--green); margin-top:6px; font-weight:700; }

/* ── Empty state ── */
.empty-state { display:flex; flex-direction:column; align-items:center; padding:40px 24px; gap:10px; text-align:center; }
.empty-icon { font-size:36px; opacity:.4; }
.empty-title { font-size:13px; font-weight:700; }
.empty-sub { font-family:var(--mono); font-size:10px; color:var(--muted); max-width:240px; line-height:1.6; }

/* ── Filter bar ── */
.filter-bar { display:flex; gap:8px; flex-wrap:wrap; }
.filter-btn { padding:6px 14px; border-radius:8px; border:1px solid var(--border); background:var(--surface); color:var(--muted); font-family:var(--mono); font-size:10px; font-weight:600; cursor:pointer; transition:.18s; }
.filter-btn:hover { color:var(--text); border-color:rgba(99,102,241,.4); }
.filter-btn.active { color:#a5b4fc; background:rgba(99,102,241,.15); border-color:rgba(99,102,241,.35); }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
.tab-panel.active { animation:fadeUp .2s ease; }
</style>
</head>
<body>

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

        <a class="nav-item" href="dashboard_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Дашборд
        </a>

        <a class="nav-item" href="courses_catalog.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            Всі курси
        </a>

        <a class="nav-item" href="schedule_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Розклад
        </a>

        <a class="nav-item active" href="grades_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            Оцінки
            <span class="nav-badge green"><?= $reviewedCount ?></span>
        </a>

        <a class="nav-item" href="homework_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Домашні завдання
            <span class="nav-badge amber"><?= $pendingCount ?></span>
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
        <div class="topbar-title">МОЇ <span>ОЦІНКИ</span></div>
        <div class="topbar-tabs">
            <button class="tab-btn active" onclick="switchTab('overview')">Огляд</button>
            <button class="tab-btn" onclick="switchTab('grades')">Журнал оцінок</button>
            <button class="tab-btn" onclick="switchTab('attendance')">Відвідуваність</button>
            <button class="tab-btn" onclick="switchTab('feedback')">Відгуки</button>
        </div>
    </div>

    <div class="content">

        <!-- ── Загальна статистика ── -->
        <div class="stats-row">
            <div class="stat-card c-purple">
                <div class="stat-icon">📚</div>
                <div class="stat-num"><?= $activeCoursesCount ?></div>
                <div class="stat-label">Активні курси</div>
            </div>
            <div class="stat-card c-teal">
                <div class="stat-icon">✅</div>
                <div class="stat-num"><?= $totalSubmissions ?></div>
                <div class="stat-label">Завдань здано</div>
                <div class="stat-sub"><?= $pendingCount ?> на перевірці</div>
            </div>
            <div class="stat-card c-green">
                <div class="stat-icon">⭐</div>
                <div class="stat-num"><?= $avgScore !== null ? $avgScore : '—' ?></div>
                <div class="stat-label">Середній бал</div>
                <?php if ($avgScore !== null): ?>
                <div class="stat-sub">з <?= $reviewedCount ?> оцінених</div>
                <?php endif; ?>
            </div>
            <div class="stat-card c-amber">
                <div class="stat-icon">🏆</div>
                <div class="stat-num"><?= $maxScoreAchieved !== null ? $maxScoreAchieved : '—' ?></div>
                <div class="stat-label">Найвищий бал</div>
            </div>
            <div class="stat-card c-red">
                <div class="stat-icon">📅</div>
                <div class="stat-num"><?= $attendanceRate ?>%</div>
                <div class="stat-label">Відвідуваність</div>
                <div class="stat-sub"><?= $completedLessons ?>/<?= $totalLessons ?> занять</div>
            </div>

        </div>

        <!-- ── TAB: ОГЛЯД ── -->
        <div class="tab-panel active" id="tab-overview">
            <div class="grid-2-1">
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Оцінки по курсах -->
                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">Успішність по курсах</div>
                            <span class="card-sub"><?= count($courseStats) ?> курсів</span>
                        </div>
                        <?php if (empty($courseStats)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">📊</div>
                                <div class="empty-title">Поки немає даних</div>
                                <div class="empty-sub">Здайте перші завдання, щоб побачити статистику</div>
                            </div>
                        <?php else: ?>
                            <div class="grid-3">
                                <?php
                                $flags = ['en'=>'🇬🇧','de'=>'🇩🇪','ja'=>'🇯🇵','fr'=>'🇫🇷'];
                                foreach ($courseStats as $cs):
                                    $avg = $cs['reviewed'] > 0 ? round($cs['sum'] / $cs['reviewed'], 1) : null;
                                    $flag = $flags[$cs['code']] ?? '🌐';
                                    $progress = $avg !== null ? min(100, round($avg)) : 0;
                                ?>
                                <div class="course-stat-card">
                                    <div class="cs-header">
                                        <div class="cs-flag"><?= $flag ?></div>
                                        <div>
                                            <div class="cs-lang"><?= htmlspecialchars($cs['lang'] ?? '') ?></div>
                                            <div class="cs-title"><?= htmlspecialchars($cs['title'] ?? '') ?></div>
                                        </div>
                                    </div>
                                    <div class="cs-avg"><?= $avg ?? '—' ?></div>
                                    <div class="cs-avg-label">середній бал</div>
                                    <?php if ($avg !== null): ?>
                                    <div class="cs-progress">
                                        <div class="cs-progress-fill" style="width:<?= $progress ?>%"></div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="cs-meta">
                                        <span><?= $cs['reviewed'] ?>/<?= $cs['total'] ?> оцінено</span>
                                        <span>макс: <?= $cs['max'] ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Активність по місяцях -->
                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">Активність (здані завдання)</div>
                            <span class="card-sub">Останні 6 місяців</span>
                        </div>
                        <div class="activity-chart">
                            <?php foreach ($monthlyActivity as $key => $m):
                                $barH = $maxActivity > 0 ? round($m['count'] / $maxActivity * 64) : 3;
                                $barH = max($barH, 3);
                            ?>
                            <div class="act-bar-wrap">
                                <div style="flex:1;display:flex;align-items:flex-end;">
                                    <div class="act-bar" style="height:<?= $barH ?>px;width:100%;"></div>
                                </div>
                                <div class="act-count"><?= $m['count'] ?></div>
                                <div class="act-label"><?= $m['label'] ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- Права колонка -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Відвідуваність кільце -->
                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">Відвідуваність</div>
                        </div>
                        <?php
                        $circumference = 2 * M_PI * 40;
                        $offset = $circumference - ($attendanceRate / 100) * $circumference;
                        ?>
                        <div class="attend-ring">
                            <svg class="ring-svg" width="100" height="100" viewBox="0 0 100 100">
                                <circle class="ring-bg" cx="50" cy="50" r="40"/>
                                <circle class="ring-fg" cx="50" cy="50" r="40"
                                    stroke-dasharray="<?= $circumference ?>"
                                    stroke-dashoffset="<?= $offset ?>"
                                    id="ringFg"/>
                            </svg>
                            <div class="ring-text"><?= $attendanceRate ?>%</div>
                            <div class="ring-label">присутність</div>
                        </div>
                        <div style="display:flex;justify-content:space-around;margin-top:8px;">
                            <div style="text-align:center;">
                                <div style="font-family:var(--mono);font-size:16px;font-weight:800;color:var(--green)"><?= $completedLessons ?></div>
                                <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">відвідані</div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-family:var(--mono);font-size:16px;font-weight:800;color:#fca5a5"><?= $canceledLessons ?></div>
                                <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">скасовані</div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-family:var(--mono);font-size:16px;font-weight:800;color:var(--muted)"><?= $totalLessons ?></div>
                                <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">всього</div>
                            </div>
                        </div>
                    </div>

                    <!-- Розподіл оцінок -->
                    <div class="card">
                        <div class="card-head">
                            <div class="card-title">Розподіл оцінок</div>
                        </div>
                        <div class="dist-chart">
                            <?php
                            $gradeLabels = ['A'=>'A (90–100%)','B'=>'B (75–89%)','C'=>'C (60–74%)','D'=>'D (40–59%)','F'=>'F (<40%)'];
                            $maxDist = max(array_values($gradeDistribution)) ?: 1;
                            foreach ($gradeDistribution as $grade => $cnt):
                                $pct = round($cnt / $maxDist * 100);
                            ?>
                            <div class="dist-row dist-<?= $grade ?>">
                                <div class="dist-label"><?= $grade ?></div>
                                <div class="dist-bar-wrap">
                                    <div class="dist-bar-fill" style="width:<?= $pct ?>%"><?= $cnt > 0 ? $cnt : '' ?></div>
                                </div>
                                <div class="dist-count"><?= $cnt ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── TAB: ЖУРНАЛ ОЦІНОК ── -->
        <div class="tab-panel" id="tab-grades">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Журнал оцінок</div>
                    <div class="filter-bar">
                        <button class="filter-btn active" onclick="filterGrades('all',this)">Всі</button>
                        <button class="filter-btn" onclick="filterGrades('reviewed',this)">Оцінені</button>
                        <button class="filter-btn" onclick="filterGrades('submitted',this)">На перевірці</button>
                    </div>
                </div>
                <?php if (empty($allSubmissions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📝</div>
                        <div class="empty-title">Ще немає зданих завдань</div>
                        <div class="empty-sub">Виконайте домашні завдання, щоб отримати оцінки</div>
                    </div>
                <?php else: ?>
                <table class="grade-table" id="gradesTable">
                    <thead>
                        <tr>
                            <th>Завдання</th>
                            <th>Тип</th>
                            <th>Оцінка</th>
                            <th>Літера</th>
                            <th>Статус</th>
                            <th>Здано</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allSubmissions as $s):
                        $pct = ($s['score'] !== null && $s['max_score'] > 0) ? ($s['score'] / $s['max_score'] * 100) : null;
                        $gradeLetter = '—';
                        $gradeClass  = '';
                        if ($pct !== null) {
                            if ($pct >= 90)     { $gradeLetter = 'A'; $gradeClass = 'grade-A'; }
                            elseif ($pct >= 75) { $gradeLetter = 'B'; $gradeClass = 'grade-B'; }
                            elseif ($pct >= 60) { $gradeLetter = 'C'; $gradeClass = 'grade-C'; }
                            elseif ($pct >= 40) { $gradeLetter = 'D'; $gradeClass = 'grade-D'; }
                            else                { $gradeLetter = 'F'; $gradeClass = 'grade-F'; }
                        }
                        $typeClass = 'type-' . strtolower($s['task_type'] ?? 'homework');
                        $typeLabel = [
                            'homework' => 'Д/З',
                            'test'     => 'Тест',
                            'quiz'     => 'Квіз',
                            'project'  => 'Проект',
                        ][$s['task_type'] ?? 'homework'] ?? ucfirst($s['task_type'] ?? '');
                        $submittedDt = new DateTime($s['submitted_at']);
                        $barColor = match(true) {
                            $pct === null    => 'rgba(100,116,139,.3)',
                            $pct >= 90       => 'rgba(34,197,94,.6)',
                            $pct >= 75       => 'rgba(34,211,238,.6)',
                            $pct >= 60       => 'rgba(245,158,11,.6)',
                            $pct >= 40       => 'rgba(239,68,68,.4)',
                            default          => 'rgba(239,68,68,.7)',
                        };
                    ?>
                    <tr data-status="<?= htmlspecialchars($s['status']) ?>">
                        <td>
                            <div class="grade-task-name"><?= htmlspecialchars($s['task_title'] ?? '') ?></div>
                            <div class="grade-course-tag"><?= htmlspecialchars($s['course_title'] ?? '') ?></div>
                        </td>
                        <td>
                            <span class="grade-type-tag <?= $typeClass ?>"><?= $typeLabel ?></span>
                        </td>
                        <td>
                            <div class="score-display">
                                <div>
                                    <span class="score-val" style="color:<?= $pct !== null ? ($pct>=75?'var(--green)':($pct>=50?'var(--amber)':'#fca5a5')) : 'var(--muted)' ?>">
                                        <?= $s['score'] !== null ? $s['score'] : '—' ?>
                                    </span>
                                    <span class="score-max">/ <?= htmlspecialchars($s['max_score'] ?? '') ?></span>
                                </div>
                                <div class="score-bar-wrap">
                                    <div class="score-bar-fill" style="width:<?= $pct !== null ? $pct : 0 ?>%;background:<?= $barColor ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($gradeLetter !== '—'): ?>
                                <span class="score-grade <?= $gradeClass ?>"><?= $gradeLetter ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-family:var(--mono);font-size:11px">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['status'] === 'reviewed'): ?>
                                <span class="status-badge status-reviewed">✓ Оцінено</span>
                            <?php elseif ($s['status'] === 'submitted'): ?>
                                <span class="status-badge status-submitted">⏳ На перевірці</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">📋 <?= htmlspecialchars($s['status'] ?? '') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="date-text"><?= $submittedDt->format('d.m.Y') ?></div>
                            <div class="date-text"><?= $submittedDt->format('H:i') ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: ВІДВІДУВАНІСТЬ ── -->
        <div class="tab-panel" id="tab-attendance">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Журнал уроків</div>
                    <span class="card-sub"><?= count($attendanceLessons) ?> уроків</span>
                </div>
                <?php if (empty($attendanceLessons)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📅</div>
                        <div class="empty-title">Немає даних про уроки</div>
                        <div class="empty-sub">Дані з'являтися після завершення перших занять</div>
                    </div>
                <?php else: ?>
                    <div class="attend-grid">
                        <?php foreach ($attendanceLessons as $l):
                            $dt = new DateTime($l['scheduled_at']);
                            $flags = ['en'=>'🇬🇧','de'=>'🇩🇪','ja'=>'🇯🇵','fr'=>'🇫🇷'];
                            $flag = $flags[$l['lang_code']] ?? '🌐';
                            $isCompleted = $l['status'] === 'completed';
                        ?>
                        <div class="attend-item <?= $isCompleted ? 'status-completed' : 'status-canceled' ?>">
                            <div class="attend-dot <?= $isCompleted ? 'present' : 'absent' ?>"></div>
                            <div style="flex:1;">
                                <div class="attend-lesson"><?= htmlspecialchars($l['lesson_title'] ?? '') ?></div>
                                <div class="attend-course"><?= $flag ?> <?= htmlspecialchars($l['course_title'] ?? '') ?></div>
                                <div class="attend-date"><?= $dt->format('d.m.Y H:i') ?></div>
                            </div>
                            <div class="attend-status">
                                <?php if ($isCompleted): ?>
                                    <span class="status-badge status-completed-badge">✓ Завершено</span>
                                <?php else: ?>
                                    <span class="status-badge status-canceled-badge">✕ Скасовано</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: ВІДГУКИ ── -->
        <div class="tab-panel" id="tab-feedback">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Відгуки викладачів</div>
                    <?php
                    $withFeedback = array_filter($allSubmissions, fn($s) => !empty($s['feedback']));
                    ?>
                    <span class="card-sub"><?= count($withFeedback) ?> коментарів</span>
                </div>
                <?php if (empty($withFeedback)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">💬</div>
                        <div class="empty-title">Відгуків поки немає</div>
                        <div class="empty-sub">Після перевірки завдань викладачі залишатимуть коментарі тут</div>
                    </div>
                <?php else: ?>
                    <div class="feedback-list">
                        <?php foreach ($withFeedback as $s):
                            $pct = ($s['score'] !== null && $s['max_score'] > 0)
                                ? round($s['score'] / $s['max_score'] * 100) : null;
                            $teacherName = trim(($s['teacher_first'] ?? '') . ' ' . ($s['teacher_last'] ?? ''));
                        ?>
                        <div class="feedback-item">
                            <div class="fb-task"><?= htmlspecialchars($s['task_title'] ?? '') ?></div>
                            <div class="fb-course">📚 <?= htmlspecialchars($s['course_title'] ?? '') ?></div>
                            <div class="fb-text">"<?= htmlspecialchars($s['feedback'] ?? '') ?>"</div>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
                                <div class="fb-score">
                                    <?= $s['score'] !== null ? ($s['score'] . ' / ' . $s['max_score'] . ' балів') : '' ?>
                                    <?php if ($pct !== null): ?>
                                        (<?= $pct ?>%)
                                    <?php endif; ?>
                                </div>
                                <?php if ($teacherName): ?>
                                <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">— <?= htmlspecialchars($teacherName ?? '') ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}

function filterGrades(type, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#gradesTable tbody tr').forEach(tr => {
        if (type === 'all') {
            tr.style.display = '';
        } else {
            tr.style.display = tr.dataset.status === type ? '' : 'none';
        }
    });
}

/* Анімація кільця відвідуваності при завантаженні */
window.addEventListener('load', () => {
    const ring = document.getElementById('ringFg');
    if (ring) {
        const full = 2 * Math.PI * 40;
        const rate = <?= $attendanceRate ?>;
        ring.style.strokeDashoffset = full - (rate / 100) * full;
    }
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>