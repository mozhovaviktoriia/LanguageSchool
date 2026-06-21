<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacherId = $_SESSION['user_id'];
$taskId    = $_GET['task_id'] ?? $_POST['task_id'] ?? '';
$isEdit    = !empty($taskId);

/* ── Teacher info ── */
$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $teacherId]);
$teacherUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
$teacherName = trim(($teacherUser['first_name'] ?? '') . ' ' . ($teacherUser['last_name'] ?? '')) ?: 'Викладач';
$avatarUrl   = $teacherUser['avatar_url'] ?? '';
$initials    = strtoupper(substr($teacherUser['first_name'] ?? '', 0, 1) . substr($teacherUser['last_name'] ?? '', 0, 1)) ?: '👨‍🏫';

/* ── Teacher's courses ── */
$stmtCourses = $pdo->prepare("
    SELECT id, title FROM courses
    WHERE teacher_id = :tid AND is_active = true
    ORDER BY title
");
$stmtCourses->execute(['tid' => $teacherId]);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

/* ── Students enrolled in teacher's courses ── */
$stmtStudents = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name
    FROM users u
    JOIN enrollments e ON e.student_id = u.id
    JOIN courses c     ON c.id = e.course_id
    WHERE c.teacher_id = :tid AND u.role = 'student'
    ORDER BY u.last_name, u.first_name
");
$stmtStudents->execute(['tid' => $teacherId]);
$students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

/* ── Existing task (edit mode) ── */
$existingTask = null;
if ($isEdit) {
    $stmtTask = $pdo->prepare("
        SELECT id, lesson_id, title, description, task_type, max_score, deadline, assigned_to, created_at
        FROM tasks
        WHERE id = :id AND created_by = :tid
    ");
    $stmtTask->execute(['id' => $taskId, 'tid' => $teacherId]);
    $existingTask = $stmtTask->fetch(PDO::FETCH_ASSOC);
    if (!$existingTask) {
        header('Location: tasks.php');
        exit;
    }
}

/* ── Handle POST ── */
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';

    if ($action === 'save_task') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $courseId    = $_POST['course_id'] ?? null;
        $lessonId    = $_POST['lesson_id'] ?: null;
        $taskType    = $_POST['task_type'] ?? 'text';
        $maxScore    = (int)($_POST['max_score'] ?? 100);
        $deadline    = $_POST['deadline'] ?: null;
        $assignedTo  = $_POST['assigned_to'] ?: null;

        // Валідація дедлайну
        if ($deadline) {
            $deadlineTime = strtotime($deadline);
            $currentTime = time();
            if ($deadlineTime < $currentTime) {
                $errorMsg = 'Дедлайн не може бути в минулому.';
            }
        }

        if (!$title || !$courseId) {
            $errorMsg = 'Заповніть назву та оберіть курс.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM courses WHERE id = :cid AND teacher_id = :tid");
            $chk->execute(['cid' => $courseId, 'tid' => $teacherId]);
            if (!$chk->fetch()) {
                $errorMsg = 'Курс не знайдено або недоступний.';
            } else {
                if (!$errorMsg) {
                    if ($isEdit) {
                        $upd = $pdo->prepare("
                            UPDATE tasks
                            SET lesson_id   = :lesson_id,
                                course_id   = :course_id,
                                title       = :title,
                                description = :description,
                                task_type   = :task_type::task_type,
                                max_score   = :max_score,
                                deadline    = :deadline,
                                assigned_to = :assigned_to
                            WHERE id = :id AND created_by = :tid
                        ");
                        $upd->execute([
                            'id'          => $taskId,
                            'tid'         => $teacherId,
                            'lesson_id'   => $lessonId,
                            'course_id'   => $courseId,
                            'title'       => $title,
                            'description' => $description,
                            'task_type'   => $taskType,
                            'max_score'   => $maxScore,
                            'deadline'    => $deadline,
                            'assigned_to' => $assignedTo,
                        ]);
                        $successMsg = 'Завдання оновлено!';
                    } else {
                        $ins = $pdo->prepare("
                            INSERT INTO tasks
                                (lesson_id, course_id, created_by, title, description, task_type,
                                 max_score, deadline, assigned_to, created_at)
                            VALUES
                                (:lesson_id, :course_id, :created_by, :title, :description, :task_type::task_type,
                                 :max_score, :deadline, :assigned_to, NOW())
                            RETURNING id
                        ");
                        $ins->execute([
                            'lesson_id'   => $lessonId,
                            'course_id'   => $courseId,
                            'created_by'  => $teacherId,
                            'title'       => $title,
                            'description' => $description,
                            'task_type'   => $taskType,
                            'max_score'   => $maxScore,
                            'deadline'    => $deadline,
                            'assigned_to' => $assignedTo,
                        ]);
                        $taskId = $ins->fetchColumn();
                        $successMsg = 'Завдання створено!';
                    }
                    header("Location: tasks.php?success=" . urlencode($successMsg));
                    exit;
                }
            }
        }
    }
}

/* ── AJAX: lessons for course ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lessons' && isset($_GET['course_id'])) {
    $cid = $_GET['course_id'];
    $stmtL = $pdo->prepare("
        SELECT id, title, scheduled_at FROM lessons
        WHERE course_id = :cid AND teacher_id = :tid
        ORDER BY scheduled_at DESC
    ");
    $stmtL->execute(['cid' => $cid, 'tid' => $teacherId]);
    header('Content-Type: application/json');
    echo json_encode($stmtL->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

/* ── AJAX: students for course ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'students' && isset($_GET['course_id'])) {
    $cid = $_GET['course_id'];
    // Verify course belongs to this teacher
    $chkC = $pdo->prepare("SELECT id FROM courses WHERE id = :cid AND teacher_id = :tid");
    $chkC->execute(['cid' => $cid, 'tid' => $teacherId]);
    if (!$chkC->fetch()) { header('Content-Type: application/json'); echo '[]'; exit; }

    $stmtS = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name
        FROM users u
        JOIN enrollments e ON e.student_id = u.id
        WHERE e.course_id = :cid AND e.status = 'active' AND u.role = 'student'
        ORDER BY u.last_name, u.first_name
    ");
    $stmtS->execute(['cid' => $cid]);
    header('Content-Type: application/json');
    echo json_encode($stmtS->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = :id")->execute(['id' => $teacherId]);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isEdit ? 'Редагування завдання' : 'Нове завдання' ?> — EduSpace</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root {
    --bg:#07080f; --surface:#0d1117; --card:#111827; --border:#1e293b;
    --accent:#6366f1; --teal:#22d3ee; --green:#22c55e; --amber:#f59e0b;
    --red:#ef4444; --text:#e2e8f0; --muted:#64748b;
    --font:'Syne',sans-serif; --mono:'JetBrains Mono',monospace;
    --sidebar:220px; --right:260px;
}
body { font-family:var(--font); background:var(--bg); color:var(--text); min-height:100vh; display:flex; }
body::before { content:''; position:fixed; inset:0; background:radial-gradient(ellipse 70% 50% at 8% 10%,rgba(99,102,241,.12) 0%,transparent 55%),radial-gradient(ellipse 50% 40% at 90% 85%,rgba(34,211,238,.09) 0%,transparent 55%); pointer-events:none; z-index:0; }

.sidebar { position:fixed; top:0; left:0; width:var(--sidebar); height:100vh; background:rgba(13,17,23,.95); backdrop-filter:blur(20px); border-right:1px solid var(--border); display:flex; flex-direction:column; padding:24px 16px; z-index:100; gap:4px; }
.logo { display:flex; align-items:center; gap:10px; padding:0 6px 20px; border-bottom:1px solid var(--border); margin-bottom:12px; }
.logo-icon { width:34px; height:34px; background:linear-gradient(135deg,var(--accent),var(--teal)); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
.logo-text { font-size:15px; font-weight:800; background:linear-gradient(90deg,#a5b4fc,var(--teal)); background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.nav-label { font-family:var(--mono); font-size:9px; color:var(--muted); letter-spacing:2px; text-transform:uppercase; padding:0 8px; margin:10px 0 4px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:10px; text-decoration:none; color:var(--muted); font-size:13px; font-weight:600; transition:.2s; border:1px solid transparent; }
.nav-item:hover,.nav-item.active { background:rgba(99,102,241,.12); color:var(--text); border-color:rgba(99,102,241,.25); }
.nav-item.active { color:#a5b4fc; }
.nav-icon { font-size:14px; width:20px; text-align:center; }
.sidebar-bottom { margin-top:auto; padding-top:14px; border-top:1px solid var(--border); }
.logout { display:block; padding:9px; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.2); border-radius:10px; color:#fca5a5; font-family:var(--mono); font-size:11px; font-weight:600; text-decoration:none; text-align:center; transition:.2s; }
.logout:hover { background:rgba(239,68,68,.18); }

.right-panel { position:fixed; top:0; right:0; width:var(--right); height:100vh; background:rgba(13,17,23,.95); backdrop-filter:blur(20px); border-left:1px solid var(--border); padding:24px 18px; display:flex; flex-direction:column; gap:16px; z-index:100; overflow-y:auto; }
.profile-block { text-align:center; padding-bottom:18px; border-bottom:1px solid var(--border); }
.profile-avatar { width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal)); margin:0 auto 10px; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; color:#fff; border:3px solid rgba(99,102,241,.3); overflow:hidden; position:relative; }
.profile-avatar img { width:100%; height:100%; object-fit:cover; position:absolute; inset:0; border-radius:50%; }
.profile-name { font-size:14px; font-weight:800; margin-bottom:3px; }
.profile-role { font-family:var(--mono); font-size:10px; color:var(--teal); }

.page { margin-left:var(--sidebar); margin-right:var(--right); flex:1; position:relative; z-index:1; min-height:100vh; display:flex; flex-direction:column; }
.topbar { display:flex; align-items:center; gap:14px; padding:16px 28px; border-bottom:1px solid var(--border); background:rgba(13,17,23,.9); backdrop-filter:blur(20px); position:sticky; top:0; z-index:50; }
.topbar-title { font-size:15px; font-weight:800; background:linear-gradient(90deg,#e2e8f0,#a5b4fc); background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.content { padding:24px 28px; flex:1; }

.create-form { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:24px 28px; max-width:820px; animation:fadeUp .3s ease; }
.form-section { margin-bottom:24px; }
.form-section-title { font-family:var(--mono); font-size:10px; color:var(--teal); text-transform:uppercase; letter-spacing:1.5px; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-group { display:flex; flex-direction:column; gap:7px; }
.form-group.full { grid-column:1/-1; }
.form-label { font-family:var(--mono); font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; }
.form-input,.form-select,.form-textarea { background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:10px; padding:11px 14px; color:var(--text); font-family:var(--font); font-size:13px; transition:.2s; outline:none; -webkit-appearance:none; width:100%; }
.form-input:focus,.form-select:focus,.form-textarea:focus { border-color:var(--accent); background:rgba(99,102,241,.06); box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.form-textarea { resize:vertical; min-height:110px; line-height:1.6; }
.form-input::placeholder,.form-textarea::placeholder { color:var(--muted); }
.form-select option { background:#111827; }
.form-hint { font-family:var(--mono); font-size:10px; color:var(--muted); margin-top:4px; }

/* Student selector */
.student-selector { display:grid; grid-template-columns:1fr 1fr; gap:8px; max-height:200px; overflow-y:auto; border:1px solid var(--border); border-radius:10px; padding:8px; background:rgba(255,255,255,.02); }
.student-option { display:flex; align-items:center; gap:8px; padding:8px 10px; border-radius:8px; cursor:pointer; transition:.15s; border:1px solid transparent; }
.student-option:hover { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.2); }
.student-option input[type=radio] { accent-color:var(--accent); width:14px; height:14px; flex-shrink:0; }
.student-option-all { grid-column:1/-1; background:rgba(99,102,241,.05); border-color:rgba(99,102,241,.15) !important; }
.so-avatar { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--teal)); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; color:#fff; flex-shrink:0; }
.so-name { font-size:12px; font-weight:600; }

