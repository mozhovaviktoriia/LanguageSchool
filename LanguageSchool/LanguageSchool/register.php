<?php
session_start();
require 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard_student.php");
    exit;
}

// Load active courses for the dropdown
$courses = $pdo->query("
    SELECT c.id, c.title, c.level, c.price, l.name_ua
    FROM courses c
    JOIN languages l ON c.language_id = l.id
    WHERE c.is_active = TRUE
    ORDER BY l.name_ua, c.title
")->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $email     = trim($_POST['email']      ?? '');
    $password  = trim($_POST['password']   ?? '');
    $courseId  = (int)($_POST['course_id'] ?? 0);

    // Validation
    if (!$firstName || !$lastName || !$phone || !$email || !$password || !$courseId) {
        $message = 'Будь ласка, заповніть усі поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Невірний формат email.';
    } elseif (mb_strlen($password) < 6) {
        $message = 'Пароль має бути не менше 6 символів.';
    } else {
        // Check if email already exists
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $chk->execute(['email' => $email]);
        if ($chk->fetch()) {
            $message = 'Цей email вже зареєстрований.';
        } else {
            try {
                $pdo->beginTransaction();

                // Insert user with status 'inactive' — admin must approve
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password_hash, first_name, last_name, phone, role, status)
                    VALUES (:email, :hash, :fn, :ln, :phone, 'student', 'inactive')
                ");
                $stmt->execute([
                    'email' => $email,
                    'hash'  => $hashedPassword,
                    'fn'    => $firstName,
                    'ln'    => $lastName,
                    'phone' => $phone,
                ]);
                $userId = $pdo->lastInsertId();

                // Create enrollment with status 'pending', reset if already existed (rejected/old)
                $enroll = $pdo->prepare("
                    INSERT INTO enrollments (student_id, course_id, status, enrolled_at)
                    VALUES (:uid, :cid, 'pending', NOW())
                    ON CONFLICT (student_id, course_id)
                    DO UPDATE SET status = 'pending', enrolled_at = NOW()
                ");
                $enroll->execute(['uid' => $userId, 'cid' => $courseId]);

                $pdo->commit();
                $success = true;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = 'Помилка реєстрації. Спробуйте ще раз.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Реєстрація | LinguaSchool</title>
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
    max-width: 460px;
    color: #fff;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.logo {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -.5px;
    margin-bottom: 6px;
}
.logo span { color: #a5f3fc; }

.subtitle {
    font-size: 13px;
    opacity: .7;
    margin-bottom: 28px;
    font-family: 'JetBrains Mono', monospace;
}

.row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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

.field input,
.field select {
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
.field input:focus,
.field select:focus {
    border-color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.15);
}

.field select option { background: #1e293b; color: #e2e8f0; }

.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 6px 0 16px;
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

.btn-submit {
    width: 100%;
    padding: 14px;
    margin-top: 4px;
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
}
.msg.error { background: rgba(239,68,68,.2); border: 1px solid rgba(239,68,68,.4); }
.msg.success { background: rgba(34,211,153,.15); border: 1px solid rgba(34,211,153,.35); }

.success-icon { font-size: 42px; text-align: center; margin-bottom: 14px; }
.success-title { font-size: 20px; font-weight: 800; text-align: center; margin-bottom: 10px; }
.success-text { font-size: 13px; opacity: .8; text-align: center; line-height: 1.6; margin-bottom: 24px; }

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

<?php if ($success): ?>
    <!-- Success screen -->
    <div class="success-icon">🎉</div>
    <div class="success-title">Заявку подано!</div>
    <div class="success-text">
        Ваші дані прийнято. Адміністратор перевірить заявку та зв'яжеться з вами найближчим часом.<br><br>
        Після підтвердження ви зможете увійти до системи.
    </div>
    <a class="btn-submit" href="index.php" style="display:block;text-align:center;text-decoration:none;">
        ← На головну
    </a>

<?php else: ?>

    <div class="logo">Lingua<span>School</span></div>
    <div class="subtitle">Реєстрація нового студента</div>

    <form method="POST">

        <div class="row-2">
            <div class="field">
                <label>Ім'я</label>
                <input type="text" name="first_name"
                       placeholder="Оля"
                       value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Прізвище</label>
                <input type="text" name="last_name"
                       placeholder="Дяченко"
                       value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
            </div>
        </div>

        <div class="field">
            <label>Номер телефону</label>
            <input type="tel" name="phone"
                   placeholder="+380XXXXXXXXX"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
        </div>

        <div class="field">
            <label>Email</label>
            <input type="email" name="email"
                   placeholder="student@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="field">
            <label>Пароль</label>
            <input type="password" name="password" placeholder="Мінімум 6 символів" required>
        </div>

        <div class="divider">Обрати курс</div>

        <div class="field">
            <label>Курс, який вас цікавить</label>
            <select name="course_id" required>
                <option value="">— Оберіть курс —</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= $c['id'] ?>"
                    <?= (isset($_POST['course_id']) && $_POST['course_id'] == $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name_ua']) ?> — <?= htmlspecialchars($c['title']) ?>
                    (<?= htmlspecialchars($c['level']) ?>, <?= $c['price'] ?> грн)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-submit">Подати заявку</button>

        <?php if ($message): ?>
            <div class="msg error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

    </form>

    <div class="links">
        <a href="index.php">← На головну</a>
        <a href="login.php">Вже є акаунт? Увійти</a>
    </div>

<?php endif; ?>

</div>

<script src="theme-switcher.js"></script>
</body>
</html>