<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

$editUser = null;
$editTeacherLangs = [];

// Delete user by ID
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute(['id' => $_GET['delete']]);
    header("Location: admin_users.php");
    exit;
}

// Fetch user data and teacher languages
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $_GET['edit']]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editUser && $editUser['role'] === 'teacher') {
        $stmt2 = $pdo->prepare("SELECT language_id FROM teacher_languages WHERE teacher_id = :tid");
        $stmt2->execute(['tid' => $editUser['id']]);
        $editTeacherLangs = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Create new or update existing user
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id     = $_POST['id'] ?? null;
    $email  = $_POST['email'];
    $first  = $_POST['first_name'];
    $last   = $_POST['last_name'];
    $role   = $_POST['role'];
    $status = $_POST['status'];
    $selectedLangs = $_POST['languages'] ?? [];

    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    }

    if ($id) {
        // Update existing user
        if (!empty($_POST['password'])) {
            $sql = "UPDATE users
                    SET email=:email, first_name=:first, last_name=:last,
                        role=:role, status=:status, password_hash=:password
                    WHERE id=:id";
            $params = compact('email','first','last','role','status','password','id');
        } else {
            $sql = "UPDATE users
                    SET email=:email, first_name=:first, last_name=:last,
                        role=:role, status=:status
                    WHERE id=:id";
            $params = compact('email','first','last','role','status','id');
        }
        $pdo->prepare($sql)->execute($params);
        $userId = $id;
    } else {
        // Insert new user
        $sql = "INSERT INTO users (email, password_hash, first_name, last_name, role, status)
                VALUES (:email, :password, :first, :last, :role, :status)";
        $params = compact('email','password','first','last','role','status');
        $pdo->prepare($sql)->execute($params);
        $userId = $pdo->lastInsertId();
    }

    // Sync teacher's languages
    if ($role === 'teacher') {
        $pdo->prepare("DELETE FROM teacher_languages WHERE teacher_id = :tid")
            ->execute(['tid' => $userId]);

        if (!empty($selectedLangs)) {
            $ins = $pdo->prepare("INSERT INTO teacher_languages (teacher_id, language_id) VALUES (:tid, :lid)");
            foreach ($selectedLangs as $lid) {
                $ins->execute(['tid' => $userId, 'lid' => (int)$lid]);
            }
        }
    }

    header("Location: admin_users.php");
    exit;
}

// Fetch all users, languages, and teacher language mappings
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$languages = $pdo->query("SELECT id, name_ua FROM languages ORDER BY name_ua")->fetchAll(PDO::FETCH_ASSOC);

$teacherLangs = $pdo->query("
    SELECT u.id, STRING_AGG(l.name_ua, ', ') AS langs
    FROM users u
    JOIN teacher_languages tl ON tl.teacher_id = u.id
    JOIN languages l ON l.id = tl.language_id
    WHERE u.role = 'teacher'
    GROUP BY u.id
")->fetchAll(PDO::FETCH_KEY_PAIR);

$defaultRole = $_GET['role'] ?? 'student';

$roleLabels = [
    'student' => 'Студент',
    'teacher' => 'Викладач',
    'admin'   => 'Адміністратор',
];

$statusLabels = [
    'active'   => 'Активний',
    'inactive' => 'Неактивний',
    'banned'   => 'Заблокований',
];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Користувачі — Адмін</title>
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
.header-sub { font-family: var(--mono); font-size: 10px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; margin-top: 2px; }

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
.back-btn:hover { color: var(--text); border-color: var(--accent); background: rgba(99,102,241,.1); }

.wrap {
    position: relative;
    z-index: 1;
    max-width: 1150px;
    margin: 0 auto;
    padding: 36px 28px;
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 32px;
    align-items: start;
}

/* ── FORM ── */
.form-panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px;
    position: sticky;
    top: 24px;
}

