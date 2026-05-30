<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$studentId = $_SESSION['user_id'];

// Fetch student profile data
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, phone, avatar_url, created_at, last_activity
    FROM users WHERE id = :id
");
$stmt->execute(['id' => $studentId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$fullName    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$studentName = $fullName ?: 'Студент';

$stmtStats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM enrollments WHERE student_id = :sid AND status = 'active') AS total_groups,
        (SELECT COUNT(DISTINCT l.id)
         FROM lessons l
         JOIN enrollments e ON e.course_id = l.course_id
         WHERE e.student_id = :sid AND e.status = 'active') AS total_lessons,
        (SELECT COUNT(DISTINCT la.lesson_id)
         FROM lesson_attendance la
         JOIN lessons l ON l.id = la.lesson_id
         JOIN enrollments e ON e.course_id = l.course_id
         WHERE la.student_id = :sid AND la.attended = true
           AND e.student_id = :sid AND e.status = 'active') AS attended,
        (SELECT COUNT(DISTINCT l.id)
         FROM lessons l
         JOIN enrollments e ON e.course_id = l.course_id
         WHERE e.student_id = :sid AND e.status = 'active'
           AND l.scheduled_at >= NOW()) AS upcoming_lessons
");
$stmtStats->execute(['sid' => $studentId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
// Handle form submissions (avatar, profile update, etc)
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* -- Оновлення профілю -- */
    if ($_POST['action'] === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $email     = trim($_POST['email']      ?? '');
        $phone     = trim($_POST['phone']      ?? '');

        if ($firstName === '' || $email === '') {
            $errorMsg = "Ім'я та email обов'язкові.";
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e AND id != :id");
            $chk->execute(['e' => $email, 'id' => $studentId]);
            if ($chk->fetch()) {
                $errorMsg = 'Цей email вже використовується іншим користувачем.';
            } else {
                $pdo->prepare("
                    UPDATE users
                    SET first_name=:fn, last_name=:ln, email=:e, phone=:p, updated_at=NOW()
                    WHERE id=:id
                ")->execute(['fn'=>$firstName,'ln'=>$lastName,'e'=>$email,'p'=>$phone,'id'=>$studentId]);

                $user['first_name'] = $firstName;
                $user['last_name']  = $lastName;
                $user['email']      = $email;
                $user['phone']      = $phone;
                $fullName           = trim("$firstName $lastName");
                $studentName        = $fullName;
                $_SESSION['name']   = $fullName;
                $successMsg         = 'Профіль успішно оновлено!';
            }
        }
    }

    /* -- Зміна пароля -- */
    if ($_POST['action'] === 'change_password') {
        $oldPwd  = $_POST['old_password']  ?? '';
        $newPwd  = $_POST['new_password']  ?? '';
        $confPwd = $_POST['conf_password'] ?? '';

        $stmtPwd = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmtPwd->execute(['id' => $studentId]);
        $row = $stmtPwd->fetch();

        if (!password_verify($oldPwd, $row['password_hash'])) {
            $errorMsg = 'Поточний пароль невірний.';
        } elseif (strlen($newPwd) < 6) {
            $errorMsg = 'Новий пароль має містити щонайменше 6 символів.';
        } elseif ($newPwd !== $confPwd) {
            $errorMsg = 'Паролі не збігаються.';
        } else {
            $hash = password_hash($newPwd, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=:h, updated_at=NOW() WHERE id=:id")
                ->execute(['h' => $hash, 'id' => $studentId]);
            $successMsg = 'Пароль успішно змінено!';
        }
    }

    /* -- Аватар -- */
    if ($_POST['action'] === 'upload_avatar' && isset($_FILES['avatar'])) {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($file['type'], $allowed)) {
            $errorMsg = 'Дозволені формати: JPG, PNG, WEBP, GIF.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errorMsg = 'Файл занадто великий (макс. 2 МБ).';
        } else {
            $dir = 'uploads/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'student_' . $studentId . '_' . time() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dir . $filename);
            $pdo->prepare("UPDATE users SET avatar_url=:a, updated_at=NOW() WHERE id=:id")
                ->execute(['a' => $dir . $filename, 'id' => $studentId]);
            $user['avatar_url'] = $dir . $filename;
            $successMsg = 'Аватар оновлено!';
        }
    }
}

// Update last activity timestamp
$pdo->prepare("UPDATE users SET last_activity=NOW() WHERE id=:id")
    ->execute(['id' => $studentId]);

// Helper variables for template (initials, dates, attendance)
$fn       = $user['first_name'] ?? '';
$ln       = $user['last_name']  ?? '';
$initials = strtoupper((substr($fn, 0, 1) ?: '') . (substr($ln, 0, 1) ?: '')) ?: 'S';
$memberSince = !empty($user['created_at'])    ? (new DateTime($user['created_at']))->format('d.m.Y')         : '—';
$lastActive  = !empty($user['last_activity']) ? (new DateTime($user['last_activity']))->format('d.m.Y, H:i') : '—';

/* ─── Відсоток відвідуваності ─── */
$attendPct = ($stats['total_lessons'] > 0)
    ? round($stats['attended'] / $stats['total_lessons'] * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Профіль | LinguaSchool</title>
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

/* ══ SIDEBAR ══ */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar);
    height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    padding: 28px 18px;
    z-index: 100;
    gap: 6px;
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
    padding: 0 8px;
    margin: 10px 0 4px;
}

.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    text-decoration: none;
    color: var(--muted);
    font-size: 13px; font-weight: 600;
    transition: .2s;
    border: 1px solid transparent;
}

.nav-item:hover, .nav-item.active {
    background: rgba(99,102,241,.12);
    color: var(--text);
    border-color: rgba(99,102,241,.25);
}

.nav-item.active { color: #a5b4fc; }
.nav-icon { font-size: 15px; width: 20px; text-align: center; }

.sidebar-bottom {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 8px;
}

.logout-side {
    display: block;
    padding: 9px 12px;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.2);
    border-radius: 10px;
    color: #fca5a5;
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    text-decoration: none;
    text-align: center;
    transition: .2s;
}

.logout-side:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }

