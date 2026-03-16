<?php
// ===== api/salary.php =====
require_once '../config.php';

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
?>
