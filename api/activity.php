<?php
// ===== api/activity.php =====
require_once '../config.php';

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