/* ══ PAGE ══ */
.page {
    margin-left: var(--sidebar);
    flex: 1;
    position: relative; z-index: 1;
    display: flex; flex-direction: column;
    min-height: 100vh;
}

/* ── TOPBAR ── */
.topbar {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 32px;
    border-bottom: 1px solid var(--border);
    background: rgba(13,17,23,.88);
    backdrop-filter: blur(20px);
    position: sticky; top: 0; z-index: 50;
}

.topbar-back {
    display: flex; align-items: center; gap: 8px;
    text-decoration: none; color: var(--muted);
    font-family: var(--mono); font-size: 12px;
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: 9px;
    transition: .2s;
}

.topbar-back:hover { color: var(--text); border-color: var(--accent); }

.topbar-title {
    font-size: 16px; font-weight: 800;
    background: linear-gradient(90deg, var(--text), #a5b4fc);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}

.topbar-date {
    font-family: var(--mono);
    font-size: 11px; color: var(--muted);
    margin-left: auto;
}

/* ── CONTENT ── */
.content {
    padding: 32px;
    flex: 1;
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
    align-items: start;
}

/* ─ ALERT ─ */
.alert {
    grid-column: 1/-1;
    padding: 14px 20px;
    border-radius: 12px;
    font-family: var(--mono); font-size: 12px; font-weight: 600;
    display: flex; align-items: center; gap: 10px;
    animation: fadeUp .3s ease;
}

.alert.success {
    background: rgba(34,197,94,.1);
    border: 1px solid rgba(34,197,94,.3);
    color: #86efac;
}

.alert.error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: #fca5a5;
}

/* ─ PROFILE CARD (left) ─ */
.profile-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
    animation: fadeUp .4s ease;
}

.profile-cover {
    height: 100px;
    background: linear-gradient(135deg, rgba(34,211,238,.4) 0%, rgba(99,102,241,.3) 100%);
    position: relative;
}

.profile-cover::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(
        -45deg,
        transparent,
        transparent 10px,
        rgba(255,255,255,.02) 10px,
        rgba(255,255,255,.02) 20px
    );
}

