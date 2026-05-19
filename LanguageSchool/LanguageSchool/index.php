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
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LinguaSchool - Онлайн школа мов</title>
<link href="theme.css" rel="stylesheet">

<style>
*,
*::before,
*::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: 'Segoe UI', sans-serif;
    background: #1a2a2a;
    color: white;
    transition: opacity .35s ease;
    min-width: 320px;
    overflow-x: hidden;
}

body.fade-out {
    opacity: 0;
}

header {
    background: rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 18px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
    width: 100%;
}

.logo {
    font-size: 24px;
    font-weight: bold;
    white-space: nowrap;
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 24px;
}

.nav-right {
    display: flex;
    align-items: center;
    gap: 18px;
}

header a {
    color: white;
    text-decoration: none;
    font-weight: 600;
    transition: color .3s;
    white-space: nowrap;
}

header a:hover {
    color: #fff176;
}

.hero {
    height: 100vh;
    background:
        linear-gradient(rgba(0, 0, 0, .45), rgba(0, 0, 0, .45)),
        url('images/banner.jpg') center / cover no-repeat;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    color: white;
    padding: 80px 20px 60px;
    width: 100%;
}

.hero h1 {
    font-size: clamp(28px, 5vw, 52px);
    margin-bottom: 20px;
    line-height: 1.2;
    max-width: 700px;
}

.hero p {
    font-size: clamp(16px, 2.5vw, 20px);
    margin-bottom: 30px;
    max-width: 600px;
}

.btn {
    display: inline-block;
    background: white;
    color: #009688;
    padding: 13px 28px;
    border-radius: 30px;
    text-decoration: none;
    font-weight: bold;
    transition: transform .3s, box-shadow .3s;
    white-space: nowrap;
}

.btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, .2);
}

.section {
    padding: 70px 40px;
    width: 100%;
    background: linear-gradient(135deg, #ffb74d, #26a69a);
}

.section h2 {
    text-align: center;
    margin-bottom: 40px;
    font-size: clamp(22px, 3vw, 32px);
}

.courses {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
    gap: 25px;
    max-width: 1200px;
    margin: 0 auto;
}

.course-card {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
    display: flex;
    flex-direction: column;
    transition: transform .4s, opacity .4s;
    opacity: 0;
    transform: translateY(40px);
}

.course-card.show {
    opacity: 1;
    transform: translateY(0);
}

.course-card:hover {
    transform: translateY(-8px);
}

.course-card img {
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
    flex-shrink: 0;
}

.course-content {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
}

.course-content h3 {
    font-size: 18px;
    margin-bottom: 4px;
}

.course-content p {
    font-size: 14px;
    line-height: 1.5;
}

.price {
    color: #fff176;
    font-size: 22px;
    font-weight: bold;
    margin: 4px 0 12px;
}

.features {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 50px;
    text-align: center;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 40px;
}

.feature-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 36px 24px;
    transition: transform .3s, background .3s;
}

.feature-box:hover {
    background: rgba(255, 255, 255, 0.18);
    transform: translateY(-6px);
}

.feature-box img {
    width: 110px;
    height: 110px;
    object-fit: contain;
    border-radius: 16px;
    transition: transform .3s ease;
}

.feature-box:hover img {
    transform: scale(1.08);
}

.feature-box h3 {
    font-size: 19px;
    font-weight: 700;
}

.feature-box p {
    font-size: 15px;
    line-height: 1.6;
    opacity: .88;
}

.stats {
    display: flex;
    justify-content: center;
    gap: 40px;
    margin-top: 40px;
    flex-wrap: wrap;
}

.stat {
    text-align: center;
    min-width: 100px;
}

.stat h3 {
    font-size: 36px;
    color: #fff176;
    margin: 0 0 6px;
    line-height: 1;
}

footer {
    background: rgba(255, 255, 255, 0.12);
    text-align: center;
    padding: 25px 20px;
    font-size: 14px;
}

@media (max-width: 600px) {
    header {
        padding: 14px 20px;
    }

    .section {
        padding: 50px 20px;
    }

    .stats {
        gap: 24px;
    }
}
</style>
</head>
<body>

<header>
    <div class="nav-left">
        <span class="logo">LinguaSchool</span>
        <a href="courses.php">Курси</a>
    </div>

    <div class="nav-right">
        <a href="login.php">Увійти</a>
        <a href="register.php">Реєстрація</a>
    </div>
</header>

<section class="hero">
    <h1>Вивчай іноземні мови онлайн</h1>
    <p>Англійська, німецька, французька та інші мови з професійними викладачами</p>
    <a class="btn" href="register.php">Почати навчання</a>
</section>

<section class="section">
    <h2>Популярні курси</h2>
    <div class="courses">
        <?php foreach($courses as $course): ?>
            <div class="course-card">
                <img src="<?= !empty($course['image']) ? htmlspecialchars($course['image']) : 'images/course-placeholder.jpg' ?>" alt="<?= htmlspecialchars($course['title']) ?>">
                <div class="course-content">
                    <h3><?= htmlspecialchars($course['title']) ?></h3>
                    <p>Мова: <?= htmlspecialchars($course['name_ua']) ?></p>
                    <p>Рівень: <?= htmlspecialchars($course['level']) ?></p>
                    <p class="price"><?= $course['price'] ?> грн</p>
                    <a class="btn" href="courses.php?id=<?= $course['id'] ?>">Детальніше</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <h2>Чому обирають нас</h2>
    <div class="features">
        <div class="feature-box">
            <img src="images/teacher.jpg" alt="Викладач">
            <h3>Досвідчені викладачі</h3>
            <p>Професійні викладачі міжнародного рівня</p>
        </div>
        <div class="feature-box">
            <img src="images/schedule.jpg" alt="Графік">
            <h3>Гнучкий графік</h3>
            <p>Зручний час занять для кожного студента</p>
        </div>
        <div class="feature-box">
            <img src="images/online.jpg" alt="Онлайн">
            <h3>Онлайн 24/7</h3>
            <p>Матеріали доступні у будь-який момент</p>
        </div>
        <div class="feature-box">
            <img src="images/certificate.jpg" alt="Сертифікат">
            <h3>Сертифікат</h3>
            <p>Після завершення курсу сертифікат</p>
        </div>
    </div>
</section>

<section class="section">
    <h2>Наші результати</h2>
    <div class="stats">
        <div class="stat">
            <h3>2500+</h3>
            <p>Студентів</p>
        </div>
        <div class="stat">
            <h3>15</h3>
            <p>Мов</p>
        </div>
        <div class="stat">
            <h3>98%</h3>
            <p>Задоволених клієнтів</p>
        </div>
        <div class="stat">
            <h3>24/7</h3>
            <p>Підтримка</p>
        </div>
    </div>
</section>

<footer>
    © 2026 LinguaSchool. Всі права захищені.
</footer>

<script>
document.querySelectorAll('.course-card').forEach(card => {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('show');
            }
        });
    }, { threshold: 0.2 });
    observer.observe(card);
});

document.querySelectorAll('header a').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const url = this.href;
        document.body.classList.add('fade-out');
        setTimeout(() => { window.location = url; }, 300);
    });
});
</script>

</body>
</html>