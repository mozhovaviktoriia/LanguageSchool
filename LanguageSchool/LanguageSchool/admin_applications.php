<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Handle approve / reject / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['app_id'])) {
    $appId  = $_POST['app_id'];
    $action = $_POST['action'];
    $source = $_POST['source'] ?? 'application';

    if ($source === 'enrollment') {
        // Заявки з register.php — enrollments + users
        $realId = preg_replace('/^enr_/', '', $appId);

        if ($action === 'approve') {
            // Активуємо enrollment
            $pdo->prepare("UPDATE enrollments SET status = 'active' WHERE id = :id")
                ->execute(['id' => $realId]);
            // Знаходимо student_id і активуємо юзера
            $enr = $pdo->prepare("SELECT student_id FROM enrollments WHERE id = :id");
            $enr->execute(['id' => $realId]);
            $studentId = $enr->fetchColumn();
            if ($studentId) {
                $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")
                    ->execute(['id' => $studentId]);
            }
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Студента активовано! Тепер він може увійти в систему.'];

        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE enrollments SET status = 'rejected' WHERE id = :id")
                ->execute(['id' => $realId]);
            $_SESSION['flash'] = ['type' => 'warn', 'text' => 'Заявку відхилено.'];

        } elseif ($action === 'delete') {
            $enr = $pdo->prepare("SELECT student_id FROM enrollments WHERE id = :id");
            $enr->execute(['id' => $realId]);
            $studentId = $enr->fetchColumn();
            $pdo->prepare("DELETE FROM enrollments WHERE id = :id")->execute(['id' => $realId]);
            if ($studentId) {
                $otherEnr = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = :sid");
                $otherEnr->execute(['sid' => $studentId]);
                if ($otherEnr->fetchColumn() == 0) {
                    $pdo->prepare("DELETE FROM users WHERE id = :id AND status = 'inactive'")
                        ->execute(['id' => $studentId]);
                }
            }
            $_SESSION['flash'] = ['type' => 'info', 'text' => 'Заявку видалено.'];
        }

    } else {
        // Таблиця applications (форма заявки без реєстрації)
        $realId = preg_replace('/^app_/', '', $appId);

        if ($action === 'approve') {
            $pdo->prepare("UPDATE applications SET status = 'approved' WHERE id = :id")
                ->execute(['id' => $realId]);

            // Отримуємо дані заявки
            $app = $pdo->prepare("SELECT * FROM applications WHERE id = :id");
            $app->execute(['id' => $realId]);
            $appData = $app->fetch(PDO::FETCH_ASSOC);

            if ($appData && !empty($appData['email'])) {
                // Перевіряємо чи юзер вже існує
                $exists = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $exists->execute(['email' => $appData['email']]);
                $existingUserId = $exists->fetchColumn();

                if (!$existingUserId) {
                    // Розбиваємо ім'я на ім'я + прізвище
                    $nameParts = explode(' ', trim($appData['name']), 2);
                    $firstName = $nameParts[0] ?? $appData['name'];
                    $lastName  = $nameParts[1] ?? '';

                    $tempPassword = bin2hex(random_bytes(6));
                    $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

                    $insertUser = $pdo->prepare("
                        INSERT INTO users (email, password_hash, first_name, last_name, phone, role, status)
                        VALUES (:email, :ph, :first, :last, :phone, 'student', 'active')
                        RETURNING id
                    ");
                    $insertUser->execute([
                        'email' => $appData['email'],
                        'ph'    => $passwordHash,
                        'first' => $firstName,
                        'last'  => $lastName,
                        'phone' => $appData['phone'] ?? '',
                    ]);

                    $newUserId = $insertUser->fetchColumn();

                    // Записуємо студента на курс
                    if (!empty($appData['course_id'])) {
                        $pdo->prepare("
                            INSERT INTO enrollments (student_id, course_id, status)
                            VALUES (:sid, :cid, 'active')
                        ")->execute([
                            'sid' => $newUserId,
                            'cid' => $appData['course_id'],
                        ]);
                    }

                    // Зберігаємо тимчасові дані для показу листа
                    $_SESSION['temp_creds'] = [
                        'email'     => $appData['email'],
                        'firstName' => $firstName,
                        'lastName'  => $lastName,
                        'password'  => $tempPassword,
                    ];

                    $_SESSION['flash'] = ['type' => 'success', 'text' => 'Заявку підтверджено. Студента створено в системі!'];
                } else {
                    // Юзер вже існує — просто записуємо на курс якщо треба
                    if (!empty($appData['course_id'])) {
                        $alreadyEnrolled = $pdo->prepare("
                            SELECT COUNT(*) FROM enrollments
                            WHERE student_id = :sid AND course_id = :cid
                        ");
                        $alreadyEnrolled->execute([
                            'sid' => $existingUserId,
                            'cid' => $appData['course_id'],
                        ]);
                        if ($alreadyEnrolled->fetchColumn() == 0) {
                            $pdo->prepare("
                                INSERT INTO enrollments (student_id, course_id, status)
                                VALUES (:sid, :cid, 'active')
                            ")->execute([
                                'sid' => $existingUserId,
                                'cid' => $appData['course_id'],
                            ]);
                        }
                    }
                    $_SESSION['flash'] = ['type' => 'success', 'text' => 'Заявку підтверджено. Користувач вже існує в системі.'];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'warn', 'text' => 'Заявку підтверджено, але email відсутній — користувача не створено.'];
            }

        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE applications SET status = 'rejected' WHERE id = :id")
                ->execute(['id' => $realId]);
            $_SESSION['flash'] = ['type' => 'warn', 'text' => 'Заявку відхилено.'];

        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM applications WHERE id = :id")
                ->execute(['id' => $realId]);
            $_SESSION['flash'] = ['type' => 'info', 'text' => 'Заявку видалено.'];
        }
    }

    header("Location: admin_applications.php");
    exit;
}

