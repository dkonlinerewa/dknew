<?php
// ===== api/users.php =====
require_once '../config.php';

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
?>