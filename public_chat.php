<?php
// ===== public_chat.php =====
// Handles public/guest chat messages
// Include this in your public chat page

require_once 'config.php';
header('Content-Type: application/json');

session_start();

$db = db();

// Generate or get session ID for guest
if (!isset($_SESSION['guest_chat_id'])) {
    $_SESSION['guest_chat_id'] = 'guest_' . uniqid() . '_' . rand(1000, 9999);
}
$session_id = $_SESSION['guest_chat_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;
    
    // Ensure session exists
    $check = $db->prepare("SELECT id FROM chat_sessions WHERE session_id = ?");
    $check->bindValue(1, $session_id);
    $result = $check->execute();
    
    if (!$result->fetchArray()) {
        // Create new session
        $name = $_SESSION['guest_name'] ?? 'Guest';
        $email = $_SESSION['guest_email'] ?? '';
        $reason = $_SESSION['guest_reason'] ?? 'General Inquiry';
        
        $insert = $db->prepare("INSERT INTO chat_sessions 
            (session_id, guest_name, guest_email, contact_reason, status, last_activity, created_at) 
            VALUES (?, ?, ?, ?, 'active', datetime('now'), datetime('now'))");
        $insert->bindValue(1, $session_id);
        $insert->bindValue(2, $name);
        $insert->bindValue(3, $email);
        $insert->bindValue(4, $reason);
        $insert->execute();
        
        // Add welcome message
        $welcome = $db->prepare("INSERT INTO chat_messages 
            (session_id, sender_type, sender_name, message, type, is_read, created_at) 
            VALUES (?, 'system', 'System', 'Welcome! How can we help you today?', 'guest', 1, datetime('now'))");
        $welcome->bindValue(1, $session_id);
        $welcome->execute();
    }
    
    // Get messages
    if ($since_id > 0) {
        $stmt = $db->prepare("SELECT * FROM chat_messages 
                              WHERE session_id = ? 
                              AND id > ? 
                              AND sender_type != 'guest'
                              ORDER BY created_at ASC");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $since_id);
    } else {
        $stmt = $db->prepare("SELECT * FROM chat_messages 
                              WHERE session_id = ? 
                              ORDER BY created_at ASC 
                              LIMIT 50");
        $stmt->bindValue(1, $session_id);
    }
    
    $result = $stmt->execute();
    $messages = [];
    $last_id = $since_id;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['is_me'] = ($row['sender_type'] === 'guest');
        $messages[] = $row;
        $last_id = $row['id'];
    }
    
    // Mark admin messages as read
    $db->exec("UPDATE chat_messages SET is_read = 1 
              WHERE session_id = '$session_id' 
              AND sender_type = 'admin' 
              AND is_read = 0");
    
    echo json_encode([
        'messages' => $messages,
        'last_id' => $last_id,
        'session_id' => $session_id
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $message = trim($data['message'] ?? '');
    $name = $data['name'] ?? $_SESSION['guest_name'] ?? 'Guest';
    $email = $data['email'] ?? $_SESSION['guest_email'] ?? '';
    $reason = $data['reason'] ?? $_SESSION['guest_reason'] ?? 'General Inquiry';
    $temp_id = $data['temp_id'] ?? uniqid('guest_');
    
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required']);
        exit;
    }
    
    // Store guest info in session
    $_SESSION['guest_name'] = $name;
    $_SESSION['guest_email'] = $email;
    $_SESSION['guest_reason'] = $reason;
    
    $db->exec("BEGIN TRANSACTION");
    
    try {
        // Update or create session
        $check = $db->prepare("SELECT id FROM chat_sessions WHERE session_id = ?");
        $check->bindValue(1, $session_id);
        $result = $check->execute();
        
        if ($result->fetchArray()) {
            // Update existing session
            $update = $db->prepare("UPDATE chat_sessions SET 
                guest_name = ?, guest_email = ?, contact_reason = ?, 
                last_activity = datetime('now'), status = 'active'
                WHERE session_id = ?");
            $update->bindValue(1, $name);
            $update->bindValue(2, $email);
            $update->bindValue(3, $reason);
            $update->bindValue(4, $session_id);
            $update->execute();
        } else {
            // Create new session
            $insert = $db->prepare("INSERT INTO chat_sessions 
                (session_id, guest_name, guest_email, contact_reason, status, last_activity, created_at) 
                VALUES (?, ?, ?, ?, 'active', datetime('now'), datetime('now'))");
            $insert->bindValue(1, $session_id);
            $insert->bindValue(2, $name);
            $insert->bindValue(3, $email);
            $insert->bindValue(4, $reason);
            $insert->execute();
        }
        
        // Insert message
        $stmt = $db->prepare("INSERT INTO chat_messages 
            (session_id, sender_type, sender_name, message, type, is_read, created_at) 
            VALUES (?, 'guest', ?, ?, 'guest', 0, datetime('now'))");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $name);
        $stmt->bindValue(3, $message);
        $stmt->execute();
        
        $message_id = $db->lastInsertRowID();
        
        $db->exec("COMMIT");
        
        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'temp_id' => $temp_id,
            'session_id' => $session_id
        ]);
        
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>