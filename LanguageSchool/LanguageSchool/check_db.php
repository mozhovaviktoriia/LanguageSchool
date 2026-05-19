<?php
require 'config.php';

// Check if chat_attachments table exists
$result = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public'
");
echo "Tables:\n";
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    echo "  - " . $row['table_name'] . "\n";
}

// Check columns in chat_attachments
echo "\nchat_attachments columns:\n";
$result = $pdo->query("
    SELECT column_name, data_type, is_nullable
    FROM information_schema.columns
    WHERE table_name = 'chat_attachments'
    ORDER BY ordinal_position
");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    echo "  - " . $row['column_name'] . " (" . $row['data_type'] . ")" . ($row['is_nullable'] === 'YES' ? ' nullable' : ' NOT NULL') . "\n";
}
?>
