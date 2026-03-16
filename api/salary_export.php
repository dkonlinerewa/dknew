<?php
// ===== api/salary_export.php =====
require_once '../config.php';

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
?>
