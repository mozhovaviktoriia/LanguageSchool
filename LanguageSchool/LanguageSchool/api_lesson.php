<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$teacherId = $_SESSION['user_id'];

// ── Зчитуємо action з GET або POST, або з JSON body ──
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Підтримка JSON body (на випадок якщо fetch надсилає JSON)
if (!$action) {
    $body = file_get_contents('php://input');
    if ($body) {
        $json = json_decode($body, true);
        $action = $json['action'] ?? null;
    }
}

// ВИПРАВЛЕННЯ: якщо GET-запит без action — вважаємо це 'get'
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$action) {
    $action = 'get';
}

/* ========= GET ========= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get') {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing lesson id']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT l.*
        FROM lessons l
        WHERE l.id = :id AND l.teacher_id = :tid
    ");
    $stmt->execute(['id' => $id, 'tid' => $teacherId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lesson) {
        http_response_code(404);
        echo json_encode(['error' => 'Lesson not found']);
        exit;
    }

    // Отримуємо студента (якщо обраний)
    $stmtStudent = $pdo->prepare("
        SELECT student_id
        FROM lesson_students
        WHERE lesson_id = :id
        LIMIT 1
    ");
    $stmtStudent->execute(['id' => $id]);
    $studentRow = $stmtStudent->fetch(PDO::FETCH_ASSOC);
    if ($studentRow) {
        $lesson['student_id'] = $studentRow['student_id'];
    }

    echo json_encode($lesson);
    exit;
}

/* ========= DELETE ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $id = $_GET['id'] ?? $_POST['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing lesson id']);
        exit;
    }

    try {
        $stmtDel = $pdo->prepare("DELETE FROM lesson_students WHERE lesson_id = :id");
        $stmtDel->execute(['id' => $id]);

        $stmt = $pdo->prepare("DELETE FROM lessons WHERE id = :id AND teacher_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $teacherId]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Lesson not found or access denied']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

/* ========= ADD / UPDATE ========= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode([
        'error'  => 'Invalid action or method',
        'method' => $_SERVER['REQUEST_METHOD'],
        'action' => $action
    ]);
    exit;
}

$title       = trim($_POST['title']       ?? '');
$description = trim($_POST['description'] ?? '');
$courseId    = $_POST['course_id']   ?? null;
$studentId   = $_POST['student_id']  ?? null;
$date        = $_POST['date']        ?? null;
$time        = $_POST['start_time']  ?? null;
$meetingUrl  = trim($_POST['meeting_url'] ?? '');
$status      = $_POST['status']      ?? 'scheduled';

if (!$title || !$courseId || !$date || !$time) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields: title, course_id, date, start_time are required']);
    exit;
}

// Формуємо timestamp
$scheduledAt = "$date $time:00";
try {
    $dt          = new DateTime($scheduledAt);
    $scheduledAt = $dt->format('Y-m-d H:i:s');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format: ' . $e->getMessage()]);
    exit;
}

/* ===== ADD ===== */
if ($action === 'add') {
    try {
        // UUID v4
        $id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmt = $pdo->prepare("
            INSERT INTO lessons
                (id, teacher_id, course_id, title, description, scheduled_at, meeting_url, status, created_at)
            VALUES
                (:id, :tid, :course, :title, :desc, :at, :url, :status, NOW())
        ");
        $stmt->execute([
            'id'     => $id,
            'tid'    => $teacherId,
            'course' => $courseId,
            'title'  => $title,
            'desc'   => $description,
            'at'     => $scheduledAt,
            'url'    => $meetingUrl ?: null,
            'status' => $status,
        ]);

        if ($studentId) {
            $stmtLS = $pdo->prepare("
                INSERT INTO lesson_students (lesson_id, student_id)
                VALUES (:lid, :sid)
                ON CONFLICT DO NOTHING
            ");
            $stmtLS->execute(['lid' => $id, 'sid' => $studentId]);
        }

        echo json_encode(['success' => true, 'id' => $id]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

/* ===== UPDATE ===== */
if ($action === 'update') {
    $id = $_POST['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing lesson id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE lessons
            SET title        = :title,
                description  = :desc,
                course_id    = :course,
                scheduled_at = :at,
                meeting_url  = :url,
                status       = :status
            WHERE id = :id AND teacher_id = :tid
        ");
        $stmt->execute([
            'title'  => $title,
            'desc'   => $description,
            'course' => $courseId,
            'at'     => $scheduledAt,
            'url'    => $meetingUrl ?: null,
            'status' => $status,
            'id'     => $id,
            'tid'    => $teacherId,
        ]);

        if ($stmt->rowCount() === 0) {
            $chk = $pdo->prepare("SELECT id FROM lessons WHERE id = :id AND teacher_id = :tid");
            $chk->execute(['id' => $id, 'tid' => $teacherId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Lesson not found or access denied']);
                exit;
            }
        }

        // Оновлюємо прив'язку студента
        $stmtDel = $pdo->prepare("DELETE FROM lesson_students WHERE lesson_id = :lid");
        $stmtDel->execute(['lid' => $id]);

        if ($studentId) {
            $stmtLS = $pdo->prepare("
                INSERT INTO lesson_students (lesson_id, student_id)
                VALUES (:lid, :sid)
                ON CONFLICT DO NOTHING
            ");
            $stmtLS->execute(['lid' => $id, 'sid' => $studentId]);
        }

        echo json_encode(['success' => true]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);