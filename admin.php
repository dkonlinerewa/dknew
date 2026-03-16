<?php
// ===== admin.php - Comprehensive Admin Panel =====
session_start();
require_once 'config.php';
// ===== GLOBAL API ROUTER =====
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    if ($action !== '' && function_exists('api_' . $action)) {
        call_user_func('api_' . $action);
        die();
    }
}

// ===== INITIALIZATION & AUTHENTICATION =====


function api_activity() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: text/html');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit;
}

$db = db();

// Get recent activities
$result = $db->query("SELECT a.*, u.full_name as user_name
                      FROM activity_log a
                      LEFT JOIN admin_users u ON a.user_id = u.id
                      ORDER BY a.created_at DESC
                      LIMIT 10");

$html = '';
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $time = date('H:i', strtotime($row['created_at']));
    $date = date('d/m', strtotime($row['created_at']));

    $icons = [
        'login' => 'fa-sign-in-alt text-green-600',
        'logout' => 'fa-sign-out-alt text-red-600',
        'task_created' => 'fa-tasks text-blue-600',
        'task_completed' => 'fa-check-circle text-green-600',
        'quote_created' => 'fa-file-invoice text-purple-600',
        'worker_added' => 'fa-user-plus text-blue-600',
        'settings_updated' => 'fa-cog text-yellow-600'
    ];

    $icon = $icons[$row['action']] ?? 'fa-circle text-gray-400';

    $html .= "<div class='flex items-start space-x-2'>";
    $html .= "<i class='fas $icon mt-1'></i>";
    $html .= "<div class='flex-1'>";
    $html .= "<p class='text-sm'><span class='font-medium'>{$row['user_name']}</span> {$row['details']}</p>";
    $html .= "<p class='text-xs text-gray-400'>$date at $time</p>";
    $html .= "</div>";
    $html .= "</div>";
}

echo $html;
}

function api_analytics() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit;
}

$db = db();
$period = $_GET['period'] ?? 'month';

// Tasks completion trend
$tasks_data = $db->query("
    SELECT
        strftime('%Y-%m-%d', created_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM tasks
    WHERE created_at >= date('now', '-30 days')
    GROUP BY date
    ORDER BY date
");

$tasks_labels = [];
$tasks_completed = [];
$tasks_created = [];

while ($row = $tasks_data->fetchArray(SQLITE3_ASSOC)) {
    $tasks_labels[] = $row['date'];
    $tasks_completed[] = (int)$row['completed'];
    $tasks_created[] = (int)$row['total'];
}

// Applications by status
$apps_status = $db->query("
    SELECT status, COUNT(*) as count
    FROM applications
    GROUP BY status
");

$apps_labels = [];
$apps_counts = [];

while ($row = $apps_status->fetchArray(SQLITE3_ASSOC)) {
    $apps_labels[] = ucfirst($row['status']);
    $apps_counts[] = (int)$row['count'];
}

// Revenue data (from quotations)
$revenue = $db->query("
    SELECT
        strftime('%Y-%m', created_at) as month,
        SUM(total) as revenue
    FROM quotations
    WHERE status = 'accepted'
    AND created_at >= date('now', '-12 months')
    GROUP BY month
    ORDER BY month
");

$revenue_labels = [];
$revenue_amounts = [];

while ($row = $revenue->fetchArray(SQLITE3_ASSOC)) {
    $revenue_labels[] = $row['month'];
    $revenue_amounts[] = (float)$row['revenue'];
}

// Attendance summary
$attendance = $db->query("
    SELECT
        status,
        COUNT(*) as count
    FROM attendance
    WHERE date >= date('now', '-30 days')
    GROUP BY status
");

$attendance_labels = [];
$attendance_counts = [];

while ($row = $attendance->fetchArray(SQLITE3_ASSOC)) {
    $attendance_labels[] = ucfirst($row['status']);
    $attendance_counts[] = (int)$row['count'];
}

echo json_encode([
    'tasks' => [
        'labels' => $tasks_labels,
        'created' => $tasks_created,
        'completed' => $tasks_completed
    ],
    'applications' => [
        'labels' => $apps_labels,
        'data' => $apps_counts
    ],
    'revenue' => [
        'labels' => $revenue_labels,
        'data' => $revenue_amounts
    ],
    'attendance' => [
        'labels' => $attendance_labels,
        'data' => $attendance_counts
    ]
]);
}

function api_approvals() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role = $_SESSION['admin_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'all';

    if ($type === 'pending_changes') {
        if (!in_array($role, ['admin', 'manager'])) {
            echo json_encode(['error' => 'Forbidden']); exit;
        }
        $result = $db->query("SELECT pc.*, u.full_name as requester_name, u.role as requester_role
            FROM pending_changes pc
            JOIN admin_users u ON pc.requested_by = u.id
            WHERE pc.status = 'pending'
            ORDER BY pc.created_at DESC");
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['changes'] = json_decode($row['field_changes'], true);
            if ($row['table_name'] === 'workers') {
                $rec = $db->querySingle("SELECT name FROM workers WHERE id = {$row['record_id']}", true);
                $row['record_label'] = $rec['name'] ?? "Worker #{$row['record_id']}";
            } else {
                $row['record_label'] = "{$row['table_name']} #{$row['record_id']}";
            }
            $rows[] = $row;
        }
        echo json_encode($rows);

    } elseif (isset($_GET['pending'])) {
        $approvals = [];

        $leaves_query = "";
        if ($role === 'admin') {
            $leaves_query = "SELECT l.*, u.full_name as user_name, 'leave' as approval_type
                             FROM leaves l
                             LEFT JOIN admin_users u ON l.user_id = u.id
                             WHERE l.status = 'pending' ORDER BY l.created_at DESC";
        } elseif ($role === 'manager') {
            $user = $db->querySingle("SELECT department FROM admin_users WHERE id = $user_id", true);
            $dept = SQLite3::escapeString($user['department'] ?? '');
            $leaves_query = "SELECT l.*, u.full_name as user_name, 'leave' as approval_type
                             FROM leaves l
                             LEFT JOIN admin_users u ON l.user_id = u.id
                             WHERE l.status = 'pending' AND u.department = '$dept' ORDER BY l.created_at DESC";
        } elseif ($role === 'lead') {
            $user = $db->querySingle("SELECT team_id FROM admin_users WHERE id = $user_id", true);
            $team_id = intval($user['team_id'] ?? 0);
            $leaves_query = "SELECT l.*, u.full_name as user_name, 'leave' as approval_type
                             FROM leaves l
                             LEFT JOIN admin_users u ON l.user_id = u.id
                             WHERE l.status = 'pending' AND u.team_id = $team_id ORDER BY l.created_at DESC";
        }

        if ($leaves_query) {
            $result = $db->query($leaves_query);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $approvals[] = $row;
            }
        }

        if (in_array($role, ['admin', 'manager'])) {
            $result = $db->query("SELECT pc.*, u.full_name as user_name, 'worker_edit' as approval_type
                FROM pending_changes pc
                JOIN admin_users u ON pc.requested_by = u.id
                WHERE pc.status = 'pending' ORDER BY pc.created_at DESC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $approvals[] = $row;
            }
        }

        echo json_encode($approvals);

    } else {
        header('Content-Type: text/html');
        $html = '';
        $leaves_query = "";

        if ($role === 'admin') {
            $leaves_query = "SELECT l.*, u.full_name as user_name
                             FROM leaves l LEFT JOIN admin_users u ON l.user_id = u.id
                             WHERE l.status = 'pending' ORDER BY l.created_at DESC LIMIT 10";
        } elseif ($role === 'manager') {
            $user = $db->querySingle("SELECT department FROM admin_users WHERE id = $user_id", true);
            $dept = SQLite3::escapeString($user['department'] ?? '');
            $leaves_query = "SELECT l.*, u.full_name as user_name
                             FROM leaves l LEFT JOIN admin_users u ON l.user_id = u.id
                             WHERE l.status = 'pending' AND u.department = '$dept' ORDER BY l.created_at DESC LIMIT 10";
        }

        if ($leaves_query) {
            $result = $db->query($leaves_query);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $html .= "<tr class='border-b'>";
                $html .= "<td class='py-2'>Leave</td>";
                $html .= "<td class='py-2'>{$row['user_name']}</td>";
                $html .= "<td class='py-2'>{$row['leave_type']} " . date('d/m', strtotime($row['start_date'])) . " - " . date('d/m', strtotime($row['end_date'])) . "</td>";
                $html .= "<td class='py-2'>" . date('d/m/Y', strtotime($row['created_at'])) . "</td>";
                $html .= "<td class='py-2'>
                    <button onclick='approveLeave({$row['id']})' class='text-green-600 mr-2'><i class='fas fa-check'></i></button>
                    <button onclick='rejectLeave({$row['id']})' class='text-red-600'><i class='fas fa-times'></i></button>
                  </td>";
                $html .= "</tr>";
            }
        }

        if (in_array($role, ['admin', 'manager'])) {
            $result = $db->query("SELECT pc.*, u.full_name as user_name
                FROM pending_changes pc
                JOIN admin_users u ON pc.requested_by = u.id
                WHERE pc.status = 'pending' ORDER BY pc.created_at DESC LIMIT 5");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $changes = json_decode($row['field_changes'], true);
                $change_summary = implode(', ', array_keys($changes));
                $html .= "<tr class='border-b bg-yellow-50'>";
                $html .= "<td class='py-2 text-xs'><span class='bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full'>Edit Request</span></td>";
                $html .= "<td class='py-2'>{$row['user_name']}</td>";
                $html .= "<td class='py-2 text-xs'>Fields: $change_summary on {$row['table_name']} #{$row['record_id']}</td>";
                $html .= "<td class='py-2 text-xs'>" . date('d/m/Y', strtotime($row['created_at'])) . "</td>";
                $html .= "<td class='py-2'>
                    <button onclick='approveChange({$row['id']})' class='text-green-600 mr-2'><i class='fas fa-check'></i></button>
                    <button onclick='rejectChange({$row['id']})' class='text-red-600'><i class='fas fa-times'></i></button>
                  </td>";
                $html .= "</tr>";
            }
        }
        echo $html;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $id     = intval($data['id'] ?? 0);
    $reason = $data['reason'] ?? '';

    if ($action === 'approve') {
        $stmt = $db->prepare("SELECT * FROM leaves WHERE id = ?");
        $stmt->bindValue(1, $id);
        $leave = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($leave) {
            $stmt = $db->prepare("UPDATE leaves SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $id);
            $stmt->execute();

            $stmt = $db->prepare("INSERT INTO events (title, event_type, start_date, end_date, target_type, target_ids, created_by, is_approved) VALUES (?, 'leave', ?, ?, 'specific', ?, ?, 1)");
            $stmt->bindValue(1, "Leave: " . $leave['leave_type']);
            $stmt->bindValue(2, $leave['start_date']);
            $stmt->bindValue(3, $leave['end_date']);
            $stmt->bindValue(4, (string)$leave['user_id']);
            $stmt->bindValue(5, $user_id);
            $stmt->execute();

            sendNotification($leave['user_id'], 'leave_approved', 'Leave Approved',
                "Your leave from {$leave['start_date']} to {$leave['end_date']} has been approved.");
            logActivity('leave_approved', "Approved leave ID: $id");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Leave not found']);
        }

    } elseif ($action === 'reject') {
        if (str_word_count($reason) < 2) {
            echo json_encode(['success' => false, 'error' => 'Please provide a reason (at least 2 words)']);
            exit;
        }
        $stmt = $db->prepare("UPDATE leaves SET status = 'rejected', approved_by = ?, approved_at = CURRENT_TIMESTAMP, approval_reason = ? WHERE id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $reason);
        $stmt->bindValue(3, $id);
        $stmt->execute();

        $stmt = $db->prepare("SELECT user_id FROM leaves WHERE id = ?");
        $stmt->bindValue(1, $id);
        $leave = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($leave) {
            sendNotification($leave['user_id'], 'leave_rejected', 'Leave Rejected',
                "Your leave request was rejected. Reason: $reason");
        }
        logActivity('leave_rejected', "Rejected leave ID: $id");
        echo json_encode(['success' => true]);

    } elseif ($action === 'approve_change') {
        if (!in_array($role, ['admin', 'manager'])) {
            echo json_encode(['error' => 'Forbidden']); exit;
        }
        $stmt = $db->prepare("SELECT * FROM pending_changes WHERE id = ? AND status = 'pending'");
        $stmt->bindValue(1, $id);
        $change = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$change) {
            echo json_encode(['error' => 'Pending change not found or already processed']); exit;
        }

        $changes   = json_decode($change['field_changes'], true);
        $table     = preg_replace('/[^a-z_]/', '', $change['table_name']);
        $record_id = intval($change['record_id']);

        foreach ($changes as $field => $vals) {
            $safe_field = preg_replace('/[^a-z_]/', '', $field);
            $safe_val   = SQLite3::escapeString($vals['new']);
            $db->exec("UPDATE $table SET $safe_field = '$safe_val' WHERE id = $record_id");
        }

        $stmt = $db->prepare("UPDATE pending_changes SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $id);
        $stmt->execute();

        sendNotification($change['requested_by'], 'edit_approved', 'Edit Request Approved',
            "Your edit request for {$change['table_name']} #{$record_id} has been approved and applied.");
        logActivity('pending_change_approved', "Approved change ID $id");
        echo json_encode(['success' => true]);

    } elseif ($action === 'reject_change') {
        if (!in_array($role, ['admin', 'manager'])) {
            echo json_encode(['error' => 'Forbidden']); exit;
        }
        $stmt = $db->prepare("SELECT requested_by, table_name, record_id FROM pending_changes WHERE id = ?");
        $stmt->bindValue(1, $id);
        $change = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        $stmt = $db->prepare("UPDATE pending_changes SET status = 'rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_note = ? WHERE id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $reason);
        $stmt->bindValue(3, $id);
        $stmt->execute();

        if ($change) {
            sendNotification($change['requested_by'], 'edit_rejected', 'Edit Request Rejected',
                "Your edit request for {$change['table_name']} #{$change['record_id']} was rejected." . ($reason ? " Reason: $reason" : ''));
        }
        logActivity('pending_change_rejected', "Rejected change ID $id");
        echo json_encode(['success' => true]);
    }
}

}

function api_attendance() {
    global $db;
    if (!$db) $db = db();

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
function _api_attendance_haversineDistance($lat1, $lng1, $lat2, $lng2) {
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
function _api_attendance_getGeofenceForUser($db, $user_id) {
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
        $geofence = _api_attendance_getGeofenceForUser($db, $user_id);
        $geofence_status = 'not_checked';
        $distance = null;

        if ($geofence && $user_lat !== null && $user_lng !== null) {
            $distance = _api_attendance_haversineDistance($user_lat, $user_lng, $geofence['lat'], $geofence['lng']);
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

}

function api_audit() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$db = db();

$page = $_GET['page'] ?? 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$filters = [];
$params = [];

if (!empty($_GET['user'])) {
    $filters[] = "u.full_name LIKE ?";
    $params[] = '%' . $_GET['user'] . '%';
}

if (!empty($_GET['action'])) {
    $filters[] = "a.action = ?";
    $params[] = $_GET['action'];
}

if (!empty($_GET['date'])) {
    $filters[] = "DATE(a.created_at) = ?";
    $params[] = $_GET['date'];
}

$where = empty($filters) ? "" : "WHERE " . implode(" AND ", $filters);

// Get total count
$count_query = "SELECT COUNT(*) as total FROM activity_log a LEFT JOIN admin_users u ON a.user_id = u.id $where";
$stmt = $db->prepare($count_query);
foreach ($params as $i => $param) {
    $stmt->bindValue($i + 1, $param);
}
$result = $stmt->execute();
$total = $result->fetchArray(SQLITE3_ASSOC)['total'];
$total_pages = ceil($total / $per_page);

// Get logs
$query = "SELECT a.*, u.full_name as user_name FROM activity_log a LEFT JOIN admin_users u ON a.user_id = u.id $where ORDER BY a.created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($query);
foreach ($params as $i => $param) {
    $stmt->bindValue($i + 1, $param);
}
$result = $stmt->execute();

$html = '';
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $status_class = $row['success'] ? 'text-green-600' : 'text-red-600';
    $status_icon = $row['success'] ? 'fa-check-circle' : 'fa-exclamation-circle';

    $html .= "<tr class='border-b hover:bg-gray-50'>";
    $html .= "<td class='py-2'>" . date('d/m/Y H:i', strtotime($row['created_at'])) . "</td>";
    $html .= "<td class='py-2'>{$row['user_name']}</td>";
    $html .= "<td class='py-2'><span class='px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs'>" . ucfirst(str_replace('_', ' ', $row['action'])) . "</span></td>";
    $html .= "<td class='py-2'>{$row['details']}</td>";
    $html .= "<td class='py-2'>{$row['ip_address']}</td>";
    $html .= "<td class='py-2 {$status_class}'><i class='fas {$status_icon}'></i></td>";
    $html .= "</tr>";
}

if (isset($_GET['export'])) {
    // Export to CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'User', 'Action', 'Details', 'IP Address', 'Status']);

    $result = $db->query($query);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($row['created_at'])),
            $row['user_name'],
            $row['action'],
            $row['details'],
            $row['ip_address'],
            $row['success'] ? 'Success' : 'Failed'
        ]);
    }
    fclose($output);
    exit;
}

echo json_encode([
    'html' => $html,
    'total_pages' => $total_pages,
    'current_page' => $page
]);

}

function api_calendar() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    die();
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $month_param = $_GET['month'] ?? date('Y-m');
    $year = substr($month_param, 0, 4);
    $month = substr($month_param, 5, 2);

    $timestamp = strtotime("$month_param-01");
    $days_in_month = date('t', $timestamp);
    $first_day_of_week = date('w', $timestamp);

    // Get ALL events for this month (General holidays + User specific)
    $stmt = $db->prepare("SELECT * FROM events
                          WHERE (strftime('%Y-%m', start_date) = ? OR (end_date IS NOT NULL AND strftime('%Y-%m', end_date) = ?))
                          AND (target_type = 'all' OR target_ids LIKE ? OR created_by = ?)
                          AND is_approved = 1");
    $stmt->bindValue(1, $month_param);
    $stmt->bindValue(2, $month_param);
    $stmt->bindValue(3, "%$user_id%");
    $stmt->bindValue(4, $user_id);
    $result = $stmt->execute();

    $events_by_date = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $events_by_date[$row['start_date']][] = $row;
        if ($row['end_date'] && $row['end_date'] > $row['start_date']) {
            $curr = strtotime($row['start_date'] . ' +1 day');
            $last = strtotime($row['end_date']);
            while ($curr <= $last) {
                $events_by_date[date('Y-m-d', $curr)][] = $row;
                $curr = strtotime('+1 day', $curr);
            }
        }
    }

    // Also get PENDING events for managers/admins to review
    $pending_events = [];
    if (in_array($user_role, ['admin', 'manager'])) {
        $stmt_pending = $db->prepare("SELECT * FROM events WHERE is_approved = 0");
        $res_pending = $stmt_pending->execute();
        while ($row = $res_pending->fetchArray(SQLITE3_ASSOC)) {
            $pending_events[] = $row;
        }
    }

    // Get attendance for this user and month
    $stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? AND strftime('%Y-%m', date) = ?");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $month_param);
    $result = $stmt->execute();

    $attendance_by_date = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $attendance_by_date[$row['date']] = $row;
    }

    $days = [];
    for ($i = 0; $i < $first_day_of_week; $i++) { $days[] = ['padding' => true]; }

    for ($d = 1; $d <= $days_in_month; $d++) {
        $date = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $days[] = [
            'date' => $d,
            'full_date' => $date,
            'events' => $events_by_date[$date] ?? [],
            'attendance' => $attendance_by_date[$date] ?? null,
            'is_today' => ($date === date('Y-m-d'))
        ];
    }

    echo json_encode([
        'month_name' => date('F Y', $timestamp),
        'days' => $days,
        'pending_approvals' => $pending_events
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Action: Approve or Create
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'create';

    if ($action === 'approve') {
        if (!in_array($user_role, ['admin', 'manager'])) {
            http_response_code(403);
            die(json_encode(['error' => 'Forbidden']));
        }

        $event_id = $data['id'] ?? 0;
        // Rule: Manager cannot approve their own weekly-off
        $event = $db->querySingle("SELECT created_by FROM events WHERE id = $event_id", true);
        if ($user_role === 'manager' && $event['created_by'] == $user_id) {
            http_response_code(400);
            die(json_encode(['error' => 'Managers cannot approve their own weekly-off requests.']));
        }

        $stmt = $db->prepare("UPDATE events SET is_approved = 1, approved_by = ? WHERE id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $event_id);
        $stmt->execute();
        die(json_encode(['success' => true]));

    } elseif ($action === 'create') {
        $title = $data['title'] ?? '';
        $type = $data['event_type'] ?? 'general';
        $start = $data['start_date'] ?? '';
        $end = $data['end_date'] ?? null;
        $target_type = $data['target_type'] ?? 'all';
        $target_ids = $data['target_ids'] ?? '';

        if (empty($title) || empty($start)) {
            http_response_code(400);
            die(json_encode(['error' => 'Title and Start Date are required.']));
        }

        // Rule: Weekly-off on Sunday/Monday requires approval
        $day_of_week = date('w', strtotime($start)); // 0=Sun, 1=Mon
        $is_approved = 1;
        if ($type === 'weekly-off' && ($day_of_week == 0 || $day_of_week == 1)) {
            // Staff and Managers require approval for Sun/Mon offs.
            // Admins are auto-approved for simplicity unless specified otherwise.
            if ($user_role !== 'admin') {
                $is_approved = 0;
            }
        }

        $stmt = $db->prepare("INSERT INTO events (title, description, event_type, start_date, end_date, target_type, target_ids, created_by, is_approved)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $title);
        $stmt->bindValue(2, $data['description'] ?? '');
        $stmt->bindValue(3, $type);
        $stmt->bindValue(4, $start);
        $stmt->bindValue(5, $end);
        $stmt->bindValue(6, $target_type);
        $stmt->bindValue(7, is_array($target_ids) ? implode(',', $target_ids) : $target_ids);
        $stmt->bindValue(8, $user_id);
        $stmt->bindValue(9, $is_approved);

        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'needs_approval' => ($is_approved === 0),
                'message' => ($is_approved === 0) ? 'Your Sunday/Monday weekly-off request has been submitted for approval.' : 'Event created successfully.'
            ]);
        } else {
            echo json_encode(['error' => 'Failed to save event.']);
        }
    }
}

}

function _api_chat_assignGuestChats() {
    global $db;
    if (!$db) $db = db();

    $unassigned_chats = [];
    $result = $db->query("SELECT id, session_id FROM chat_sessions WHERE status = 'active' AND assigned_to = 0 ORDER BY created_at ASC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $unassigned_chats[] = $row;
    }

    if (empty($unassigned_chats)) return;

    $staff_list = [];
    $staff_res = $db->query("SELECT id FROM admin_users WHERE last_active > datetime('now', '-5 minutes') AND (role = 'admin' OR care_permission = 1)");
    while ($row = $staff_res->fetchArray(SQLITE3_ASSOC)) {
        $staff_list[] = $row['id'];
    }

    if (empty($staff_list)) return;

    $staff_loads = [];
    foreach ($staff_list as $sid) {
        $load = $db->querySingle("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active' AND assigned_to = $sid");
        $staff_loads[$sid] = (int)$load;
    }

    foreach ($unassigned_chats as $chat) {
        asort($staff_loads);
        reset($staff_loads);
        $min_load_staff = key($staff_loads);
        $min_all_load = current($staff_loads);

        if ($staff_loads[$min_load_staff] >= 2) break;
        if ($staff_loads[$min_load_staff] == 1 && $min_all_load == 0) break;

        $stmt = $db->prepare("UPDATE chat_sessions SET assigned_to = ? WHERE id = ?");
        $stmt->bindValue(1, $min_load_staff);
        $stmt->bindValue(2, $chat['id']);
        $stmt->execute();

        $staff_loads[$min_load_staff]++;
    }
}
function api_chat() {
    global $db;
    if (!$db) $db = db();


header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    die();
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

// Get user details
$user = $db->querySingle("SELECT full_name, role, team_id, care_permission FROM admin_users WHERE id = $user_id", true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'guest';
    $session_id = $_GET['session_id'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

    // Unread count poll
    if (isset($_GET['unread'])) {
        $count = $db->querySingle("SELECT COUNT(*) FROM chat_messages
            WHERE receiver_type = 'admin'
            AND is_read = 0
            AND sender_id != $user_id");
        echo json_encode(['count' => (int)$count]);
        exit;
    }

    // Get active guest sessions
    if ($type === 'guest_sessions') {
        _api_chat_assignGuestChats();
                $where_clause = "WHERE cs.status = 'active'";
        if ($user['role'] !== 'admin' && $user['role'] !== 'manager') {
            $where_clause .= " AND (cs.assigned_to = 0 OR cs.assigned_to = $user_id)";
        }

                $where_clause = "";
        if ($user['role'] !== 'admin' && $user['role'] !== 'manager') {
            $where_clause = "WHERE cs.assigned_to = 0 OR cs.assigned_to = $user_id";
        }

        $stmt = $db->prepare("SELECT cs.*,
                              (SELECT COUNT(*) FROM chat_messages
                               WHERE session_id = cs.session_id
                               AND is_read = 0
                               AND sender_type = 'guest') as unread_count,
                              (SELECT message FROM chat_messages
                               WHERE session_id = cs.session_id
                               ORDER BY created_at DESC LIMIT 1) as last_message
                              FROM chat_sessions cs
                              $where_clause
                              ORDER BY cs.last_activity DESC
                              LIMIT 100");
        $result = $stmt->execute();
        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['last_activity_formatted'] = $row['last_activity'] ? date('h:i A', strtotime($row['last_activity'])) : '';
            $sessions[] = $row;
        }
        echo json_encode($sessions);
        exit;
    }

    // Get messages for guest session
    if ($type === 'guest' && $session_id) {
        // Mark messages as read
        $db->exec("UPDATE chat_messages SET is_read = 1
                  WHERE session_id = '$session_id'
                  AND sender_type = 'guest'
                  AND is_read = 0");

        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE session_id = ?
                                  AND id > ?
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $session_id);
            $stmt->bindValue(2, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE session_id = ?
                                  ORDER BY created_at ASC
                                  LIMIT ?");
            $stmt->bindValue(1, $session_id);
            $stmt->bindValue(2, $limit);
        }
    }
    // Staff messages
    elseif ($type === 'staff') {
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE ((receiver_id = ? AND sender_type = 'admin')
                                  OR (sender_id = ? AND receiver_type = 'staff'))
                                  AND id > ?
                                  AND type = 'staff'
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $user_id);
            $stmt->bindValue(3, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE ((receiver_id = ? AND sender_type = 'admin')
                                  OR (sender_id = ? AND receiver_type = 'staff'))
                                  AND type = 'staff'
                                  ORDER BY created_at ASC
                                  LIMIT ?");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $user_id);
            $stmt->bindValue(3, $limit);
        }
    }
    // Team messages
    elseif ($type === 'team') {
        $team_id = $user['team_id'] ?? 0;
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE type = 'team'
                                  AND (receiver_id = ? OR receiver_id = 0)
                                  AND id > ?
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $team_id);
            $stmt->bindValue(2, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE type = 'team'
                                  AND (receiver_id = ? OR receiver_id = 0)
                                  ORDER BY created_at ASC
                                  LIMIT ?");
            $stmt->bindValue(1, $team_id);
            $stmt->bindValue(2, $limit);
        }
    }
    // Broadcast messages
    elseif ($type === 'broadcast') {
        if ($since_id > 0) {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE type = 'broadcast'
                                  AND id > ?
                                  ORDER BY created_at ASC");
            $stmt->bindValue(1, $since_id);
        } else {
            $stmt = $db->prepare("SELECT * FROM chat_messages
                                  WHERE type = 'broadcast'
                                  ORDER BY created_at DESC
                                  LIMIT ?");
            $stmt->bindValue(2, $limit);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }

    $result = $stmt->execute();
    $messages = [];
    $last_id = $since_id;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['date'] = date('M d, Y', strtotime($row['created_at']));
        $row['is_admin'] = ($row['sender_type'] === 'admin');
        $messages[] = $row;
        $last_id = $row['id'];
    }

    if ($since_id > 0) {
                $is_typing = false;
        if ($type === 'guest' && $session_id) {
            $typing_res = $db->querySingle("SELECT guest_typing FROM chat_sessions WHERE session_id = '$session_id'");
            if ($typing_res && strtotime($typing_res) > time() - 5) {
                $is_typing = true;
            }
        }
        echo json_encode([
            'messages' => $messages,
            'last_id' => $last_id,
            'count' => count($messages),
            'is_typing' => $is_typing
        ]);
    } else {
        echo json_encode($messages);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'send';

    // Handle session termination
    if ($action === 'terminate') {
        $session_id = $data['session_id'] ?? '';
        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }

        $db->exec("BEGIN TRANSACTION");

        try {
            // Update session status
            $stmt = $db->prepare("UPDATE chat_sessions SET status = 'terminated', last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();

            // Add system message
            $stmt = $db->prepare("INSERT INTO chat_messages
                (session_id, sender_type, sender_name, message, type, is_read, created_at)
                VALUES (?, 'system', 'System', 'This conversation has ended. Thank you for chatting!', 'guest', 1, datetime('now'))");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();

            $db->exec("COMMIT");

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            $db->exec("ROLLBACK");
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // Handle sending message
    $message = trim($data['message'] ?? '');
    $type = $data['type'] ?? 'guest';
    $session_id = $data['session_id'] ?? '';
    $receiver_id = intval($data['receiver_id'] ?? 0);
    $temp_id = $data['temp_id'] ?? uniqid('msg_');

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required', 'temp_id' => $temp_id]);
        exit;
    }

    // Only admins can broadcast
    if ($type === 'broadcast' && $user_role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Only admins can broadcast', 'temp_id' => $temp_id]);
        exit;
    }

    // Validate session for guest chat
    if ($type === 'guest' && empty($session_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Session ID required', 'temp_id' => $temp_id]);
        exit;
    }

    $db->exec("BEGIN TRANSACTION");

    try {
        // Check if session exists and is active
        if ($type === 'guest') {
            $check = $db->prepare("SELECT status FROM chat_sessions WHERE session_id = ?");
            $check->bindValue(1, $session_id);
            $result = $check->execute();
            $session = $result->fetchArray(SQLITE3_ASSOC);

            if (!$session) {
                // Create session if it doesn't exist
                $create = $db->prepare("INSERT INTO chat_sessions (session_id, status, last_activity, created_at) VALUES (?, 'active', datetime('now'), datetime('now'))");
                $create->bindValue(1, $session_id);
                $create->execute();
            } elseif ($session['status'] !== 'active') {
                // Reactivate if terminated
                $update = $db->prepare("UPDATE chat_sessions SET status = 'active', last_activity = datetime('now') WHERE session_id = ?");
                $update->bindValue(1, $session_id);
                $update->execute();
            }
        }

        // Insert message
        $stmt = $db->prepare("INSERT INTO chat_messages
            (session_id, sender_id, sender_type, sender_name, message, type, receiver_id, is_read, created_at)
            VALUES (?, ?, 'admin', ?, ?, ?, ?, 0, datetime('now'))");

        $stmt->bindValue(1, $session_id ?: null);
        $stmt->bindValue(2, $user_id);
        $stmt->bindValue(3, $user['full_name']);
        $stmt->bindValue(4, $message);
        $stmt->bindValue(5, $type);
        $stmt->bindValue(6, $receiver_id);

        $stmt->execute();
        $message_id = $db->lastInsertRowID();

        // Update session activity
        if ($type === 'guest' && $session_id) {
            $update = $db->prepare("UPDATE chat_sessions SET last_activity = datetime('now') WHERE session_id = ?");
            $update->bindValue(1, $session_id);
            $update->execute();
        }

        $db->exec("COMMIT");

        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'temp_id' => $temp_id,
            'created_at' => date('Y-m-d H:i:s')
        ]);

    } catch (\Exception $e) {
        $db->exec("ROLLBACK");
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'temp_id' => $temp_id]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Mark messages as read
    $data = json_decode(file_get_contents('php://input'), true);
    $session_id = $data['session_id'] ?? '';

    if ($session_id) {
        $stmt = $db->prepare("UPDATE chat_messages SET is_read = 1
                              WHERE session_id = ? AND sender_type = 'guest' AND is_read = 0");
        $stmt->bindValue(1, $session_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Session ID required']);
    }
}

}

function api_chat_fetch() {
    global $db;
    if (!$db) $db = db();
 echo json_encode(['module'=>'chat fetch']);
}

function api_chat_send() {
    global $db;
    if (!$db) $db = db();
 echo json_encode(['module'=>'chat send']);
}

function api_clear_chat_session() {
    global $db;
    if (!$db) $db = db();

session_start();
unset($_SESSION['chat_session_id']);
echo json_encode(['success' => true]);

}

function api_crm_leads() {
    global $db;
    if (!$db) $db = db();
 echo json_encode(['module'=>'crm']);
}

function api_data_manage() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

// Restricted to Super Admin (ID 1)
if ($_SESSION['admin_id'] != 1) {
    http_response_code(403);
    die(json_encode(['error' => 'Super Admin only']));
}

$db = db();

$action = $_GET['action'] ?? '';
$table = $_GET['table'] ?? '';

$allowedTables = ['workers', 'admin_users', 'applications', 'enquiries', 'attendance', 'salary_records', 'tasks'];

if (!in_array($table, $allowedTables)) {
    die(json_encode(['error' => 'Invalid table']));
}

if ($action === 'export_csv') {
    $results = $db->query("SELECT * FROM $table");
    $filename = $table . "_" . date('Y-m-d_H-i-s') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Header
    $info = $db->query("PRAGMA table_info($table)");
    $headers = [];
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $headers[] = $row['name'];
    }
    fputcsv($output, $headers);

    // Data
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    return;

} elseif ($action === 'export_vcf') {
    if (!in_array($table, ['workers', 'admin_users'])) {
        die(json_encode(['error' => 'VCF only supported for people tables']));
    }

    $results = $db->query("SELECT * FROM $table");
    $filename = $table . "_" . date('Y-m-d') . ".vcf";

    header('Content-Type: text/vcard');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        echo "BEGIN:VCARD\n";
        echo "VERSION:3.0\n";
        $name = $row['name'] ?? $row['full_name'] ?? 'Unknown';
        echo "FN:$name\n";
        if (isset($row['phone'])) echo "TEL;TYPE=CELL:" . $row['phone'] . "\n";
        if (isset($row['email'])) echo "EMAIL;TYPE=INTERNET:" . $row['email'] . "\n";
        if (isset($row['address'])) echo "ADR;TYPE=HOME:;;" . str_replace("\n", " ", $row['address']) . "\n";
        echo "END:VCARD\n";
    }
    return;

} elseif ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        die(json_encode(['error' => 'No file uploaded']));
    }

    $tmpName = $_FILES['file']['tmp_name'];
    $handle = fopen($tmpName, "r");
    $headers = fgetcsv($handle);

    if (!$headers) {
        die(json_encode(['error' => 'Invalid CSV']));
    }

    $count = 0;
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);

        // Build INSERT query
        $cols = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));

        $stmt = $db->prepare("INSERT OR REPLACE INTO $table ($cols) VALUES ($placeholders)");
        $i = 1;
        foreach ($data as $val) {
            $stmt->bindValue($i++, $val);
        }
        $stmt->execute();
        $count++;
    }

    fclose($handle);
    echo json_encode(['success' => true, 'count' => $count]);
    return;
}

}

