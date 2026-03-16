<?php
// ===== api/verify_qr.php =====
require_once '../config.php';

// Public endpoint - no authentication needed
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$qr_data = $data['data'] ?? '';

if (empty($qr_data)) {
    http_response_code(400);
    echo json_encode(['error' => 'No QR data provided']);
    exit;
}

$qr_info = json_decode($qr_data, true);
$db = db();

// Check if it's a staff ID
if (isset($qr_info['employee_id'])) {
    $stmt = $db->prepare("SELECT full_name, role, employee_id, is_active 
                          FROM admin_users WHERE employee_id = ?");
    $stmt->bindValue(1, $qr_info['employee_id']);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        echo json_encode([
            'valid' => true,
            'type' => 'staff',
            'name' => $user['full_name'],
            'role' => $user['role'],
            'id' => $user['employee_id'],
            'status' => $user['is_active'] ? 'active' : 'inactive'
        ]);
    } else {
        echo json_encode(['valid' => false, 'error' => 'Invalid ID']);
    }
}
// Check if it's a worker ID
elseif (isset($qr_info['worker_id'])) {
    $stmt = $db->prepare("SELECT name, skills, worker_id, status 
                          FROM workers WHERE worker_id = ?");
    $stmt->bindValue(1, $qr_info['worker_id']);
    $result = $stmt->execute();
    $worker = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($worker) {
        echo json_encode([
            'valid' => true,
            'type' => 'worker',
            'name' => $worker['name'],
            'skills' => $worker['skills'],
            'id' => $worker['worker_id'],
            'status' => $worker['status']
        ]);
    } else {
        echo json_encode(['valid' => false, 'error' => 'Invalid worker ID']);
    }
} else {
    echo json_encode(['valid' => false, 'error' => 'Unknown QR type']);
}