.panel-title {
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 22px;
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

.field { margin-bottom: 14px; }

.field label {
    display: block;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.field input,
.field select {
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

.field input:focus,
.field select:focus { border-color: var(--accent); }
.field input::placeholder { color: var(--muted); }
.field select option { background: #1f2937; }

.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* ── LANGUAGE PILLS ── */
.langs-block {
    margin-bottom: 14px;
    display: none;
}

.langs-block .block-label {
    display: block;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.lang-pills { display: flex; flex-wrap: wrap; gap: 8px; }

.lang-pill { display: flex; align-items: center; cursor: pointer; }

.lang-pill input[type="checkbox"] { display: none; }

.lang-pill span {
    font-family: var(--mono);
    font-size: 11px;
    padding: 5px 12px;
    border-radius: 7px;
    border: 1px solid var(--border);
    color: var(--muted);
    background: var(--surface);
    transition: .2s;
    cursor: pointer;
    user-select: none;
}

.lang-pill input:checked + span {
    color: var(--accent2);
    border-color: var(--accent2);
    background: rgba(34,211,238,.1);
}

.lang-pill span:hover { border-color: var(--accent); color: var(--text); }

.submit-btn {
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
.submit-btn:hover { background: #818cf8; transform: translateY(-1px); }

/* ── TABLE ── */
.sec-head { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
.sec-title { font-size: 18px; font-weight: 800; }
.sec-count {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
    background: var(--border);
    padding: 3px 10px;
    border-radius: 99px;
}

.tbl-wrap { border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; }

table { width: 100%; border-collapse: collapse; font-size: 12px; }
thead tr { background: var(--surface); }
thead th {
    font-family: var(--mono);
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--muted);
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: rgba(99,102,241,.05); }
tbody td { padding: 11px 14px; vertical-align: middle; }

.nm { font-weight: 700; font-size: 13px; }
.em { font-family: var(--mono); font-size: 10px; color: var(--muted); }
.lc { font-family: var(--mono); font-size: 10px; color: var(--accent2); }

.role-badge {
    font-family: var(--mono);
    font-size: 10px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 6px;
}
.role-student { background: rgba(99,102,241,.15); color: #a5b4fc; }
.role-teacher { background: rgba(34,211,238,.12); color: #67e8f9; }
.role-admin   { background: rgba(245,158,11,.12);  color: #fcd34d; }

.status-dot {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: var(--mono);
    font-size: 10px;
}
.status-dot::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.s-active   { color: var(--success); }
.s-inactive { color: var(--warn); }
.s-banned   { color: var(--danger); }

.tbl-actions { display: flex; gap: 5px; }
.tbl-edit, .tbl-del {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-family: var(--mono);
    font-weight: 600;
    text-decoration: none;
    transition: .15s;
    border: 1px solid transparent;
}
.tbl-edit { background: rgba(59,130,246,.12); color: #93c5fd; }
.tbl-del  { background: rgba(239,68,68,.1);  color: #fca5a5; }
.tbl-edit:hover { background: rgba(59,130,246,.28); }
.tbl-del:hover  { background: rgba(239,68,68,.25); }

/* ═══════════════════════════════════════════════════════════ */
/* LIGHT THEME                                                 */
/* ═══════════════════════════════════════════════════════════ */

/* Header */
body.light-theme header {
    background: rgba(255,255,255,.97) !important;
    border-bottom-color: #e2e8f0 !important;
}
body.light-theme .logo { color: #0f172a; }
body.light-theme .logo span { color: #4f46e5; }
body.light-theme .header-sub { color: #94a3b8; }
body.light-theme .back-btn {
    color: #475569 !important;
    border-color: #cbd5e1 !important;
    background: transparent !important;
}
body.light-theme .back-btn:hover {
    color: #4f46e5 !important;
    border-color: #4f46e5 !important;
    background: rgba(79,70,229,.07) !important;
}

/* Form panel */
body.light-theme .form-panel {
    background: #ffffff !important;
    border-color: #e2e8f0 !important;
    box-shadow: 0 2px 16px rgba(0,0,0,.06);
}
body.light-theme .panel-title { color: #0f172a; }
body.light-theme .panel-title::before { background: #4f46e5; }

body.light-theme .field label { color: #64748b; }

body.light-theme .field input,
body.light-theme .field select {
    background: #f8fafc !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
}
body.light-theme .field input:focus,
body.light-theme .field select:focus {
    border-color: #4f46e5 !important;
    background: #fff !important;
    box-shadow: 0 0 0 3px rgba(79,70,229,.1) !important;
}
body.light-theme .field input::placeholder { color: #94a3b8 !important; }
body.light-theme .field select option { background: #fff; color: #0f172a; }

/* Language pills */
body.light-theme .block-label { color: #64748b; }
body.light-theme .lang-pill span {
    background: #f1f5f9 !important;
    border-color: #e2e8f0 !important;
    color: #475569 !important;
}
body.light-theme .lang-pill span:hover {
    border-color: #4f46e5 !important;
    color: #4f46e5 !important;
    background: rgba(79,70,229,.06) !important;
}
body.light-theme .lang-pill input:checked + span {
    color: #0891b2 !important;
    border-color: #0891b2 !important;
    background: rgba(8,145,178,.1) !important;
}

/* Submit button */
body.light-theme .submit-btn {
    background: linear-gradient(135deg, #4f46e5, #6366f1) !important;
    box-shadow: 0 4px 14px rgba(79,70,229,.3);
    color: #fff !important;
}
body.light-theme .submit-btn:hover {
    background: linear-gradient(135deg, #4338ca, #4f46e5) !important;
    box-shadow: 0 6px 20px rgba(79,70,229,.4);
}

/* Section heading */
body.light-theme .sec-title { color: #0f172a; }
body.light-theme .sec-count {
    background: #e2e8f0 !important;
    color: #64748b !important;
}

/* Table */
body.light-theme .tbl-wrap {
    border-color: #e2e8f0 !important;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
}
body.light-theme thead tr { background: #f8fafc !important; }
body.light-theme thead th {
    color: #64748b !important;
    border-bottom-color: #e2e8f0 !important;
}
body.light-theme tbody tr { border-bottom-color: #f1f5f9 !important; }
body.light-theme tbody tr:hover { background: rgba(79,70,229,.04) !important; }
body.light-theme tbody td { background: #ffffff; }

body.light-theme .nm { color: #0f172a !important; }
body.light-theme .em { color: #64748b !important; }
body.light-theme .lc { color: #0891b2 !important; }

/* Role badges */
body.light-theme .role-student { background: rgba(79,70,229,.1)  !important; color: #4f46e5 !important; }
body.light-theme .role-teacher { background: rgba(8,145,178,.1)  !important; color: #0891b2 !important; }
body.light-theme .role-admin   { background: rgba(217,119,6,.12) !important; color: #b45309 !important; }

/* Status dots */
body.light-theme .s-active   { color: #16a34a !important; }
body.light-theme .s-inactive { color: #d97706 !important; }
body.light-theme .s-banned   { color: #dc2626 !important; }

/* Action buttons */
body.light-theme .tbl-edit {
    background: rgba(59,130,246,.08) !important;
    color: #2563eb !important;
    border-color: rgba(59,130,246,.2) !important;
}
body.light-theme .tbl-edit:hover {
    background: rgba(59,130,246,.16) !important;
    border-color: rgba(59,130,246,.4) !important;
}
body.light-theme .tbl-del {
    background: rgba(220,38,38,.07) !important;
    color: #dc2626 !important;
    border-color: rgba(220,38,38,.15) !important;
}
body.light-theme .tbl-del:hover {
    background: rgba(220,38,38,.14) !important;
    border-color: rgba(220,38,38,.35) !important;
}
</style>
</head>
<body>

<header>
    <div>
        <div class="logo">Адмін<span>.</span>панель</div>
        <div class="header-sub">Керування користувачами</div>
    </div>
    <button class="theme-toggle" title="Змінити тему">
        <span class="theme-toggle-icon">☀️</span>
    </button>
    <a class="back-btn" href="admin.php">← Назад</a>
</header>

<div class="wrap">

    <!-- Form panel: create/edit users -->
    <div class="form-panel">
        <div class="panel-title">
            <?= $editUser ? 'Редагувати користувача' : 'Новий користувач' ?>
        </div>

        <form method="POST">
            <input type="hidden" name="id" value="<?= htmlspecialchars($editUser['id'] ?? '') ?>">

            <div class="row-2">
                <div class="field">
                    <label>Ім'я</label>
                    <input type="text" name="first_name" placeholder="Оля"
                           value="<?= htmlspecialchars($editUser['first_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label>Прізвище</label>
                    <input type="text" name="last_name" placeholder="Дяченко"
                           value="<?= htmlspecialchars($editUser['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="field">
                <label>Email</label>
                <input type="email" name="email" placeholder="user@school.com"
                       value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" required>
            </div>

            <div class="field">
                <label>Пароль <?= $editUser ? '(залиш пустим щоб не змінювати)' : '' ?></label>
                <input type="password" name="password" placeholder="••••••••">
            </div>

            <div class="row-2">
                <div class="field">
                    <label>Роль</label>
                    <select name="role" id="roleSelect">
                        <?php
                        $curRole = $editUser['role'] ?? $defaultRole;
                        foreach (['student'=>'Студент','teacher'=>'Викладач','admin'=>'Адміністратор'] as $val => $lbl):
                        ?>
                        <option value="<?= $val ?>" <?= $curRole === $val ? 'selected' : '' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Статус</label>
                    <select name="status">
                        <?php
                        $curStatus = $editUser['status'] ?? 'active';
                        foreach (['active'=>'Активний','inactive'=>'Неактивний','banned'=>'Заблокований'] as $val => $lbl):
                        ?>
                        <option value="<?= $val ?>" <?= $curStatus === $val ? 'selected' : '' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Languages: shows only for teachers -->
            <div class="langs-block" id="langsBlock">
                <span class="block-label">Мови викладання</span>
                <div class="lang-pills">
                    <?php foreach ($languages as $l): ?>
                    <label class="lang-pill">
                        <input type="checkbox" name="languages[]"
                               value="<?= $l['id'] ?>"
                               <?= in_array($l['id'], $editTeacherLangs) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($l['name_ua']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button class="submit-btn" type="submit">
                <?= $editUser ? 'Оновити користувача' : 'Створити користувача' ?>
            </button>
        </form>
    </div>

    <!-- Table: list all users -->
    <div class="list-panel">
        <div class="sec-head">
            <div class="sec-title">Всі користувачі</div>
            <span class="sec-count"><?= count($users) ?></span>
        </div>

        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ім'я</th>
                        <th>Email</th>
                        <th>Роль</th>
                        <th>Мови</th>
                        <th>Статус</th>
                        <th>Дії</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="nm"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
                    <td class="em"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="role-badge role-<?= $u['role'] ?>">
                            <?= $roleLabels[$u['role']] ?? $u['role'] ?>
                        </span>
                    </td>
                    <td class="lc">
                        <?= $u['role'] === 'teacher'
                            ? htmlspecialchars($teacherLangs[$u['id']] ?? '—')
                            : '—' ?>
                    </td>
                    <td>
                        <span class="status-dot s-<?= $u['status'] ?>">
                            <?= $statusLabels[$u['status']] ?? $u['status'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="tbl-actions">
                            <a class="tbl-edit" href="?edit=<?= $u['id'] ?>">Редагувати</a>
                            <a class="tbl-del"  href="?delete=<?= $u['id'] ?>"
                               onclick="return confirm('Видалити?')">Видалити</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const roleSelect = document.getElementById('roleSelect');
const langsBlock = document.getElementById('langsBlock');

function toggleLangs() {
    langsBlock.style.display = roleSelect.value === 'teacher' ? 'block' : 'none';
}

roleSelect.addEventListener('change', toggleLangs);
toggleLangs();
</script>
<script src="theme-switcher.js"></script>
</body>
</html>