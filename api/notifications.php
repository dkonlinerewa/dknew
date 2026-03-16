<?php
// ===== api/notifications.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $last_id = $_GET['last_id'] ?? 0;
    
    $stmt = $db->prepare("SELECT * FROM notifications 
                          WHERE user_id = ? AND id > ? 
                          ORDER BY created_at DESC");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $last_id);
    $result = $stmt->execute();
    
    $notifications = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notifications[] = $row;
    }
    
    echo json_encode($notifications);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['mark_read'])) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->bindValue(1, $data['id']);
        $stmt->execute();
    } elseif (isset($data['mark_all_read'])) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true]);
}