<?php
// ===== api/workers.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user_role === 'admin') {
        $result = $db->query("SELECT * FROM workers ORDER BY created_at DESC");
    } else {
        $stmt = $db->prepare("SELECT * FROM workers WHERE reporting_head = ? ORDER BY created_at DESC");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
    }
    
    $workers = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Generate worker ID if not exists
        if (empty($row['worker_id'])) {
            $worker_id = 'WRK' . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
            $db->exec("UPDATE workers SET worker_id = '$worker_id' WHERE id = {$row['id']}");
            $row['worker_id'] = $worker_id;
        }
        
        $workers[] = $row;
    }
    
    echo json_encode($workers);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Generate worker ID
    $stmt = $db->prepare("SELECT MAX(id) as max_id FROM workers");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $next_id = ($row['max_id'] ?? 0) + 1;
    $worker_id = 'WRK' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
    
    // Generate QR code data
    $qr_data = json_encode([
        'id' => $worker_id,
        'name' => $data['name'],
        'phone' => $data['phone']
    ]);
    
    // Save QR code as image
    $qr_filename = QR_DIR . 'worker_' . $worker_id . '.png';
    // In production, use a QR code library like phpqrcode
    // For now, we'll store the data to generate later
    
    $stmt = $db->prepare("INSERT INTO workers 
        (worker_id, name, father_name, dob, gender, phone, email, address, skills, experience, qualification, status, reporting_head) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bindValue(1, $worker_id);
    $stmt->bindValue(2, $data['name']);
    $stmt->bindValue(3, $data['father_name'] ?? '');
    $stmt->bindValue(4, $data['dob'] ?? '');
    $stmt->bindValue(5, $data['gender'] ?? '');
    $stmt->bindValue(6, $data['phone']);
    $stmt->bindValue(7, $data['email'] ?? '');
    $stmt->bindValue(8, $data['address'] ?? '');
    $stmt->bindValue(9, $data['skills'] ?? '');
    $stmt->bindValue(10, $data['experience'] ?? '');
    $stmt->bindValue(11, $data['qualification'] ?? '');
    $stmt->bindValue(12, $data['status'] ?? 'active');
    $stmt->bindValue(13, $data['reporting_head'] ?? 0);
    
    $stmt->execute();
    
    logActivity('worker_added', "Added worker: {$data['name']}");
    
    echo json_encode(['success' => true, 'worker_id' => $worker_id]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($_GET['id'] ?? 0);

    if (!$id) {
        echo json_encode(['error' => 'Worker ID required']); exit;
    }

    if (in_array($user_role, ['admin', 'manager'])) {
        // Admin/Manager: apply changes directly
        $stmt = $db->prepare("UPDATE workers SET 
            name = ?, father_name = ?, dob = ?, gender = ?, phone = ?, email = ?, 
            address = ?, skills = ?, experience = ?, qualification = ?, status = ?, reporting_head = ? 
            WHERE id = ?");
        $stmt->bindValue(1,  $data['name']);
        $stmt->bindValue(2,  $data['father_name']  ?? '');
        $stmt->bindValue(3,  $data['dob']           ?? '');
        $stmt->bindValue(4,  $data['gender']        ?? '');
        $stmt->bindValue(5,  $data['phone']);
        $stmt->bindValue(6,  $data['email']         ?? '');
        $stmt->bindValue(7,  $data['address']       ?? '');
        $stmt->bindValue(8,  $data['skills']        ?? '');
        $stmt->bindValue(9,  $data['experience']    ?? '');
        $stmt->bindValue(10, $data['qualification'] ?? '');
        $stmt->bindValue(11, $data['status']        ?? 'active');
        $stmt->bindValue(12, $data['reporting_head'] ?? 0);
        $stmt->bindValue(13, $id);
        $stmt->execute();
        logActivity('worker_updated', "Updated worker ID: $id");
        echo json_encode(['success' => true]);

    } else {
        // Staff/Lead: route through pending_changes for approval
        $existing = $db->querySingle("SELECT * FROM workers WHERE id = $id", true);
        
        // Diff: only include fields that actually changed
        $fields_to_compare = ['name','father_name','dob','gender','phone','email','address','skills','experience','qualification','status'];
        $changes = [];
        foreach ($fields_to_compare as $f) {
            $new_val = $data[$f] ?? '';
            if ($existing[$f] !== $new_val) {
                $changes[$f] = ['old' => $existing[$f], 'new' => $new_val];
            }
        }

        if (empty($changes)) {
            echo json_encode(['success' => true, 'message' => 'No changes detected']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO pending_changes 
            (table_name, record_id, field_changes, requested_by, status) 
            VALUES ('workers', ?, ?, ?, 'pending')");
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, json_encode($changes));
        $stmt->bindValue(3, $user_id);
        $stmt->execute();

        // Notify reporting head / manager
        $user = $db->querySingle("SELECT reporting_head, full_name FROM admin_users WHERE id = $user_id", true);
        if (!empty($user['reporting_head'])) {
            sendNotification(
                $user['reporting_head'],
                'pending_edit',
                'Worker Edit Pending Approval',
                "{$user['full_name']} submitted a worker edit request for worker ID $id that requires your approval."
            );
        }

        logActivity('worker_edit_requested', "Worker ID $id edit submitted for approval by $user_id");
        echo json_encode([
            'success' => true,
            'pending' => true,
            'message' => 'Your changes have been submitted for approval by your manager.'
        ]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array($user_role, ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Only admins/managers can delete workers']);
        exit;
    }
    $id = intval($_GET['id'] ?? 0);
    $db->exec("UPDATE workers SET status = 'inactive' WHERE id = $id");
    logActivity('worker_deleted', "Deactivated worker ID: $id");
    echo json_encode(['success' => true]);
}