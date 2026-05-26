<?php
session_start();
require 'config.php';

// Якщо залогінений — скидаємо сесію (реєстрація лише для нових учнів)
if (isset($_SESSION['user_id'])) {
    session_unset();
    session_destroy();
    session_start();
}

// Google OAuth — перевіряємо чи прийшли з Google
$viaGoogle = isset($_GET['via']) && $_GET['via'] === 'google' && isset($_SESSION['google_email']);

// Підставляємо дані з Google якщо GET-запит (не POST)
if ($viaGoogle && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST['email']      = $_SESSION['google_email'];
    $_POST['first_name'] = $_SESSION['google_first_name'];
    $_POST['last_name']  = $_SESSION['google_last_name'];
}

// Завантаження активних курсів
$courses = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.title, c.level, c.price, l.name_ua
        FROM courses c
        JOIN languages l ON c.language_id = l.id
        WHERE c.is_active = TRUE
        ORDER BY l.name_ua, c.title
    ");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $courses = [];
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $email     = trim($_POST['email']      ?? '');
    $password  = trim($_POST['password']   ?? '');
    $courseId  = trim($_POST['course_id']  ?? '');
    $isGoogle  = !empty($_POST['via_google']); // прихований input

    // Валідація
    if (!$firstName || !$lastName || !$phone || !$email || !$courseId) {
        $message = 'Будь ласка, заповніть усі поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Невірний формат email.';
    } elseif (!$isGoogle && mb_strlen($password) < 6) {
        // Пароль перевіряємо лише якщо реєстрація не через Google
        $message = 'Пароль має бути не менше 6 символів.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $chk->execute(['email' => $email]);
        if ($chk->fetch()) {
            $message = 'Цей email вже зареєстрований.';
        } else {
            try {
                $pdo->beginTransaction();

                // Якщо через Google — генеруємо випадковий пароль (не потрібен для входу)
                $hashedPassword = $isGoogle
                    ? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)
                    : password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password_hash, first_name, last_name, phone, role, status)
                    VALUES (:email, :hash, :fn, :ln, :phone, 'student', 'inactive')
                    RETURNING id
                ");
                $stmt->execute([
                    'email' => $email,
                    'hash'  => $hashedPassword,
                    'fn'    => $firstName,
                    'ln'    => $lastName,
                    'phone' => $phone,
                ]);
                $userId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];

                $enroll = $pdo->prepare("
                    INSERT INTO enrollments (student_id, course_id, status, enrolled_at)
                    VALUES (:uid::uuid, :cid::uuid, 'pending', NOW())
                    ON CONFLICT (student_id, course_id) DO UPDATE
                        SET status = 'pending', enrolled_at = NOW()
                ");
                $enroll->execute(['uid' => $userId, 'cid' => $courseId]);

                $pdo->commit();

                // Очищаємо Google-дані з сесії
                unset($_SESSION['google_email'], $_SESSION['google_first_name'], $_SESSION['google_last_name']);

                $success = true;

            } catch (PDOException $e) {
                $pdo->rollBack();
                // Розкоментуйте для діагностики:
                // $message = 'DB Error: ' . $e->getMessage();
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

.logo { font-size: 22px; font-weight: 800; letter-spacing: -.5px; margin-bottom: 6px; }
.logo span { color: #a5f3fc; }

.subtitle {
    font-size: 13px;
    opacity: .7;
    margin-bottom: 28px;
    font-family: 'JetBrains Mono', monospace;
}

/* Банер "реєстрація через Google" */
.google-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 12px;
    background: rgba(66,133,244,0.15);
    border: 1px solid rgba(66,133,244,0.35);
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 18px;
    color: #93c5fd;
}

.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
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

.field input:read-only {
    opacity: .6;
    cursor: not-allowed;
}

.field select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='rgba(255,255,255,0.6)' d='M6 8L0 0h12z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
    cursor: pointer;
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

.no-courses {
    padding: 12px 16px;
    border-radius: 10px;
    background: rgba(251,191,36,.15);
    border: 1px solid rgba(251,191,36,.4);
    font-size: 13px;
    color: #fde68a;
    margin-bottom: 14px;
    text-align: center;
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
.btn-submit:disabled {
    opacity: .45;
    cursor: not-allowed;
    transform: none;
}

/* Кнопка Google на сторінці реєстрації */
.divider-or {
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
.divider-or::before, .divider-or::after {
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
}
.msg.error { background: rgba(239,68,68,.2); border: 1px solid rgba(239,68,68,.4); }

.success-icon  { font-size: 42px; text-align: center; margin-bottom: 14px; }
.success-title { font-size: 20px; font-weight: 800; text-align: center; margin-bottom: 10px; }
.success-text  { font-size: 13px; opacity: .8; text-align: center; line-height: 1.6; margin-bottom: 24px; }

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

    <?php if ($viaGoogle): ?>
    <div class="google-badge">
        <svg width="16" height="16" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path fill="#EA4335" d="M24 9.5c3.14 0 5.95 1.08 8.17 2.86l6.1-6.1C34.46 3.09 29.48 1 24 1 14.82 1 7.07 6.48 3.73 14.22l7.1 5.52C12.5 13.59 17.8 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.1 24.5c0-1.64-.15-3.22-.42-4.74H24v8.97h12.42c-.54 2.9-2.18 5.36-4.65 7.01l7.13 5.54C43.16 37.37 46.1 31.4 46.1 24.5z"/>
            <path fill="#FBBC05" d="M10.83 28.26A14.58 14.58 0 0 1 9.5 24c0-1.48.25-2.91.7-4.26l-7.1-5.52A23.93 23.93 0 0 0 .5 24c0 3.87.93 7.53 2.57 10.75l7.76-6.49z"/>
            <path fill="#34A853" d="M24 46.5c5.48 0 10.08-1.82 13.44-4.93l-7.13-5.54c-1.98 1.32-4.51 2.1-6.31 2.1-6.2 0-11.5-4.09-13.17-9.74l-7.76 6.49C7.07 41.52 14.82 46.5 24 46.5z"/>
        </svg>
        Реєстрація через Google — оберіть курс і вкажіть телефон
    </div>
    <?php endif; ?>

    <form method="POST" action="register.php<?= $viaGoogle ? '?via=google' : '' ?>">

        <?php if ($viaGoogle): ?>
            <!-- Прихований маркер Google-реєстрації -->
            <input type="hidden" name="via_google" value="1">
        <?php endif; ?>

        <div class="row-2">
            <div class="field">
                <label>Ім'я</label>
                <input type="text" name="first_name"
                       placeholder="Оля"
                       value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                       <?= $viaGoogle ? 'readonly' : '' ?> required>
            </div>
            <div class="field">
                <label>Прізвище</label>
                <input type="text" name="last_name"
                       placeholder="Дяченко"
                       value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                       <?= $viaGoogle ? 'readonly' : '' ?> required>
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
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   <?= $viaGoogle ? 'readonly' : '' ?> required>
        </div>

        <?php if (!$viaGoogle): ?>
        <div class="field">
            <label>Пароль</label>
            <input type="password" name="password" placeholder="Мінімум 6 символів" required>
        </div>
        <?php endif; ?>

        <div class="divider">Обрати курс</div>

        <?php if (empty($courses)): ?>
            <div class="no-courses">
                ⚠️ Наразі немає доступних курсів. Зверніться до адміністратора.
            </div>
        <?php else: ?>
        <div class="field">
            <label>Курс, який вас цікавить</label>
            <select name="course_id" required>
                <option value="">— Оберіть курс —</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= htmlspecialchars($c['id']) ?>"
                    <?= (isset($_POST['course_id']) && $_POST['course_id'] === (string)$c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name_ua']) ?> — <?= htmlspecialchars($c['title']) ?>
                    (<?= htmlspecialchars($c['level']) ?>, <?= htmlspecialchars((string)$c['price']) ?> грн)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-submit" <?= empty($courses) ? 'disabled' : '' ?>>
            <?= $viaGoogle ? 'Завершити реєстрацію' : 'Подати заявку' ?>
        </button>

        <?php if ($message): ?>
            <div class="msg error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

    </form>

    <?php if (!$viaGoogle): ?>
    <div class="divider-or">або</div>
    <a href="oauth_google.php" class="btn-google">
        <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path fill="#EA4335" d="M24 9.5c3.14 0 5.95 1.08 8.17 2.86l6.1-6.1C34.46 3.09 29.48 1 24 1 14.82 1 7.07 6.48 3.73 14.22l7.1 5.52C12.5 13.59 17.8 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.1 24.5c0-1.64-.15-3.22-.42-4.74H24v8.97h12.42c-.54 2.9-2.18 5.36-4.65 7.01l7.13 5.54C43.16 37.37 46.1 31.4 46.1 24.5z"/>
            <path fill="#FBBC05" d="M10.83 28.26A14.58 14.58 0 0 1 9.5 24c0-1.48.25-2.91.7-4.26l-7.1-5.52A23.93 23.93 0 0 0 .5 24c0 3.87.93 7.53 2.57 10.75l7.76-6.49z"/>
            <path fill="#34A853" d="M24 46.5c5.48 0 10.08-1.82 13.44-4.93l-7.13-5.54c-1.98 1.32-4.51 2.1-6.31 2.1-6.2 0-11.5-4.09-13.17-9.74l-7.76 6.49C7.07 41.52 14.82 46.5 24 46.5z"/>
        </svg>
        Зареєструватись через Google
    </a>
    <?php endif; ?>

    <div class="links">
        <a href="index.php">← На головну</a>
        <a href="login.php">Вже є акаунт? Увійти</a>
    </div>

<?php endif; ?>

</div>
<script src="theme-switcher.js"></script>
</body>
</html>