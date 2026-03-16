<?php
// ===== api/settings.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query("SELECT * FROM site_settings");
    $settings = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo json_encode($settings);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $section = $data['section'] ?? '';
    $settings = $data['data'] ?? [];
    
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bindValue(1, $key);
        $stmt->bindValue(2, $value);
        $stmt->execute();
    }
    
    logActivity('settings_updated', "Updated $section settings");
    
    echo json_encode(['success' => true]);
}