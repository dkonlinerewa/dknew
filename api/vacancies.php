<?php
// ===== api/vacancies.php =====
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$role    = $_SESSION['admin_role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $show_all = isset($_GET['all']) && ($role === 'admin' || $role === 'manager');
    $where    = $show_all ? '' : 'WHERE is_active = 1';
    $result   = $db->query("SELECT * FROM open_positions $where ORDER BY urgent DESC, created_at DESC");
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    echo json_encode($rows);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if (isset($data['id']) && $data['id']) {
        $stmt = $db->prepare("UPDATE open_positions SET title=?, location=?, type=?, salary=?, description=?, requirements=?, urgent=?, is_active=? WHERE id=?");
        $stmt->bindValue(1, $data['title'] ?? '');
        $stmt->bindValue(2, $data['location'] ?? '');
        $stmt->bindValue(3, $data['type'] ?? '');
        $stmt->bindValue(4, $data['salary'] ?? '');
        $stmt->bindValue(5, $data['description'] ?? '');
        $stmt->bindValue(6, $data['requirements'] ?? '');
        $stmt->bindValue(7, ($data['urgent'] ?? false) ? 1 : 0);
        $stmt->bindValue(8, ($data['is_active'] ?? 1) ? 1 : 0);
        $stmt->bindValue(9, intval($data['id']));
        $stmt->execute();
        logActivity('vacancy_updated', "Updated vacancy: " . ($data['title'] ?? ''));
    } else {
        $stmt = $db->prepare("INSERT INTO open_positions (title, location, type, salary, description, requirements, urgent) VALUES (?,?,?,?,?,?,?)");
        $stmt->bindValue(1, $data['title'] ?? '');
        $stmt->bindValue(2, $data['location'] ?? '');
        $stmt->bindValue(3, $data['type'] ?? '');
        $stmt->bindValue(4, $data['salary'] ?? '');
        $stmt->bindValue(5, $data['description'] ?? '');
        $stmt->bindValue(6, $data['requirements'] ?? '');
        $stmt->bindValue(7, ($data['urgent'] ?? false) ? 1 : 0);
        $stmt->execute();
        logActivity('vacancy_created', "Created vacancy: " . ($data['title'] ?? ''));
    }
    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array($role, ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $id = intval($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("UPDATE open_positions SET is_active = 0 WHERE id = ?");
        $stmt->bindValue(1, $id);
        $stmt->execute();
        logActivity('vacancy_deleted', "Deactivated vacancy ID: $id");
    }
    echo json_encode(['success' => true]);
}