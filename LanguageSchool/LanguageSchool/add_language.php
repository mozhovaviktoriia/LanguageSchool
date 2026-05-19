<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$nameUa = trim($_POST['name'] ?? '');

if ($nameUa === '') {
    echo json_encode(['success' => false, 'error' => 'empty']);
    exit;
}

try {

    // 🔥 нормальна генерація коду
    function generateCode($name) {
        $map = [
            'Французька' => 'fr',
            'Німецька'   => 'de',
            'Іспанська'  => 'es',
            'Італійська' => 'it',
            'Польська'   => 'pl',
            'Українська' => 'uk',
            'Англійська' => 'en',
            'Японська'   => 'ja'
        ];

        if (isset($map[$name])) {
            return $map[$name];
        }

        // fallback (латиниця)
        return strtolower(substr(preg_replace('/[^a-z]/i', '', $name), 0, 2)) ?: 'xx';
    }

    $code = generateCode($nameUa);

    // перевірка дубля
    $check = $pdo->prepare("
        SELECT id FROM languages WHERE LOWER(name_ua) = LOWER(:name)
    ");
    $check->execute(['name' => $nameUa]);

    if ($check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'exists']);
        exit;
    }

    // вставка
    $stmt = $pdo->prepare("
        INSERT INTO languages (code, name_ua, name_en, flag_url)
        VALUES (:code, :ua, :en, :flag)
        RETURNING id
    ");

    $stmt->execute([
        'code' => $code,
        'ua'   => $nameUa,
        'en'   => $nameUa,
        'flag' => null
    ]);

    $id = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'id' => $id,
        'name' => $nameUa
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}