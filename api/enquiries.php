<?php
// ===== api/enquiries.php =====
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