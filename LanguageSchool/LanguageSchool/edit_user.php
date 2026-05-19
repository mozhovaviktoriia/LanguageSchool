<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

if (!isset($_GET['id'])) {
    die("Користувач не знайдений");
}

$userId = trim($_GET['id']);

// Fetch user data by ID
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, role, status
    FROM users
    WHERE id = :id
");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Користувача не знайдено");
}

$message = "";

// Save user changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName  = trim($_POST['last_name']);
    $email     = trim($_POST['email']);
    $role      = trim($_POST['role']);
    $status    = trim($_POST['status']);

    $update = $pdo->prepare("
        UPDATE users
        SET first_name = :first_name,
            last_name = :last_name,
            email = :email,
            role = :role,
            status = :status
        WHERE id = :id
    ");

    $update->execute([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'role'       => $role,
        'status'     => $status,
        'id'         => $userId
    ]);

    $message = "Дані користувача оновлено";

    // Refresh user data after update
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Редагування користувача</title>

<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 28px;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
        radial-gradient(ellipse 70% 50% at 15% 10%, rgba(99,102,241,.1) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 85% 85%, rgba(34,211,238,.07) 0%, transparent 55%);
    pointer-events: none;
    z-index: 0;
}

.form-box {
    position: relative;
    z-index: 1;
    max-width: 480px;
    margin: 0 auto;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 36px;
}

h2 {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 28px;
}

.field { margin-bottom: 18px; }

.field label {
    display: block;
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 7px;
    font-weight: 600;
}

input, select {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
    font-family: var(--font);
    font-size: 13px;
    padding: 11px 13px;
    outline: none;
    transition: border-color .2s;
}

input:focus, select:focus {
    border-color: var(--accent);
}

input::placeholder {
    color: var(--muted);
}

select option {
    background: #1f2937;
    color: var(--text);
}

.message {
    text-align: center;
    margin-bottom: 18px;
    padding: 12px;
    background: rgba(34,213,98,.1);
    color: var(--success);
    border-radius: 9px;
    font-size: 13px;
}

button {
    width: 100%;
    padding: 12px;
    margin-top: 6px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s, transform .15s;
}

button:hover {
    background: #818cf8;
    transform: translateY(-1px);
}

.back {
    display: block;
    margin-top: 20px;
    text-align: center;
    color: var(--muted);
    text-decoration: none;
    font-size: 12px;
    transition: color .2s;
}

.back:hover {
    color: var(--accent);
}
</style>
</head>
<body>

<div class="form-box">
    <h2>Редагування користувача</h2>

    <?php if($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="field">
            <label>Ім'я</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" placeholder="Ім'я" required>
        </div>
        
        <div class="field">
            <label>Прізвище</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" placeholder="Прізвище" required>
        </div>
        
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="Email" required>
        </div>

        <div class="field">
            <label>Роль</label>
            <select name="role">
                <option value="student" <?= $user['role']=='student'?'selected':'' ?>>Студент</option>
                <option value="teacher" <?= $user['role']=='teacher'?'selected':'' ?>>Викладач</option>
                <option value="admin" <?= $user['role']=='admin'?'selected':'' ?>>Адмін</option>
            </select>
        </div>

        <div class="field">
            <label>Статус</label>
            <select name="status">
                <option value="active" <?= $user['status']=='active'?'selected':'' ?>>Активний</option>
                <option value="inactive" <?= $user['status']=='inactive'?'selected':'' ?>>Неактивний</option>
                <option value="banned" <?= $user['status']=='banned'?'selected':'' ?>>Заблокований</option>
            </select>
        </div>

        <button type="submit">Зберегти зміни</button>
    </form>

    <a class="back" href="admin.php">← Назад до адмін панелі</a>
</div>

</body>
<script src="theme-switcher.js"></script>
</html>