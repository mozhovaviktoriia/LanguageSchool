<?php
$host = "localhost";
$port = "5432";
$dbname = "LanguageSchool";
$user = "postgres";
$password = "010420";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Помилка підключення: " . $e->getMessage());
}

// Helper function for case conversion that works with multibyte strings
if (!function_exists('safe_strtolower')) {
    function safe_strtolower($string) {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($string, 'UTF-8');
        }
        return strtolower($string);
    }
}
?>