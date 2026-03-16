<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    die();
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

// Get user details
$user = $db->querySingle("SELECT full_name, role, team_id, care_permission FROM admin_users WHERE id = $user_id", true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'guest';
    $session_id = $_GET['session_id'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

    // Unread count poll
    if (isset($_GET['unread'])) {
        $count = $db->querySingle("SELECT COUNT(*) FROM chat_messages 
            WHERE receiver_type = 'admin' 
            AND is_read = 0 
            AND sender_id != $user_id");
        echo json_encode(['count' => (int)$count]);
        exit;
    }
    
    // Get active guest sessions
    if ($type === 'guest_sessions') {
        $stmt = $db->prepare("SELECT cs.*, 
                              (SELECT COUNT(*) FROM chat_messages 
                               WHERE session_id = cs.session_id 
                               AND is_read = 0 
                               AND sender_type = 'guest') as unread_count,
                              (SELECT COUNT(*) FROM chat_messages 
                               WHERE session_id = cs.session_id) as total_messages,
                              (SELECT message FROM chat_messages 
                               WHERE session_id = cs.session_id 
                               ORDER BY created_at DESC LIMIT 1) as last_message,
                              (SELECT created_at FROM chat_messages 
                               WHERE session_id = cs.session_id 
                               ORDER BY created_at DESC LIMIT 1) as last_message_time
                              FROM chat_sessions cs 
                              WHERE cs.status = 'active' 
                              ORDER BY cs.last_activity DESC");
        $result = $stmt->execute();
        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['last_activity_formatted'] = $row['last_activity'] ? date('h:i A', strtotime($row['last_activity'])) : '';
            $row['last_message_time_formatted'] = $row['last_message_time'] ? date('h:i A', strtotime($row['last_message_time'])) : '';
            $sessions[] = $row;
        }
        echo json_encode($sessions);
        exit;
    }
    
    // Get all guest sessions
    if ($type === 'all_guest_sessions') {
        $stmt = $db->prepare("SELECT cs.*, 
                              (SELECT COUNT(*) FROM chat_messages 
                               WHERE session_id = cs.session_id 
                               AND is_read = 0 
                               AND sender_type = 'guest') as unread_count,
                              (SELECT message FROM chat_messages 
                               WHERE session_id = cs.session_id 
                               ORDER BY created_at DESC LIMIT 1) as last_message
                              FROM chat_sessions cs 
                              ORDER BY cs.last_activity DESC 
                              LIMIT 100");
        $result = $stmt->execute();
        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['last_activity_formatted'] = $row['last_activity'] ? date('h:i A', strtotime($row['last_activity'])) : '';
            $sessions[] = $row;
        }
        echo json_encode($sessions);
        exit;
    }
    
    // Get messages for guest session
    if ($type === 'guest' && $session_id) {
        // Mark messages as read
        $db->exec("UPDATE chat_messages SET is_read = 1 
                  WHERE session_id = '$session_id' 
                  AND sender_type = 'guest' 
                  AND is_read = 0");
        
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE session_id = ? 
                                  AND id > ? 
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $session_id);
            $stmt->bindValue(2, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE session_id = ? 
                                  ORDER BY created_at ASC 
                                  LIMIT ?");
            $stmt->bindValue(1, $session_id);
            $stmt->bindValue(2, $limit);
        }
    }
    // Staff messages
    elseif ($type === 'staff') {
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE ((receiver_id = ? AND sender_type = 'admin') 
                                  OR (sender_id = ? AND receiver_type = 'staff')) 
                                  AND id > ? 
                                  AND type = 'staff' 
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $user_id);
            $stmt->bindValue(3, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE ((receiver_id = ? AND sender_type = 'admin') 
                                  OR (sender_id = ? AND receiver_type = 'staff')) 
                                  AND type = 'staff' 
                                  ORDER BY created_at ASC 
                                  LIMIT ?");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $user_id);
            $stmt->bindValue(3, $limit);
        }
    }
    // Team messages
    elseif ($type === 'team') {
        $team_id = $user['team_id'] ?? 0;
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE type = 'team' 
                                  AND (receiver_id = ? OR receiver_id = 0) 
                                  AND id > ? 
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $team_id);
            $stmt->bindValue(2, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE type = 'team' 
                                  AND (receiver_id = ? OR receiver_id = 0) 
                                  ORDER BY created_at ASC 
                                  LIMIT ?");
            $stmt->bindValue(1, $team_id);
            $stmt->bindValue(2, $limit);
        }
    }
    // Broadcast messages
    elseif ($type === 'broadcast') {
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE type = 'broadcast' 
                                  AND id > ? 
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages 
                                  WHERE type = 'broadcast' 
                                  ORDER BY created_at DESC 
                                  LIMIT ?");
            $stmt->bindValue(2, $limit);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }
    
    $result = $stmt->execute();
    $messages = [];
    $last_id = $since_id;
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['date'] = date('M d, Y', strtotime($row['created_at']));
        $row['is_admin'] = ($row['sender_type'] === 'admin');
        $messages[] = $row;
        $last_id = $row['id'];
    }
    
    if ($since_id > 0) {
        echo json_encode([
            'messages' => $messages,
            'last_id' => $last_id,
            'count' => count($messages)
        ]);
    } else {
        echo json_encode($messages);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'send';
    
    // Handle session termination
    if ($action === 'terminate') {
        $session_id = $data['session_id'] ?? '';
        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }
        
        $db->exec("BEGIN TRANSACTION");
        
        try {
            // Update session status
            $stmt = $db->prepare("UPDATE chat_sessions SET status = 'terminated', last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();
            
            // Add system message
            $stmt = $db->prepare("INSERT INTO chat_messages 
                (session_id, sender_type, sender_name, message, type, is_read, created_at) 
                VALUES (?, 'system', 'System', 'This conversation has ended. Thank you for chatting!', 'guest', 1, datetime('now'))");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();
            
            $db->exec("COMMIT");
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->exec("ROLLBACK");
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle sending message
    $message = trim($data['message'] ?? '');
    $type = $data['type'] ?? 'guest';
    $session_id = $data['session_id'] ?? '';
    $receiver_id = intval($data['receiver_id'] ?? 0);
    $temp_id = $data['temp_id'] ?? uniqid('msg_');
    
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required', 'temp_id' => $temp_id]);
        exit;
    }
    
    // Only admins can broadcast
    if ($type === 'broadcast' && $user_role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Only admins can broadcast', 'temp_id' => $temp_id]);
        exit;
    }
    
    // Validate session for guest chat
    if ($type === 'guest' && empty($session_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Session ID required', 'temp_id' => $temp_id]);
        exit;
    }
    
    $db->exec("BEGIN TRANSACTION");
    
    try {
        // Check if session exists and is active
        if ($type === 'guest') {
            $check = $db->prepare("SELECT status FROM chat_sessions WHERE session_id = ?");
            $check->bindValue(1, $session_id);
            $result = $check->execute();
            $session = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$session) {
                // Create session if it doesn't exist
                $create = $db->prepare("INSERT INTO chat_sessions (session_id, status, last_activity, created_at) VALUES (?, 'active', datetime('now'), datetime('now'))");
                $create->bindValue(1, $session_id);
                $create->execute();
            } elseif ($session['status'] !== 'active') {
                // Reactivate if terminated
                $update = $db->prepare("UPDATE chat_sessions SET status = 'active', last_activity = datetime('now') WHERE session_id = ?");
                $update->bindValue(1, $session_id);
                $update->execute();
            }
        }
        
        // Insert message
        $stmt = $db->prepare("INSERT INTO chat_messages 
            (session_id, sender_id, sender_type, sender_name, message, type, receiver_id, is_read, created_at) 
            VALUES (?, ?, 'admin', ?, ?, ?, ?, 0, datetime('now'))");
        
        $stmt->bindValue(1, $session_id ?: null);
        $stmt->bindValue(2, $user_id);
        $stmt->bindValue(3, $user['full_name']);
        $stmt->bindValue(4, $message);
        $stmt->bindValue(5, $type);
        $stmt->bindValue(6, $receiver_id);
        
        $stmt->execute();
        $message_id = $db->lastInsertRowID();
        
        // Update session activity
        if ($type === 'guest' && $session_id) {
            $update = $db->prepare("UPDATE chat_sessions SET last_activity = datetime('now') WHERE session_id = ?");
            $update->bindValue(1, $session_id);
            $update->execute();
        }
        
        $db->exec("COMMIT");
        
        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'temp_id' => $temp_id,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'temp_id' => $temp_id]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Mark messages as read
    $data = json_decode(file_get_contents('php://input'), true);
    $session_id = $data['session_id'] ?? '';
    
    if ($session_id) {
        $stmt = $db->prepare("UPDATE chat_messages SET is_read = 1 
                              WHERE session_id = ? AND sender_type = 'guest' AND is_read = 0");
        $stmt->bindValue(1, $session_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Session ID required']);
    }
}
?>