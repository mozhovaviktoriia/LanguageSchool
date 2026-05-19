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
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Курси мов | LinguaSchool</title>

<style>
body{
    margin:0;
    font-family:'Segoe UI',sans-serif;
    background:linear-gradient(135deg,#ffb74d,#26a69a);
    color:white;
    min-height:100vh;
}

header{
    background:rgba(255,255,255,0.12);
    backdrop-filter:blur(12px);
    padding:20px 40px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

header a{
    color:white;
    text-decoration:none;
    margin-left:20px;
    font-weight:600;
}

header a:hover{
    color:#fff176;
}

.page-title{
    text-align:center;
    padding:40px 20px 20px;
}

.filters{
    display:flex;
    flex-wrap:wrap;
    justify-content:center;
    gap:15px;
    padding:20px;
}

.filters input,
.filters select{
    padding:12px 16px;
    border:none;
    border-radius:25px;
    font-size:14px;
    outline:none;
}

.courses-container{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
    gap:30px;
    padding:40px;
}

.course-card{
    background:rgba(255,255,255,0.15);
    backdrop-filter:blur(16px);
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 10px 25px rgba(0,0,0,.15);
    transition:.4s;
    color:white;
    opacity:0;
    transform:translateY(40px);
}

.course-card.show{
    opacity:1;
    transform:translateY(0);
}

.course-card:hover{
    transform:translateY(-8px);
}

.course-card img{
    width:100%;
    height:200px;
    object-fit:cover;
    transition:transform .4s ease;
}

.course-card:hover img{
    transform:scale(1.05);
}

.course-content{
    padding:20px;
}

.course-content h3{
    margin-top:0;
}

.level{
    display:inline-block;
    background:rgba(255,255,255,0.2);
    padding:6px 12px;
    border-radius:20px;
    margin-bottom:10px;
}

.price{
    font-size:24px;
    font-weight:bold;
    color:#fff176;
    margin:15px 0;
}

.btn{
    display:inline-block;
    background:white;
    color:#009688;
    padding:12px 24px;
    border-radius:30px;
    text-decoration:none;
    font-weight:bold;
    transition:.3s;
}

.btn:hover{
    transform:translateY(-2px);
}

footer{
    background:rgba(255,255,255,0.12);
    text-align:center;
    padding:20px;
    margin-top:40px;
}
</style>
</head>
<body>

<header>
    <h2>LinguaSchool</h2>
    <nav>
        <a href="index.php">Головна</a>
        <a href="courses.php">Курси</a>
        </button>
        <a href="login.php">Увійти</a>
        <a href="register.php">Реєстрація</a>
    </nav>
</header>

<div class="page-title">
    <h1>Курси іноземних мов</h1>
    <p>Знайдіть курс який підходить саме вам</p>
</div>

<div class="filters">
    <input type="text" id="searchInput" placeholder="Пошук курсу...">

    <select id="languageFilter">
        <option value="">Усі мови</option>
        <?php foreach($languages as $lang): ?>
            <option value="<?= htmlspecialchars($lang) ?>">
                <?= htmlspecialchars($lang) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select id="levelFilter">
        <option value="">Усі рівні</option>
        <?php foreach($levels as $level): ?>
            <option value="<?= htmlspecialchars($level) ?>">
                <?= htmlspecialchars($level) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="courses-container" id="coursesContainer">
<?php foreach ($courses as $course): ?>

    <?php
    $imageMap = [
        'Англійська' => 'english.jpg',
        'Німецька'   => 'german.jpg',
        'Японська' => 'japanese.jpg',
    ];

    $image = $imageMap[$course['name_ua']] ?? 'default.jpg';
    ?>

    <div class="course-card"
         data-title="<?= strtolower(htmlspecialchars($course['title'])) ?>"
         data-language="<?= htmlspecialchars($course['name_ua']) ?>"
         data-level="<?= htmlspecialchars($course['level']) ?>">

        <img src="images/<?= $image ?>" alt="<?= htmlspecialchars($course['name_ua']) ?>">

        <div class="course-content">
            <h3><?= htmlspecialchars($course['title']) ?></h3>

            <p><strong>Мова:</strong> <?= htmlspecialchars($course['name_ua']) ?></p>

            <div class="level">
                <?= htmlspecialchars($course['level']) ?>
            </div>

            <p><?= nl2br(htmlspecialchars($course['description'])) ?></p>

            <div class="price">
                <?= $course['price'] ?> грн
            </div>

            <a class="btn" href="apply.php?course_id=<?= $course['id'] ?>">Залишити заявку</a>
        </div>
    </div>
<?php endforeach; ?>
</div>

<footer>
    © 2026 LinguaSchool. Всі права захищені.
</footer>

<script>
const cards = document.querySelectorAll('.course-card');

const observer = new IntersectionObserver(entries=>{
    entries.forEach(entry=>{
        if(entry.isIntersecting){
            entry.target.classList.add('show');
        }
    });
},{threshold:0.2});

cards.forEach(card=>observer.observe(card));

function filterCourses(){
    const search = document.getElementById('searchInput').value.toLowerCase();
    const language = document.getElementById('languageFilter').value;
    const level = document.getElementById('levelFilter').value;

    cards.forEach(card=>{
        const title = card.dataset.title;
        const cardLang = card.dataset.language;
        const cardLevel = card.dataset.level;

        const matchSearch = title.includes(search);
        const matchLang = !language || cardLang === language;
        const matchLevel = !level || cardLevel === level;

        card.style.display = (matchSearch && matchLang && matchLevel) ? 'block' : 'none';
    });
}

document.getElementById('searchInput').addEventListener('input', filterCourses);
document.getElementById('languageFilter').addEventListener('change', filterCourses);
document.getElementById('levelFilter').addEventListener('change', filterCourses);
</script>

</body>
</html>