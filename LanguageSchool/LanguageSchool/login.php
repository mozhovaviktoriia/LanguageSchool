<?php
session_start();
require 'config.php';

$error = "";

// Повідомлення після редиректу з oauth_google.php
$errorMessages = [
    'pending'        => 'Ваша заявка ще розглядається. Зачекайте підтвердження адміністратора.',
    'inactive'       => 'Ваш акаунт деактивовано. Зверніться до адміністратора.',
    'google_fail'    => 'Помилка авторизації через Google. Спробуйте ще раз.',
    'google_no_email'=> 'Не вдалося отримати email з Google.',
    'state'          => 'Помилка безпеки. Спробуйте ще раз.',
];
if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])) {
    $error = $errorMessages[$_GET['error']];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, first_name, role, password_hash, status
        FROM users
        WHERE email = :email
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $error = "Невірний логін або пароль";
    } elseif ($user['status'] === 'pending') {
    $error = "Ваша заявка ще розглядається. Зачекайте підтвердження адміністратора.";
    } elseif ($user['status'] === 'inactive') {
    $error = "Ваш акаунт деактивовано. Зверніться до адміністратора.";
    } elseif ($user['status'] === 'banned') {
    $error = "Ваш акаунт заблоковано. Зверніться до адміністратора.";
    } elseif (!password_verify($password, $user['password_hash'])) {
        $error = "Невірний логін або пароль";
    } else {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name']    = $user['first_name'];
        $_SESSION['role']    = $user['role'];

        if ($user['role'] === 'student') { header("Location: dashboard_student.php"); exit; }
        if ($user['role'] === 'teacher') { header("Location: dashboard_teacher.php"); exit; }
        if ($user['role'] === 'admin')   { header("Location: admin.php");             exit; }
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

.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 16px 0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    letter-spacing: 2px;
    text-transform: uppercase;
    opacity: .5;
}
.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,0.25);
}

.btn-google {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 13px;
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 14px;
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-family: var(--font, 'Syne', sans-serif);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: background .2s, transform .2s;
    cursor: pointer;
}
.btn-google:hover {
    background: rgba(255,255,255,0.2);
    transform: translateY(-1px);
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

    <div class="divider">або</div>

    <a href="oauth_google.php" class="btn-google">
        <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path fill="#EA4335" d="M24 9.5c3.14 0 5.95 1.08 8.17 2.86l6.1-6.1C34.46 3.09 29.48 1 24 1 14.82 1 7.07 6.48 3.73 14.22l7.1 5.52C12.5 13.59 17.8 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.1 24.5c0-1.64-.15-3.22-.42-4.74H24v8.97h12.42c-.54 2.9-2.18 5.36-4.65 7.01l7.13 5.54C43.16 37.37 46.1 31.4 46.1 24.5z"/>
            <path fill="#FBBC05" d="M10.83 28.26A14.58 14.58 0 0 1 9.5 24c0-1.48.25-2.91.7-4.26l-7.1-5.52A23.93 23.93 0 0 0 .5 24c0 3.87.93 7.53 2.57 10.75l7.76-6.49z"/>
            <path fill="#34A853" d="M24 46.5c5.48 0 10.08-1.82 13.44-4.93l-7.13-5.54c-1.98 1.32-4.51 2.1-6.31 2.1-6.2 0-11.5-4.09-13.17-9.74l-7.76 6.49C7.07 41.52 14.82 46.5 24 46.5z"/>
        </svg>
        Увійти через Google
    </a>

    <div class="links">
        <a href="index.php">← На головну</a>
        <a href="register.php">Реєстрація</a>
    </div>

</div>
<script src="theme-switcher.js"></script>
</body>
</html>