<?php
// ===== api/approvals.php =====
require_once '../config.php';

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
?>