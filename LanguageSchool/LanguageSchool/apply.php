<?php
require 'config.php';

// Функція валідації телефону
function validatePhone(string $phone): bool {
    // Дозволяє: +380XXXXXXXXX (12 цифр після +) або 0XXXXXXXXX (10 цифр)
    return (bool) preg_match('/^\+?3?8?(0\d{9})$/', preg_replace('/[\s\-()]/', '', $phone));
}

// Функція валідації email
function validateEmail(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) 
        && strlen($email) <= 100
        && preg_match('/\.[a-zA-Z]{2,6}$/', $email);
}
// Load active courses for dropdown
$courses = $pdo->query("
    SELECT c.id, c.title, c.level, c.price, l.name_ua
    FROM courses c
    JOIN languages l ON c.language_id = l.id
    WHERE c.is_active = TRUE
    ORDER BY l.name_ua, c.title
")->fetchAll(PDO::FETCH_ASSOC);

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']      ?? '');
    $phone    = trim($_POST['phone']     ?? '');
    $email    = trim($_POST['email']     ?? '');
    $courseId = trim($_POST['course_id'] ?? '');

   if (!$name || !$phone || !$email || !$courseId) {
    $error = 'Будь ласка, заповніть усі поля.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\.[a-zA-Z]{2,6}$/', $email) || strlen($email) > 100) {
    $error = 'Невірний формат email. Приклад: name@domain.ua';
} elseif (!preg_match('/^\+?3?8?(0\d{9})$/', preg_replace('/[\s\-()]/', '', $phone))) {
    $error = 'Невірний номер телефону. Приклад: +380961234567 або 0961234567';
} else {
    try {
        $pdo->prepare("
            INSERT INTO applications (name, phone, email, course_id)
            VALUES (:name, :phone, :email, :course_id)
        ")->execute([
            'name'      => $name,
            'phone'     => $phone,
            'email'     => $email,
            'course_id' => $courseId,
        ]);
        $success = true;
    } catch (PDOException $e) {
        $error = 'Щось пішло не так, спробуйте ще раз.';
    }
}
}

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Записатися на курс | LinguaSchool</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Syne', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
}

/* Ambient glow */
body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 60% 50% at 20% 20%, rgba(99,102,241,.18) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 80% 80%, rgba(34,211,238,.12) 0%, transparent 55%);
}

.card {
    position: relative; z-index: 1;
    background: rgba(255,255,255,.06);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 24px;
    padding: 44px 48px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 24px 80px rgba(0,0,0,.4);
    color: #f1f5f9;
}

/* Logo */
.logo {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -.5px;
    margin-bottom: 4px;
}
.logo span { color: #67e8f9; }

.tagline {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    color: rgba(255,255,255,.45);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 36px;
}

/* Fields */
.field { margin-bottom: 18px; }

.field label {
    display: block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 10px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(255,255,255,.55);
    margin-bottom: 8px;
}

.field input,
.field select {
    width: 100%;
    padding: 13px 16px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 12px;
    color: #f1f5f9;
    font-family: 'Syne', sans-serif;
    font-size: 14px;
    outline: none;
    transition: border-color .2s, background .2s;
    appearance: none;
    -webkit-appearance: none;
}

.field input::placeholder { color: rgba(255,255,255,.3); }

.field input:focus,
.field select:focus {
    border-color: rgba(99,102,241,.7);
    background: rgba(99,102,241,.08);
}

.field select option {
    background: #1e1b4b;
    color: #f1f5f9;
}

/* Select arrow */
.select-wrap { position: relative; }
.select-wrap::after {
    content: '▾';
    position: absolute;
    right: 14px; top: 50%;
    transform: translateY(-50%);
    color: rgba(255,255,255,.4);
    pointer-events: none;
    font-size: 12px;
}

/* Submit button */
.btn {
    width: 100%;
    margin-top: 8px;
    padding: 15px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    border: none;
    border-radius: 14px;
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
    letter-spacing: -.2px;
}
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(99,102,241,.45);
}
.btn:active { transform: translateY(0); }

/* Messages */
.error-msg {
    margin-top: 16px;
    padding: 12px 16px;
    background: rgba(239,68,68,.15);
    border: 1px solid rgba(239,68,68,.35);
    border-radius: 10px;
    font-size: 13px;
    color: #fca5a5;
    text-align: center;
}

/* Back link */
.back {
    display: block;
    text-align: center;
    margin-top: 22px;
    color: rgba(255,255,255,.4);
    text-decoration: none;
    font-size: 13px;
    transition: color .2s;
}
.back:hover { color: rgba(255,255,255,.75); }

/* ── SUCCESS SCREEN ── */
.success-wrap { text-align: center; }

.success-circle {
    width: 72px; height: 72px;
    margin: 0 auto 20px;
    background: rgba(34,211,153,.15);
    border: 2px solid rgba(34,211,153,.4);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px;
}

.success-title {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 12px;
}

.success-text {
    font-size: 14px;
    color: rgba(255,255,255,.6);
    line-height: 1.65;
    margin-bottom: 28px;
}

