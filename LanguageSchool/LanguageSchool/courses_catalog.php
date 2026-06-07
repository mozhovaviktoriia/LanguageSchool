<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = $_SESSION['user_id'];

$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $studentId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: 'Студент';
$initials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1)) ?: 'СТ';

$stmtEnrolled = $pdo->prepare("SELECT course_id FROM enrollments WHERE student_id = :sid AND status = 'active'");
$stmtEnrolled->execute(['sid' => $studentId]);
$enrolledCourseIds = array_map(fn($r) => $r['course_id'], $stmtEnrolled->fetchAll(PDO::FETCH_ASSOC));

$sqlCourses = "
SELECT
    c.id, c.title, c.level, c.price, c.description,
    l.id AS language_id, l.name_ua AS language, l.code AS lang_code,
    u.first_name AS teacher_first, u.last_name AS teacher_last, u.avatar_url AS teacher_avatar,
    COUNT(DISTINCT e.student_id) AS students_count
FROM courses c
LEFT JOIN languages l ON c.language_id = l.id
LEFT JOIN users u ON c.teacher_id = u.id
LEFT JOIN enrollments e ON e.course_id = c.id AND e.status = 'active'
WHERE c.is_active = TRUE
GROUP BY c.id, c.title, c.level, c.price, c.description, l.id, l.name_ua, l.code, u.first_name, u.last_name, u.avatar_url
ORDER BY l.name_ua, c.title
";
$stmtCourses = $pdo->prepare($sqlCourses);
$stmtCourses->execute();
$allCourses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

$stmtLangs = $pdo->query("SELECT id, code, name_ua FROM languages ORDER BY name_ua");
$languages = $stmtLangs->fetchAll(PDO::FETCH_ASSOC);

// emoji map for languages
$langEmoji = [
    'en' => '🇬🇧', 'de' => '🇩🇪', 'fr' => '🇫🇷', 'es' => '🇪🇸',
    'it' => '🇮🇹', 'pl' => '🇵🇱', 'uk' => '🇺🇦', 'zh' => '🇨🇳',
    'ja' => '🇯🇵', 'ko' => '🇰🇷', 'pt' => '🇵🇹', 'ar' => '🇸🇦',
];

if ($_POST['action'] ?? false) {
    $action = $_POST['action'];
    $courseId = $_POST['course_id'];
    if ($action === 'enroll') {
        $chk = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = :sid AND course_id = :cid");
        $chk->execute(['sid' => $studentId, 'cid' => $courseId]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, status, enrolled_at) VALUES (:sid, :cid, 'active', NOW())");
            $stmt->execute(['sid' => $studentId, 'cid' => $courseId]);
            header("Location: courses_catalog.php?msg=enrolled"); exit;
        }
    } elseif ($action === 'unenroll') {
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE student_id = :sid AND course_id = :cid");
        $stmt->execute(['sid' => $studentId, 'cid' => $courseId]);
        header("Location: courses_catalog.php?msg=unenrolled"); exit;
    }
}

$searchTerm = $_GET['q'] ?? '';
$selectedLang = $_GET['lang'] ?? '';
$filteredCourses = $allCourses;
if ($searchTerm) {
    $filteredCourses = array_filter($filteredCourses, fn($c) =>
        stripos($c['title'], $searchTerm) !== false || stripos($c['description'], $searchTerm) !== false
    );
}
if ($selectedLang) {
    $filteredCourses = array_filter($filteredCourses, fn($c) => $c['language_id'] == $selectedLang);
}

$coursesByLang = [];
foreach ($filteredCourses as $course) {
    $coursesByLang[$course['language']][] = $course;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Каталог курсів — LinguaHub</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    display: flex;
    overflow-x: hidden;
    font-size: 14px;
    line-height: 1.5;
    transition: background .25s, color .25s;
}

body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(ellipse 70% 55% at 8% 2%,  var(--glow-1) 0%, transparent 60%),
        radial-gradient(ellipse 55% 55% at 92% 92%, var(--glow-2) 0%, transparent 60%);
}