/* File upload */
.file-upload-wrap { border:2px dashed var(--border); border-radius:10px; padding:16px; text-align:center; cursor:pointer; transition:.2s; }
.file-upload-wrap:hover { border-color:var(--accent); background:rgba(99,102,241,.04); }
.file-upload-wrap.has-file { border-color:var(--green); background:rgba(34,197,94,.04); }
.fu-icon { font-size:24px; margin-bottom:6px; }
.fu-text { font-size:12px; color:var(--muted); }
.fu-name { font-family:var(--mono); font-size:11px; color:var(--green); margin-top:4px; }

.btn-row { display:flex; gap:10px; margin-top:24px; align-items:center; }
.btn-primary { display:inline-flex; align-items:center; gap:8px; padding:11px 26px; border-radius:10px; background:linear-gradient(135deg,var(--accent),#818cf8); color:#fff; font-family:var(--font); font-size:13px; font-weight:700; border:none; cursor:pointer; transition:.2s; box-shadow:0 4px 16px rgba(99,102,241,.3); }
.btn-primary:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(99,102,241,.4); }
.btn-secondary { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:10px; background:transparent; color:var(--muted); font-family:var(--font); font-size:13px; font-weight:600; border:1px solid var(--border); cursor:pointer; transition:.2s; text-decoration:none; }
.btn-secondary:hover { color:var(--text); border-color:var(--muted); }

.alert { padding:12px 16px; border-radius:10px; font-family:var(--mono); font-size:12px; margin-bottom:18px; display:flex; align-items:center; gap:10px; }
.alert-success { background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.3); color:#86efac; }
.alert-error   { background:rgba(239,68,68,.12);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
</style>
</head>
<body>

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
    <a class="nav-item" href="chat.php"><span class="nav-icon">💬</span> Чат</a>
    <a class="nav-item" href="https://meet.google.com" target="_blank"><span class="nav-icon">📞</span> Meet</a>
    <div class="sidebar-bottom">        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px">
            <span class="theme-toggle-icon">☀️</span>
        </button>        <a class="logout" href="logout.php">🚪 Вийти</a>
    </div>
</aside>

<div class="page">
    <div class="topbar">
        <div class="topbar-title"><?= $isEdit ? '✏️ Редагування завдання' : '✚ Нове завдання' ?></div>
        <a href="tasks.php" class="btn-secondary" style="margin-left:auto;font-size:12px;padding:7px 14px;">← Назад</a>
    </div>

    <div class="content">
        <?php if ($successMsg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
        <?php if ($errorMsg):   ?><div class="alert alert-error">✕ <?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

        <div class="create-form">
            <form method="POST" enctype="multipart/form-data"
                  action="add_task.php<?= $isEdit ? '?task_id=' . urlencode($taskId) : '' ?>">
                <input type="hidden" name="action" value="save_task">
                <?php if ($isEdit): ?>
                <input type="hidden" name="task_id" value="<?= htmlspecialchars($taskId) ?>">
                <?php endif; ?>

                <!-- ── Основна інформація ── -->
                <div class="form-section">
                    <div class="form-section-title">📌 Основна інформація</div>
                    <div class="form-row">
                        <div class="form-group full">
                            <label class="form-label">Назва завдання *</label>
                            <input class="form-input" name="title" required
                                   placeholder="Наприклад: Домашнє завдання — Present Perfect"
                                   value="<?= htmlspecialchars($existingTask['title'] ?? '') ?>">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Опис та інструкції</label>
                            <textarea class="form-textarea" name="description"
                                      placeholder="Детально опишіть завдання..."><?= htmlspecialchars($existingTask['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ── Параметри ── -->
                <div class="form-section">
                    <div class="form-section-title">⚙️ Параметри завдання</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Тип завдання</label>
                            <select class="form-select" name="task_type">
                                <?php foreach (['text'=>'Текстова відповідь','file'=>'Файл','audio'=>'Аудіо','video'=>'Відео','quiz'=>'Тест'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= (($existingTask['task_type'] ?? 'text') === $v) ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Максимальна оцінка</label>
                            <input class="form-input" name="max_score" type="number" min="1" max="1000"
                                   value="<?= (int)($existingTask['max_score'] ?? 100) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Курс *</label>
                            <select class="form-select" name="course_id" id="courseSelect" required
                                    onchange="loadLessons(this.value)">
                                <option value="">— Оберіть курс —</option>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Урок (необов'язково)</label>
                            <select class="form-select" name="lesson_id" id="lessonSelect">
                                <option value="">— Оберіть урок —</option>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Дедлайн (необов'язково)</label>
                            <input class="form-input" name="deadline" type="datetime-local"
                                   value="<?= htmlspecialchars($existingTask['deadline'] ?? '') ?>"
                                   min="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                    </div>
                </div>



                <!-- ── Призначення студенту ── -->
                <div class="form-section">
                    <div class="form-section-title">👨‍🎓 Призначення студенту</div>
                    <div class="student-selector" id="studentSelector">
                        <div style="font-family:var(--mono);font-size:11px;color:var(--muted);padding:12px">Спочатку оберіть курс</div>
                    </div>
                    <div class="form-hint">Якщо обрати "Всі студенти" — завдання побачать всі учні курсу</div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-primary">
                        <?= $isEdit ? '✓ Оновити завдання' : '✚ Створити завдання' ?>
                    </button>
                    <a href="tasks.php" class="btn-secondary">Скасувати</a>
                </div>
            </form>
        </div>
    </div>
</div>

<aside class="right-panel">
    <div class="profile-block">
        <div class="profile-avatar">
            <?php if ($avatarUrl): ?>
            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
            <?php else: ?>
            <?= $initials ?>
            <?php endif; ?>
        </div>
        <div class="profile-name"><?= htmlspecialchars($teacherName) ?></div>
        <div class="profile-role">Викладач</div>
    </div>
    <div>
        <div style="font-size:13px;font-weight:800;margin-bottom:12px">💡 Поради</div>
        <div style="font-size:12px;line-height:1.7;color:var(--muted)">
            <p style="margin-bottom:8px">Пишіть детальні інструкції щоб учні розуміли що робити.</p>
            <p style="margin-bottom:8px">Прикріплюйте матеріали: документи, приклади, зразки.</p>
            <p>Встановлюйте реалістичні дедлайни для виконання.</p>
        </div>
    </div>
    <a class="logout" href="logout.php" style="margin-top:auto">🚪 Вийти</a>
</aside>

<script>

function loadLessons(courseId) {
    const sel = document.getElementById('lessonSelect');
    if (!courseId) {
        sel.innerHTML = '<option value="">— Оберіть урок —</option>';
        loadStudents('');
        return;
    }
    sel.innerHTML = '<option value="">Завантаження...</option>';
    fetch('add_task.php?ajax=lessons&course_id=' + encodeURIComponent(courseId))
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">— Оберіть урок —</option>';
            data.forEach(l => {
                const o = document.createElement('option');
                o.value = l.id;
                o.textContent = l.title;
                sel.appendChild(o);
            });
        });
    loadStudents(courseId);
}

function loadStudents(courseId) {
    const container = document.getElementById('studentSelector');
    if (!container) return;

    if (!courseId) {
        container.innerHTML = '<div style="font-family:var(--mono);font-size:11px;color:var(--muted);padding:12px">Спочатку оберіть курс</div>';
        return;
    }

    container.innerHTML = '<div style="font-family:var(--mono);font-size:11px;color:var(--muted);padding:12px">Завантаження...</div>';

    fetch('add_task.php?ajax=students&course_id=' + encodeURIComponent(courseId))
        .then(r => r.json())
        .then(students => {
            if (students.length === 0) {
                container.innerHTML = '<div style="font-family:var(--mono);font-size:11px;color:var(--muted);padding:12px">ℹ️ Немає записаних студентів на цьому курсі</div>';
                return;
            }

            const allOption = `
                <label class="student-option student-option-all">
                    <input type="radio" name="assigned_to" value="" checked>
                    <div class="so-avatar" style="background:linear-gradient(135deg,var(--muted),#475569)">👥</div>
                    <span class="so-name">Всі студенти курсу</span>
                </label>`;

            const studentOptions = students.map(s => {
                const init = (s.first_name.charAt(0) + s.last_name.charAt(0)).toUpperCase();
                return `<label class="student-option">
                    <input type="radio" name="assigned_to" value="${s.id}">
                    <div class="so-avatar">${init}</div>
                    <span class="so-name">${s.first_name} ${s.last_name}</span>
                </label>`;
            }).join('');

            container.innerHTML = allOption + studentOptions;
        });
}
</script>
<script src="theme-switcher.js"></script>
</body>
</html>