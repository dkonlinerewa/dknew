<?php
// ===== api/geofence.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'global';

    if ($action === 'global') {
        $keys = ['geofence_enabled', 'geofence_lat', 'geofence_lng', 'geofence_radius', 'geofence_address'];
        $settings = [];
        foreach ($keys as $key) {
            $val = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = '$key'");
            $settings[$key] = $val;
        }
        echo json_encode($settings);

    } elseif ($action === 'user_override') {
        $user_id = intval($_GET['user_id'] ?? 0);
        if (!$user_id) { echo json_encode(['error' => 'user_id required']); exit; }
        $row = $db->querySingle("SELECT geo_override_lat, geo_override_lng, geo_override_radius FROM admin_users WHERE id = $user_id", true);
        echo json_encode($row);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'save_global';

    if ($action === 'save_global') {
        $upsert = function($key, $val, $type = 'text') use ($db) {
            $val = SQLite3::escapeString($val);
            $db->exec("INSERT OR REPLACE INTO site_settings (setting_key, setting_value, setting_type) VALUES ('$key', '$val', '$type')");
        };
        $upsert('geofence_enabled', $data['enabled'] ? '1' : '0', 'boolean');
        $upsert('geofence_lat',     $data['lat'] ?? '', 'text');
        $upsert('geofence_lng',     $data['lng'] ?? '', 'text');
        $upsert('geofence_radius',  $data['radius'] ?? '500', 'number');
        $upsert('geofence_address', $data['address'] ?? '', 'text');
        logActivity('geofence_updated', 'Updated global geofence settings');
        echo json_encode(['success' => true]);

    } elseif ($action === 'save_user_override') {
        $user_id = intval($data['user_id'] ?? 0);
        if (!$user_id) { echo json_encode(['error' => 'user_id required']); exit; }
        $lat    = !empty($data['lat'])    ? (float)$data['lat'] : 'NULL';
        $lng    = !empty($data['lng'])    ? (float)$data['lng'] : 'NULL';
        $radius = !empty($data['radius']) ? (int)$data['radius'] : 'NULL';
        $db->exec("UPDATE admin_users SET geo_override_lat = $lat, geo_override_lng = $lng, geo_override_radius = $radius WHERE id = $user_id");
        logActivity('geofence_user_override', "Set geofence override for user {$user_id}");
        echo json_encode(['success' => true]);

    } elseif ($action === 'clear_user_override') {
        $user_id = intval($data['user_id'] ?? 0);
        if (!$user_id) { echo json_encode(['error' => 'user_id required']); exit; }
        $db->exec("UPDATE admin_users SET geo_override_lat = NULL, geo_override_lng = NULL, geo_override_radius = NULL WHERE id = $user_id");
        logActivity('geofence_user_override_cleared', "Cleared geofence override for user {$user_id}");
        echo json_encode(['success' => true]);
    }
}
?>
