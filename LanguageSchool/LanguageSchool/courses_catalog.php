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

:root {
    --bg:       #07080f;
    --surface:  #0d1117;
    --card:     #0f1520;
    --card-hover: #131d2e;
    --border:   rgba(255,255,255,.07);
    --border-hover: rgba(99,102,241,.45);
    --accent:   #6366f1;
    --accent2:  #818cf8;
    --teal:     #22d3ee;
    --green:    #34d399;
    --amber:    #fbbf24;
    --red:      #f87171;
    --text:     #f1f5f9;
    --sub:      #94a3b8;
    --muted:    #475569;
    --font:     'Syne', sans-serif;
    --mono:     'JetBrains Mono', monospace;
    --sidebar-w: 240px;
    --topbar-h:  60px;
    --radius:   12px;
}

html, body { height: 100%; }
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    display: flex;
    overflow-x: hidden;
    font-size: 14px;
    line-height: 1.5;
}

/* ─── Ambient background ─── */
body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(ellipse 70% 55% at 8% 2%,  rgba(99,102,241,.10) 0%, transparent 60%),
        radial-gradient(ellipse 55% 55% at 92% 92%, rgba(34,211,238,.08) 0%, transparent 60%);
}

/* ══════════════════════════════
   SIDEBAR
══════════════════════════════ */
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--sidebar-w);
    background: rgba(10,13,20,.98);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 30;
}

.sidebar-logo {
    padding: 20px 20px 16px;
    border-bottom: 1px solid var(--border);
}
.logo-text { font-size: 19px; font-weight: 800; letter-spacing: -.5px; }
.logo-text span { color: var(--teal); }

.sidebar-profile {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}
.s-avatar {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; color: #fff;
    border: 2px solid rgba(99,102,241,.35); overflow: hidden;
}
.s-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-name {
    font-size: 13px; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: 148px;
}
.profile-role {
    font-family: var(--mono); font-size: 9px;
    color: var(--muted); text-transform: uppercase; letter-spacing: 1.2px;
    margin-top: 2px;
}

.sidebar-nav {
    flex: 1; padding: 10px 10px;
    display: flex; flex-direction: column; gap: 1px;
    overflow-y: auto;
}
.nav-label {
    font-family: var(--mono); font-size: 9px;
    color: var(--muted); letter-spacing: 2px; text-transform: uppercase;
    padding: 12px 10px 5px;
}
.nav-item {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 11px; border-radius: 9px;
    text-decoration: none; color: var(--sub);
    font-size: 13px; font-weight: 600;
    transition: background .15s, color .15s;
    border: 1px solid transparent;
}
.nav-item svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .75; }
.nav-item:hover { color: var(--text); background: rgba(255,255,255,.05); }
.nav-item.active {
    color: #fff; background: rgba(99,102,241,.15);
    border-color: rgba(99,102,241,.28);
}
.nav-item.active svg { opacity: 1; }

.sidebar-footer { padding: 10px 10px; border-top: 1px solid var(--border); }
.logout-btn {
    display: flex; align-items: center; gap: 9px;
    width: 100%; padding: 9px 11px; border-radius: 9px;
    background: rgba(239,68,68,.06); border: 1px solid rgba(239,68,68,.18);
    color: #fca5a5; font-family: var(--font); font-size: 13px; font-weight: 600;
    cursor: pointer; transition: .15s; text-decoration: none;
}
.logout-btn svg { width: 15px; height: 15px; }
.logout-btn:hover { background: rgba(239,68,68,.14); border-color: rgba(239,68,68,.35); }

/* ══════════════════════════════
   MAIN LAYOUT
══════════════════════════════ */
.main {
    margin-left: var(--sidebar-w);
    flex: 1; display: flex; flex-direction: column;
    min-height: 100vh; position: relative; z-index: 1;
}

/* ══════════════════════════════
   TOPBAR
══════════════════════════════ */
.topbar {
    position: sticky; top: 0; z-index: 20;
    height: var(--topbar-h);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px; gap: 16px;
    background: rgba(7,8,15,.90); backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
}
.page-title {
    font-size: 15px; font-weight: 800; letter-spacing: -.2px;
    display: flex; align-items: center; gap: 8px;
}
.page-title-icon { font-size: 18px; line-height: 1; }

