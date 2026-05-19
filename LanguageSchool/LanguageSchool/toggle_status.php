<?php
session_start();
require 'config.php';

$id = $_GET['id'];

$sql = "
UPDATE users
SET status = CASE
    WHEN status='active' THEN 'blocked'
    ELSE 'active'
END
WHERE id=:id
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id'=>$id]);

header("Location: admin.php");
exit;
?>