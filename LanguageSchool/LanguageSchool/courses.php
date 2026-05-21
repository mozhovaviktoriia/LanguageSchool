<?php
require 'config.php';

$sql = "
SELECT 
    courses.id,
    courses.title,
    courses.description,
    courses.level,
    courses.price,
    languages.name_ua
FROM courses
JOIN languages ON courses.language_id = languages.id
WHERE courses.is_active = TRUE
ORDER BY courses.title
";

$stmt = $pdo->query($sql);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$languages = array_unique(array_column($courses, 'name_ua'));
$levels = array_unique(array_column($courses, 'level'));
?>

<!DOCTYPE html>
<html lang="uk" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Курси мов | LinguaSchool</title>
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
    --toggle-bg:     rgba(255,255,255,0.1);
    --toggle-border: rgba(255,255,255,0.2);
    --toggle-color:  rgba(255,255,255,0.75);
    --toggle-hover:  rgba(255,255,255,0.06);
    --filter-bg:     rgba(255,255,255,0.07);
    --filter-border: rgba(255,255,255,0.12);
    --filter-color:  rgba(255,255,255,0.85);
    --filter-placeholder: rgba(255,255,255,0.4);
    --page-hero-bg:  #0e1117;
    --page-hero-border: rgba(255,255,255,0.05);
    --page-hero-h1:  #ffffff;
    --page-hero-p:   rgba(255,255,255,0.55);
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
    --toggle-bg:     rgba(14,17,23,0.06);
    --toggle-border: rgba(14,17,23,0.18);
    --toggle-color:  rgba(14,17,23,0.7);
    --toggle-hover:  rgba(14,17,23,0.06);
    --filter-bg:     #ffffff;
    --filter-border: rgba(14,17,23,0.12);
    --filter-color:  #0e1117;
    --filter-placeholder: rgba(14,17,23,0.4);
    --page-hero-bg:  #e8e2d8;
    --page-hero-border: rgba(14,17,23,0.06);
    --page-hero-h1:  #0e1117;
    --page-hero-p:   rgba(14,17,23,0.55);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg-page);
    color: var(--ink);
    overflow-x: hidden;
    transition: background .35s ease, opacity .35s ease;
    min-height: 100vh;
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
    text-decoration: none;
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
header nav a:hover,
header nav a.active { color: var(--nav-hover); }
header nav a.active { font-weight: 600; }

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

/* ── PAGE HERO (замість секції з банером) ── */
.page-hero {
    margin-top: 68px;
    padding: 64px 48px 52px;
    background: var(--page-hero-bg);
    border-bottom: 1px solid var(--page-hero-border);
    text-align: center;
    transition: background .35s, border-color .35s;
}
.page-hero-label {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--sage);
    margin-bottom: 14px;
}
.page-hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(30px, 4vw, 52px);
    font-weight: 900;
    color: var(--page-hero-h1);
    letter-spacing: -.5px;
    line-height: 1.1;
    margin-bottom: 14px;
    transition: color .35s;
}
.page-hero p {
    font-size: 16px;
    color: var(--page-hero-p);
    font-weight: 300;
    transition: color .35s;
}

/* ── FILTERS ── */
.filters-wrap {
    background: var(--section-bg);
    padding: 28px 48px;
    border-bottom: 1px solid var(--page-hero-border);
    transition: background .35s;
}
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    max-width: 1160px;
    margin: 0 auto;
    align-items: center;
}

.filter-search {
    position: relative;
    flex: 1;
    min-width: 200px;
}
.filter-search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--filter-placeholder);
    pointer-events: none;
}
.filter-search-icon svg {
    width: 16px; height: 16px;
    fill: none; stroke: currentColor;
    stroke-width: 2; stroke-linecap: round;
}

.filters input,
.filters select {
    width: 100%;
    padding: 11px 16px 11px 42px;
    background: var(--filter-bg);
    border: 1.5px solid var(--filter-border);
    border-radius: 100px;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--filter-color);
    outline: none;
    transition: border-color .25s, background .35s, color .35s;
    appearance: none;
    -webkit-appearance: none;
}
.filters select {
    padding-left: 16px;
    cursor: pointer;
}
.filters input::placeholder { color: var(--filter-placeholder); }
.filters input:focus,
.filters select:focus { border-color: var(--sage); }

.filter-count {
    font-size: 13px;
    color: var(--warm-gray);
    white-space: nowrap;
    padding: 0 4px;
}

/* ── COURSES GRID ── */
.courses-section {
    background: var(--cream);
    padding: 48px 48px 80px;
    min-height: 400px;
    transition: background .35s;
}

.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
    max-width: 1160px;
    margin: 0 auto;
}

/* ── COURSE CARD ── */
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
.course-card[style*="display: none"] { display: none !important; }

.course-img-wrap { overflow: hidden; flex-shrink: 0; }
.course-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    display: block;
    transition: transform .5s ease;
}
.course-card:hover img { transform: scale(1.04); }

.course-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
}

.course-meta {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
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
    margin-bottom: 4px;
}

