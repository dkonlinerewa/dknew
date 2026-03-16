<?php
require_once 'config.php';

$db = new SQLite3(DB_PATH);

echo "Running chat migrations...\n";

// Add new columns to chat_messages
$columns = [
    'chat_id' => 'TEXT',
    'is_terminated' => 'INTEGER DEFAULT 0',
    'temp_id' => 'TEXT',
    'welcome_sent' => 'INTEGER DEFAULT 0'
];

foreach ($columns as $col => $type) {
    try {
        $db->exec("ALTER TABLE chat_messages ADD COLUMN $col $type");
        echo "Added column $col\n";
    } catch (Exception $e) {
        echo "Column $col already exists\n";
    }
}

// Add columns to chat_sessions
$session_columns = [
    'chat_id' => 'TEXT',
    'welcome_sent' => 'INTEGER DEFAULT 0'
];

foreach ($session_columns as $col => $type) {
    try {
        $db->exec("ALTER TABLE chat_sessions ADD COLUMN $col $type");
        echo "Added column $col to chat_sessions\n";
    } catch (Exception $e) {
        echo "Column $col already exists in chat_sessions\n";
    }
}

// Update existing sessions with chat_id
$db->exec("UPDATE chat_sessions SET chat_id = session_id WHERE chat_id IS NULL OR chat_id = ''");
echo "Updated existing sessions with chat_id\n";

echo "Migration complete!\n";
?>