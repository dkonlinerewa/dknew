<?php
// ===== api/calendar.php =====
require_once '../config.php';

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
?>
