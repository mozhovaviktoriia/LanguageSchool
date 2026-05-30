<?php
require 'config.php';

$sql = "
SELECT 
    courses.id,
    courses.title,
    courses.level,
    courses.price,
    languages.name_ua
FROM courses
JOIN languages ON courses.language_id = languages.id
WHERE courses.is_active = TRUE
LIMIT 6
";

$stmt = $pdo->query($sql);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="uk" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LinguaSchool - Онлайн школа мов</title>
<link href="theme.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ── DESIGN TOKENS ── */
:root {
    --gold:      #c9a84c;
    --gold-light:#e8cc82;
}

/* DARK THEME (default) */
[data-theme="dark"] {
    --bg-page:       #0e1117;
    --ink:           #0e1117;
    --cream:         #f5f0e8;
    --sage:          #4a7c6f;
    --sage-dark:     #2d5247;
    --sage-light:    #7fb3a7;
    --warm-gray:     #8a8275;
    --card-bg:       #ffffff;
    --section-bg:    #f0ebe1;
    --section-alt:   #e8e2d8;
    --header-bg:     rgba(14, 17, 23, 0.72);
    --header-border: rgba(255,255,255,0.06);
    --nav-color:     rgba(255,255,255,0.75);
    --nav-hover:     #ffffff;
    --footer-bg:     #080c10;
    --footer-border: rgba(255,255,255,0.05);
    --footer-logo:   rgba(255,255,255,0.5);
    --footer-text:   rgba(255,255,255,0.3);
    --stats-bg:      #0e1117;
    --stat-grid-bg:  rgba(255,255,255,0.06);
    --stat-item-bg:  rgba(255,255,255,0.03);
    --stat-item-hover: rgba(255,255,255,0.07);
    --stat-label:    rgba(255,255,255,0.5);
    --stats-label:   var(--gold);
    --stats-h2:      #ffffff;
    /* ── ОНОВЛЕНИЙ OVERLAY: зверху світло, знизу темніше для читабельності тексту ── */
    --hero-overlay:
        linear-gradient(
            to bottom,
            rgba(14,17,23,0.10) 0%,
            rgba(14,17,23,0.40) 60%,
            rgba(14,17,23,0.70) 100%
        );
    --scroll-color:  rgba(255,255,255,0.4);
    --scroll-line:   rgba(255,255,255,0.4);
    --toggle-bg:     rgba(255,255,255,0.1);
    --toggle-border: rgba(255,255,255,0.2);
    --toggle-color:  rgba(255,255,255,0.75);
    --toggle-hover:  rgba(255,255,255,0.06);
}

/* LIGHT THEME */
[data-theme="light"] {
    --bg-page:       #f5f0e8;
    --ink:           #0e1117;
    --cream:         #f5f0e8;
    --sage:          #4a7c6f;
    --sage-dark:     #2d5247;
    --sage-light:    #7fb3a7;
    --warm-gray:     #7a7570;
    --card-bg:       #ffffff;
    --section-bg:    #f0ebe1;
    --section-alt:   #e8e2d8;
    --header-bg:     rgba(245, 240, 232, 0.85);
    --header-border: rgba(14,17,23,0.08);
    --nav-color:     rgba(14,17,23,0.7);
    --nav-hover:     #0e1117;
    --footer-bg:     #e0dbd1;
    --footer-border: rgba(14,17,23,0.1);
    --footer-logo:   rgba(14,17,23,0.5);
    --footer-text:   rgba(14,17,23,0.45);
    --stats-bg:      #2d5247;
    --stat-grid-bg:  rgba(255,255,255,0.12);
    --stat-item-bg:  rgba(255,255,255,0.06);
    --stat-item-hover: rgba(255,255,255,0.15);
    --stat-label:    rgba(255,255,255,0.65);
    --stats-label:   var(--gold-light);
    --stats-h2:      #ffffff;
    /* ── ОНОВЛЕНИЙ OVERLAY для світлої теми ── */
    --hero-overlay:
        linear-gradient(
            to bottom,
            rgba(14,17,23,0.05) 0%,
            rgba(14,17,23,0.30) 60%,
            rgba(14,17,23,0.60) 100%
        );
    --scroll-color:  rgba(255,255,255,0.5);
    --scroll-line:   rgba(255,255,255,0.5);
    --toggle-bg:     rgba(14,17,23,0.06);
    --toggle-border: rgba(14,17,23,0.18);
    --toggle-color:  rgba(14,17,23,0.7);
    --toggle-hover:  rgba(14,17,23,0.06);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg-page);
    color: var(--ink);
    overflow-x: hidden;
    transition: opacity .35s ease, background .35s ease;
}
body.fade-out { opacity: 0; }