.success-card {
    background: rgba(99,102,241,.1);
    border: 1px solid rgba(99,102,241,.25);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 28px;
    text-align: left;
}
.success-card-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    padding: 4px 0;
}
.success-card-row span:first-child {
    color: rgba(255,255,255,.45);
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .8px;
}

.btn-outline {
    display: block;
    padding: 13px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 14px;
    color: rgba(255,255,255,.75);
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    transition: .2s;
}
.btn-outline:hover {
    border-color: rgba(255,255,255,.4);
    color: #fff;
    background: rgba(255,255,255,.05);
}

/* Validation styles */
.field input.valid {
    border-color: rgba(52,211,153,.6);
    background: rgba(52,211,153,.1);
}

.field input.invalid {
    border-color: rgba(239,68,68,.6);
    background: rgba(239,68,68,.1);
}

.field-hint {
    display: block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    color: #ff5252;
    margin-top: 6px;
    min-height: 16px;
}
</style>
</head>
<body>

<div class="card">

<?php if ($success):
    // Find selected course info for the success screen
    $selected = array_filter($courses, fn($c) => $c['id'] === ($_POST['course_id'] ?? ''));
    $selected = reset($selected);
?>
    <div class="success-wrap">
        <div class="success-circle">✓</div>
        <div class="success-title">Заявку прийнято!</div>
        <div class="success-text">
            Дякуємо! Ми отримали вашу заявку і зв'яжемося з вами найближчим часом.
        </div>

        <div class="success-card">
            <div class="success-card-row">
                <span>Ім'я</span>
                <span><?= htmlspecialchars($_POST['name']) ?></span>
            </div>
            <div class="success-card-row">
                <span>Телефон</span>
                <span><?= htmlspecialchars($_POST['phone']) ?></span>
            </div>
            <div class="success-card-row">
                <span>Email</span>
                <span><?= htmlspecialchars($_POST['email']) ?></span>
            </div>
            <?php if ($selected): ?>
            <div class="success-card-row">
                <span>Курс</span>
                <span><?= htmlspecialchars($selected['name_ua'] . ' — ' . $selected['title']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <a class="btn-outline" href="index.php">← На головну</a>
    </div>

<?php else: ?>

    <div class="logo">Lingua<span>School</span></div>
    <div class="tagline">Залишити заявку на курс</div>

    <form method="POST" novalidate>

        <div class="field">
            <label>Ваше ім'я</label>
            <input
                type="text"
                name="name"
                placeholder="Наприклад: Оля Дяченко"
                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                required
                autocomplete="name"
            >
        </div>

        <div class="field">
            <label>Номер телефону</label>
            <input
                type="tel"
                id="phoneInput"
                name="phone"
                placeholder="+380 XX XXX XX XX"
                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                required
                autocomplete="tel"
            >
            <div class="field-hint" id="phoneHint"></div>
        </div>

        <div class="field">
            <label>Email</label>
            <input
                type="email"
                id="emailInput"
                name="email"
                placeholder="example@email.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
                autocomplete="email"
            >
            <div class="field-hint" id="emailHint"></div>
        </div>

        <div class="field">
            <label>Оберіть курс</label>
            <div class="select-wrap">
                <select name="course_id" required>
                    <option value="">— Оберіть курс —</option>
                    <?php foreach ($courses as $c): ?>
                    <option
                        value="<?= htmlspecialchars($c['id']) ?>"
                       <?= (isset($_GET['course_id']) && $_GET['course_id'] == $c['id']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($c['name_ua']) ?> —
                        <?= htmlspecialchars($c['title']) ?>
                        (<?= htmlspecialchars($c['level']) ?>, <?= (int)$c['price'] ?> грн)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn">Надіслати заявку →</button>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

    </form>

    <a class="back" href="index.php">← Повернутися на головну</a>

<?php endif; ?>

</div>
<script>
function validatePhoneJS(val) {
    return /^\+?3?8?(0\d{9})$/.test(val.replace(/[\s\-()]/g, ''));
}
function validateEmailJS(val) {
    return /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,6}$/.test(val) && val.length <= 100;
}

// Перевіряємо наявність елементів перед додаванням обробників
const phoneInput = document.getElementById('phoneInput');
const emailInput = document.getElementById('emailInput');

if (phoneInput) {
    phoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^\d\+\s\-\(\)]/g, '');
    });
    phoneInput.addEventListener('blur', function() {
        const hint = document.getElementById('phoneHint');
        if (!this.value) { this.classList.remove('valid','invalid'); hint.textContent = ''; return; }
        if (validatePhoneJS(this.value)) {
            this.classList.add('valid'); this.classList.remove('invalid'); hint.textContent = '';
        } else {
            this.classList.add('invalid'); this.classList.remove('valid');
            hint.textContent = 'некоректно введений номер';
        }
    });
}

if (emailInput) {
    emailInput.addEventListener('blur', function() {
        const hint = document.getElementById('emailHint');
        if (!this.value) { this.classList.remove('valid','invalid'); hint.textContent = ''; return; }
        if (validateEmailJS(this.value)) {
            this.classList.add('valid'); this.classList.remove('invalid'); hint.textContent = '';
        } else {
            this.classList.add('invalid'); this.classList.remove('valid');
            hint.textContent = 'некоректно введено пошту';
        }
    });
}
</script>
</body>
</html>