// Завантажуємо ВСІ заявки — з обох таблиць
$apps = $pdo->query("
    SELECT * FROM (
        SELECT
            'app_' || a.id::text   AS id,
            a.name                 AS display_name,
            a.email,
            a.phone,
            a.status,
            a.created_at,
            c.title                AS course_title,
            c.level,
            c.price,
            l.name_ua              AS language,
            'application'          AS source
        FROM applications a
        LEFT JOIN courses   c ON a.course_id   = c.id
        LEFT JOIN languages l ON c.language_id = l.id

        UNION ALL

        SELECT
            'enr_' || e.id::text   AS id,
            u.first_name || ' ' || u.last_name AS display_name,
            u.email,
            u.phone,
            'new'                  AS status,
            e.enrolled_at          AS created_at,
            c.title                AS course_title,
            c.level,
            c.price,
            l.name_ua              AS language,
            'enrollment'           AS source
        FROM enrollments e
        JOIN users     u ON e.student_id   = u.id
        JOIN courses   c ON e.course_id    = c.id
        JOIN languages l ON c.language_id  = l.id
        WHERE e.status = 'pending' AND u.status = 'inactive'
    ) AS combined
    ORDER BY
        CASE status WHEN 'new' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Заявки на курси — Адмін</title>
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
    position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(ellipse 70% 50% at 15% 10%, rgba(99,102,241,.09) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 85% 85%, rgba(34,211,238,.06) 0%, transparent 55%);
}

header {
    position: relative; z-index: 10;
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 36px;
    border-bottom: 1px solid var(--border);
    background: rgba(7,8,15,.92);
    backdrop-filter: blur(20px);
}
.logo { font-size: 18px; font-weight: 800; letter-spacing: -.5px; }
.logo span { color: var(--teal); }
.header-sub {
    font-family: var(--mono); font-size: 10px;
    color: var(--muted); letter-spacing: 2px; text-transform: uppercase; margin-top: 2px;
}
.header-nav { display: flex; align-items: center; gap: 10px; }
.nav-link {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
    text-decoration: none; padding: 7px 14px;
    border: 1px solid var(--border); border-radius: 8px; transition: .2s;
}
.nav-link:hover { color: var(--text); border-color: var(--accent); background: rgba(99,102,241,.1); }

