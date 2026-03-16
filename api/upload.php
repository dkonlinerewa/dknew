<?php
// ===== api/upload.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
// ===== api/upload.php (continued) =====
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$response = ['success' => false, 'url' => ''];

// Handle different file types
if (isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['error' => 'Invalid file type. Only JPG, PNG and GIF allowed']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['error' => 'File too large. Maximum size 5MB']);
        exit;
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'photo_' . uniqid() . '_' . time() . '.' . $extension;
    $destination = PHOTO_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $response['success'] = true;
        $response['url'] = $destination;
    }
    
} elseif (isset($_FILES['document'])) {
    $file = $_FILES['document'];
    $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['error' => 'Invalid file type. Only PDF, DOC, DOCX, JPG, PNG allowed']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['error' => 'File too large. Maximum size 10MB']);
        exit;
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'doc_' . uniqid() . '_' . time() . '.' . $extension;
    $destination = DOCUMENT_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $response['success'] = true;
        $response['url'] = $destination;
        $response['filename'] = $file['name'];
    }
    
} elseif (isset($_FILES['logo']) || isset($_FILES['favicon'])) {
    $type = isset($_FILES['logo']) ? 'logo' : 'favicon';
    $file = $_FILES[$type];
    $allowed_types = ['image/jpeg', 'image/png', 'image/svg+xml', 'image/x-icon'];
    $max_size = 2 * 1024 * 1024; // 2MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, SVG, ICO allowed']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['error' => 'File too large. Maximum size 2MB']);
        exit;
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $type . '_' . uniqid() . '.' . $extension;
    $destination = UPLOAD_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $response['success'] = true;
        $response['url'] = $destination;
        
        // Update settings in database
        $db = db();
        $key = $type === 'logo' ? 'site_logo' : 'site_favicon';
        $stmt = $db->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bindValue(1, $key);
        $stmt->bindValue(2, $destination);
        $stmt->execute();
    }
    
} elseif (isset($_FILES['file'])) {
    // Generic file upload for chat
    $file = $_FILES['file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['error' => 'File type not allowed']);
        exit;
    }
    
    if ($file['size'] > $max_size) {
        echo json_encode(['error' => 'File too large']);
        exit;
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'chat_' . uniqid() . '_' . time() . '.' . $extension;
    $destination = UPLOAD_DIR . 'chat/' . $filename;
    
    if (!file_exists(UPLOAD_DIR . 'chat/')) {
        mkdir(UPLOAD_DIR . 'chat/', 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $response['success'] = true;
        $response['url'] = $destination;
        $response['name'] = $file['name'];
        $response['size'] = $file['size'];
    }
}

echo json_encode($response);