/* ── HEADER ── */
header {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 48px;
    height: 68px;
    background: var(--header-bg);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--header-border);
    transition: background .35s ease, border-color .35s ease;
}

.logo {
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    font-weight: 900;
    color: var(--nav-hover);
    letter-spacing: -.3px;
    transition: color .35s;
}
.logo span { color: var(--gold); }

.nav-left { display: flex; align-items: center; gap: 32px; }
.nav-right { display: flex; align-items: center; gap: 12px; }

header nav a {
    color: var(--nav-color);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    letter-spacing: .3px;
    transition: color .25s;
}
header nav a:hover { color: var(--nav-hover); }

/* ── THEME TOGGLE ── */
.theme-toggle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--toggle-bg);
    border: 1.5px solid var(--toggle-border);
    color: var(--toggle-color);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .25s, border-color .25s, color .25s, transform .2s;
    flex-shrink: 0;
}
.theme-toggle:hover {
    background: var(--toggle-hover);
    border-color: var(--nav-hover);
    color: var(--nav-hover);
    transform: rotate(15deg) scale(1.05);
}
.theme-toggle svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
    transition: opacity .2s;
}
.icon-sun  { display: none; }
.icon-moon { display: block; }
[data-theme="light"] .icon-sun  { display: block; }
[data-theme="light"] .icon-moon { display: none; }

.btn-ghost {
    padding: 8px 20px;
    border: 1.5px solid var(--toggle-border);
    border-radius: 100px;
    color: var(--nav-color) !important;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: border-color .25s, color .25s, background .25s;
}
.btn-ghost:hover {
    border-color: var(--nav-hover);
    color: var(--nav-hover) !important;
    background: var(--toggle-hover);
}

.btn-solid {
    padding: 9px 22px;
    background: var(--gold);
    border-radius: 100px;
    color: var(--ink) !important;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: background .25s, transform .2s;
}
.btn-solid:hover { background: var(--gold-light); transform: translateY(-1px); }

/* ── HERO ── */
.hero {
    position: relative;
    height: 100vh;
    min-height: 600px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    color: #fff;
    overflow: hidden;
}

.hero-bg {
    position: absolute;
    inset: 0;
    background: url('images/banner6.jpg') center/cover no-repeat;
    transform: scale(1.04);
    transition: transform 8s ease;
}
.hero-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--hero-overlay);
    transition: background .15s ease;
}

.hero-content {
    position: relative;
    z-index: 2;
    padding: 0 24px;
    max-width: 760px;
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(201,168,76,0.18);
    border: 1px solid rgba(201,168,76,0.4);
    border-radius: 100px;
    padding: 6px 16px;
    font-size: 13px;
    color: var(--gold-light);
    letter-spacing: .5px;
    text-transform: uppercase;
    margin-bottom: 28px;
    font-weight: 500;
}
.hero-badge::before { content: '✦'; font-size: 10px; }

.hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(38px, 6vw, 72px);
    font-weight: 900;
    line-height: 1.08;
    margin-bottom: 22px;
    letter-spacing: -1px;
    /* Тінь для кращої читабельності на світлому фоні */
    text-shadow: 0 2px 20px rgba(0,0,0,0.4), 0 1px 4px rgba(0,0,0,0.3);
}
.hero h1 em {
    font-style: italic;
    color: var(--gold-light);
}