.profile-avatar-wrap {
    display: flex; justify-content: center;
    margin-top: -40px;
    margin-bottom: 16px;
    position: relative;
}

.profile-avatar {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--teal) 0%, var(--accent) 100%);
    border: 4px solid var(--card);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 800;
    color: white;
    overflow: hidden;
    position: relative;
    box-shadow: 0 0 32px rgba(34,211,238,.3);
}

.profile-avatar img {
    width: 100%; height: 100%;
    object-fit: cover;
    position: absolute; inset: 0;
}

.avatar-edit-btn {
    position: absolute;
    bottom: 0; right: calc(50% - 40px + 2px);
    width: 26px; height: 26px;
    background: var(--teal);
    border: 2px solid var(--card);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; cursor: pointer;
    transition: .2s;
}

.avatar-edit-btn:hover { background: #67e8f9; transform: scale(1.1); }

.profile-body { padding: 0 24px 24px; text-align: center; }

.profile-name { font-size: 20px; font-weight: 800; margin-bottom: 4px; }

.profile-role {
    font-family: var(--mono); font-size: 10px;
    color: var(--teal); text-transform: uppercase; letter-spacing: 1.5px;
    margin-bottom: 6px;
}

.profile-email {
    font-family: var(--mono); font-size: 11px;
    color: var(--muted); margin-bottom: 20px;
}

.profile-divider { height: 1px; background: var(--border); margin: 0 0 20px; }

.profile-meta {
    display: flex; flex-direction: column; gap: 12px;
    text-align: left;
}

.meta-row {
    display: flex; align-items: center; gap: 10px;
}

.meta-icon {
    width: 32px; height: 32px;
    background: rgba(34,211,238,.08);
    border: 1px solid rgba(34,211,238,.18);
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}

.meta-info {}
.meta-label { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
.meta-value { font-size: 12px; font-weight: 600; margin-top: 1px; }

/* ─ ATTENDANCE BAR ─ */
.attend-wrap {
    padding: 0 24px 20px;
}

.attend-header {
    display: flex; justify-content: space-between;
    margin-bottom: 8px;
}

.attend-label {
    font-family: var(--mono); font-size: 9px;
    color: var(--muted); text-transform: uppercase; letter-spacing: 1px;
}

.attend-pct {
    font-family: var(--mono); font-size: 11px; font-weight: 700;
    color: <?php echo $attendPct >= 75 ? 'var(--green)' : ($attendPct >= 50 ? 'var(--amber)' : 'var(--red)'); ?>;
}

.attend-track {
    height: 6px; border-radius: 99px;
    background: var(--border);
    overflow: hidden;
}

.attend-bar {
    height: 100%; border-radius: 99px;
    transition: width 1s ease;
}

/* ─ STATS MINI ─ */
.stats-mini {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    padding: 0 24px 24px;
}

.stat-mini {
    background: rgba(34,211,238,.05);
    border: 1px solid rgba(34,211,238,.12);
    border-radius: 12px;
    padding: 14px;
    text-align: center;
}

.stat-mini-num {
    font-size: 26px; font-weight: 800;
    background: linear-gradient(135deg, var(--teal), #a5b4fc);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    line-height: 1;
}

.stat-mini-label {
    font-family: var(--mono); font-size: 9px;
    color: var(--muted); margin-top: 5px;
    text-transform: uppercase; letter-spacing: .5px;
}

/* ─ RIGHT COLUMN ─ */
.right-col {
    display: flex; flex-direction: column; gap: 20px;
}

/* ─ CARD ─ */
.form-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
    animation: fadeUp .4s ease;
}

.form-card:nth-child(2) { animation-delay: .06s; }
.form-card:nth-child(3) { animation-delay: .12s; }

.card-header {
    display: flex; align-items: center; gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.02);
}

.card-header-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
}

.card-header-icon.purple { background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.25); }
.card-header-icon.amber  { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.22); }
.card-header-icon.red    { background: rgba(239,68,68,.10);  border: 1px solid rgba(239,68,68,.20); }

.card-title { font-size: 15px; font-weight: 800; }
.card-sub   { font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 2px; }

.card-body { padding: 24px; }

/* ─ FORM ─ */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-grid.single { grid-template-columns: 1fr; }

