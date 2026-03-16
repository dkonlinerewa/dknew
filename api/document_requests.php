<?php
// ===== api/document_requests.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO document_requests (user_id, document_type, reason, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $data['type']);
    $stmt->bindValue(3, $data['reason']);
    $stmt->execute();
    
    // Notify manager
    $user = $db->querySingle("SELECT manager_id, full_name FROM admin_users WHERE id = $user_id", true);
    if ($user && $user['manager_id']) {
        sendNotification($user['manager_id'], 'document', 'New Document Request', 
                        "{$user['full_name']} requested a {$data['type']}");
    }
    
    logActivity('document_requested', "Requested document: {$data['type']}");
    
    echo json_encode(['success' => true]);
}
?>