.hero p {
    font-size: clamp(16px, 2vw, 19px);
    color: rgba(255,255,255,0.90);
    margin-bottom: 40px;
    line-height: 1.65;
    font-weight: 300;
    /* Тінь для підзаголовка */
    text-shadow: 0 1px 12px rgba(0,0,0,0.5);
}

.hero-actions {
    display: flex;
    align-items: center;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-hero-primary {
    padding: 14px 36px;
    background: var(--gold);
    border-radius: 100px;
    color: var(--ink);
    font-size: 15px;
    font-weight: 700;
    text-decoration: none;
    letter-spacing: .2px;
    transition: background .25s, transform .25s, box-shadow .25s;
    box-shadow: 0 8px 32px rgba(201,168,76,0.35);
}
.btn-hero-primary:hover {
    background: var(--gold-light);
    transform: translateY(-3px);
    box-shadow: 0 14px 40px rgba(201,168,76,0.45);
}

.btn-hero-secondary {
    padding: 13px 32px;
    border: 1.5px solid rgba(255,255,255,0.55);
    border-radius: 100px;
    color: rgba(255,255,255,0.95);
    font-size: 15px;
    font-weight: 500;
    text-decoration: none;
    transition: border-color .25s, color .25s;
    /* Легкий фон щоб кнопка читалась на світлій частині фото */
    background: rgba(0,0,0,0.15);
    backdrop-filter: blur(6px);
}
.btn-hero-secondary:hover {
    border-color: rgba(255,255,255,0.9);
    color: #fff;
    background: rgba(0,0,0,0.25);
}

.hero-scroll {
    position: absolute;
    bottom: 36px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: var(--scroll-color);
    font-size: 11px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    animation: scrollBounce 2s ease-in-out infinite;
    text-shadow: 0 1px 8px rgba(0,0,0,0.4);
}
.hero-scroll::after {
    content: '';
    width: 1px;
    height: 40px;
    background: linear-gradient(to bottom, var(--scroll-line), transparent);
}

@keyframes scrollBounce {
    0%, 100% { transform: translateX(-50%) translateY(0); }
    50% { transform: translateX(-50%) translateY(8px); }
}

/* ── SECTIONS ── */
.section {
    background: var(--cream);
    padding: 90px 48px;
    transition: background .35s ease;
}
.section + .section { background: var(--section-bg); }

.section-header {
    text-align: center;
    margin-bottom: 56px;
}
.section-label {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--sage);
    margin-bottom: 14px;
}
.section h2 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(28px, 3.5vw, 44px);
    font-weight: 900;
    color: var(--ink);
    letter-spacing: -.5px;
    line-height: 1.1;
}

/* ── COURSE CARDS ── */
.courses {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    max-width: 1160px;
    margin: 0 auto;
}

.course-card {
    background: var(--card-bg);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(14,17,23,0.07);
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(14,17,23,0.07);
    transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s, opacity .4s;
    opacity: 0;
    transform: translateY(32px);
}
.course-card.show {
    opacity: 1;
    transform: translateY(0);
}
.course-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 16px 40px rgba(14,17,23,0.14);
}

.course-card img {
    width: 100%;
    height: 190px;
    object-fit: cover;
    display: block;
    transition: transform .5s ease;
}
.course-card:hover img { transform: scale(1.04); }

.course-img-wrap {
    overflow: hidden;
    flex-shrink: 0;
}

.course-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}

.course-meta {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.course-tag {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .8px;
    text-transform: uppercase;
    padding: 4px 10px;
    border-radius: 100px;
    background: var(--section-bg);
    color: var(--sage-dark);
}

.course-content h3 {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--ink);
    line-height: 1.25;
    margin-bottom: 8px;
}

.course-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid rgba(14,17,23,0.07);
}

.price {
    font-size: 24px;
    font-weight: 700;
    color: var(--sage-dark);
    letter-spacing: -.3px;
}
.price span { font-size: 14px; font-weight: 400; color: var(--warm-gray); }

.btn-card {
    padding: 10px 22px;
    background: var(--sage);
    border-radius: 100px;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background .25s, transform .2s;
    letter-spacing: .2px;
}
.btn-card:hover { background: var(--sage-dark); transform: translateY(-1px); }

