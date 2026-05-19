<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Delete user
if (isset($_GET['delete_user'])) {
    $uid = $_GET['delete_user'];
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => $uid]);
    header("Location: admin.php"); exit;
}

// Toggle user status: banned <-> active
if (isset($_GET['toggle_user'])) {
    $uid = $_GET['toggle_user'];
    $cur = $pdo->prepare("SELECT status FROM users WHERE id = :id");
    $cur->execute(['id' => $uid]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    $newStatus = ($row['status'] === 'banned') ? 'active' : 'banned';
    $pdo->prepare("UPDATE users SET status = :s WHERE id = :id")->execute(['s' => $newStatus, 'id' => $uid]);
    header("Location: admin.php"); exit;
}

// Delete course
if (isset($_GET['delete_course'])) {
    $cid = $_GET['delete_course'];
    // First delete all enrollments for this course
    $pdo->prepare("DELETE FROM enrollments WHERE course_id = :id")->execute(['id' => $cid]);
    // Then delete the course
    $pdo->prepare("DELETE FROM courses WHERE id = :id")->execute(['id' => $cid]);
    header("Location: admin.php"); exit;
}

// Filter courses by language
$languageFilter = $_GET['language'] ?? '';
$languages = $pdo->query("SELECT id, name_ua FROM languages ORDER BY name_ua")->fetchAll(PDO::FETCH_ASSOC);

// Fetch and display all courses with stats
$coursesSQL = "
    SELECT c.id, c.title, c.level, c.price, c.is_active,
           l.name_ua,
           u.first_name AS t_first, u.last_name AS t_last,
           COUNT(DISTINCT e.student_id) AS students_count
    FROM courses c
    JOIN languages l ON c.language_id = l.id
    LEFT JOIN users u ON c.teacher_id = u.id
    LEFT JOIN enrollments e ON e.course_id = c.id
";
if ($languageFilter) {
    $coursesSQL .= " WHERE l.id = " . (int)$languageFilter;
}
$coursesSQL .= " GROUP BY c.id, c.title, c.level, c.price, c.is_active, l.name_ua, u.first_name, u.last_name ORDER BY c.title";
$courses = $pdo->query($coursesSQL)->fetchAll(PDO::FETCH_ASSOC);

// Fetch students with their enrolled courses
$students = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.status,
           STRING_AGG(c.title, ' · ' ORDER BY c.title) AS courses
    FROM users u
    LEFT JOIN enrollments e ON e.student_id = u.id AND e.status = 'active'
    LEFT JOIN courses c ON e.course_id = c.id
    WHERE u.role = 'student'
    GROUP BY u.id, u.first_name, u.last_name, u.email, u.status
    ORDER BY u.last_name, u.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch teachers with their courses and languages
$teachers = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.status,
           STRING_AGG(DISTINCT c.title, ' · ' ORDER BY c.title) AS courses,
           STRING_AGG(DISTINCT l.name_ua, ', ') AS langs
    FROM users u
    LEFT JOIN courses c ON c.teacher_id = u.id
    LEFT JOIN languages l ON c.language_id = l.id
    WHERE u.role = 'teacher'
    GROUP BY u.id, u.first_name, u.last_name, u.email, u.status
    ORDER BY u.last_name, u.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Color palette for CEFR levels
$levelColors = ['A1'=>'#6366f1','A2'=>'#8b5cf6','B1'=>'#3b82f6','B2'=>'#0ea5e9','C1'=>'#14b8a6','C2'=>'#10b981'];
$statusLabels = ['active'=>'Активний','inactive'=>'Неактивний','banned'=>'Заблокований'];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Адмін панель</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:#07080f; --surface:#0d1117; --card:#111827; --border:#1e293b;
    --accent:#6366f1; --accent2:#22d3ee; --text:#e2e8f0; --muted:#64748b;
    --danger:#ef4444; --success:#22c55e; --warn:#f59e0b;
    --radius:14px; --font:'Syne',sans-serif; --mono:'JetBrains Mono',monospace;
}
body { font-family:var(--font); background:var(--bg); color:var(--text); min-height:100vh; }
body::before { content:''; position:fixed; inset:0; background-image: radial-gradient(ellipse 80% 60% at 20% 10%,rgba(99,102,241,.12) 0%,transparent 60%), radial-gradient(ellipse 50% 40% at 80% 80%,rgba(34,211,238,.08) 0%,transparent 55%); pointer-events:none; z-index:0; }