.wrap {
    position: relative; z-index: 1;
    max-width: 1050px; margin: 0 auto;
    padding: 36px 28px;
}

/* ── STATS ROW ── */
.stats { display: flex; gap: 14px; margin-bottom: 32px; }
.stat-card {
    flex: 1; padding: 18px 20px;
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius);
}
.stat-val { font-size: 28px; font-weight: 800; line-height: 1; margin-bottom: 4px; }
.stat-label { font-family: var(--mono); font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
.stat-new  .stat-val { color: var(--accent); }
.stat-ok   .stat-val { color: var(--green); }
.stat-rej  .stat-val { color: var(--muted); }

/* ── FLASH ── */
.flash {
    padding: 13px 18px; border-radius: 10px;
    font-size: 13px; font-weight: 600;
    margin-bottom: 24px;
    display: flex; align-items: center; gap: 10px;
}
.flash.success { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: var(--green); }
.flash.warn    { background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.3); color: var(--amber); }
.flash.info    { background: rgba(99,102,241,.10); border: 1px solid rgba(99,102,241,.3); color: #a5b4fc; }

/* ── SECTION HEADER ── */
.sec-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
.sec-title { font-size: 17px; font-weight: 800; }
.pill {
    font-family: var(--mono); font-size: 10px;
    padding: 3px 10px; border-radius: 99px;
    background: var(--border); color: var(--muted);
}
.pill.hot { background: rgba(239,68,68,.15); color: var(--red); }

/* ── CARDS GRID ── */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
    margin-bottom: 48px;
}

.app-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    display: flex; flex-direction: column; gap: 14px;
    transition: border-color .2s, box-shadow .2s;
}
.app-card.is-new {
    border-color: rgba(99,102,241,.3);
    box-shadow: 0 0 0 1px rgba(99,102,241,.1);
}
.app-card:hover { border-color: rgba(99,102,241,.45); box-shadow: 0 6px 20px rgba(99,102,241,.1); }