function api_document_requests() {
    global $db;
    if (!$db) $db = db();

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

    $stmt = $db->prepare("INSERT INTO document_requests (user_id, document_type, reason, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $data['type']);
    $stmt->bindValue(3, $data['reason']);
    $stmt->execute();

    // Notify manager
    $user = $db->querySingle("SELECT manager_id, full_name FROM admin_users WHERE id = $user_id", true);
    if ($user && $user['manager_id']) {
        sendNotification($user['manager_id'], 'document', 'New Document Request',
                        "{$user['full_name']} requested a {$data['type']}");
    }

    logActivity('document_requested', "Requested document: {$data['type']}");

    echo json_encode(['success' => true]);
}

}

function api_documents() {
    global $db;
    if (!$db) $db = db();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$db = db();
$user_id = $_SESSION['admin_id'];
$type = $_GET['type'] ?? '';

// Get user data
$user = $db->querySingle("SELECT * FROM admin_users WHERE id = $user_id", true);

// Get default template for this document type
$valid_types = ['offer_letter', 'joining_letter', 'salary_slip', 'annual_statement', 'profile_summary', 'experience_certificate'];
$template_type = in_array($type, $valid_types) ? $type : 'custom';

$template = $db->querySingle("SELECT * FROM templates WHERE template_type = '$template_type' AND is_default = 1", true);

if (!$template) {
    // Fallback to default content
    $template = [
        'content' => _api_documents_getDefaultTemplate($type, $user),
        'css' => ''
    ];
}

// Generate document based on type
switch ($type) {
    case 'salary_slip':
        _api_documents_generateSalarySlip($db, $user, $template);
        break;
    case 'annual_statement':
        _api_documents_generateAnnualStatement($db, $user, $template);
        break;
    case 'offer_letter':
    case 'joining_letter':
    case 'experience_certificate':
        _api_documents_generateLetter($user, $type, $template);
        break;
    case 'profile_summary':
        _api_documents_generateProfileSummary($user, $template);
        break;
    default:
        _api_documents_generateCustomDocument($user, $template);
}

function _api_documents_getDefaultTemplate($type, $user)
{
    $templates = [
        'offer_letter' => '<h1>Offer Letter</h1><p>Dear {full_name},</p><p>We are pleased to offer you the position of {role} at D K Associates.</p>',
        'joining_letter' => '<h1>Joining Letter</h1><p>Dear {full_name},</p><p>This confirms your joining as {role} effective from {joining_date}.</p>',
        'salary_slip' => '<h1>Salary Slip</h1><p>Employee: {full_name}</p><p>Month: {month}</p>',
        'profile_summary' => '<h1>Employee Profile</h1><p>Name: {full_name}</p><p>Employee Code: {employee_code}</p>'
    ];

    return $templates[$type] ?? '<h1>Document</h1><p>Generated for {full_name}</p>';
}

function _api_documents_generateSalarySlip($db, $user, $template)
{
    $month = $_GET['month'] ?? date('Y-m');
    $year = substr($month, 0, 4);
    $month_name = date('F Y', strtotime($month . '-01'));

    // Get attendance for the month
    $attendance = $db->querySingle("SELECT COUNT(*) as days FROM attendance WHERE user_id = {$user['id']} AND strftime('%Y-%m', date) = '$month' AND status IN ('ontime', 'late')");
    $attended_days = $attendance['days'] ?? 0;

    // Get salary record
    $salary = $db->querySingle("SELECT * FROM salary_records WHERE user_id = {$user['id']} AND month = '$month'", true);

    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{month}', '{year}', '{attended_days}', '{basic_salary}', '{allowances}', '{deductions}', '{net_salary}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        $month_name,
        $year,
        $attended_days,
        $user['salary_basic'] ?? 0,
        $user['salary_allowance'] ?? 0,
        $user['salary_deductions'] ?? 0,
        ($user['salary_basic'] ?? 0) + ($user['salary_allowance'] ?? 0) - ($user['salary_deductions'] ?? 0)
    ],
        $template['content']
    );

    _api_documents_generatePDF($content, $template['css'], "Salary_Slip_{$user['employee_code']}_{$month}.pdf");
}

function _api_documents_generateAnnualStatement($db, $user, $template)
{
    $year = $_GET['year'] ?? date('Y');

    // Get yearly salary summary
    $salary_data = $db->query("SELECT * FROM salary_records WHERE user_id = {$user['id']} AND strftime('%Y', month) = '$year'");

    $total_earned = 0;
    $total_deductions = 0;
    $months_data = '';

    while ($row = $salary_data->fetchArray(SQLITE3_ASSOC)) {
        $total_earned += $row['final_salary'] ?? 0;
        $total_deductions += $row['deductions'] ?? 0;
        $months_data .= "<tr><td>" . date('F Y', strtotime($row['month'] . '-01')) . "</td><td>{$row['final_salary']}</td></tr>";
    }

    $content = str_replace(
    ['{full_name}', '{employee_code}', '{year}', '{total_earned}', '{total_deductions}', '{months_data}'],
    [$user['full_name'], $user['employee_code'], $year, $total_earned, $total_deductions, $months_data],
        $template['content']
    );

    _api_documents_generatePDF($content, $template['css'], "Annual_Statement_{$user['employee_code']}_{$year}.pdf");
}

function _api_documents_generateLetter($user, $type, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{date}', '{joining_date}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        date('d F Y'),
        date('d F Y', strtotime($user['created_at']))
    ],
        $template['content']
    );

    _api_documents_generatePDF($content, $template['css'], ucfirst($type) . "_{$user['employee_code']}.pdf");
}

function _api_documents_generateProfileSummary($user, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{email}', '{phone}', '{department}', '{reporting_head}', '{blood_group}', '{emergency_contact}', '{joining_date}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        $user['email'],
        $user['phone'],
        $user['department'],
        _api_documents_getUserName($user['reporting_head']),
        $user['blood_group'],
        $user['emergency_contact'],
        date('d F Y', strtotime($user['created_at']))
    ],
        $template['content']
    );

    _api_documents_generatePDF($content, $template['css'], "Profile_{$user['employee_code']}.pdf");
}

function _api_documents_generateCustomDocument($user, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{email}', '{phone}', '{date}'],
    [$user['full_name'], $user['role'], $user['employee_code'], $user['email'], $user['phone'], date('d F Y')],
        $template['content']
    );

    _api_documents_generatePDF($content, $template['css'], "Document_{$user['employee_code']}.pdf");
}

function _api_documents_generatePDF($content, $css, $filename)
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator('D K Associates');
    $pdf->SetAuthor('D K Associates');
    $pdf->SetTitle($filename);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $pdf->AddPage();

    $html = "<html><head><style>{$css}</style></head><body>{$content}</body></html>";

    $pdf->writeHTML($html, true, false, true, false, '');

    ob_end_clean();
    $pdf->Output($filename, 'D');
}

function _api_documents_getUserName($user_id)
{
    global $db;
    if (!$user_id)
        return 'Not Assigned';
    $user = $db->querySingle("SELECT full_name FROM admin_users WHERE id = $user_id", true);
    return $user['full_name'] ?? 'Not Assigned';
}

}

function api_enquiries() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role = $_SESSION['admin_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Build query based on role
    switch ($role) {
        case 'admin':
            $query = "SELECT e.*, u.full_name as assigned_to_name
                      FROM enquiries e
                      LEFT JOIN admin_users u ON e.assigned_to = u.id
                      ORDER BY e.created_at DESC";
            break;

        case 'manager':
            $user = $db->querySingle("SELECT department FROM admin_users WHERE id = $user_id", true);
            $dept = $user['department'];
            $query = "SELECT e.*, u.full_name as assigned_to_name
                      FROM enquiries e
                      LEFT JOIN admin_users u ON e.assigned_to = u.id
                      WHERE u.department = '$dept' OR e.assigned_to = $user_id
                      ORDER BY e.created_at DESC";
            break;

        default:
            $query = "SELECT e.*, u.full_name as assigned_to_name
                      FROM enquiries e
                      LEFT JOIN admin_users u ON e.assigned_to = u.id
                      WHERE e.assigned_to = $user_id
                      ORDER BY e.created_at DESC";
    }

    $result = $db->query($query);

    $html = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $status_colors = [
            'new' => 'bg-blue-100 text-blue-800',
            'assigned' => 'bg-yellow-100 text-yellow-800',
            'processing' => 'bg-purple-100 text-purple-800',
            'quoted' => 'bg-green-100 text-green-800',
            'converted' => 'bg-green-600 text-white',
            'closed' => 'bg-gray-100 text-gray-800'
        ];
        $status_class = $status_colors[$row['status']] ?? 'bg-gray-100 text-gray-800';

        $response_time = $row['response_time'] ? $row['response_time'] . ' min' : 'Pending';

        $html .= "<tr class='border-b'>";
        $html .= "<td class='py-2'>" . date('d/m/Y', strtotime($row['created_at'])) . "</td>";
        $html .= "<td class='py-2'>{$row['name']}</td>";
        $html .= "<td class='py-2'>{$row['service_type']}</td>";
        $html .= "<td class='py-2'><span class='px-2 py-1 rounded-full text-xs $status_class'>" . ucfirst($row['status']) . "</span></td>";
        $html .= "<td class='py-2'>{$row['assigned_to_name']}</td>";
        $html .= "<td class='py-2'>$response_time</td>";
        $html .= "<td class='py-2'>
                    <button onclick='viewEnquiry({$row['id']})' class='text-blue-600 mr-2'><i class='fas fa-eye'></i></button>
                    <button onclick='assignEnquiry({$row['id']})' class='text-yellow-600 mr-2'><i class='fas fa-user-tag'></i></button>
                    <button onclick='createQuote({$row['id']})' class='text-green-600'><i class='fas fa-file-invoice'></i></button>
                  </td>";
        $html .= "</tr>";
    }

    echo $html;
}
}

function api_expenses() {
    global $db;
    if (!$db) $db = db();

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

}

function api_export() {
    global $db;
    if (!$db) $db = db();

if (!isset($_SESSION['admin_id'])) {
    die('Unauthorized');
}

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'pdf';
$id = $_GET['id'] ?? 0;

$db = db();

switch ($type) {
    case 'id_card':
        _api_export_exportIDCard($db, $id, $format);
        break;
    case 'worker_id':
        _api_export_exportWorkerID($db, $id, $format);
        break;
    case 'quotation':
        _api_export_exportQuotation($db, $id, $format);
        break;
    case 'report':
        _api_export_exportReport($db, $format);
        break;
}

function _api_export_exportIDCard($db, $user_id, $format) {
    $user = $db->querySingle("SELECT * FROM admin_users WHERE id = $user_id", true);

    if ($format === 'pdf') {

        $pdf = new \Fpdf\Fpdf('L', 'mm', '86x54'); // Credit card size
        $pdf->AddPage();

        // Design ID card
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');

        if ($user['photo_url']) {
            $pdf->Image('..' . $user['photo_url'], 10, 15, 20, 20);
        }

        $pdf->SetXY(35, 15);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, $user['full_name'], 0, 1);

        $pdf->SetX(35);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, ucfirst($user['role']), 0, 1);

        $pdf->SetX(35);
        $pdf->Cell(0, 4, 'ID: ' . $user['employee_id'], 0, 1);

        // Add QR code
        if ($user['qr_code'] && file_exists('..' . $user['qr_code'])) {
            $pdf->Image('..' . $user['qr_code'], 60, 35, 15, 15);
        }

        $pdf->Output('D', 'ID_Card_' . $user['employee_id'] . '.pdf');
    }
}

function _api_export_exportWorkerID($db, $worker_id, $format) {
    $worker = $db->querySingle("SELECT * FROM workers WHERE id = $worker_id", true);

    if ($format === 'pdf') {

        $pdf = new \Fpdf\Fpdf('L', 'mm', '86x54');
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, 'Worker ID Card', 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, $worker['name'], 0, 1, 'C');

        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, 'ID: ' . $worker['worker_id'], 0, 1, 'C');

        // Add QR code if available
        if (!empty($worker['qr_code']) && file_exists('..' . $worker['qr_code'])) {
            $pdf->Image('..' . $worker['qr_code'], 35, 25, 20, 20);
        }

        $pdf->Output('D', 'Worker_ID_' . $worker['worker_id'] . '.pdf');
    }
}

function _api_export_exportQuotation($db, $id, $format) {
    $quotation = $db->querySingle("SELECT * FROM quotations WHERE id = $id", true);

    if (!$quotation) {
        die('Quotation not found.');
    }

    if ($format === 'pdf') {

        $pdf = new \Fpdf\Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();

        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Quotation', 0, 1, 'C');
        $pdf->Ln(5);

        // Quotation details
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Quotation No:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['quotation_number'] ?? $id, 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Date:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['created_at'] ?? date('Y-m-d'), 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Client:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['client_name'] ?? 'N/A', 0, 1);
        $pdf->Ln(5);

        // Items table header
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(90, 8, 'Description', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Unit Price', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Total', 1, 1, 'C', true);

        // Items rows
        $items = json_decode($quotation['items'] ?? '[]', true);
        $pdf->SetFont('Arial', '', 9);
        $grand_total = 0;
        foreach ((array)$items as $item) {
            $line_total = ($item['qty'] ?? 1) * ($item['unit_price'] ?? 0);
            $grand_total += $line_total;
            $pdf->Cell(90, 7, $item['description'] ?? '', 1, 0);
            $pdf->Cell(30, 7, $item['qty'] ?? 1, 1, 0, 'C');
            $pdf->Cell(35, 7, number_format($item['unit_price'] ?? 0, 2), 1, 0, 'R');
            $pdf->Cell(35, 7, number_format($line_total, 2), 1, 1, 'R');
        }

        // Grand total
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(155, 8, 'Grand Total', 1, 0, 'R');
        $pdf->Cell(35, 8, number_format($grand_total, 2), 1, 1, 'R');

        $pdf->Output('D', 'Quotation_' . ($quotation['quotation_number'] ?? $id) . '.pdf');
    }
}

function _api_export_exportReport($db, $format) {
    if ($format === 'pdf') {

        $pdf = new \Fpdf\Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();

        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Summary Report', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $pdf->Ln(5);

        // Workers summary
        $worker_count = $db->querySingle("SELECT COUNT(*) FROM workers") ?? 0;
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, 'Workers', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, 'Total Workers: ' . $worker_count, 0, 1);
        $pdf->Ln(3);

        // Users summary
        $user_count = $db->querySingle("SELECT COUNT(*) FROM admin_users") ?? 0;
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, 'Users', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, 'Total Users: ' . $user_count, 0, 1);

        $pdf->Output('D', 'Report_' . date('Ymd') . '.pdf');
    }
}
}

function api_geofence() {
    global $db;
    if (!$db) $db = db();

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

}

