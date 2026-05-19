<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

if (!isset($_GET['id'])) {
    die("Курс не знайдено");
}

/* ❗ FIX: UUID не можна перетворювати в int */
$courseId = $_GET['id'];

// Get language list for dropdown
$languages = $pdo->query("
    SELECT id, name_ua
    FROM languages
    ORDER BY name_ua
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch course data by ID
$stmt = $pdo->prepare("
    SELECT id, title, description, language_id, level, price, is_active
    FROM courses
    WHERE id = :id
");
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Курс не знайдено");
}

$message = "";

// Save course changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $languageId  = (int)$_POST['language_id'];
    $level       = trim($_POST['level']);
    $price       = (float)$_POST['price'];
    $isActive    = (int)$_POST['is_active'];

    $update = $pdo->prepare("
        UPDATE courses
        SET title = :title,
            description = :description,
            language_id = :language_id,
            level = :level,
            price = :price,
            is_active = :is_active
        WHERE id = :id
    ");

    $update->execute([
        'title'       => $title,
        'description' => $description,
        'language_id' => $languageId,
        'level'       => $level,
        'price'       => $price,
        'is_active'   => $isActive,
        'id'          => $courseId
    ]);

    $message = "Курс успішно оновлено";

    /* оновити дані після збереження */
    $stmt->execute(['id' => $courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Редагування курсу</title>

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

input, select, textarea {
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

input:focus, select:focus, textarea:focus {
    border-color: var(--accent);
}

input::placeholder, textarea::placeholder {
    color: var(--muted);
}

select option {
    background: #1f2937;
    color: var(--text);
}

textarea {
    min-height: 100px;
    resize: vertical;
    font-family: var(--font);
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
    <h2>Редагування курсу</h2>

    <?php if($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">

        <div class="field">
            <label>Назва курсу</label>
            <input type="text"
                   name="title"
                   value="<?= htmlspecialchars($course['title']) ?>"
                   placeholder="Назва курсу"
                   required>
        </div>

        <div class="field">
            <label>Опис курсу</label>
            <textarea name="description"
                      placeholder="Опис курсу"><?= htmlspecialchars($course['description']) ?></textarea>
        </div>

        <div class="field">
            <label>Мова курсу</label>
            <select name="language_id">
                <?php foreach($languages as $lang): ?>
                    <option value="<?= $lang['id'] ?>"
                        <?= $lang['id'] == $course['language_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lang['name_ua']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Рівень курсу</label>
            <select name="level">
                <option value="A1" <?= $course['level']=='A1'?'selected':'' ?>>A1</option>
                <option value="A2" <?= $course['level']=='A2'?'selected':'' ?>>A2</option>
                <option value="B1" <?= $course['level']=='B1'?'selected':'' ?>>B1</option>
                <option value="B2" <?= $course['level']=='B2'?'selected':'' ?>>B2</option>
                <option value="C1" <?= $course['level']=='C1'?'selected':'' ?>>C1</option>
                <option value="C2" <?= $course['level']=='C2'?'selected':'' ?>>C2</option>
            </select>
        </div>

        <div class="field">
            <label>Ціна</label>
            <input type="number"
                   step="0.01"
                   name="price"
                   value="<?= htmlspecialchars($course['price']) ?>"
                   placeholder="Ціна">
        </div>

        <div class="field">
            <label>Статус курсу</label>
            <select name="is_active">
                <option value="1" <?= $course['is_active'] ? 'selected' : '' ?>>Активний</option>
                <option value="0" <?= !$course['is_active'] ? 'selected' : '' ?>>Прихований</option>
            </select>
        </div>

        <button type="submit">Зберегти зміни</button>
    </form>

    <a class="back" href="admin.php">← Назад до адмін панелі</a>
</div>

</body>
<script src="theme-switcher.js"></script>
</html>