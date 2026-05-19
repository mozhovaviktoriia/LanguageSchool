<?php
require 'config.php';

// Alter table to make message_id nullable
$pdo->exec("
    ALTER TABLE chat_attachments
    ALTER COLUMN message_id DROP NOT NULL
");

echo "Table altered successfully!";
?>
