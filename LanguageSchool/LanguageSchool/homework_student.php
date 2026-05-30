<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$studentId = $_SESSION['user_id'];

/* ── Student info ── */
$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $studentId]);
$student = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: 'Студент';
$initials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1)) ?: '👨‍🎓';

/* ── Flash ── */
$flashSuccess = '';
$flashError   = '';
if (isset($_GET['ok']))  $flashSuccess = 'Роботу успішно здано!';
if (isset($_GET['err'])) $flashError   = htmlspecialchars($_GET['err']);
if (isset($_GET['cancelled'])) $flashSuccess = 'Відправлення скасовано. Можете відредагувати відповідь.';

/* ── Handle POST: submit answer ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId     = $_POST['task_id'] ?? '';
    $action     = $_POST['action'] ?? '';

    if ($action === 'submit' && $taskId) {
        /* Verify task is assigned to this student */
        $chk = $pdo->prepare("
            SELECT id, max_score FROM tasks
            WHERE id = :id AND (assigned_to = :sid OR assigned_to IS NULL)
        ");
        $chk->execute(['id' => $taskId, 'sid' => $studentId]);
        $task = $chk->fetch(PDO::FETCH_ASSOC);

        if ($task) {
            $contentText = trim($_POST['content_text'] ?? '');
            $fileUrl     = null;
            $hasText = $contentText !== '';
            $hasFile = !empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
            if (!$hasText && !$hasFile) {
            $flashError = 'Неможливо здати порожнє завдання. Напишіть відповідь або прикріпіть файл.';
        }

            /* Handle file upload */
            if (!empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/submissions/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $ext      = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $allowed  = ['pdf','doc','docx','txt','jpg','jpeg','png','gif','mp4','mp3','wav','ogg'];
                if (in_array($ext, $allowed) && $_FILES['file']['size'] <= 52428800) {
                    $safeName = uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $filePath = $uploadDir . $safeName;
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                        $fileUrl = $filePath;
                    }
                } else {
                    $flashError = 'Недозволений тип або розмір файлу (макс. 50 МБ).';
                }
            }

            if (!$flashError) {
                /* Check if already submitted — update or insert */
                $existing = $pdo->prepare("SELECT id FROM task_submissions WHERE task_id = :tid AND student_id = :sid");
                $existing->execute(['tid' => $taskId, 'sid' => $studentId]);
                $sub = $existing->fetch();

                if ($sub) {
                    $upd = $pdo->prepare("
                        UPDATE task_submissions
                        SET content_text = :ct, file_url = COALESCE(:fu, file_url),
                            status = 'submitted', submitted_at = NOW()
                        WHERE id = :id
                    ");
                    $upd->execute(['ct' => $contentText, 'fu' => $fileUrl, 'id' => $sub['id']]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO task_submissions (task_id, student_id, content_text, file_url, status, submitted_at)
                        VALUES (:tid, :sid, :ct, :fu, 'submitted', NOW())
                    ");
                    $ins->execute(['tid' => $taskId, 'sid' => $studentId, 'ct' => $contentText, 'fu' => $fileUrl]);
                }
                header("Location: homework_student.php?ok=1");
                exit;
            }
        } else {
            $flashError = 'Завдання не знайдено або недоступне.';
        }
    }

    if ($action === 'cancel' && $taskId) {
        $cancel = $pdo->prepare("
            UPDATE task_submissions
            SET status = 'assigned'
            WHERE task_id = :tid AND student_id = :sid AND status = 'submitted'
        ");
        $cancel->execute(['tid' => $taskId, 'sid' => $studentId]);
        header("Location: homework_student.php?task_id=" . urlencode($taskId) . "&cancelled=1");
        exit;
    }
}

/* ── Fetch tasks assigned to this student (filtered by enrolled courses) ── */
$stmtTasks = $pdo->prepare("
    SELECT
        t.id, t.title, t.description, t.task_type, t.max_score, t.deadline, t.created_at,
        COALESCE(c.title, c2.title) AS course_title,
        u.first_name AS teacher_first, u.last_name AS teacher_last,
        ts.id AS sub_id, ts.status AS sub_status, ts.score, ts.feedback,
        ts.submitted_at, ts.reviewed_at, ts.content_text AS sub_text, ts.file_url AS sub_file
    FROM tasks t
    LEFT JOIN lessons l      ON t.lesson_id = l.id
    LEFT JOIN courses c      ON c.id = l.course_id
    LEFT JOIN courses c2     ON c2.id = t.course_id
    LEFT JOIN users u        ON u.id = t.created_by
    LEFT JOIN task_submissions ts ON ts.task_id = t.id AND ts.student_id = :sid
    WHERE (t.assigned_to = :sid2 OR t.assigned_to IS NULL)
    AND COALESCE(l.course_id, t.course_id) IN (
        SELECT course_id FROM enrollments WHERE student_id = :sid3 AND status = 'active'
    )
    ORDER BY
        CASE WHEN ts.status IS NULL THEN 0
             WHEN ts.status = 'assigned' THEN 1
             WHEN ts.status = 'submitted' THEN 2
             ELSE 3 END,
        t.deadline ASC NULLS LAST,
        t.created_at DESC
");
$stmtTasks->execute(['sid' => $studentId, 'sid2' => $studentId, 'sid3' => $studentId]);
$tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

/* Stats */
$totalTasks    = count($tasks);
$pendingTasks  = count(array_filter($tasks, fn($t) => $t['sub_status'] === null || $t['sub_status'] === 'assigned'));
$submittedTasks= count(array_filter($tasks, fn($t) => $t['sub_status'] === 'submitted'));
$gradedTasks   = count(array_filter($tasks, fn($t) => $t['sub_status'] === 'reviewed'));

/* Active task */
$activeTaskId = $_GET['task_id'] ?? '';
$activeTask   = null;
if ($activeTaskId) {
    foreach ($tasks as $t) {
        if ($t['id'] === $activeTaskId) { $activeTask = $t; break; }
    }
}
/* Auto-open first pending */
if (!$activeTask && $tasks) {
    foreach ($tasks as $t) {
        if ($t['sub_status'] === null || $t['sub_status'] === 'assigned') {
            $activeTask   = $t;
            $activeTaskId = $t['id'];
            break;
        }
    }
    if (!$activeTask) { $activeTask = $tasks[0]; $activeTaskId = $tasks[0]['id']; }
}

function taskTypeIcon(string $t): string {
    return match($t) { 'file'=>'📎','audio'=>'🎙','video'=>'🎬','quiz'=>'🧩','text'=>'📝', default=>'📄' };
}
function statusInfo(string|null $s): array {
    return match($s) {
        'submitted' => ['label'=>'На перевірці','cls'=>'s-pending','dot'=>'dot-pending'],
        'reviewed'  => ['label'=>'Оцінено',     'cls'=>'s-graded', 'dot'=>'dot-graded'],
        'assigned'  => ['label'=>'Повернено',   'cls'=>'s-returned','dot'=>'dot-returned'],
        default     => ['label'=>'Нове',         'cls'=>'s-new',    'dot'=>'dot-new'],
    };
}
function gradeColor(float $pct): string {
    return match(true) { $pct>=90=>'#22c55e', $pct>=75=>'#22d3ee', $pct>=60=>'#f59e0b', $pct>=40=>'#fca5a5', default=>'#ef4444' };
}
function gradeLetter(float $pct): string {
    return match(true) { $pct>=90=>'A', $pct>=75=>'B', $pct>=60=>'C', $pct>=40=>'D', default=>'F' };
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мої завдання — EduSpace</title>
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

/* Sidebar */
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

/* Layout */
.page { margin-left:var(--sidebar); flex:1; display:grid; grid-template-rows:auto 1fr; min-height:100vh; position:relative; z-index:1; overflow:hidden; }
.topbar { display:flex; align-items:center; gap:12px; padding:13px 24px; border-bottom:1px solid var(--border); background:rgba(13,17,23,.9); backdrop-filter:blur(20px); z-index:50; }
.topbar-title { font-size:15px; font-weight:800; background:linear-gradient(90deg,#e2e8f0,#a5b4fc); background-clip:text; -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.topbar-stats { display:flex; gap:8px; margin-left:auto; }
.ts-badge { font-family:var(--mono); font-size:10px; padding:4px 10px; border-radius:99px; }

/* Two-column body */
.body-wrap { display:grid; grid-template-columns:300px 1fr; height:calc(100vh - 55px); overflow:hidden; }

/* LEFT: task list */
.task-list-panel { border-right:1px solid var(--border); overflow-y:auto; background:rgba(13,17,23,.4); }
.tl-header { padding:14px 16px 10px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(13,17,23,.95); backdrop-filter:blur(10px); z-index:5; }
.tl-title { font-size:12px; font-weight:800; margin-bottom:8px; }
.stats-mini { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:var(--border); border-radius:8px; overflow:hidden; margin-bottom:8px; }
.sm-item { background:var(--surface); padding:8px; text-align:center; }
.sm-num { font-family:var(--mono); font-size:16px; font-weight:800; line-height:1; }
.sm-lbl { font-family:var(--mono); font-size:8px; color:var(--muted); margin-top:2px; text-transform:uppercase; }

.task-item { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid rgba(30,41,59,.5); cursor:pointer; transition:.15s; text-decoration:none; color:inherit; position:relative; }
.task-item:hover { background:rgba(255,255,255,.02); }
.task-item.active { background:rgba(99,102,241,.1); border-left:3px solid var(--accent); }
.task-item.active .ti-title { color:#a5b4fc; }
.ti-icon { font-size:20px; flex-shrink:0; width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.04); border-radius:8px; }
.ti-title { font-size:12px; font-weight:700; margin-bottom:3px; }
.ti-meta { font-family:var(--mono); font-size:9px; color:var(--muted); }
.ti-right { margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
.status-dot { width:7px; height:7px; border-radius:50%; }
.dot-new      { background:var(--muted); }
.dot-pending  { background:var(--amber); box-shadow:0 0 5px rgba(245,158,11,.6); }
.dot-graded   { background:var(--green); box-shadow:0 0 5px rgba(34,197,94,.6); }
.dot-returned { background:var(--red);   box-shadow:0 0 5px rgba(239,68,68,.6); }
.s-badge { font-family:var(--mono); font-size:9px; font-weight:700; padding:2px 7px; border-radius:99px; }
.s-new      { background:rgba(100,116,139,.15); color:var(--muted); }
.s-pending  { background:rgba(245,158,11,.15);  color:var(--amber); }
.s-graded   { background:rgba(34,197,94,.15);   color:var(--green); }
.s-returned { background:rgba(239,68,68,.15);   color:#fca5a5; }

.deadline-warn { font-family:var(--mono); font-size:9px; color:var(--red); }

/* RIGHT: task detail */
.task-detail { overflow-y:auto; display:flex; flex-direction:column; }

.td-header { padding:20px 28px 16px; border-bottom:1px solid var(--border); background:rgba(99,102,241,.03); }
.td-title { font-size:18px; font-weight:800; margin-bottom:10px; }
.td-badges { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
.td-badge { font-family:var(--mono); font-size:9px; font-weight:700; padding:3px 9px; border-radius:99px; }
.tb-type  { background:rgba(99,102,241,.12); color:#a5b4fc; }
.tb-score { background:rgba(34,197,94,.1);   color:var(--green); }
.tb-deadline { background:rgba(245,158,11,.12); color:var(--amber); }
.tb-deadline.overdue { background:rgba(239,68,68,.12); color:#fca5a5; }
.tb-teacher { background:rgba(34,211,238,.1); color:var(--teal); }
.td-desc { font-size:13px; color:var(--muted); line-height:1.75; }
.td-attachment { display:inline-flex; align-items:center; gap:8px; margin-top:14px; padding:9px 16px; border-radius:9px; background:rgba(34,211,238,.07); border:1px solid rgba(34,211,238,.2); color:var(--teal); font-family:var(--mono); font-size:10px; text-decoration:none; transition:.2s; }
.td-attachment:hover { background:rgba(34,211,238,.14); }

/* Submission area */
.td-body { flex:1; padding:20px 28px; }
.section-label { font-family:var(--mono); font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; display:flex; align-items:center; gap:8px; }

/* Result card (when graded) */
.result-card { background:rgba(34,197,94,.06); border:1px solid rgba(34,197,94,.2); border-radius:14px; padding:18px 22px; margin-bottom:20px; }
.result-card.returned { background:rgba(239,68,68,.06); border-color:rgba(239,68,68,.2); }
.result-card.pending  { background:rgba(245,158,11,.06); border-color:rgba(245,158,11,.2); }
.rc-score { font-family:var(--mono); font-size:32px; font-weight:800; line-height:1; margin-bottom:4px; }
.rc-label { font-family:var(--mono); font-size:10px; color:var(--muted); margin-bottom:12px; }
.rc-feedback { font-size:13px; line-height:1.7; color:var(--text); background:rgba(255,255,255,.03); border-radius:10px; padding:12px 16px; border:1px solid var(--border); white-space:pre-wrap; }
.rc-feedback-label { font-family:var(--mono); font-size:9px; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }

/* Submit form */
.submit-form { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px 24px; }
.sf-textarea { width:100%; background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:10px; padding:14px 16px; color:var(--text); font-family:var(--font); font-size:13px; line-height:1.7; resize:vertical; min-height:140px; outline:none; transition:.2s; }
.sf-textarea:focus { border-color:var(--accent); background:rgba(99,102,241,.05); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.sf-textarea::placeholder { color:var(--muted); }

.file-upload-area { border:2px dashed var(--border); border-radius:10px; padding:18px; text-align:center; cursor:pointer; transition:.2s; margin-top:12px; }
.file-upload-area:hover { border-color:var(--accent); background:rgba(99,102,241,.04); }
.file-upload-area.has-file { border-color:var(--green); background:rgba(34,197,94,.04); }
.fu-icon { font-size:24px; margin-bottom:6px; }
.fu-text { font-size:12px; color:var(--muted); }
.fu-file-name { font-family:var(--mono); font-size:11px; color:var(--green); margin-top:4px; }

.prev-submission { background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.ps-label { font-family:var(--mono); font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
.ps-text  { font-size:13px; line-height:1.7; color:var(--text); white-space:pre-wrap; }

.btn-submit { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; border-radius:11px; background:linear-gradient(135deg,var(--accent),#818cf8); color:#fff; font-family:var(--font); font-size:14px; font-weight:800; border:none; cursor:pointer; transition:.2s; box-shadow:0 4px 16px rgba(99,102,241,.3); margin-top:14px; }
.btn-submit:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,.4); }
.btn-submit:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* Flash */
.flash { display:flex; align-items:center; gap:10px; padding:10px 16px; border-radius:9px; font-family:var(--mono); font-size:11px; margin:0 28px 16px; }
.flash.ok  { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.25); color:var(--green); }
.flash.err { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#fca5a5; }

/* Empty */
.empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:12px; text-align:center; padding:40px; }
.empty-icon { font-size:48px; opacity:.2; }
.empty-text { font-family:var(--mono); font-size:12px; color:var(--muted); line-height:1.7; }

.no-tasks { padding:32px 16px; text-align:center; font-family:var(--mono); font-size:11px; color:var(--muted); }

@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.task-detail { animation:fadeIn .2s ease; }

/* ═══════════════════════════════════════════════════════════ */
/* LIGHT THEME — читабельний текст                            */
/* ═══════════════════════════════════════════════════════════ */
body.light-theme {
    color: #0f172a;
}

/* Загальні текстові елементи */
body.light-theme .ti-title,
body.light-theme .td-title,
body.light-theme .tl-title,
body.light-theme .ps-text,
body.light-theme .empty-text {
    color: #0f172a !important;
}

body.light-theme .ti-meta,
body.light-theme .sm-lbl,
body.light-theme .nav-label,
body.light-theme .td-desc,
body.light-theme .fu-text,
body.light-theme .rc-label,
body.light-theme .rc-feedback-label,
body.light-theme .section-label,
body.light-theme .ps-label,
body.light-theme .no-tasks {
    color: #475569 !important;
}

/* Навігація */
body.light-theme .nav-item { color: #475569 !important; }
body.light-theme .nav-item:hover { color: #1e293b !important; }
body.light-theme .nav-item.active { color: #4f46e5 !important; }

/* Topbar */
body.light-theme .topbar-title {
    background: linear-gradient(90deg, #1e293b, #4f46e5) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
}

/* Ім'я студента в topbar (inline style color:var(--muted)) */
body.light-theme .topbar span[style*="color:var(--muted)"],
body.light-theme .topbar span[style*="color: var(--muted)"] {
    color: #475569 !important;
}

/* Stats mini */
body.light-theme .sm-item { background: #fff; }

/* Task list panel */
body.light-theme .task-list-panel { background: rgba(241,245,249,.7) !important; }
body.light-theme .tl-header { background: rgba(255,255,255,.97) !important; border-bottom-color: #e2e8f0 !important; }
body.light-theme .task-item { border-bottom-color: #e2e8f0 !important; color: #0f172a !important; }
body.light-theme .task-item:hover { background: rgba(79,70,229,.04) !important; }
body.light-theme .task-item.active { background: rgba(79,70,229,.1) !important; border-left-color: #4f46e5 !important; }
body.light-theme .task-item.active .ti-title { color: #4f46e5 !important; }
body.light-theme .ti-icon { background: rgba(79,70,229,.07) !important; }

/* Task detail header */
body.light-theme .td-header { background: rgba(79,70,229,.03) !important; border-bottom-color: #e2e8f0 !important; }

/* Badges — підкоригувати для видимості на світлому */
body.light-theme .tb-type    { background: rgba(79,70,229,.12) !important;  color: #4f46e5 !important; }
body.light-theme .tb-score   { background: rgba(22,163,74,.12) !important;  color: #16a34a !important; }
body.light-theme .tb-teacher { background: rgba(8,145,178,.12) !important;  color: #0891b2 !important; }
body.light-theme .tb-deadline { background: rgba(217,119,6,.12) !important; color: #b45309 !important; }
body.light-theme .tb-deadline.overdue { background: rgba(220,38,38,.12) !important; color: #dc2626 !important; }
body.light-theme .s-new      { background: rgba(100,116,139,.12) !important; color: #475569 !important; }
body.light-theme .s-pending  { background: rgba(217,119,6,.12) !important;   color: #b45309 !important; }
body.light-theme .s-graded   { background: rgba(22,163,74,.12) !important;   color: #16a34a !important; }
body.light-theme .s-returned { background: rgba(220,38,38,.12) !important;   color: #dc2626 !important; }

/* Result cards */
body.light-theme .rc-label { color: #64748b !important; }
body.light-theme .rc-feedback {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
}
body.light-theme .result-card.returned .rc-feedback { color: #0f172a !important; }

/* "Повернено" — текст всередині result-card */
body.light-theme .result-card.returned > div[style*="color:#fca5a5"] {
    color: #dc2626 !important;
}

/* Pending card — "Здано: ..." */
body.light-theme .result-card.pending div[style*="color:var(--muted)"],
body.light-theme .result-card.pending div[style*="color: var(--muted)"] {
    color: #475569 !important;
}

/* Submit form */
body.light-theme .submit-form {
    background: #ffffff !important;
    border-color: #e2e8f0 !important;
}
body.light-theme .sf-textarea {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
    color: #0f172a !important;
}
body.light-theme .sf-textarea:focus {
    border-color: #4f46e5 !important;
    background: #fff !important;
    box-shadow: 0 0 0 3px rgba(79,70,229,.12) !important;
}
body.light-theme .sf-textarea::placeholder { color: #94a3b8 !important; }

/* File upload */
body.light-theme .file-upload-area {
    border-color: #cbd5e1 !important;
}
body.light-theme .file-upload-area:hover {
    border-color: #4f46e5 !important;
    background: rgba(79,70,229,.04) !important;
}

/* Prev submission */
body.light-theme .prev-submission {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
}

/* Flash */
body.light-theme .flash.ok  { color: #16a34a !important; }
body.light-theme .flash.err { color: #dc2626 !important; }

/* Sidebar */
body.light-theme .sidebar { background: rgba(255,255,255,.97) !important; border-right-color: #e2e8f0 !important; }
body.light-theme .logo { border-bottom-color: #e2e8f0 !important; }
body.light-theme .sidebar-bottom { border-top-color: #e2e8f0 !important; }

/* Topbar */
body.light-theme .topbar { background: rgba(241,245,249,.95) !important; border-bottom-color: #e2e8f0 !important; }
</style>
</head>
<body>

<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon">👨‍🎓</div>
        <div class="logo-text">EduSpace</div>
    </div>
    <span class="nav-label">Меню</span>
    <a class="nav-item" href="dashboard_student.php"><span class="nav-icon">📚</span> Курси</a>
    <a class="nav-item active" href="homework_student.php"><span class="nav-icon">📋</span> Завдання</a>

    <a class="nav-item" href="schedule_student.php"><span class="nav-icon">📅</span> Розклад</a>
    <a class="nav-item" href="chat.php"><span class="nav-icon">💬</span> Чат</a>
    <a class="nav-item" href="https://meet.google.com" target="_blank"><span class="nav-icon">📞</span> Meet</a>
    <div class="sidebar-bottom">        <button class="theme-toggle" title="Змінити тему" style="width:100%;margin-bottom:8px;padding:8px">
            <span class="theme-toggle-icon">☀️</span>
        </button>        <a class="logout" href="logout.php">🚪 Вийти</a>
    </div>
</aside>

<div class="page">
    <div class="topbar">
        <div class="topbar-title">📋 Мої завдання</div>
        <div class="topbar-stats">
            <span class="ts-badge" style="background:rgba(100,116,139,.15);color:var(--muted)"><?= $totalTasks ?> всього</span>
            <?php if ($pendingTasks): ?>
            <span class="ts-badge" style="background:rgba(245,158,11,.15);color:var(--amber)"><?= $pendingTasks ?> нових</span>
            <?php endif; ?>
            <?php if ($gradedTasks): ?>
            <span class="ts-badge" style="background:rgba(34,197,94,.15);color:var(--green)"><?= $gradedTasks ?> оцінено</span>
            <?php endif; ?>
        </div>
        <div style="margin-left:8px;display:flex;align-items:center;gap:8px;">
            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--teal));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff"><?= $initials ?></div>
            <span style="font-family:var(--mono);font-size:11px;color:var(--muted)"><?= htmlspecialchars($studentName) ?></span>
        </div>
    </div>

    <div class="body-wrap">

        <!-- LEFT: Task list -->
        <div class="task-list-panel">
            <div class="tl-header">
                <div class="tl-title">Завдання</div>
                <div class="stats-mini">
                    <div class="sm-item">
                        <div class="sm-num" style="color:#a5b4fc"><?= $totalTasks ?></div>
                        <div class="sm-lbl">Всього</div>
                    </div>
                    <div class="sm-item">
                        <div class="sm-num" style="color:var(--amber)"><?= $pendingTasks ?></div>
                        <div class="sm-lbl">Нових</div>
                    </div>
                    <div class="sm-item">
                        <div class="sm-num" style="color:var(--green)"><?= $gradedTasks ?></div>
                        <div class="sm-lbl">Оцінено</div>
                    </div>
                </div>
            </div>

            <?php if (empty($tasks)): ?>
            <div class="no-tasks">📭<br>Завдань поки немає</div>
            <?php else: ?>
            <?php foreach ($tasks as $t):
                $si = statusInfo($t['sub_status']);
                $isActive = ($t['id'] === $activeTaskId);
                $isOverdue = $t['deadline'] && strtotime($t['deadline']) < time() && $t['sub_status'] === null;
                $pct = ($t['score'] !== null && $t['max_score'] > 0) ? round($t['score'] / $t['max_score'] * 100) : null;
            ?>
            <a href="homework_student.php?task_id=<?= urlencode($t['id']) ?>"
               class="task-item <?= $isActive ? 'active' : '' ?>">
                <div class="ti-icon"><?= taskTypeIcon($t['task_type']) ?></div>
                <div style="flex:1;min-width:0">
                    <div class="ti-title"><?= htmlspecialchars($t['title']) ?></div>
                    <div class="ti-meta">
                        <?= htmlspecialchars($t['course_title'] ?? '') ?>
                        <?php if ($t['deadline']): ?>
                        · <?= (new DateTime($t['deadline']))->format('d.m') ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($isOverdue): ?>
                    <div class="deadline-warn">⚠ Прострочено</div>
                    <?php endif; ?>
                </div>
                <div class="ti-right">
                    <?php if ($pct !== null): ?>
                    <span style="font-family:var(--mono);font-size:11px;font-weight:800;color:<?= gradeColor($pct) ?>"><?= $t['score'] ?>/<?= $t['max_score'] ?></span>
                    <?php endif; ?>
                    <div class="status-dot <?= $si['dot'] ?>"></div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Task detail -->
        <div class="task-detail">
            <?php if (!$activeTask): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <div class="empty-text">Оберіть завдання зліва</div>
            </div>
            <?php else:
                $si = statusInfo($activeTask['sub_status']);
                $isOverdue = $activeTask['deadline'] && strtotime($activeTask['deadline']) < time();
                $pct = ($activeTask['score'] !== null && $activeTask['max_score'] > 0)
                       ? round($activeTask['score'] / $activeTask['max_score'] * 100) : null;
                $canSubmit = in_array($activeTask['sub_status'], [null, 'assigned']);
            ?>

            <!-- Task header -->
            <div class="td-header">
                <div class="td-title"><?= taskTypeIcon($activeTask['task_type']) ?> <?= htmlspecialchars($activeTask['title']) ?></div>
                <div class="td-badges">
                    <span class="td-badge tb-type"><?= ucfirst($activeTask['task_type']) ?></span>
                    <span class="td-badge tb-score">макс. <?= (int)$activeTask['max_score'] ?> б.</span>
                    <?php if ($activeTask['teacher_first']): ?>
                    <span class="td-badge tb-teacher">👨‍🏫 <?= htmlspecialchars($activeTask['teacher_first'] . ' ' . $activeTask['teacher_last']) ?></span>
                    <?php endif; ?>
                    <?php if ($activeTask['deadline']): ?>
                    <span class="td-badge tb-deadline <?= $isOverdue ? 'overdue' : '' ?>">
                        <?= $isOverdue ? '⚠' : '⏱' ?> <?= (new DateTime($activeTask['deadline']))->format('d.m.Y H:i') ?>
                    </span>
                    <?php endif; ?>
                    <span class="td-badge <?= $si['cls'] ?>"><?= $si['label'] ?></span>
                </div>

                <?php if ($activeTask['description']): ?>
                <div class="td-desc"><?= nl2br(htmlspecialchars($activeTask['description'])) ?></div>
                <?php endif; ?>

            </div>

            <div class="td-body">
                <?php if ($flashSuccess): ?>
                <div class="flash ok" style="margin:0 0 16px">✓ <?= htmlspecialchars($flashSuccess) ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                <div class="flash err" style="margin:0 0 16px">✕ <?= htmlspecialchars($flashError) ?></div>
                <?php endif; ?>

                <!-- Result block (graded) -->
                <?php if ($activeTask['sub_status'] === 'reviewed' && $pct !== null): ?>
                <div class="result-card" style="border-color:<?= gradeColor($pct) ?>33;background:<?= gradeColor($pct) ?>0d">
                    <div class="rc-score" style="color:<?= gradeColor($pct) ?>"><?= gradeLetter($pct) ?> · <?= $activeTask['score'] ?>/<?= $activeTask['max_score'] ?></div>
                    <div class="rc-label"><?= $pct ?>% · Перевірено <?= $activeTask['reviewed_at'] ? (new DateTime($activeTask['reviewed_at']))->format('d.m.Y H:i') : '' ?></div>
                    <?php if ($activeTask['feedback']): ?>
                    <div class="rc-feedback-label">Коментар вчителя</div>
                    <div class="rc-feedback"><?= htmlspecialchars($activeTask['feedback']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Returned -->
                <?php if ($activeTask['sub_status'] === 'assigned'): ?>
                <div class="result-card returned">
                    <div style="font-size:13px;font-weight:700;color:#fca5a5;margin-bottom:6px">↩ Повернено на доопрацювання</div>
                    <?php if ($activeTask['feedback']): ?>
                    <div class="rc-feedback-label">Коментар вчителя</div>
                    <div class="rc-feedback"><?= htmlspecialchars($activeTask['feedback']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Pending -->
                <?php if ($activeTask['sub_status'] === 'submitted'): ?>
                <div class="result-card pending">
                    <div style="font-size:13px;font-weight:700;color:var(--amber);margin-bottom:4px">⏳ Робота здана, очікує перевірки</div>
                    <div style="font-family:var(--mono);font-size:10px;color:var(--muted)">Здано: <?= $activeTask['submitted_at'] ? (new DateTime($activeTask['submitted_at']))->format('d.m.Y H:i') : '' ?></div>
                    <form method="POST" style="margin-top:12px">
                        <input type="hidden" name="action"  value="cancel">
                        <input type="hidden" name="task_id" value="<?= htmlspecialchars($activeTask['id']) ?>">
                        <button type="submit" onclick="return confirmCancel()"
                                style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;transition:.2s"
                                onmouseover="this.style.background='rgba(245,158,11,.22)'"
                                onmouseout="this.style.background='rgba(245,158,11,.12)'">
                            ↩ Скасувати відправлення
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Previous submission text (if returned) -->
                <?php if ($activeTask['sub_status'] === 'assigned' && $activeTask['sub_text']): ?>
                <div class="prev-submission">
                    <div class="ps-label">Ваша попередня відповідь</div>
                    <div class="ps-text"><?= htmlspecialchars($activeTask['sub_text']) ?></div>
                </div>
                <?php endif; ?>

                <!-- Submit form -->
                <?php if ($canSubmit): ?>
                <div class="section-label">✏️ <span>Ваша відповідь</span></div>
                <form method="POST" enctype="multipart/form-data" class="submit-form" onsubmit="return validateForm(this)">
                    <input type="hidden" name="action"  value="submit">
                    <input type="hidden" name="task_id" value="<?= htmlspecialchars($activeTask['id']) ?>">

                    <textarea class="sf-textarea" name="content_text"
                              placeholder="Напишіть вашу відповідь тут..."><?= htmlspecialchars($activeTask['sub_text'] ?? '') ?></textarea>

                    <div class="file-upload-area" id="fuArea" onclick="document.getElementById('fileInput').click()">
                        <div class="fu-icon">📎</div>
                        <div class="fu-text">Прикріпити файл (необов'язково)</div>
                        <div class="fu-file-name" id="fuName"></div>
                        <input type="file" id="fileInput" name="file" style="display:none"
                               accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif,.mp4,.mp3,.wav,.ogg"
                               onchange="showFile(this)">
                    </div>

                    <button type="submit" class="btn-submit">
                        🚀 Здати роботу
                    </button>
                </form>
                <?php endif; ?>

                <!-- View submitted answer (read-only) -->
                <?php if ($activeTask['sub_status'] === 'submitted' && $activeTask['sub_text']): ?>
                <div class="section-label" style="margin-top:16px">📝 <span>Ваша відповідь</span></div>
                <div class="prev-submission">
                    <div class="ps-text"><?= htmlspecialchars($activeTask['sub_text']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($activeTask['sub_file']): ?>
                <a href="<?= htmlspecialchars($activeTask['sub_file']) ?>" class="td-attachment" target="_blank" style="margin-top:8px;display:inline-flex">
                    📎 Ваш прикріплений файл
                </a>
                <?php endif; ?>

            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    function validateForm(form) {
    const text = form.content_text.value.trim();
    const file = form.file.files.length > 0;
    if (!text && !file) {
        alert('Будь ласка, напишіть відповідь або прикріпіть файл перед відправкою.');
        return false;
    }
    return true;
}
function showFile(input) {
    const area = document.getElementById('fuArea');
    const name = document.getElementById('fuName');
    if (input.files && input.files[0]) {
        area.classList.add('has-file');
        name.textContent = '✓ ' + input.files[0].name;
    }
}
function confirmCancel() {
    return confirm('Скасувати відправлення? Ваша відповідь збережеться, і ви зможете її відредагувати.');
}
</script>
<script src="theme-switcher.js"></script>
</body>
</html>