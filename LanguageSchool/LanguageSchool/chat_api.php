<?php
// JSON API for chat: conversations, messages, sending, polling, file attachments

error_reporting(E_ALL);
ini_set('display_errors', '0');

session_start();
require 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['error' => "$errstr (line $errline in $errfile)"]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$me   = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── Upload directory for chat attachments ──────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/chat/');
define('UPLOAD_URL', 'uploads/chat/');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB
define('ALLOWED_MIME', [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/msword',                                                        // .doc
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // .xlsx
    'application/vnd.ms-excel',                                                  // .xls
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',// .pptx
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'text/plain',
]);

function resp(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    resp(['error' => $msg]);
}
function isUuid(?string $s): bool {
    if (!$s) return false;
    return (bool)preg_match('/^[0-9a-f\-]{36}$/i', $s);
}
function userInfo(PDO $pdo, string $id): array {
    $s = $pdo->prepare(
        "SELECT id, first_name, last_name, avatar_url, role FROM users WHERE id = :id LIMIT 1"
    );
    $s->execute([':id' => $id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
function convBelongsToMe(PDO $pdo, string $convId, string $userId): bool {
    $s = $pdo->prepare(
        "SELECT id FROM chat_conversations
         WHERE id = :c AND (student_id = :u OR teacher_id = :u) LIMIT 1"
    );
    $s->execute([':c' => $convId, ':u' => $userId]);
    return (bool)$s->fetch();
}

// Attach file info to messages array
function attachFilesToMessages(PDO $pdo, array &$msgs): void {
    if (empty($msgs)) return;
    $ids = array_filter(array_column($msgs, 'id'));
    if (empty($ids)) return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT message_id, id AS att_id, original_name, stored_name, mime_type, file_size
         FROM chat_attachments WHERE message_id IN ($placeholders)"
    );
    $st->execute(array_values($ids));
    $atts = $st->fetchAll(PDO::FETCH_ASSOC);

    // Index by message_id
    $byMsg = [];
    foreach ($atts as $a) {
        $byMsg[$a['message_id']][] = $a;
    }
    foreach ($msgs as &$m) {
        $m['attachments'] = $byMsg[$m['id']] ?? [];
    }
}

// ── ACTION: conversations ──────────────────────────────────────────────────
if ($action === 'conversations') {
    $st = $pdo->prepare("
        SELECT
            cc.id, cc.student_id, cc.teacher_id, cc.course_id,
            c.title AS course_title,
            lm.body AS last_body,
            lm.created_at AS last_at,
            lm.sender_id AS last_sender_id,
            lm.has_attachment,
            COALESCE(unr.cnt, 0) AS unread
        FROM chat_conversations cc
        LEFT JOIN courses c ON c.id = cc.course_id
        LEFT JOIN LATERAL (
            SELECT body, created_at, sender_id,
                   (EXISTS(SELECT 1 FROM chat_attachments ca WHERE ca.message_id = m.id)) AS has_attachment
            FROM chat_messages m
            WHERE m.conversation_id = cc.id
            ORDER BY created_at DESC LIMIT 1
        ) lm ON TRUE
        LEFT JOIN LATERAL (
            SELECT COUNT(*) AS cnt FROM chat_messages
            WHERE conversation_id = cc.id AND is_read = FALSE AND sender_id != :me2
        ) unr ON TRUE
        WHERE cc.student_id = :me1 OR cc.teacher_id = :me3
        ORDER BY lm.created_at DESC NULLS LAST
    ");
    $st->execute([':me1' => $me, ':me2' => $me, ':me3' => $me]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $otherId = ($row['student_id'] === $me) ? $row['teacher_id'] : $row['student_id'];
        $row['other'] = userInfo($pdo, $otherId);
    }
    resp(['conversations' => $rows]);
}

// ── ACTION: messages ───────────────────────────────────────────────────────
if ($action === 'messages') {
    $convId = $_GET['conv_id'] ?? '';
    if (!isUuid($convId))                     fail('Invalid conv_id');
    if (!convBelongsToMe($pdo, $convId, $me)) fail('Forbidden', 403);

    $st = $pdo->prepare("
        SELECT m.id, m.sender_id, m.body, m.is_read,
               m.created_at AT TIME ZONE 'UTC' AS created_at,
               u.first_name, u.last_name, u.avatar_url, u.role
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = :c
        ORDER BY m.created_at ASC
        LIMIT 300
    ");
    $st->execute([':c' => $convId]);
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
    attachFilesToMessages($pdo, $msgs);

    $pdo->prepare("
        UPDATE chat_messages SET is_read = TRUE
        WHERE conversation_id = :c AND sender_id != :me AND is_read = FALSE
    ")->execute([':c' => $convId, ':me' => $me]);

    resp(['messages' => $msgs, 'me' => $me]);
}

// ── ACTION: poll ───────────────────────────────────────────────────────────
if ($action === 'poll') {
    $convId  = $_GET['conv_id'] ?? '';
    $afterId = $_GET['after']   ?? '';

    if (!isUuid($convId))                     fail('Invalid conv_id');
    if (!convBelongsToMe($pdo, $convId, $me)) fail('Forbidden', 403);

    if ($afterId && isUuid($afterId)) {
        $st = $pdo->prepare("
            SELECT m.id, m.sender_id, m.body, m.is_read,
                   m.created_at AT TIME ZONE 'UTC' AS created_at,
                   u.first_name, u.last_name, u.avatar_url, u.role
            FROM chat_messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = :c
              AND m.created_at > (SELECT created_at FROM chat_messages WHERE id = :after LIMIT 1)
            ORDER BY m.created_at ASC
        ");
        $st->execute([':c' => $convId, ':after' => $afterId]);
    } else {
        $st = $pdo->prepare("
            SELECT m.id, m.sender_id, m.body, m.is_read,
                   m.created_at AT TIME ZONE 'UTC' AS created_at,
                   u.first_name, u.last_name, u.avatar_url, u.role
            FROM chat_messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = :c
            ORDER BY m.created_at ASC LIMIT 50
        ");
        $st->execute([':c' => $convId]);
    }
    $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
    attachFilesToMessages($pdo, $msgs);

    if ($msgs) {
        $pdo->prepare("
            UPDATE chat_messages SET is_read = TRUE
            WHERE conversation_id = :c AND sender_id != :me AND is_read = FALSE
        ")->execute([':c' => $convId, ':me' => $me]);
    }
    resp(['messages' => $msgs, 'me' => $me]);
}

// ── ACTION: send (text + optional files already uploaded) ─────────────────
if ($action === 'send') {
    $convId     = $_POST['conv_id']      ?? '';
    $body       = trim($_POST['body']    ?? '');
    $attIdsJson = $_POST['attachment_ids'] ?? '[]';

    if (!isUuid($convId))                     fail('Invalid conv_id');
    if ($body === '' && $attIdsJson === '[]')  fail('Empty message');
    if (strlen($body) > 4000)                 fail('Too long');
    if (!convBelongsToMe($pdo, $convId, $me)) fail('Forbidden', 403);

    $attIds = json_decode($attIdsJson, true) ?: [];
    $attIds = array_filter($attIds, 'isUuid');

    // Insert message (body may be null if only files)
    $ins = $pdo->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, body)
        VALUES (:c, :s, :b) RETURNING id
    ");
    $ins->execute([':c' => $convId, ':s' => $me, ':b' => $body ?: null]);
    $newId = $ins->fetchColumn();

    // Link pending attachments to this message
    if ($attIds) {
        $ph  = implode(',', array_fill(0, count($attIds), '?'));
        $upd = $pdo->prepare(
            "UPDATE chat_attachments SET message_id = ? WHERE id IN ($ph) AND message_id IS NULL"
        );
        $upd->execute(array_merge([$newId], array_values($attIds)));
    }

    $st = $pdo->prepare("
        SELECT m.id, m.sender_id, m.body, m.is_read,
               m.created_at AT TIME ZONE 'UTC' AS created_at,
               u.first_name, u.last_name, u.avatar_url, u.role
        FROM chat_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.id = :id
    ");
    $st->execute([':id' => $newId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);
    $msgs = [$msg];
    attachFilesToMessages($pdo, $msgs);

    resp(['message' => $msgs[0]]);
}

// ── ACTION: upload_file ────────────────────────────────────────────────────
if ($action === 'upload_file') {
    if (empty($_FILES['file'])) {
        fail('No file uploaded');
    }
    
    $f = $_FILES['file'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = match($f['error']) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds max size',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form size',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file',
            default => 'Upload error: ' . $f['error'],
        };
        fail($errorMsg);
    }
    
    if ($f['size'] > MAX_FILE_SIZE) {
        fail('File too large (max 20 MB)');
    }

    // Detect MIME by extension
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $extMimeMap = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt'  => 'text/plain',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
    
    $mimeReal = $extMimeMap[$ext] ?? '';

    if (!$mimeReal || !in_array($mimeReal, ALLOWED_MIME, true)) {
        fail('File type not allowed: ' . $ext);
    }

    if (!is_dir(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0755, true)) {
            fail('Cannot create upload directory');
        }
    }

    $stored     = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath   = UPLOAD_DIR . $stored;

    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
        fail('Failed to save file');
    }

    // Insert with NULL message_id — will be linked on send
    try {
        $ins = $pdo->prepare("
            INSERT INTO chat_attachments (message_id, original_name, stored_name, mime_type, file_size)
            VALUES (NULL, :orig, :stored, :mime, :sz)
            RETURNING id
        ");
        $ins->execute([
            ':orig'   => substr($f['name'], 0, 255),
            ':stored' => $stored,
            ':mime'   => $mimeReal,
            ':sz'     => $f['size'],
        ]);
        $attId = $ins->fetchColumn();

        resp([
            'att_id'        => $attId,
            'original_name' => $f['name'],
            'mime_type'     => $mimeReal,
            'file_size'     => $f['size'],
        ]);
    } catch (Exception $e) {
        fail('Database error: ' . $e->getMessage());
    }
}

// ── ACTION: download_file ──────────────────────────────────────────────────
if ($action === 'download_file') {
    // Switch to binary output
    header_remove('Content-Type');

    $attId = $_GET['att_id'] ?? '';
    if (!isUuid($attId)) fail('Invalid att_id');

    // Verify the user owns the conversation this attachment belongs to
    $st = $pdo->prepare("
        SELECT ca.stored_name, ca.original_name, ca.mime_type
        FROM chat_attachments ca
        JOIN chat_messages cm ON cm.id = ca.message_id
        JOIN chat_conversations cc ON cc.id = cm.conversation_id
        WHERE ca.id = :id
          AND (cc.student_id = :me OR cc.teacher_id = :me)
        LIMIT 1
    ");
    $st->execute([':id' => $attId, ':me' => $me]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $path = UPLOAD_DIR . $row['stored_name'];
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }

    header('Content-Type: ' . $row['mime_type']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($row['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-cache');
    readfile($path);
    exit;
}

// ── ACTION: open_or_create ─────────────────────────────────────────────────
if ($action === 'open_or_create') {
    $otherId  = $_POST['other_id']  ?? '';
    $courseId = $_POST['course_id'] ?? '';
    if (!isUuid($otherId)) fail('Invalid other_id');

    $other = userInfo($pdo, $otherId);
    if (!$other) fail('User not found', 404);

    $myRole    = $role;
    $otherRole = $other['role'];

    if      ($myRole === 'student' && $otherRole === 'teacher') { $studentId = $me;      $teacherId = $otherId; }
    elseif  ($myRole === 'teacher' && $otherRole === 'student') { $studentId = $otherId; $teacherId = $me;      }
    elseif  ($myRole === 'student' && $otherRole === 'admin')   { $studentId = $me;      $teacherId = $otherId; }
    elseif  ($myRole === 'admin'   && $otherRole === 'student') { $studentId = $otherId; $teacherId = $me;      }
    elseif  ($myRole === 'teacher' && $otherRole === 'admin')   { $studentId = $me;      $teacherId = $otherId; }
    elseif  ($myRole === 'admin'   && $otherRole === 'teacher') { $studentId = $otherId; $teacherId = $me;      }
    elseif  ($myRole === $otherRole) fail('Не можна писати користувачам з однаковою роллю');
    else    fail('Непідтримувана комбінація ролей');

    $chk = $pdo->prepare(
        "SELECT id FROM chat_conversations WHERE student_id = :s AND teacher_id = :t LIMIT 1"
    );
    $chk->execute([':s' => $studentId, ':t' => $teacherId]);
    $existing = $chk->fetchColumn();

    if ($existing) resp(['conv_id' => $existing, 'created' => false]);

    $courseUuid = isUuid($courseId) ? $courseId : null;
    $ins = $pdo->prepare("
        INSERT INTO chat_conversations (student_id, teacher_id, course_id)
        VALUES (:s, :t, :c) RETURNING id
    ");
    $ins->execute([':s' => $studentId, ':t' => $teacherId, ':c' => $courseUuid]);
    resp(['conv_id' => $ins->fetchColumn(), 'created' => true]);
}

// ── ACTION: mark_read ──────────────────────────────────────────────────────
if ($action === 'mark_read') {
    $convId = $_POST['conv_id'] ?? '';
    if (!isUuid($convId))                     fail('Invalid conv_id');
    if (!convBelongsToMe($pdo, $convId, $me)) fail('Forbidden', 403);

    $pdo->prepare("
        UPDATE chat_messages SET is_read = TRUE
        WHERE conversation_id = :c AND sender_id != :me AND is_read = FALSE
    ")->execute([':c' => $convId, ':me' => $me]);
    resp(['ok' => true]);
}

// ── ACTION: edit_message ───────────────────────────────────────────────────
if ($action === 'edit_message') {
    $msgId  = $_POST['message_id'] ?? '';
    $newBody = trim($_POST['body'] ?? '');

    if (!isUuid($msgId)) fail('Invalid message_id');
    if ($newBody === '') fail('Body cannot be empty');
    if (strlen($newBody) > 4000) fail('Too long');

    // Verify ownership
    $st = $pdo->prepare("
        SELECT m.sender_id, m.conversation_id FROM chat_messages m WHERE m.id = :id
    ");
    $st->execute([':id' => $msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg) fail('Message not found', 404);
    if ($msg['sender_id'] !== $me) fail('Cannot edit other\'s message', 403);

    // Update the message
    $upd = $pdo->prepare("UPDATE chat_messages SET body = :b WHERE id = :id");
    $upd->execute([':b' => $newBody, ':id' => $msgId]);

    resp(['ok' => true]);
}

// ── ACTION: delete_message ────────────────────────────────────────────────
if ($action === 'delete_message') {
    $msgId = $_POST['message_id'] ?? '';

    if (!isUuid($msgId)) fail('Invalid message_id');

    // Verify ownership
    $st = $pdo->prepare("
        SELECT m.sender_id FROM chat_messages m WHERE m.id = :id
    ");
    $st->execute([':id' => $msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg) fail('Message not found', 404);
    if ($msg['sender_id'] !== $me) fail('Cannot delete other\'s message', 403);

    // Delete attachments first
    $attSt = $pdo->prepare("
        SELECT stored_name FROM chat_attachments WHERE message_id = :id
    ");
    $attSt->execute([':id' => $msgId]);
    $attachments = $attSt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($attachments as $att) {
        $path = UPLOAD_DIR . $att['stored_name'];
        if (file_exists($path)) @unlink($path);
    }

    // Delete attachments from DB
    $pdo->prepare("DELETE FROM chat_attachments WHERE message_id = :id")
        ->execute([':id' => $msgId]);

    // Delete the message
    $pdo->prepare("DELETE FROM chat_messages WHERE id = :id")
        ->execute([':id' => $msgId]);

    resp(['ok' => true]);
}

// ── ACTION: delete_conversation ────────────────────────────────────────────
if ($action === 'delete_conversation') {
    $convId = $_POST['conv_id'] ?? '';

    if (!isUuid($convId)) fail('Invalid conv_id');
    if (!convBelongsToMe($pdo, $convId, $me)) fail('Forbidden', 403);

    // Get all attachments for this conversation
    $attSt = $pdo->prepare("
        SELECT ca.stored_name FROM chat_attachments ca
        JOIN chat_messages cm ON cm.id = ca.message_id
        WHERE cm.conversation_id = :c
    ");
    $attSt->execute([':c' => $convId]);
    $attachments = $attSt->fetchAll(PDO::FETCH_ASSOC);

    // Delete files
    foreach ($attachments as $att) {
        $path = UPLOAD_DIR . $att['stored_name'];
        if (file_exists($path)) @unlink($path);
    }

    // Delete attachments
    $pdo->prepare("
        DELETE FROM chat_attachments WHERE message_id IN (
            SELECT id FROM chat_messages WHERE conversation_id = :c
        )
    ")->execute([':c' => $convId]);

    // Delete messages
    $pdo->prepare("DELETE FROM chat_messages WHERE conversation_id = :c")
        ->execute([':c' => $convId]);

    // Delete conversation
    $pdo->prepare("DELETE FROM chat_conversations WHERE id = :c")
        ->execute([':c' => $convId]);

    resp(['ok' => true]);
}

fail('Unknown action');