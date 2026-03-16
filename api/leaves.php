<?php
// ===== api/leaves.php =====
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
    
    $stmt = $db->prepare("INSERT INTO leaves (user_id, user_type, leave_type, start_date, end_date, reason) 
                          VALUES (?, 'staff', ?, ?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $data['type']);
    $stmt->bindValue(3, $data['start_date']);
    $stmt->bindValue(4, $data['end_date']);
    $stmt->bindValue(5, $data['reason']);
    $stmt->execute();
    
    $leave_id = $db->lastInsertRowID();
    
    // Notify manager
    $user = $db->querySingle("SELECT manager_id FROM admin_users WHERE id = $user_id", true);
    if ($user && $user['manager_id']) {
        sendNotification($user['manager_id'], 'leave', 'Leave Application', 
                        "New leave application from " . date('d/m/Y', strtotime($data['start_date'])), 
                        "admin.php?tab=ops&approval=$leave_id");
    }
    
    logActivity('leave_applied', "Applied for leave from {$data['start_date']} to {$data['end_date']}");
    
    echo json_encode(['success' => true]);
}