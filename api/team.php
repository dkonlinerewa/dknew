<?php
// ===== api/team.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query("SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order ASC");
    $members = [];
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $members[] = $row;
    }
    
    echo json_encode($members);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO team_members (name, position, bio, photo_url, display_order, is_active) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $data['name']);
    $stmt->bindValue(2, $data['position']);
    $stmt->bindValue(3, $data['bio'] ?? '');
    $stmt->bindValue(4, $data['photo_url'] ?? '');
    $stmt->bindValue(5, $data['display_order'] ?? 0);
    $stmt->bindValue(6, $data['is_active'] ?? 1);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    $stmt = $db->prepare("UPDATE team_members SET name = ?, position = ?, bio = ?, photo_url = ?, display_order = ?, is_active = ? WHERE id = ?");
    $stmt->bindValue(1, $data['name']);
    $stmt->bindValue(2, $data['position']);
    $stmt->bindValue(3, $data['bio'] ?? '');
    $stmt->bindValue(4, $data['photo_url'] ?? '');
    $stmt->bindValue(5, $data['display_order'] ?? 0);
    $stmt->bindValue(6, $data['is_active'] ?? 1);
    $stmt->bindValue(7, $data['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $db->prepare("DELETE FROM team_members WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}
?>