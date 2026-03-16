<?php
// ===== api/analytics.php =====
require_once '../config.php';

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