function api_guest_chat() {
    global $db;
    if (!$db) $db = db();

/**
 * Public Guest Chat API - No authentication required for guests
 * Used by index.php chat widget
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

try {
    $db = db();
    $db->enableExceptions(true);

    // Ensure tables exist
    $db->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT UNIQUE,
        guest_name TEXT,
        guest_email TEXT,
        guest_phone TEXT,
        contact_reason TEXT,
        device_id TEXT,
        status TEXT DEFAULT 'active',
        assigned_to INTEGER DEFAULT 0,
        guest_typing DATETIME,
        agent_typing DATETIME,
        assigned_to INTEGER DEFAULT 0,
        last_activity DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT,
        sender_type TEXT,
        sender_name TEXT,
        sender_id INTEGER DEFAULT 0,
        receiver_id INTEGER DEFAULT 0,
        receiver_type TEXT,
        message TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $data = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true) ?? [];
    }

    $action = $data['action'] ?? ($_GET['action'] ?? '');

    // ===== START CHAT SESSION =====
    if ($action === 'start_session') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        $guest_name = htmlspecialchars($data['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
        $guest_email = filter_var($data['guest_email'] ?? '', FILTER_SANITIZE_EMAIL);
        $guest_phone = preg_replace('/[^0-9+\-]/', '', $data['guest_phone'] ?? '');
        $contact_reason = htmlspecialchars($data['contact_reason'] ?? 'general_query', ENT_QUOTES, 'UTF-8');

        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }

        $stmt = $db->prepare("INSERT OR IGNORE INTO chat_sessions
            (session_id, guest_name, guest_email, guest_phone, contact_reason, status, last_activity)
            VALUES (?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $guest_name);
        $stmt->bindValue(3, $guest_email);
        $stmt->bindValue(4, $guest_phone);
        $stmt->bindValue(5, $contact_reason);
        $stmt->execute();

        // Add initial welcome message row from admin
        $welcome = "Hi $guest_name! Welcome to DK Associates Live Chat. An agent will join shortly. How can we help you today?";
        $stmt2 = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message, receiver_type) VALUES (?, 'admin', 'DK Associates', ?, 'guest')");
        $stmt2->bindValue(1, $session_id);
        $stmt2->bindValue(2, $welcome);
        $stmt2->execute();

        echo json_encode(['success' => true, 'session_id' => $session_id, 'welcome_message' => $welcome]);
        exit;
    }

        if ($action === 'typing') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        $sender_type = $data['sender_type'] ?? 'guest';
        if ($session_id) {
            $col = ($sender_type === 'admin') ? 'agent_typing' : 'guest_typing';
            $stmt = $db->prepare("UPDATE chat_sessions SET $col = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        die();
    }
    // ===== TERMINATE SESSION =====
    if ($action === 'terminate_session') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        if ($session_id) {
            $stmt = $db->prepare("UPDATE chat_sessions SET status = 'terminated', last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
            $stmt->bindValue(1, $session_id);
            $stmt->execute();

            $stmt2 = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, 'system', 'System', 'Chat session ended by guest.')");
            $stmt2->bindValue(1, $session_id);
            $stmt2->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ===== SEND GUEST MESSAGE =====
    if ($action === 'send_message') {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? '');
        $message = htmlspecialchars(trim($data['message'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (empty($session_id) || empty($message)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID and message required']);
            exit;
        }

        // Verify session is active
        $sess = $db->querySingle("SELECT guest_name, status FROM chat_sessions WHERE session_id = '" . SQLite3::escapeString($session_id) . "'", true);
        if (!$sess || $sess['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or terminated session']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, 'guest', ?, ?)");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $sess['guest_name']);
        $stmt->bindValue(3, $message);
        $stmt->execute();

        // Update session activity
        $stmt2 = $db->prepare("UPDATE chat_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_id = ?");
        $stmt2->bindValue(1, $session_id);
        $stmt2->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // ===== POLL MESSAGES =====
    if ($action === 'get_messages' || isset($_GET['session_id'])) {
        $session_id = preg_replace('/[^a-zA-Z0-9_]/', '', $data['session_id'] ?? $_GET['session_id'] ?? '');
        $since_id = intval($data['since_id'] ?? $_GET['since_id'] ?? 0);

        if (empty($session_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Session ID required']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, sender_type, sender_name, message, created_at FROM chat_messages
            WHERE session_id = ? AND id > ? ORDER BY created_at ASC LIMIT 50");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $since_id);
        $result = $stmt->execute();

        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['time'] = date('h:i A', strtotime($row['created_at']));
            $messages[] = $row;
        }

        // Check session status
        $session = $db->querySingle("SELECT status FROM chat_sessions WHERE session_id = '" . SQLite3::escapeString($session_id) . "'", true);

        echo json_encode([
            'messages' => $messages,
            'session_status' => $session['status'] ?? 'unknown'
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
}

}

function api_holidays() {
    global $db;
    if (!$db) $db = db();

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

// Initialize table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    FOREIGN KEY(created_by) REFERENCES admin_users(id)
)");

// Check if this is an event action request
$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? 'holiday';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Return HTML rows for both holidays and events
        $html = '';

        // Get Holidays
        $stmtHolidays = $db->prepare("SELECT id, 'holiday' as type, date as start_date, NULL as end_date, 'holiday' as event_type, description, created_by
                                      FROM holidays");
        $resHolidays = $stmtHolidays->execute();
        $items = [];
        while ($row = $resHolidays->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }

        // Get Events
        $stmtEvents = $db->prepare("SELECT id, 'event' as type, start_date, end_date, event_type, title || ' - ' || description as description, created_by
                                    FROM events");
        $resEvents = $stmtEvents->execute();
        while ($row = $resEvents->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }

        // Sort items by date descending
        usort($items, function($a, $b) {
            return strtotime($b['start_date']) - strtotime($a['start_date']);
        });

        foreach ($items as $row) {
            $formattedDate = date('d M Y', strtotime($row['start_date']));
            if (!empty($row['end_date'])) {
                $formattedDate .= ' to ' . date('d M Y', strtotime($row['end_date']));
            }

            $badgeColor = 'bg-gray-100 text-gray-800';
            if ($row['event_type'] === 'holiday') {
                $badgeColor = 'bg-blue-100 text-blue-800';
            } elseif ($row['event_type'] === 'weekly-off') {
                $badgeColor = 'bg-yellow-100 text-yellow-800';
            } elseif ($row['event_type'] === 'general') {
                $badgeColor = 'bg-green-100 text-green-800';
            }

            $badge = '<span class="px-2 py-0.5 rounded text-xs font-semibold ' . $badgeColor . '">' . ucfirst($row['event_type']) . '</span>';

            $html .= '<tr class="border-b hover:bg-gray-50">';
            $html .= '<td class="p-2 font-medium">' . $formattedDate . ' ' . $badge . '</td>';
            $html .= '<td class="p-2">' . htmlspecialchars($row['description']) . '</td>';
            $html .= '<td class="p-2">';
            $html .= '<button @click="deleteHoliday(' . $row['id'] . ', \'' . $row['type'] . '\')" class="text-red-500 hover:text-red-700 ml-2" title="Delete"><i class="fas fa-trash"></i></button>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        if (empty($html)) {
            $html = '<tr><td colspan="3" class="p-4 text-center text-gray-500">No holidays or events declared</td></tr>';
        }

        header('Content-Type: text/html');
        echo $html;
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        if ($action === 'event') {
            if (empty($data['title']) || empty($data['start_date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Title, Start Date, and Description are required']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO events (title, description, event_type, start_date, end_date, target_type, created_by) VALUES (:title, :description, :event_type, :start_date, :end_date, :target_type, :user)");
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':event_type', $data['event_type'], SQLITE3_TEXT);
            $stmt->bindValue(':start_date', $data['start_date'], SQLITE3_TEXT);
            $stmt->bindValue(':end_date', $data['end_date'] ?: null, SQLITE3_TEXT);
            $stmt->bindValue(':target_type', $data['target_type'] ?? 'all', SQLITE3_TEXT);
            $stmt->bindValue(':user', $_SESSION['admin_id'], SQLITE3_INTEGER);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
            }

        } else {
            // Holiday POST
            if (empty($data['date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Date and description are required']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO holidays (date, description, created_by) VALUES (:date, :desc, :user)");
            $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':user', $_SESSION['admin_id'], SQLITE3_INTEGER);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
            }
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            exit;
        }

        if ($type === 'event') {
            if (empty($data['title']) || empty($data['start_date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Title, Start Date, and Description are required']);
                exit;
            }

            $stmt = $db->prepare("UPDATE events SET title = :title, description = :description, event_type = :event_type, start_date = :start_date, end_date = :end_date, target_type = :target_type WHERE id = :id");
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':event_type', $data['event_type'], SQLITE3_TEXT);
            $stmt->bindValue(':start_date', $data['start_date'], SQLITE3_TEXT);
            $stmt->bindValue(':end_date', $data['end_date'] ?: null, SQLITE3_TEXT);
            $stmt->bindValue(':target_type', $data['target_type'] ?? 'all', SQLITE3_TEXT);
            $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
        } else {
            if (empty($data['date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Date and description are required']);
                exit;
            }

            $stmt = $db->prepare("UPDATE holidays SET date = :date, description = :desc WHERE id = :id");
            $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
        }
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            exit;
        }

        if ($type === 'event') {
            $stmt = $db->prepare("DELETE FROM events WHERE id = :id");
        } else {
            $stmt = $db->prepare("DELETE FROM holidays WHERE id = :id");
        }

        $stmt->bindValue(':id', $_GET['id'], SQLITE3_INTEGER);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
        }
        break;
}

}

function api_leaves() {
    global $db;
    if (!$db) $db = db();

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
}

function api_login() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);
$device_id = $_POST['device_id'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
$db = db();

// Check failed attempts
$stmt = $db->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                      WHERE (ip_address = ? OR device_id = ?)
                      AND attempt_time > datetime('now', '-15 minutes')
                      AND success = 0");
$stmt->bindValue(1, $ip);
$stmt->bindValue(2, $device_id);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$failed_attempts = $row['attempts'] ?? 0;

// Check if blocked (3 attempts = 15 min, 5 attempts = 12 hours)
if ($failed_attempts >= 5) {
    // Check if 12 hours have passed
    $stmt = $db->prepare("SELECT attempt_time FROM login_attempts
                          WHERE (ip_address = ? OR device_id = ?)
                          AND success = 0
                          ORDER BY attempt_time DESC LIMIT 1");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $device_id);
    $result = $stmt->execute();
    $last_attempt = $result->fetchArray(SQLITE3_ASSOC);

    if ($last_attempt && strtotime($last_attempt['attempt_time']) > time() - 43200) { // 12 hours
        http_response_code(429);
        echo json_encode(['error' => 'Account locked due to multiple failed attempts. Please try again after 12 hours.']);
        exit;
    }
} elseif ($failed_attempts >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Please try again after 15 minutes.']);
    exit;
}

// Get user
$stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
$stmt->bindValue(1, $username);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

$login_success = 0;

if ($user && password_verify($password, $user['password_hash'])) {
    $login_success = 1;

    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['login_time'] = time();

    if ($remember) {
        // Set persistent cookie (30 days)
        $token = bin2hex(random_bytes(32));
        setcookie('remember_token', $token, time() + 2592000, '/', '', true, true);

        // Store token in database
        $stmt = $db->prepare("UPDATE admin_users SET remember_token = ? WHERE id = ?");
        $stmt->bindValue(1, password_hash($token, PASSWORD_DEFAULT));
        $stmt->bindValue(2, $user['id']);
        $stmt->execute();
    }

    // Update last login
    $stmt = $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bindValue(1, $user['id']);
    $stmt->execute();

    logActivity('login', 'User logged in');

    // Clear failed attempts
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR device_id = ?");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $device_id);
    $stmt->execute();

} else {
    logActivity('failed_login', "Failed login attempt for username: $username");
}

// Log attempt
$stmt = $db->prepare("INSERT INTO login_attempts (username, ip_address, device_id, success) VALUES (?, ?, ?, ?)");
$stmt->bindValue(1, $username);
$stmt->bindValue(2, $ip);
$stmt->bindValue(3, $device_id);
$stmt->bindValue(4, $login_success);
$stmt->execute();

if ($login_success) {
    header('Location: ../admin.php?tab=dashboard');
    exit;
} else {
    header('Location: ../admin.php?page=login&error=1');
    exit;
}

}

function api_logout() {
    global $db;
    if (!$db) $db = db();

session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: ../admin.php?action=login");
exit;

}

function api_notes() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role    = $_SESSION['admin_role'] ?? '';

// Ensure sticky_notes table exists
$db->exec("CREATE TABLE IF NOT EXISTS sticky_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    color TEXT DEFAULT '#FFF9C4',
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Admin sees everyone's notes; others see only their own
    if ($role === 'admin' || ($db->querySingle("SELECT admin_permission FROM admin_users WHERE id = $user_id") == 1)) {
        $result = $db->query("SELECT sn.*, u.full_name as author FROM sticky_notes sn LEFT JOIN admin_users u ON sn.user_id = u.id WHERE sn.is_active = 1 ORDER BY sn.created_at DESC");
    } else {
        $stmt = $db->prepare("SELECT sn.*, u.full_name as author FROM sticky_notes sn LEFT JOIN admin_users u ON sn.user_id = u.id WHERE sn.user_id = ? AND sn.is_active = 1 ORDER BY sn.created_at DESC");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
    }
    $notes = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notes[] = $row;
    }
    echo json_encode($notes);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = trim($data['note'] ?? $data['content'] ?? '');
    $color   = $data['color'] ?? '#FFF9C4';

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Note content required']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO sticky_notes (user_id, content, color) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $content);
    $stmt->bindValue(3, htmlspecialchars($color, ENT_QUOTES, 'UTF-8'));
    $stmt->execute();
    $id = $db->lastInsertRowID();

    echo json_encode(['success' => true, 'id' => $id]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = intval($data['id'] ?? $_GET['id'] ?? 0);
    if ($id) {
        // Own note or admin can delete
        if ($role === 'admin') {
            $db->exec("UPDATE sticky_notes SET is_active = 0 WHERE id = $id");
        } else {
            $stmt = $db->prepare("UPDATE sticky_notes SET is_active = 0 WHERE id = ? AND user_id = ?");
            $stmt->bindValue(1, $id);
            $stmt->bindValue(2, $user_id);
            $stmt->execute();
        }
    }
    echo json_encode(['success' => true]);
}
}

function api_notifications() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $last_id = $_GET['last_id'] ?? 0;

    $stmt = $db->prepare("SELECT * FROM notifications
                          WHERE user_id = ? AND id > ?
                          ORDER BY created_at DESC");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $last_id);
    $result = $stmt->execute();

    $notifications = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notifications[] = $row;
    }

    echo json_encode($notifications);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['mark_read'])) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->bindValue(1, $data['id']);
        $stmt->execute();
    } elseif (isset($data['mark_all_read'])) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bindValue(1, $user_id);
        $stmt->execute();
    }

    echo json_encode(['success' => true]);
}
}

function api_payroll() {
    global $db;
    if (!$db) $db = db();
 echo json_encode(['module'=>'payroll']);
}

function api_profile_requests() {
    global $db;
    if (!$db) $db = db();

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

}

function api_quotations() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query("SELECT q.*, u.full_name as created_by_name
                          FROM quotations q
                          LEFT JOIN admin_users u ON q.created_by = u.id
                          ORDER BY q.created_at DESC");

    $html = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $status_colors = [
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'accepted' => 'bg-green-100 text-green-800',
            'rejected' => 'bg-red-100 text-red-800',
            'expired' => 'bg-yellow-100 text-yellow-800'
        ];
        $status_class = $status_colors[$row['status']] ?? 'bg-gray-100 text-gray-800';

        $html .= "<tr class='border-b'>";
        $html .= "<td class='py-2'>{$row['quote_number']}</td>";
        $html .= "<td class='py-2'>{$row['customer_name']}</td>";
        $html .= "<td class='py-2'>₹" . number_format($row['total']) . "</td>";
        $html .= "<td class='py-2'><span class='px-2 py-1 rounded-full text-xs $status_class'>" . ucfirst($row['status']) . "</span></td>";
        $html .= "<td class='py-2'>{$row['valid_until']}</td>";
        $html .= "<td class='py-2'>
                    <button onclick='viewQuote({$row['id']})' class='text-blue-600 mr-2'><i class='fas fa-eye'></i></button>
                    <button onclick='downloadQuote({$row['id']})' class='text-green-600 mr-2'><i class='fas fa-download'></i></button>
                    <button onclick='duplicateQuote({$row['id']})' class='text-yellow-600'><i class='fas fa-copy'></i></button>
                  </td>";
        $html .= "</tr>";
    }

    echo $html;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Generate quote number
    $year = date('Y');
    $month = date('m');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM quotations WHERE strftime('%Y-%m', created_at) = ?");
    $stmt->bindValue(1, "$year-$month");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $seq = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
    $quote_number = "Q$year$month$seq";

    // Calculate totals
    $subtotal = 0;
    foreach ($data['items'] as $item) {
        $subtotal += $item['quantity'] * $item['unit_price'];
    }
    $tax = $subtotal * 0.18;
    $total = $subtotal + $tax;

    $stmt = $db->prepare("INSERT INTO quotations
        (quote_number, customer_name, customer_email, customer_phone, items, subtotal, tax, total, status, created_by, valid_until, terms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bindValue(1, $quote_number);
    $stmt->bindValue(2, $data['customer_name']);
    $stmt->bindValue(3, $data['customer_email'] ?? '');
    $stmt->bindValue(4, $data['customer_phone'] ?? '');
    $stmt->bindValue(5, json_encode($data['items']));
    $stmt->bindValue(6, $subtotal);
    $stmt->bindValue(7, $tax);
    $stmt->bindValue(8, $total);
    $stmt->bindValue(9, 'draft');
    $stmt->bindValue(10, $user_id);
    $stmt->bindValue(11, $data['valid_until'] ?? '');
    $stmt->bindValue(12, $data['terms'] ?? '');
    $stmt->execute();

    $quote_id = $db->lastInsertRowID();

    logActivity('quote_created', "Created quotation: $quote_number");

    echo json_encode(['success' => true, 'id' => $quote_id, 'number' => $quote_number]);
}
 elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;

    $stmt = $db->prepare("DELETE FROM quotations WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();

    logActivity('quote_deleted', "Deleted quotation ID: $id");
    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($data['items'] as $item) {
        $subtotal += $item['quantity'] * $item['unit_price'];
    }
    $tax = $subtotal * 0.18;
    $total = $subtotal + $tax;

    $stmt = $db->prepare("UPDATE quotations SET customer_name = ?, customer_email = ?, customer_phone = ?, items = ?, subtotal = ?, tax = ?, total = ?, status = ?, valid_until = ?, terms = ? WHERE id = ?");
    $stmt->bindValue(1, $data['customer_name']);
    $stmt->bindValue(2, $data['customer_email'] ?? '');
    $stmt->bindValue(3, $data['customer_phone'] ?? '');
    $stmt->bindValue(4, json_encode($data['items']));
    $stmt->bindValue(5, $subtotal);
    $stmt->bindValue(6, $tax);
    $stmt->bindValue(7, $total);
    $stmt->bindValue(8, $data['status'] ?? 'draft');
    $stmt->bindValue(9, $data['valid_until'] ?? '');
    $stmt->bindValue(10, $data['terms'] ?? '');
    $stmt->bindValue(11, $data['id']);

    if ($stmt->execute()) {
        logActivity('quote_updated', "Updated quotation ID: " . $data['id']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }

} elseif (isset($_GET['duplicate'])) {
    $id = $_GET['duplicate'];
    // Get original quote
    $original = $db->querySingle("SELECT * FROM quotations WHERE id = $id", true);
    if ($original) {
        // Generate new quote number
        $year = date('Y');
        $month = date('m');
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM quotations WHERE strftime('%Y-%m', created_at) = ?");
        $stmt->bindValue(1, "$year-$month");
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $seq = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
        $quote_number = "Q$year$month$seq";
        // Insert duplicate
        $stmt = $db->prepare("INSERT INTO quotations
            (quote_number, customer_name, customer_email, customer_phone, items, subtotal, tax, total, status, created_by, valid_until, terms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)");
        $stmt->bindValue(1, $quote_number);
        $stmt->bindValue(2, $original['customer_name']);
        $stmt->bindValue(3, $original['customer_email']);
        $stmt->bindValue(4, $original['customer_phone']);
        $stmt->bindValue(5, $original['items']);
        $stmt->bindValue(6, $original['subtotal']);
        $stmt->bindValue(7, $original['tax']);
        $stmt->bindValue(8, $original['total']);
        $stmt->bindValue(9, $user_id);
        $stmt->bindValue(10, $original['valid_until']);
        $stmt->bindValue(11, $original['terms']);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Quote not found']);
    }
    exit;
}

}

function api_recruitment() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

// Get recruitment pipeline data
$pipeline = [
    'applications' => '',
    'screening' => '',
    'interviews' => '',
    'offers' => '',
    'onboarding' => ''
];

// Applications (new)
$result = $db->query("SELECT * FROM applications WHERE status = 'new' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['applications'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-gray-400'>{$row['created_at']}</span>
                <button onclick='moveToScreening({$row['id']})' class='text-xs text-blue-600'>Screen</button>
            </div>
        </div>";
}

// Screening
$result = $db->query("SELECT * FROM applications WHERE status = 'screening' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['screening'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-gray-400'>Score: {$row['screening_score']}</span>
                <button onclick='scheduleInterview({$row['id']})' class='text-xs text-green-600'>Interview</button>
            </div>
        </div>";
}

// Interviews
$result = $db->query("SELECT * FROM applications WHERE status = 'interview' AND interview_date IS NOT NULL ORDER BY interview_date ASC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['interviews'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <p class='text-xs text-gray-400'>" . date('d M Y', strtotime($row['interview_date'])) . "</p>
            <div class='flex justify-between mt-1'>
                <button onclick='addFeedback({$row['id']})' class='text-xs text-purple-600'>Feedback</button>
                <button onclick='makeOffer({$row['id']})' class='text-xs text-green-600'>Offer</button>
            </div>
        </div>";
}

// Offers
$result = $db->query("SELECT * FROM applications WHERE status = 'offer' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['offers'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-green-600'>Offer Sent</span>
                <button onclick='startOnboarding({$row['id']})' class='text-xs text-blue-600'>Onboard</button>
            </div>
        </div>";
}

// Onboarding
$result = $db->query("SELECT * FROM applications WHERE status = 'onboarding' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['onboarding'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <p class='text-xs text-gray-400'>{$row['onboarding_status']}</p>
        </div>";
}

echo json_encode($pipeline);
}

function api_reports() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

// Ensure reports table exists
$db->exec("CREATE TABLE IF NOT EXISTS daily_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    report_date DATE NOT NULL,
    content TEXT NOT NULL,
    tasks_completed TEXT,
    blockers TEXT,
    mood INTEGER DEFAULT 3,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, report_date)
)");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date  = $_GET['date'] ?? date('Y-m-d');
    $month = $_GET['month'] ?? date('Y-m');
    $target_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

    // Build user visibility scope
    if (in_array($user_role, ['admin', 'manager'])) {
        // Admin/Manager see all under them
        $where = "1=1";
    } else {
        // Staff/Lead only see their own + direct reports
        $directReports = [];
        $res = $db->query("SELECT id FROM admin_users WHERE reporting_head = $user_id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $directReports[] = $row['id'];
        }
        $directReports[] = $user_id;
        $ids = implode(',', $directReports);
        $where = "r.user_id IN ($ids)";
    }

    if ($target_user) {
        $where .= " AND r.user_id = $target_user";
    }

    if (isset($_GET['date'])) {
        $safeDate = SQLite3::escapeString($date);
        $where .= " AND r.report_date = '$safeDate'";
    } else {
        $safeMonth = SQLite3::escapeString($month);
        $where .= " AND strftime('%Y-%m', r.report_date) = '$safeMonth'";
    }

    $result = $db->query("SELECT r.*, u.full_name, u.role, u.department
                          FROM daily_reports r
                          JOIN admin_users u ON r.user_id = u.id
                          WHERE $where
                          ORDER BY r.report_date DESC, u.full_name ASC");
    $reports = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $reports[] = $row;
    }
    echo json_encode($reports);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $report_date    = $data['report_date'] ?? date('Y-m-d');
    $content        = $data['content'] ?? '';
    $tasks_completed = $data['tasks_completed'] ?? '';
    $blockers       = $data['blockers'] ?? '';
    $mood           = intval($data['mood'] ?? 3);

    if (empty($content)) {
        echo json_encode(['error' => 'Report content is required']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO daily_reports
        (user_id, report_date, content, tasks_completed, blockers, mood, created_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $report_date);
    $stmt->bindValue(3, $content);
    $stmt->bindValue(4, $tasks_completed);
    $stmt->bindValue(5, $blockers);
    $stmt->bindValue(6, $mood);
    $stmt->execute();

    logActivity('daily_report', "Submitted daily report for $report_date");
    echo json_encode(['success' => true]);
}

}

function api_resume_upload() {
    global $db;
    if (!$db) $db = db();

$max=5*1024*1024;
$allowed=['pdf','doc','docx','jpg','jpeg','png'];

if(!isset($_FILES['resume'])){
 echo json_encode(['success'=>false]);
 exit;
}

$f=$_FILES['resume'];
$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));

if($f['size']>$max){ echo json_encode(['success'=>false]); exit; }
if(!in_array($ext,$allowed)){ echo json_encode(['success'=>false]); exit; }

$name='resume_'.time().'_'.$ext;
$path=__DIR__.'/../uploads/resumes/'.$name;

if(move_uploaded_file($f['tmp_name'],$path)){
 echo json_encode(['success'=>true,'path'=>'uploads/resumes/'.$name]);
}else{
 echo json_encode(['success'=>false]);
}

}

function api_salary() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['admin', 'manager'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    die();
}

$db = db();
$month_param = $_GET['month'] ?? date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Month stats
    $timestamp = strtotime($month_param . "-01");
    $days_in_month = (int)date('t', $timestamp);

    // 2. Count TOTAL global holidays/offs
    $stmt_global = $db->prepare("SELECT COUNT(DISTINCT start_date) FROM events
                                WHERE strftime('%Y-%m', start_date) = ?
                                AND event_type IN ('holiday', 'weekly-off')
                                AND target_type = 'all' AND is_approved = 1");
    $stmt_global->bindValue(1, $month_param);
    $total_global_offs = $stmt_global->execute()->fetchArray()[0];

    $users = $db->query("SELECT id, full_name, role, monthly_salary FROM admin_users WHERE is_active = 1");
    $report = [];

    while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
        $user_id = $user['id'];

        // Count user-specific holidays/weekly-offs (including their approval status)
        $stmt_user_events = $db->prepare("SELECT COUNT(DISTINCT start_date) FROM events
                                         WHERE strftime('%Y-%m', start_date) = ?
                                         AND event_type IN ('holiday', 'weekly-off')
                                         AND (target_type = 'specific' AND target_ids LIKE ?)
                                         AND is_approved = 1");
        $stmt_user_events->bindValue(1, $month_param);
        $stmt_user_events->bindValue(2, "%$user_id%");
        $user_specific_offs = $stmt_user_events->execute()->fetchArray()[0];

        $total_offs = $total_global_offs + $user_specific_offs;
        $expected_working_days = $days_in_month - $total_offs;

        // Count actual attendance
        $stmt_attendance = $db->prepare("SELECT COUNT(*) FROM attendance
                                        WHERE user_id = ? AND strftime('%Y-%m', date) = ?
                                        AND status IN ('ontime', 'late')");
        $stmt_attendance->bindValue(1, $user_id);
        $stmt_attendance->bindValue(2, $month_param);
        $attended_days = $stmt_attendance->execute()->fetchArray()[0];

        // Fetch bonus/deductions from salary_records
        $stmt_record = $db->prepare("SELECT * FROM salary_records WHERE user_id = ? AND month = ?");
        $stmt_record->bindValue(1, $user_id);
        $stmt_record->bindValue(2, $month_param);
        $record = $stmt_record->execute()->fetchArray(SQLITE3_ASSOC);

        $bonus = $record['bonus'] ?? 0;
        $deductions = $record['deductions'] ?? 0;

        // Formula: salary = (monthly salary / (days in month - total offs)) * attended days + bonus - deductions
        $base_salary_per_day = ($expected_working_days > 0) ? ($user['monthly_salary'] / $expected_working_days) : 0;
        $earned_salary = $base_salary_per_day * $attended_days;
        $final_salary = $earned_salary + $bonus - $deductions;

        $report[] = [
            'id' => $user_id,
            'name' => $user['full_name'],
            'role' => $user['role'],
            'monthly_salary' => $user['monthly_salary'],
            'attended_days' => $attended_days,
            'total_offs' => $total_offs,
            'expected_working_days' => $expected_working_days,
            'bonus' => $bonus,
            'deductions' => $deductions,
            'final_salary' => round($final_salary, 2),
            'status' => $record['status'] ?? 'pending'
        ];
    }

    echo json_encode($report);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'] ?? 0;
    $month = $data['month'] ?? date('Y-m');
    $bonus = $data['bonus'] ?? null;
    $deductions = $data['deductions'] ?? null;
    $status = $data['status'] ?? null;
    $monthly_salary = $data['monthly_salary'] ?? null;

    if ($monthly_salary !== null) {
        $stmt = $db->prepare("UPDATE admin_users SET monthly_salary = ? WHERE id = ?");
        $stmt->bindValue(1, $monthly_salary);
        $stmt->bindValue(2, $user_id);
        $stmt->execute();
    }

    if ($user_id > 0) {
        // Upsert logic for salary_records
        $existing = $db->querySingle("SELECT id FROM salary_records WHERE user_id = $user_id AND month = '$month'");

        if ($existing) {
            $updates = [];
            if ($bonus !== null) $updates[] = "bonus = $bonus";
            if ($deductions !== null) $updates[] = "deductions = $deductions";
            if ($status !== null) $updates[] = "status = '$status'";

            if (!empty($updates)) {
                $db->exec("UPDATE salary_records SET " . implode(', ', $updates) . " WHERE id = $existing");
            }
        } else {
            $stmt = $db->prepare("INSERT INTO salary_records (user_id, month, bonus, deductions, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $month);
            $stmt->bindValue(3, $bonus ?? 0);
            $stmt->bindValue(4, $deductions ?? 0);
            $stmt->bindValue(5, $status ?? 'pending');
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
    }
}

}

function api_salary_export() {
    global $db;
    if (!$db) $db = db();

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['admin', 'manager'])) {
    die("Unauthorized");
}

$db = db();
$month = $_GET['month'] ?? date('Y-m');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Salary_Report_' . $month . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Staff Name', 'Role', 'Monthly Salary', 'Attended Days', 'Expected Working Days', 'Offs', 'Bonus', 'Deductions', 'Final Salary', 'Status']);

// Reuse logic from salary.php
$timestamp = strtotime($month . "-01");
$days_in_month = (int)date('t', $timestamp);
$stmt_global = $db->prepare("SELECT COUNT(DISTINCT start_date) FROM events WHERE strftime('%Y-%m', start_date) = ? AND event_type IN ('holiday', 'weekly-off') AND target_type = 'all' AND is_approved = 1");
$stmt_global->bindValue(1, $month);
$total_global_offs = $stmt_global->execute()->fetchArray()[0];

$users = $db->query("SELECT id, full_name, role, monthly_salary FROM admin_users WHERE is_active = 1");

while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
    $user_id = $user['id'];
    $stmt_user_events = $db->prepare("SELECT COUNT(DISTINCT start_date) FROM events WHERE strftime('%Y-%m', start_date) = ? AND event_type IN ('holiday', 'weekly-off') AND (target_type = 'specific' AND target_ids LIKE ?) AND is_approved = 1");
    $stmt_user_events->bindValue(1, $month);
    $stmt_user_events->bindValue(2, "%$user_id%");
    $user_specific_offs = $stmt_user_events->execute()->fetchArray()[0];

    $total_offs = $total_global_offs + $user_specific_offs;
    $expected_working_days = $days_in_month - $total_offs;

    $stmt_att = $db->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND strftime('%Y-%m', date) = ? AND status IN ('ontime', 'late')");
    $stmt_att->bindValue(1, $user_id);
    $stmt_att->bindValue(2, $month);
    $attended_days = $stmt_att->execute()->fetchArray()[0];

    $stmt_rec = $db->prepare("SELECT * FROM salary_records WHERE user_id = ? AND month = ?");
    $stmt_rec->bindValue(1, $user_id);
    $stmt_rec->bindValue(2, $month);
    $record = $stmt_rec->execute()->fetchArray(SQLITE3_ASSOC);

    $bonus = $record['bonus'] ?? 0;
    $deductions = $record['deductions'] ?? 0;
    $base_salary_per_day = ($expected_working_days > 0) ? ($user['monthly_salary'] / $expected_working_days) : 0;
    $final_salary = ($base_salary_per_day * $attended_days) + $bonus - $deductions;

    fputcsv($output, [
        $user['full_name'],
        $user['role'],
        $user['monthly_salary'],
        $attended_days,
        $expected_working_days,
        $total_offs,
        $bonus,
        $deductions,
        round($final_salary, 2),
        $record['status'] ?? 'pending'
    ]);
}
fclose($output);

}

function api_settings() {
    global $db;
    if (!$db) $db = db();

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
}

function api_tasks() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role = $_SESSION['admin_role'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get tasks based on role
    switch ($role) {
        case 'admin':
            $query = "SELECT t.*, u.full_name as assigned_to_name
                      FROM tasks t
                      LEFT JOIN admin_users u ON t.assigned_to = u.id
                      ORDER BY t.due_date ASC";
            $result = $db->query($query);
            break;

        case 'manager':
            // Get department tasks
            $user = $db->querySingle("SELECT department FROM admin_users WHERE id = $user_id", true);
            $dept = $user['department'];
            $query = "SELECT t.*, u.full_name as assigned_to_name
                      FROM tasks t
                      LEFT JOIN admin_users u ON t.assigned_to = u.id
                      WHERE u.department = '$dept' OR t.assigned_by = $user_id
                      ORDER BY t.due_date ASC";
            $result = $db->query($query);
            break;

        case 'lead':
            // Get team tasks
            $user = $db->querySingle("SELECT team_id FROM admin_users WHERE id = $user_id", true);
            $team_id = $user['team_id'];
            $query = "SELECT t.*, u.full_name as assigned_to_name
                      FROM tasks t
                      LEFT JOIN admin_users u ON t.assigned_to = u.id
                      WHERE u.team_id = $team_id OR t.assigned_by = $user_id
                      ORDER BY t.due_date ASC";
            $result = $db->query($query);
            break;

        default:
            // Staff - only assigned tasks
            $query = "SELECT t.*, u.full_name as assigned_to_name
                      FROM tasks t
                      LEFT JOIN admin_users u ON t.assigned_to = u.id
                      WHERE t.assigned_to = $user_id
                      ORDER BY t.due_date ASC";
            $result = $db->query($query);
    }

    $tasks = [
        'todo' => [],
        'progress' => [],
        'done' => [],
        'review' => [],
        'archive' => []
    ];

    // Check if we need comments for a specific task
    if (isset($_GET['comments'])) {
        $task_id = intval($_GET['comments']);
        $stmt = $db->prepare("SELECT c.*, u.full_name as user_name
                              FROM task_comments c
                              JOIN admin_users u ON c.user_id = u.id
                              WHERE c.task_id = ?
                              ORDER BY c.created_at ASC");
        $stmt->bindValue(1, $task_id);
        $res = $stmt->execute();
        $comments = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $comments[] = $row;
        }
        echo json_encode($comments);
        exit;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        switch ($row['status']) {
            case 'pending':
                $tasks['todo'][] = $row;
                break;
            case 'in_progress':
                $tasks['progress'][] = $row;
                break;
            case 'completed':
                $tasks['done'][] = $row;
                break;
            case 'review':
                $tasks['review'][] = $row;
                break;
            case 'archived':
                $tasks['archive'][] = $row;
                break;
        }
    }

    echo json_encode($tasks);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($data['action']) && $data['action'] === 'comment') {
        $stmt = $db->prepare("INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $data['task_id']);
        $stmt->bindValue(2, $user_id);
        $stmt->bindValue(3, $data['comment']);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO tasks (title, description, assigned_to, assigned_by, priority, due_date)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $data['title']);
    $stmt->bindValue(2, $data['description']);
    $stmt->bindValue(3, $data['assigned_to']);
    $stmt->bindValue(4, $user_id);
    $stmt->bindValue(5, $data['priority'] ?? 'medium');
    $stmt->bindValue(6, $data['due_date']);
    $stmt->execute();

    $task_id = $db->lastInsertRowID();

    // Send notification to assigned user
    sendNotification($data['assigned_to'], 'task', 'New Task Assigned',
                     "You have been assigned a new task: {$data['title']}",
                     "admin.php?tab=ops&task=$task_id");

    logActivity('task_created', "Created task: {$data['title']}");

    echo json_encode(['success' => true, 'id' => $task_id]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['status'])) {
        $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $stmt->bindValue(1, $data['status']);
        $stmt->bindValue(2, $data['id']);
        $stmt->execute();

        if ($data['status'] === 'completed') {
            $stmt = $db->prepare("UPDATE tasks SET completed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $data['id']);
            $stmt->execute();
        }

        echo json_encode(['success' => true]);
    }
}
}

function api_team() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query("SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order ASC");
    $members = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $members[] = $row;
    }

    echo json_encode($members);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $stmt = $db->prepare("INSERT INTO team_members (name, position, bio, photo_url, display_order, is_active)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $data['name']);
    $stmt->bindValue(2, $data['position']);
    $stmt->bindValue(3, $data['bio'] ?? '');
    $stmt->bindValue(4, $data['photo_url'] ?? '');
    $stmt->bindValue(5, $data['display_order'] ?? 0);
    $stmt->bindValue(6, $data['is_active'] ?? 1);
    $stmt->execute();

    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    $stmt = $db->prepare("UPDATE team_members SET name = ?, position = ?, bio = ?, photo_url = ?, display_order = ?, is_active = ? WHERE id = ?");
    $stmt->bindValue(1, $data['name']);
    $stmt->bindValue(2, $data['position']);
    $stmt->bindValue(3, $data['bio'] ?? '');
    $stmt->bindValue(4, $data['photo_url'] ?? '');
    $stmt->bindValue(5, $data['display_order'] ?? 0);
    $stmt->bindValue(6, $data['is_active'] ?? 1);
    $stmt->bindValue(7, $data['id']);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;

    $stmt = $db->prepare("DELETE FROM team_members WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();

    echo json_encode(['success' => true]);
}

}

function api_templates() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        // Get single template
        $stmt = $db->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->bindValue(1, $_GET['id']);
        $result = $stmt->execute();
        $template = $result->fetchArray(SQLITE3_ASSOC);
        echo json_encode($template);
    } else {
        // Get templates by type
        $type = $_GET['type'] ?? 'staff_id';
        $stmt = $db->prepare("SELECT * FROM templates WHERE template_type = ? ORDER BY is_default DESC, name ASC");
        $stmt->bindValue(1, $type);
        $result = $stmt->execute();

        $html = '';
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $default_badge = $row['is_default'] ? '<span class="ml-2 bg-green-100 text-green-800 text-xs px-2 py-1 rounded">Default</span>' : '';
            $html .= "<div class='border rounded p-3 mb-2 flex justify-between items-center'>";
            $html .= "<div>";
            $html .= "<h5 class='font-bold'>{$row['name']} {$default_badge}</h5>";
            $html .= "<p class='text-xs text-gray-500'>Created: " . date('d/m/Y', strtotime($row['created_at'])) . "</p>";
            $html .= "</div>";
            $html .= "<div class='flex space-x-2'>";
            $html .= "<button onclick='editTemplate({$row['id']})' class='text-blue-600'><i class='fas fa-edit'></i></button>";
            $html .= "<button onclick='previewTemplate()' class='text-green-600'><i class='fas fa-eye'></i></button>";
            $html .= "<button onclick='deleteTemplate({$row['id']})' class='text-red-600'><i class='fas fa-trash'></i></button>";
            $html .= "</div>";
            $html .= "</div>";
        }

        if (empty($html)) {
            $html = "<p class='text-gray-500 text-center py-4'>No templates found. Create your first template.</p>";
        }

        echo $html;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['id'])) {
        // Update existing template
        if (isset($data['is_default']) && $data['is_default']) {
            // Clear default flag for this type
            $stmt = $db->prepare("UPDATE templates SET is_default = 0 WHERE template_type = ?");
            $stmt->bindValue(1, $data['type']);
            $stmt->execute();
        }

        $stmt = $db->prepare("UPDATE templates SET name = ?, content = ?, css = ?, is_default = ? WHERE id = ?");
        $stmt->bindValue(1, $data['name']);
        $stmt->bindValue(2, $data['content']);
        $stmt->bindValue(3, $data['css'] ?? '');
        $stmt->bindValue(4, $data['is_default'] ? 1 : 0);
        $stmt->bindValue(5, $data['id']);
        $stmt->execute();
    } else {
        // Create new template
        if (isset($data['is_default']) && $data['is_default']) {
            // Clear default flag for this type
            $stmt = $db->prepare("UPDATE templates SET is_default = 0 WHERE template_type = ?");
            $stmt->bindValue(1, $data['type']);
            $stmt->execute();
        }

        $stmt = $db->prepare("INSERT INTO templates (template_type, name, content, css, is_default, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $data['type']);
        $stmt->bindValue(2, $data['name']);
        $stmt->bindValue(3, $data['content']);
        $stmt->bindValue(4, $data['css'] ?? '');
        $stmt->bindValue(5, $data['is_default'] ? 1 : 0);
        $stmt->bindValue(6, $user_id);
        $stmt->execute();
    }

    logActivity('template_saved', "Saved template: {$data['name']}");

    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;

    $stmt = $db->prepare("DELETE FROM templates WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();

    echo json_encode(['success' => true]);
}

}

function api_test_email() {
    global $db;
    if (!$db) $db = db();







header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    die();
}

$data = json_decode(file_get_contents('php://input'), true);
$mail = new \PHPMailer\PHPMailer\PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $data['smtp_host'] ?? '';
    $mail->SMTPAuth = true;
    $mail->Username = $data['smtp_user'] ?? '';
    $mail->Password = $data['smtp_pass'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $data['smtp_port'] ?? 587;
    $mail->setFrom($data['from_email'] ?? '', 'Test');
    $mail->addAddress($data['from_email'] ?? '');
    $mail->isHTML(true);
    $mail->Subject = 'Test Email';
    $mail->Body = 'Test';
    $mail->send();
    echo json_encode(['success' => true]);
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $mail->ErrorInfo]);
}

}

function api_users() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = $_SESSION['admin_id'];
    $user_role = $_SESSION['admin_role'];
    $is_list = isset($_GET['list']) && $_GET['list'] == 1;

    if ($user_role === 'admin') {
        $result = $db->query("SELECT id, username, email, full_name, role, department, team_id, is_active, last_login
                            FROM admin_users ORDER BY created_at DESC");
    } elseif ($user_role === 'manager') {
        $stmt = $db->prepare("SELECT id, username, email, full_name, role, department, team_id, is_active, last_login
                              FROM admin_users WHERE role = 'staff' OR id = ? ORDER BY created_at DESC");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT id, username, email, full_name, role, department, team_id, is_active, last_login
                              FROM admin_users WHERE id = ? ORDER BY created_at DESC");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
    }

    if ($is_list) {
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        echo json_encode($users);
        exit;
    }

    $html = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $status_class = $row['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
        $status_text = $row['is_active'] ? 'Active' : 'Inactive';

        $html .= "<tr class='border-b'>";
        $html .= "<td class='py-2'>{$row['full_name']}</td>";
        $html .= "<td class='py-2'>{$row['email']}</td>";
        $html .= "<td class='py-2'>" . ucfirst($row['role']) . "</td>";
        $html .= "<td class='py-2'>{$row['department']}</td>";
        $html .= "<td class='py-2'><span class='px-2 py-1 rounded-full text-xs $status_class'>$status_text</span></td>";
        $html .= "<td class='py-2'>
                    <button onclick='editUser({$row['id']})' class='text-blue-600 mr-2'><i class='fas fa-edit'></i></button>
                    <button onclick='toggleUser({$row['id']})' class='text-yellow-600 mr-2'><i class='fas fa-ban'></i></button>
                    <button onclick='deleteUser({$row['id']})' class='text-red-600'><i class='fas fa-trash'></i></button>
                  </td>";
        $html .= "</tr>";
    }

    echo $html;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Check if username exists
    $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->bindValue(1, $data['username']);
    $result = $stmt->execute();

    if ($result->fetchArray()) {
        http_response_code(400);
        echo json_encode(['error' => 'Username already exists']);
        exit;
    }

    // Generate employee ID
    $emp_id = 'EMP' . date('Y') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

    $stmt = $db->prepare("INSERT INTO admin_users
        (username, password_hash, email, full_name, role, department, team_id, employee_id, phone, reporting_head, monthly_salary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bindValue(1, $data['username']);
    $stmt->bindValue(2, password_hash($data['password'], PASSWORD_DEFAULT));
    $stmt->bindValue(3, $data['email']);
    $stmt->bindValue(4, $data['full_name']);
    $stmt->bindValue(5, $data['role']);
    $stmt->bindValue(6, $data['department'] ?? '');
    $stmt->bindValue(7, $data['team_id'] ?? 0);
    $stmt->bindValue(8, $emp_id);
    $stmt->bindValue(9, $data['phone'] ?? '');
    $stmt->bindValue(10, $data['reporting_head'] ?? 0);
    $stmt->bindValue(11, $data['monthly_salary'] ?? 0);
    $stmt->execute();

    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    $id = intval($data['id']);

    // Check if we are only toggling status
    if (isset($data['action']) && $data['action'] === 'toggle') {
        $stmt = $db->prepare("UPDATE admin_users SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bindValue(1, $id);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    $sql = "UPDATE admin_users SET
            email = ?, full_name = ?, role = ?, department = ?, team_id = ?,
            phone = ?, reporting_head = ?, monthly_salary = ?";

    $params = [
        $data['email'], $data['full_name'], $data['role'],
        $data['department'] ?? '', $data['team_id'] ?? 0,
        $data['phone'] ?? '', $data['reporting_head'] ?? 0,
        $data['monthly_salary'] ?? 0
    ];

    if (!empty($data['password'])) {
        $sql .= ", password_hash = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    if (isset($data['is_active'])) {
        $sql .= ", is_active = ?";
        $params[] = $data['is_active'] ? 1 : 0;
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;

    $stmt = $db->prepare($sql);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
    $stmt->bindValue(1, $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }
}

}

function api_vacancies() {
    global $db;
    if (!$db) $db = db();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role    = $_SESSION['admin_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $show_all = isset($_GET['all']) && ($role === 'admin' || $role === 'manager');
    $where    = $show_all ? '' : 'WHERE is_active = 1';
    $result   = $db->query("SELECT * FROM open_positions $where ORDER BY urgent DESC, created_at DESC");
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    echo json_encode($rows);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if (isset($data['id']) && $data['id']) {
        $stmt = $db->prepare("UPDATE open_positions SET title=?, location=?, type=?, salary=?, description=?, requirements=?, urgent=?, is_active=? WHERE id=?");
        $stmt->bindValue(1, $data['title'] ?? '');
        $stmt->bindValue(2, $data['location'] ?? '');
        $stmt->bindValue(3, $data['type'] ?? '');
        $stmt->bindValue(4, $data['salary'] ?? '');
        $stmt->bindValue(5, $data['description'] ?? '');
        $stmt->bindValue(6, $data['requirements'] ?? '');
        $stmt->bindValue(7, ($data['urgent'] ?? false) ? 1 : 0);
        $stmt->bindValue(8, ($data['is_active'] ?? 1) ? 1 : 0);
        $stmt->bindValue(9, intval($data['id']));
        $stmt->execute();
        logActivity('vacancy_updated', "Updated vacancy: " . ($data['title'] ?? ''));
    } else {
        $stmt = $db->prepare("INSERT INTO open_positions (title, location, type, salary, description, requirements, urgent) VALUES (?,?,?,?,?,?,?)");
        $stmt->bindValue(1, $data['title'] ?? '');
        $stmt->bindValue(2, $data['location'] ?? '');
        $stmt->bindValue(3, $data['type'] ?? '');
        $stmt->bindValue(4, $data['salary'] ?? '');
        $stmt->bindValue(5, $data['description'] ?? '');
        $stmt->bindValue(6, $data['requirements'] ?? '');
        $stmt->bindValue(7, ($data['urgent'] ?? false) ? 1 : 0);
        $stmt->execute();
        logActivity('vacancy_created', "Created vacancy: " . ($data['title'] ?? ''));
    }
    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array($role, ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $id = intval($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("UPDATE open_positions SET is_active = 0 WHERE id = ?");
        $stmt->bindValue(1, $id);
        $stmt->execute();
        logActivity('vacancy_deleted', "Deactivated vacancy ID: $id");
    }
    echo json_encode(['success' => true]);
}
}

function api_verify_qr() {
    global $db;
    if (!$db) $db = db();

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
}

function api_verify_worker() {
    global $db;
    if (!$db) $db = db();

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

}

function api_workers() {
    global $db;
    if (!$db) $db = db();

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
}

function api_public_chat() {
    global $db;
    if (!$db) $db = db();

// ===== public_chat.php =====
// Handles public/guest chat messages
// Include this in your public chat page


header('Content-Type: application/json');

session_start();

$db = db();

// Generate or get session ID for guest
if (!isset($_SESSION['guest_chat_id'])) {
    $_SESSION['guest_chat_id'] = 'guest_' . uniqid() . '_' . rand(1000, 9999);
}
$session_id = $_SESSION['guest_chat_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

    // Ensure session exists
    $check = $db->prepare("SELECT id FROM chat_sessions WHERE session_id = ?");
    $check->bindValue(1, $session_id);
    $result = $check->execute();

    if (!$result->fetchArray()) {
        // Create new session
        $name = $_SESSION['guest_name'] ?? 'Guest';
        $email = $_SESSION['guest_email'] ?? '';
        $reason = $_SESSION['guest_reason'] ?? 'General Inquiry';

        $insert = $db->prepare("INSERT INTO chat_sessions
            (session_id, guest_name, guest_email, contact_reason, status, last_activity, created_at)
            VALUES (?, ?, ?, ?, 'active', datetime('now'), datetime('now'))");
        $insert->bindValue(1, $session_id);
        $insert->bindValue(2, $name);
        $insert->bindValue(3, $email);
        $insert->bindValue(4, $reason);
        $insert->execute();

        // Add welcome message
        $welcome = $db->prepare("INSERT INTO chat_messages
            (session_id, sender_type, sender_name, message, type, is_read, created_at)
            VALUES (?, 'system', 'System', 'Welcome! How can we help you today?', 'guest', 1, datetime('now'))");
        $welcome->bindValue(1, $session_id);
        $welcome->execute();
    }

    // Get messages
    if ($since_id > 0) {
        $stmt = $db->prepare("SELECT * FROM chat_messages
                              WHERE session_id = ?
                              AND id > ?
                              AND sender_type != 'guest'
                              ORDER BY created_at ASC");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $since_id);
    } else {
        $stmt = $db->prepare("SELECT * FROM chat_messages
                              WHERE session_id = ?
                              ORDER BY created_at ASC
                              LIMIT 50");
        $stmt->bindValue(1, $session_id);
    }

    $result = $stmt->execute();
    $messages = [];
    $last_id = $since_id;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['time'] = date('h:i A', strtotime($row['created_at']));
        $row['is_me'] = ($row['sender_type'] === 'guest');
        $messages[] = $row;
        $last_id = $row['id'];
    }

    // Mark admin messages as read
    $db->exec("UPDATE chat_messages SET is_read = 1
              WHERE session_id = '$session_id'
              AND sender_type = 'admin'
              AND is_read = 0");

    echo json_encode([
        'messages' => $messages,
        'last_id' => $last_id,
        'session_id' => $session_id
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $message = trim($data['message'] ?? '');
    $name = $data['name'] ?? $_SESSION['guest_name'] ?? 'Guest';
    $email = $data['email'] ?? $_SESSION['guest_email'] ?? '';
    $reason = $data['reason'] ?? $_SESSION['guest_reason'] ?? 'General Inquiry';
    $temp_id = $data['temp_id'] ?? uniqid('guest_');

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required']);
        exit;
    }

    // Store guest info in session
    $_SESSION['guest_name'] = $name;
    $_SESSION['guest_email'] = $email;
    $_SESSION['guest_reason'] = $reason;

    $db->exec("BEGIN TRANSACTION");

    try {
        // Update or create session
        $check = $db->prepare("SELECT id FROM chat_sessions WHERE session_id = ?");
        $check->bindValue(1, $session_id);
        $result = $check->execute();

        if ($result->fetchArray()) {
            // Update existing session
            $update = $db->prepare("UPDATE chat_sessions SET
                guest_name = ?, guest_email = ?, contact_reason = ?,
                last_activity = datetime('now'), status = 'active'
                WHERE session_id = ?");
            $update->bindValue(1, $name);
            $update->bindValue(2, $email);
            $update->bindValue(3, $reason);
            $update->bindValue(4, $session_id);
            $update->execute();
        } else {
            // Create new session
            $insert = $db->prepare("INSERT INTO chat_sessions
                (session_id, guest_name, guest_email, contact_reason, status, last_activity, created_at)
                VALUES (?, ?, ?, ?, 'active', datetime('now'), datetime('now'))");
            $insert->bindValue(1, $session_id);
            $insert->bindValue(2, $name);
            $insert->bindValue(3, $email);
            $insert->bindValue(4, $reason);
            $insert->execute();
        }

        // Insert message
        $stmt = $db->prepare("INSERT INTO chat_messages
            (session_id, sender_type, sender_name, message, type, is_read, created_at)
            VALUES (?, 'guest', ?, ?, 'guest', 0, datetime('now'))");
        $stmt->bindValue(1, $session_id);
        $stmt->bindValue(2, $name);
        $stmt->bindValue(3, $message);
        $stmt->execute();

        $message_id = $db->lastInsertRowID();

        $db->exec("COMMIT");

        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'temp_id' => $temp_id,
            'session_id' => $session_id
        ]);

    } catch (\Exception $e) {
        $db->exec("ROLLBACK");
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

}


class AdminPanel {
    private $db;
    private $user;
    private $role;
    private $settings;

    public function __construct() {
        $this->db = db();
        $this->checkAuth();
        $this->loadUser();
        $this->loadSettings();
    }

    private function checkAuth() {
        if (isset($_GET['logout'])) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();
            header('Location: ?action=login');
            exit;
        }

        $public_pages = ['login', 'verify_qr'];
        $current_page = $_GET['page'] ?? 'login';

        if (!isset($_SESSION['admin_id']) && !in_array($current_page, $public_pages)) {
            header('Location: ?page=login');
            exit;
        }
    }

    private function loadUser() {
        if (isset($_SESSION['admin_id'])) {
            $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE id = ?");
            $stmt->bindValue(1, $_SESSION['admin_id']);
            $result = $stmt->execute();
            $this->user = $result->fetchArray(SQLITE3_ASSOC);
            if ($this->user) {
                $this->role = $this->user['role'];
            }
        }
    }

    private function loadSettings() {
        $settings = [];
        $result = $this->db->query("SELECT * FROM site_settings");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $this->settings = $settings;
    }

    public function render() {
        $page = $_GET['page'] ?? 'dashboard';

        // HTMX partial mode: return only the main content HTML (no full page wrapper)

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Panel - D K Associates</title>

            <!-- Tailwind CSS -->
            <script src="https://cdn.tailwindcss.com"></script>

            <!-- Alpine.js -->
            <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

            <!-- HTMX -->
            <script src="https://unpkg.com/htmx.org@1.9.10"></script>

            <!-- Chart.js -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

            <!-- SortableJS -->
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

            <!-- SimpleMDE Markdown Editor -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
            <script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>

            <!-- Pikaday -->
            <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/pikaday/css/pikaday.css">
            <script src="https://cdn.jsdelivr.net/npm/pikaday/pikaday.js"></script>

            <!-- Choices.js -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
            <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

            <!-- Font Awesome -->
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

            <!-- QR Code Generator -->
            <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>

            <!-- html2canvas for ID card download -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>


        </head>

        <body class="bg-gray-100 font-sans text-gray-800" x-data="app()">
            <!-- Loading Overlay -->
            <div x-show="loading" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                <div class="animate-spin rounded-full h-32 w-32 border-b-2 border-white"></div>
            </div>

            <!-- Notification Toast -->
            <div x-show="notification.show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 transform translate-x-full"
                 x-transition:enter-end="opacity-100 transform translate-x-0"
                 class="fixed top-4 right-4 z-50 max-w-sm bg-white rounded-lg shadow-lg p-4">
                <div class="flex items-center">
                    <div :class="'w-2 h-2 rounded-full mr-2 ' + notification.type"></div>
                    <p class="text-sm" x-text="notification.message"></p>
                    <button @click="notification.show = false" class="ml-4 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <?php if (isset($_SESSION['admin_id'])): ?>
            <!-- Sidebar -->
            <div class="fixed inset-y-0 left-0 w-64 bg-gray-900 text-white overflow-y-auto">
                <div class="p-4 border-b border-gray-700">
                    <?php
                    $logo = $this->settings['site_logo'] ?? '';
                    $siteTitle = htmlspecialchars($this->settings['site_title'] ?? 'Admin Panel');
                    $isUrl = filter_var($logo, FILTER_VALIDATE_URL);
                    $isPath = !empty($logo) && $logo[0] === '/' && file_exists(dirname(__DIR__) . $logo);
                    ?>
                    <div class="flex items-center gap-3 mb-2">
                        <?php if ($isUrl || $isPath): ?>
                        <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="h-8 w-auto object-contain" onerror="this.remove()">
                        <?php elseif (!empty($logo) && mb_strlen($logo) <= 4): ?>
                        <span class="text-2xl"><?php echo htmlspecialchars($logo); ?></span>
                        <?php endif; ?>
                        <h1 class="text-lg font-bold truncate"><?php echo $siteTitle; ?></h1>
                    </div>
                    <p class="text-xs text-gray-400">Welcome, <?php echo htmlspecialchars($this->user['full_name']); ?></p>
                    <p class="text-xs text-gray-500"><?php echo ucfirst($this->role); ?></p>
                </div>

                <nav class="mt-4">
                    <?php
                                        $menu_items = [
                        'dashboard' => ['icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
                        'ops'       => ['icon' => 'fa-gears',       'label' => 'Operations'],
                        'crm'       => ['icon' => 'fa-users',       'label' => 'CRM'],
                        'staff'     => ['icon' => 'fa-user-tie',    'label' => 'Staff'],
                        'documents' => ['icon' => 'fa-folder-open', 'label' => 'Documents'],
                        'payroll'   => ['icon' => 'fa-money-bill',  'label' => 'Payroll']
                    ];

                    // Settings: admin role only
                    if ($this->role === 'admin' || $this->user['tech_permission'] == 1 || $this->user['admin_permission'] == 1) {
                        $menu_items['settings'] = ['icon' => 'fa-gear', 'label' => 'Settings'];
                    }

                    // Settings: admin role only


                    foreach ($menu_items as $key => $item):
                        $active = ($page === $key) ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white';
                    ?>
                    <a href="?page=<?php echo $key; ?>"

                       hx-push-url="?tab=<?php echo $key; ?>"
                       hx-on:htmx:before-request="document.getElementById('main-content').classList.add('htmx-loading')"
                       hx-on:htmx:after-settle="document.getElementById('main-content').classList.remove('htmx-loading')"
                       class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white transition rounded-md mx-2 my-0.5 nav-link"
                       data-tab="<?php echo $key; ?>"
                       onclick="setActiveNav(this)"
                       >
                        <i class="fas <?php echo $item['icon']; ?> w-6"></i>
                        <span><?php echo $item['label']; ?></span>

                        <?php if ($key === 'ops' && $this->getUnreadCount() > 0): ?>
                        <span class="ml-auto bg-red-500 text-xs px-2 py-1 rounded-full">
                            <?php echo $this->getUnreadCount(); ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </nav>

                <div class="absolute bottom-0 left-0 right-0 p-4">
                    <a href="?logout=1" class="flex items-center px-4 py-2 hover:bg-gray-800 rounded">
                        <i class="fas fa-sign-out-alt w-6"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>

            <!-- Main Content (HTMX target) -->
            <div id="main-content" class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100">
                <?php
            switch ($page) {
                case 'ops':        $this->renderOps(); break;
                case 'crm':        echo '<div x-data="{ activeTab: \'crm\' }">'; $this->renderManagement(); echo '</div>'; break;
                case 'staff':      echo '<div x-data="{ activeTab: \'directory\' }">'; $this->renderManagement(); echo '</div>'; break;
                case 'documents':  echo '<div x-data="{ activeTab: \'documents\' }">'; $this->renderOps(); echo '</div>'; break;
                case 'payroll':    echo '<div x-data="{ activeTab: \'payroll\' }">'; $this->renderManagement(); echo '</div>'; break;
                case 'management': $this->renderManagement(); break;
                case 'profile':    $this->renderProfile(); break;
                case 'settings':
                    $hasTechPerm = isset($this->user['tech_permission']) && $this->user['tech_permission'] == 1;
                    $hasAdminPerm= isset($this->user['admin_permission']) && $this->user['admin_permission'] == 1;
                    if ($this->role === 'admin' || $hasTechPerm || $hasAdminPerm) {
                        $this->renderSettings();
                    } else {
                        http_response_code(403);
                        echo '<div class="p-8 text-red-600 font-bold">Access Denied.</div>';
                    }
                    break;
                default:           $this->renderDashboard();
            }
                ?>
            </div><!-- end #main-content -->

            <?php if ($this->role !== 'staff' || $this->user['care_permission'] == 1): ?>
<div x-data="chatWidget()" class="fixed bottom-4 right-4 z-40">
    <!-- Chat Toggle -->
    <button @click="toggleChat"
            class="bg-blue-600 text-white rounded-full w-14 h-14 shadow-lg hover:bg-blue-700 transition relative">
        <i class="fas fa-comment" x-show="!isOpen"></i>
        <i class="fas fa-times" x-show="isOpen"></i>
        <span x-show="queueCount > 0 && !isOpen" 
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"
              x-text="queueCount"></span>
    </button>

    <!-- Chat Window -->
    <div x-show="isOpen"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform scale-95"
         x-transition:enter-end="opacity-100 transform scale-100"
         class="absolute bottom-16 right-0 w-[32rem] bg-white rounded-lg shadow-xl border">

        <!-- Header with Tabs -->
        <div class="bg-blue-600 text-white p-3 rounded-t-lg">
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold">Team Communicator</h3>
                <button @click="isOpen = false" class="text-white hover:text-gray-200">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
            <div class="flex space-x-1 text-sm">
                <button @click="activeTab = 'guest'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'guest'}"
                        class="px-3 py-1 rounded flex-1">
                    Guest Chat <span x-show="queueCount > 0" class="ml-1 bg-red-500 px-1.5 rounded-full text-xs" x-text="queueCount"></span>
                </button>
                <button @click="activeTab = 'staff'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'staff'}"
                        class="px-3 py-1 rounded flex-1">
                    Staff
                </button>
                <button @click="activeTab = 'team'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'team'}"
                        class="px-3 py-1 rounded flex-1">
                    Team
                </button>
                <button @click="activeTab = 'broadcast'; loadMessages();"
                        :class="{'bg-blue-700': activeTab === 'broadcast'}"
                        class="px-3 py-1 rounded flex-1">
                    Broadcast
                </button>
            </div>
        </div>

        <!-- Session List for Guest Chat -->
        <div x-show="activeTab === 'guest'" class="border-b max-h-32 overflow-y-auto bg-gray-50">
            <template x-for="session in guestSessions" :key="session.session_id">
                <div @click="selectSession(session.session_id)"
                     :class="{'bg-blue-100': selectedSession === session.session_id}"
                     class="px-4 py-2 cursor-pointer hover:bg-gray-100 flex justify-between items-center border-b last:border-b-0">
                    <div class="flex-1">
                        <div class="flex items-center">
                            <span class="font-medium text-sm" x-text="session.guest_name || 'Guest'"></span>
                            <span x-show="session.unread_count > 0" 
                                  class="ml-2 bg-red-500 text-white text-xs rounded-full px-2 py-0.5"
                                  x-text="session.unread_count"></span>
                        </div>
                        <div class="text-xs text-gray-500 flex items-center">
                            <span x-text="session.contact_reason || 'General'"></span>
                            <span class="mx-1">•</span>
                            <span x-text="session.last_activity_formatted"></span>
                        </div>
                        <div x-show="session.last_message" class="text-xs text-gray-600 truncate max-w-xs" x-text="session.last_message"></div>
                    </div>
                    <button @click.stop="terminateSession(session.session_id)" 
                            class="text-red-500 hover:text-red-700 text-xs ml-2"
                            title="Terminate Chat">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </div>
            </template>
            <div x-show="guestSessions.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center">
                No active guest chats
            </div>
        </div>

        <!-- Messages Area -->
        <div class="h-96 overflow-y-auto p-4 bg-gray-50" x-ref="messages">
            <template x-for="msg in messages" :key="msg.id || msg.temp_id">
                <div :class="{'flex justify-end': msg.sender_type === 'admin' || msg.sender_type === 'system'}"
                     class="mb-3">
                    <div :class="{
                            'bg-blue-600 text-white': msg.sender_type === 'admin',
                            'bg-gray-300 text-gray-800': msg.sender_type === 'system',
                            'bg-gray-200 text-gray-800': msg.sender_type !== 'admin' && msg.sender_type !== 'system',
                            'opacity-50': msg.is_temp
                         }"
                         class="inline-block p-3 rounded-lg max-w-xs shadow-sm">
                        <p class="text-xs font-bold mb-1 flex items-center">
                            <span x-text="msg.sender_name || (msg.sender_type === 'admin' ? 'You' : 'Guest')"></span>
                            <span x-show="msg.sender_type === 'system'" class="ml-1 text-xs">(System)</span>
                        </p>
                        <p class="text-sm break-words" x-text="msg.message"></p>
                        <div class="flex justify-end items-center mt-1 space-x-1">
                            <p class="text-xs opacity-75" x-text="msg.time"></p>
                            <i x-show="msg.is_read && msg.sender_type === 'admin'" class="fas fa-check-double text-xs text-green-300"></i>
                            <i x-show="!msg.is_read && msg.sender_type === 'admin'" class="fas fa-check text-xs"></i>
                        </div>
                    </div>
                </div>
            </template>
            <div x-show="messages.length === 0" class="text-center text-gray-400 py-8">
                No messages yet. Start the conversation!
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-3 border-t bg-white">
            <form @submit.prevent="sendMessage">
                <div class="flex space-x-2">
                    <input type="text"
                           x-model="newMessage" @input="sendTyping"
                           :placeholder="'Type your ' + (activeTab === 'broadcast' ? 'broadcast' : activeTab) + ' message...'"
                           class="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-600"
                           :disabled="activeTab === 'guest' && !selectedSession">
                    <button type="submit"
                            :disabled="activeTab === 'guest' && !selectedSession"
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>

                <!-- Staff Selector -->
                <div x-show="activeTab === 'staff'" class="mt-2">
                    <select id="staffSelect" x-model="receiverId" class="w-full text-sm border rounded px-2 py-1">
                        <option value="">Select staff member...</option>
                        <template x-for="staff in staffList" :key="staff.id">
                            <option :value="staff.id" x-text="staff.full_name + ' (' + staff.role + ')'"></option>
                        </template>
                    </select>
                </div>

                <!-- Attachment Button -->
                <div class="mt-2 flex justify-between items-center">
                    <button type="button" @click="document.getElementById('chatFile').click()"
                            class="text-gray-500 hover:text-blue-600 text-sm">
                        <i class="fas fa-paperclip mr-1"></i>Attach File
                    </button>
                    <span x-show="activeTab === 'guest' && !selectedSession" class="text-xs text-red-500">
                        Select a guest session first
                    </span>
                </div>
                <input type="file" id="chatFile" class="hidden" @change="uploadFile">
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

            <?php else: ?>
            <!-- Login Page -->
            <?php $this->renderLogin(); ?>
            <?php endif; ?>

            
        </body>
        </html>
        <?php
    }

    private function renderDashboard() {
        ?>
        <div x-data="dashboard()" x-init="init()">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold">Command Center</h1>
                <div class="flex space-x-2">
                    <button @click="punchInOut"
                            :class="isPunchedIn ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700'"
                            class="text-white px-4 py-2 rounded-lg transition">
                        <i :class="isPunchedIn ? 'fas fa-sign-out-alt' : 'fas fa-sign-in-alt'" class="mr-2"></i>
                        <span x-text="isPunchedIn ? 'Punch Out' : 'Punch In'"></span>
                    </button>
                    <button @click="showAddNote = true" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-sticky-note mr-2"></i>Add Note
                    </button>
                </div>
            </div>

            <!-- Sticky Notes Display -->
            <div x-show="activeNotes.length > 0" class="fixed bottom-4 right-4 z-50">
                <template x-for="(note, index) in activeNotes" :key="note.id">
                    <div class="sticky-note mb-2" :style="'background-color: ' + note.color + ';'">
                        <div class="flex justify-between items-center mb-2">
                            <i class="fas fa-sticky-note"></i>
                            <button @click="closeNote(note.id)" class="text-gray-600 hover:text-red-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="note-content" x-text="note.content"></div>
                        <div class="text-xs text-gray-500 mt-2" x-text="new Date(note.created_at).toLocaleString()"></div>
                    </div>
                </template>
            </div>

            <!-- Summary Widgets -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <?php
                $widgets = [
                    ['title' => 'Tasks', 'icon' => 'fa-tasks', 'color' => 'blue',
                     'count' => $this->getTaskCounts()],
                    ['title' => 'Applications', 'icon' => 'fa-file-alt', 'color' => 'green',
                     'count' => $this->getApplicationCounts()],
                    ['title' => 'Enquiries', 'icon' => 'fa-question-circle', 'color' => 'yellow',
                     'count' => $this->getEnquiryCounts()],
                    ['title' => 'Workers', 'icon' => 'fa-users', 'color' => 'purple',
                     'count' => $this->getWorkerCounts()]
                ];

                foreach ($widgets as $widget):
                ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm"><?php echo $widget['title']; ?></p>
                            <p class="text-2xl font-bold">
                                <?php echo $widget['count']['total'] ?? 0; ?>
                                <?php if (isset($widget['count']['pending'])): ?>
                                <span class="text-sm text-gray-400 ml-2">
                                    (<?php echo $widget['count']['pending']; ?> pending)
                                </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="bg-<?php echo $widget['color']; ?>-100 p-3 rounded-lg">
                            <i class="fas <?php echo $widget['icon']; ?> text-<?php echo $widget['color']; ?>-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 3-Month Calendar -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Interactive Calendar</h2>
                    <div class="flex space-x-2">
                        <button @click="changeMonth(-1)" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <button @click="changeMonth(0)" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Current
                        </button>
                        <button @click="changeMonth(1)" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <template x-for="(month, index) in months" :key="index">
                            <div>
                                <h3 class="font-bold text-center mb-2 text-sm" x-text="month.name"></h3>
                                <div class="grid grid-cols-7 gap-0.5 text-xs">
                                    <template x-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d">
                                        <div class="text-center font-semibold text-gray-400 py-1" x-text="d.charAt(0)"></div>
                                    </template>
                                    <template x-for="day in month.days" :key="(day.full_date || 'pad_') + day.date">
                                        <div @click="day.date && selectDate(day)"
                                             :title="day.full_date ? (day.status || 'No record') : ''"
                                             :style="day.date ? calendarCellStyle(day.status) : ''"
                                             :class="[
                                                'text-center py-1 rounded text-xs',
                                                day.date ? 'cursor-pointer hover:opacity-80' : 'invisible',
                                                !day.status ? 'bg-white text-gray-700 border border-gray-100' : ''
                                             ]">
                                            <span x-text="day.date || ''"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Pending Approvals -->
                    <template x-if="pendingApprovals.length > 0">
                        <div class="mt-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                            <h4 class="font-bold text-orange-800 mb-2"><i class="fas fa-exclamation-circle mr-2"></i>Pending Approvals</h4>
                            <div class="space-y-2">
                                <template x-for="event in pendingApprovals" :key="event.id">
                                    <div class="flex justify-between items-center bg-white p-2 rounded shadow-sm">
                                        <div class="text-sm">
                                            <span class="font-bold" x-text="event.title"></span> on <span x-text="event.start_date"></span>
                                            <p class="text-xs text-gray-500" x-text="event.description"></p>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="approveEvent(event.id)" class="bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700">Approve</button>
                                            <button @click="showRejectReason(event.id)" class="bg-red-600 text-white px-3 py-1 rounded text-xs hover:bg-red-700">Reject</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Legend -->
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="flex items-center"><span class="w-3 h-3 bg-green-200 rounded mr-1"></span> On-time</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-yellow-200 rounded mr-1"></span> Late</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-red-200 rounded mr-1"></span> Early</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-purple-200 rounded mr-1"></span> Leave</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-blue-200 rounded mr-1"></span> Holiday</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-gray-200 rounded mr-1"></span> Week-off</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-orange-200 rounded mr-1"></span> Event</span>
                        <span class="flex items-center"><span class="w-3 h-3 bg-amber-200 rounded mr-1"></span> Expiry</span>
                    </div>
                </div>
            </div>

            <!-- Activity Feed -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b">
                    <h2 class="text-xl font-bold">Recent Activity</h2>
                </div>
                <div class="p-4">
                    <div class="space-y-3" x-html="activityFeed"></div>
                </div>
            </div>

            <!-- Quick Note Modal -->
            <div x-show="showAddNote" @click.outside="showAddNote = false" @keydown.escape.window="showAddNote = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Quick Note</h3>
                    <textarea x-model="newNote" class="w-full border rounded p-2 h-32 mb-4" placeholder="Type your note..."></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showAddNote = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="saveNote" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                    </div>
                </div>
            </div>

            <!-- Reject Reason Modal -->
            <div x-show="showRejectModal" @click.outside="showRejectModal = false" @keydown.escape.window="showRejectModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Rejection Reason</h3>
                    <textarea x-model="rejectReason" class="w-full border rounded p-2 h-32 mb-4" placeholder="Please provide reason for rejection (minimum 2 words)"></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showRejectModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="confirmReject" class="px-4 py-2 bg-red-600 text-white rounded">Reject</button>
                    </div>
                </div>
            </div>

            <!-- Calendar Action Choice Modal -->
            <div x-show="showCalendarActions" @click.outside="showCalendarActions = false" @keydown.escape.window="showCalendarActions = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-80 shadow-xl">
                    <h3 class="text-lg font-bold mb-4 text-center">Calendar Actions</h3>
                    <p class="text-sm text-gray-600 mb-6 text-center">Action for <span class="font-bold" x-text="selectedDate"></span></p>
                    <div class="space-y-3">
                        <button @click="showCalendarActions = false; applyLeaveFromCalendar()" class="w-full py-2 bg-purple-600 text-white rounded hover:bg-purple-700 font-medium">
                            <i class="fas fa-plane-departure mr-2"></i>Apply Leave
                        </button>
                        <button @click="showCalendarActions = false; showReminderModal = true" class="w-full py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium">
                            <i class="fas fa-bell mr-2"></i>Add Reminder
                        </button>
                        <button @click="showCalendarActions = false" class="w-full py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reminder Modal -->
            <div x-show="showReminderModal" @click.outside="showReminderModal = false" @keydown.escape.window="showReminderModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-96 shadow-xl">
                    <h3 class="text-lg font-bold mb-4">Add Reminder for <span x-text="selectedDate"></span></h3>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Title</label>
                        <input type="text" x-model="reminderForm.title" class="w-full border rounded px-3 py-2" placeholder="e.g. Follow up with client">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Description</label>
                        <textarea x-model="reminderForm.description" class="w-full border rounded px-3 py-2" rows="3" placeholder="Additional details..."></textarea>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button @click="showReminderModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                        <button @click="saveReminder" class="px-4 py-2 bg-blue-600 text-white rounded">Save Reminder</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function dashboard() {
                return {
                    isPunchedIn: false,
                    showAddNote: false,
                    showRejectModal: false,
                    newNote: '',
                    rejectReason: '',
                    currentEventId: null,
                    months: [],
                    activityFeed: '',
                    pendingApprovals: [],
                    currentMonthOffset: 0,
                    selectedDate: null,
                    showCalendarActions: false,
                    showReminderModal: false,
                    reminderForm: {
                        title: '',
                        description: ''
                    },

                    init() {
                        this.loadCalendar();
                        this.loadActivity();
                        this.checkPunchStatus();
                        this.loadPendingApprovals();
                    },

                    calendarCellStyle(status) {
                        const map = {
                            'ontime':  'background:#bbf7d0;color:#166534',   // green-200 / green-800
                            'late':    'background:#fef08a;color:#854d0e',   // yellow-200 / yellow-800
                            'early':   'background:#fecaca;color:#991b1b',   // red-200 / red-800
                            'leave':   'background:#e9d5ff;color:#6b21a8',   // purple-200 / purple-800
                            'holiday': 'background:#bfdbfe;color:#1e40af',   // blue-200 / blue-800
                            'weekoff': 'background:#e5e7eb;color:#374151',   // gray-200 / gray-700
                            'event':   'background:#fed7aa;color:#9a3412',   // orange-200 / orange-800
                            'expiry':  'background:#fde68a;color:#92400e',   // amber-200 / amber-800
                            'absent':  'background:#fca5a5;color:#7f1d1d',   // red-300 / red-900
                        };
                        return map[status] || 'background:#f9fafb;color:#374151';
                    },

                                        punchInOut() {
                        const doPunch = (lat, lng) => {
                            const deviceId = this.getDeviceId();
                            fetch('admin.php?action=attendance&status=1')
                                .then(res => res.json())
                                .then(data => {
                                    const action = (data.punched_in && this.isPunchedIn) ? 'out' : 'in';
                                    return fetch('admin.php?action=attendance', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify({
                                            action: action,
                                            location: { lat: lat, lng: lng },
                                            device_id: deviceId
                                        })
                                    });
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if (data.error) {
                                        if (data.geofence_failed) {
                                            alert(`📍 Outside office area!\nYou are ${data.distance}m away from the office. Allowed radius: ${data.radius}m.`);
                                        } else if (data.gps_required) {
                                            alert('📍 GPS location is required for attendance. Please enable location access in your browser settings and try again.');
                                        } else {
                                            alert(data.error);
                                        }
                                        return;
                                    }
                                    this.isPunchedIn = !this.isPunchedIn;
                                    const msg = this.isPunchedIn ? '✅ Punched In Successfully' : '✅ Punched Out Successfully';
                                    window.appNotify(msg);
                                    this.loadCalendar();
                                });
                        };

                        if (navigator.geolocation) {
                            navigator.geolocation.getCurrentPosition(
                                pos => doPunch(pos.coords.latitude, pos.coords.longitude),
                                () => doPunch(null, null) // If GPS denied, let server decide based on geofence config
                            );
                        } else {
                            doPunch(null, null);
                        }
                    },

                    getDeviceId() {
                        let deviceId = localStorage.getItem('device_id');
                        if (!deviceId) {
                            deviceId = 'device_' + Math.random().toString(36).substr(2, 9);
                            localStorage.setItem('device_id', deviceId);
                        }
                        return deviceId;
                    },

                    loadCalendar() {
                        // Load 3 months: previous, current, next (relative to currentMonthOffset)
                        const base = new Date();
                        const promises = [-1, 0, 1].map(delta => {
                            const d = new Date(base);
                            d.setDate(1);
                            d.setMonth(base.getMonth() + this.currentMonthOffset + delta);
                            const y = d.getFullYear();
                            const m = String(d.getMonth() + 1).padStart(2, '0');
                            return fetch('admin.php?action=calendar&month=${y}-${m}')
                                .then(r => r.json())
                                .then(data => ({
                                    name: data.month_name || `${y}-${m}`,
                                    days: data.days || []
                                }))
                                .catch(() => ({ name: `${y}-${m}`, days: [] }));
                        });
                        Promise.all(promises).then(results => {
                            this.months = results;
                            // Also pick up pending approvals from current month
                            fetch(`api/calendar.php?month=${(() => {
                                const d = new Date(); d.setDate(1);
                                d.setMonth(base.getMonth() + this.currentMonthOffset);
                                return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                            })()}`).then(r=>r.json()).then(data => {
                                this.pendingApprovals = data.pending_approvals || [];
                            }).catch(()=>{});
                        });
                    },

                    changeMonth(directionOrReset) {
                        if (directionOrReset === 0) {
                            this.currentMonthOffset = 0;
                        } else {
                            this.currentMonthOffset += directionOrReset;
                        }
                        this.loadCalendar();
                    },

                    loadActivity() {
                        fetch('admin.php?action=activity')
                            .then(res => res.text())
                            .then(data => {
                                this.activityFeed = data;
                            });
                    },

                    checkPunchStatus() {
                        fetch('admin.php?action=attendance&status=1')
                            .then(res => res.json())
                            .then(data => {
                                this.isPunchedIn = data.punched_in;
                            });
                    },

                    loadPendingApprovals() {
                        fetch('admin.php?action=approvals&pending=1')
                            .then(res => res.json())
                            .then(data => {
                                this.pendingApprovals = data;
                            });
                    },

                    selectDate(day) {
                        if (day.status !== 'leave' && day.status !== 'holiday' && day.status !== 'weekoff') {
                            this.selectedDate = day.full_date;
                            this.showCalendarActions = true;
                        }
                    },

                    applyLeaveFromCalendar() {
                        if (confirm(`Do you want to apply for leave on ${this.selectedDate}?`)) {
                            fetch('admin.php?action=leaves', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    start_date: this.selectedDate,
                                    end_date: this.selectedDate,
                                    type: 'Casual',
                                    reason: 'Leave applied from calendar'
                                })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    window.appNotify('Leave applied successfully. Awaiting approval.');
                                    this.loadCalendar();
                                } else {
                                    alert(data.error || 'Failed to apply leave');
                                }
                            });
                        }
                    },

                    saveReminder() {
                        if (!this.reminderForm.title.trim()) return;
                        fetch('admin.php?action=calendar', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: 'create',
                                title: this.reminderForm.title,
                                description: this.reminderForm.description,
                                event_type: 'general',
                                start_date: this.selectedDate,
                                target_type: 'specific',
                                target_ids: [<?php echo intval($_SESSION['admin_id']); ?>]
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showReminderModal = false;
                                this.reminderForm.title = '';
                                this.reminderForm.description = '';
                                window.appNotify('Reminder added to calendar');
                                this.loadCalendar();
                            } else {
                                alert(data.error || 'Failed to save reminder');
                            }
                        });
                    },

                    saveNote() {
                        if (!this.newNote.trim()) return;
                        fetch('admin.php?action=notes', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ note: this.newNote })
                        }).then(r => r.json()).then(() => {
                            this.showAddNote = false;
                            this.newNote = '';
                            // Reload notes in the app-level component
                            const appEl = document.getElementById('app');
                            if (appEl && appEl._x_dataStack) {
                                Alpine.evaluate(appEl, 'loadNotes()');
                            }
                        }).catch(() => {});
                    },


                    approveEvent(eventId) {
                        if (confirm('Are you sure you want to approve this request?')) {
                            fetch('admin.php?action=approvals', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ action: 'approve', id: eventId })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadPendingApprovals();
                                    window.appNotify('Request approved');
                                }
                            });
                        }
                    },

                    showRejectReason(eventId) {
                        this.currentEventId = eventId;
                        this.rejectReason = '';
                        this.showRejectModal = true;
                    },

                    confirmReject() {
                        const words = this.rejectReason.trim().split(/\s+/);
                        if (words.length < 2) {
                            alert('Please provide at least 2 words for rejection reason');
                            return;
                        }

                        // Check if this is a pending_change rejection or a leave rejection
                        const isChange = String(this.currentEventId).startsWith('change_');
                        const numericId = isChange ? parseInt(String(this.currentEventId).replace('change_', '')) : this.currentEventId;
                        const action = isChange ? 'reject_change' : 'reject';

                        fetch('admin.php?action=approvals', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ 
                                action: action, 
                                id: numericId,
                                reason: this.rejectReason
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showRejectModal = false;
                                this.rejectReason = '';
                                this.currentEventId = null;
                                this.loadPendingApprovals();
                                window.appNotify('Request rejected');
                            } else {
                                alert(data.error || 'Failed to reject request');
                            }
                        });
                    },

                    approveChange(changeId) {
                        if (!confirm('Approve this edit request? The changes will be applied immediately.')) return;
                        fetch('admin.php?action=approvals', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'approve_change', id: changeId })
                        }).then(r => r.json()).then(data => {
                            if (data.success) {
                                this.loadPendingApprovals();
                                window.appNotify('Edit request approved and applied');
                            } else {
                                alert(data.error || 'Failed to approve change');
                            }
                        });
                    },

                    rejectChange(changeId) {
                        this.currentEventId = 'change_' + changeId;
                        this.showRejectModal = true;
                    },

                }
            }
        </script>
        <?php
    }

    private function renderOps() {
        ?>
        <div x-data="ops()" x-init="init()" style="max-width:100%;overflow-x:hidden">
            <h1 class="text-3xl font-bold mb-6">Operations Hub</h1>

            <!-- Tabs -->
            <div class="border-b mb-6">
                <nav class="flex space-x-4">
                    <button @click="activeTab = 'tasks'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'tasks'}"
                            class="px-4 py-2 font-medium">
                        Tasks
                    </button>
                    <?php if ($this->role === 'admin' || $this->role === 'manager'): ?>
                    <button @click="activeTab = 'approvals'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'approvals'}"
                            class="px-4 py-2 font-medium">
                        Approvals
                    </button>
                    <button @click="activeTab = 'users'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'users'}"
                            class="px-4 py-2 font-medium">
                        Users
                    </button>
                    <?php endif; ?>
                    <button @click="activeTab = 'directory'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'directory'}"
                            class="px-4 py-2 font-medium">
                        Directory
                    </button>
                    <button @click="activeTab = 'communicator'"
                            :class="{'border-b-2 border-blue-600 text-blue-600': activeTab === 'communicator'}"
                            class="px-4 py-2 font-medium">
                        Communicator
                    </button>
                </nav>
            </div>

            <!-- Tasks View (List Format) -->
            <div x-show="activeTab === 'tasks'">
                <div class="mb-4 flex justify-between items-center">
                    <button @click="showTaskModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Create Task
                    </button>
                    <div class="flex space-x-2">
                        <select x-model="taskFilter" class="border rounded px-3 py-2 text-sm">
                            <option value="all">All Active Tasks</option>
                            <option value="pending">To Do</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Done</option>
                            <option value="review">Review</option>
                            <option value="archived">Archived Only</option>
                        </select>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Task</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Assigned To</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Due Date</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Priority</th>
                                <th class="px-4 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template x-for="task in filteredTasksList" :key="task.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 cursor-pointer" @click="viewTask(task)">
                                        <div class="text-sm font-medium text-gray-900" x-text="task.title"></div>
                                        <div class="text-xs text-gray-500 truncate max-w-xs" x-text="task.description"></div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500" x-text="task.assigned_to_name"></td>
                                    <td class="px-4 py-3 text-sm text-gray-500" x-text="task.due_date"></td>
                                    <td class="px-4 py-3">
                                        <span :class="{
                                            'bg-gray-100 text-gray-800': task.status === 'pending',
                                            'bg-blue-100 text-blue-800': task.status === 'in_progress',
                                            'bg-green-100 text-green-800': task.status === 'completed',
                                            'bg-purple-100 text-purple-800': task.status === 'review',
                                            'bg-red-100 text-red-800': task.status === 'archived'
                                        }" class="px-2 py-1 rounded-full text-xs font-semibold"
                                        x-text="{pending:'To Do',in_progress:'In Progress',completed:'Done',review:'Review',archived:'Archived'}[task.status] || task.status"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span :class="{
                                            'text-red-600': task.priority === 'high',
                                            'text-yellow-600': task.priority === 'medium',
                                            'text-blue-600': task.priority === 'low'
                                        }" class="text-xs font-bold uppercase" x-text="task.priority"></span>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium">
                                        <div class="flex items-center space-x-2">
                                            <button @click.stop="viewTask(task)" title="View / Comment" class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button @click.stop="promptDeleteTask(task)" title="Delete Task" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredTasksList.length === 0">
                                <td colspan="6" class="text-center py-8 text-gray-400">No tasks found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approvals View -->
            <div x-show="activeTab === 'approvals'">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-4 border-b flex justify-between items-center">
                        <h3 class="font-bold">Pending Approvals</h3>
                        <button @click="loadApprovals()" class="text-sm text-blue-600 hover:underline">
                            <i class="fas fa-sync mr-1"></i>Refresh
                        </button>
                    </div>
                    <div class="p-4">
                        <!-- approvalsList is raw HTML from api/approvals.php -->
                        <!-- Buttons inside use onclick="window.opsApprove(id)" / window.opsReject(id, isChange) -->
                        <div x-show="!approvalsList" class="text-center text-gray-400 py-8">
                            <i class="fas fa-spinner fa-spin text-2xl mb-2 block"></i>
                            <p>Loading approvals...</p>
                        </div>
                        <table class="w-full" x-show="approvalsList">
                            <thead>
                                <tr class="text-left text-gray-600 border-b bg-gray-50">
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Type</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Requester</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Details</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Date</th>
                                    <th class="pb-3 px-3 font-semibold text-xs uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody x-html="approvalsList"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Approval Action Modal - opened via window.opsApprove / window.opsReject -->
                <div x-show="showApprovalModal" @click.outside="showApprovalModal = false" @keydown.escape.window="showApprovalModal = false"
                     class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
                     x-cloak>
                    <div class="bg-white rounded-lg p-6 w-[420px] shadow-xl">
                        <h3 class="text-lg font-bold mb-1"
                            x-text="approvalAction === 'approve' ? '✅ Confirm Approval' : '❌ Confirm Rejection'"></h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Request ID: <strong x-text="currentApprovalId"></strong>
                        </p>
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">
                                <span x-text="approvalAction === 'approve' ? 'Note (optional)' : 'Reason for Rejection *'"></span>
                            </label>
                            <textarea x-model="approvalReason"
                                      class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
                                      rows="3"
                                      :placeholder="approvalAction === 'approve'
                                          ? 'Add an approval note (optional)...'
                                          : 'Please provide a reason (minimum 2 words)...'"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showApprovalModal = false; approvalReason = ''"
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                                Cancel
                            </button>
                            <button @click="submitApprovalAction()"
                                    :class="approvalAction === 'approve'
                                        ? 'bg-green-600 hover:bg-green-700'
                                        : 'bg-red-600 hover:bg-red-700'"
                                    class="px-4 py-2 text-white rounded font-medium">
                                <span x-text="approvalAction === 'approve' ? 'Approve' : 'Reject'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Directory View -->
            <div x-show="activeTab === 'directory'">
                <div class="mb-4 flex space-x-2">
                    <button @click="showContactModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Contact
                    </button>
                    <button @click="showAddWorker = true" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        <i class="fas fa-user-plus mr-2"></i>Add Worker
                    </button>
                </div>
                <!-- Controls for listing Workers visual instead of the list being in a separate tab -->
                <div class="grid grid-cols-2 gap-6">
                    <!-- Contacts -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Contacts Directory</h3>
                        </div>
                        <div class="p-4">
                            <input type="text"
                                   x-model="contactSearch"
                                   @input="searchContacts"
                                   placeholder="Search contacts..."
                                   class="w-full border rounded px-3 py-2 mb-4">
                            <div class="space-y-2" x-html="contactsList"></div>
                        </div>
                    </div>

                    <!-- Workers Directory Output -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Workers Directory</h3>
                        </div>
                        <div class="p-4">
                            <input type="text"
                                   x-model="workerSearch"
                                   @input="searchWorkers"
                                   placeholder="Search workers..."
                                   class="w-full border rounded px-3 py-2 mb-4">
                            <div class="space-y-2" x-html="workersList"></div>

                            <hr class="my-4">
                            <h4 class="font-bold mb-2 text-sm text-gray-500">Worker Cards</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 h-96 overflow-y-auto">
                                <template x-for="worker in workers" :key="worker.id">
                                    <div class="bg-gray-50 rounded-lg shadow-sm border p-3">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <img :src="worker.photo_url || 'https://via.placeholder.com/50'"
                                                 class="w-10 h-10 rounded-full object-cover">
                                            <div>
                                                <h3 class="font-bold text-sm" x-text="worker.name"></h3>
                                                <p class="text-xs text-gray-600" x-text="worker.skills"></p>
                                            </div>
                                        </div>
                                        <div class="text-xs space-y-1 text-gray-600 mb-2">
                                            <p><i class="fas fa-phone w-4"></i> <span x-text="worker.phone"></span></p>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span :class="{
                                                'bg-green-100 text-green-800': worker.status === 'active',
                                                'bg-red-100 text-red-800': worker.status === 'inactive'
                                            }" class="px-2 py-1 rounded-full text-xs" x-text="worker.status"></span>

                                            <div class="flex space-x-2">
                                                <button @click="viewWorker(worker)" class="text-blue-600 hover:text-blue-800"><i class="fas fa-eye"></i></button>
                                                <button @click="generateIDCard(worker)" class="text-green-600 hover:text-green-800"><i class="fas fa-id-card"></i></button>
                                                <button @click="editWorker(worker)" class="text-yellow-600 hover:text-yellow-800"><i class="fas fa-edit"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Communicator View -->
<div x-show="activeTab === 'communicator'">
    <div class="bg-white rounded-lg shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-bold">Team Communications</h3>
            <div class="flex space-x-1 text-sm">
                <button @click="activeChatTab = 'guest'; fetchMessages(); loadAllGuestSessions();" 
                        :class="activeChatTab === 'guest' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Guest Chat
                </button>
                <button @click="activeChatTab = 'staff'; fetchMessages(); loadStaffList();" 
                        :class="activeChatTab === 'staff' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Staff
                </button>
                <button @click="activeChatTab = 'team'; fetchMessages();" 
                        :class="activeChatTab === 'team' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Team
                </button>
                <button @click="activeChatTab = 'broadcast'; fetchMessages();" 
                        :class="activeChatTab === 'broadcast' ? 'bg-blue-600 text-white' : 'bg-gray-200'"
                        class="px-3 py-1 rounded">
                    Broadcast
                </button>
            </div>
        </div>

        <div class="p-4">
            <div class="grid grid-cols-4 gap-4">
                <!-- Session List (for guest chat) -->
                <div x-show="activeChatTab === 'guest'" class="col-span-1 border-r pr-4">
                    <h4 class="font-bold mb-3 text-sm">All Guest Sessions</h4>
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <template x-for="session in allGuestSessions" :key="session.session_id">
                            <div @click="selectedGuestSession = session.session_id; fetchGuestMessages(session.session_id);"
                                 :class="{'bg-blue-100 border-blue-500': selectedGuestSession === session.session_id, 'border-gray-200': selectedGuestSession !== session.session_id}"
                                 class="border rounded-lg p-3 cursor-pointer hover:bg-gray-50 transition">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-medium text-sm" x-text="session.guest_name || 'Anonymous Guest'"></span>
                                        <span x-show="session.status === 'active'" class="ml-2 text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full">Active</span>
                                        <span x-show="session.status === 'terminated'" class="ml-2 text-xs bg-gray-100 text-gray-800 px-2 py-0.5 rounded-full">Terminated</span>
                                    </div>
                                    <span class="text-xs text-gray-500" x-text="session.last_activity_formatted"></span>
                                </div>
                                <div class="text-xs text-gray-600 mt-1" x-text="session.contact_reason || 'General Inquiry'"></div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-xs text-gray-500 truncate max-w-[150px]" x-text="session.last_message || 'No messages'"></span>
                                    <span x-show="session.unread_count > 0" class="bg-red-500 text-white text-xs rounded-full px-2 py-0.5" x-text="session.unread_count"></span>
                                </div>
                                <div class="mt-2 flex justify-end space-x-2">
                                    <button @click.stop="viewGuestDetails(session)" class="text-blue-600 text-xs hover:underline">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button x-show="session.status === 'active'" @click.stop="terminateGuestSession(session.session_id)" class="text-red-600 text-xs hover:underline">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <div x-show="allGuestSessions.length === 0" class="text-center text-gray-400 py-8">
                            No guest sessions found
                        </div>
                    </div>
                </div>

                <!-- Messages Area -->
                <div :class="activeChatTab === 'guest' ? 'col-span-3' : 'col-span-4'">
                    <div class="border rounded-lg h-[500px] overflow-y-auto p-4 bg-gray-50" x-ref="chatMessages">
                        <template x-for="msg in teamMessages" :key="msg.id">
                            <div :class="{'flex justify-end': msg.sender_id === currentUserId && msg.sender_type === 'admin'}"
                                 class="mb-3">
                                <div :class="{
                                        'bg-blue-600 text-white': msg.sender_id === currentUserId && msg.sender_type === 'admin',
                                        'bg-gray-300 text-gray-800': msg.sender_type === 'system',
                                        'bg-gray-200 text-gray-800': !(msg.sender_id === currentUserId && msg.sender_type === 'admin') && msg.sender_type !== 'system'
                                     }"
                                     class="inline-block p-3 rounded-lg max-w-md shadow-sm">
                                    <p class="text-xs font-bold mb-1">
                                        <span x-text="msg.sender_name || (msg.sender_type === 'admin' ? 'You' : msg.sender_type === 'guest' ? 'Guest' : 'Staff')"></span>
                                        <span x-show="msg.sender_type === 'system'" class="ml-1 text-xs">(System)</span>
                                    </p>
                                    <p class="text-sm break-words" x-text="msg.message"></p>
                                    <div class="flex justify-end items-center mt-1">
                                        <p class="text-xs opacity-75" x-text="msg.time"></p>
                                        <i x-show="msg.is_read && msg.sender_type === 'admin'" class="fas fa-check-double text-xs ml-1 text-green-300"></i>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="teamMessages.length === 0" class="text-center text-gray-400 py-8">
                            No messages yet
                        </div>
                    </div>

                    <!-- Input Area -->
                    <div class="mt-4">
                        <div class="flex space-x-2">
                            <template x-if="activeChatTab === 'staff'">
                                <select x-model="chatReceiverId" class="border rounded-lg px-3 py-2 w-48 text-sm">
                                    <option value="">Select Staff...</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.id" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="activeChatTab === 'guest'">
                                <select x-model="selectedGuestSession" class="border rounded-lg px-3 py-2 w-48 text-sm">
                                    <option value="">Select Session...</option>
                                    <template x-for="session in allGuestSessions" :key="session.session_id">
                                        <option :value="session.session_id" x-text="(session.guest_name || 'Guest') + ' - ' + (session.contact_reason || 'General')"></option>
                                    </template>
                                </select>
                            </template>
                            <input type="text"
                                   x-model="teamMessage"
                                   @keyup.enter="sendTeamMessage"
                                   :placeholder="'Type your ' + activeChatTab + ' message...'"
                                   class="flex-1 border rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-blue-600"
                                   :disabled="(activeChatTab === 'guest' && !selectedGuestSession) || (activeChatTab === 'staff' && !chatReceiverId)">
                            <button @click="sendTeamMessage" 
                                    :disabled="(activeChatTab === 'guest' && !selectedGuestSession) || (activeChatTab === 'staff' && !chatReceiverId)"
                                    class="bg-blue-600 text-white px-5 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- Users Management View -->
            <div x-show="activeTab === 'users'">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold">User Management</h2>
                        <button @click="openAddUserModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Add User
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left border-b bg-gray-50 uppercase text-xs text-gray-500 font-medium">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Department</th>
                                    <th class="px-4 py-3">Reporting Head</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <template x-for="u in users" :key="u.id">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900" x-text="u.full_name"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="u.email"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500 text-capitalize" x-text="u.role"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="u.department || 'N/A'"></td>
                                        <td class="px-4 py-3 text-sm text-gray-500" x-text="getReportingHeadName(u.reporting_head)"></td>
                                        <td class="px-4 py-3 text-sm">
                                            <span :class="u.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-1 rounded-full text-xs font-semibold" x-text="u.is_active ? 'Active' : 'Inactive'"></span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex space-x-2">
                                                <button @click="editUser(u)" class="text-blue-600 hover:text-blue-900"><i class="fas fa-edit"></i></button>
                                                <button @click="toggleUser(u)" :class="u.is_active ? 'text-yellow-600' : 'text-green-600'" class="hover:opacity-75"><i :class="u.is_active ? 'fas fa-ban' : 'fas fa-check-circle'"></i></button>
                                                <button @click="deleteUser(u.id)" class="text-red-600 hover:text-red-900"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Task Detail Popup -->
            <div x-show="showTaskDetail" @click.outside="showTaskDetail = false" @keydown.escape.window="showTaskDetail = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-2xl font-bold" x-text="currentTask?.title"></h3>
                            <p class="text-sm text-gray-500">Assigned by <span x-text="currentTask?.assigned_by_name"></span> on <span x-text="currentTask?.created_at"></span></p>
                        </div>
                        <button @click="showTaskDetail = false" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-3 gap-6 mb-4">
                        <div class="col-span-2">
                            <h4 class="font-bold mb-2">Description</h4>
                            <p class="text-gray-700 whitespace-pre-wrap mb-4 text-sm" x-text="currentTask?.description || 'No description.'"></p>

                            <h4 class="font-bold mb-2">Comments</h4>
                            <div class="space-y-3 mb-3 max-h-48 overflow-y-auto bg-gray-50 p-3 rounded border">
                                <template x-for="comment in taskComments" :key="comment.id">
                                    <div class="bg-white p-2 rounded shadow-sm border-l-4 border-blue-500">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="font-bold text-xs" x-text="comment.user_name"></span>
                                            <span class="text-[10px] text-gray-400" x-text="comment.created_at"></span>
                                        </div>
                                        <p class="text-sm text-gray-700" x-text="comment.comment"></p>
                                    </div>
                                </template>
                                <template x-if="taskComments.length === 0">
                                    <p class="text-xs text-gray-400 text-center py-4">No comments yet. Be the first to comment.</p>
                                </template>
                            </div>
                            <!-- Comment input — Enter to submit, button as backup -->
                            <div class="flex space-x-2">
                                <input type="text"
                                       x-model="newTaskComment"
                                       @keyup.enter="addTaskComment()"
                                       placeholder="Add a comment and press Enter or click Send..."
                                       class="flex-1 border rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <button type="button"
                                        @click="addTaskComment()"
                                        class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700 whitespace-nowrap">
                                    <i class="fas fa-paper-plane mr-1"></i>Send
                                </button>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Status</label>
                                <select x-model="pendingTaskStatus"
                                        class="w-full border rounded px-2 py-1 text-sm bg-gray-50">
                                    <option value="pending">To Do</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Done</option>
                                    <option value="review">Review</option>
                                    <option value="archived">Archive</option>
                                </select>
                                <button type="button"
                                        @click="promptStatusChange()"
                                        x-show="pendingTaskStatus !== currentTaskStatus"
                                        class="mt-1 w-full text-xs bg-blue-600 text-white rounded px-2 py-1 hover:bg-blue-700">
                                    Apply Status Change
                                </button>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Priority</label>
                                <div class="mt-1">
                                    <span :class="{
                                        'bg-red-100 text-red-800': currentTask?.priority === 'high',
                                        'bg-yellow-100 text-yellow-800': currentTask?.priority === 'medium',
                                        'bg-blue-100 text-blue-800': currentTask?.priority === 'low'
                                    }" class="px-2 py-1 rounded text-xs font-bold uppercase" x-text="currentTask?.priority"></span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Assigned To</label>
                                <p class="mt-1 text-sm font-medium" x-text="currentTask?.assigned_to_name"></p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase">Due Date</label>
                                <p class="mt-1 text-sm flex items-center"
                                   :class="isOverdue(currentTask?.due_date) ? 'text-red-600 font-bold' : 'text-gray-700'">
                                    <i class="far fa-calendar-alt mr-1"></i>
                                    <span x-text="currentTask?.due_date || 'No due date'"></span>
                                </p>
                            </div>
                            <!-- Action buttons -->
                            <div class="pt-2 border-t space-y-2">
                                <button type="button"
                                        @click="promptDeleteTask(currentTask)"
                                        class="w-full text-xs bg-red-50 text-red-600 border border-red-200 rounded px-3 py-2 hover:bg-red-100">
                                    <i class="fas fa-trash mr-1"></i>Delete Task
                                </button>
                                <button type="button"
                                        @click="showTaskDetail = false"
                                        class="w-full text-xs bg-gray-100 text-gray-600 rounded px-3 py-2 hover:bg-gray-200">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Action Reason Modal (status change / delete / cancel) -->
            <div x-show="showTaskReasonModal" @click.outside="showTaskReasonModal = false" @keydown.escape.window="showTaskReasonModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]" x-cloak>
                <div class="bg-white rounded-lg p-6 w-96 shadow-xl">
                    <h3 class="text-lg font-bold mb-1" x-text="taskReasonTitle"></h3>
                    <p class="text-sm text-gray-500 mb-4" x-text="taskReasonSubtitle"></p>
                    <textarea x-model="taskReasonText"
                              class="w-full border rounded px-3 py-2 text-sm mb-4 focus:outline-none focus:border-blue-500"
                              rows="3"
                              placeholder="Please enter a reason (minimum 3 words)..."></textarea>
                    <div class="flex justify-end space-x-2">
                        <button @click="showTaskReasonModal = false; taskReasonText = ''"
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Cancel</button>
                        <button @click="confirmTaskReasonAction()"
                                :class="taskReasonAction === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
                                class="px-4 py-2 text-white rounded font-medium"
                                x-text="taskReasonAction === 'delete' ? 'Delete' : 'Confirm'"></button>
                    </div>
                </div>
            </div>

            <!-- Task Creation Modal -->
            <div x-show="showTaskModal" @click.outside="showTaskModal = false" @keydown.escape.window="showTaskModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Create New Task</h3>
                    <form @submit.prevent="createTask">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Title</label>
                            <input type="text" x-model="taskForm.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="taskForm.description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Assign To</label>
                            <select x-model="taskForm.assigned_to" class="w-full border rounded px-3 py-2">
                                <option value="">Select User</option>
                                <template x-for="user in users" :key="user.id">
                                    <option :value="user.id" x-text="user.full_name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Priority</label>
                            <select x-model="taskForm.priority" class="w-full border rounded px-3 py-2">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Due Date</label>
                            <input type="date" x-model="taskForm.due_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showTaskModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Create</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Contact Creation Modal -->
            <div x-show="showContactModal" @click.outside="showContactModal = false" @keydown.escape.window="showContactModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Contact</h3>
                    <form @submit.prevent="addContact">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Name</label>
                            <input type="text" x-model="contactForm.name" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Profession</label>
                            <input type="text" x-model="contactForm.profession" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Contact Number</label>
                            <input type="text" x-model="contactForm.contact_number" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Locality</label>
                            <input type="text" x-model="contactForm.locality" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Notes</label>
                            <textarea x-model="contactForm.notes" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showContactModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Add</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Add/Edit User Modal -->
            <div x-show="showAddUserModal" @click.outside="showAddUserModal = false" @keydown.escape.window="showAddUserModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-11/12 md:w-3/4 lg:w-1/2 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingUser ? 'Edit User' : 'Add New User'"></h3>
                    <form @submit.prevent="saveUser">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Full Name</label>
                                <input type="text" x-model="userForm.full_name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="userForm.email" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Phone</label>
                                <input type="tel" x-model="userForm.phone" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Role</label>
                                <select x-model="userForm.role" class="w-full border rounded px-3 py-2">
                                    <option value="staff">Staff</option>
                                    <option value="manager">Manager</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Department</label>
                                <input type="text" x-model="userForm.department" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Reporting Head</label>
                                <select x-model="userForm.reporting_head" class="w-full border rounded px-3 py-2">
                                    <option value="0">None</option>
                                    <template x-for="u in users.filter(u => u.id !== userForm.id)" :key="u.id">
                                        <option :value="u.id" x-text="u.full_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="userForm.is_active" class="w-full border rounded px-3 py-2">
                                    <option :value="1">Active</option>
                                    <option :value="0">Inactive</option>
                                </select>
                            </div>
                            <div class="mb-2" x-show="!editingUser">
                                <label class="block text-sm font-medium mb-1">Password</label>
                                <input type="password" x-model="userForm.password" class="w-full border rounded px-3 py-2" :required="!editingUser">
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddUserModal = false; editingUser = null;" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Worker Creation/Edit Modal -->
            <div x-show="showAddWorker" @click.outside="showAddWorker = false" @keydown.escape.window="showAddWorker = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-11/12 md:w-3/4 lg:w-1/2 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingWorker ? 'Edit Worker' : 'Add New Worker'"></h3>
                    <form @submit.prevent="saveWorker">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Basic Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Full Name</label>
                                <input type="text" x-model="workerForm.name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Father/Husband Name</label>
                                <input type="text" x-model="workerForm.father_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Date of Birth</label>
                                <input type="date" x-model="workerForm.dob" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Gender</label>
                                <select x-model="workerForm.gender" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <!-- Contact Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Phone Number</label>
                                <input type="tel" x-model="workerForm.phone" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="workerForm.email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2 md:col-span-2">
                                <label class="block text-sm font-medium mb-1">Address</label>
                                <textarea x-model="workerForm.address" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                            </div>

                            <!-- Professional Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Skills</label>
                                <input type="text" x-model="workerForm.skills" class="w-full border rounded px-3 py-2" placeholder="e.g., Electrician, Plumber">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Experience</label>
                                <input type="text" x-model="workerForm.experience" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Qualification</label>
                                <input type="text" x-model="workerForm.qualification" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Reporting Head</label>
                                <select x-model="workerForm.reporting_head" class="w-full border rounded px-3 py-2">
                                    <option value="0">Self / Top Level</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.id" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </div>

                            <!-- Other Details -->
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Blood Group</label>
                                <select x-model="workerForm.blood_group" class="w-full border rounded px-3 py-2">
                                    <option value="">Select</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="workerForm.status" class="w-full border rounded px-3 py-2">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddWorker = false; editingWorker = null;" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function ops() {
                return {
                    activeTab: 'tasks',
                    tasks: {
                        todo: [],
                        progress: [],
                        done: [],
                        review: [],
                        archive: []
                    },
                    taskFilter: 'all',
                    allTasksList: [],
                    filteredTasksList: [],
                    currentTask: null,
                    showTaskDetail: false,
                    taskComments: [],
                    newTaskComment: '',
                    currentTaskStatus: '',
                    pendingTaskStatus: '',
                    showTaskReasonModal: false,
                    taskReasonAction: '',  // 'status_change' | 'delete'
                    taskReasonTitle: '',
                    taskReasonSubtitle: '',
                    taskReasonText: '',
                    draggedTask: null,
                    showTaskModal: false,
                    showContactModal: false,
                    showAddWorker: false,
                    showAddUserModal: false, // New state for user modal
                    workers: [],
                    users: [], // This will now hold all users for management
                    approvalsList: '',
                    showApprovalModal: false,
                    approvalAction: 'approve',   // 'approve' | 'reject'
                    approvalReason: '',
                    currentApprovalId: null,
                    isChangeApproval: false,
                    contactsList: '',
                    workersList: '',
                    teamMessages: [],
                    onlineMembers: '',
                    currentUserId: <?php echo $_SESSION['admin_id']; ?>,
                    activeChatTab: 'team',
                    chatReceiverId: '0',
                    chatSessionId: '',
                    teamMessage: '',
                    contactSearch: '',
                    workerSearch: '',
                    editingWorker: null, // To track if we are editing a worker
                    editingUser: null, // To track if we are editing a user
                    // Add these to the ops() function's return object
allGuestSessions: [],
selectedGuestSession: '',
staffList: [],
chatLastId: 0,

loadAllGuestSessions() {
    fetch('admin.php?action=chat&type=all_guest_sessions')
        .then(res => res.json())
        .then(data => {
            this.allGuestSessions = data;
        })
        .catch(err => console.error('Error loading all sessions:', err));
},

loadStaffList() {
    fetch('admin.php?action=users&list=1&role=staff,manager,admin')
        .then(res => res.json())
        .then(data => {
            this.staffList = data;
        })
        .catch(err => console.error('Error loading staff list:', err));
},

fetchGuestMessages(sessionId) {
    if (!sessionId) return;

    fetch('admin.php?action=chat&type=guest&session_id=${sessionId}')
        .then(res => res.json())
        .then(data => {
            this.teamMessages = data;
            this.chatLastId = data.length > 0 ? data[data.length - 1].id : 0;
            this.$nextTick(() => {
                let container = this.$refs.chatMessages;
                if (container) container.scrollTop = container.scrollHeight;
            });
        })
        .catch(err => console.error('Error fetching guest messages:', err));
},

fetchMessages() {
    let url = `admin.php?action=chat&type=${this.activeChatTab}`;
    if (this.activeChatTab === 'guest' && this.selectedGuestSession) {
        url += `&session_id=${this.selectedGuestSession}`;
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {
            this.teamMessages = Array.isArray(data) ? data : [];
            this.$nextTick(() => {
                let container = this.$refs.chatMessages;
                if (container) container.scrollTop = container.scrollHeight;
            });
        })
        .catch(err => console.error('Error fetching messages:', err));
},

sendTeamMessage() {
    if (!this.teamMessage.trim()) return;

    if (this.activeChatTab === 'staff' && !this.chatReceiverId) {
        alert("Please select a staff member to message.");
        return;
    }

    if (this.activeChatTab === 'guest' && !this.selectedGuestSession) {
        alert("Please select a guest session.");
        return;
    }

    let tempId = 'temp_' + Date.now() + '_' + Math.random().toString(36);

    // Optimistic update
    let optimisticMsg = {
        id: tempId,
        message: this.teamMessage,
        sender_id: this.currentUserId,
        sender_type: 'admin',
        sender_name: 'You',
        time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        is_temp: true
    };
    this.teamMessages.push(optimisticMsg);
    let messageText = this.teamMessage;
    this.teamMessage = '';

    fetch('admin.php?action=chat', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            message: messageText,
            type: this.activeChatTab,
            receiver_id: this.chatReceiverId,
            session_id: this.selectedGuestSession,
            temp_id: tempId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Remove optimistic message and fetch real ones
            this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
            this.fetchMessages();
            if (this.activeChatTab === 'guest') {
                this.loadAllGuestSessions();
            }
        } else {
            // Remove optimistic message on error
            this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
            alert(data.error || 'Failed to send message');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        this.teamMessages = this.teamMessages.filter(m => m.id !== tempId);
        alert('Network error');
    });
},

viewGuestDetails(session) {
    // Create modal with guest details
    let detailsHtml = `
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="guestDetailsModal">
            <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold">Guest Details</h3>
                    <button onclick="document.getElementById('guestDetailsModal').remove()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="space-y-3">
                    <div><span class="font-medium">Name:</span> ${session.guest_name || 'Not provided'}</div>
                    <div><span class="font-medium">Email:</span> ${session.guest_email || 'Not provided'}</div>
                    <div><span class="font-medium">Phone:</span> ${session.guest_phone || 'Not provided'}</div>
                    <div><span class="font-medium">Reason:</span> ${session.contact_reason || 'Not provided'}</div>
                    <div><span class="font-medium">Device ID:</span> ${session.device_id || 'Not available'}</div>
                    <div><span class="font-medium">Started:</span> ${new Date(session.created_at).toLocaleString()}</div>
                    <div><span class="font-medium">Last Activity:</span> ${new Date(session.last_activity).toLocaleString()}</div>
                    <div><span class="font-medium">Status:</span> <span class="px-2 py-1 rounded-full text-xs ${session.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">${session.status}</span></div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button onclick="document.getElementById('guestDetailsModal').remove()" class="px-4 py-2 bg-gray-200 rounded">Close</button>
                </div>
            </div>
        </div>
    `;

    let tempDiv = document.createElement('div');
    tempDiv.innerHTML = detailsHtml;
    document.body.appendChild(tempDiv.firstChild);
},

terminateGuestSession(sessionId) {
    if (confirm('Terminate this guest chat? The guest will be notified.')) {
        fetch('admin.php?action=chat', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                action: 'terminate_session',
                session_id: sessionId 
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadAllGuestSessions();
                if (this.selectedGuestSession === sessionId) {
                    this.selectedGuestSession = '';
                    this.teamMessages = [];
                }
                window.appNotify('Chat terminated');
            }
        })
        .catch(err => console.error('Error:', err));
    }
}

                    taskForm: {
                        title: '',
                        description: '',
                        assigned_to: '',
                        priority: 'medium',
                        due_date: ''
                    },

                    contactForm: {
                        name: '',
                        profession: '',
                        contact_number: '',
                        locality: '',
                        notes: ''
                    },

                    workerForm: {
                        name: '',
                        father_name: '',
                        dob: '',
                        gender: '',
                        phone: '',
                        email: '',
                        address: '',
                        skills: '',
                        experience: '',
                        qualification: '',
                        status: 'active'
                    },

                    userForm: { // New form for user management
                        id: null,
                        full_name: '',
                        email: '',
                        phone: '',
                        role: 'staff',
                        department: '',
                        reporting_head: '0',
                        is_active: 1,
                        password: ''
                    },

                    init() {
                        this.loadTasks();
                        this.loadApprovals();
                        this.loadDirectory();
                        this.loadWorkers();
                        this.loadUsers(); // Load users for both task assignment and user management
                        this.fetchMessages();
                        this.loadOnlineMembers();

                        this.$watch('activeTab', (value) => {
                            if (value === 'communicator') {
                                this.fetchMessages();
                                this.loadOnlineMembers();
                            }
                            if (value === 'users') {
                                this.loadUsers();
                            }
                            if (value === 'approvals') {
                                this.loadApprovals(); // re-fetch & re-register bridges
                            }
                        });

                        // Also watch approvalsList: re-register bridges whenever content updates
                        this.$watch('approvalsList', () => {
                            this.$nextTick(() => this._registerApprovalBridges());
                        });

                        // Re-apply filter whenever taskFilter changes
                        this.$watch('taskFilter', () => this._applyTaskFilter());
                    },

                    loadTasks() {
                        fetch('admin.php?action=tasks')
                            .then(res => res.json())
                            .then(data => {
                                this.tasks = data;
                                this.allTasksList = [
                                    ...(data.todo      || []),
                                    ...(data.progress  || []),
                                    ...(data.done      || []),
                                    ...(data.review    || []),
                                    ...(data.archive   || [])
                                ];
                                this._applyTaskFilter();
                            })
                            .catch(() => {});
                    },

                    _applyTaskFilter() {
                        if (this.taskFilter === 'all') {
                            // "All Active" excludes archived
                            this.filteredTasksList = this.allTasksList.filter(t => t.status !== 'archived');
                        } else {
                            this.filteredTasksList = this.allTasksList.filter(t => t.status === this.taskFilter);
                        }
                    },

                    viewTask(task) {
                        this.currentTask       = task;
                        this.currentTaskStatus = task.status;
                        this.pendingTaskStatus = task.status;
                        this.showTaskDetail    = true;
                        this.newTaskComment    = '';
                        this.loadTaskComments(task.id);
                    },

                    loadTaskComments(taskId) {
                        fetch('admin.php?action=tasks&comments=${taskId}')
                            .then(res => res.json())
                            .then(data => {
                                this.taskComments = Array.isArray(data) ? data : [];
                            })
                            .catch(() => { this.taskComments = []; });
                    },

                    addTaskComment() {
                        const text = (this.newTaskComment || '').trim();
                        if (!text) return;
                        if (!this.currentTask) return;
                        fetch('admin.php?action=tasks', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action:   'comment',
                                task_id:  this.currentTask.id,
                                comment:  text
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.newTaskComment = '';
                                this.loadTaskComments(this.currentTask.id);
                            } else {
                                alert(data.error || 'Failed to add comment');
                            }
                        })
                        .catch(() => alert('Network error'));
                    },

                    promptStatusChange() {
                        const labels = {pending:'To Do',in_progress:'In Progress',completed:'Done',review:'Review',archived:'Archived'};
                        this.taskReasonAction   = 'status_change';
                        this.taskReasonTitle    = 'Change Task Status';
                        this.taskReasonSubtitle = `Changing from "${labels[this.currentTaskStatus]}" to "${labels[this.pendingTaskStatus]}". Please provide a reason.`;
                        this.taskReasonText     = '';
                        this.showTaskReasonModal = true;
                    },

                    promptDeleteTask(task) {
                        this.currentTask        = task;
                        this.taskReasonAction   = 'delete';
                        this.taskReasonTitle    = 'Delete Task';
                        this.taskReasonSubtitle = `You are about to permanently delete "${task.title}". This cannot be undone.`;
                        this.taskReasonText     = '';
                        this.showTaskReasonModal = true;
                    },

                    confirmTaskReasonAction() {
                        const words = this.taskReasonText.trim().split(/\s+/).filter(Boolean);
                        if (words.length < 3) {
                            alert('Please provide at least 3 words as a reason.');
                            return;
                        }
                        if (this.taskReasonAction === 'delete') {
                            fetch('admin.php?action=tasks', {
                                method: 'DELETE',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ id: this.currentTask.id, reason: this.taskReasonText })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.showTaskReasonModal = false;
                                    this.showTaskDetail = false;
                                    this.taskReasonText = '';
                                    this.loadTasks();
                                    window.appNotify('Task deleted');
                                } else {
                                    alert(data.error || 'Failed to delete task');
                                }
                            })
                            .catch(() => alert('Network error'));
                        } else if (this.taskReasonAction === 'status_change') {
                            fetch('admin.php?action=tasks', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id:     this.currentTask.id,
                                    status: this.pendingTaskStatus,
                                    reason: this.taskReasonText
                                })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.showTaskReasonModal = false;
                                    this.taskReasonText = '';
                                    this.currentTaskStatus = this.pendingTaskStatus;
                                    this.currentTask.status = this.pendingTaskStatus;
                                    this.loadTasks();
                                    window.appNotify('Task status updated');
                                } else {
                                    alert(data.error || 'Failed to update status');
                                }
                            })
                            .catch(() => alert('Network error'));
                        }
                    },

                    isOverdue(dateStr) {
                        if (!dateStr) return false;
                        const d = new Date(dateStr);
                        const now = new Date();
                        return d < now && this.currentTaskStatus !== 'completed' && this.currentTaskStatus !== 'archived';
                    },

                    loadApprovals() {
                        fetch('admin.php?action=approvals')
                            .then(res => res.text())
                            .then(data => {
                                this.approvalsList = data;
                                // Register window bridges AFTER html is injected
                                this.$nextTick(() => this._registerApprovalBridges());
                            })
                            .catch(() => { this.approvalsList = '<tr><td colspan="5" class="text-center py-6 text-gray-400">Failed to load approvals.</td></tr>'; });
                    },

                    // Called after x-html injects the approval rows, bridges window.opsApprove/opsReject
                    // so onclick= attributes in the injected HTML can reach Alpine component scope
                    _registerApprovalBridges() {
                        const self = this;
                        window.opsApprove = function(id, isChange) {
                            self.currentApprovalId  = id;
                            self.isChangeApproval   = !!isChange;
                            self.approvalAction     = 'approve';
                            self.approvalReason     = '';
                            self.showApprovalModal  = true;
                        };
                        window.opsReject = function(id, isChange) {
                            self.currentApprovalId  = id;
                            self.isChangeApproval   = !!isChange;
                            self.approvalAction     = 'reject';
                            self.approvalReason     = '';
                            self.showApprovalModal  = true;
                        };
                    },

                    submitApprovalAction() {
                        if (this.approvalAction === 'reject') {
                            const words = this.approvalReason.trim().split(/\s+/).filter(Boolean);
                            if (words.length < 2) {
                                alert('Please provide at least 2 words for the rejection reason.');
                                return;
                            }
                        }
                        const apiAction = this.approvalAction === 'approve'
                            ? (this.isChangeApproval ? 'approve_change' : 'approve')
                            : (this.isChangeApproval ? 'reject_change' : 'reject');

                        const notifyMsg = this.approvalAction === 'approve'
                            ? 'Request approved successfully'
                            : 'Request rejected';

                        fetch('admin.php?action=approvals', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: apiAction,
                                id: this.currentApprovalId,
                                reason: this.approvalReason
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showApprovalModal = false;
                                this.approvalReason   = '';
                                this.currentApprovalId = null;
                                this.loadApprovals();
                                window.appNotify(notifyMsg);
                            } else {
                                alert(data.error || 'Action failed. Please try again.');
                            }
                        })
                        .catch(() => alert('Network error. Please try again.'));
                    },

                    loadDirectory() {
                        this.searchContacts();
                        this.searchWorkers();
                    },

                    searchContacts() {
                        fetch('admin.php?action=directory&search=${this.contactSearch}&type=contacts')
                            .then(res => res.text())
                            .then(data => {
                                this.contactsList = data;
                            });
                    },

                    searchWorkers() {
                        fetch('admin.php?action=directory&search=${this.workerSearch}&type=workers')
                            .then(res => res.text())
                            .then(data => {
                                this.workersList = data;
                            });
                    },

                    loadWorkers() {
                        fetch('admin.php?action=workers')
                            .then(res => res.json())
                            .then(data => {
                                this.workers = data;
                                this.$nextTick(() => {
                                    this.workers.forEach(worker => {
                                        this.generateQR(worker);
                                    });
                                });
                            });
                    },

                    loadUsers() {
                        fetch('admin.php?action=users&list=1')
                            .then(res => res.json())
                            .then(data => {
                                this.users = data;
                            });
                    },

                    loadOnlineMembers() {
                        fetch('admin.php?action=online')
                            .then(res => res.text())
                            .then(data => {
                                this.onlineMembers = data;
                            });
                    },

                    scrollToBottom() {
                        this.$nextTick(() => {
                            let container = this.$refs.chatMessages;
                            if (container) container.scrollTop = container.scrollHeight;
                        });
                    },

                    fetchMessages() {
                        let url = `admin.php?action=chat&type=${this.activeChatTab}`;
                        if (this.activeChatTab === 'guest' && this.chatSessionId) {
                            url += `&session_id=${this.chatSessionId}`;
                        }
                        fetch(url)
                            .then(res => res.json())
                            .then(data => {
                                if (!data.error) {
                                    this.teamMessages = data;
                                    setTimeout(() => {
                                        if(this.$refs.chatMessages) {
                                            this.$refs.chatMessages.scrollTop = this.$refs.chatMessages.scrollHeight;
                                        }
                                    }, 100);
                                }
                            })
                            .catch(err => console.error("Error fetching messages:", err));
                    },

                    sendTeamMessage() {
                        if (!this.teamMessage.trim()) return;

                        if (this.activeChatTab === 'staff' && this.chatReceiverId === '0') {
                            alert("Please select a staff member to message.");
                            return;
                        }

                        fetch('admin.php?action=chat', {
                            method: 'POST',
                            body: JSON.stringify({
                                message: this.teamMessage,
                                type: this.activeChatTab,
                                receiver_id: this.chatReceiverId,
                                session_id: this.chatSessionId
                            }),
                            headers: {'Content-Type': 'application/json'}
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.teamMessage = '';
                                this.fetchMessages();
                            } else {
                                alert(data.error || 'Failed to send message');
                            }
                        });
                    },

                    dragStart(event, task) {
                        this.draggedTask = task;
                        event.dataTransfer.effectAllowed = 'move';
                    },

                    drop(event, status) {
                        event.preventDefault();
                        if (this.draggedTask) {
                            fetch('admin.php?action=tasks', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id: this.draggedTask.id,
                                    status: status
                                })
                            })
                            .then(() => {
                                this.loadTasks();
                                this.draggedTask = null;
                            });
                        }
                    },

                    createTask() {
                        fetch('admin.php?action=tasks', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.taskForm)
                        })
                        .then(res => res.json())
                        .then(() => {
                            this.showTaskModal = false;
                            this.taskForm = {
                                title: '',
                                description: '',
                                assigned_to: '',
                                priority: 'medium',
                                due_date: ''
                            };
                            this.loadTasks();
                            window.appNotify('Task created successfully');
                        });
                    },

                    addContact() {
                        fetch('admin.php?action=directory', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.contactForm)
                        })
                        .then(res => res.json())
                        .then(() => {
                            this.showContactModal = false;
                            this.contactForm = {
                                name: '',
                                profession: '',
                                contact_number: '',
                                locality: '',
                                notes: ''
                            };
                            this.searchContacts();
                            window.appNotify('Contact added successfully');
                        });
                    },

                                        generateQR(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;

                        const qrData = JSON.stringify({
                            id: worker.id,
                            worker_code: workerCode,
                            name: worker.name,
                            skills: worker.skills,
                            type: 'worker'
                        });

                        const canvas = document.getElementById('qr-' + worker.id);
                        if (canvas) {
                            QRCode.toCanvas(canvas, qrData, {
                                width: 100,
                                margin: 1,
                                color: {
                                    dark: '#0f3b5e',
                                    light: '#ffffff'
                                }
                            }, function(error) {
                                if (error) console.error('QR Error:', error);
                            });
                        }
                    },

                                        viewWorker(worker) {
                        // Create modal to view worker details
                        const modalHtml = `
                            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="viewWorkerModal">
                                <div class="bg-white rounded-lg p-6 w-2/3 max-h-screen overflow-y-auto">
                                    <div class="flex justify-between items-center mb-4">
                                        <h3 class="text-xl font-bold">Worker Details</h3>
                                        <button onclick="document.getElementById('viewWorkerModal').remove()" class="text-gray-500 hover:text-gray-700">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-3 gap-4">
                                        <!-- Photo Column -->
                                        <div class="col-span-1">
                                            <div class="bg-gray-100 rounded-lg p-4 text-center">
                                                <img src="${worker.photo_url || 'https://via.placeholder.com/150'}" 
                                                     class="w-32 h-32 rounded-full mx-auto mb-3 object-cover">
                                                <h4 class="font-bold text-lg">${worker.name}</h4>
                                                <p class="text-gray-600">${worker.skills || 'No skills listed'}</p>
                                                <span class="inline-block px-3 py-1 rounded-full text-sm mt-2 ${
                                                    worker.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                                }">
                                                    ${worker.status}
                                                </span>
                                            </div>

                                            <div class="mt-4 bg-gray-50 rounded-lg p-4">
                                                <h5 class="font-bold mb-2">Quick Actions</h5>
                                                <div class="space-y-2">
                                                    <button onclick="document.getElementById('viewWorkerModal').remove(); window.workersData.editWorker(${JSON.stringify(worker).replace(/"/g, '&quot;')})" 
                                                            class="w-full text-left px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                                        <i class="fas fa-edit mr-2"></i>Edit Worker
                                                    </button>
                                                    <button onclick="window.workersData.generateIDCard(${JSON.stringify(worker).replace(/"/g, '&quot;')})" 
                                                            class="w-full text-left px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                                        <i class="fas fa-id-card mr-2"></i>Generate ID Card
                                                    </button>
                                                    <button onclick="window.workersData.viewWorkerAttendance(${worker.id})" 
                                                            class="w-full text-left px-3 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                                                        <i class="fas fa-calendar-check mr-2"></i>View Attendance
                                                    </button>
                                                    <button onclick="window.workersData.viewWorkerDocuments(${worker.id})" 
                                                            class="w-full text-left px-3 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">
                                                        <i class="fas fa-file mr-2"></i>View Documents
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Details Column -->
                                        <div class="col-span-2">
                                            <div class="bg-white border rounded-lg p-4">
                                                <h5 class="font-bold mb-3">Personal Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Full Name</p>
                                                        <p class="font-medium">${worker.name}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Father's Name</p>
                                                        <p class="font-medium">${worker.father_name || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Date of Birth</p>
                                                        <p class="font-medium">${worker.dob || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Gender</p>
                                                        <p class="font-medium">${worker.gender || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Blood Group</p>
                                                        <p class="font-medium">${worker.blood_group || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Worker Code</p>
                                                        <p class="font-medium">${worker.worker_code || this.generateWorkerCode(worker.id)}</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Contact Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Phone</p>
                                                        <p class="font-medium">${worker.phone || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Email</p>
                                                        <p class="font-medium">${worker.email || 'Not provided'}</p>
                                                    </div>
                                                    <div class="col-span-2">
                                                        <p class="text-sm text-gray-500">Address</p>
                                                        <p class="font-medium">${worker.address || 'Not provided'}</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Professional Information</h5>
                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Skills</p>
                                                        <p class="font-medium">${worker.skills || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Experience</p>
                                                        <p class="font-medium">${worker.experience || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Qualification</p>
                                                        <p class="font-medium">${worker.qualification || 'Not provided'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Supervisor</p>
                                                        <p class="font-medium">${worker.supervisor || 'Not assigned'}</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Rating</p>
                                                        <p class="font-medium">${worker.rating || '0'} / 5</p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Reporting Head</p>
                                                        <p class="font-medium">${worker.reporting_head_name || 'Not assigned'}</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white border rounded-lg p-4 mt-4">
                                                <h5 class="font-bold mb-3">Bank Details</h5>
                                                <pre class="text-sm bg-gray-50 p-3 rounded">${worker.bank_details ? JSON.stringify(JSON.parse(worker.bank_details), null, 2) : 'No bank details provided'}</pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;

                        // Create a temporary div and append to body
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = modalHtml;
                        document.body.appendChild(tempDiv.firstChild);
                    },

                    editWorker(worker) {
                        // Populate form with worker data for editing
                        this.editingWorker = worker;
                        this.workerForm = {
                            name: worker.name || '',
                            father_name: worker.father_name || '',
                            dob: worker.dob || '',
                            gender: worker.gender || '',
                            phone: worker.phone || '',
                            email: worker.email || '',
                            address: worker.address || '',
                            skills: worker.skills || '',
                            experience: worker.experience || '',
                            qualification: worker.qualification || '',
                            blood_group: worker.blood_group || '',
                            supervisor: worker.supervisor || '',
                            status: worker.status || 'active',
                            photo_url: worker.photo_url || '',
                            worker_code: worker.worker_code || ''
                        };
                        this.showAddWorker = true;
                    },

                    viewWorkerAttendance(workerId) {
                        fetch('admin.php?action=attendance&worker_id=${workerId}')
                            .then(res => res.json())
                            .then(data => {
                                const modalHtml = `
                                    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="attendanceModal">
                                        <div class="bg-white rounded-lg p-6 w-2/3 max-h-screen overflow-y-auto">
                                            <div class="flex justify-between items-center mb-4">
                                                <h3 class="text-xl font-bold">Worker Attendance</h3>
                                                <button onclick="document.getElementById('attendanceModal').remove()" class="text-gray-500 hover:text-gray-700">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-4 gap-4 mb-4">
                                                <div class="bg-green-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-green-600">${data.present || 0}</p>
                                                    <p class="text-sm">Present</p>
                                                </div>
                                                <div class="bg-yellow-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-yellow-600">${data.absent || 0}</p>
                                                    <p class="text-sm">Absent</p>
                                                </div>
                                                <div class="bg-blue-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-blue-600">${data.leave || 0}</p>
                                                    <p class="text-sm">Leave</p>
                                                </div>
                                                <div class="bg-purple-100 p-3 rounded text-center">
                                                    <p class="text-2xl font-bold text-purple-600">${data.holiday || 0}</p>
                                                    <p class="text-sm">Holiday</p>
                                                </div>
                                            </div>
                                            <table class="w-full">
                                                <thead class="bg-gray-100">
                                                    <tr>
                                                        <th class="p-2 text-left">Date</th>
                                                        <th class="p-2 text-left">Status</th>
                                                        <th class="p-2 text-left">Check In</th>
                                                        <th class="p-2 text-left">Check Out</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${data.records.map(record => `
                                                        <tr class="border-b">
                                                            <td class="p-2">${record.date}</td>
                                                            <td class="p-2">
                                                                <span class="px-2 py-1 rounded-full text-xs ${
                                                                    record.status === 'present' ? 'bg-green-100 text-green-800' :
                                                                    record.status === 'absent' ? 'bg-red-100 text-red-800' :
                                                                    'bg-yellow-100 text-yellow-800'
                                                                }">${record.status}</span>
                                                            </td>
                                                            <td class="p-2">${record.punch_in || '-'}</td>
                                                            <td class="p-2">${record.punch_out || '-'}</td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                `;
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = modalHtml;
                                document.body.appendChild(tempDiv.firstChild);
                            });
                    },

                    viewWorkerDocuments(workerId) {
                        fetch('admin.php?action=workers&documents=${workerId}')
                            .then(res => res.json())
                            .then(data => {
                                const modalHtml = `
                                    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="documentsModal">
                                        <div class="bg-white rounded-lg p-6 w-2/3">
                                            <div class="flex justify-between items-center mb-4">
                                                <h3 class="text-xl font-bold">Worker Documents</h3>
                                                <button onclick="document.getElementById('documentsModal').remove()" class="text-gray-500 hover:text-gray-700">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-3 gap-4">
                                                ${data.documents ? JSON.parse(data.documents).map(doc => `
                                                    <div class="border rounded p-3 text-center">
                                                        <i class="fas fa-file-pdf text-4xl text-red-600 mb-2"></i>
                                                        <p class="text-sm font-medium">${doc.name}</p>
                                                        <p class="text-xs text-gray-500">${doc.type}</p>
                                                        <a href="${doc.url}" target="_blank" class="text-blue-600 text-sm mt-2 inline-block">View</a>
                                                    </div>
                                                `).join('') : '<p class="col-span-3 text-center text-gray-500">No documents uploaded</p>'}
                                            </div>
                                            <div class="mt-4">
                                                <button class="bg-blue-600 text-white px-4 py-2 rounded" onclick="document.getElementById('documentsModal').remove()">
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = modalHtml;
                                document.body.appendChild(tempDiv.firstChild);
                            });
                    },

                    generateWorkerCode(id) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = id.toString(16).toUpperCase().padStart(3, '0');
                        return `DKW${year}${month}${hexCode}`;
                    },

                    saveWorker() {
                        // Validate required fields
                        if (!this.workerForm.name || !this.workerForm.phone) {
                            alert('Name and Phone are required fields');
                            return;
                        }

                        // Generate worker code if not exists
                        if (!this.workerForm.worker_code) {
                            const nextId = this.workers.length > 0 ? Math.max(...this.workers.map(w => w.id)) + 1 : 1;
                            this.workerForm.worker_code = this.generateWorkerCode(nextId);
                        }

                        const url = this.editingWorker ? `admin.php?action=workers&id=${this.editingWorker.id}` : 'api/workers.php';
                        const method = this.editingWorker ? 'PUT' : 'POST';

                        fetch(url, {
                            method: method,
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.workerForm)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showAddWorker = false;
                                this.loadWorkers();
                                this.editingWorker = null;
                                this.workerForm = {
                                    name: '',
                                    father_name: '',
                                    dob: '',
                                    gender: '',
                                    phone: '',
                                    email: '',
                                    address: '',
                                    skills: '',
                                    experience: '',
                                    qualification: '',
                                    blood_group: '',
                                    supervisor: '',
                                    status: 'active'
                                };
                                window.appNotify(
                                    this.editingWorker ? 'Worker updated successfully' : 'Worker added successfully'
                                );
                            } else {
                                alert(data.error || 'Failed to save worker');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while saving worker');
                        });
                    },

                                        generateIDCard(worker) {
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = worker.id.toString(16).toUpperCase().padStart(3, '0');
                        const workerCode = `DKW${year}${month}${hexCode}`;

                        // Get company logo from settings
                        const logo = this.$root.settings?.site_logo || '🏢';
                        const logoHtml = logo.startsWith('http') ? 
                            `<img src="${logo}" style="max-width: 100%; max-height: 100%;">` : 
                            `<span style="font-size: 24px;">${logo}</span>`;

                        // Create temporary container for ID card
                        const tempDiv = document.createElement('div');
                        tempDiv.style.position = 'absolute';
                        tempDiv.style.left = '-9999px';
                        tempDiv.style.top = '-9999px';
                        tempDiv.innerHTML = `
                            <div class="id-card-preview" style="width: 85.6mm; height: 53.98mm; background: white; border: 1px solid #ccc; border-radius: 3mm; padding: 5mm; position: relative; font-family: Arial, sans-serif; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                <div class="logo" style="position: absolute; top: 5mm; left: 5mm; width: 15mm; height: 15mm; display: flex; align-items: center; justify-content: center;">
                                    ${logoHtml}
                                </div>
                                <img src="${worker.photo_url || 'https://via.placeholder.com/80x80?text=Photo'}" 
                                     style="position: absolute; top: 5mm; right: 5mm; width: 20mm; height: 20mm; border-radius: 2mm; object-fit: cover; border: 1px solid #ddd;">
                                <div style="position: absolute; top: 5mm; left: 25mm; right: 30mm;">
                                    <div style="font-weight: bold; font-size: 12pt; margin-bottom: 2px;">${worker.name}</div>
                                    <div style="font-size: 10pt; color: #666; margin-bottom: 2px;">${worker.skills || 'Worker'}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">ID: ${workerCode}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">Supervisor: ${worker.supervisor || 'Not Assigned'}</div>
                                    ${worker.blood_group ? `<div style="font-size: 8pt; color: #999;">Blood: ${worker.blood_group}</div>` : ''}
                                </div>
                                <div class="qr" style="position: absolute; bottom: 5mm; right: 5mm; width: 15mm; height: 15mm;" id="temp-qr-${worker.id}"></div>
                                <div class="footer" style="position: absolute; bottom: 5mm; left: 5mm; font-size: 6pt; color: #999;">
                                    www.hidk.in | Valid ID
                                </div>
                            </div>
                        `;

                        document.body.appendChild(tempDiv);

                        // Generate QR in the temporary element
                        const qrData = JSON.stringify({
                            id: worker.id,
                            worker_code: workerCode,
                            name: worker.name,
                            type: 'worker'
                        });

                        const tempCanvas = document.createElement('canvas');
                        QRCode.toCanvas(tempCanvas, qrData, { width: 50, margin: 0 }, function(error) {
                            if (error) {
                                console.error('QR Error:', error);
                                document.body.removeChild(tempDiv);
                                return;
                            }

                            // Replace QR placeholder with canvas
                            const qrPlaceholder = document.getElementById(`temp-qr-${worker.id}`);
                            if (qrPlaceholder) {
                                tempCanvas.style.width = '100%';
                                tempCanvas.style.height = '100%';
                                qrPlaceholder.appendChild(tempCanvas);
                            }

                            // Capture and download
                            html2canvas(tempDiv.firstChild, {
                                scale: 2,
                                backgroundColor: '#ffffff'
                            }).then(canvas => {
                                const link = document.createElement('a');
                                link.download = `Worker_ID_${workerCode}.png`;
                                link.href = canvas.toDataURL('image/png');
                                link.click();

                                // Clean up
                                document.body.removeChild(tempDiv);
                            });
                        });
                    },

                    // User Management Methods
                    openAddUserModal() {
                        this.editingUser = null;
                        this.userForm = {
                            id: null,
                            full_name: '',
                            email: '',
                            phone: '',
                            role: 'staff',
                            department: '',
                            reporting_head: '0',
                            is_active: 1,
                            password: ''
                        };
                        this.showAddUserModal = true;
                    },

                    editUser(user) {
                        this.editingUser = user;
                        this.userForm = { ...user, is_active: user.is_active ? 1 : 0, password: '' }; // Ensure is_active is number
                        this.showAddUserModal = true;
                    },

                    saveUser() {
                        if (!this.userForm.full_name || !this.userForm.email || !this.userForm.role) {
                            alert('Full Name, Email, and Role are required.');
                            return;
                        }
                        if (!this.editingUser && !this.userForm.password) {
                            alert('Password is required for new users.');
                            return;
                        }

                        const url = this.editingUser ? `admin.php?action=users&id=${this.editingUser.id}` : 'api/users.php';
                        const method = this.editingUser ? 'PUT' : 'POST';

                        fetch(url, {
                            method: method,
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.userForm)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showAddUserModal = false;
                                this.loadUsers();
                                window.appNotify(
                                    this.editingUser ? 'User updated successfully' : 'User added successfully'
                                );
                            } else {
                                alert(data.error || 'Failed to save user');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while saving user');
                        });
                    },

                    deleteUser(id) {
                        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                            fetch('admin.php?action=users&id=${id}', {
                                method: 'DELETE'
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadUsers();
                                    window.appNotify('User deleted successfully');
                                } else {
                                    alert(data.error || 'Failed to delete user');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while deleting user');
                            });
                        }
                    },

                    toggleUser(user) {
                        const newStatus = user.is_active ? 0 : 1;
                        if (confirm(`Are you sure you want to ${newStatus ? 'activate' : 'deactivate'} ${user.full_name}?`)) {
                            fetch('admin.php?action=users&id=${user.id}', {
                                method: 'PUT',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ is_active: newStatus })
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    this.loadUsers();
                                    window.appNotify(`User ${newStatus ? 'activated' : 'deactivated'}`);
                                } else {
                                    alert(data.error || 'Failed to change user status');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while changing user status');
                            });
                        }
                    },

                    getReportingHeadName(id) {
                        if (id === '0' || !id) return 'None';
                        const head = this.users.find(u => u.id == id);
                        return head ? head.full_name : 'Unknown';
                    },
                }
            }
        </script>
        <?php
    }

    private function renderManagement() {
        ?>
        <div x-data="management()" x-init="init()" class="overflow-x-hidden">
            <h1 class="text-3xl font-bold mb-6">Pipeline & Project Management</h1>

            <!-- Recruitment Pipeline -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Recruitment Pipeline</h2>
                    <button @click="showVacancyModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Vacancy
                    </button>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-5 gap-4">
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Applications</h3>
                            <div class="space-y-2" x-html="recruitment.applications"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Screening</h3>
                            <div class="space-y-2" x-html="recruitment.screening"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Interviews</h3>
                            <div class="space-y-2" x-html="recruitment.interviews"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Offers</h3>
                            <div class="space-y-2" x-html="recruitment.offers"></div>
                        </div>
                        <div class="bg-gray-100 rounded p-3">
                            <h3 class="font-bold text-sm mb-2">Onboarding</h3>
                            <div class="space-y-2" x-html="recruitment.onboarding"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daily Reports -->
            <div class="bg-white rounded-lg shadow mb-6" x-data="dailyReports()" x-init="init()">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold"><i class="fas fa-clipboard-list mr-2 text-blue-600"></i>Daily Reports</h2>
                    <button @click="showSubmitModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Submit My Report
                    </button>
                </div>
                <div class="p-4">
                    <div class="flex gap-4 mb-4">
                        <input type="date" x-model="filterDate" @change="loadReports()" class="border rounded px-3 py-2">
                        <select x-model="filterUser" @change="loadReports()" class="border rounded px-3 py-2">
                            <option value="">All Members</option>
                            <template x-for="u in teamList" :key="u.id">
                                <option :value="u.id" x-text="u.full_name"></option>
                            </template>
                        </select>
                    </div>
                    <div class="space-y-3">
                        <template x-for="r in reports" :key="r.id">
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-semibold" x-text="r.full_name"></span>
                                        <span class="text-gray-400 text-sm ml-2" x-text="r.report_date"></span>
                                        <span class="ml-2 text-xs px-2 py-1 rounded-full" 
                                            :class="{'bg-green-100 text-green-800': r.role === 'admin', 'bg-blue-100 text-blue-800': r.role === 'manager', 'bg-gray-100 text-gray-700': !['admin','manager'].includes(r.role)}"
                                            x-text="r.role"></span>
                                    </div>
                                    <div class="text-yellow-500">★★★★★</div>
                                </div>
                                <p class="mt-2 text-gray-700 text-sm" x-text="r.content"></p>
                                <div x-show="r.tasks_completed" class="mt-2 text-sm text-gray-500">
                                    <strong>Completed:</strong> <span x-text="r.tasks_completed"></span>
                                </div>
                                <div x-show="r.blockers" class="mt-1 text-sm text-red-600">
                                    <strong>Blockers:</strong> <span x-text="r.blockers"></span>
                                </div>
                            </div>
                        </template>
                        <div x-show="reports.length === 0" class="text-center text-gray-400 py-8">No reports found for selected filters.</div>
                    </div>
                </div>

                <!-- Submit Report Modal -->
                <div x-show="showSubmitModal" @click.outside="showSubmitModal = false" @keydown.escape.window="showSubmitModal = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-lg p-6 w-[560px] max-h-[90vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4">Submit Daily Report</h3>
                        <form @submit.prevent="submitReport">
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Date</label>
                                <input type="date" x-model="reportForm.report_date" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">What did you accomplish today?</label>
                                <textarea x-model="reportForm.content" class="w-full border rounded px-3 py-2" rows="4" required placeholder="Describe your work today..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Tasks Completed</label>
                                <input type="text" x-model="reportForm.tasks_completed" class="w-full border rounded px-3 py-2" placeholder="List completed tasks...">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">Blockers / Issues</label>
                                <input type="text" x-model="reportForm.blockers" class="w-full border rounded px-3 py-2" placeholder="Any blockers?">
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showSubmitModal = false" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Submit Report</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>


            <!-- Active Vacancies -->
            <div class="bg-white rounded-lg shadow mb-6" x-data="vacanciesManager()" x-init="init()">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Active Vacancies</h2>
                    <button @click="openModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Vacancy
                    </button>
                </div>
                <div class="p-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left bg-gray-50 border-b">
                                <th class="pb-3 px-2 font-semibold">Position</th>
                                <th class="pb-3 px-2 font-semibold">Location</th>
                                <th class="pb-3 px-2 font-semibold">Type</th>
                                <th class="pb-3 px-2 font-semibold">Salary</th>
                                <th class="pb-3 px-2 font-semibold">Status</th>
                                <th class="pb-3 px-2 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="v in vacancies" :key="v.id">
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-2">
                                        <span x-text="v.title"></span>
                                        <span x-show="v.urgent == 1" class="ml-2 bg-red-100 text-red-800 px-2 py-0.5 rounded-full text-xs">Urgent</span>
                                    </td>
                                    <td class="py-2 px-2" x-text="v.location"></td>
                                    <td class="py-2 px-2" x-text="v.type"></td>
                                    <td class="py-2 px-2" x-text="v.salary"></td>
                                    <td class="py-2 px-2">
                                        <span :class="v.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-0.5 rounded-full text-xs" x-text="v.is_active == 1 ? 'Active' : 'Inactive'"></span>
                                    </td>
                                    <td class="py-2 px-2">
                                        <button @click="editVacancy(v)" class="text-blue-600 hover:text-blue-800 mr-3" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button @click="deleteVacancy(v.id)" class="text-red-600 hover:text-red-800" title="Delete"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="vacancies.length === 0">
                                <td colspan="6" class="text-center text-gray-400 py-8">No active vacancies found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Vacancy Modal -->
                <div x-show="showModal" @click.outside="showModal = false; resetForm()" @keydown.escape.window="showModal = false; resetForm()" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-lg p-6 w-[480px] max-h-[90vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4" x-text="editMode ? 'Edit Vacancy' : 'Add Vacancy'"></h3>
                        <form @submit.prevent="saveVacancy">
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Position Title *</label>
                                <input type="text" x-model="form.title" class="w-full border rounded px-3 py-2" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Location *</label>
                                <input type="text" x-model="form.location" class="w-full border rounded px-3 py-2" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Job Type *</label>
                                <select x-model="form.type" class="w-full border rounded px-3 py-2" required>
                                    <option value="">Select type</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Temporary">Temporary</option>
                                </select></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Salary Range *</label>
                                <input type="text" x-model="form.salary" class="w-full border rounded px-3 py-2" placeholder="e.g. ₹8,000-12,000/month" required></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Description</label>
                                <textarea x-model="form.description" class="w-full border rounded px-3 py-2" rows="3"></textarea></div>
                            <div class="mb-3"><label class="block text-sm font-medium mb-1">Requirements</label>
                                <textarea x-model="form.requirements" class="w-full border rounded px-3 py-2" rows="2"></textarea></div>
                            <div class="mb-3 flex items-center gap-4">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" x-model="form.urgent" class="w-4 h-4"> Mark as Urgent
                                </label>
                                <template x-if="editMode">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" x-model="form.is_active" class="w-4 h-4"> Active
                                    </label>
                                </template>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="showModal = false; resetForm()" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Vacancy</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>


            <!-- Quotations Management -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Quotations</h2>
                    <button @click="showQuoteModal = true"
                            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>New Quotation
                    </button>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Quote #</th>
                                <th class="pb-2">Customer</th>
                                <th class="pb-2">Total</th>
                                <th class="pb-2">Status</th>
                                <th class="pb-2">Valid Until</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="quotationsList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Service Enquiries -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4 border-b">
                    <h2 class="text-xl font-bold">Service Enquiries</h2>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Date</th>
                                <th class="pb-2">Customer</th>
                                <th class="pb-2">Service</th>
                                <th class="pb-2">Status</th>
                                <th class="pb-2">Assigned To</th>
                                <th class="pb-2">Action Status</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="enquiriesList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Holidays and Events Planner -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold">Holidays and Events</h2>
                    <div class="flex gap-2">
                        <button @click="showEventModal = true"
                                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                            <i class="fas fa-calendar-plus mr-2"></i>Add Event
                        </button>
                        <button @click="showHolidayModal = true"
                                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Declare Holiday
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <table class="w-full">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="pb-2">Holiday Date</th>
                                <th class="pb-2">Description</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody x-html="holidaysList"></tbody>
                    </table>
                </div>
            </div>

            <!-- Vacancy Modal -->
            <div x-show="showVacancyModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add New Vacancy</h3>
                    <form @submit.prevent="saveVacancy">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Position Title</label>
                            <input type="text" x-model="vacancy.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Location</label>
                            <input type="text" x-model="vacancy.location" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Job Type</label>
                            <select x-model="vacancy.type" class="w-full border rounded px-3 py-2" required>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                                <option value="Internship">Internship</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Salary Range</label>
                            <input type="text" x-model="vacancy.salary" class="w-full border rounded px-3 py-2" placeholder="e.g., ₹15,000-25,000/month">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="vacancy.description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Requirements</label>
                            <textarea x-model="vacancy.requirements" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3 flex items-center">
                            <input type="checkbox" x-model="vacancy.urgent" class="mr-2">
                            <label class="text-sm font-medium">Urgent Hiring</label>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showVacancyModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quotation Modal -->
            <div x-show="showQuoteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Create New Quotation</h3>

                    <form @submit.prevent="saveQuotation">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Name</label>
                                <input type="text" x-model="quote.customer_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Email</label>
                                <input type="email" x-model="quote.customer_email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Customer Phone</label>
                                <input type="tel" x-model="quote.customer_phone" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Valid Until</label>
                                <input type="date" x-model="quote.valid_until" class="w-full border rounded px-3 py-2">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Items</label>
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100">
                                        <th class="p-2 text-left">Description</th>
                                        <th class="p-2 text-left">Quantity</th>
                                        <th class="p-2 text-left">Unit Price</th>
                                        <th class="p-2 text-left">Total</th>
                                        <th class="p-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, index) in quote.items" :key="index">
                                        <tr>
                                            <td class="p-2">
                                                <input type="text" x-model="item.description" class="w-full border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model="item.quantity" @input="calculateTotal" class="w-20 border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model="item.unit_price" @input="calculateTotal" class="w-24 border rounded px-2 py-1">
                                            </td>
                                            <td class="p-2" x-text="item.quantity * item.unit_price"></td>
                                            <td class="p-2">
                                                <button @click="removeItem(index)" class="text-red-600">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <button @click="addItem" type="button" class="mt-2 text-blue-600">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>

                        <div class="mb-4 text-right">
                            <p>Subtotal: ₹<span x-text="quote.subtotal"></span></p>
                            <p>Tax (18%): ₹<span x-text="quote.tax"></span></p>
                            <p class="font-bold">Total: ₹<span x-text="quote.total"></span></p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Terms & Conditions</label>
                            <textarea x-model="quote.terms" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>

                        <div class="flex justify-end space-x-2">
                            <button @click="showQuoteModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Create Quotation
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Holiday Modal -->
            <div x-show="showHolidayModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Declare Holiday</h3>
                    <form @submit.prevent="saveHoliday">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Holiday Date</label>
                            <input type="date" x-model="holiday.date" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="holiday.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showHolidayModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Event Modal -->
            <div x-show="showEventModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-[500px] max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Event</h3>
                    <form @submit.prevent="saveEvent">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Event Title</label>
                            <input type="text" x-model="eventObj.title" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Start Date</label>
                            <input type="date" x-model="eventObj.start_date" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">End Date (Optional)</label>
                            <input type="date" x-model="eventObj.end_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Event Type</label>
                            <select x-model="eventObj.event_type" class="w-full border rounded px-3 py-2" required>
                                <option value="general">General Event</option>
                                <option value="holiday">Holiday</option>
                                <option value="weekly-off">Weekly Off</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="eventObj.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showEventModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">Save Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <?php
    }

    private function renderProfile() {
        $user = $this->user;
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($user['id']), 3, '0', STR_PAD_LEFT);
        $employee_code = 'DKA' . $year . $month . $hexCode;

        $qr_data = json_encode([
            'id' => $user['id'],
            'employee_id' => $user['employee_id'],
            'employee_code' => $employee_code,
            'name' => $user['full_name'],
            'role' => $user['role'],
            'type' => 'staff'
        ]);
        ?>
        <div x-data="profile()" x-init="init()">
            <h1 class="text-3xl font-bold mb-6">Profile Plus</h1>

            <div class="grid grid-cols-3 gap-6">
                <!-- Digital ID Card -->
                <div class="col-span-1">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="bg-gradient-to-r from-blue-600 to-blue-800 p-4 text-white">
                            <h3 class="font-bold">Staff ID Card</h3>
                        </div>
                        <div class="p-4">
                            <!-- ID Card Preview -->
                            <div class="id-card-preview mx-auto mb-4" id="idCardPreview">
                                <div class="logo">
                                    <?php if (filter_var($this->settings['site_logo'] ?? '', FILTER_VALIDATE_URL)): ?>
                                        <img src="<?php echo $this->settings['site_logo']; ?>" alt="Logo" style="max-width: 100%; max-height: 100%;">
                                    <?php else: ?>
                                        <span style="font-size: 20px;"><?php echo $this->settings['site_logo'] ?? 'DK'; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($user['photo_url']): ?>
                                <img src="<?php echo $user['photo_url']; ?>" class="photo">
                                <?php endif; ?>
                                <div style="position: absolute; top: 5mm; left: 25mm;">
                                    <div style="font-weight: bold; font-size: 12pt;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div style="font-size: 10pt; color: #666;"><?php echo ucfirst($user['role']); ?></div>
                                    <div style="font-size: 8pt; color: #999;">ID: <?php echo $employee_code; ?></div>
                                    <div style="font-size: 8pt; color: #999;">RO: <?php echo $user['reporting_office'] ?? 'Head Office'; ?></div>
                                    <?php if ($user['blood_group']): ?>
                                    <div style="font-size: 8pt; color: #999;">Blood: <?php echo $user['blood_group']; ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="qr" id="qrCodePreview"></div>
                            </div>

                            <button @click="downloadIDCard" class="bg-blue-600 text-white px-4 py-2 rounded w-full">
                                <i class="fas fa-download mr-2"></i>Download ID Card
                            </button>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-lg shadow mt-4">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Quick Actions</h3>
                        </div>
                        <div class="p-4 space-y-2">
                            <button @click="showLeaveModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-calendar-alt mr-2 text-blue-600"></i>Apply Leave
                            </button>
                            <button @click="showExpenseModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-receipt mr-2 text-green-600"></i>Submit Expense
                            </button>
                            <button @click="showDocumentRequest = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-file mr-2 text-yellow-600"></i>Request Document
                            </button>
                            <!-- Profile photo upload -->
                            <label class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded cursor-pointer flex items-center">
                                <i class="fas fa-camera mr-2 text-purple-600"></i>Change Profile Photo
                                <input type="file" accept="image/jpeg,image/png,image/gif" class="hidden" @change="uploadProfilePhoto($event)">
                            </label>
                            <button @click="showPasswordModal = true" class="w-full text-left px-3 py-2 hover:bg-gray-100 rounded">
                                <i class="fas fa-key mr-2 text-red-600"></i>Change Password
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-span-2 space-y-4">
                    <!-- Personal Information -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b flex justify-between items-center">
                            <h3 class="font-bold">Personal Information</h3>
                            <button @click="showUpdateRequestModal = true" class="text-blue-600 text-sm">
                                <i class="fas fa-edit mr-1"></i>Request Update
                            </button>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm text-gray-500">Full Name</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Employee Code</p>
                                    <p class="font-medium"><?php echo $employee_code; ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Email</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Phone</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['phone']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Department</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['department'] ?? 'Not set'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Reporting Head</p>
                                    <p class="font-medium" x-text="reportingHeadName"></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Date of Joining</p>
                                    <p class="font-medium"><?php echo date('d M Y', strtotime($user['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">My Documents</h3>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-3 gap-4">
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('offer_letter')">
                                    <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                    <p class="text-sm">Offer Letter</p>
                                </div>
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('id_proof')">
                                    <i class="fas fa-id-card text-3xl text-blue-600 mb-2"></i>
                                    <p class="text-sm">ID Proof</p>
                                </div>
                                <div class="border rounded p-3 text-center cursor-pointer" @click="viewDocument('bank_details')">
                                    <i class="fas fa-university text-3xl text-green-600 mb-2"></i>
                                    <p class="text-sm">Bank Details</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time & Attendance -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Time & Attendance</h3>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-4 gap-4 mb-4">
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-green-600" x-text="attendance.present"></p>
                                    <p class="text-xs text-gray-600">Present Days</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-yellow-600" x-text="attendance.late"></p>
                                    <p class="text-xs text-gray-600">Late Days</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-purple-600" x-text="attendance.leave"></p>
                                    <p class="text-xs text-gray-600">Leaves</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-2xl font-bold text-red-600" x-text="attendance.absent"></p>
                                    <p class="text-xs text-gray-600">Absent</p>
                                </div>
                            </div>

                            <div class="border rounded">
                                <table class="w-full">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="p-2 text-left">Date</th>
                                            <th class="p-2 text-left">Punch In</th>
                                            <th class="p-2 text-left">Punch Out</th>
                                            <th class="p-2 text-left">Status</th>
                                            <th class="p-2 text-left">Location</th>
                                        </tr>
                                    </thead>
                                    <tbody x-html="attendanceHistory"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Report -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4 border-b">
                            <h3 class="font-bold">Daily Report</h3>
                        </div>
                        <div class="p-4">
                            <textarea x-model="dailyReport"
                                      placeholder="What did you work on today? Any blockers?"
                                      class="w-full border rounded p-3 h-32"></textarea>
                            <button @click="submitReport" class="mt-2 bg-blue-600 text-white px-4 py-2 rounded">
                                Submit Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leave Application Modal -->
            <div x-show="showLeaveModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Apply for Leave</h3>
                    <form @submit.prevent="submitLeave">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Leave Type</label>
                            <select x-model="leave.type" class="w-full border rounded px-3 py-2">
                                <option value="sick">Sick Leave</option>
                                <option value="casual">Casual Leave</option>
                                <option value="annual">Annual Leave</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Start Date</label>
                            <input type="date" x-model="leave.start_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">End Date</label>
                            <input type="date" x-model="leave.end_date" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason</label>
                            <textarea x-model="leave.reason" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showLeaveModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Expense Request Modal -->
            <div x-show="showExpenseModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Submit Expense</h3>
                    <form @submit.prevent="submitExpense">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Amount (₹)</label>
                            <input type="number" x-model="expense.amount" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Category</label>
                            <select x-model="expense.category" class="w-full border rounded px-3 py-2" required>
                                <option value="travel">Travel</option>
                                <option value="food">Food</option>
                                <option value="supplies">Office Supplies</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Description</label>
                            <textarea x-model="expense.description" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Receipt</label>
                            <input type="file" @change="uploadReceipt" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showExpenseModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Document Request Modal -->
            <div x-show="showDocumentRequest" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Request Document</h3>
                    <form @submit.prevent="submitDocumentRequest">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Document Type</label>
                            <select x-model="documentRequest.type" class="w-full border rounded px-3 py-2" required>
                                <option value="salary_slip">Salary Slip</option>
                                <option value="annual_statement">Annual Statement</option>
                                <option value="offer_letter">Offer Letter</option>
                                <option value="joining_letter">Joining Letter</option>
                                <option value="profile_summary">Profile Summary</option>
                                <option value="experience_certificate">Experience Certificate</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason for Request</label>
                            <textarea x-model="documentRequest.reason" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showDocumentRequest = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Change Modal -->
            <div x-show="showPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Change Password</h3>
                    <form @submit.prevent="changePassword">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Current Password</label>
                            <input type="password" x-model="password.current" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">New Password</label>
                            <input type="password" x-model="password.new" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Confirm New Password</label>
                            <input type="password" x-model="password.confirm" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showPasswordModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Profile Update Request Modal -->
            <div x-show="showUpdateRequestModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Request Profile Update</h3>
                    <form @submit.prevent="submitUpdateRequest">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Field to Update</label>
                            <select x-model="updateRequest.field" class="w-full border rounded px-3 py-2" required>
                                <option value="phone">Phone Number</option>
                                <option value="email">Email Address</option>
                                <option value="address">Address</option>
                                <option value="blood_group">Blood Group</option>
                                <option value="emergency_contact">Emergency Contact</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">New Value</label>
                            <input type="text" x-model="updateRequest.new_value" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Reason for Change</label>
                            <textarea x-model="updateRequest.reason" class="w-full border rounded px-3 py-2" rows="3" required></textarea>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showUpdateRequestModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function profile() {
                return {
                    showLeaveModal: false,
                    showExpenseModal: false,
                    showDocumentRequest: false,
                    showPasswordModal: false,
                    showUpdateRequestModal: false,
                    attendance: { present: 0, late: 0, leave: 0, absent: 0 },
                    attendanceHistory: '',
                    dailyReport: '',
                    reportingHeadName: '',

                    leave: {
                        type: 'sick',
                        start_date: '',
                        end_date: '',
                        reason: ''
                    },

                    expense: {
                        amount: '',
                        category: 'travel',
                        description: '',
                        receipt: null
                    },

                    documentRequest: {
                        type: 'salary_slip',
                        reason: ''
                    },

                    password: {
                        current: '',
                        new: '',
                        confirm: ''
                    },

                    updateRequest: {
                        field: 'phone',
                        new_value: '',
                        reason: ''
                    },

                    init() {
                        this.loadAttendance();
                        this.loadReportingHead();
                        this.generateQR();
                    },

                    loadAttendance() {
                        fetch('admin.php?action=attendance&my=1')
                            .then(res => res.json())
                            .then(data => {
                                this.attendance = data.summary;
                                this.attendanceHistory = data.history;
                            });
                    },

                    loadReportingHead() {
                        fetch('admin.php?action=users&reporting_head=1')
                            .then(res => res.json())
                            .then(data => {
                                this.reportingHeadName = data.name;
                            });
                    },

                    generateQR() {
                        const qrData = <?php echo json_encode($qr_data); ?>;
                        QRCode.toCanvas(document.getElementById('qrCodePreview'), JSON.stringify(qrData), {
                            width: 50,
                            margin: 0
                        });
                    },

                                        downloadIDCard() {
                        const user = <?php echo json_encode($user); ?>;
                        const year = new Date().getFullYear().toString().slice(-2);
                        const month = new Date().toLocaleString('default', { month: 'short' }).toUpperCase();
                        const hexCode = user.id.toString(16).toUpperCase().padStart(3, '0');
                        const employeeCode = `DKA${year}${month}${hexCode}`;

                        // Get company logo from settings
                        const logo = this.$root.settings?.site_logo || '🏢';
                        const logoHtml = logo.startsWith('http') ? 
                            `<img src="${logo}" style="max-width: 100%; max-height: 100%;">` : 
                            `<span style="font-size: 24px;">${logo}</span>`;

                        // Create temporary container for ID card
                        const tempDiv = document.createElement('div');
                        tempDiv.style.position = 'absolute';
                        tempDiv.style.left = '-9999px';
                        tempDiv.style.top = '-9999px';
                        tempDiv.innerHTML = `
                            <div class="id-card-preview" style="width: 85.6mm; height: 53.98mm; background: white; border: 1px solid #ccc; border-radius: 3mm; padding: 5mm; position: relative; font-family: Arial, sans-serif; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                <div class="logo" style="position: absolute; top: 5mm; left: 5mm; width: 15mm; height: 15mm; display: flex; align-items: center; justify-content: center;">
                                    ${logoHtml}
                                </div>
                                <img src="${user.photo_url || 'https://via.placeholder.com/80x80?text=Photo'}" 
                                     style="position: absolute; top: 5mm; right: 5mm; width: 20mm; height: 20mm; border-radius: 2mm; object-fit: cover; border: 1px solid #ddd;">
                                <div style="position: absolute; top: 5mm; left: 25mm; right: 30mm;">
                                    <div style="font-weight: bold; font-size: 12pt; margin-bottom: 2px;">${user.full_name}</div>
                                    <div style="font-size: 10pt; color: #666; margin-bottom: 2px;">${user.role}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">ID: ${employeeCode}</div>
                                    <div style="font-size: 8pt; color: #999; margin-bottom: 2px;">RO: ${user.reporting_office || 'Head Office'}</div>
                                    ${user.blood_group ? `<div style="font-size: 8pt; color: #999;">Blood: ${user.blood_group}</div>` : ''}
                                </div>
                                <div class="qr" style="position: absolute; bottom: 5mm; right: 5mm; width: 15mm; height: 15mm;" id="temp-qr-staff"></div>
                                <div class="footer" style="position: absolute; bottom: 5mm; left: 5mm; font-size: 6pt; color: #999;">
                                    www.hidk.in | Authorized Personnel
                                </div>
                            </div>
                        `;

                        document.body.appendChild(tempDiv);

                        // Generate QR
                        const qrData = JSON.stringify({
                            id: user.id,
                            employee_code: employeeCode,
                            name: user.full_name,
                            role: user.role,
                            type: 'staff'
                        });

                        const tempCanvas = document.createElement('canvas');
                        QRCode.toCanvas(tempCanvas, qrData, { width: 50, margin: 0 }, function(error) {
                            if (error) {
                                console.error('QR Error:', error);
                                document.body.removeChild(tempDiv);
                                return;
                            }

                            // Replace QR placeholder
                            const qrPlaceholder = document.getElementById('temp-qr-staff');
                            if (qrPlaceholder) {
                                tempCanvas.style.width = '100%';
                                tempCanvas.style.height = '100%';
                                qrPlaceholder.appendChild(tempCanvas);
                            }

                            // Capture and download
                            html2canvas(tempDiv.firstChild, {
                                scale: 2,
                                backgroundColor: '#ffffff'
                            }).then(canvas => {
                                const link = document.createElement('a');
                                link.download = `Staff_ID_${employeeCode}.png`;
                                link.href = canvas.toDataURL('image/png');
                                link.click();

                                // Clean up
                                document.body.removeChild(tempDiv);
                            });
                        });
                    },

                    viewDocument(type) {
                        fetch('admin.php?action=documents&type=${type}')
                            .then(res => res.blob())
                            .then(blob => {
                                const url = window.URL.createObjectURL(blob);
                                window.open(url);
                            });
                    },

                    submitReport() {
                        fetch('admin.php?action=reports', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ report: this.dailyReport })
                        })
                        .then(() => {
                            this.dailyReport = '';
                            window.appNotify('Report submitted');
                        });
                    },

                    submitLeave() {
                        fetch('admin.php?action=leaves', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.leave)
                        })
                        .then(() => {
                            this.showLeaveModal = false;
                            window.appNotify('Leave application submitted');
                        });
                    },

                    uploadProfilePhoto(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        const allowed = ['image/jpeg', 'image/png', 'image/gif'];
                        if (!allowed.includes(file.type)) {
                            alert('Only JPG, PNG, GIF images are allowed'); return;
                        }
                        if (file.size > 5 * 1024 * 1024) {
                            alert('Image must be under 5MB'); return;
                        }
                        const formData = new FormData();
                        formData.append('photo', file);
                        fetch('admin.php?action=profile_requests', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success) {
                                    window.appNotify('📸 Photo uploaded! Pending manager approval.');
                                } else {
                                    alert(data.error || 'Failed to upload photo');
                                }
                            })
                            .catch(() => alert('Network error'));
                    },

                    uploadReceipt(e) {
                        const file = e.target.files[0];
                        const formData = new FormData();
                        formData.append('receipt', file);

                        fetch('admin.php?action=upload', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.expense.receipt = data.url;
                        });
                    },

                                        submitExpense() {
                        if (!this.expense.amount || !this.expense.category || !this.expense.description) {
                            alert('Please fill all required fields');
                            return;
                        }

                        fetch('admin.php?action=expenses', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.expense)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showExpenseModal = false;
                                this.expense = {
                                    amount: '',
                                    category: 'travel',
                                    description: '',
                                    receipt: null
                                };
                                window.appNotify('Expense request submitted');
                            } else {
                                alert(data.error || 'Failed to submit expense');
                            }
                        });
                    },

                    submitDocumentRequest() {
                        if (!this.documentRequest.type || !this.documentRequest.reason) {
                            alert('Please fill all required fields');
                            return;
                        }

                        fetch('admin.php?action=document_requests', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.documentRequest)
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showDocumentRequest = false;
                                this.documentRequest = {
                                    type: 'salary_slip',
                                    reason: ''
                                };
                                window.appNotify('Document request submitted');
                            } else {
                                alert(data.error || 'Failed to submit request');
                            }
                        });
                    },

                    viewDocument(type) {
                        fetch('admin.php?action=documents&type=${type}')
                            .then(res => {
                                if (res.ok) {
                                    return res.blob();
                                }
                                throw new Error('Document not found');
                            })
                            .then(blob => {
                                const url = window.URL.createObjectURL(blob);
                                window.open(url);
                            })
                            .catch(error => {
                                alert('Document not available. Please request it.');
                            });
                    },

                    changePassword() {
                        if (this.password.new !== this.password.confirm) {
                            alert('New passwords do not match');
                            return;
                        }

                        fetch('admin.php?action=users', {
                            method: 'PUT',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'change_password', passwords: this.password })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                this.showPasswordModal = false;
                                this.password = { current: '', new: '', confirm: '' };
                                window.appNotify('Password changed successfully');
                            } else {
                                alert(data.error || 'Failed to change password');
                            }
                        });
                    },

                    submitUpdateRequest() {
                        fetch('admin.php?action=profile_requests', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.updateRequest)
                        })
                        .then(() => {
                            this.showUpdateRequestModal = false;
                            window.appNotify('Update request submitted for approval');
                        });
                    }
                }
            }
        </script>
        <?php
    }

    private function renderWorkers() {
        ?>
        <div x-data="workers()" x-init="init()">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-bold">Workers Management</h1>
                <button @click="showAddWorker = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    <i class="fas fa-plus mr-2"></i>Add Worker
                </button>
            </div>

            <!-- Workers Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template x-for="worker in workers" :key="worker.id">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="p-4">
                            <div class="flex items-center space-x-3">
                                <img :src="worker.photo_url || 'https://via.placeholder.com/50'"
                                     class="w-12 h-12 rounded-full object-cover">
                                <div>
                                    <h3 class="font-bold" x-text="worker.name"></h3>
                                    <p class="text-sm text-gray-600" x-text="worker.skills"></p>
                                </div>
                            </div>

                            <div class="mt-3 space-y-1 text-sm">
                                <p><i class="fas fa-phone w-4 text-gray-400"></i> <span x-text="worker.phone"></span></p>
                                <p><i class="fas fa-map-marker-alt w-4 text-gray-400"></i> <span x-text="worker.address"></span></p>
                                <p><i class="fas fa-star w-4 text-yellow-400"></i> <span x-text="worker.rating + ' / 5'"></span></p>
                                <p><i class="fas fa-user-tie w-4 text-gray-400"></i> <span x-text="worker.supervisor || 'Not assigned'"></span></p>
                            </div>

                            <div class="mt-3 flex justify-between items-center">
                                <span :class="{
                                    'bg-green-100 text-green-800': worker.status === 'active',
                                    'bg-gray-100 text-gray-800': worker.status === 'inactive'
                                }" class="px-2 py-1 rounded-full text-xs" x-text="worker.status"></span>

                                <div class="flex space-x-2">
                                    <button @click="viewWorker(worker)" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button @click="generateIDCard(worker)" class="text-green-600 hover:text-green-800">
                                        <i class="fas fa-id-card"></i>
                                    </button>
                                    <button @click="editWorker(worker)" class="text-yellow-600 hover:text-yellow-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- QR Code -->
                            <div class="mt-3 text-center">
                                <div :id="'qr-' + worker.id" class="inline-block"></div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Add/Edit Worker Modal -->
            <div x-show="showAddWorker" @click.outside="showAddWorker = false" @keydown.escape.window="showAddWorker = false" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4" x-text="editingWorker ? 'Edit Worker' : 'Add New Worker'"></h3>

                    <form @submit.prevent="saveWorker">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Full Name *</label>
                                <input type="text" x-model="workerForm.name" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Father's Name</label>
                                <input type="text" x-model="workerForm.father_name" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Date of Birth</label>
                                <input type="date" x-model="workerForm.dob" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Gender</label>
                                <select x-model="workerForm.gender" class="w-full border rounded px-3 py-2">
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Phone *</label>
                                <input type="tel" x-model="workerForm.phone" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Email</label>
                                <input type="email" x-model="workerForm.email" class="w-full border rounded px-3 py-2">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-medium mb-1">Address</label>
                                <textarea x-model="workerForm.address" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-medium mb-1">Skills (comma separated)</label>
                                <input type="text" x-model="workerForm.skills" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Experience</label>
                                <input type="text" x-model="workerForm.experience" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Qualification</label>
                                <input type="text" x-model="workerForm.qualification" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Blood Group</label>
                                <select x-model="workerForm.blood_group" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Supervisor</label>
                                <select x-model="workerForm.supervisor" class="w-full border rounded px-3 py-2">
                                    <option value="">Select Supervisor</option>
                                    <template x-for="user in users" :key="user.id">
                                        <option :value="user.full_name" x-text="user.full_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Photo</label>
                                <input type="file" @change="uploadPhoto" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Status</label>
                                <select x-model="workerForm.status" class="w-full border rounded px-3 py-2">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="on_leave">On Leave</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-2 mt-4">
                            <button @click="showAddWorker = false" type="button" class="px-4 py-2 bg-gray-200 rounded">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
                                Save Worker
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <?php
    }

    private function renderSettings() {
        ?>
        <div x-data="settings()" x-init="init()">
            <h1 class="text-3xl font-bold mb-6">Settings</h1>

            <div class="grid grid-cols-4 gap-4">
                <!-- Settings Navigation -->
                <div class="col-span-1">
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-4">
                            <ul class="space-y-2">
                                <?php if ($this->user['id'] == 1): ?>
                                <li><button @click="activeSection = 'data_manage'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'data_manage'}"
                                            class="w-full text-left px-3 py-2 rounded">Data Management</button></li>
                                <?php endif; ?>
                                <li><button @click="activeSection = 'homepage'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'homepage'}"
                                            class="w-full text-left px-3 py-2 rounded">Homepage</button></li>
                                <li><button @click="activeSection = 'general'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'general'}"
                                            class="w-full text-left px-3 py-2 rounded">General</button></li>
                                <li><button @click="activeSection = 'contact'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'contact'}"
                                            class="w-full text-left px-3 py-2 rounded">Contact Info</button></li>
                                <li><button @click="activeSection = 'team'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'team'}"
                                            class="w-full text-left px-3 py-2 rounded">Team Members</button></li>
                                <li><button @click="activeSection = 'templates'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'templates'}"
                                            class="w-full text-left px-3 py-2 rounded">Templates</button></li>
                                <li><button @click="activeSection = 'email'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'email'}"
                                            class="w-full text-left px-3 py-2 rounded">Email Configuration</button></li>
                                <li><button @click="activeSection = 'payment'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'payment'}"
                                            class="w-full text-left px-3 py-2 rounded">Payment Info</button></li>
                                <li><button @click="activeSection = 'security'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'security'}"
                                            class="w-full text-left px-3 py-2 rounded">Security</button></li>
                                <li><button @click="activeSection = 'social'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'social'}"
                                            class="w-full text-left px-3 py-2 rounded">Social Media</button></li>
                                <li><button @click="activeSection = 'audit'"
                                            :class="{'bg-blue-600 text-white': activeSection === 'audit'}"
                                            class="w-full text-left px-3 py-2 rounded">Audit Logs</button></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Settings Content -->
                <div class="col-span-3">
                    <div class="bg-white rounded-lg shadow p-6">

                        <!-- Data Management -->
                        <?php if ($this->user['id'] == 1): ?>
                        <div x-show="activeSection === 'data_manage'">
                            <h2 class="text-xl font-bold mb-4">Export & Import Utility</h2>
                            <p class="text-sm text-gray-600 mb-6">Backup your data or bulk import records via CSV/VCF.</p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="border p-6 rounded-lg bg-gray-50">
                                    <h3 class="font-bold mb-4"><i class="fas fa-file-export mr-2 text-blue-600"></i>Export Data</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Select Table</label>
                                            <select x-model="dataManage.exportTable" class="w-full border rounded px-3 py-2">
                                                <option value="workers">Workers</option>
                                                <option value="admin_users">Staff/Users</option>
                                                <option value="applications">Job Applications</option>
                                                <option value="enquiries">Service Enquiries</option>
                                                <option value="attendance">Attendance Records</option>
                                                <option value="salary_records">Salary History</option>
                                                <option value="tasks">Tasks</option>
                                            </select>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="exportData('csv')" class="flex-1 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Export CSV</button>
                                            <button x-show="['workers', 'admin_users'].includes(dataManage.exportTable)" @click="exportData('vcf')" class="flex-1 bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Export VCF</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="border p-6 rounded-lg bg-gray-50">
                                    <h3 class="font-bold mb-4"><i class="fas fa-file-import mr-2 text-orange-600"></i>Import Data (CSV)</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Target Table</label>
                                            <select x-model="dataManage.importTable" class="w-full border rounded px-3 py-2">
                                                <option value="workers">Workers</option>
                                                <option value="admin_users">Staff/Users</option>
                                                <option value="tasks">Tasks</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium mb-1">Select CSV File</label>
                                            <input type="file" @change="dataManage.file = $event.target.files[0]" class="w-full border rounded px-3 py-2">
                                        </div>
                                        <button @click="importData" class="w-full bg-orange-600 text-white px-4 py-2 rounded hover:bg-orange-700">Run Import</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Homepage Customization -->
                        <div x-show="activeSection === 'homepage'">
                            <h2 class="text-xl font-bold mb-4">Homepage Customization</h2>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Statistics</h3>
                                <template x-for="(stat, index) in homepage.stats" :key="index">
                                    <div class="grid grid-cols-2 gap-4 mb-2">
                                        <input type="text" x-model="stat.number" placeholder="Number" class="border rounded px-3 py-2">
                                        <div class="flex space-x-2">
                                            <input type="text" x-model="stat.label" placeholder="Label" class="flex-1 border rounded px-3 py-2">
                                            <button @click="homepage.stats.splice(index, 1)" class="text-red-600"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </template>
                                <button @click="homepage.stats.push({number: '', label: ''})" class="text-blue-600 text-sm mt-2">+ Add Statistic</button>
                            </div>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Why Choose Us</h3>
                                <template x-for="(point, index) in homepage.why_choose_us" :key="index">
                                    <div class="border p-4 rounded mb-4">
                                        <div class="grid grid-cols-2 gap-4 mb-2">
                                            <input type="text" x-model="point.title" placeholder="Title" class="border rounded px-3 py-2">
                                            <input type="text" x-model="point.icon" placeholder="Icon (e.g. bi-clock)" class="border rounded px-3 py-2">
                                        </div>
                                        <textarea x-model="point.description" class="w-full border rounded px-3 py-2 mt-2" rows="2"></textarea>
                                        <button @click="homepage.why_choose_us.splice(index, 1)" class="text-red-600 mt-2 text-sm">Remove</button>
                                    </div>
                                </template>
                                <button @click="homepage.why_choose_us.push({title: '', icon: '', description: ''})" class="text-blue-600 text-sm">+ Add Point</button>
                            </div>
                            <div class="mb-8 border-b pb-6">
                                <h3 class="font-bold mb-3">Service Categories</h3>
                                <template x-for="(cat, index) in homepage.service_categories" :key="index">
                                    <div class="border p-4 rounded mb-4">
                                        <div class="grid grid-cols-2 gap-4 mb-2">
                                            <input type="text" x-model="cat.title" placeholder="Title" class="border rounded px-3 py-2">
                                            <input type="text" x-model="cat.icon" placeholder="Icon" class="border rounded px-3 py-2">
                                        </div>
                                        <input type="text" x-model="cat.description" placeholder="Short description" class="w-full border rounded px-3 py-2 mb-2">
                                        <div class="mt-2">
                                            <label class="text-sm font-bold">Services (comma separated)</label>
                                            <input type="text" x-model="cat.services_text" @change="cat.services = cat.services_text.split(',').map(s => s.trim())" class="w-full border rounded px-3 py-2">
                                        </div>
                                        <button @click="homepage.service_categories.splice(index, 1)" class="text-red-600 mt-2 text-sm">Remove</button>
                                    </div>
                                </template>
                                <button @click="homepage.service_categories.push({title: '', icon: '', description: '', services: [], services_text: ''})" class="text-blue-600 text-sm">+ Add Category</button>
                            </div>
                            <button @click="saveHomepage" class="bg-blue-600 text-white px-6 py-2 rounded font-bold shadow hover:bg-blue-700">Save Homepage Changes</button>
                        </div>

                        <!-- Social Media Settings -->
                        <div x-show="activeSection === 'social'">
                            <h2 class="text-xl font-bold mb-4">Social Media Links</h2>
                            <form @submit.prevent="saveSocial">
                                <div class="space-y-4">
                                    <template x-for="(link, key) in social_media" :key="key">
                                        <div class="border p-4 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-medium" x-text="key.charAt(0).toUpperCase() + key.slice(1)"></span>
                                                <label class="flex items-center cursor-pointer">
                                                    <div class="relative">
                                                        <input type="checkbox" x-model="link.visible" class="sr-only">
                                                        <div class="block bg-gray-600 w-10 h-6 rounded-full"></div>
                                                        <div :class="link.visible ? 'translate-x-full bg-blue-500' : 'bg-white'" class="dot absolute left-1 top-1 w-4 h-4 rounded-full transition"></div>
                                                    </div>
                                                    <span class="ml-3 text-sm">Visible</span>
                                                </label>
                                            </div>
                                            <input type="text" x-model="link.url" class="w-full border rounded px-3 py-2" :placeholder="'Enter ' + key + ' link'" :disabled="!link.visible">
                                        </div>
                                    </template>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Social Media</button>
                                </div>
                            </form>
                        </div>

                        <!-- General Settings -->
                        <div x-show="activeSection === 'general'">
                            <h2 class="text-xl font-bold mb-4">General Settings</h2>
                            <form @submit.prevent="saveGeneral">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Title</label>
                                        <input type="text" x-model="settings.site_title" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Application Timezone</label>
                                        <select x-model="settings.timezone" class="w-full border rounded px-3 py-2">
                                            <option value="Asia/Kolkata">IST – Asia/Kolkata (Default)</option>
                                            <option value="Asia/Mumbai">Asia/Mumbai</option>
                                            <option value="Asia/Delhi">Asia/Delhi</option>
                                            <option value="UTC">UTC</option>
                                            <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                                            <option value="America/New_York">America/New_York (EST)</option>
                                            <option value="Europe/London">Europe/London (GMT)</option>
                                        </select>
                                        <p class="text-xs text-gray-500 mt-1">This timezone applies to all timestamps, logs, and attendance records.</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Tagline</label>
                                        <input type="text" x-model="settings.site_tagline" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Site Logo</label>
                                        <input type="file" @change="uploadLogo" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Favicon</label>
                                        <input type="file" @change="uploadFavicon" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Founded Year</label>
                                        <input type="text" x-model="settings.founded_year" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Contact Info -->
                        <div x-show="activeSection === 'contact'">
                            <h2 class="text-xl font-bold mb-4">Contact Information</h2>
                            <form @submit.prevent="saveContact">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Phone</label>
                                        <input type="text" x-model="settings.company_phone" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company WhatsApp</label>
                                        <input type="text" x-model="settings.company_whatsapp" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Email</label>
                                        <input type="email" x-model="settings.company_email" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Company Address</label>
                                        <textarea x-model="settings.company_address" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Working Hours</label>
                                        <input type="text" x-model="settings.company_hours" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Team Members -->
                        <div x-show="activeSection === 'team'">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold">Team Members</h2>
                                <button @click="showAddTeam = true" class="bg-blue-600 text-white px-3 py-1 rounded">
                                    <i class="fas fa-plus mr-1"></i>Add Member
                                </button>
                            </div>

                            <div class="space-y-2">
                                <template x-for="member in teamMembers" :key="member.id">
                                    <div class="border rounded p-3 flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <img :src="member.photo_url || 'https://via.placeholder.com/40'" class="w-10 h-10 rounded-full">
                                            <div>
                                                <p class="font-medium" x-text="member.name"></p>
                                                <p class="text-sm text-gray-600" x-text="member.position"></p>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button @click="editTeamMember(member)" class="text-blue-600">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button @click="deleteTeamMember(member.id)" class="text-red-600">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Templates Management -->
                        <div x-show="activeSection === 'templates'">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xl font-bold">Document Templates</h2>
                                <button @click="showTemplateModal = true" class="bg-blue-600 text-white px-3 py-1 rounded">
                                    <i class="fas fa-plus mr-1"></i>New Template
                                </button>
                            </div>

                            <div class="mb-4">
                                <select x-model="templateType" @change="loadTemplates" class="border rounded px-3 py-2">
                                    <option value="staff_id">Staff ID Card</option>
                                    <option value="worker_id">Worker ID Card</option>
                                    <option value="offer_letter">Offer Letter</option>
                                    <option value="joining_letter">Joining Letter</option>
                                    <option value="salary_slip">Salary Slip</option>
                                    <option value="annual_statement">Annual Statement</option>
                                    <option value="interview_invitation">Interview Invitation</option>
                                    <option value="deployment_letter">Deployment Letter</option>
                                    <option value="company_policies">Company Policies</option>
                                    <option value="faqs">FAQs</option>
                                    <option value="warning_letter">Warning Letter</option>
                                    <option value="termination_letter">Termination Letter</option>
                                    <option value="service_agreement">Service Agreement</option>
                                    <option value="custom">Custom Template</option>
                                </select>
                            </div>

                            <div class="space-y-2" x-html="templatesList"></div>

                            <!-- Template Editor Modal -->
                            <div x-show="showTemplateModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                                <div class="bg-white rounded-lg p-6 w-2/3 max-h-[90vh] overflow-y-auto">
                                    <h3 class="text-lg font-bold mb-4">Edit Template</h3>
                                    <form @submit.prevent="saveTemplate">
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">Template Name</label>
                                            <input type="text" x-model="templateForm.name" class="w-full border rounded px-3 py-2" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">HTML Content</label>
                                            <textarea x-model="templateForm.content" class="w-full border rounded px-3 py-2 font-mono" rows="10"></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="block text-sm font-medium mb-1">CSS</label>
                                            <textarea x-model="templateForm.css" class="w-full border rounded px-3 py-2 font-mono" rows="5"></textarea>
                                        </div>
                                        <div class="mb-3 flex items-center">
                                            <input type="checkbox" x-model="templateForm.is_default" class="mr-2">
                                            <label class="text-sm font-medium">Set as Default</label>
                                        </div>
                                        <div class="flex justify-end space-x-2">
                                            <button @click="showTemplateModal = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save Template</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>


                        <!-- Audit Logs -->
                        <div x-show="activeSection === 'audit'">
                            <h2 class="text-xl font-bold mb-4">Audit Logs</h2>

                            <div class="mb-4 flex space-x-2">
                                <input type="text" x-model="auditFilters.user" placeholder="Filter by user" class="border rounded px-3 py-2">
                                <select x-model="auditFilters.action" class="border rounded px-3 py-2">
                                    <option value="">All Actions</option>
                                    <option value="login">Login</option>
                                    <option value="logout">Logout</option>
                                    <option value="failed_login">Failed Login</option>
                                    <option value="task_created">Task Created</option>
                                                                        <option value="task_completed">Task Completed</option>
                                    <option value="quote_created">Quote Created</option>
                                    <option value="worker_added">Worker Added</option>
                                    <option value="settings_updated">Settings Updated</option>
                                    <option value="user_created">User Created</option>
                                    <option value="user_updated">User Updated</option>
                                    <option value="document_downloaded">Document Downloaded</option>
                                </select>
                                <input type="date" x-model="auditFilters.date" class="border rounded px-3 py-2">
                                <button @click="loadAuditLogs" class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
                                <button @click="exportAuditLogs" class="bg-green-600 text-white px-4 py-2 rounded">Export</button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="text-left border-b">
                                            <th class="pb-2">Time</th>
                                            <th class="pb-2">User</th>
                                            <th class="pb-2">Action</th>
                                            <th class="pb-2">Details</th>
                                            <th class="pb-2">IP Address</th>
                                            <th class="pb-2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody x-html="auditLogsList"></tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex justify-between items-center">
                                <button @click="prevPage" :disabled="currentPage === 1" class="px-3 py-1 bg-gray-200 rounded disabled:opacity-50">Previous</button>
                                <span>Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span></span>
                                <button @click="nextPage" :disabled="currentPage === totalPages" class="px-3 py-1 bg-gray-200 rounded disabled:opacity-50">Next</button>
                            </div>
                        </div>

                        <!-- Email Configuration -->
                        <div x-show="activeSection === 'email'">
                            <h2 class="text-xl font-bold mb-4">Email Configuration</h2>
                            <form @submit.prevent="saveEmail">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Host</label>
                                        <input type="text" x-model="email.smtp_host" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Port</label>
                                        <input type="text" x-model="email.smtp_port" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Username</label>
                                        <input type="text" x-model="email.smtp_user" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">SMTP Password</label>
                                        <input type="password" x-model="email.smtp_pass" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">From Email</label>
                                        <input type="email" x-model="email.from_email" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">From Name</label>
                                        <input type="text" x-model="email.from_name" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Configuration</button>
                                    <button type="button" @click="testEmail" class="ml-2 bg-gray-200 px-4 py-2 rounded">Test Connection</button>
                                </div>
                            </form>
                        </div>

                        <!-- Payment Info -->
                        <div x-show="activeSection === 'payment'">
                            <h2 class="text-xl font-bold mb-4">Payment Information</h2>
                            <form @submit.prevent="savePayment">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Bank Name</label>
                                        <input type="text" x-model="payment.bank_name" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Account Holder</label>
                                        <input type="text" x-model="payment.account_holder" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Account Number</label>
                                        <input type="text" x-model="payment.account_number" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">IFSC Code</label>
                                        <input type="text" x-model="payment.ifsc_code" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">UPI ID</label>
                                        <input type="text" x-model="payment.upi_id" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Security -->
                        <div x-show="activeSection === 'security'">
                            <h2 class="text-xl font-bold mb-4">Security Settings</h2>
                            <form @submit.prevent="saveSecurity">
                                <div class="space-y-4">
                                    <div class="flex items-center">
                                        <input type="checkbox" x-model="security.two_factor" class="mr-2">
                                        <label>Enable Two-Factor Authentication for Admin</label>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-1">Session Timeout (minutes)</label>
                                        <input type="number" x-model="security.session_timeout" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Max Login Attempts</label>
                                        <input type="number" x-model="security.max_attempts" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Lockout Time (minutes)</label>
                                        <input type="number" x-model="security.lockout_time" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">IP Whitelist (comma separated)</label>
                                        <textarea x-model="security.ip_whitelist" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Rate Limiting (requests per minute)</label>
                                        <input type="number" x-model="security.rate_limit" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Security Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                <!-- Geofencing Settings Card -->
                <div class="bg-white rounded-lg shadow p-6 mt-6">
                    <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                        <i class="fas fa-map-marker-alt text-blue-600"></i> Geofencing for Attendance
                    </h3>
                    <form @submit.prevent="saveGeofence">
                        <div class="mb-4 flex items-center gap-3">
                            <input type="checkbox" id="geo_enabled" x-model="geofence.enabled" class="w-4 h-4">
                            <label for="geo_enabled" class="font-medium">Enable GPS Geofencing for Attendance</label>
                        </div>
                        <div x-show="geofence.enabled" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Office Address / Label</label>
                                <input type="text" x-model="geofence.address" class="w-full border rounded px-3 py-2" placeholder="e.g. Head Office, Rewa">
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1">Latitude</label>
                                    <input type="number" step="any" x-model="geofence.lat" class="w-full border rounded px-3 py-2" placeholder="e.g. 24.5374">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Longitude</label>
                                    <input type="number" step="any" x-model="geofence.lng" class="w-full border rounded px-3 py-2" placeholder="e.g. 81.2978">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Radius (meters)</label>
                                    <input type="number" x-model="geofence.radius" class="w-full border rounded px-3 py-2" placeholder="e.g. 500">
                                </div>
                            </div>
                            <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Tip:</strong> Use <a href="https://maps.google.com" target="_blank" class="underline">Google Maps</a> to find coordinates. Right-click on your office location → "What's here?" to get lat/lng.
                            </div>
                            <button type="button" @click="detectLocation" class="text-sm text-blue-600 underline">
                                <i class="fas fa-crosshairs mr-1"></i> Auto-detect my current location
                            </button>

                            <hr class="my-4">
                            <h4 class="font-semibold text-gray-700 mb-2">Per-User Geofence Override</h4>
                            <p class="text-sm text-gray-500 mb-3">Set a custom geofence center/radius for a specific user (overrides global setting for that user only).</p>
                            <div class="flex gap-2">
                                <select x-model="geoOverride.user_id" class="flex-1 border rounded px-3 py-2">
                                    <option value="">Select user...</option>
                                    <template x-for="u in geoUserList" :key="u.id">
                                        <option :value="u.id" x-text="u.full_name + ' (' + u.role + ')'"></option>
                                    </template>
                                </select>
                                <button type="button" @click="loadUserGeoOverride" class="px-3 py-2 bg-gray-200 rounded text-sm">Load</button>
                            </div>
                            <div x-show="geoOverride.user_id" class="grid grid-cols-3 gap-4 mt-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Latitude</label>
                                    <input type="number" step="any" x-model="geoOverride.lat" class="w-full border rounded px-2 py-1 text-sm" placeholder="Leave empty to use global">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Longitude</label>
                                    <input type="number" step="any" x-model="geoOverride.lng" class="w-full border rounded px-2 py-1 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1">Override Radius (m)</label>
                                    <input type="number" x-model="geoOverride.radius" class="w-full border rounded px-2 py-1 text-sm" placeholder="e.g. 200">
                                </div>
                            </div>
                            <div x-show="geoOverride.user_id" class="flex gap-2 mt-2">
                                <button type="button" @click="saveUserGeoOverride" class="px-4 py-2 bg-green-600 text-white rounded text-sm">Save Override</button>
                                <button type="button" @click="clearUserGeoOverride" class="px-4 py-2 bg-red-500 text-white rounded text-sm">Clear Override</button>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save Geofencing Settings</button>
                        </div>
                    </form>
                </div>
            </div>


            <!-- Add Team Member Modal -->
            <div x-show="showAddTeam" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
                    <h3 class="text-lg font-bold mb-4">Add Team Member</h3>
                    <form @submit.prevent="saveTeamMember">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Name</label>
                            <input type="text" x-model="teamForm.name" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Position</label>
                            <input type="text" x-model="teamForm.position" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Bio</label>
                            <textarea x-model="teamForm.bio" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Photo</label>
                            <input type="file" @change="uploadTeamPhoto" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Display Order</label>
                            <input type="number" x-model="teamForm.display_order" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button @click="showAddTeam = false" type="button" class="px-4 py-2 bg-gray-200 rounded">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Add Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <?php
    }

    private function renderLogin() {
        ?>
        <div class="min-h-screen flex items-center justify-center bg-gray-100">
            <div class="bg-white p-8 rounded-lg shadow-lg w-96">
                <div class="text-center mb-6">
                    <?php if (filter_var($this->settings['site_logo'] ?? '', FILTER_VALIDATE_URL)): ?>
                        <img src="<?php echo $this->settings['site_logo']; ?>" alt="Logo" class="h-16 mx-auto mb-4">
                    <?php else: ?>
                        <div class="text-4xl mb-4"><?php echo $this->settings['site_logo'] ?? '🏢'; ?></div>
                    <?php endif; ?>
                    <h1 class="text-2xl font-bold">Admin Login</h1>
                    <p class="text-sm text-gray-600"><?php echo $this->settings['site_title'] ?? 'D K Associates'; ?></p>
                </div>

                <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    Invalid credentials
                </div>
                <?php endif; ?>

                <?php if (isset($_GET['locked'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    Account locked due to multiple failed attempts. Please try again after 15 minutes.
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?action=login">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Username</label>
                        <input type="text" name="username"
                               class="w-full border rounded px-3 py-2 focus:outline-none focus:border-blue-600" required>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium mb-2">Password</label>
                        <input type="password" name="password"
                               class="w-full border rounded px-3 py-2 focus:outline-none focus:border-blue-600" required>
                    </div>

                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="remember" class="mr-2">
                            <span class="text-sm">Remember me</span>
                        </label>
                    </div>

                    <input type="hidden" name="device_id" id="deviceIdInput">
                    <button type="submit"
                            class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition">
                        Login
                    </button>
                </form>

                <div class="mt-4 text-center text-sm text-gray-600">
                    <a href="#" class="hover:text-blue-600">Forgot Password?</a>
                </div>
            </div>
        </div>


        <?php
    }

    // Helper methods
    private function getUnreadCount() {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bindValue(1, $_SESSION['admin_id']);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row['count'] ?? 0;
    }

    private function generateEmployeeCode($id) {
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($id), 3, '0', STR_PAD_LEFT);
        return 'DKA' . $year . $month . $hexCode;
    }

    private function generateWorkerCode($id) {
        $year = date('y');
        $month = strtoupper(date('M', strtotime('2026-' . date('m') . '-01')));
        $hexCode = str_pad(dechex($id), 3, '0', STR_PAD_LEFT);
        return 'DKW' . $year . $month . $hexCode;
    }

    private function getTaskCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM tasks");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getApplicationCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as pending
            FROM applications");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getEnquiryCounts() {
        $result = $this->db->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM enquiries");
        return $result->fetchArray(SQLITE3_ASSOC);
    }

    private function getWorkerCounts() {
        $result = $this->db->query("SELECT COUNT(*) as total FROM workers WHERE status = 'active'");
        return $result->fetchArray(SQLITE3_ASSOC);
    }
}

// Initialize and render admin panel
$admin = new AdminPanel();
$admin->render();
?>