/* ══ SIDEBAR ══ */
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: 240px;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 30;
    transition: background .25s, border-color .25s;
}
.sidebar-logo { padding: 20px 20px 16px; border-bottom: 1px solid var(--border); }
.logo-text { font-size: 19px; font-weight: 800; letter-spacing: -.5px; color: var(--text); }
.logo-text span { color: var(--teal); }
.sidebar-profile {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px; border-bottom: 1px solid var(--border);
}
.s-avatar {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; color: #fff;
    border: 2px solid rgba(99,102,241,.35); overflow: hidden;
}
.s-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-name { font-size: 13px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 148px; }
.profile-role { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 1.2px; margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 10px; display: flex; flex-direction: column; gap: 1px; overflow-y: auto; }
.nav-label { font-family: var(--mono); font-size: 9px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; padding: 12px 10px 5px; }
.nav-item {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 11px; border-radius: 9px;
    text-decoration: none; color: var(--muted);
    font-size: 13px; font-weight: 600;
    transition: background .15s, color .15s;
    border: 1px solid transparent;
}
.nav-item svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .75; }
.nav-item:hover { color: var(--text); background: var(--hover-nav); }
.nav-item.active { color: var(--accent); background: rgba(99,102,241,.13); border-color: rgba(99,102,241,.28); }
.nav-item.active svg { opacity: 1; }
.sidebar-footer { padding: 10px; border-top: 1px solid var(--border); }
.logout-btn {
    display: flex; align-items: center; gap: 9px;
    width: 100%; padding: 9px 11px; border-radius: 9px;
    background: rgba(239,68,68,.06); border: 1px solid rgba(239,68,68,.18);
    color: var(--red); font-family: var(--font); font-size: 13px; font-weight: 600;
    cursor: pointer; transition: .15s; text-decoration: none;
}
.logout-btn svg { width: 15px; height: 15px; }
.logout-btn:hover { background: rgba(239,68,68,.14); border-color: rgba(239,68,68,.35); }

/* ══ MAIN ══ */
.main { margin-left: 240px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; position: relative; z-index: 1; }

/* ══ TOPBAR ══ */
.topbar {
    position: sticky; top: 0; z-index: 20;
    height: 56px;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 24px; gap: 16px;
    background: var(--topbar-bg); backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
    transition: background .25s, border-color .25s;
}
.page-title { font-size: 14px; font-weight: 800; letter-spacing: -.2px; display: flex; align-items: center; gap: 8px; color: var(--text); }
.topbar-controls { display: flex; align-items: center; gap: 8px; }
.search-box {
    display: flex; align-items: center; gap: 8px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 9px; padding: 0 12px; height: 34px;
    transition: border-color .15s, background .25s;
}
.search-box:focus-within { border-color: rgba(99,102,241,.5); }
.search-box svg { flex-shrink: 0; color: var(--muted); }
.search-box input { background: none; border: none; outline: none; color: var(--text); font-family: var(--font); font-size: 13px; width: 180px; }
.search-box input::placeholder { color: var(--muted); }
.filter-select {
    height: 34px; padding: 0 12px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 9px; color: var(--text);
    font-family: var(--font); font-size: 13px; cursor: pointer;
    transition: border-color .15s, background .25s; outline: none;
}
.filter-select:focus, .filter-select:hover { border-color: rgba(99,102,241,.4); }

/* ══ CONTENT ══ */
.content { padding: 20px 24px; flex: 1; }

/* ══ LANG SECTION ══ */
.lang-section { margin-bottom: 28px; }
.lang-heading {
    display: flex; align-items: center; gap: 8px;
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 2px; color: var(--muted);
    font-family: var(--mono); margin-bottom: 8px;
}
.lang-heading::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.lang-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--teal); flex-shrink: 0; box-shadow: 0 0 8px var(--teal); }
.courses-list { display: flex; flex-direction: column; gap: 6px; }

/* ══ COMPACT COURSE ROW ══ */
.course-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 16px;
    display: flex; align-items: center; gap: 14px;
    transition: border-color .2s, transform .15s, box-shadow .2s, background .25s;
    position: relative; overflow: hidden;
}
.course-card::before {
    content: '';
    position: absolute; left: 0; top: 10px; bottom: 10px;
    width: 3px; border-radius: 0 3px 3px 0;
    background: linear-gradient(180deg, var(--accent), var(--teal));
    opacity: 0; transition: opacity .2s;
}
.course-card:hover { border-color: rgba(99,102,241,.4); transform: translateX(2px); box-shadow: 0 4px 16px rgba(99,102,241,.09); }
.course-card:hover::before { opacity: 1; }

/* Left icon block */
.card-icon {
    width: 46px; height: 46px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    background: linear-gradient(135deg, rgba(99,102,241,.12), rgba(20,184,166,.09));
    border: 1px solid rgba(99,102,241,.16);
}

