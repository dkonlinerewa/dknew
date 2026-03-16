<?php
// ===== api/attendance.php =====
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

// Helper: Calculate Haversine distance in meters
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; // Earth radius in meters
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lng2 - $lng1);
    $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlambda/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($R * $c);
}

// Helper: Get geofence settings for a user
function getGeofenceForUser($db, $user_id) {
    // Check user-level override first
    $user = $db->querySingle("SELECT geo_override_lat, geo_override_lng, geo_override_radius FROM admin_users WHERE id = $user_id", true);
    if (!empty($user['geo_override_lat']) && !empty($user['geo_override_lng'])) {
        return [
            'lat' => (float)$user['geo_override_lat'],
            'lng' => (float)$user['geo_override_lng'],
            'radius' => (int)($user['geo_override_radius'] ?? 500),
            'source' => 'user_override'
        ];
    }
    // Fall back to global setting
    $enabled = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = 'geofence_enabled'");
    if (!$enabled || $enabled === '0') {
        return null; // Geofencing disabled globally
    }
    $lat = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = 'geofence_lat'");
    $lng = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = 'geofence_lng'");
    $radius = $db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = 'geofence_radius'");
    if (empty($lat) || empty($lng)) return null;
    return [
        'lat' => (float)$lat,
        'lng' => (float)$lng,
        'radius' => (int)($radius ?? 500),
        'source' => 'global'
    ];
}

// GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['status'])) {
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $today);
        $result = $stmt->execute();
        $record = $result->fetchArray(SQLITE3_ASSOC);
        echo json_encode(['punched_in' => !empty($record)]);

    } elseif (isset($_GET['my'])) {
        $month = date('Y-m');
        $stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? AND strftime('%Y-%m', date) = ? ORDER BY date DESC");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $month);
        $result = $stmt->execute();

        $history = '';
        $summary = ['present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $status_class = [
                'ontime' => 'text-green-600',
                'late' => 'text-yellow-600',
                'early' => 'text-red-600'
            ][$row['status']] ?? 'text-gray-600';
            $loc = json_decode($row['punch_in_location'], true);
            $loc_display = isset($loc['address']) ? $loc['address'] : ($loc['ip'] ?? 'Unknown');
            $history .= "<tr>";
            $history .= "<td class='p-2'>{$row['date']}</td>";
            $history .= "<td class='p-2'>" . date('h:i A', strtotime($row['punch_in'])) . "</td>";
            $history .= "<td class='p-2'>" . ($row['punch_out'] ? date('h:i A', strtotime($row['punch_out'])) : '—') . "</td>";
            $history .= "<td class='p-2 {$status_class}'>" . ucfirst($row['status']) . "</td>";
            $history .= "<td class='p-2'>{$loc_display}</td>";
            $history .= "</tr>";
            $summary[$row['status'] === 'ontime' ? 'present' : $row['status']]++;
        }

        echo json_encode(['summary' => $summary, 'history' => $history]);

    } elseif (isset($_GET['all']) && in_array($user_role, ['admin', 'manager'])) {
        // Admins can view all attendance for today
        $today = $_GET['date'] ?? date('Y-m-d');
        $result = $db->query("SELECT a.*, u.full_name, u.employee_code 
                              FROM attendance a 
                              JOIN admin_users u ON a.user_id = u.id 
                              WHERE a.date = '$today' ORDER BY a.punch_in ASC");
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        echo json_encode($rows);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $device_id = $data['device_id'] ?? $_SERVER['HTTP_USER_AGENT'];
    $user_lat = isset($data['location']['lat']) ? (float)$data['location']['lat'] : null;
    $user_lng = isset($data['location']['lng']) ? (float)$data['location']['lng'] : null;

    if ($action === 'in') {
        // Check if already punched in today
        $stmt = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $today);
        if ($stmt->execute()->fetchArray()) {
            echo json_encode(['error' => 'Already punched in today']);
            exit;
        }

        $stmt2 = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NOT NULL");
        $stmt2->bindValue(1, $user_id);
        $stmt2->bindValue(2, $today);
        if ($stmt2->execute()->fetchArray()) {
            echo json_encode(['error' => 'Already completed attendance for today']);
            exit;
        }

        // ===== GEOFENCE CHECK =====
        $geofence = getGeofenceForUser($db, $user_id);
        $geofence_status = 'not_checked';
        $distance = null;

        if ($geofence && $user_lat !== null && $user_lng !== null) {
            $distance = haversineDistance($user_lat, $user_lng, $geofence['lat'], $geofence['lng']);
            if ($distance > $geofence['radius']) {
                echo json_encode([
                    'error' => "You are outside the allowed area. Distance: {$distance}m, Allowed: {$geofence['radius']}m.",
                    'distance' => $distance,
                    'radius' => $geofence['radius'],
                    'geofence_failed' => true
                ]);
                exit;
            }
            $geofence_status = 'inside';
        } elseif ($geofence && ($user_lat === null || $user_lng === null)) {
            // Geofence required but no GPS provided
            if ($geofence['source'] !== 'not_checked') {
                echo json_encode([
                    'error' => 'GPS location required for attendance. Please enable location access.',
                    'gps_required' => true
                ]);
                exit;
            }
        }

        $location = json_encode([
            'lat' => $user_lat,
            'lng' => $user_lng,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'geofence_status' => $geofence_status,
            'distance_from_office' => $distance
        ]);

        // Determine if late (customizable start time, default 10:00)
        $start_hour = (int)($db->querySingle("SELECT setting_value FROM site_settings WHERE setting_key = 'work_start_hour'") ?? 10);
        $now_h = (int)date('H');
        $now_m = (int)date('i');
        $late_minutes = ($now_h > $start_hour || ($now_h === $start_hour && $now_m > 15))
            ? max(0, ($now_h - $start_hour) * 60 + $now_m)
            : 0;
        $status = $late_minutes > 15 ? 'late' : 'ontime';

        $stmt = $db->prepare("INSERT INTO attendance (user_id, punch_in, date, status, late_minutes, punch_in_location, device_id) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $now);
        $stmt->bindValue(3, $today);
        $stmt->bindValue(4, $status);
        $stmt->bindValue(5, $late_minutes);
        $stmt->bindValue(6, $location);
        $stmt->bindValue(7, $device_id);
        $stmt->execute();

        logActivity('punch_in', "Punched in at $now" . ($distance ? " | {$distance}m from office" : ""));
        echo json_encode(['success' => true, 'message' => 'Punched in successfully', 'status' => $status]);

    } elseif ($action === 'out') {
        $location = json_encode([
            'lat' => $user_lat,
            'lng' => $user_lng,
            'ip' => $_SERVER['REMOTE_ADDR']
        ]);
        $stmt = $db->prepare("UPDATE attendance SET punch_out = ?, punch_out_location = ? 
                              WHERE user_id = ? AND date = ? AND punch_out IS NULL");
        $stmt->bindValue(1, $now);
        $stmt->bindValue(2, $location);
        $stmt->bindValue(3, $user_id);
        $stmt->bindValue(4, $today);
        $stmt->execute();

        logActivity('punch_out', "Punched out at $now");
        echo json_encode(['success' => true, 'message' => 'Punched out successfully']);
    }
}
?>