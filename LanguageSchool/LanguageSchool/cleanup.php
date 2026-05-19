<?php
require 'config.php';

// Видаляємо неактивні записи, якщо є активні на ту ж курс
$cleanup = $pdo->prepare("
DELETE FROM enrollments e1
WHERE e1.status != 'active'
AND EXISTS (
    SELECT 1 FROM enrollments e2 
    WHERE e2.student_id = e1.student_id 
    AND e2.course_id = e1.course_id 
    AND e2.status = 'active'
)
");

try {
    $cleanup->execute();
    echo "✅ Дублікати видалені!";
} catch (Exception $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>