/* Course info */
.card-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.course-title { font-size: 13px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.course-desc { font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-badges { display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap; }
.badge {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    padding: 2px 7px; border-radius: 5px; border: 1px solid transparent; line-height: 1.5;
}
.badge-lang   { color: var(--accent); background: rgba(99,102,241,.09); border-color: rgba(99,102,241,.2); }
.badge-level  { color: var(--amber); background: rgba(245,158,11,.09); border-color: rgba(245,158,11,.2); }
.badge-price  { color: var(--green); background: rgba(34,197,94,.09); border-color: rgba(34,197,94,.2); }
.badge-enrolled { color: var(--green); background: rgba(34,197,94,.09); border-color: rgba(34,197,94,.2); font-weight: 700; }

/* Teacher block */
.card-teacher {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; width: 150px;
}
.t-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: #fff; overflow: hidden;
}
.t-avatar img { width: 100%; height: 100%; object-fit: cover; }
.t-info { min-width: 0; }
.t-name { font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 88px; }
.t-sub { font-family: var(--mono); font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .6px; }

/* Students count */
.students-count {
    flex-shrink: 0;
    font-family: var(--mono); font-size: 10px; color: var(--muted);
    background: var(--input-bg); border: 1px solid var(--border);
    padding: 3px 9px; border-radius: 6px; white-space: nowrap;
}

/* Action button */
.card-action { flex-shrink: 0; }
.btn {
    height: 32px; padding: 0 14px; border-radius: 8px;
    font-family: var(--font); font-size: 11px; font-weight: 700;
    cursor: pointer; transition: .15s; border: none; white-space: nowrap;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.btn-enroll   { background: rgba(34,197,94,.13); color: var(--green); border: 1px solid rgba(34,197,94,.28); }
.btn-enroll:hover { background: rgba(34,197,94,.22); border-color: rgba(34,197,94,.5); }
.btn-unenroll { background: rgba(239,68,68,.09); color: var(--red); border: 1px solid rgba(239,68,68,.2); }
.btn-unenroll:hover { background: rgba(239,68,68,.17); border-color: rgba(239,68,68,.4); }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 56px 40px; background: var(--card); border: 1px dashed var(--border); border-radius: 12px; }
.empty-icon  { font-size: 36px; margin-bottom: 10px; opacity: .5; }
.empty-title { font-size: 14px; font-weight: 700; color: var(--muted); margin-bottom: 4px; }
.empty-sub   { font-family: var(--mono); font-size: 11px; color: var(--muted); }

/* ── Toast ── */
.toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 999;
    padding: 11px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    display: none; animation: slideIn .22s ease;
}
.toast.show { display: flex; align-items: center; gap: 8px; }
.toast.success { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: var(--green); }
@keyframes slideIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

