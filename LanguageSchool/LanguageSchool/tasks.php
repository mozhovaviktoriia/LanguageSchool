<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacherId = $_SESSION['user_id'];

// ── Fetch teacher info ──────────────────────────────────────────────────────
$stmtUser = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE id = :id");
$stmtUser->execute(['id' => $teacherId]);
$teacherUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

$teacherName = trim(($teacherUser['first_name'] ?? '') . ' ' . ($teacherUser['last_name'] ?? '')) ?: 'Викладач';
$avatarUrl   = $teacherUser['avatar_url'] ?? '';
$initials    = strtoupper(
    (substr($teacherUser['first_name'] ?? '', 0, 1) ?: '') .
    (substr($teacherUser['last_name']  ?? '', 0, 1) ?: '')
) ?: '👨‍🏫';

// ── Fetch teacher's courses ─────────────────────────────────────────────────
$stmtCourses = $pdo->prepare("
    SELECT c.id, c.title
    FROM courses c
    WHERE c.teacher_id = :tid AND c.is_active = true
    ORDER BY c.title
");
$stmtCourses->execute(['tid' => $teacherId]);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

// ── Handle POST actions ─────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';



    // ── GRADE SUBMISSION ────────────────────────────────────────────────────
    if ($action === 'grade') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $score        = $_POST['score'] !== '' ? (float)$_POST['score'] : null;
        $feedback     = trim($_POST['feedback'] ?? '');
        // Маппінг: reviewed = оцінено, assigned = повернено на доопрацювання
        $statusRaw = $_POST['status'] ?? 'reviewed';
        $status    = ($statusRaw === 'returned') ? 'assigned' : 'reviewed';

        if ($submissionId) {
            // Verify submission belongs to teacher's task
            $chk = $pdo->prepare("
                SELECT ts.id, ts.task_id, t.max_score
                FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.id
                WHERE ts.id = :sid AND t.created_by = :tid
            ");
            $chk->execute(['sid' => $submissionId, 'tid' => $teacherId]);
            $sub = $chk->fetch(PDO::FETCH_ASSOC);

            if ($sub) {
                if ($score !== null && ($score < 0 || $score > $sub['max_score'])) {
                    $errorMsg = 'Оцінка виходить за межі допустимого діапазону.';
                } else {
                    $upd = $pdo->prepare("
                        UPDATE task_submissions
                        SET score         = :score,
                            feedback_text = :feedback,
                            status        = :status::task_status,
                            reviewed_by   = :reviewer,
                            reviewed_at   = NOW()
                        WHERE id = :sid
                    ");
                    $upd->execute([
                        'score'    => $score,
                        'feedback' => $feedback,
                        'status'   => $status,
                        'reviewer' => $teacherId,
                        'sid'      => $submissionId,
                    ]);

                    if ($status === 'assigned') {
                        $successMsg = 'Роботу повернено учню на доопрацювання.';
                    } else {
                        $successMsg = 'Оцінку збережено.';
                    }
                }
            } else {
                $errorMsg = 'Відповідь не знайдена.';
            }
        }
    }

    // ── DELETE TASK ────────────────────────────────────────────────────────
    if ($action === 'delete_task') {
        $taskId = $_POST['task_id'] ?? '';
        
        if (!$taskId) {
            $errorMsg = 'ID завдання не передано.';
        } else {
            // Verify task belongs to teacher
            $chk = $pdo->prepare("SELECT id FROM tasks WHERE id = :id AND created_by = :tid");
            $chk->execute(['id' => $taskId, 'tid' => $teacherId]);
            if ($chk->fetch()) {
                try {
                    // Delete task submissions first
                    $pdo->prepare("DELETE FROM task_submissions WHERE task_id = :id")->execute(['id' => $taskId]);
                    // Delete task
                    $pdo->prepare("DELETE FROM tasks WHERE id = :id")->execute(['id' => $taskId]);
                    $successMsg = 'Завдання видалено.';
                } catch (Exception $e) {
                    $errorMsg = 'Помилка при видаленні: ' . $e->getMessage();
                }
            } else {
                $errorMsg = 'Завдання не знайдено або недоступне.';
            }
        }
    }

    // ── ADD COMMENT ─────────────────────────────────────────────────────────
    if ($action === 'add_comment') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $commentText  = trim($_POST['comment_text'] ?? '');

        if ($submissionId && $commentText) {
            // Verify ownership
            $chk = $pdo->prepare("
                SELECT ts.id FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.id
                WHERE ts.id = :sid AND t.created_by = :tid
            ");
            $chk->execute(['sid' => $submissionId, 'tid' => $teacherId]);
            if ($chk->fetch()) {
                // Store comment in chat_messages or a dedicated comments table.
                // Here we append to feedback_text for simplicity if no separate table exists.
                // If you have a task_comments table, replace with an INSERT there.
                $pdo->prepare("
                    UPDATE task_submissions
                    SET feedback_text = CASE
                        WHEN feedback_text IS NULL OR feedback_text = '' THEN :comment
                        ELSE feedback_text || E'\n---\n' || :comment2
                    END
                    WHERE id = :sid
                ")->execute([
                    'comment'  => $commentText,
                    'comment2' => $commentText,
                    'sid'      => $submissionId,
                ]);
                $successMsg = 'Коментар додано.';
            }
        }
    }

    // Redirect to avoid re-POST on refresh
    if ($successMsg || $errorMsg) {
        $_SESSION['flash_success'] = $successMsg;
        $_SESSION['flash_error'] = $errorMsg;
    }
    header("Location: tasks.php?view=" . ($_GET['view'] ?? 'list') . (isset($_GET['task_id']) ? '&task_id=' . (int)$_GET['task_id'] : ''));
    exit;
}

