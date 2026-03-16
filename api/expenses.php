<?php
// ===== api/expenses.php =====
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
    
    $stmt = $db->prepare("INSERT INTO expense_requests (user_id, amount, category, description, receipt_url, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $data['amount']);
    $stmt->bindValue(3, $data['category']);
    $stmt->bindValue(4, $data['description']);
    $stmt->bindValue(5, $data['receipt'] ?? '');
    $stmt->execute();
    
    // Notify manager
    $user = $db->querySingle("SELECT manager_id, full_name FROM admin_users WHERE id = $user_id", true);
    if ($user && $user['manager_id']) {
        sendNotification($user['manager_id'], 'expense', 'New Expense Request', 
                        "{$user['full_name']} submitted an expense request of ₹{$data['amount']}");
    }
    
    logActivity('expense_submitted', "Submitted expense request of ₹{$data['amount']}");
    
    echo json_encode(['success' => true]);
}
?>