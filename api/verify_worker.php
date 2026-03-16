<?php
// ===== api/verify_worker.php =====
require_once '../config.php';

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

// Check if it's a worker ID
if (isset($qr_info['worker_code'])) {
    $stmt = $db->prepare("SELECT name, skills, worker_code, status, supervisor, blood_group, photo_url 
                          FROM workers WHERE worker_code = ?");
    $stmt->bindValue(1, $qr_info['worker_code']);
    $result = $stmt->execute();
    $worker = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($worker) {
        echo json_encode([
            'valid' => true,
            'type' => 'worker',
            'name' => $worker['name'],
            'skills' => $worker['skills'],
            'code' => $worker['worker_code'],
            'status' => $worker['status'],
            'supervisor' => $worker['supervisor'],
            'blood_group' => $worker['blood_group'],
            'photo_url' => $worker['photo_url']
        ]);
    } else {
        echo json_encode(['valid' => false, 'error' => 'Invalid worker code']);
    }
} 
// Check if it's a staff ID
elseif (isset($qr_info['employee_code'])) {
    $stmt = $db->prepare("SELECT full_name, role, employee_code, is_active, reporting_office, blood_group, photo_url 
                          FROM admin_users WHERE employee_code = ?");
    $stmt->bindValue(1, $qr_info['employee_code']);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        echo json_encode([
            'valid' => true,
            'type' => 'staff',
            'name' => $user['full_name'],
            'role' => $user['role'],
            'code' => $user['employee_code'],
            'status' => $user['is_active'] ? 'active' : 'inactive',
            'reporting_office' => $user['reporting_office'],
            'blood_group' => $user['blood_group'],
            'photo_url' => $user['photo_url']
        ]);
    } else {
        echo json_encode(['valid' => false, 'error' => 'Invalid employee code']);
    }
} else {
    echo json_encode(['valid' => false, 'error' => 'Invalid QR code format']);
}
?>