/* Light overrides */
body.light-theme .sidebar { box-shadow: 2px 0 16px rgba(0,0,0,.06); }
body.light-theme .card-icon { background: linear-gradient(135deg,rgba(79,70,229,.09),rgba(8,145,178,.07)); border-color: rgba(79,70,229,.18); }
body.light-theme .badge-lang { color: #4338ca; background: rgba(79,70,229,.09); border-color: rgba(79,70,229,.18); }
body.light-theme .badge-level { color: #b45309; background: rgba(217,119,6,.09); border-color: rgba(217,119,6,.2); }
body.light-theme .badge-price { color: #15803d; background: rgba(22,163,74,.09); border-color: rgba(22,163,74,.2); }
body.light-theme .badge-enrolled { color: #15803d; background: rgba(22,163,74,.09); border-color: rgba(22,163,74,.2); }
body.light-theme .btn-enroll  { background: rgba(22,163,74,.11); color: #15803d; border-color: rgba(22,163,74,.26); }
body.light-theme .btn-enroll:hover { background: rgba(22,163,74,.20); }
body.light-theme .btn-unenroll { background: rgba(220,38,38,.07); color: #dc2626; border-color: rgba(220,38,38,.18); }
body.light-theme .btn-unenroll:hover { background: rgba(220,38,38,.15); }
body.light-theme .filter-select option { background: #ffffff; color: #1e293b; }
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-text">Lingua<span>Hub</span></div>
    </div>
    <div class="sidebar-profile">
        <div class="s-avatar">
            <?php if (!empty($student['avatar_url']) && file_exists($student['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($student['avatar_url']) ?>" alt="">
            <?php else: ?><?= htmlspecialchars($initials) ?><?php endif; ?>
        </div>
        <div>
            <div class="profile-name"><?= htmlspecialchars($studentName) ?></div>
            <div class="profile-role">Студент</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Меню</div>
        <a class="nav-item" href="dashboard_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Мій кабінет
        </a>
        <a class="nav-item" href="schedule_student.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Розклад
        </a>
        <a class="nav-item active" href="courses_catalog.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            Каталог курсів
        </a>
        <a class="nav-item" href="chat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Чат
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

<!-- MAIN -->
<main class="main">
    <div class="topbar">
        <div class="page-title">
            <span>📚</span> Каталог курсів
        </div>
        <form class="topbar-controls" method="GET">
            <div class="search-box">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" name="q" placeholder="Пошук курсів…" value="<?= htmlspecialchars($searchTerm) ?>">
            </div>
            <select name="lang" class="filter-select" onchange="this.form.submit()">
                <option value="">Усі мови</option>
                <?php foreach ($languages as $lang): ?>
                <option value="<?= $lang['id'] ?>" <?= $selectedLang == $lang['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lang['name_ua']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="content">
        <?php if ($_GET['msg'] ?? false): ?>
        <div class="toast show success" id="msgToast">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $_GET['msg'] === 'enrolled' ? 'Ви успішно записалися на курс' : 'Ви вийшли з курсу' ?>
        </div>
        <script>setTimeout(() => document.getElementById('msgToast')?.classList.remove('show'), 3000);</script>
        <?php endif; ?>

        <?php if (empty($filteredCourses)): ?>
        <div class="empty-state">
            <div class="empty-icon">📚</div>
            <div class="empty-title">Курсів не знайдено</div>
            <div class="empty-sub">Спробуйте змінити параметри пошуку</div>
        </div>
        <?php else: ?>
            <?php foreach ($coursesByLang as $lang => $courses):
                // find lang_code for emoji
                $langCode = '';
                foreach ($courses as $c) { $langCode = $c['lang_code'] ?? ''; break; }
                $emoji = $langEmoji[$langCode] ?? '🌐';
            ?>
            <div class="lang-section">
                <div class="lang-heading">
                    <span class="lang-dot"></span>
                    <?= htmlspecialchars($lang) ?>
                    <span style="opacity:.6;"><?= count($courses) ?> курс<?= count($courses) !== 1 ? 'ів' : '' ?></span>
                </div>
                <div class="courses-list">
                    <?php foreach ($courses as $course):
                        $isEnrolled = in_array($course['id'], $enrolledCourseIds);
                        $teacherInitials = strtoupper(
                            substr($course['teacher_first'] ?? '', 0, 1) .
                            substr($course['teacher_last'] ?? '', 0, 1)
                        );
                        $teacherName = trim(($course['teacher_first'] ?? '') . ' ' . ($course['teacher_last'] ?? ''));
                    ?>
                    <div class="course-card">
                        <div class="card-icon"><?= $emoji ?></div>

                        <div class="card-info">
                            <div class="course-title"><?= htmlspecialchars($course['title']) ?></div>
                            <?php if (!empty($course['description'])): ?>
                            <div class="course-desc"><?= htmlspecialchars($course['description']) ?></div>
                            <?php endif; ?>
                            <div class="card-badges">
                                <span class="badge badge-lang"><?= htmlspecialchars($course['language']) ?></span>
                                <span class="badge badge-level"><?= htmlspecialchars($course['level']) ?></span>
                                <span class="badge badge-price"><?= htmlspecialchars($course['price']) ?> грн</span>
                                <?php if ($isEnrolled): ?>
                                    <span class="badge badge-enrolled">✓ Записаний</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-teacher">
                            <div class="t-avatar">
                                <?php if (!empty($course['teacher_avatar']) && file_exists($course['teacher_avatar'])): ?>
                                    <img src="<?= htmlspecialchars($course['teacher_avatar']) ?>" alt="">
                                <?php else: ?><?= htmlspecialchars($teacherInitials) ?><?php endif; ?>
                            </div>
                            <div class="t-info">
                                <div class="t-name"><?= htmlspecialchars($teacherName) ?></div>
                                <div class="t-sub">Викладач</div>
                            </div>
                        </div>

                        <div class="students-count">👥 <?= $course['students_count'] ?></div>

                        <div class="card-action">
                            <?php if ($isEnrolled): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="unenroll">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" class="btn btn-unenroll">Вийти</button>
                            </form>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" class="btn btn-enroll">Записатися</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script src="theme-switcher.js"></script>
</body>
</html>