/* Бейдж джерела */
.source-badge {
    font-family: var(--mono); font-size: 9px; font-weight: 600;
    padding: 2px 7px; border-radius: 5px; display: inline-block; margin-bottom: 6px;
}
.source-enrollment { background: rgba(34,211,238,.1); color: var(--teal); border: 1px solid rgba(34,211,238,.2); }
.source-application { background: rgba(99,102,241,.1); color: #a5b4fc; border: 1px solid rgba(99,102,241,.2); }

.card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.applicant-name { font-size: 16px; font-weight: 800; }
.applicant-sub {
    font-family: var(--mono); font-size: 11px;
    color: var(--teal); margin-top: 3px;
}

.status-badge {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap; flex-shrink: 0;
}
.badge-new      { background: rgba(99,102,241,.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,.3); }
.badge-approved { background: rgba(34,197,94,.12);  color: var(--green); border: 1px solid rgba(34,197,94,.25); }
.badge-rejected { background: rgba(100,116,139,.1); color: var(--muted); border: 1px solid var(--border); }

.course-box {
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
}
.course-lang {
    font-family: var(--mono); font-size: 10px;
    color: var(--teal); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;
}
.course-title { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.course-meta { display: flex; gap: 8px; }
.tag {
    font-family: var(--mono); font-size: 10px;
    padding: 3px 8px; border-radius: 6px; border: 1px solid transparent;
}
.tag-level { color: var(--amber); background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.2); }
.tag-price { color: var(--green); background: rgba(34,197,94,.08);  border-color: rgba(34,197,94,.2); }

.card-date { font-family: var(--mono); font-size: 10px; color: var(--muted); }

.card-actions { display: flex; gap: 8px; }
.btn {
    height: 36px; border-radius: 9px;
    font-family: var(--font); font-size: 12px; font-weight: 700;
    cursor: pointer; border: none; transition: .15s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
    padding: 0 14px;
}
.btn-approve {
    flex: 1;
    background: rgba(34,197,94,.15); color: var(--green);
    border: 1px solid rgba(34,197,94,.3);
}
.btn-approve:hover { background: rgba(34,197,94,.28); }
.btn-reject {
    background: rgba(100,116,139,.12); color: var(--muted);
    border: 1px solid var(--border);
}
.btn-reject:hover { color: var(--text); background: rgba(255,255,255,.07); }
.btn-delete {
    background: rgba(239,68,68,.08); color: var(--red);
    border: 1px solid rgba(239,68,68,.2);
    padding: 0 10px;
}
.btn-delete:hover { background: rgba(239,68,68,.18); }

.empty {
    text-align: center; padding: 56px 40px;
    background: var(--card); border: 1px dashed var(--border);
    border-radius: var(--radius); margin-bottom: 40px;
}
.empty-icon { font-size: 36px; margin-bottom: 12px; opacity: .45; }
.empty-title { font-size: 14px; font-weight: 700; color: var(--muted); }

/* ── CREDENTIALS MODAL ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); backdrop-filter: blur(8px);
    z-index: 200; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 18px; padding: 32px;
    max-width: 520px; width: 90%;
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 20px;
}
.modal-title-text { font-size: 17px; font-weight: 800; }
.modal-close {
    background: none; border: none; color: var(--muted);
    font-size: 20px; cursor: pointer; line-height: 1;
}
.modal-label {
    font-family: var(--mono); font-size: 11px; color: var(--muted);
    margin-bottom: 6px;
}
.modal-value {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 14px;
    font-family: var(--mono); font-size: 13px; color: var(--teal);
    margin-bottom: 16px;
}
.modal-textarea {
    width: 100%; height: 220px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; padding: 14px;
    font-family: var(--mono); font-size: 12px; color: var(--text);
    resize: none; line-height: 1.6; outline: none;
}
.modal-actions-row { display: flex; gap: 10px; margin-top: 16px; }
.modal-btn {
    flex: 1; padding: 10px; border-radius: 10px;
    font-family: var(--mono); font-size: 12px; font-weight: 600;
    cursor: pointer; text-align: center; text-decoration: none;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: .15s;
}
.modal-btn-copy {
    background: rgba(99,102,241,.15); color: #a5b4fc;
    border: 1px solid rgba(99,102,241,.3);
}
.modal-btn-copy:hover { background: rgba(99,102,241,.28); }
.modal-btn-gmail {
    background: rgba(34,211,238,.1); color: var(--teal);
    border: 1px solid rgba(34,211,238,.3);
}
.modal-btn-gmail:hover { background: rgba(34,211,238,.2); }
.modal-hint {
    margin-top: 10px; font-family: var(--mono); font-size: 10px;
    color: var(--muted); text-align: center;
}
</style>
</head>
<body>

<?php if (isset($_SESSION['temp_creds'])): ?>
<?php
    $c = $_SESSION['temp_creds'];
    unset($_SESSION['temp_creds']);
    $gmailSubject = rawurlencode("LinguaSchool — Ваші дані для входу");
    $gmailBody = rawurlencode(
        "Привіт, {$c['firstName']}!\n\n" .
        "Вас додано до платформи LinguaSchool.\n" .
        "Ваші дані для входу:\n\n" .
        "Логін (Email): {$c['email']}\n" .
        "Тимчасовий пароль: {$c['password']}\n\n" .
        "Посилання: http://localhost:3000/LanguageSchool/LanguageSchool/login.php\n\n" .
        "Після першого входу змініть пароль.\n\n" .
        "З повагою,\nАдміністрація LinguaSchool"
    );
    $letterText = "Привіт, {$c['firstName']}!\n\nВас додано до платформи LinguaSchool.\nВаші дані для входу:\n\n📧 Логін (Email): {$c['email']}\n🔐 Тимчасовий пароль: {$c['password']}\n\nПосилання для входу:\nhttp://localhost:3000/LanguageSchool/LanguageSchool/login.php\n\n⚠️ Після першого входу змініть пароль в особистому кабінеті.\n\nЗ повагою,\nАдміністрація LinguaSchool";
?>
<div class="modal-overlay open" id="credsModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title-text">📋 Лист для студента</div>
            <button class="modal-close" onclick="document.getElementById('credsModal').classList.remove('open')">✕</button>
        </div>

        <div class="modal-label">Кому:</div>
        <div class="modal-value"><?= htmlspecialchars($c['email']) ?></div>

        <div class="modal-label">Тема:</div>
        <div class="modal-value" style="color: var(--text);">LinguaSchool — Ваші дані для входу</div>

        <div class="modal-label">Текст листа:</div>
        <textarea class="modal-textarea" id="credsText" readonly><?= htmlspecialchars($letterText) ?></textarea>

        <div class="modal-actions-row">
            <button class="modal-btn modal-btn-copy" onclick="copyCredsTxt(this)">📋 Копіювати текст</button>
            <a class="modal-btn modal-btn-gmail"
               href="https://mail.google.com/mail/?view=cm&to=<?= rawurlencode($c['email']) ?>&su=<?= $gmailSubject ?>&body=<?= $gmailBody ?>"
               target="_blank">✉️ Відкрити у Gmail</a>
        </div>
        <div class="modal-hint">Скопіюйте текст або відкрийте у Gmail щоб надіслати дані доступу</div>
    </div>
</div>
<?php endif; ?>

<header>
    <div>
        <div class="logo">Lingua<span>School</span></div>
        <div class="header-sub">Заявки на курси</div>
    </div>
    <div class="header-nav">
        <button class="theme-toggle nav-link" title="Тема"><span class="theme-toggle-icon">☀️</span></button>
        <a class="nav-link" href="admin.php">← Панель</a>
    </div>
</header>

<div class="wrap">

    <?php
    $flash = $_SESSION['flash'] ?? null;
    if ($flash) unset($_SESSION['flash']);
    if ($flash): ?>
    <div class="flash <?= $flash['type'] ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <?php
    $cntNew      = count(array_filter($apps, fn($a) => $a['status'] === 'new'));
    $cntApproved = count(array_filter($apps, fn($a) => $a['status'] === 'approved'));
    $cntRejected = count(array_filter($apps, fn($a) => $a['status'] === 'rejected'));
    ?>

    <div class="stats">
        <div class="stat-card stat-new">
            <div class="stat-val"><?= $cntNew ?></div>
            <div class="stat-label">Нових заявок</div>
        </div>
        <div class="stat-card stat-ok">
            <div class="stat-val"><?= $cntApproved ?></div>
            <div class="stat-label">Підтверджено</div>
        </div>
        <div class="stat-card stat-rej">
            <div class="stat-val"><?= $cntRejected ?></div>
            <div class="stat-label">Відхилено</div>
        </div>
    </div>

    <!-- Нові заявки -->
    <div class="sec-head">
        <div class="sec-title">Нові заявки</div>
        <?php if ($cntNew > 0): ?>
            <span class="pill hot"><?= $cntNew ?> очікують</span>
        <?php else: ?>
            <span class="pill">0</span>
        <?php endif; ?>
    </div>

    <?php $newApps = array_filter($apps, fn($a) => $a['status'] === 'new'); ?>
    <?php if (empty($newApps)): ?>
    <div class="empty">
        <div class="empty-icon">✅</div>
        <div class="empty-title">Нових заявок немає</div>
    </div>
    <?php else: ?>
    <div class="grid">
    <?php foreach ($newApps as $a): ?>
        <div class="app-card is-new">

            <?php if ($a['source'] === 'enrollment'): ?>
                <span class="source-badge source-enrollment">📋 Реєстрація на сайті</span>
            <?php else: ?>
                <span class="source-badge source-application">📝 Форма заявки</span>
            <?php endif; ?>

            <div class="card-top">
                <div>
                    <div class="applicant-name"><?= htmlspecialchars($a['display_name']) ?></div>
                    <?php if (!empty($a['email'])): ?>
                        <div class="applicant-sub">✉ <?= htmlspecialchars($a['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['phone'])): ?>
                        <div class="applicant-sub">📞 <?= htmlspecialchars($a['phone']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="status-badge badge-new">Нова</span>
            </div>

            <div class="course-box">
                <div class="course-lang"><?= htmlspecialchars($a['language'] ?? '—') ?></div>
                <div class="course-title"><?= htmlspecialchars($a['course_title'] ?? 'Курс не знайдено') ?></div>
                <div class="course-meta">
                    <span class="tag tag-level"><?= htmlspecialchars($a['level'] ?? '') ?></span>
                    <span class="tag tag-price"><?= (int)$a['price'] ?> грн</span>
                </div>
            </div>

            <div class="card-date">
                Подано: <?= date('d.m.Y о H:i', strtotime($a['created_at'])) ?>
            </div>

            <div class="card-actions">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="app_id" value="<?= htmlspecialchars($a['id']) ?>">
                    <input type="hidden" name="source" value="<?= htmlspecialchars($a['source']) ?>">
                    <button name="action" value="approve" class="btn btn-approve">✓ Підтвердити</button>
                    <button name="action" value="reject"  class="btn btn-reject">Відхилити</button>
                    <button name="action" value="delete"  class="btn btn-delete"
                            onclick="return confirm('Видалити заявку?')">🗑</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Опрацьовані заявки -->
    <?php $doneApps = array_filter($apps, fn($a) => $a['status'] !== 'new'); ?>
    <?php if (!empty($doneApps)): ?>
    <div class="sec-head">
        <div class="sec-title">Опрацьовані</div>
        <span class="pill"><?= count($doneApps) ?></span>
    </div>
    <div class="grid">
    <?php foreach ($doneApps as $a): ?>
        <div class="app-card">

            <?php if ($a['source'] === 'enrollment'): ?>
                <span class="source-badge source-enrollment">📋 Реєстрація на сайті</span>
            <?php else: ?>
                <span class="source-badge source-application">📝 Форма заявки</span>
            <?php endif; ?>

            <div class="card-top">
                <div>
                    <div class="applicant-name"><?= htmlspecialchars($a['display_name']) ?></div>
                    <?php if (!empty($a['email'])): ?>
                        <div class="applicant-sub">✉ <?= htmlspecialchars($a['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($a['phone'])): ?>
                        <div class="applicant-sub">📞 <?= htmlspecialchars($a['phone']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($a['status'] === 'approved'): ?>
                    <span class="status-badge badge-approved">✓ Підтверджено</span>
                <?php else: ?>
                    <span class="status-badge badge-rejected">Відхилено</span>
                <?php endif; ?>
            </div>

            <div class="course-box">
                <div class="course-lang"><?= htmlspecialchars($a['language'] ?? '—') ?></div>
                <div class="course-title"><?= htmlspecialchars($a['course_title'] ?? '—') ?></div>
                <div class="course-meta">
                    <span class="tag tag-level"><?= htmlspecialchars($a['level'] ?? '') ?></span>
                    <span class="tag tag-price"><?= (int)$a['price'] ?> грн</span>
                </div>
            </div>

            <div class="card-date">
                <?= date('d.m.Y о H:i', strtotime($a['created_at'])) ?>
            </div>

            <div class="card-actions">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="app_id" value="<?= htmlspecialchars($a['id']) ?>">
                    <input type="hidden" name="source" value="<?= htmlspecialchars($a['source']) ?>">
                    <button name="action" value="delete" class="btn btn-delete"
                            style="width:100%;"
                            onclick="return confirm('Видалити заявку?')">🗑 Видалити</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
function copyCredsTxt(btn) {
    const ta = document.getElementById('credsText');
    ta.select();
    document.execCommand('copy');
    btn.textContent = '✅ Скопійовано!';
    setTimeout(() => btn.textContent = '📋 Копіювати текст', 2000);
}
document.getElementById('credsModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>
<script src="theme-switcher.js"></script>
</body>
</html>