.course-description {
    font-size: 14px;
    color: var(--warm-gray);
    line-height: 1.65;
    flex: 1;
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

/* ── EMPTY STATE ── */
.empty-state {
    display: none;
    text-align: center;
    padding: 80px 24px;
    max-width: 400px;
    margin: 0 auto;
}
.empty-state-icon {
    font-size: 48px;
    margin-bottom: 20px;
    opacity: .4;
}
.empty-state h3 {
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 10px;
}
.empty-state p {
    font-size: 14px;
    color: var(--warm-gray);
    line-height: 1.65;
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
@media (max-width: 768px) {
    header { padding: 0 20px; }
    .page-hero { padding: 48px 20px 36px; }
    .filters-wrap { padding: 20px; }
    .courses-section { padding: 32px 20px 60px; }
    footer { flex-direction: column; gap: 12px; text-align: center; padding: 28px 20px; }
}
@media (max-width: 480px) {
    .filter-search { min-width: 100%; }
}
</style>
</head>
<body>

<header>
    <div class="nav-left">
        <a class="logo" href="index.php">Lingua<span>School</span></a>
        <nav>
            <a href="index.php">Головна</a>
        </nav>
    </div>
    <div class="nav-right">
        <button class="theme-toggle" id="themeToggle" aria-label="Перемкнути тему">
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
            <svg class="icon-moon" viewBox="0 0 24 24">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
        <a class="btn-ghost" href="login.php">Увійти</a>
        <a class="btn-solid" href="register.php">Реєстрація</a>
    </div>
</header>

<!-- PAGE HERO -->
<div class="page-hero">
    <span class="page-hero-label">Каталог</span>
    <h1>Курси іноземних мов</h1>
    <p>Знайдіть курс, який підходить саме вам</p>
</div>

<!-- FILTERS -->
<div class="filters-wrap">
    <div class="filters">
        <div class="filter-search">
            <span class="filter-search-icon">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="text" id="searchInput" placeholder="Пошук курсу...">
        </div>

        <div style="position:relative; min-width:180px;">
            <select id="languageFilter" style="padding-left:16px; width:100%;">
                <option value="">Усі мови</option>
                <?php foreach($languages as $lang): ?>
                    <option value="<?= htmlspecialchars($lang) ?>"><?= htmlspecialchars($lang) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="position:relative; min-width:160px;">
            <select id="levelFilter" style="padding-left:16px; width:100%;">
                <option value="">Усі рівні</option>
                <?php foreach($levels as $level): ?>
                    <option value="<?= htmlspecialchars($level) ?>"><?= htmlspecialchars($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <span class="filter-count" id="filterCount"></span>
    </div>
</div>

<!-- COURSES -->
<section class="courses-section">
    <div class="courses-grid" id="coursesGrid">
        <?php
        $imageMap = [
            'Англійська' => 'english.jpg',
            'Німецька'   => 'german.jpg',
            'Японська'   => 'japanese.jpg',
        ];
        foreach ($courses as $course):
            $image = $imageMap[$course['name_ua']] ?? 'default.jpg';
        ?>
        <div class="course-card"
             data-title="<?= strtolower(htmlspecialchars($course['title'])) ?>"
             data-language="<?= htmlspecialchars($course['name_ua']) ?>"
             data-level="<?= htmlspecialchars($course['level']) ?>">

            <div class="course-img-wrap">
                <img src="images/<?= $image ?>" alt="<?= htmlspecialchars($course['name_ua']) ?>">
            </div>

            <div class="course-content">
                <div class="course-meta">
                    <span class="course-tag"><?= htmlspecialchars($course['name_ua']) ?></span>
                    <span class="course-tag"><?= htmlspecialchars($course['level']) ?></span>
                </div>
                <h3><?= htmlspecialchars($course['title']) ?></h3>
                <p class="course-description"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
                <div class="course-footer">
                    <div class="price"><?= $course['price'] ?> <span>грн</span></div>
                    <a class="btn-card" href="apply.php?course_id=<?= $course['id'] ?>">Залишити заявку</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="empty-state" id="emptyState">
        <div class="empty-state-icon">🔍</div>
        <h3>Нічого не знайдено</h3>
        <p>Спробуйте змінити параметри пошуку або скиньте фільтри</p>
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
const saved = localStorage.getItem('lingua-theme');
if (saved) html.setAttribute('data-theme', saved);

toggleBtn.addEventListener('click', () => {
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('lingua-theme', next);
});

// ── CARD REVEAL ──
const cards = document.querySelectorAll('.course-card');
const revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) entry.target.classList.add('show');
    });
}, { threshold: 0.15 });
cards.forEach(card => revealObserver.observe(card));

// ── FILTER ──
const searchInput    = document.getElementById('searchInput');
const languageFilter = document.getElementById('languageFilter');
const levelFilter    = document.getElementById('levelFilter');
const emptyState     = document.getElementById('emptyState');
const filterCount    = document.getElementById('filterCount');
const total          = cards.length;

function filterCourses() {
    const search   = searchInput.value.toLowerCase().trim();
    const language = languageFilter.value;
    const level    = levelFilter.value;
    let visible    = 0;

    cards.forEach(card => {
        const matchSearch   = card.dataset.title.includes(search);
        const matchLanguage = !language || card.dataset.language === language;
        const matchLevel    = !level    || card.dataset.level === level;
        const show          = matchSearch && matchLanguage && matchLevel;

        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    emptyState.style.display = visible === 0 ? 'block' : 'none';
    filterCount.textContent  = visible < total
        ? `Знайдено: ${visible} з ${total}`
        : `Всього курсів: ${total}`;
}

searchInput.addEventListener('input', filterCourses);
languageFilter.addEventListener('change', filterCourses);
levelFilter.addEventListener('change', filterCourses);

// Початковий підрахунок
filterCourses();

// ── PAGE TRANSITION ──
document.querySelectorAll('a[href]').forEach(link => {
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