/* ── FEATURES ── */
.features {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    max-width: 1160px;
    margin: 0 auto;
}

.feature-box {
    background: var(--card-bg);
    border-radius: 20px;
    padding: 36px 28px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 16px;
    border: 1px solid rgba(14,17,23,0.07);
    box-shadow: 0 2px 12px rgba(14,17,23,0.05);
    transition: transform .3s, box-shadow .3s;
}
.feature-box:hover {
    transform: translateY(-6px);
    box-shadow: 0 14px 36px rgba(14,17,23,0.1);
}

.feature-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: var(--section-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.feature-box img {
    width: 64px;
    height: 64px;
    object-fit: cover;
    border-radius: 14px;
}

.feature-box h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--ink);
}
.feature-box p {
    font-size: 14px;
    color: var(--warm-gray);
    line-height: 1.65;
}

/* ── STATS ── */
.stats-section {
    background: var(--stats-bg);
    padding: 80px 48px;
    transition: background .35s ease;
}
.stats-section .section-label { color: var(--stats-label); }
.stats-section h2 { color: var(--stats-h2); }

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 2px;
    max-width: 900px;
    margin: 56px auto 0;
    background: var(--stat-grid-bg);
    border-radius: 20px;
    overflow: hidden;
    border: 1px solid var(--stat-grid-bg);
}

.stat {
    padding: 40px 32px;
    text-align: center;
    background: var(--stat-item-bg);
    transition: background .25s;
}
.stat:hover { background: var(--stat-item-hover); }

.stat h3 {
    font-family: 'Playfair Display', serif;
    font-size: 42px;
    font-weight: 900;
    color: var(--gold);
    line-height: 1;
    margin-bottom: 8px;
    letter-spacing: -1px;
}
.stat p {
    font-size: 13px;
    color: var(--stat-label);
    letter-spacing: .5px;
    text-transform: uppercase;
    font-weight: 500;
}

/* ── FOOTER ── */
footer {
    background: var(--footer-bg);
    padding: 32px 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--footer-border);
    transition: background .35s ease;
}
.footer-logo {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 900;
    color: var(--footer-logo);
}
.footer-logo span { color: var(--gold); }
footer p {
    font-size: 13px;
    color: var(--footer-text);
}

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .features { grid-template-columns: repeat(2, 1fr); }
    .stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    header { padding: 0 20px; }
    .section { padding: 60px 20px; }
    .features { grid-template-columns: 1fr; }
    .stats { grid-template-columns: repeat(2, 1fr); }
    .stats-section { padding: 60px 20px; }
    footer { flex-direction: column; gap: 12px; text-align: center; padding: 28px 20px; }
}
</style>
</head>
<body>

<header>
    <div class="nav-left">
        <div class="logo">Lingua<span>School</span></div>
        <nav>
            <a href="courses.php">Курси</a>
        </nav>
    </div>
    <div class="nav-right">
        <button class="theme-toggle" id="themeToggle" aria-label="Перемкнути тему">
            <!-- Sun icon (shown in dark mode = switch to light) -->
            <svg class="icon-sun" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/>
                <line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/>
                <line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <!-- Moon icon (shown in light mode = switch to dark) -->
            <svg class="icon-moon" viewBox="0 0 24 24">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
        <a class="btn-ghost" href="login.php">Увійти</a>
        <a class="btn-solid" href="register.php">Реєстрація</a>
    </div>
</header>

<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <div class="hero-badge">Онлайн-навчання нового рівня</div>
        <h1>Вивчай мови<br><em>без кордонів</em></h1>
        <p>Англійська, німецька, французька та ще 12 мов<br>з досвідченими викладачами — у зручний для вас час</p>
        <div class="hero-actions">
            <a class="btn-hero-primary" href="register.php">Почати навчання</a>
            <a class="btn-hero-secondary" href="courses.php">Переглянути курси</a>
        </div>
    </div>
    <div class="hero-scroll">Scroll</div>
</section>

