<?php
session_start();
require 'config.php';

$studentId = $_SESSION['user_id'];

$sql = "
SELECT 
    c.title,
    l.name_ua,
    e.status
FROM enrollments e
JOIN courses c ON e.course_id = c.id
JOIN languages l ON c.language_id = l.id
WHERE e.student_id = :student_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':student_id' => $studentId]);

$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Мої курси</h2>";

foreach ($enrollments as $item) {
    echo "{$item['title']} ({$item['name_ua']}) - {$item['status']}<br>";
}
?>