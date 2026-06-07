<?php
// Load environment variables from .env file
function loadEnv($filePath = null) {
    if ($filePath === null) {
        $filePath = __DIR__ . '/.env';
    }
    
    if (!file_exists($filePath)) {
        return;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false || strpos($line, '#') === 0) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Remove quotes if present
        if (preg_match('/^"(.*)"$/', $value)) {
            $value = substr($value, 1, -1);
        }
        
        if (!isset($_ENV[$name])) {
            $_ENV[$name] = $value;
        }
    }
}

// Load .env file
loadEnv();

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