header { position:relative; z-index:10; display:flex; align-items:center; justify-content:space-between; padding:20px 36px; border-bottom:1px solid var(--border); background:rgba(13,17,23,.85); backdrop-filter:blur(20px); }
.logo { font-size:22px; font-weight:800; letter-spacing:-.5px; }
.logo span { color:var(--accent); }
.header-sub { font-family:var(--mono); font-size:11px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; }
.header-actions { display:flex; gap:10px; }
.h-btn { font-family:var(--mono); font-size:11px; padding:8px 16px; border-radius:9px; text-decoration:none; border:1px solid var(--border); color:var(--muted); background:var(--card); transition:.2s; }
.h-btn:hover { color:var(--text); border-color:var(--accent); background:rgba(99,102,241,.1); }
.h-btn.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
.h-btn.primary:hover { background:#818cf8; }

/* ── НОВЕ: кнопка Чат у хедері ── */
.h-btn.chat { background:rgba(34,211,238,.1); color:var(--accent2); border-color:rgba(34,211,238,.3); }
.h-btn.chat:hover { background:rgba(34,211,238,.2); border-color:var(--accent2); color:#fff; }

.h-btn.logout { background:rgba(239,68,68,.1); color:#fca5a5; border-color:rgba(239,68,68,.2); }
.h-btn.logout:hover { background:rgba(239,68,68,.25); border-color:#fca5a5; color:#fff; }

.wrap { position:relative; z-index:1; max-width:1300px; margin:0 auto; padding:40px 32px; display:flex; flex-direction:column; gap:56px; }

.sec-head { display:flex; align-items:center; gap:16px; margin-bottom:24px; }
.sec-title { font-size:20px; font-weight:800; letter-spacing:-.3px; }
.sec-count { font-family:var(--mono); font-size:12px; color:var(--muted); background:var(--border); padding:3px 10px; border-radius:99px; }
.plus-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:10px; background:var(--accent); color:#fff; font-size:22px; line-height:1; text-decoration:none; transition:.2s; margin-left:auto; }
.plus-btn:hover { background:#818cf8; transform:scale(1.1); }

.filter-bar { display:flex; gap:10px; margin-bottom:22px; flex-wrap:wrap; }
.filter-bar a { font-family:var(--mono); font-size:12px; text-decoration:none; padding:6px 16px; border-radius:8px; border:1px solid var(--border); color:var(--muted); background:var(--card); transition:.2s; }
.filter-bar a.active, .filter-bar a:hover { color:var(--text); border-color:var(--accent); background:rgba(99,102,241,.12); }

/* Courses grid */
.courses-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.course-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:20px; display:flex; flex-direction:column; gap:8px; transition:border-color .2s,transform .2s; position:relative; overflow:hidden; }
.course-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--accent),var(--accent2)); opacity:0; transition:opacity .2s; }
.course-card:hover { border-color:var(--accent); transform:translateY(-3px); }
.course-card:hover::before { opacity:1; }
.course-title { font-size:15px; font-weight:700; }
.course-teacher-label { font-family:var(--mono); font-size:10px; color:var(--muted); }
.course-teacher-label span { color:#a5b4fc; }
.course-meta { font-family:var(--mono); font-size:11px; color:var(--muted); }
.course-row { display:flex; align-items:center; justify-content:space-between; margin-top:4px; }
.level-badge { font-family:var(--mono); font-size:11px; font-weight:600; padding:3px 9px; border-radius:6px; background:rgba(99,102,241,.15); color:#a5b4fc; }
.price { font-family:var(--mono); font-size:13px; font-weight:600; color:var(--accent2); }
.students-count { font-family:var(--mono); font-size:10px; color:var(--success); }
.card-actions { display:flex; gap:8px; margin-top:10px; }
.act-btn { flex:1; text-align:center; padding:7px 0; border-radius:8px; font-size:12px; font-family:var(--mono); font-weight:600; text-decoration:none; transition:.2s; cursor:pointer; border:none; }
.act-edit { background:rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.25) !important; }
.act-del  { background:rgba(239,68,68,.1); color:#fca5a5; border:1px solid rgba(239,68,68,.2) !important; }
.act-edit:hover { background:rgba(59,130,246,.3); }
.act-del:hover  { background:rgba(239,68,68,.25); }

/* Tables */
.tbl-wrap { overflow-x:auto; border-radius:var(--radius); border:1px solid var(--border); }
table { width:100%; border-collapse:collapse; font-size:14px; }
thead tr { background:var(--surface); }
thead th { font-family:var(--mono); font-size:11px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--muted); padding:14px 18px; text-align:left; border-bottom:1px solid var(--border); }
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:rgba(99,102,241,.05); }
tbody td { padding:13px 18px; vertical-align:middle; }

.name-cell { font-weight:600; font-size:14px; }
.email-cell { font-family:var(--mono); font-size:12px; color:var(--muted); }

.courses-cell { font-family:var(--mono); font-size:10px; color:var(--accent2); max-width:260px; }
.courses-cell .no-courses { color:var(--muted); }
.course-pill { display:inline-block; background:rgba(34,211,238,.08); border:1px solid rgba(34,211,238,.15); border-radius:5px; padding:2px 7px; margin:2px 2px 2px 0; white-space:nowrap; }

.status-dot { display:inline-flex; align-items:center; gap:7px; font-size:12px; font-family:var(--mono); }
.status-dot::before { content:''; width:8px; height:8px; border-radius:50%; background:currentColor; flex-shrink:0; }
.s-active   { color:var(--success); }
.s-inactive { color:var(--warn); }
.s-banned   { color:var(--danger); }

.tbl-actions { display:flex; gap:6px; flex-wrap:wrap; }
.tbl-edit, .tbl-del, .tbl-block, .tbl-unblock, .tbl-chat {
    padding:5px 10px; border-radius:6px; font-size:11px; font-family:var(--mono); font-weight:600; text-decoration:none; transition:.15s; white-space:nowrap;
}
.tbl-edit    { background:rgba(59,130,246,.12); color:#93c5fd; }
.tbl-del     { background:rgba(239,68,68,.1); color:#fca5a5; }
.tbl-block   { background:rgba(245,158,11,.1); color:#fcd34d; }
.tbl-unblock { background:rgba(34,197,94,.1); color:#86efac; }

/* ── НОВЕ: кнопка "Написати" у таблиці ── */
.tbl-chat    { background:rgba(34,211,238,.08); color:var(--accent2); border:1px solid rgba(34,211,238,.2); }
.tbl-chat:hover { background:rgba(34,211,238,.2); border-color:var(--accent2); color:#fff; }

.tbl-edit:hover    { background:rgba(59,130,246,.28); }
.tbl-del:hover     { background:rgba(239,68,68,.25); }
.tbl-block:hover   { background:rgba(245,158,11,.25); }
.tbl-unblock:hover { background:rgba(34,197,94,.25); }

.empty { text-align:center; font-family:var(--mono); font-size:13px; color:var(--muted); padding:48px 20px; }
.divider { height:1px; background:linear-gradient(90deg,transparent,var(--border),transparent); }

/* Модальне підтвердження */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(6px); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--card); border:1px solid var(--border); border-radius:18px; padding:32px; max-width:360px; width:90%; text-align:center; }
.modal-icon { font-size:40px; margin-bottom:12px; }
.modal-title { font-size:17px; font-weight:800; margin-bottom:8px; }
.modal-sub { font-family:var(--mono); font-size:11px; color:var(--muted); margin-bottom:24px; line-height:1.6; }
.modal-actions { display:flex; gap:10px; justify-content:center; }
.modal-cancel { padding:10px 24px; border-radius:10px; background:var(--border); color:var(--muted); border:none; font-family:var(--font); font-size:13px; font-weight:600; cursor:pointer; }
.modal-cancel:hover { color:var(--text); }
.modal-confirm { padding:10px 24px; border-radius:10px; background:rgba(239,68,68,.15); color:#fca5a5; border:1px solid rgba(239,68,68,.3); font-family:var(--font); font-size:13px; font-weight:700; cursor:pointer; }
.modal-confirm:hover { background:rgba(239,68,68,.3); color:#fff; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
</style>
</head>
<body>

<!-- Модальне вікно підтвердження видалення -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon">🗑️</div>
        <div class="modal-title" id="modalTitle">Підтвердіть дію</div>
        <div class="modal-sub" id="modalSub">Цю дію не можна скасувати.</div>
        <div class="modal-actions">
            <button class="modal-cancel" onclick="closeModal()">Скасувати</button>
            <a class="modal-confirm" id="modalLink" href="#">Підтвердити</a>
        </div>
    </div>
</div>

<header>
    <div>
        <div class="logo">Адмін<span>.</span>панель</div>
        <div class="header-sub">Онлайн школа мов</div>
    </div>
    <div class="header-actions">
        <button class="theme-toggle h-btn" title="Змінити тему">
            <span class="theme-toggle-icon">☀️</span>
        </button>
        <!-- НОВЕ: кнопка переходу до чату -->
        <a class="h-btn chat" href="chat.php">💬 Чат</a>
        <a class="h-btn" href="admin_users.php">+ Користувач</a>
        <a class="h-btn primary" href="add_course.php">+ Курс</a>
        <a class="h-btn" href="admin_reports.php">📊 Звіти</a>
        <a class="h-btn logout" href="?logout=1">Вихід</a>
    </div>
</header>

<div class="wrap">

<!-- ══ КУРСИ ══ -->
<section>
    <div class="sec-head">
        <div class="sec-title">Курси</div>
        <span class="sec-count"><?= count($courses) ?></span>
        <a class="plus-btn" href="add_course.php" title="Додати курс">+</a>
    </div>

    <div class="filter-bar">
        <a href="?language=" class="<?= !$languageFilter ? 'active' : '' ?>">Всі мови</a>
        <?php foreach ($languages as $l): ?>
        <a href="?language=<?= $l['id'] ?>" class="<?= $languageFilter == $l['id'] ? 'active' : '' ?>">
            <?= htmlspecialchars($l['name_ua']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($courses)): ?>
        <div class="empty">Курсів не знайдено</div>
    <?php else: ?>
    <div class="courses-grid">
        <?php foreach ($courses as $c):
            $lvlColor = $levelColors[$c['level']] ?? '#6366f1';
            $tName = trim(($c['t_first'] ?? '') . ' ' . ($c['t_last'] ?? ''));
        ?>
        <div class="course-card">
            <div class="course-title"><?= htmlspecialchars($c['title']) ?></div>
            <div class="course-meta"><?= htmlspecialchars($c['name_ua']) ?></div>
            <?php if ($tName): ?>
            <div class="course-teacher-label">Викладач: <span><?= htmlspecialchars($tName) ?></span></div>
            <?php else: ?>
            <div class="course-teacher-label" style="color:var(--danger)">⚠ Викладач не призначений</div>
            <?php endif; ?>
            <div class="students-count">👥 <?= (int)$c['students_count'] ?> учнів</div>
            <div class="course-row">
                <span class="level-badge" style="color:<?= $lvlColor ?>;background:<?= $lvlColor ?>22">
                    <?= htmlspecialchars($c['level']) ?>
                </span>
                <span class="price"><?= htmlspecialchars($c['price']) ?> ₴</span>
            </div>
            <div class="card-actions">
                <a class="act-btn act-edit" href="edit_course.php?id=<?= $c['id'] ?>">Редагувати</a>
                <a class="act-btn act-del" href="#"
                   onclick="confirmAction('?delete_course=<?= $c['id'] ?>','Видалити курс?','Курс &laquo;<?= htmlspecialchars(addslashes($c['title'])) ?>&raquo; буде видалено безповоротно.')">
                    Видалити
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<div class="divider"></div>

<!-- ══ СТУДЕНТИ ══ -->
<section>
<div class="sec-head">
    <div class="sec-title">Студенти</div>
    <span class="sec-count"><?= count($students) ?></span>

    <?php
    $pendingEnroll = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending'")->fetchColumn();
    $pendingApps   = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new'")->fetchColumn();
    ?>

    <!-- Заявки через apply.php (без реєстрації) -->
    <a class="h-btn<?= $pendingApps > 0 ? ' chat' : '' ?>" href="admin_applications.php"
       style="<?= $pendingApps > 0 ? '' : 'color:var(--muted)' ?>">
        Заявки<?= $pendingApps > 0 ? " ({$pendingApps})" : '' ?>
    </a>

    <a class="plus-btn" href="admin_users.php?role=student" title="Додати студента">+</a>
</div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ім'я</th>
                    <th>Email</th>
                    <th>Записаний на курси</th>
                    <th>Статус</th>
                    <th>Дії</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($students)): ?>
                <tr><td colspan="5" class="empty">Немає студентів</td></tr>
            <?php else: ?>
            <?php foreach ($students as $u):
                $isBanned = $u['status'] === 'banned';
                $courses_str = $u['courses'] ?? '';
                $courseList = $courses_str ? explode(' · ', $courses_str) : [];
            ?>
            <tr>
                <td class="name-cell"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
                <td class="email-cell"><?= htmlspecialchars($u['email']) ?></td>
                <td class="courses-cell">
                    <?php if (empty($courseList)): ?>
                        <span class="no-courses">— без курсів</span>
                    <?php else: ?>
                        <?php foreach ($courseList as $cTitle): ?>
                            <span class="course-pill"><?= htmlspecialchars(trim($cTitle)) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-dot s-<?= $u['status'] ?>">
                        <?= $statusLabels[$u['status']] ?? $u['status'] ?>
                    </span>
                </td>
                <td>
                    <div class="tbl-actions">
                        <a class="tbl-edit" href="admin_users.php?id=<?= $u['id'] ?>">Редагувати</a>
                        <!-- НОВЕ: кнопка "Написати" — відкриває чат з цим студентом -->
                           <?php if ($u['status'] === 'inactive'): ?>
                        <a class="tbl-edit" href="admin_enrollments.php" 
                        style="color:var(--amber);background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.25);">
                            ⏳ Заявка
                        </a>
                        <?php endif; ?>
                        <a class="tbl-chat" href="chat.php?open_user=<?= $u['id'] ?>">💬 Написати</a>
                        <?php if ($isBanned): ?>
                        <a class="tbl-unblock" href="?toggle_user=<?= $u['id'] ?>">Розблокувати</a>
                        <?php else: ?>
                        <a class="tbl-block" href="#"
                           onclick="confirmAction('?toggle_user=<?= $u['id'] ?>','Заблокувати користувача?','<?= htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])) ?> буде заблокований.')">
                            Заблокувати
                        </a>
                        <?php endif; ?>
                        <a class="tbl-del" href="#"
                           onclick="confirmAction('?delete_user=<?= $u['id'] ?>','Видалити користувача?','<?= htmlspecialchars(addslashes($u['first_name'].' '.$u['last_name'])) ?> буде видалений безповоротно.')">
                            Видалити
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="divider"></div>

<!-- ══ ВИКЛАДАЧІ ══ -->
<section>
    <div class="sec-head">
        <div class="sec-title">Викладачі</div>
        <span class="sec-count"><?= count($teachers) ?></span>
        <a class="plus-btn" href="admin_users.php" title="Додати викладача">+</a>
    </div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ім'я</th>
                    <th>Email</th>
                    <th>Веде курси</th>
                    <th>Статус</th>
                    <th>Дії</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($teachers)): ?>
                <tr><td colspan="5" class="empty">Немає викладачів</td></tr>
            <?php else: ?>
            <?php foreach ($teachers as $t):
                $isBanned = $t['status'] === 'banned';
                $tCourses = $t['courses'] ?? '';
                $tCourseList = $tCourses ? explode(' · ', $tCourses) : [];
            ?>
            <tr>
                <td class="name-cell"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></td>
                <td class="email-cell"><?= htmlspecialchars($t['email']) ?></td>
                <td class="courses-cell">
                    <?php if (empty($tCourseList)): ?>
                        <span class="no-courses">— курсів немає</span>
                    <?php else: ?>
                        <?php foreach ($tCourseList as $cTitle): ?>
                            <span class="course-pill"><?= htmlspecialchars(trim($cTitle)) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-dot s-<?= $t['status'] ?>">
                        <?= $statusLabels[$t['status']] ?? $t['status'] ?>
                    </span>
                </td>
                <td>
                    <div class="tbl-actions">
                        <a class="tbl-edit" href="admin_users.php?id=<?= $t['id'] ?>">Редагувати</a>
                        <!-- НОВЕ: кнопка "Написати" — відкриває чат з цим викладачем -->
                        <a class="tbl-chat" href="chat.php?open_user=<?= $t['id'] ?>">💬 Написати</a>
                        <?php if ($isBanned): ?>
                        <a class="tbl-unblock" href="?toggle_user=<?= $t['id'] ?>">Розблокувати</a>
                        <?php else: ?>
                        <a class="tbl-block" href="#"
                           onclick="confirmAction('?toggle_user=<?= $t['id'] ?>','Заблокувати викладача?','<?= htmlspecialchars(addslashes($t['first_name'].' '.$t['last_name'])) ?> буде заблокований.')">
                            Заблокувати
                        </a>
                        <?php endif; ?>
                        <a class="tbl-del" href="#"
                           onclick="confirmAction('?delete_user=<?= $t['id'] ?>','Видалити викладача?','<?= htmlspecialchars(addslashes($t['first_name'].' '.$t['last_name'])) ?> та всі пов\'язані дані будуть видалені.')">
                            Видалити
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</div>

<script>
function confirmAction(href, title, sub) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalSub').innerHTML = sub;
    document.getElementById('modalLink').href = href;
    document.getElementById('confirmModal').classList.add('open');
    return false;
}
function closeModal() {
    document.getElementById('confirmModal').classList.remove('open');
}
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>