<section class="section">
    <div class="section-header">
        <span class="section-label">Каталог</span>
        <h2>Популярні курси</h2>
    </div>
    <div class="courses">
        <?php foreach($courses as $course): ?>
            <div class="course-card">
                <div class="course-img-wrap">
                    <img src="<?= !empty($course['image']) ? htmlspecialchars($course['image']) : 'images/course-placeholder.jpg' ?>" alt="<?= htmlspecialchars($course['title']) ?>">
                </div>
                <div class="course-content">
                    <div class="course-meta">
                        <span class="course-tag"><?= htmlspecialchars($course['name_ua']) ?></span>
                        <span class="course-tag"><?= htmlspecialchars($course['level']) ?></span>
                    </div>
                    <h3><?= htmlspecialchars($course['title']) ?></h3>
                    <div class="course-footer">
                        <div class="price"><?= $course['price'] ?> <span>грн</span></div>
                        <a class="btn-card" href="courses.php?id=<?= $course['id'] ?>">Детальніше</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="section-header">
        <span class="section-label">Переваги</span>
        <h2>Чому обирають нас</h2>
    </div>
    <div class="features">
        <div class="feature-box">
            <img src="images/teacher.jpg" alt="Викладач">
            <h3>Досвідчені викладачі</h3>
            <p>Професіонали міжнародного рівня з підтвердженою кваліфікацією</p>
        </div>
        <div class="feature-box">
            <img src="images/schedule.jpg" alt="Графік">
            <h3>Гнучкий графік</h3>
            <p>Заняття у зручний для вас час — вранці, ввечері або у вихідні</p>
        </div>
        <div class="feature-box">
            <img src="images/online.jpg" alt="Онлайн">
            <h3>Онлайн 24/7</h3>
            <p>Усі матеріали доступні у будь-який момент з будь-якого пристрою</p>
        </div>
        <div class="feature-box">
            <img src="images/certificate.jpg" alt="Сертифікат">
            <h3>Сертифікат</h3>
            <p>Офіційний документ після завершення курсу, визнаний роботодавцями</p>
        </div>
    </div>
</section>

<section class="stats-section">
    <div class="section-header">
        <span class="section-label">Цифри</span>
        <h2>Наші результати</h2>
    </div>
    <div class="stats">
        <div class="stat"><h3>2500+</h3><p>Студентів</p></div>
        <div class="stat"><h3>15</h3><p>Мов</p></div>
        <div class="stat"><h3>98%</h3><p>Задоволених</p></div>
        <div class="stat"><h3>24/7</h3><p>Підтримка</p></div>
    </div>
</section>

<footer>
    <div class="footer-logo">Lingua<span>School</span></div>
    <p>© 2026 LinguaSchool. Всі права захищені.</p>
</footer>

<script>
// ── THEME TOGGLE ──
const html = document.documentElement;
const toggleBtn = document.getElementById('themeToggle');

// Load saved preference
const saved = localStorage.getItem('lingua-theme');
if (saved) html.setAttribute('data-theme', saved);

toggleBtn.addEventListener('click', () => {
    const current = html.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('lingua-theme', next);
});

// ── PARALLAX HERO BG ──
const heroBg = document.querySelector('.hero-bg');
window.addEventListener('scroll', () => {
    const y = window.scrollY;
    if (heroBg) heroBg.style.transform = `scale(1.04) translateY(${y * 0.25}px)`;
});

// ── CARD REVEAL ──
document.querySelectorAll('.course-card').forEach((card, i) => {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                setTimeout(() => entry.target.classList.add('show'), i * 80);
            }
        });
    }, { threshold: 0.15 });
    observer.observe(card);
});

// ── PAGE TRANSITION ──
document.querySelectorAll('header a, .btn-hero-primary, .btn-hero-secondary, .btn-card').forEach(link => {
    link.addEventListener('click', function(e) {
        const url = this.href;
        if (!url || url === '#' || url === window.location.href) return;
        e.preventDefault();
        document.body.classList.add('fade-out');
        setTimeout(() => { window.location = url; }, 300);
    });
});
</script>

</body>
</html>