.field label {
    display: block;
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); text-transform: uppercase; letter-spacing: 1px;
    margin-bottom: 6px;
}

.field input,
.field textarea {
    width: 100%;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 14px;
    color: var(--text);
    font-family: var(--font); font-size: 13px;
    outline: none;
    transition: border-color .2s;
}

.field textarea {
    resize: vertical;
    min-height: 90px;
    font-family: var(--font);
}

.field input:focus,
.field textarea:focus {
    border-color: var(--teal);
    background: rgba(34,211,238,.04);
}

.field input::placeholder,
.field textarea::placeholder { color: var(--muted); }

.field input.valid {
    border-color: #22c55e;
    background: rgba(34,197,94,.06);
}

.field input.invalid {
    border-color: #ef4444;
    background: rgba(239,68,68,.06);
}

.field-hint {
    display: block;
    font-family: var(--mono); font-size: 9px;
    color: #ff5252; margin-top: 5px;
    min-height: 16px;
}

.field-full { grid-column: 1/-1; }

/* ─ BUTTON ─ */
.btn-row {
    display: flex; align-items: center; gap: 12px;
    margin-top: 20px;
}

.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 24px;
    border-radius: 10px;
    border: none; cursor: pointer;
    font-family: var(--font); font-size: 13px; font-weight: 700;
    transition: .2s;
}

.btn-primary {
    background: linear-gradient(135deg, var(--teal), #67e8f9);
    color: #07080f;
    box-shadow: 0 4px 16px rgba(34,211,238,.25);
}

.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(34,211,238,.35); }

/* ─ PASSWORD STRENGTH ─ */
.pwd-strength {
    height: 4px; border-radius: 99px;
    background: var(--border);
    margin-top: 8px;
    overflow: hidden;
}

.pwd-strength-bar {
    height: 100%;
    border-radius: 99px;
    width: 0;
    transition: width .3s, background .3s;
}

/* ─ AVATAR UPLOAD ZONE ─ */
.avatar-drop {
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 28px;
    text-align: center;
    cursor: pointer;
    transition: .2s;
    position: relative;
}

.avatar-drop:hover,
.avatar-drop.drag-over {
    border-color: var(--teal);
    background: rgba(34,211,238,.05);
}

.avatar-drop input[type=file] {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer; width: 100%; height: 100%;
}