.topbar-controls { display: flex; align-items: center; gap: 10px; }

.search-box {
    display: flex; align-items: center; gap: 8px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 9px; padding: 0 12px; height: 36px;
    transition: border-color .15s;
}
.search-box:focus-within { border-color: rgba(99,102,241,.5); }
.search-box svg { flex-shrink: 0; color: var(--muted); }
.search-box input {
    background: none; border: none; outline: none;
    color: var(--text); font-family: var(--font); font-size: 13px;
    width: 190px;
}
.search-box input::placeholder { color: var(--muted); }

.filter-select {
    height: 36px; padding: 0 12px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 9px; color: var(--text);
    font-family: var(--font); font-size: 13px; cursor: pointer;
    transition: border-color .15s; outline: none;
}
.filter-select:focus,
.filter-select:hover { border-color: rgba(99,102,241,.4); }
.filter-select option { background: #0d1117; }

/* ══════════════════════════════
   CONTENT
══════════════════════════════ */
.content { padding: 24px 28px; flex: 1; }

/* ── Language section ── */
.lang-section { margin-bottom: 36px; }
.lang-heading {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1.5px; color: var(--sub);
    font-family: var(--mono);
    margin-bottom: 14px;
}
.lang-heading::after {
    content: '';
    flex: 1; height: 1px;
    background: var(--border);
}
.lang-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--teal); flex-shrink: 0;
    box-shadow: 0 0 8px var(--teal);
}

/* ── Courses grid ── */
.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
}

/* ── Course card ── */
.course-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px;
    display: flex; flex-direction: column; gap: 14px;
    transition: border-color .2s, transform .2s, box-shadow .2s;
    position: relative; overflow: hidden;
}
.course-card::after {
    content: '';
    position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(135deg, rgba(99,102,241,.04) 0%, transparent 60%);
    opacity: 0; transition: opacity .2s;
}
.course-card:hover {
    border-color: var(--border-hover);
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(99,102,241,.15);
    background: var(--card-hover);
}
.course-card:hover::after { opacity: 1; }

/* Card top row */
.card-top {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
}
.lang-badge {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    color: var(--accent2); background: rgba(99,102,241,.12);
    padding: 3px 9px; border-radius: 6px; letter-spacing: .3px;
    border: 1px solid rgba(99,102,241,.2);
    white-space: nowrap;
}
.enrolled-chip {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    color: var(--green); background: rgba(52,211,153,.1);
    padding: 3px 9px; border-radius: 6px; letter-spacing: .3px;
    border: 1px solid rgba(52,211,153,.2);
    white-space: nowrap;
}