// ── Read flash messages from session ──────────────────────────────────────
$successMsg = htmlspecialchars($_SESSION['flash_success'] ?? '');
$errorMsg   = htmlspecialchars($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Current view ────────────────────────────────────────────────────────────
$view         = $_GET['view']    ?? 'list';   // list | create | grade
$activeTaskId = (int)($_GET['task_id'] ?? 0);
$filterStatus = $_GET['filter']  ?? 'all';

// ── Fetch tasks created by this teacher ────────────────────────────────────
$sqlTasks = "
    SELECT
        t.id,
        t.title,
        t.description,
        t.task_type,
        t.max_score,
        t.deadline,
        t.created_at,
        c.title  AS course_title,
        c.id     AS course_id,
        l.title  AS lesson_title,
        COUNT(ts.id)                                                  AS total_submissions,
        COUNT(ts.id) FILTER (WHERE ts.status = 'submitted')          AS pending_count,
        COUNT(ts.id) FILTER (WHERE ts.status = 'reviewed')           AS graded_count,
        COUNT(ts.id) FILTER (WHERE ts.status = 'assigned')           AS returned_count,
        ROUND(AVG(ts.score) FILTER (WHERE ts.score IS NOT NULL), 1) AS avg_score
    FROM tasks t
    LEFT JOIN lessons l       ON t.lesson_id  = l.id
    LEFT JOIN courses c       ON l.course_id  = c.id OR t.lesson_id IS NULL AND c.teacher_id = t.created_by
    LEFT JOIN task_submissions ts ON ts.task_id = t.id
    WHERE t.created_by = :tid
    GROUP BY t.id, t.title, t.description, t.task_type, t.max_score, t.deadline, t.created_at,
             c.title, c.id, l.title
    ORDER BY t.created_at DESC
";
$stmtTasks = $pdo->prepare($sqlTasks);
$stmtTasks->execute(['tid' => $teacherId]);
$tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

$totalTasks    = count($tasks);
$pendingTotal  = array_sum(array_column($tasks, 'pending_count'));
$gradedTotal   = array_sum(array_column($tasks, 'graded_count'));

// ── Fetch submissions for active task (grade view) ──────────────────────────
$submissions = [];
$activeTask  = null;
if ($view === 'grade' && $activeTaskId) {
    $stmtAT = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND created_by = :tid");
    $stmtAT->execute(['id' => $activeTaskId, 'tid' => $teacherId]);
    $activeTask = $stmtAT->fetch(PDO::FETCH_ASSOC);

    if ($activeTask) {
        $sqlSubs = "
            SELECT
                ts.id,
                ts.student_id,
                ts.content_text,
                ts.file_url,
                ts.score,
                ts.feedback_text,
                ts.status,
                ts.reviewed_at,
                ts.created_at,
                u.first_name,
                u.last_name,
                u.avatar_url
            FROM task_submissions ts
            JOIN users u ON ts.student_id = u.id
            WHERE ts.task_id = :task_id
            ORDER BY ts.created_at DESC
        ";
        $stmtSubs = $pdo->prepare($sqlSubs);
        $stmtSubs->execute(['task_id' => $activeTaskId]);
        $submissions = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Fetch lessons for selected course (AJAX endpoint) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lessons' && isset($_GET['course_id'])) {
    $cid = (int)$_GET['course_id'];
    $stmtL = $pdo->prepare("
        SELECT id, title, scheduled_at
        FROM lessons
        WHERE course_id = :cid AND teacher_id = :tid
        ORDER BY scheduled_at DESC
    ");
    $stmtL->execute(['cid' => $cid, 'tid' => $teacherId]);
    header('Content-Type: application/json');
    echo json_encode($stmtL->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Update last activity ────────────────────────────────────────────────────
$pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = :id")->execute(['id' => $teacherId]);

// ── Helper ──────────────────────────────────────────────────────────────────
function taskTypeLabel(string $t): string {
    return match($t) {
        'homework'  => 'Домашнє завдання',
        'classwork' => 'Класна робота',
        'project'   => 'Проект',
        'essay'     => 'Есе',
        default     => ucfirst($t),
    };
}
function taskTypeIcon(string $t): string {
    return match($t) {
        'homework'  => '📋',
        'classwork' => '✏️',
        'project'   => '🗂️',
        'essay'     => '📝',
        default     => '📄',
    };
}
function statusLabel(string $s): string {
    return match($s) {
        'submitted' => 'Очікує перевірки',
        'reviewed'  => 'Оцінено',
        'assigned'  => 'Повернено',
        'overdue'   => 'Прострочено',
        default     => ucfirst($s),
    };
}
function statusClass(string $s): string {
    return match($s) {
        'reviewed' => 'status-graded',
        'assigned' => 'status-returned',
        'overdue'  => 'status-returned',
        default    => 'status-pending',
    };
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Завдання — EduSpace</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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

/* ── Sidebar ── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: var(--sidebar); height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    padding: 28px 18px;
    z-index: 100; gap: 6px;
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
    background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.nav-label {
    font-family: var(--mono);
    font-size: 9px; color: var(--muted);
    letter-spacing: 2px; text-transform: uppercase;
    padding: 0 8px; margin: 10px 0 4px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    text-decoration: none; color: var(--muted);
    font-size: 13px; font-weight: 600;
    transition: .2s; border: 1px solid transparent;
}
.nav-item:hover, .nav-item.active {
    background: rgba(99,102,241,.12);
    color: var(--text); border-color: rgba(99,102,241,.25);
}
.nav-item.active { color: #a5b4fc; }
.nav-icon { font-size: 15px; width: 20px; text-align: center; }
.sidebar-bottom {
    margin-top: auto; padding-top: 16px;
    border-top: 1px solid var(--border);
}
.help-box {
    background: rgba(99,102,241,.08);
    border: 1px solid rgba(99,102,241,.2);
    border-radius: 12px; padding: 14px; text-align: center;
}
.help-box .help-icon { font-size: 28px; margin-bottom: 8px; }
.help-box .help-title { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
.help-box .help-sub { font-family: var(--mono); font-size: 10px; color: var(--muted); line-height: 1.4; }

/* ── Page layout ── */
.page {
    margin-left: var(--sidebar);
    margin-right: var(--right);
    flex: 1; position: relative; z-index: 1;
    min-height: 100vh; display: flex; flex-direction: column;
}
.topbar {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 32px;
    border-bottom: 1px solid var(--border);
    background: rgba(13,17,23,.88);
    backdrop-filter: blur(20px);
    position: sticky; top: 0; z-index: 50;
}
.topbar-title {
    font-size: 16px; font-weight: 800;
    background: linear-gradient(90deg, #e2e8f0, #a5b4fc);
    background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.topbar-date { font-family: var(--mono); font-size: 11px; color: var(--muted); margin-left: auto; }
.content { padding: 28px 32px; flex: 1; }

/* ── Stats row ── */
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 28px; }
.stat-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px;
    position: relative; overflow: hidden; transition: .2s;
    animation: fadeUp .4s ease both;
}
.stat-card::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
    opacity: 0; transition: opacity .25s;
}
.stat-card.c-purple::after { background: linear-gradient(90deg, var(--accent), #818cf8); }
.stat-card.c-amber::after  { background: linear-gradient(90deg, var(--amber), #fcd34d); }
.stat-card.c-green::after  { background: linear-gradient(90deg, var(--green), #86efac); }
.stat-card:hover { border-color: var(--accent); transform: translateY(-3px); }
.stat-card:hover::after { opacity: 1; }
.stat-icon { font-size: 20px; margin-bottom: 12px; display: block; }
.stat-num  { font-size: 34px; font-weight: 800; letter-spacing: -1px; line-height: 1; }
.c-purple .stat-num { color: #a5b4fc; }
.c-amber  .stat-num { color: var(--amber); }
.c-green  .stat-num { color: var(--green); }
.stat-label { font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 7px; }

/* ── Section header ── */
.sec-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.sec-title { font-size: 16px; font-weight: 800; }
.sec-line  { flex: 1; height: 1px; background: var(--border); }
.sec-count {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
    background: var(--border); padding: 2px 9px; border-radius: 99px;
}

/* ── Filter tabs ── */
.filter-tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-tab {
    font-family: var(--mono); font-size: 11px;
    padding: 6px 14px; border-radius: 8px;
    border: 1px solid var(--border);
    color: var(--muted); text-decoration: none; transition: .2s;
}
.filter-tab:hover { color: var(--text); border-color: var(--accent); }
.filter-tab.active {
    background: rgba(99,102,241,.15);
    color: #a5b4fc; border-color: rgba(99,102,241,.4);
}

/* ── Tasks list ── */
.tasks-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 28px; }
.task-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px 24px;
    display: grid; grid-template-columns: 1fr auto;
    gap: 12px 16px; align-items: start;
    transition: .2s; animation: fadeUp .35s ease both; cursor: pointer;
    text-decoration: none; color: inherit;
}
.task-card:hover { border-color: rgba(99,102,241,.5); transform: translateX(4px); }
.task-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.task-type-icon { font-size: 16px; }
.task-title { font-size: 15px; font-weight: 700; }
.task-meta-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.task-tag {
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); background: var(--border);
    padding: 2px 8px; border-radius: 6px;
}
.task-deadline { font-family: var(--mono); font-size: 10px; color: var(--amber); }
.task-deadline.overdue { color: var(--red); }
.task-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.sub-counts { display: flex; gap: 6px; }
.sub-badge {
    font-family: var(--mono); font-size: 10px;
    padding: 3px 8px; border-radius: 6px;
}
.sub-badge.pending  { background: rgba(245,158,11,.12); color: var(--amber); }
.sub-badge.graded   { background: rgba(34,197,94,.12);  color: var(--green); }
.sub-badge.returned { background: rgba(239,68,68,.12);  color: var(--red); }
.sub-badge.neutral  { background: var(--border); color: var(--muted); }
.btn-grade {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: var(--mono); font-size: 11px;
    padding: 7px 14px; border-radius: 9px;
    background: rgba(99,102,241,.12);
    border: 1px solid rgba(99,102,241,.3);
    color: #a5b4fc; text-decoration: none; transition: .2s;
}
.btn-grade:hover { background: rgba(99,102,241,.22); border-color: rgba(99,102,241,.6); }

/* ── Create form ── */
.create-form {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 18px; padding: 28px 32px;
    max-width: 760px; animation: fadeUp .4s ease;
}
.form-section { margin-bottom: 24px; }
.form-section-title {
    font-family: var(--mono); font-size: 10px; color: var(--teal);
    text-transform: uppercase; letter-spacing: 1.5px;
    margin-bottom: 14px; padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-family: var(--mono); font-size: 11px; color: var(--muted); }
.form-input,
.form-select,
.form-textarea {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 14px;
    color: var(--text); font-family: var(--font); font-size: 13px;
    transition: .2s; outline: none;
    appearance: none; -webkit-appearance: none;
}
.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    border-color: var(--accent);
    background: rgba(99,102,241,.06);
    box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.form-textarea { resize: vertical; min-height: 100px; line-height: 1.6; }
.form-input::placeholder,
.form-textarea::placeholder { color: var(--muted); }
.form-select option { background: #111827; }

.btn-row { display: flex; gap: 10px; margin-top: 6px; align-items: center; }
.btn-primary {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 24px; border-radius: 10px;
    background: linear-gradient(135deg, var(--accent), #818cf8);
    color: white; font-family: var(--font); font-size: 13px; font-weight: 700;
    border: none; cursor: pointer; transition: .2s;
    box-shadow: 0 4px 16px rgba(99,102,241,.3);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,.4); }
.btn-secondary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px; border-radius: 10px;
    background: transparent; color: var(--muted);
    font-family: var(--font); font-size: 13px; font-weight: 600;
    border: 1px solid var(--border); cursor: pointer; transition: .2s;
    text-decoration: none;
}
.btn-secondary:hover { color: var(--text); border-color: var(--muted); }
.btn-danger-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 9px;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5; font-family: var(--mono); font-size: 11px;
    cursor: pointer; transition: .2s;
}
.btn-danger-outline:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }
.btn-success-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 9px;
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.25);
    color: #86efac; font-family: var(--mono); font-size: 11px;
    cursor: pointer; transition: .2s;
}
.btn-success-outline:hover { background: rgba(34,197,94,.18); border-color: rgba(34,197,94,.5); }

/* ── Grade view ── */
.grade-topbar {
    display: flex; align-items: center; gap: 14px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 18px 24px;
    margin-bottom: 20px; animation: fadeUp .3s ease;
}
.grade-task-title { font-size: 17px; font-weight: 800; }
.grade-task-meta  { font-family: var(--mono); font-size: 11px; color: var(--muted); margin-top: 3px; }
.grade-stats { display: flex; gap: 10px; margin-left: auto; flex-wrap: wrap; }

.submission-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
    margin-bottom: 14px; animation: fadeUp .35s ease both;
    transition: border-color .2s;
}
.submission-card.is-open { border-color: rgba(99,102,241,.4); }
.sub-header {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px; cursor: pointer;
    transition: background .15s;
}
.sub-header:hover { background: rgba(255,255,255,.03); }
.sub-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: white;
    flex-shrink: 0; overflow: hidden;
}
.sub-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sub-student-name { font-size: 14px; font-weight: 700; }
.sub-date { font-family: var(--mono); font-size: 10px; color: var(--muted); margin-top: 2px; }
.sub-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }
.sub-toggle { color: var(--muted); font-size: 12px; transition: transform .25s; }
.sub-toggle.open { transform: rotate(180deg); }

.status-badge {
    font-family: var(--mono); font-size: 10px;
    padding: 3px 9px; border-radius: 6px;
}
.status-pending  { background: rgba(245,158,11,.12); color: var(--amber); }
.status-graded   { background: rgba(34,197,94,.12);  color: var(--green); }
.status-returned { background: rgba(239,68,68,.12);  color: var(--red); }

.sub-body { display: none; padding: 0 20px 20px; border-top: 1px solid var(--border); }
.sub-body.open { display: block; }

.answer-block {
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    border-radius: 10px; padding: 16px;
    margin: 16px 0; font-size: 13px; line-height: 1.7;
    white-space: pre-wrap; color: var(--text);
}
.file-link {
    display: inline-flex; align-items: center; gap: 8px;
    font-family: var(--mono); font-size: 11px;
    padding: 6px 14px; border-radius: 8px;
    background: rgba(34,211,238,.08); border: 1px solid rgba(34,211,238,.2);
    color: var(--teal); text-decoration: none; margin-bottom: 14px;
    transition: .2s;
}
.file-link:hover { background: rgba(34,211,238,.15); }

.grade-section { display: grid; grid-template-columns: auto 1fr; gap: 20px; margin-bottom: 18px; align-items: start; }
.grade-input-wrap { display: flex; align-items: center; gap: 8px; }
.score-input {
    width: 80px;
    background: rgba(255,255,255,.06); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 12px;
    color: var(--text); font-family: var(--mono); font-size: 16px; font-weight: 700;
    text-align: center; outline: none; transition: .2s;
}
.score-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.score-max { font-family: var(--mono); font-size: 14px; color: var(--muted); }
.feedback-label { font-family: var(--mono); font-size: 11px; color: var(--muted); margin-bottom: 6px; display: block; }

.comments-block {
    border-top: 1px solid var(--border);
    padding-top: 16px; margin-top: 16px;
}
.comments-title { font-family: var(--mono); font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; }
.comment-item { display: flex; gap: 10px; margin-bottom: 12px; }
.comment-bubble {
    flex: 1; background: rgba(255,255,255,.04);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 10px 14px;
}
.comment-author { font-size: 12px; font-weight: 700; color: #a5b4fc; margin-bottom: 4px; }
.comment-text   { font-size: 12px; color: var(--text); line-height: 1.5; white-space: pre-wrap; }
.comment-input-row { display: flex; gap: 8px; margin-top: 10px; }
.comment-input-row .form-input { flex: 1; padding: 8px 12px; font-size: 12px; }

.action-row { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }

/* ── Alerts ── */
.alert {
    padding: 12px 18px; border-radius: 10px;
    font-family: var(--mono); font-size: 12px;
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
    animation: fadeUp .3s ease;
}
.alert-success { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: #86efac; }
.alert-error   { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.3);  color: #fca5a5; }

/* ── Empty state ── */
.empty-state {
    background: var(--card); border: 1px dashed var(--border);
    border-radius: var(--radius); padding: 48px 32px; text-align: center;
    font-family: var(--mono); font-size: 12px; color: var(--muted);
}
.empty-icon { font-size: 40px; display: block; margin-bottom: 12px; opacity: .5; }

/* ── Right panel ── */
.right-panel {
    position: fixed; top: 0; right: 0;
    width: var(--right); height: 100vh;
    background: rgba(13,17,23,.92);
    backdrop-filter: blur(20px);
    border-left: 1px solid var(--border);
    padding: 28px 18px;
    display: flex; flex-direction: column; gap: 20px;
    z-index: 100; overflow-y: auto;
}
.profile-block { text-align: center; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.profile-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent) 0%, var(--teal) 100%);
    margin: 0 auto 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; color: white;
    border: 3px solid rgba(99,102,241,.3);
    box-shadow: 0 0 24px rgba(99,102,241,.25);
    overflow: hidden; position: relative;
}
.profile-avatar img {
    width: 100%; height: 100%; object-fit: cover;
    position: absolute; inset: 0; border-radius: 50%;
}
.profile-name { font-size: 15px; font-weight: 800; margin-bottom: 4px; }
.profile-role { font-family: var(--mono); font-size: 10px; color: var(--teal); text-transform: uppercase; letter-spacing: 1px; }
.profile-btn {
    display: block; margin: 12px auto 0; padding: 8px 20px;
    background: linear-gradient(135deg, var(--accent), #818cf8);
    color: white; text-decoration: none; border-radius: 10px;
    font-size: 12px; font-weight: 700; text-align: center; width: fit-content;
    transition: .2s; box-shadow: 0 4px 16px rgba(99,102,241,.3);
}
.profile-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,.4); }

.quick-stats-title { font-size: 14px; font-weight: 800; margin-bottom: 12px; }
.quick-stat-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0; border-bottom: 1px solid rgba(30,41,59,.5);
}
.quick-stat-row:last-child { border-bottom: none; }
.qs-label { font-family: var(--mono); font-size: 11px; color: var(--muted); }
.qs-val   { font-family: var(--mono); font-size: 13px; font-weight: 700; }

