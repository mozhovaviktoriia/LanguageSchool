<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

/* список мов */
$languages = $pdo->query("
    SELECT id, name_ua
    FROM languages
    ORDER BY name_ua
")->fetchAll(PDO::FETCH_ASSOC);

/* створення курсу */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $languageId  = (int)$_POST['language_id'];
    $level       = trim($_POST['level']);
    $price       = (float)$_POST['price'];
    $isActive    = (int)$_POST['is_active'];

    $stmt = $pdo->prepare("
        INSERT INTO courses (title, description, language_id, level, price, is_active)
        VALUES (:title, :description, :language_id, :level, :price, :is_active)
    ");

    $stmt->execute([
        'title'       => $title,
        'description' => $description,
        'language_id' => $languageId,
        'level'       => $level,
        'price'       => $price,
        'is_active'   => $isActive
    ]);

    header("Location: admin.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Додати курс — Адмін</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="theme.css" rel="stylesheet">
<style>

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
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

/* ── HEADER ── */
header {
    position: relative;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 36px;
    border-bottom: 1px solid var(--border);
    background: rgba(13,17,23,.9);
    backdrop-filter: blur(20px);
}

.logo { font-size: 20px; font-weight: 800; letter-spacing: -.5px; }
.logo span { color: var(--accent); }
.header-sub {
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 2px;
}

.back-btn {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
    text-decoration: none;
    padding: 7px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    transition: .2s;
}
.back-btn:hover {
    color: var(--text);
    border-color: var(--accent);
    background: rgba(99,102,241,.1);
}

/* ── LAYOUT ── */
.wrap {
    position: relative;
    z-index: 1;
    max-width: 620px;
    margin: 0 auto;
    padding: 48px 28px;
}

/* ── FORM PANEL ── */
.form-panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 32px;
}

.panel-title {
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 26px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.panel-title::before {
    content: '';
    width: 4px;
    height: 18px;
    background: var(--accent);
    border-radius: 4px;
    flex-shrink: 0;
}

/* ── FIELDS ── */
.field { margin-bottom: 16px; }

.field label {
    display: block;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.field input,
.field select,
.field textarea {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
    font-family: var(--font);
    font-size: 13px;
    padding: 10px 13px;
    outline: none;
    transition: border-color .2s;
    resize: none;
}

.field textarea {
    min-height: 90px;
    line-height: 1.6;
}

.field input:focus,
.field select:focus,
.field textarea:focus { border-color: var(--accent); }

.field input::placeholder,
.field textarea::placeholder { color: var(--muted); }

.field select option { background: #1f2937; }

.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ── LANGUAGE ROW ── */
.lang-row {
    display: flex;
    align-items: flex-end;
    gap: 10px;
}
.lang-row .field { flex: 1; margin-bottom: 0; }

.add-lang-btn {
    flex-shrink: 0;
    height: 40px;
    padding: 0 14px;
    background: rgba(99,102,241,.15);
    color: var(--accent);
    border: 1px solid rgba(99,102,241,.35);
    border-radius: 9px;
    font-family: var(--mono);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: .2s;
    margin-bottom: 16px;
}
.add-lang-btn:hover {
    background: rgba(99,102,241,.28);
    border-color: var(--accent);
}

/* ── LEVEL PILLS ── */
.level-pills {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.level-pill { display: flex; align-items: center; }
.level-pill input[type="radio"] { display: none; }
.level-pill span {
    font-family: var(--mono);
    font-size: 11px;
    padding: 5px 14px;
    border-radius: 7px;
    border: 1px solid var(--border);
    color: var(--muted);
    background: var(--surface);
    cursor: pointer;
    user-select: none;
    transition: .2s;
}
.level-pill input:checked + span {
    color: var(--accent2);
    border-color: var(--accent2);
    background: rgba(34,211,238,.1);
}
.level-pill span:hover { border-color: var(--accent); color: var(--text); }

/* ── SUBMIT ── */
.submit-btn {
    width: 100%;
    padding: 13px;
    margin-top: 8px;
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
.submit-btn:hover { background: #818cf8; transform: translateY(-1px); }

/* ── MODAL ── */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.6);
    align-items: center;
    justify-content: center;
    z-index: 100;
    backdrop-filter: blur(4px);
}
.modal.open { display: flex; }

.modal-box {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px;
    width: 320px;
}

.modal-title {
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-title::before {
    content: '';
    width: 4px;
    height: 16px;
    background: var(--accent2);
    border-radius: 4px;
    flex-shrink: 0;
}

.modal-field { margin-bottom: 16px; }
.modal-field label {
    display: block;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 7px;
}
.modal-field input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
    font-family: var(--font);
    font-size: 13px;
    padding: 10px 13px;
    outline: none;
    transition: border-color .2s;
}
.modal-field input:focus { border-color: var(--accent2); }
.modal-field input::placeholder { color: var(--muted); }

.modal-actions { display: flex; gap: 10px; margin-top: 4px; }

.modal-save {
    flex: 1;
    padding: 10px;
    background: var(--accent2);
    color: #07080f;
    border: none;
    border-radius: 9px;
    font-family: var(--font);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s;
}
.modal-save:hover { opacity: .85; }

.modal-cancel {
    flex: 1;
    padding: 10px;
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 9px;
    font-family: var(--mono);
    font-size: 11px;
    cursor: pointer;
    transition: .2s;
}
.modal-cancel:hover { color: var(--text); border-color: var(--muted); }

</style>
</head>
<body>

<header>
    <div>
        <div class="logo">Адмін<span>.</span>панель</div>
        <div class="header-sub">Створення курсу</div>
    </div>
    <button class="theme-toggle" title="Змінити тему">
        <span class="theme-toggle-icon">☀️</span>
    </button>
    <a class="back-btn" href="admin.php">← Назад</a>
</header>

<div class="wrap">
    <div class="form-panel">
        <div class="panel-title">Новий курс</div>

        <form method="POST">

            <div class="field">
                <label>Назва курсу</label>
                <input type="text" name="title" placeholder="Наприклад: Французька для початківців" required>
            </div>

            <div class="field">
                <label>Опис</label>
                <textarea name="description" placeholder="Короткий опис курсу..."></textarea>
            </div>

            <!-- Мова -->
            <div class="lang-row">
                <div class="field">
                    <label>Мова</label>
                    <select name="language_id" id="languageSelect" required>
                        <?php foreach($languages as $lang): ?>
                            <option value="<?= $lang['id'] ?>">
                                <?= htmlspecialchars($lang['name_ua']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="add-lang-btn" onclick="openModal()">+ Мова</button>
            </div>

            <!-- Рівень -->
            <div class="field">
                <label>Рівень</label>
                <div class="level-pills">
                    <?php foreach (['A1','A2','B1','B2','C1','C2'] as $i => $lvl): ?>
                    <label class="level-pill">
                        <input type="radio" name="level" value="<?= $lvl ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <span><?= $lvl ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row-2">
                <div class="field">
                    <label>Ціна (грн)</label>
                    <input type="number" step="0.01" name="price" placeholder="0.00">
                </div>
                <div class="field">
                    <label>Статус</label>
                    <select name="is_active">
                        <option value="1">Активний</option>
                        <option value="0">Прихований</option>
                    </select>
                </div>
            </div>

            <button class="submit-btn" type="submit">Створити курс</button>
        </form>
    </div>
</div>

<!-- МОДАЛКА: нова мова -->
<div class="modal" id="langModal">
    <div class="modal-box">
        <div class="modal-title">Нова мова</div>
        <div class="modal-field">
            <label>Назва мови</label>
            <input type="text" id="newLangName" placeholder="Французька">
        </div>
        <div class="modal-actions">
            <button class="modal-save" onclick="saveLanguage()">Зберегти</button>
            <button class="modal-cancel" onclick="closeModal()">Скасувати</button>
        </div>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('langModal').classList.add('open');
    document.getElementById('newLangName').focus();
}

function closeModal() {
    document.getElementById('langModal').classList.remove('open');
    document.getElementById('newLangName').value = '';
}

function saveLanguage() {
    const name = document.getElementById('newLangName').value.trim();
    if (!name) return;

    fetch('add_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'name=' + encodeURIComponent(name)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) { alert('Помилка: ' + data.error); return; }

        const select = document.getElementById('languageSelect');
        const option = document.createElement('option');
        option.value = data.id;
        option.textContent = data.name;
        option.selected = true;
        select.appendChild(option);

        closeModal();
    })
    .catch(() => alert('Сервер не відповідає'));
}

// Close modal on backdrop click
document.getElementById('langModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>