/* Card body */
.card-body { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.course-title {
    font-size: 15px; font-weight: 800; line-height: 1.3;
    color: var(--text);
}
.course-desc {
    font-size: 12px; color: var(--sub); line-height: 1.55;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}

/* Tags */
.card-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.tag {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    padding: 3px 9px; border-radius: 6px;
    border: 1px solid transparent;
}
.tag-level {
    color: var(--amber); background: rgba(251,191,36,.08);
    border-color: rgba(251,191,36,.18);
}
.tag-price {
    color: var(--green); background: rgba(52,211,153,.08);
    border-color: rgba(52,211,153,.18);
}

/* Teacher row */
.teacher-row {
    display: flex; align-items: center; gap: 10px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
.t-avatar {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: #fff; overflow: hidden;
}
.t-avatar img { width: 100%; height: 100%; object-fit: cover; }
.t-info { flex: 1; min-width: 0; }
.t-name {
    font-size: 12px; font-weight: 700; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.t-role {
    font-family: var(--mono); font-size: 9px; color: var(--muted);
    margin-top: 1px; text-transform: uppercase; letter-spacing: .8px;
}
.students-pill {
    font-family: var(--mono); font-size: 10px; color: var(--muted);
    background: rgba(255,255,255,.04); border: 1px solid var(--border);
    padding: 3px 9px; border-radius: 6px; white-space: nowrap; flex-shrink: 0;
}

/* Action button */
.card-action { display: flex; }
.btn {
    flex: 1; height: 36px; border-radius: 9px;
    font-family: var(--font); font-size: 12px; font-weight: 700;
    cursor: pointer; transition: .15s; border: none;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-enroll {
    background: rgba(52,211,153,.15); color: var(--green);
    border: 1px solid rgba(52,211,153,.3);
}
.btn-enroll:hover { background: rgba(52,211,153,.25); border-color: rgba(52,211,153,.5); }
.btn-unenroll {
    background: rgba(248,113,113,.08); color: var(--red);
    border: 1px solid rgba(248,113,113,.2);
}
.btn-unenroll:hover { background: rgba(248,113,113,.16); border-color: rgba(248,113,113,.4); }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 64px 40px;
    background: var(--card); border: 1px dashed var(--border);
    border-radius: var(--radius);
}
.empty-icon { font-size: 40px; margin-bottom: 12px; opacity: .5; }
.empty-title { font-size: 15px; font-weight: 700; color: var(--sub); margin-bottom: 5px; }
.empty-sub { font-family: var(--mono); font-size: 11px; color: var(--muted); }

/* ── Toast ── */
.toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 999;
    padding: 12px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    display: none; animation: slideIn .22s ease;
}
.toast.show { display: flex; align-items: center; gap: 8px; }
.toast.success {
    background: rgba(52,211,153,.12); border: 1px solid rgba(52,211,153,.3);
    color: var(--green);
}
@keyframes slideIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
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

<!-- ══ MAIN ══ -->
<main class="main">
    <div class="topbar">
        <div class="page-title">
            <span class="page-title-icon">📚</span>
            Каталог курсів
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
            <?php foreach ($coursesByLang as $lang => $courses): ?>
            <div class="lang-section">
                <div class="lang-heading">
                    <span class="lang-dot"></span>
                    <?= htmlspecialchars($lang) ?>
                    <span style="color:var(--muted); font-size:10px;"><?= count($courses) ?> курс<?= count($courses) !== 1 ? 'ів' : '' ?></span>
                </div>
                <div class="courses-grid">
                    <?php foreach ($courses as $course):
                        $isEnrolled = in_array($course['id'], $enrolledCourseIds);
                        $teacherInitials = strtoupper(
                            substr($course['teacher_first'] ?? '', 0, 1) .
                            substr($course['teacher_last'] ?? '', 0, 1)
                        );
                        $teacherName = trim(($course['teacher_first'] ?? '') . ' ' . ($course['teacher_last'] ?? ''));
                    ?>
                    <div class="course-card">
                        <!-- Top row -->
                        <div class="card-top">
                            <span class="lang-badge"><?= htmlspecialchars($course['language']) ?></span>
                            <?php if ($isEnrolled): ?>
                                <span class="enrolled-chip">✓ Записаний</span>
                            <?php endif; ?>
                        </div>

                        <!-- Body -->
                        <div class="card-body">
                            <div class="course-title"><?= htmlspecialchars($course['title']) ?></div>
                            <?php if (!empty($course['description'])): ?>
                            <div class="course-desc"><?= htmlspecialchars($course['description']) ?></div>
                            <?php endif; ?>
                            <div class="card-tags">
                                <span class="tag tag-level"><?= htmlspecialchars($course['level']) ?></span>
                                <span class="tag tag-price"><?= htmlspecialchars($course['price']) ?> грн</span>
                            </div>
                        </div>

                        <!-- Teacher -->
                        <div class="teacher-row">
                            <div class="t-avatar">
                                <?php if (!empty($course['teacher_avatar']) && file_exists($course['teacher_avatar'])): ?>
                                    <img src="<?= htmlspecialchars($course['teacher_avatar']) ?>" alt="">
                                <?php else: ?><?= htmlspecialchars($teacherInitials) ?><?php endif; ?>
                            </div>
                            <div class="t-info">
                                <div class="t-name"><?= htmlspecialchars($teacherName) ?></div>
                                <div class="t-role">Викладач</div>
                            </div>
                            <div class="students-pill">👥 <?= $course['students_count'] ?></div>
                        </div>

                        <!-- Action -->
                        <div class="card-action">
                            <?php if ($isEnrolled): ?>
                            <form method="POST" style="flex:1;">
                                <input type="hidden" name="action" value="unenroll">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" class="btn btn-unenroll">Вийти з курсу</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="flex:1;">
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

</body>
</html>