.logout {
    display: block; padding: 9px;
    background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.2);
    border-radius: 10px; color: #fca5a5;
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    text-decoration: none; text-align: center; transition: .2s; margin-top: auto;
}
.logout:hover { background: rgba(239,68,68,.18); border-color: rgba(239,68,68,.5); }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
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
    <div class="sidebar-bottom">
        <button class="theme-toggle" title="Змінити тему">
            <span class="theme-toggle-icon">☀️</span>
        </button>
        <div class="help-box">
            <div class="help-icon">🎯</div>
            <div class="help-title">Потрібна допомога?</div>
            <div class="help-sub">Зверніться до підтримки у будь-який час</div>
        </div>
    </div>
</aside>

<!-- ════ MAIN PAGE ════ -->
<div class="page">
    <div class="topbar">
        <div class="topbar-title">
            <?php if ($view === 'grade' && $activeTask): ?>
                📋 Оцінювання: <?= htmlspecialchars($activeTask['title']) ?>
            <?php else: ?>
                ✓ Завдання
            <?php endif; ?>
        </div>
        <a href="add_task.php" class="btn-primary" style="margin-left:auto; font-size:12px; padding:8px 18px;">
            ✚ Нове завдання
        </a>
        <div class="topbar-date" id="dateLabel"></div>
    </div>

    <div class="content">

        <?php if ($successMsg): ?>
        <div class="alert alert-success">✓ <?= $successMsg ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-error">✕ <?= $errorMsg ?></div>
        <?php endif; ?>

        <!-- ════════════════════════════════════ LIST VIEW ════ -->
        <?php if ($view === 'list'): ?>

        <div class="stats">
            <div class="stat-card c-purple" style="animation-delay:.05s">
                <span class="stat-icon">📋</span>
                <div class="stat-num"><?= $totalTasks ?></div>
                <div class="stat-label">Всього завдань</div>
            </div>
            <div class="stat-card c-amber" style="animation-delay:.10s">
                <span class="stat-icon">⏳</span>
                <div class="stat-num"><?= $pendingTotal ?></div>
                <div class="stat-label">Очікують перевірки</div>
            </div>
            <div class="stat-card c-green" style="animation-delay:.15s">
                <span class="stat-icon">✅</span>
                <div class="stat-num"><?= $gradedTotal ?></div>
                <div class="stat-label">Оцінено відповідей</div>
            </div>
        </div>

        <div class="sec-head">
            <div class="sec-title">Мої завдання</div>
            <div class="sec-line"></div>
            <span class="sec-count"><?= $totalTasks ?></span>
        </div>

        <div class="filter-tabs">
            <a href="tasks.php?filter=all"        class="filter-tab <?= $filterStatus === 'all'        ? 'active' : '' ?>">Всі</a>
            <a href="tasks.php?filter=submitted"  class="filter-tab <?= $filterStatus === 'submitted'  ? 'active' : '' ?>">Очікують ⏳</a>
            <a href="tasks.php?filter=reviewed"   class="filter-tab <?= $filterStatus === 'reviewed'   ? 'active' : '' ?>">Оцінено ✅</a>
            <a href="tasks.php?filter=assigned"   class="filter-tab <?= $filterStatus === 'assigned'   ? 'active' : '' ?>">Повернено ↩</a>
        </div>

        <?php if ($tasks):
            // Apply filter — column names відповідають SQL aliases вище
            $filteredTasks = array_filter($tasks, function($t) use ($filterStatus) {
                if ($filterStatus === 'all')       return true;
                if ($filterStatus === 'submitted') return $t['pending_count']  > 0;
                if ($filterStatus === 'reviewed')  return $t['graded_count']   > 0;
                if ($filterStatus === 'assigned')  return $t['returned_count'] > 0;
                return true;
            });
        ?>
        <?php if ($filteredTasks): ?>
        <div class="tasks-list">
            <?php foreach ($filteredTasks as $i => $t):
                $isOverdue = $t['deadline'] && strtotime($t['deadline']) < time();
            ?>
            <div class="task-card" style="animation-delay:<?= $i * 0.04 ?>s">
                <div>
                    <div class="task-header">
                        <span class="task-type-icon"><?= taskTypeIcon($t['task_type']) ?></span>
                        <span class="task-title"><?= htmlspecialchars($t['title']) ?></span>
                    </div>
                    <div class="task-meta-row">
                        <span class="task-tag"><?= taskTypeLabel($t['task_type']) ?></span>
                        <?php if ($t['course_title']): ?>
                        <span class="task-tag">📘 <?= htmlspecialchars($t['course_title']) ?></span>
                        <?php endif; ?>
                        <?php if ($t['lesson_title']): ?>
                        <span class="task-tag">🎓 <?= htmlspecialchars($t['lesson_title']) ?></span>
                        <?php endif; ?>
                        <span class="task-tag">макс. <?= (int)$t['max_score'] ?> б.</span>
                        <?php if ($t['deadline']): ?>
                        <span class="task-deadline <?= $isOverdue ? 'overdue' : '' ?>">
                            <?= $isOverdue ? '⚠' : '⏱' ?> <?= (new DateTime($t['deadline']))->format('d.m.Y') ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($t['avg_score'] !== null): ?>
                        <span class="task-tag" style="color:var(--teal)">Ø <?= $t['avg_score'] ?> б.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="task-actions">
                    <div class="sub-counts">
                        <?php if ($t['pending_count'] > 0): ?>
                        <span class="sub-badge pending"><?= $t['pending_count'] ?> нових</span>
                        <?php endif; ?>
                        <?php if ($t['graded_count'] > 0): ?>
                        <span class="sub-badge graded"><?= $t['graded_count'] ?> оцінено</span>
                        <?php endif; ?>
                        <?php if ($t['returned_count'] > 0): ?>
                        <span class="sub-badge returned"><?= $t['returned_count'] ?> повернено</span>
                        <?php endif; ?>
                        <?php if ($t['total_submissions'] == 0): ?>
                        <span class="sub-badge neutral">0 відповідей</span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center;">
                    <a href="homework.php?task_id=<?= $t['id'] ?>" class="btn-grade">
                        📝 Перевіряти
                    </a>
                        </a>
                        <form method="POST" action="tasks.php" style="margin: 0;">
                            <input type="hidden" name="action" value="delete_task">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn-grade" style="background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3);color:#fca5a5;" onclick="return confirm('Видалити це завдання? Вся дані про відповіді також будуть видалені.')">
                                🗑 Видалити
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <span class="empty-icon">🔍</span>
            Завдань за цим фільтром не знайдено
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <span class="empty-icon">📋</span>
            У вас ще немає завдань.<br>Натисніть «Нове завдання», щоб створити перше.
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════ GRADE VIEW ════ -->
        <?php elseif ($view === 'grade'): ?>

        <?php if (!$activeTask): ?>
        <div class="empty-state">
            <span class="empty-icon">❌</span>
            Завдання не знайдено або у вас немає до нього доступу.
        </div>
        <?php else: ?>

        <div class="grade-topbar">
            <div>
                <div class="grade-task-title"><?= htmlspecialchars($activeTask['title']) ?></div>
                <div class="grade-task-meta">
                    <?= taskTypeLabel($activeTask['task_type']) ?>
                    &nbsp;·&nbsp; Макс. <?= (int)$activeTask['max_score'] ?> балів
                    <?php if ($activeTask['deadline']): ?>
                        &nbsp;·&nbsp; Дедлайн: <?= (new DateTime($activeTask['deadline']))->format('d.m.Y H:i') ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grade-stats">
                <?php
                $totalSubs    = count($submissions);
                $pendingSubs  = count(array_filter($submissions, fn($s) => $s['status'] === 'submitted'));
                $gradedSubs   = count(array_filter($submissions, fn($s) => $s['status'] === 'reviewed'));
                $returnedSubs = count(array_filter($submissions, fn($s) => $s['status'] === 'assigned'));
                $scores       = array_filter(array_column($submissions, 'score'), fn($v) => $v !== null);
                $avgScore     = $scores ? round(array_sum($scores) / count($scores), 1) : null;
                ?>
                <?php if ($pendingSubs): ?><span class="sub-badge pending"><?= $pendingSubs ?> очікують</span><?php endif; ?>
                <?php if ($gradedSubs):  ?><span class="sub-badge graded"><?= $gradedSubs ?> оцінено</span><?php endif; ?>
                <?php if ($returnedSubs):?><span class="sub-badge returned"><?= $returnedSubs ?> повернено</span><?php endif; ?>
                <?php if ($avgScore !== null): ?><span class="sub-badge" style="background:rgba(34,211,238,.1);color:var(--teal)">Ø <?= $avgScore ?> б.</span><?php endif; ?>
            </div>
            <a href="tasks.php" class="btn-secondary" style="flex-shrink:0">← Назад</a>
        </div>

        <?php if ($activeTask['description']): ?>
        <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2);border-radius:12px;padding:16px 20px;margin-bottom:20px;font-size:13px;line-height:1.7;color:var(--text);">
            <div style="font-family:var(--mono);font-size:10px;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Умова завдання</div>
            <?= nl2br(htmlspecialchars($activeTask['description'])) ?>
        </div>
        <?php endif; ?>

        <?php if ($submissions): ?>
        <?php foreach ($submissions as $i => $sub):
            $sInitials = strtoupper(
                substr($sub['first_name'] ?? '', 0, 1) .
                substr($sub['last_name']  ?? '', 0, 1)
            );
            $subDate = (new DateTime($sub['created_at']))->format('d.m.Y H:i');
        ?>
        <div class="submission-card" id="sub-card-<?= $sub['id'] ?>">
            <!-- Header (click to expand) -->
            <div class="sub-header" onclick="toggleSub(<?= $sub['id'] ?>)">
                <div class="sub-avatar">
                    <?php if (!empty($sub['avatar_url']) && file_exists($sub['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($sub['avatar_url']) ?>" alt="">
                    <?php else: ?>
                        <?= $sInitials ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="sub-student-name"><?= htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']) ?></div>
                    <div class="sub-date">Подано: <?= $subDate ?></div>
                </div>
                <div class="sub-right">
                    <?php if ($sub['score'] !== null): ?>
                    <span style="font-family:var(--mono);font-size:13px;font-weight:700;color:var(--green)">
                        <?= $sub['score'] ?> / <?= (int)$activeTask['max_score'] ?>
                    </span>
                    <?php endif; ?>
                    <span class="status-badge <?= statusClass($sub['status']) ?>"><?= statusLabel($sub['status']) ?></span>
                    <span class="sub-toggle" id="toggle-<?= $sub['id'] ?>">▼</span>
                </div>
            </div>

            <!-- Expandable body -->
            <div class="sub-body" id="sub-body-<?= $sub['id'] ?>">

                <!-- Answer text -->
                <?php if ($sub['content_text']): ?>
                <div style="font-family:var(--mono);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:16px;margin-bottom:6px;">Відповідь учня</div>
                <div class="answer-block"><?= htmlspecialchars($sub['content_text']) ?></div>
                <?php endif; ?>

                <!-- Attached file -->
                <?php if ($sub['file_url']): ?>
                <a href="<?= htmlspecialchars($sub['file_url']) ?>" class="file-link" target="_blank">
                    📎 Прикріплений файл
                </a>
                <?php endif; ?>

                <!-- Video / audio -->
                <?php if (!empty($sub['video_url'])): ?>
                <a href="<?= htmlspecialchars($sub['video_url']) ?>" class="file-link" target="_blank">🎬 Відео-відповідь</a>
                <?php endif; ?>

                <!-- Grade + feedback form -->
                <form method="POST"
                      action="tasks.php?view=grade&task_id=<?= $activeTaskId ?>">
                    <input type="hidden" name="action"        value="grade">
                    <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">

                    <div class="grade-section">
                        <div>
                            <label class="feedback-label">Оцінка</label>
                            <div class="grade-input-wrap">
                                <input class="score-input" name="score" type="number"
                                       min="0" max="<?= (int)$activeTask['max_score'] ?>"
                                       step="0.5"
                                       value="<?= $sub['score'] !== null ? htmlspecialchars($sub['score']) : '' ?>"
                                       placeholder="—">
                                <span class="score-max">/ <?= (int)$activeTask['max_score'] ?></span>
                            </div>
                        </div>
                        <div>
                            <label class="feedback-label">Коментар / зворотний зв'язок</label>
                            <textarea class="form-textarea" name="feedback"
                                      placeholder="Опишіть, що зроблено добре, що потрібно виправити..."
                                      style="min-height:80px"><?= htmlspecialchars($sub['feedback_text'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="action-row">
                        <button type="submit" name="status" value="reviewed" class="btn-success-outline">
                            ✓ Зберегти оцінку
                        </button>
                        <button type="submit" name="status" value="returned" class="btn-danger-outline"
                                onclick="return confirmReturn(this)">
                            ↩ Повернути на доопрацювання
                        </button>
                    </div>
                </form>

                <!-- Inline comment (quick note without changing grade) -->
                <div class="comments-block">
                    <div class="comments-title">Швидкий коментар</div>
                    <form method="POST"
                          action="tasks.php?view=grade&task_id=<?= $activeTaskId ?>"
                          class="comment-input-row">
                        <input type="hidden" name="action"        value="add_comment">
                        <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                        <input class="form-input" name="comment_text"
                               placeholder="Додати нотатку або запитання до учня...">
                        <button type="submit" class="btn-primary" style="padding:8px 16px;font-size:12px;">
                            Надіслати
                        </button>
                    </form>
                </div>

            </div><!-- /sub-body -->
        </div><!-- /submission-card -->
        <?php endforeach; ?>

        <?php else: ?>
        <div class="empty-state">
            <span class="empty-icon">📭</span>
            Поки що жоден учень не подав відповідь на це завдання.
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /page -->

<!-- ════ RIGHT PANEL ════ -->
<aside class="right-panel">
    <div class="profile-block">
        <div class="profile-avatar">
            <?php if (!empty($avatarUrl) && file_exists($avatarUrl)): ?>
                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Аватар">
            <?php else: ?>
                <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
        </div>
        <div class="profile-name"><?= htmlspecialchars($teacherName) ?></div>
        <div class="profile-role">Викладач</div>
        <a class="profile-btn" href="profile.php">Профіль</a>
    </div>

    <div>
        <div class="quick-stats-title">📊 Статистика завдань</div>
        <div class="quick-stat-row">
            <span class="qs-label">Всього завдань</span>
            <span class="qs-val" style="color:#a5b4fc"><?= $totalTasks ?></span>
        </div>
        <div class="quick-stat-row">
            <span class="qs-label">Очікують перевірки</span>
            <span class="qs-val" style="color:var(--amber)"><?= $pendingTotal ?></span>
        </div>
        <div class="quick-stat-row">
            <span class="qs-label">Оцінено</span>
            <span class="qs-val" style="color:var(--green)"><?= $gradedTotal ?></span>
        </div>
        <div class="quick-stat-row">
            <span class="qs-label">Активних курсів</span>
            <span class="qs-val" style="color:var(--teal)"><?= count($courses) ?></span>
        </div>
    </div>

    <a class="logout" href="logout.php">🚪 Вийти</a>
</aside>

<script>
// ── Date label ──────────────────────────────────────────────────────────────
(function(){
    const opt = { day:'numeric', month:'long', year:'numeric', weekday:'long' };
    const el = document.getElementById('dateLabel');
    if (el) el.textContent = new Date().toLocaleDateString('uk-UA', opt);
})();

// ── Toggle submission expand/collapse ───────────────────────────────────────
function toggleSub(id) {
    const body   = document.getElementById('sub-body-'  + id);
    const toggle = document.getElementById('toggle-'    + id);
    const card   = document.getElementById('sub-card-'  + id);
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    if (toggle) toggle.classList.toggle('open', !isOpen);
    if (card)   card.classList.toggle('is-open', !isOpen);
}

// ── Confirm "return for revision" ───────────────────────────────────────────
function confirmReturn(btn) {
    const form     = btn.closest('form');
    const feedback = form.querySelector('textarea[name="feedback"]');
    if (!feedback || !feedback.value.trim()) {
        alert('Будь ласка, напишіть коментар — що саме потрібно виправити учню.');
        feedback && feedback.focus();
        return false;
    }
    return confirm('Повернути роботу учню на доопрацювання?\nУчень отримає ваш коментар.');
}

// ── Load lessons for selected course via AJAX ───────────────────────────────
function loadLessons(courseId) {
    const sel = document.getElementById('lessonSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">Завантаження...</option>';
    if (!courseId) {
        sel.innerHTML = '<option value="">— Оберіть урок —</option>';
        return;
    }
    fetch('tasks.php?ajax=lessons&course_id=' + encodeURIComponent(courseId))
        .then(r => r.json())
        .then(lessons => {
            sel.innerHTML = '<option value="">— Оберіть урок —</option>';
            lessons.forEach(l => {
                const date = l.scheduled_at
                    ? ' (' + new Date(l.scheduled_at).toLocaleDateString('uk-UA') + ')'
                    : '';
                const opt = document.createElement('option');
                opt.value = l.id;
                opt.textContent = l.title + date;
                sel.appendChild(opt);
            });
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Помилка завантаження</option>';
        });
}

// Auto-open first submitted (pending) submission in grade view
document.querySelectorAll('.submission-card').forEach((card, i) => {
    const badge = card.querySelector('.status-pending');
    if (badge && i === 0) {
        const id = card.id.replace('sub-card-', '');
        toggleSub(id);
    }
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>