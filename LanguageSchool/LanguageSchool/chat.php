<?php
// Chat interface: real-time messaging between students, teachers, and admins
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}

$me   = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['student', 'teacher', 'admin'])) {
    header("Location: login.php"); exit;
}

$stMe = $pdo->prepare(
    "SELECT id, first_name, last_name, avatar_url, email FROM users WHERE id = :id"
);
$stMe->execute([':id' => $me]);
$myInfo     = $stMe->fetch(PDO::FETCH_ASSOC);
$myName     = trim(($myInfo['first_name'] ?? '') . ' ' . ($myInfo['last_name'] ?? '')) ?: 'Користувач';
$myInitials = strtoupper(
    substr($myInfo['first_name'] ?? '', 0, 1) .
    substr($myInfo['last_name']  ?? '', 0, 1)
) ?: '??';

$admins = [];
if ($role !== 'admin') {
    $stAdmins = $pdo->prepare("
        SELECT id, first_name, last_name, avatar_url,
               'admin' AS peer_role, 'Адміністрація' AS context_title
        FROM users WHERE role = 'admin' ORDER BY first_name, last_name
    ");
    $stAdmins->execute();
    $admins = $stAdmins->fetchAll(PDO::FETCH_ASSOC);
}

if ($role === 'student') {
    $stPeers = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.avatar_url,
               'teacher' AS peer_role, c.title AS context_title
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN users u ON u.id = c.teacher_id
        WHERE e.student_id = :me AND e.status IN ('active','pending')
        ORDER BY u.last_name, u.first_name
    ");
    $stPeers->execute([':me' => $me]);
} elseif ($role === 'teacher') {
    $stPeers = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.avatar_url,
               'student' AS peer_role, c.title AS context_title
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN users u ON u.id = e.student_id
        WHERE c.teacher_id = :me AND e.status = 'active'
        ORDER BY u.last_name, u.first_name
    ");
    $stPeers->execute([':me' => $me]);
} else {
    $stPeers = $pdo->prepare("
        SELECT id, first_name, last_name, avatar_url,
               role AS peer_role, '' AS context_title
        FROM users WHERE id != :me AND role IN ('student','teacher')
        ORDER BY role DESC, last_name, first_name
    ");
    $stPeers->execute([':me' => $me]);
}

$peers = array_merge($admins, $stPeers->fetchAll(PDO::FETCH_ASSOC));

$activeConvId = $_GET['conv'] ?? '';
if ($activeConvId && !preg_match('/^[0-9a-f\-]{36}$/i', $activeConvId)) $activeConvId = '';

$autoOpenUserId = '';
if (isset($_GET['open_user']) && preg_match('/^[0-9a-f\-]{36}$/i', $_GET['open_user'])) {
    $autoOpenUserId = $_GET['open_user'];
}

// Get courses/languages for filtering, grouped by level
$userCourses = [];
$coursesByLevel = ['A1'=>[], 'A2'=>[], 'B1'=>[], 'B2'=>[], 'C1'=>[], 'C2'=>[]];

if ($role === 'student') {
    $st = $pdo->prepare("
        SELECT DISTINCT c.id, c.title, c.level, l.name_ua
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        JOIN languages l ON c.language_id = l.id
        WHERE e.student_id = :me AND e.status IN ('active','pending')
        ORDER BY c.level, c.title
    ");
    $st->execute([':me' => $me]);
    $userCourses = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($userCourses as $c) {
        if (isset($coursesByLevel[$c['level']])) {
            $coursesByLevel[$c['level']][] = $c;
        }
    }
} elseif ($role === 'teacher') {
    $st = $pdo->prepare("
        SELECT DISTINCT c.id, c.title, c.level, l.name_ua
        FROM courses c
        JOIN languages l ON c.language_id = l.id
        WHERE c.teacher_id = :me
        ORDER BY c.level, c.title
    ");
    $st->execute([':me' => $me]);
    $userCourses = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($userCourses as $c) {
        if (isset($coursesByLevel[$c['level']])) {
            $coursesByLevel[$c['level']][] = $c;
        }
    }
}

$dashboardUrl = match($role) {
    'teacher' => 'dashboard_teacher.php',
    'admin'   => 'admin.php',
    default   => 'dashboard_student.php',
};
$myRoleLabel = match($role) {
    'teacher' => 'Викладач',
    'admin'   => 'Адміністратор',
    default   => 'Студент',
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Чат — LinguaHub</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root {
    --bg:#07080f; --surface:#0d1117; --card:#111827; --border:#1e293b;
    --accent:#6366f1; --teal:#22d3ee; --green:#22c55e; --amber:#f59e0b;
    --red:#ef4444; --text:#e2e8f0; --muted:#64748b;
    --font:'Syne',sans-serif; --mono:'JetBrains Mono',monospace;
    --sw:300px; --hh:62px;
}
html,body { height:100%; overflow:hidden; }
body { font-family:var(--font); background:var(--bg); color:var(--text); display:flex; flex-direction:column; }
body::before { content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(ellipse 60% 40% at 0% 0%,  rgba(99,102,241,.12) 0%, transparent 55%),
        radial-gradient(ellipse 40% 30% at 100% 100%, rgba(34,211,238,.08) 0%, transparent 55%); }

/* ══ TOPBAR ══ */
.topbar { height:var(--hh); display:flex; align-items:center; gap:14px; padding:0 20px;
    background:rgba(13,17,23,.96); border-bottom:1px solid var(--border);
    position:relative; z-index:10; flex-shrink:0; backdrop-filter:blur(20px); }
.back-btn { display:flex; align-items:center; gap:7px; padding:7px 13px; border-radius:9px;
    border:1px solid var(--border); background:var(--surface); color:var(--muted);
    font-family:var(--mono); font-size:11px; font-weight:600;
    text-decoration:none; transition:.18s; white-space:nowrap; flex-shrink:0; }
.back-btn:hover { color:var(--text); border-color:rgba(99,102,241,.4); background:rgba(99,102,241,.07); }
.topbar-logo { font-size:17px; font-weight:800; letter-spacing:-.5px; flex-shrink:0; }
.topbar-logo span { color:var(--teal); }

/* ══ SIDE NAV ══ */
.side-nav { width:64px; flex-shrink:0; background:rgba(7,8,15,.98);
    border-right:1px solid var(--border); display:flex; flex-direction:column; align-items:center;
    padding:12px 0; gap:8px; overflow-y:auto; }
.side-nav::-webkit-scrollbar { width:3px; }
.side-nav::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
.nav-item { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:.2s; border:1px solid transparent; font-weight:600; font-size:20px;
    position:relative; color:var(--muted); }
.nav-item:hover { border-color:rgba(99,102,241,.3); background:rgba(99,102,241,.08); color:var(--text); }
.nav-item.active { background:rgba(99,102,241,.15); border-color:rgba(99,102,241,.4); color:var(--text); }
.nav-label { font-family:var(--mono); font-size:8px; position:absolute; bottom:-20px; width:70px;
    text-align:center; color:var(--muted); word-wrap:break-word; line-height:1.2; }
.nav-divider { width:32px; height:1px; background:var(--border); opacity:.3; }
.topbar-mid { flex:1; display:flex; align-items:center; gap:10px; min-width:0; }
.conv-info { display:flex; align-items:center; gap:10px; }
.conv-av { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal));
    display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800;
    color:#fff; flex-shrink:0; overflow:hidden; border:2px solid rgba(99,102,241,.3); }
.conv-av img { width:100%; height:100%; object-fit:cover; }
.conv-title-name { font-size:14px; font-weight:800; }
.conv-title-sub  { font-family:var(--mono); font-size:10px; color:var(--muted); margin-top:1px; }
.online-dot { width:8px; height:8px; border-radius:50%; background:var(--green); display:inline-block;
    margin-left:5px; box-shadow:0 0 6px var(--green); animation:pulse 2s infinite; }
.me-badge { display:flex; align-items:center; gap:9px; margin-left:auto; flex-shrink:0; }
.me-av { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal));
    display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800;
    color:#fff; overflow:hidden; border:2px solid rgba(99,102,241,.3); }
.me-av img { width:100%; height:100%; object-fit:cover; }
.me-name { font-size:12px; font-weight:700; }
.me-role { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }

/* ══ LAYOUT ══ */
.chat-wrap { flex:1; display:flex; overflow:hidden; position:relative; z-index:1; }

/* ══ SIDEBAR ══ */
.sidebar { width:var(--sw); flex-shrink:0; background:rgba(13,17,23,.95);
    border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; }
.sb-head { padding:16px 14px 12px; border-bottom:1px solid var(--border); flex-shrink:0; }
.sb-head-title { font-size:13px; font-weight:800; margin-bottom:10px; }
.search-box { display:flex; align-items:center; gap:8px; background:var(--surface);
    border:1px solid var(--border); border-radius:9px; padding:8px 12px; transition:.18s; }
.search-box:focus-within { border-color:rgba(99,102,241,.4); }
.search-box input { background:none; border:none; outline:none; color:var(--text);
    font-family:var(--font); font-size:12px; width:100%; }
.search-box input::placeholder { color:var(--muted); }

.conv-list { flex:1; overflow-y:auto; padding:6px 8px; }
.conv-list::-webkit-scrollbar { width:4px; }
.conv-list::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

.conv-item { display:flex; align-items:center; gap:11px; padding:11px 10px; border-radius:11px;
    cursor:pointer; transition:.18s; border:1px solid transparent; color:var(--text); }
.conv-item:hover { background:rgba(255,255,255,.04); }
.conv-item.active { background:rgba(99,102,241,.12); border-color:rgba(99,102,241,.25); }
.conv-item-av { width:40px; height:40px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--teal));
    display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800;
    color:#fff; flex-shrink:0; overflow:hidden; border:2px solid rgba(99,102,241,.2); position:relative; }
.conv-item-av img { width:100%; height:100%; object-fit:cover; }
.conv-item-av .ring { position:absolute; bottom:1px; right:1px; width:10px; height:10px;
    border-radius:50%; background:var(--green); border:2px solid var(--bg); }
.conv-info-text { flex:1; min-width:0; }
.conv-name { font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.conv-preview { font-family:var(--mono); font-size:10px; color:var(--muted);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
.conv-meta { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0; }
.conv-time { font-family:var(--mono); font-size:9px; color:var(--muted); }
.conv-unread { background:var(--accent); color:#fff; font-family:var(--mono); font-size:9px; font-weight:700;
    min-width:18px; height:18px; border-radius:99px; display:flex; align-items:center; justify-content:center; padding:0 5px; }

.sb-peers { padding:8px; border-top:1px solid var(--border); flex-shrink:0; max-height:260px; overflow-y:auto; }
.sb-peers::-webkit-scrollbar { width:4px; }
.sb-peers::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.sb-peers-label { font-family:var(--mono); font-size:9px; color:var(--muted); letter-spacing:1.5px;
    text-transform:uppercase; padding:4px 8px 8px; }
.peers-section-divider { font-family:var(--mono); font-size:9px; color:var(--border); letter-spacing:1px;
    text-transform:uppercase; padding:6px 8px 4px; margin-top:4px; border-top:1px solid rgba(30,41,59,.5); }
.peer-item { display:flex; align-items:center; gap:9px; padding:8px 10px; border-radius:10px;
    cursor:pointer; transition:.18s; border:1px solid transparent; color:var(--muted); }
.peer-item:hover { background:rgba(99,102,241,.08); color:var(--text); border-color:rgba(99,102,241,.2); }
.peer-item.is-admin { border-color:rgba(239,68,68,.12); background:rgba(239,68,68,.04); }
.peer-item.is-admin:hover { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.3); }
.peer-av { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,#3730a3,#1e40af);
    display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700;
    color:#fff; flex-shrink:0; overflow:hidden; }
.peer-av.is-admin-av { background:linear-gradient(135deg,#7f1d1d,#991b1b); }
.peer-av img { width:100%; height:100%; object-fit:cover; }
.peer-name { font-size:12px; font-weight:600; flex:1; min-width:0;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.peer-role-tag { font-family:var(--mono); font-size:8px; padding:2px 6px; border-radius:6px; flex-shrink:0; }
.peer-role-tag.teacher { background:rgba(34,211,238,.1); color:var(--teal); }
.peer-role-tag.student { background:rgba(99,102,241,.12); color:#a5b4fc; }
.peer-role-tag.admin   { background:rgba(239,68,68,.15); color:#fca5a5; }

/* ── Course items ── */
.course-item { display:flex; align-items:center; gap:11px; padding:11px 10px; border-radius:11px;
    cursor:pointer; transition:.18s; border:1px solid transparent; color:var(--text); }
.course-item:hover { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.2); }
.course-item-icon { font-size:18px; flex-shrink:0; }
.course-item-text { flex:1; min-width:0; }
.course-item-title { font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.course-item-lang { font-family:var(--mono); font-size:9px; color:var(--muted); margin-top:2px; }

/* ══ MESSAGE AREA ══ */
.msg-area { flex:1; display:flex; flex-direction:column; overflow:hidden; }
.empty-chat { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:14px; padding:40px; text-align:center; }
.empty-icon  { font-size:58px; opacity:.25; }
.empty-title { font-size:18px; font-weight:800; color:var(--muted); }
.empty-sub   { font-family:var(--mono); font-size:11px; color:var(--muted); line-height:1.7; max-width:280px; }

.messages-scroll { flex:1; overflow-y:auto; padding:20px 24px; display:flex; flex-direction:column; gap:4px; }
.messages-scroll::-webkit-scrollbar { width:5px; }
.messages-scroll::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }

.date-sep { display:flex; align-items:center; gap:12px; margin:12px 0 8px; }
.date-sep-line  { flex:1; height:1px; background:var(--border); }
.date-sep-label { font-family:var(--mono); font-size:10px; color:var(--muted); white-space:nowrap; }

.msg-row { display:flex; gap:8px; align-items:flex-end; max-width:72%; }
.msg-row.mine   { align-self:flex-end; flex-direction:row-reverse; }
.msg-row.theirs { align-self:flex-start; }
.bubble-av { width:26px; height:26px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal));
    display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:800;
    color:#fff; flex-shrink:0; overflow:hidden; margin-bottom:3px; }
.bubble-av img { width:100%; height:100%; object-fit:cover; }
.bubble-av.is-admin-bubble { background:linear-gradient(135deg,#7f1d1d,#dc2626); }

.bubble-wrap { display:flex; flex-direction:column; gap:3px; }
.msg-row.mine   .bubble-wrap { align-items:flex-end; }
.msg-row.theirs .bubble-wrap { align-items:flex-start; }
.bubble { padding:10px 14px; border-radius:18px; font-size:13px; line-height:1.55;
    max-width:100%; word-break:break-word; animation:pop .2s ease both; }
.msg-row.mine .bubble   { background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff;
    border-bottom-right-radius:5px; box-shadow:0 4px 16px rgba(99,102,241,.3); }
.msg-row.theirs .bubble { background:var(--card); color:var(--text); border:1px solid var(--border);
    border-bottom-left-radius:5px; }
.msg-row.theirs.from-admin .bubble { border-color:rgba(239,68,68,.2); background:#1a0a0a; }

/* ── Attachments in bubbles ── */
.att-list { display:flex; flex-direction:column; gap:6px; margin-top:6px; }
.att-item { display:flex; align-items:center; gap:9px; padding:8px 11px; border-radius:10px;
    cursor:pointer; transition:.18s; text-decoration:none; }
.msg-row.mine .att-item   { background:rgba(0,0,0,.2); color:#fff; border:1px solid rgba(255,255,255,.15); }
.msg-row.mine .att-item:hover { background:rgba(0,0,0,.35); }
.msg-row.theirs .att-item { background:rgba(99,102,241,.08); color:var(--text); border:1px solid rgba(99,102,241,.2); }
.msg-row.theirs .att-item:hover { background:rgba(99,102,241,.15); }
.att-icon { font-size:20px; flex-shrink:0; }
.att-info { flex:1; min-width:0; }
.att-name { font-size:12px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.att-size { font-family:var(--mono); font-size:9px; opacity:.65; margin-top:1px; }
.att-dl   { font-size:14px; opacity:.6; flex-shrink:0; }

.bubble-meta { display:flex; align-items:center; gap:5px; }
.btime { font-family:var(--mono); font-size:9px; color:var(--muted); }
.tick  { font-size:10px; }
.tick.read { color:var(--teal); }
.tick.sent { color:var(--muted); }

/* ══ INPUT AREA ══ */
.input-area { padding:12px 20px 16px; background:rgba(13,17,23,.95);
    border-top:1px solid var(--border); flex-shrink:0; backdrop-filter:blur(20px); }

/* Pending file chips above textarea */
.pending-files { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
.file-chip { display:flex; align-items:center; gap:6px; padding:5px 10px; border-radius:8px;
    background:rgba(99,102,241,.12); border:1px solid rgba(99,102,241,.3);
    font-size:11px; font-weight:600; max-width:200px; }
.file-chip-name { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width:0; }
.file-chip-rm { cursor:pointer; opacity:.6; flex-shrink:0; font-size:14px; line-height:1;
    transition:opacity .15s; }
.file-chip-rm:hover { opacity:1; }
.file-chip.uploading { opacity:.6; }
.file-chip.error { border-color:rgba(239,68,68,.4); background:rgba(239,68,68,.08); color:#fca5a5; }

.input-row  { display:flex; gap:10px; align-items:flex-end; }
.input-box  { flex:1; background:var(--card); border:1px solid var(--border); border-radius:14px;
    padding:11px 16px; display:flex; align-items:flex-end; gap:10px; transition:border-color .2s; }
.input-box:focus-within { border-color:rgba(99,102,241,.5); box-shadow:0 0 0 3px rgba(99,102,241,.08); }
.input-box textarea { flex:1; background:none; border:none; outline:none; color:var(--text);
    font-family:var(--font); font-size:13px; resize:none; max-height:120px; line-height:1.5;
    scrollbar-width:thin; }
.input-box textarea::placeholder { color:var(--muted); }

/* Attach button inside input box */
.attach-btn { background:none; border:none; cursor:pointer; color:var(--muted);
    padding:3px; border-radius:7px; transition:.18s; display:flex; align-items:center; flex-shrink:0; }
.attach-btn:hover { color:var(--accent); background:rgba(99,102,241,.1); }

.send-btn { width:44px; height:44px; border-radius:12px; flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),#818cf8); border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center; transition:.18s;
    box-shadow:0 4px 14px rgba(99,102,241,.4); }
.send-btn:hover:not(:disabled) { transform:scale(1.07); box-shadow:0 6px 20px rgba(99,102,241,.55); }
.send-btn:active:not(:disabled) { transform:scale(.95); }
.send-btn:disabled { opacity:.35; cursor:default; }
.send-btn svg { width:18px; height:18px; fill:#fff; }
.input-hint { font-family:var(--mono); font-size:9px; color:var(--muted); margin-top:7px; text-align:center; }

.spinner { display:flex; align-items:center; justify-content:center; gap:8px; padding:20px;
    color:var(--muted); font-family:var(--mono); font-size:11px; }
.spinner::before { content:''; width:16px; height:16px; border-radius:50%;
    border:2px solid var(--border); border-top-color:var(--accent);
    animation:spin .7s linear infinite; flex-shrink:0; }

@keyframes pop    { from{opacity:0;transform:scale(.94) translateY(5px)} to{opacity:1;transform:scale(1) translateY(0)} }
@keyframes pulse  { 0%,100%{opacity:1} 50%{opacity:.5} }
@keyframes spin   { to{transform:rotate(360deg)} }
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="topbar-logo">Lingua<span>Hub</span></div>
    <div class="topbar-mid">
        <div class="conv-info" id="convInfo" style="display:none">
            <div class="conv-av" id="convAvatar"></div>
            <div>
                <div class="conv-title-name" id="convName">—</div>
                <div class="conv-title-sub">Онлайн <span class="online-dot"></span></div>
            </div>
        </div>
    </div>
    <div class="me-badge">
        <div class="me-av">
            <?php if (!empty($myInfo['avatar_url']) && file_exists($myInfo['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($myInfo['avatar_url']) ?>" alt="">
            <?php else: ?>
                <?= $myInitials ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="me-name"><?= htmlspecialchars($myName) ?></div>
            <div class="me-role"><?= $myRoleLabel ?></div>
        </div>
    </div>
</div>

<!-- BODY -->
<div class="chat-wrap">
    <!-- SIDE NAV -->
    <nav class="side-nav" id="sideNav">
        <div class="nav-item" data-view="dashboard" title="Особистий кабінет" onclick="switchView('dashboard')">
            👤
        </div>
        <div class="nav-item active" data-view="messages" title="Особисті повідомлення" onclick="switchView('messages')">
            💬
        </div>
        <?php if ($role === 'teacher'): ?>
        <div class="nav-item" data-view="students" title="Мої учні" onclick="switchView('students')">
            👥
        </div>
        <?php endif; ?>
        <div class="nav-item" data-view="schedule" title="Розклад" onclick="switchView('schedule')">
            📅
        </div>
        <div class="nav-item" data-view="tasks" title="Завдання" onclick="switchView('tasks')">
            ✓
        </div>
        <div class="nav-item" data-view="tests" title="Тести" onclick="switchView('tests')">
            📝
        </div>
        <div class="nav-divider"></div>
        <div class="nav-item" data-view="meet" title="Meet" onclick="switchView('meet')">
            📞
        </div>
    </nav>

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sb-head">
            <div class="sb-head-title">💬 Особисті повідомлення</div>
            <div class="search-box">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted);flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Пошук...">
            </div>
        </div>

        <div class="conv-list" id="convList">
            <div class="spinner">Завантаження</div>
        </div>

        <?php if (!empty($peers)): ?>
        <div class="sb-peers">
            <div class="sb-peers-label">Написати</div>
            <?php
            $adminPeers = array_filter($peers, fn($p) => $p['peer_role'] === 'admin');
            $otherPeers = array_filter($peers, fn($p) => $p['peer_role'] !== 'admin');
            if (!empty($adminPeers)):
                foreach ($adminPeers as $p):
                    $pInit = strtoupper(substr($p['first_name']??'',0,1) . substr($p['last_name']??'',0,1));
                    $pName = trim(($p['first_name']??'') . ' ' . ($p['last_name']??''));
            ?>
            <div class="peer-item is-admin" data-id="<?= htmlspecialchars($p['id']) ?>"
                 data-name="<?= htmlspecialchars($pName) ?>" data-av="<?= htmlspecialchars($p['avatar_url']??'') ?>"
                 data-init="<?= $pInit ?>" data-role="admin" onclick="openOrCreate(this)">
                <div class="peer-av is-admin-av">
                    <?php if (!empty($p['avatar_url']) && file_exists($p['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="">
                    <?php else: ?><?= $pInit ?><?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="peer-name"><?= htmlspecialchars($pName) ?></div>
                    <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">Адміністрація</div>
                </div>
                <span class="peer-role-tag admin">Адмін</span>
            </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($otherPeers) && !empty($adminPeers)): ?>
                <div class="peers-section-divider">Інші</div>
            <?php endif; ?>

            <?php foreach ($otherPeers as $p):
                $pInit = strtoupper(substr($p['first_name']??'',0,1) . substr($p['last_name']??'',0,1));
                $pName = trim(($p['first_name']??'') . ' ' . ($p['last_name']??''));
                $pCtx  = substr($p['context_title']??'', 0, 30);
                $roleLabel = match($p['peer_role']) { 'teacher'=>'Виклад.','student'=>'Студент', default=>htmlspecialchars($p['peer_role']) };
            ?>
            <div class="peer-item" data-id="<?= htmlspecialchars($p['id']) ?>"
                 data-name="<?= htmlspecialchars($pName) ?>" data-av="<?= htmlspecialchars($p['avatar_url']??'') ?>"
                 data-init="<?= $pInit ?>" data-role="<?= htmlspecialchars($p['peer_role']) ?>" onclick="openOrCreate(this)">
                <div class="peer-av">
                    <?php if (!empty($p['avatar_url']) && file_exists($p['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="">
                    <?php else: ?><?= $pInit ?><?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="peer-name"><?= htmlspecialchars($pName) ?></div>
                    <?php if ($pCtx): ?>
                    <div style="font-family:var(--mono);font-size:9px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($pCtx) ?></div>
                    <?php endif; ?>
                </div>
                <span class="peer-role-tag <?= htmlspecialchars($p['peer_role']) ?>"><?= $roleLabel ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div style="padding:10px 10px;border-top:1px solid var(--border);margin-top:auto">
            <button class="theme-toggle" title="Змінити тему" style="width:100%;padding:8px;display:flex;align-items:center;justify-content:center">
                <span class="theme-toggle-icon">☀️</span>
            </button>
        </div>
    </aside>

    <!-- MESSAGE AREA -->
    <div class="msg-area" id="msgArea">
        <div class="empty-chat" id="emptyChat">
            <div class="empty-icon">💬</div>
            <div class="empty-title">Оберіть розмову</div>
            <div class="empty-sub">Виберіть діалог зі списку або напишіть комусь першим</div>
        </div>

        <div id="chatView" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
            <div class="messages-scroll" id="messagesScroll"></div>
            <div class="input-area">
                <!-- Pending file chips -->
                <div class="pending-files" id="pendingFiles"></div>
                <div class="input-row">
                    <div class="input-box">
                        <!-- Hidden file input -->
                        <input type="file" id="fileInput" multiple
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.pptx,.txt,image/*"
                               style="display:none" onchange="onFilesSelected(this)">
                        <!-- Attach button -->
                        <button class="attach-btn" type="button" title="Прикріпити файл"
                                onclick="document.getElementById('fileInput').click()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>
                            </svg>
                        </button>
                        <textarea id="msgInput" rows="1" placeholder="Написати повідомлення..." maxlength="4000"></textarea>
                    </div>
                    <button class="send-btn" id="sendBtn" onclick="sendMsg()" disabled>
                        <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22l-4-9-9-4 20-7z"/></svg>
                    </button>
                </div>
                <div class="input-hint">Ctrl+Enter — надіслати · 📎 — прикріпити файл (PDF, DOCX, зображення до 20 МБ)</div>
            </div>
        </div>
    </div>
</div>

<script>
const ME           = <?= json_encode($me) ?>;
const POLL_MS      = 2500;
const AUTO_OPEN_ID = <?= json_encode($autoOpenUserId) ?>;

let currentConvId = <?= json_encode($activeConvId) ?>;
let lastMsgId     = null;
let pollTimer     = null;
let allConvs      = [];
let currentFilter = '';

// Pending attachments: [{att_id, original_name, mime_type, file_size}]
let pendingAtts   = [];

document.addEventListener('DOMContentLoaded', async () => {
    setupInput();
    await loadConvList();
    if (currentConvId) openConv(currentConvId);
    else if (AUTO_OPEN_ID) await openOrCreateDirect(AUTO_OPEN_ID);
});

/* ══ API ══ */
async function apiGet(params) {
    const qs = new URLSearchParams(params);
    const r  = await fetch('chat_api.php?' + qs);
    return r.json();
}
async function apiPost(action, body) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(body).forEach(([k,v]) => { if (v !== null && v !== undefined) fd.append(k, v); });
    const r = await fetch('chat_api.php', {method:'POST', body:fd});
    return r.json();
}

/* ══ FILE UPLOAD ══ */
function onFilesSelected(input) {
    const files = Array.from(input.files);
    input.value = '';
    files.forEach(uploadFile);
}

async function uploadFile(file) {
    const tmpId = 'tmp_' + Math.random().toString(36).slice(2);
    addChip(tmpId, file.name, true);

    const fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('file', file);

    try {
        const r    = await fetch('chat_api.php', {method:'POST', body:fd});
        const data = await r.json();
        if (data.error) {
            updateChip(tmpId, null, data.error);
        } else {
            pendingAtts.push({
                tmpId,
                att_id:        data.att_id,
                original_name: data.original_name,
                mime_type:     data.mime_type,
                file_size:     data.file_size,
            });
            updateChip(tmpId, data.att_id, null);
        }
    } catch (e) {
        updateChip(tmpId, null, 'Помилка завантаження');
    }
    updateSendBtn();
}

function addChip(tmpId, name, uploading) {
    const wrap = document.getElementById('pendingFiles');
    const el   = document.createElement('div');
    el.className = 'file-chip' + (uploading ? ' uploading' : '');
    el.id        = 'chip_' + tmpId;
    el.innerHTML = `
        <span>${fileEmoji(name)}</span>
        <span class="file-chip-name" title="${esc(name)}">${esc(name)}</span>
        <span class="file-chip-rm" onclick="removeChip('${tmpId}')">✕</span>`;
    wrap.appendChild(el);
}
function updateChip(tmpId, attId, error) {
    const el = document.getElementById('chip_' + tmpId);
    if (!el) return;
    if (error) {
        el.className = 'file-chip error';
        el.querySelector('.file-chip-name').textContent = error;
        setTimeout(() => el.remove(), 3000);
    } else {
        el.classList.remove('uploading');
        el.dataset.attId = attId;
    }
}
function removeChip(tmpId) {
    document.getElementById('chip_' + tmpId)?.remove();
    pendingAtts = pendingAtts.filter(a => a.tmpId !== tmpId);
    updateSendBtn();
}

/* ══ CONVERSATIONS ══ */
async function loadConvList() {
    const data = await apiGet({action:'conversations'});
    allConvs = data.conversations || [];
    
    // Rebuild sidebar HTML
    const sidebar = document.querySelector('.sidebar');
    let html = `
        <div class="sb-head">
            <div class="sb-head-title">💬 Особисті повідомлення</div>
            <div class="search-box">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted);flex-shrink:0"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Пошук...">
            </div>
        </div>
        <div class="conv-list" id="convList">
    `;
    
    const filtered = allConvs.filter(c => {
        const n = ((c.other?.first_name||'') + ' ' + (c.other?.last_name||'')).toLowerCase();
        return n.includes((document.getElementById('searchInput')?.value || '').toLowerCase());
    });
    
    if (!filtered.length) {
        html += '<div style="padding:24px 16px;font-family:var(--mono);font-size:11px;color:var(--muted);text-align:center">Немає розмов</div>';
    } else {
        filtered.forEach(c => {
            const o    = c.other || {};
            const name = ((o.first_name||'') + ' ' + (o.last_name||'')).trim() || '—';
            const init = mkInitials(o.first_name, o.last_name);
            let prev;
            if (c.has_attachment && !c.last_body) prev = '📎 Файл';
            else if (c.has_attachment && c.last_body) prev = '📎 ' + esc(c.last_body.substring(0,30));
            else prev = c.last_body ? esc(c.last_body.substring(0,42)) + (c.last_body.length>42?'…':'') : '<i>Немає повідомлень</i>';
            const time  = c.last_at ? relTime(c.last_at) : '';
            const unrd  = parseInt(c.unread)||0;
            const isAct = c.id === currentConvId;
            const isAdm = o.role === 'admin';
            const avHtml= o.avatar_url ? `<img src="${esc(o.avatar_url)}" alt="">` : init;
            const avStyle = isAdm ? 'background:linear-gradient(135deg,#7f1d1d,#dc2626)' : '';
            
            html += `
            <div class="conv-item${isAct?' active':''}" data-id="${c.id}"
                 data-name="${esc(name)}" data-init="${init}" data-av="${esc(o.avatar_url||'')}"
                 data-role="${esc(o.role||'')}" onclick="openConv('${c.id}')">
                <div class="conv-item-av" style="${avStyle}">${avHtml}<div class="ring"></div></div>
                <div class="conv-info-text">
                    <div class="conv-name">${esc(name)}${isAdm?' <span style="font-family:var(--mono);font-size:8px;color:#fca5a5;background:rgba(239,68,68,.15);padding:1px 5px;border-radius:4px">АДМІН</span>':''}</div>
                    <div class="conv-preview">${prev}</div>
                </div>
                <div class="conv-meta">
                    <span class="conv-time">${time}</span>
                    ${unrd ? `<span class="conv-unread">${unrd}</span>` : ''}
                </div>
            </div>`;
        });
    }
    
    html += '</div>';
    
    // Add peers section
    const peers = <?= json_encode($peers) ?>;
    if (peers && peers.length > 0) {
        html += '<div class="sb-peers">';
        html += '<div class="sb-peers-label">Написати</div>';
        
        const adminPeers = peers.filter(p => p.peer_role === 'admin');
        const otherPeers = peers.filter(p => p.peer_role !== 'admin');
        
        adminPeers.forEach(p => {
            const pInit = (p.first_name[0]||'') + (p.last_name[0]||'');
            const pName = (p.first_name||'') + ' ' + (p.last_name||'');
            const avHtml = p.avatar_url ? `<img src="${esc(p.avatar_url)}" alt="">` : pInit;
            
            html += `
            <div class="peer-item is-admin" data-id="${p.id}"
                 data-name="${esc(pName)}" data-av="${esc(p.avatar_url||'')}"
                 data-init="${pInit}" data-role="admin" onclick="openOrCreate(this)">
                <div class="peer-av is-admin-av">${avHtml}</div>
                <div style="flex:1;min-width:0">
                    <div class="peer-name">${esc(pName)}</div>
                    <div style="font-family:var(--mono);font-size:9px;color:var(--muted)">Адміністрація</div>
                </div>
                <span class="peer-role-tag admin">Адмін</span>
            </div>`;
        });
        
        if (otherPeers.length > 0 && adminPeers.length > 0) {
            html += '<div class="peers-section-divider">Інші</div>';
        }
        
        otherPeers.forEach(p => {
            const pInit = (p.first_name[0]||'') + (p.last_name[0]||'');
            const pName = (p.first_name||'') + ' ' + (p.last_name||'');
            const pCtx  = (p.context_title||'').substring(0, 30);
            const roleLabel = p.peer_role === 'teacher' ? 'Виклад.' : p.peer_role === 'student' ? 'Студент' : p.peer_role;
            const avHtml = p.avatar_url ? `<img src="${esc(p.avatar_url)}" alt="">` : pInit;
            
            html += `
            <div class="peer-item" data-id="${p.id}"
                 data-name="${esc(pName)}" data-av="${esc(p.avatar_url||'')}"
                 data-init="${pInit}" data-role="${p.peer_role}" onclick="openOrCreate(this)">
                <div class="peer-av">${avHtml}</div>
                <div style="flex:1;min-width:0">
                    <div class="peer-name">${esc(pName)}</div>
                    ${pCtx ? `<div style="font-family:var(--mono);font-size:9px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(pCtx)}</div>` : ''}
                </div>
                <span class="peer-role-tag ${p.peer_role}">${roleLabel}</span>
            </div>`;
        });
        
        html += '</div>';
    }
    
    sidebar.innerHTML = html;
    
    // Re-attach search listener
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', renderConvList);
    }
    
    renderConvList();
}

function renderConvList() {
    const filter = (document.getElementById('searchInput').value || '').toLowerCase();
    const el     = document.getElementById('convList');
    const filtered = allConvs.filter(c => {
        const n = ((c.other?.first_name||'') + ' ' + (c.other?.last_name||'')).toLowerCase();
        return n.includes(filter);
    });
    if (!filtered.length) {
        el.innerHTML = '<div style="padding:24px 16px;font-family:var(--mono);font-size:11px;color:var(--muted);text-align:center">Немає розмов</div>';
        return;
    }
    el.innerHTML = filtered.map(c => {
        const o    = c.other || {};
        const name = ((o.first_name||'') + ' ' + (o.last_name||'')).trim() || '—';
        const init = mkInitials(o.first_name, o.last_name);
        let prev;
        if (c.has_attachment && !c.last_body) prev = '📎 Файл';
        else if (c.has_attachment && c.last_body) prev = '📎 ' + esc(c.last_body.substring(0,30));
        else prev = c.last_body ? esc(c.last_body.substring(0,42)) + (c.last_body.length>42?'…':'') : '<i>Немає повідомлень</i>';
        const time  = c.last_at ? relTime(c.last_at) : '';
        const unrd  = parseInt(c.unread)||0;
        const isAct = c.id === currentConvId;
        const isAdm = o.role === 'admin';
        const avHtml= o.avatar_url ? `<img src="${esc(o.avatar_url)}" alt="">` : init;
        const avStyle = isAdm ? 'background:linear-gradient(135deg,#7f1d1d,#dc2626)' : '';
        return `
        <div class="conv-item${isAct?' active':''}" data-id="${c.id}"
             data-name="${esc(name)}" data-init="${init}" data-av="${esc(o.avatar_url||'')}"
             data-role="${esc(o.role||'')}" onclick="openConv('${c.id}')">
            <div class="conv-item-av" style="${avStyle}">${avHtml}<div class="ring"></div></div>
            <div class="conv-info-text">
                <div class="conv-name">${esc(name)}${isAdm?' <span style="font-family:var(--mono);font-size:8px;color:#fca5a5;background:rgba(239,68,68,.15);padding:1px 5px;border-radius:4px">АДМІН</span>':''}</div>
                <div class="conv-preview">${prev}</div>
            </div>
            <div class="conv-meta">
                <span class="conv-time">${time}</span>
                ${unrd ? `<span class="conv-unread">${unrd}</span>` : ''}
            </div>
        </div>`;
    }).join('');
}

document.getElementById('searchInput').addEventListener('input', renderConvList);

async function openConv(convId) {
    stopPoll();
    currentConvId = convId;
    lastMsgId     = null;

    const convData = allConvs.find(c => c.id === convId);
    if (convData?.other) setTopBar(convData.other);
    else {
        const el = document.querySelector(`.conv-item[data-id="${convId}"]`);
        if (el) setTopBarRaw(el.dataset.name, el.dataset.init, el.dataset.av);
    }
    document.querySelectorAll('.conv-item').forEach(el =>
        el.classList.toggle('active', el.dataset.id === convId)
    );
    document.getElementById('emptyChat').style.display = 'none';
    const cv = document.getElementById('chatView');
    cv.style.display = 'flex'; cv.style.flexDirection = 'column';
    document.getElementById('messagesScroll').innerHTML = '<div class="spinner">Завантаження</div>';
    history.replaceState(null,'',`chat.php?conv=${convId}`);

    const data = await apiGet({action:'messages', conv_id: convId});
    renderMsgs(data.messages || [], data.me);
    scrollBottom();
    updateSendBtn();
    startPoll();
}

async function openOrCreate(el) {
    const data = await apiPost('open_or_create', {other_id: el.dataset.id});
    if (data.conv_id) { await loadConvList(); openConv(data.conv_id); }
}
async function openOrCreateDirect(otherId) {
    try {
        const data = await apiPost('open_or_create', {other_id: otherId});
        if (data.error) alert('Помилка: ' + data.error);
        else if (data.conv_id) { await loadConvList(); openConv(data.conv_id); }
    } catch(e) { alert('Помилка: ' + e.message); }
}

function setTopBar(other) {
    const name = ((other.first_name||'') + ' ' + (other.last_name||'')).trim();
    setTopBarRaw(name, mkInitials(other.first_name, other.last_name), other.avatar_url||'', other.role==='admin');
}
function setTopBarRaw(name, init, av, isAdmin = false) {
    const info = document.getElementById('convInfo');
    info.style.display = 'flex';
    const avEl = document.getElementById('convAvatar');
    avEl.style.background = isAdmin ? 'linear-gradient(135deg,#7f1d1d,#dc2626)' : '';
    avEl.innerHTML = av ? `<img src="${esc(av)}" alt="">` : init;
    document.getElementById('convName').textContent = name + (isAdmin ? ' (Адмін)' : '');
}

/* ══ RENDER MESSAGES ══ */
function renderMsgs(msgs, meId) {
    const el = document.getElementById('messagesScroll');
    el.innerHTML = '';
    let lastDate = '';
    msgs.forEach(m => {
        const d = (m.created_at||'').substring(0,10);
        if (d && d !== lastDate) { lastDate = d; el.appendChild(mkDateSep(m.created_at)); }
        el.appendChild(mkBubble(m, meId));
        if (m.id) lastMsgId = m.id;
    });
}
function appendMsg(m, meId) {
    const el   = document.getElementById('messagesScroll');
    const near = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    el.appendChild(mkBubble(m, meId));
    if (m.id) lastMsgId = m.id;
    if (near) scrollBottom();
}

function mkDateSep(ts) {
    const div = document.createElement('div');
    div.className = 'date-sep';
    div.innerHTML = `<div class="date-sep-line"></div><div class="date-sep-label">${fmtDate(ts)}</div><div class="date-sep-line"></div>`;
    return div;
}

function mkBubble(m, meId) {
    const isMine  = m.sender_id === meId;
    const isAdmin = m.role === 'admin';
    const init    = mkInitials(m.first_name, m.last_name);
    const avClass = 'bubble-av' + (isAdmin && !isMine ? ' is-admin-bubble' : '');
    const avHtml  = m.avatar_url ? `<img src="${esc(m.avatar_url)}" alt="">` : init;
    const tick    = isMine ? `<span class="tick ${m.is_read?'read':'sent'}">${m.is_read?'✓✓':'✓'}</span>` : '';
    const rowClass = `msg-row ${isMine?'mine':'theirs'}${isAdmin&&!isMine?' from-admin':''}`;

    // Build attachments HTML
    let attsHtml = '';
    if (m.attachments?.length) {
        attsHtml = '<div class="att-list">' +
            m.attachments.map(a => `
                <a class="att-item" href="chat_api.php?action=download_file&att_id=${esc(a.att_id)}" download="${esc(a.original_name)}" title="${esc(a.original_name)}">
                    <span class="att-icon">${fileEmoji(a.original_name)}</span>
                    <div class="att-info">
                        <div class="att-name">${esc(a.original_name)}</div>
                        <div class="att-size">${fmtSize(a.file_size)}</div>
                    </div>
                    <span class="att-dl">↓</span>
                </a>`).join('') +
            '</div>';
    }

    const bodyHtml = m.body ? `<div class="bubble">${escHtml(m.body)}${attsHtml}</div>` :
                               `<div class="bubble" style="padding:8px 12px">${attsHtml}</div>`;

    const row = document.createElement('div');
    row.className  = rowClass;
    row.dataset.id = m.id;
    row.innerHTML  = `
        <div class="${avClass}">${avHtml}</div>
        <div class="bubble-wrap">
            ${bodyHtml}
            <div class="bubble-meta">
                <span class="btime">${fmtTime(m.created_at)}</span>
                ${tick}
            </div>
        </div>`;
    return row;
}

/* ══ POLLING ══ */
function startPoll() {
    pollTimer = setInterval(async () => {
        if (!currentConvId) return;
        const params = {action:'poll', conv_id: currentConvId};
        if (lastMsgId) params.after = lastMsgId;
        const data = await apiGet(params);
        if (data.messages?.length) {
            data.messages.forEach(m => appendMsg(m, data.me));
            loadConvList();
        }
    }, POLL_MS);
}
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

/* ══ SEND ══ */
async function sendMsg() {
    const inp  = document.getElementById('msgInput');
    const body = inp.value.trim();
    const attIds = pendingAtts.filter(a => a.att_id).map(a => a.att_id);

    if (!body && !attIds.length) return;
    if (!currentConvId) return;

    inp.value = ''; inp.style.height = 'auto';
    // Clear pending UI
    const prevAtts = [...pendingAtts];
    pendingAtts = [];
    document.getElementById('pendingFiles').innerHTML = '';
    document.getElementById('sendBtn').disabled = true;

    try {
        const data = await apiPost('send', {
            conv_id:        currentConvId,
            body:           body,
            attachment_ids: JSON.stringify(attIds),
        });
        if (data.error) {
            alert('Помилка: ' + data.error);
            inp.value = body;
            pendingAtts = prevAtts;
            renderPendingChips();
        } else if (data.message) {
            appendMsg(data.message, ME);
            await loadConvList();
        }
    } catch(e) {
        alert('Помилка: ' + e.message);
        inp.value = body;
        pendingAtts = prevAtts;
        renderPendingChips();
    }
    updateSendBtn();
    inp.focus();
}

function renderPendingChips() {
    const wrap = document.getElementById('pendingFiles');
    wrap.innerHTML = '';
    pendingAtts.forEach(a => addChip(a.tmpId, a.original_name, false));
}

/* ══ INPUT ══ */
function setupInput() {
    const inp = document.getElementById('msgInput');
    inp.addEventListener('input', () => {
        inp.style.height = 'auto';
        inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
        updateSendBtn();
    });
    inp.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault(); sendMsg();
        }
    });
    // Drag & drop support
    const inputArea = document.querySelector('.input-area');
    inputArea.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
    inputArea.addEventListener('drop', e => {
        e.preventDefault();
        const files = Array.from(e.dataTransfer.files);
        files.forEach(uploadFile);
    });
}
function updateSendBtn() {
    const inp     = document.getElementById('msgInput');
    const hasText = inp.value.trim().length > 0;
    const hasAtts = pendingAtts.some(a => a.att_id);
    document.getElementById('sendBtn').disabled = (!hasText && !hasAtts) || !currentConvId;
}

/* ══ HELPERS ══ */
function scrollBottom() {
    const el = document.getElementById('messagesScroll');
    requestAnimationFrame(() => el.scrollTop = el.scrollHeight);
}
function mkInitials(fn, ln) {
    return ((fn||'').charAt(0) + (ln||'').charAt(0)).toUpperCase() || '??';
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}
function fmtTime(ts) {
    if (!ts) return '';
    return new Date(ts).toLocaleTimeString('uk-UA', {hour:'2-digit', minute:'2-digit'});
}
function fmtDate(ts) {
    if (!ts) return '';
    const d=new Date(ts), now=new Date();
    if (d.toDateString()===now.toDateString()) return 'Сьогодні';
    const yest=new Date(now); yest.setDate(now.getDate()-1);
    if (d.toDateString()===yest.toDateString()) return 'Вчора';
    return d.toLocaleDateString('uk-UA', {day:'numeric', month:'long', year:'numeric'});
}
function relTime(ts) {
    if (!ts) return '';
    const diff = (Date.now() - new Date(ts).getTime()) / 1000;
    if (diff < 60)    return 'щойно';
    if (diff < 3600)  return Math.floor(diff/60) + ' хв';
    if (diff < 86400) return Math.floor(diff/3600) + ' год';
    return new Date(ts).toLocaleDateString('uk-UA', {day:'numeric', month:'short'});
}
function fmtSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024)       return bytes + ' Б';
    if (bytes < 1048576)    return (bytes/1024).toFixed(1) + ' КБ';
    return (bytes/1048576).toFixed(1) + ' МБ';
}
function fileEmoji(name) {
    const ext = (name||'').split('.').pop().toLowerCase();
    const map = { pdf:'📄', doc:'📝', docx:'📝', xls:'📊', xlsx:'📊',
                  pptx:'📑', txt:'📃', jpg:'🖼', jpeg:'🖼', png:'🖼', gif:'🖼', webp:'🖼' };
    return map[ext] || '📁';
}

function filterByCategory(cat) {
    currentFilter = cat;
    document.querySelectorAll('.nav-item').forEach(el => {
        const f = el.dataset.filter || '';
        el.classList.toggle('active', f === cat);
    });
    renderConvList();
}

function switchView(view) {
    // Update nav active state
    document.querySelectorAll('#sideNav .nav-item[data-view]').forEach(el =>
        el.classList.toggle('active', el.dataset.view === view)
    );
    
    // Hide chat area
    document.getElementById('emptyChat').style.display = 'flex';
    document.getElementById('chatView').style.display = 'none';
    stopPoll();
    currentConvId = '';
    
    if (view === 'messages') {
        document.querySelector('.sidebar').style.display = 'flex';
        loadConvList();
    } else {
        // Other views redirect to dashboard or other pages
        const urls = {
            'dashboard': '<?= $dashboardUrl ?>',
            'students': '<?= $role === "teacher" ? "students.php" : "dashboard_student.php" ?>',
            'schedule': '<?= $role === "teacher" ? "schedule_teacher.php" : "schedule_student.php" ?>',
            'tasks': 'tasks.php',
            'tests': 'tasks.php',
            'meet': 'meet.php',
        };
        if (urls[view]) window.location.href = urls[view];
    }
}

window.addEventListener('beforeunload', stopPoll);
</script>
<script src="theme-switcher.js"></script>
</body>
</html>