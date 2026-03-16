<?php
// ===== api/tasks.php =====
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