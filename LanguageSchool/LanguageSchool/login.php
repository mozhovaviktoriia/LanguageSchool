<?php
session_start();
require 'config.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, first_name, role, password_hash
        FROM users
        WHERE email = :email
        AND status = 'active'
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name']    = $user['first_name'];
        $_SESSION['role']    = $user['role'];

        if ($user['role'] === 'student') { header("Location: dashboard_student.php"); exit; }
        if ($user['role'] === 'teacher') { header("Location: dashboard_teacher.php"); exit; }
        if ($user['role'] === 'admin')   { header("Location: admin.php");             exit; }
    } else {
        $error = "Невірний логін або пароль";
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вхід | LinguaSchool</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    margin: 0;
    font-family: var(--font, 'Syne', sans-serif);
    background: linear-gradient(135deg, #26a69a, #6366f1);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 24px;
}

.box {
    background: rgba(255,255,255,0.10);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.18);
    padding: 36px 40px;
    border-radius: 24px;
    width: 100%;
    max-width: 400px;
    color: #fff;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.logo { font-size: 22px; font-weight: 800; letter-spacing: -.5px; margin-bottom: 6px; }
.logo span { color: #a5f3fc; }

.subtitle {
    font-size: 13px;
    opacity: .7;
    margin-bottom: 32px;
    font-family: 'JetBrains Mono', monospace;
}

.field { margin-bottom: 14px; }

.field label {
    display: block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    opacity: .75;
    margin-bottom: 6px;
}

.field input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 12px;
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-family: var(--font, 'Syne', sans-serif);
    font-size: 14px;
    outline: none;
    transition: border-color .2s, background .2s;
}

.field input::placeholder { color: rgba(255,255,255,0.45); }
.field input:focus {
    border-color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.15);
}

.btn-submit {
    width: 100%;
    padding: 14px;
    margin-top: 8px;
    border: none;
    border-radius: 14px;
    background: rgba(255,255,255,0.95);
    color: #4f46e5;
    font-family: var(--font, 'Syne', sans-serif);
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
    letter-spacing: -.2px;
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.25);
}

.msg {
    margin-top: 16px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    background: rgba(239,68,68,.2);
    border: 1px solid rgba(239,68,68,.4);
}

.links {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}
.links a {
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 13px;
    transition: color .2s;
}
.links a:hover { color: #fff; }
</style>
</head>
<body>
<div class="box">

    <div class="logo">Lingua<span>School</span></div>
    <div class="subtitle">Вхід до системи</div>

    <form method="POST">
        <div class="field">
            <label>Email</label>
            <input type="email" name="email"
                   placeholder="student@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label>Пароль</label>
            <input type="password" name="password" placeholder="Ваш пароль" required>
        </div>

        <button type="submit" class="btn-submit">Увійти</button>

        <?php if ($error): ?>
            <div class="msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </form>

    <div class="links">
        <a href="index.php">← На головну</a>
        <a href="register.php">Реєстрація</a>
    </div>

</div>
<script src="theme-switcher.js"></script>
</body>
</html>