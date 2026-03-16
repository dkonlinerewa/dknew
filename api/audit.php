<?php
// ===== api/audit.php =====
require_once '../config.php';

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
?>