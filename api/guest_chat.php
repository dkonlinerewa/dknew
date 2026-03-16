<?php
/**
 * Public Guest Chat API - No authentication required for guests
 * Used by index.php chat widget
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

try {
    $db = db();
    $db->enableExceptions(true);

    // Ensure tables exist
    $db->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT UNIQUE,
        guest_name TEXT,
        guest_email TEXT,
        guest_phone TEXT,
        contact_reason TEXT,
        device_id TEXT,
        status TEXT DEFAULT 'active',
        assigned_to INTEGER DEFAULT 0,
        last_activity DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT,
        sender_type TEXT,
        sender_name TEXT,
        sender_id INTEGER DEFAULT 0,
        receiver_id INTEGER DEFAULT 0,
        receiver_type TEXT,
        message TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $data = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?? [];
    }

    $action = $data['action'] ?? ($_GET['action'] ?? '');

    // ===== START CHAT SESSION =====
    if ($action === 'start_session') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        $guest_name = htmlspecialchars($data['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
        $guest_email = filter_var($data['guest_email'] ?? '', FILTER_SANITIZE_EMAIL);
        $guest_phone = preg_replace('/[^0-9+\-]/', '', $data['guest_phone'] ?? '');
        $contact_reason = htmlspecialchars($data['contact_reason'] ?? 'general_query', ENT_QUOTES, 'UTF-8');

        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }

        $stmt = $db->prepare("INSERT OR IGNORE INTO chat_sessions
            (session_id, guest_name, guest_email, guest_phone, contact_reason, status, last_activity)
            VALUES (?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $guest_name);
        $stmt->bindValue(3, $guest_email);
        $stmt->bindValue(4, $guest_phone);
        $stmt->bindValue(5, $contact_reason);
        $stmt->execute();

        // Add initial welcome message row from admin
        $welcome = "Hi $guest_name! Welcome to DK Associates Live Chat. An agent will join shortly. How can we help you today?";
        $stmt2 = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message, receiver_type) VALUES (?, 'admin', 'DK Associates', ?, 'guest')");
        $stmt2->bindValue(1, $session_id);
        $stmt2->bindValue(2, $welcome);
        $stmt2->execute();

        echo json_encode(['success' => true, 'session_id' => $session_id, 'welcome_message' => $welcome]);
        exit;
    }

    // ===== TERMINATE SESSION =====
    if ($action === 'terminate_session') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        if ($session_id) {
            $stmt = $db->prepare("UPDATE chat_sessions SET status = 'terminated', last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();

            $stmt2 = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, 'system', 'System', 'Chat session ended by guest.')");
            $stmt2->bindValue(1, $session_id);
            $stmt2->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== SEND GUEST MESSAGE =====
    if ($action === 'send_message') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        $message = htmlspecialchars(trim($data['message'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (empty($session_id) || empty($message)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID and message required']);
            exit;
        }

        // Verify session is active
        $sess = $db->querySingle("SELECT guest_name, status FROM chat_sessions WHERE session_id = '" . SQLite3::escapeString($session_id) . "'", true);
        if (!$sess || $sess['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or terminated session']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, 'guest', ?, ?)");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $sess['guest_name']);
        $stmt->bindValue(3, $message);
        $stmt->execute();

        // Update session activity
        $stmt2 = $db->prepare("UPDATE chat_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
        $stmt2->bindValue(1, $session_id);
        $stmt2->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // ===== POLL MESSAGES =====
    if ($action === 'get_messages' || isset($_GET['session_id'])) {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? $_GET['session_id'] ?? '');
        $since_id = intval($data['since_id'] ?? $_GET['since_id'] ?? 0);

        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, sender_type, sender_name, message, created_at FROM chat_messages 
            WHERE session_id = ? AND id > ? ORDER BY created_at ASC LIMIT 50");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $since_id);
        $result = $stmt->execute();

        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['time'] = date('h:i A', strtotime($row['created_at']));
            $messages[] = $row;
        }

        // Check session status
        $session = $db->querySingle("SELECT status FROM chat_sessions WHERE session_id = '" . SQLite3::escapeString($session_id) . "'", true);

        echo json_encode([
            'messages' => $messages,
            'session_status' => $session['status'] ?? 'unknown'
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
}
