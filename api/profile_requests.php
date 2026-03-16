<?php
// ===== api/profile_requests.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user = $db->querySingle("SELECT * FROM admin_users WHERE id = $user_id", true);

// GET: fetch own pending requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT * FROM profile_update_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->bindValue(1, $user_id);
    $rows = [];
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    echo json_encode($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $field    = '';
    $old_data = '';
    $new_data = '';

    // Handle photo upload (multipart/form-data)
    if (isset($_FILES['photo'])) {
        $file = $_FILES['photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['error' => 'Invalid image type. Only JPG, PNG, GIF allowed']); exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['error' => 'File too large. Max 5MB']); exit;
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
        $destination = PHOTO_DIR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            echo json_encode(['error' => 'Failed to save uploaded photo']); exit;
        }
        $field    = 'photo_url';
        $old_data = $user['photo_url'] ?? '';
        $new_data = $destination;
    } else {
        // Text field change request
        $data     = json_decode(file_get_contents('php://input'), true);
        $field    = $data['field']     ?? '';
        $new_data = $data['new_value'] ?? '';
        $old_data = $user[$field]      ?? '';
    }

    if (empty($field) || empty($new_data)) {
        echo json_encode(['error' => 'Field and new value are required']); exit;
    }

    $stmt = $db->prepare("INSERT INTO profile_update_requests (user_id, request_type, old_data, new_data, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $field);
    $stmt->bindValue(3, $old_data);
    $stmt->bindValue(4, $new_data);
    $stmt->execute();

    // Notify reporting head
    if (!empty($user['reporting_head']) && (int)$user['reporting_head'] > 0) {
        sendNotification(
            $user['reporting_head'],
            'profile_update',
            'Profile Update Request',
            "{$user['full_name']} has requested to update their {$field}."
        );
    }

    logActivity('profile_update_requested', "Requested to update $field");
    echo json_encode(['success' => true]);
}
?>