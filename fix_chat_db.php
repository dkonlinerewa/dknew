<?php
// ===== fix_chat_db.php =====
// Run this once to fix chat database schema
// Access: http://yoursite.com/fix_chat_db.php

require_once 'config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Chat Database</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .success { background: #d4edda; color: #155724; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 5px; }
        code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔧 Chat System Database Fix</h1>";

try {
    $db = new SQLite3(DB_PATH);
    
    // Check and fix chat_messages table
    echo "<div class='info'>Checking chat_messages table...</div>";
    
    $columns = $db->query("PRAGMA table_info(chat_messages)");
    $existing = [];
    while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
        $existing[$col['name']] = $col;
    }
    
    // Required columns for chat_messages
    $required = [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'session_id' => 'TEXT',
        'sender_type' => 'TEXT',
        'sender_name' => 'TEXT',
        'sender_id' => 'INTEGER DEFAULT 0',
        'receiver_id' => 'INTEGER DEFAULT 0',
        'receiver_type' => 'TEXT',
        'message' => 'TEXT',
        'type' => 'TEXT DEFAULT \'guest\'',
        'attachments' => 'TEXT',
        'is_read' => 'INTEGER DEFAULT 0',
        'is_delivered' => 'INTEGER DEFAULT 0',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
    ];
    
    foreach ($required as $col => $def) {
        if (!isset($existing[$col])) {
            try {
                $db->exec("ALTER TABLE chat_messages ADD COLUMN $col $def");
                echo "<div class='success'>✓ Added column: $col</div>";
            } catch (Exception $e) {
                echo "<div class='error'>✗ Failed to add $col: " . $e->getMessage() . "</div>";
            }
        }
    }
    
    // Check and fix chat_sessions table
    echo "<div class='info'>Checking chat_sessions table...</div>";
    
    $columns = $db->query("PRAGMA table_info(chat_sessions)");
    $existing = [];
    while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
        $existing[$col['name']] = $col;
    }
    
    // Required columns for chat_sessions
    $required_sessions = [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'session_id' => 'TEXT UNIQUE',
        'guest_name' => 'TEXT',
        'guest_email' => 'TEXT',
        'guest_phone' => 'TEXT',
        'contact_reason' => 'TEXT',
        'device_id' => 'TEXT',
        'status' => 'TEXT DEFAULT \'active\'',
        'assigned_to' => 'INTEGER DEFAULT 0',
        'last_activity' => 'DATETIME',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
    ];
    
    foreach ($required_sessions as $col => $def) {
        if (!isset($existing[$col])) {
            try {
                $db->exec("ALTER TABLE chat_sessions ADD COLUMN $col $def");
                echo "<div class='success'>✓ Added column: $col to chat_sessions</div>";
            } catch (Exception $e) {
                echo "<div class='error'>✗ Failed to add $col: " . $e->getMessage() . "</div>";
            }
        }
    }
    
    // Create indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_chat_session ON chat_messages(session_id)",
        "CREATE INDEX IF NOT EXISTS idx_chat_created ON chat_messages(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_chat_read ON chat_messages(is_read)",
        "CREATE INDEX IF NOT EXISTS idx_chat_type ON chat_messages(type)",
        "CREATE INDEX IF NOT EXISTS idx_sessions_status ON chat_sessions(status)",
        "CREATE INDEX IF NOT EXISTS idx_sessions_activity ON chat_sessions(last_activity)"
    ];
    
    foreach ($indexes as $sql) {
        try {
            $db->exec($sql);
            echo "<div class='success'>✓ Created index</div>";
        } catch (Exception $e) {
            echo "<div class='error'>✗ Failed to create index: " . $e->getMessage() . "</div>";
        }
    }
    
    // Add sample welcome message for testing
    echo "<div class='info'>Adding test session...</div>";
    
    // Create a test session
    $test_session = 'test_' . uniqid();
    $stmt = $db->prepare("INSERT OR IGNORE INTO chat_sessions 
        (session_id, guest_name, guest_email, contact_reason, status, last_activity) 
        VALUES (?, 'Test Guest', 'test@example.com', 'Testing', 'active', datetime('now'))");
    $stmt->bindValue(1, $test_session);
    $stmt->execute();
    
    // Add a welcome message
    $stmt = $db->prepare("INSERT INTO chat_messages 
        (session_id, sender_type, sender_name, message, type, is_read, created_at) 
        VALUES (?, 'system', 'System', 'Welcome! This is a test message. The chat system is now fixed.', 'guest', 1, datetime('now'))");
    $stmt->bindValue(1, $test_session);
    $stmt->execute();
    
    echo "<div class='success'>✓ Added test session: $test_session</div>";
    echo "<div class='success'>✓ Added test welcome message</div>";
    
    // Count messages
    $count = $db->querySingle("SELECT COUNT(*) FROM chat_messages");
    $sessions = $db->querySingle("SELECT COUNT(*) FROM chat_sessions");
    
    echo "<div class='info'>";
    echo "<h3>Current Stats:</h3>";
    echo "<p>Total Messages: $count</p>";
    echo "<p>Total Sessions: $sessions</p>";
    echo "</div>";
    
    echo "<div class='success' style='font-size: 18px;'>";
    echo "<h2>✅ Database Fix Complete!</h2>";
    echo "<p>Please test the chat system now.</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}

echo "<p><a href='admin.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Admin Panel</a></p>";
echo "</body></html>";

// Option to delete this script
if (isset($_GET['delete'])) {
    unlink(__FILE__);
}
?>