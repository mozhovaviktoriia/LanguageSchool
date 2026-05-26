<?php
session_start();
require 'config.php';
require 'vendor/autoload.php';

use League\OAuth2\Client\Provider\Google;

define('GOOGLE_CLIENT_ID',     $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('REDIRECT_URI',         $_ENV['REDIRECT_URI'] ?? '');

if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    die('OAuth configuration is missing. Please check your .env file.');
}

$provider = new Google([
    'clientId'     => GOOGLE_CLIENT_ID,
    'clientSecret' => GOOGLE_CLIENT_SECRET,
    'redirectUri'  => REDIRECT_URI,
]);

// Крок 1 — редирект до Google (немає code в URL)
if (!isset($_GET['code'])) {
    $authUrl = $provider->getAuthorizationUrl(['scope' => ['email', 'profile']]);
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
    exit;
}

// Захист від CSRF
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth2state'] ?? '')) {
    unset($_SESSION['oauth2state']);
    header('Location: login.php?error=state');
    exit;
}
unset($_SESSION['oauth2state']);

try {
    // Отримуємо токен і дані від Google
    $token      = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
    $googleUser = $provider->getResourceOwner($token);
    $data       = $googleUser->toArray();

    $email     = $data['email'] ?? '';
    $firstName = $data['given_name'] ?? '';
    $lastName  = $data['family_name'] ?? '';

    if (!$email) {
        header('Location: login.php?error=google_no_email');
        exit;
    }

    // Перевіряємо чи є юзер в БД
    $stmt = $pdo->prepare("SELECT id, first_name, role, status FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Юзер існує — перевіряємо статус
        if ($user['status'] === 'pending') {
            header('Location: login.php?error=pending');
            exit;
        }
        if ($user['status'] === 'inactive') {
            header('Location: login.php?error=inactive');
            exit;
        }
        // Логінимо
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name']    = $user['first_name'];
        $_SESSION['role']    = $user['role'];

    } else {
        // Новий юзер — зберігаємо в сесії, відправляємо вибрати курс
        $_SESSION['google_email']      = $email;
        $_SESSION['google_first_name'] = $firstName;
        $_SESSION['google_last_name']  = $lastName;
        header('Location: register.php?via=google');
        exit;
    }

    // Редирект за роллю
    switch ($_SESSION['role']) {
        case 'student': header('Location: dashboard_student.php'); break;
        case 'teacher': header('Location: dashboard_teacher.php'); break;
        case 'admin':   header('Location: admin.php');             break;
        default:        header('Location: index.php');
    }
    exit;

} catch (Exception $e) {
    die('Помилка: ' . $e->getMessage());
}