.avatar-drop-icon { font-size: 32px; margin-bottom: 10px; }
.avatar-drop-text { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
.avatar-drop-sub { font-family: var(--mono); font-size: 10px; color: var(--muted); }
.avatar-preview { display: none; width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin: 0 auto 12px; border: 3px solid var(--teal); }

/* ── ANIMATIONS ── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── RESPONSIVE ── */
@media(max-width:960px) {
    .content { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .stats-mini { grid-template-columns: repeat(4,1fr); }
}
</style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">🎓</div>
        <div class="logo-text">EduSpace</div>
    </div>

    <span class="nav-label">Меню</span>
    <a class="nav-item" href="dashboard_student.php"><span class="nav-icon">📚</span> Мій профіль</a>
    <a class="nav-item" href="my_lessons.php"><span class="nav-icon">📖</span> Уроки</a>
    <a class="nav-item" href="schedule_student.php"><span class="nav-icon">📅</span> Розклад</a>
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
        <a class="topbar-back" href="dashboard_student.php">← Назад</a>
        <div class="topbar-title">Профіль студента</div>
        <div class="topbar-date" id="dateLabel"></div>
    </div>

    <div class="content">

        <!-- ALERTS -->
        <?php if ($successMsg): ?>
        <div class="alert success">✅ <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert error">❌ <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- ── LEFT: PROFILE CARD ── -->
        <div>
            <div class="profile-card">
                <div class="profile-cover"></div>
                <div class="profile-avatar-wrap">
                    <div class="profile-avatar">
                        <?php if (!empty($user['avatar_url']) && file_exists($user['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar">
                        <?php else: ?>
                        <?= htmlspecialchars($initials) ?>
                        <?php endif; ?>
                    </div>
                    <label class="avatar-edit-btn" for="quickAvatarInput" title="Змінити аватар">✏️</label>
                </div>

                <div class="profile-body">
                    <div class="profile-name"><?= htmlspecialchars($fullName) ?></div>
                    <div class="profile-role">Студент</div>
                    <div class="profile-email"><?= htmlspecialchars($user['email']) ?></div>

                    <div class="profile-divider"></div>

                    <div class="profile-meta">
                        <div class="meta-row">
                            <div class="meta-icon">📅</div>
                            <div class="meta-info">
                                <div class="meta-label">Учасник з</div>
                                <div class="meta-value"><?= $memberSince ?></div>
                            </div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-icon">🕐</div>
                            <div class="meta-info">
                                <div class="meta-label">Остання активність</div>
                                <div class="meta-value"><?= $lastActive ?></div>
                            </div>
                        </div>
                        <?php if (!empty($user['phone'])): ?>
                        <div class="meta-row">
                            <div class="meta-icon">📱</div>
                            <div class="meta-info">
                                <div class="meta-label">Телефон</div>
                                <div class="meta-value"><?= htmlspecialchars($user['phone']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Відвідуваність -->
                <div class="attend-wrap">
                    <div class="attend-header">
                        <span class="attend-label">Відвідуваність</span>
                        <span class="attend-pct"><?= $attendPct ?>%</span>
                    </div>
                    <div class="attend-track">
                        <div class="attend-bar" style="width: <?= $attendPct ?>%; background: <?php echo $attendPct >= 75 ? 'linear-gradient(90deg,#16a34a,var(--green))' : ($attendPct >= 50 ? 'linear-gradient(90deg,#d97706,var(--amber))' : 'linear-gradient(90deg,#dc2626,var(--red))'); ?>;"></div>
                    </div>
                </div>

                <div class="stats-mini">
                    <div class="stat-mini">
                        <div class="stat-mini-num"><?= (int)$stats['total_groups'] ?></div>
                        <div class="stat-mini-label">Груп</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-num"><?= (int)$stats['total_lessons'] ?></div>
                        <div class="stat-mini-label">Уроків</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-num"><?= (int)$stats['attended'] ?></div>
                        <div class="stat-mini-label">Відвідано</div>
                    </div>
                    <div class="stat-mini">
                        <div class="stat-mini-num"><?= (int)$stats['upcoming_lessons'] ?></div>
                        <div class="stat-mini-label">Заплановано</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── RIGHT: FORMS ── -->
        <div class="right-col">

            <!-- Форма: Особисті дані -->
            <div class="form-card">
                <div class="card-header">
                    <div class="card-header-icon purple">👤</div>
                    <div>
                        <div class="card-title">Особисті дані</div>
                        <div class="card-sub">Ім'я, email та контактна інформація</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-grid">
                            <div class="field">
                                <label>Ім'я *</label>
                                <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" placeholder="Іван" required>
                            </div>
                            <div class="field">
                                <label>Прізвище</label>
                                <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" placeholder="Іваненко">
                            </div>
                            <div class="field">
                                <label>Email *</label>
                                <input type="email" id="profileEmailInput" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="student@school.ua" required>
                                <div class="field-hint" id="profileEmailHint"></div>
                            </div>
                            <div class="field">
                                <label>Телефон</label>
                                <input type="text" id="profilePhoneInput" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+380 xx xxx xx xx">
                                <div class="field-hint" id="profilePhoneHint"></div>
                            </div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn-primary">💾 Зберегти зміни</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Форма: Зміна пароля -->
            <div class="form-card">
                <div class="card-header">
                    <div class="card-header-icon amber">🔐</div>
                    <div>
                        <div class="card-title">Зміна пароля</div>
                        <div class="card-sub">Мінімум 6 символів, рекомендується 12+</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid single">
                            <div class="field">
                                <label>Поточний пароль</label>
                                <input type="password" name="old_password" placeholder="••••••••" required>
                            </div>
                            <div class="field">
                                <label>Новий пароль</label>
                                <input type="password" name="new_password" id="newPwdInput" placeholder="••••••••" required oninput="checkStrength(this.value)">
                                <div class="pwd-strength"><div class="pwd-strength-bar" id="pwdBar"></div></div>
                                <div class="field-hint" id="pwdHint">Введіть новий пароль</div>
                            </div>
                            <div class="field">
                                <label>Підтвердження пароля</label>
                                <input type="password" name="conf_password" placeholder="••••••••" required>
                            </div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn-primary">🔑 Змінити пароль</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Форма: Аватар -->
            <div class="form-card">
                <div class="card-header">
                    <div class="card-header-icon red">🖼️</div>
                    <div>
                        <div class="card-title">Фото профілю</div>
                        <div class="card-sub">JPG, PNG, WEBP — до 2 МБ</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_avatar">
                        <div class="avatar-drop" id="dropZone">
                            <input type="file" name="avatar" id="quickAvatarInput" accept="image/*" onchange="previewAvatar(this)">
                            <img class="avatar-preview" id="avatarPreview" src="" alt="preview">
                            <div class="avatar-drop-icon" id="dropIcon">📸</div>
                            <div class="avatar-drop-text">Перетягніть фото або клікніть</div>
                            <div class="avatar-drop-sub">JPG · PNG · WEBP · GIF · до 2 МБ</div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn-primary">⬆️ Завантажити</button>
                        </div>
                    </form>
                </div>
            </div>

        </div><!-- /right-col -->

    </div><!-- /content -->
</div><!-- /page -->

<script>
/* Date */
(function(){
    const opt = { day:'numeric', month:'long', year:'numeric', weekday:'long' };
    document.getElementById('dateLabel').textContent = new Date().toLocaleDateString('uk-UA', opt);
})();

/* Password strength */
function checkStrength(val) {
    const bar  = document.getElementById('pwdBar');
    const hint = document.getElementById('pwdHint');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const colors = ['#ef4444','#f59e0b','#f59e0b','#22c55e','#22c55e'];
    const labels = ['Дуже слабкий','Слабкий','Середній','Сильний','Дуже сильний'];
    bar.style.width      = (score * 20) + '%';
    bar.style.background = colors[score - 1] || '#1e293b';
    hint.textContent     = val.length ? labels[score - 1] || '' : 'Введіть новий пароль';
}

/* Avatar preview */
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('avatarPreview');
        const icon = document.getElementById('dropIcon');
        prev.src = e.target.result;
        prev.style.display = 'block';
        icon.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

/* Drag-over style */
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const dt = e.dataTransfer;
    if (dt.files.length) {
        document.getElementById('quickAvatarInput').files = dt.files;
        previewAvatar(document.getElementById('quickAvatarInput'));
    }
});

/* Auto-hide alerts */
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(el => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 4000);

/* Валідація email та телефону */
function validatePhoneJS(val) {
    return /^\+?3?8?(0\d{9})$/.test(val.replace(/[\s\-()]/g, ''));
}

function validateEmailJS(val) {
    return /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,6}$/.test(val) && val.length <= 100;
}

const profilePhoneInput = document.getElementById('profilePhoneInput');
const profileEmailInput = document.getElementById('profileEmailInput');

if (profilePhoneInput) {
    profilePhoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^\d\+\s\-\(\)]/g, '');
    });
    profilePhoneInput.addEventListener('blur', function() {
        const hint = document.getElementById('profilePhoneHint');
        if (!this.value) { this.classList.remove('valid','invalid'); hint.textContent = ''; return; }
        if (validatePhoneJS(this.value)) {
            this.classList.add('valid'); this.classList.remove('invalid'); hint.textContent = '';
        } else {
            this.classList.add('invalid'); this.classList.remove('valid');
            hint.textContent = 'некоректно введений номер';
        }
    });
}

if (profileEmailInput) {
    profileEmailInput.addEventListener('blur', function() {
        const hint = document.getElementById('profileEmailHint');
        if (!this.value) { this.classList.remove('valid','invalid'); hint.textContent = ''; return; }
        if (validateEmailJS(this.value)) {
            this.classList.add('valid'); this.classList.remove('invalid'); hint.textContent = '';
        } else {
            this.classList.add('invalid'); this.classList.remove('valid');
            hint.textContent = 'некоректно введено пошту';
        }
    });
}
</script>
<script src="theme-switcher.js"></script>
</body>
</html>