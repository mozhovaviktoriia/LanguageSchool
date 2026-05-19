<?php
session_start();
require 'config.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $sql = "
    SELECT id, first_name, role, password_hash
    FROM users
    WHERE email = :email
    AND status = 'active'
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);

    // Verify password
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['first_name'];
        $_SESSION['role'] = $user['role'];

        if ($user['role'] === 'student') {
            header("Location: dashboard_student.php");
            exit;
        }

        if ($user['role'] === 'teacher') {
            header("Location: dashboard_teacher.php");
            exit;
        }

        if ($user['role'] === 'admin') {
            header("Location: admin.php");
            exit;
        }

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
<link href="theme.css" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:'Segoe UI',sans-serif;
    background:linear-gradient(135deg,#26a69a,#ffb74d);
    display:flex;
    justify-content:center;
    align-items:center;
    min-height:100vh;
}

.login-box{
    background:rgba(255,255,255,0.15);
    backdrop-filter:blur(18px);
    padding:40px;
    border-radius:24px;
    width:380px;
    box-shadow:0 15px 40px rgba(0,0,0,.2);
    text-align:center;
    color:white;
}

.login-box h2{
    margin-top:0;
    margin-bottom:25px;
}

input{
    width:100%;
    padding:14px;
    margin-bottom:16px;
    border:none;
    border-radius:14px;
    outline:none;
    font-size:15px;
    box-sizing:border-box;
}

button{
    width:100%;
    padding:14px;
    border:none;
    border-radius:30px;
    background:white;
    color:#009688;
    font-size:16px;
    font-weight:bold;
    cursor:pointer;
    transition:.3s;
}

button:hover{
    transform:translateY(-3px);
    box-shadow:0 10px 25px rgba(0,0,0,.2);
}

.error{
    margin-top:18px;
    font-weight:bold;
    color:#ffebee;
}

.links{
    margin-top:20px;
    display:flex;
    justify-content:space-between;
}

.links a{
    color:white;
    text-decoration:none;
    font-size:14px;
}

.links a:hover{
    text-decoration:underline;
}
</style>
</head>
<body>

<div class="login-box">
    <h2>Вхід до системи</h2>

    <form method="POST">
        <input type="email" name="email" placeholder="Email">
        <input type="password" name="password" placeholder="Пароль">
        <button type="submit">Увійти</button>
    </form>

    <?php if($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="links">
        <a href="index.php">← На головну</a>
        <a href="register.php">Реєстрація</a>
    </div>
</div>

<script src="theme-switcher.js"></script>
</body>
</html>