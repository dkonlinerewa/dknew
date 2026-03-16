<?php
// ===== api/notes.php =====
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

// Ensure sticky_notes table exists
$db->exec("CREATE TABLE IF NOT EXISTS sticky_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    color TEXT DEFAULT '#FFF9C4',
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Admin sees everyone's notes; others see only their own
    if ($role === 'admin' || ($db->querySingle("SELECT admin_permission FROM admin_users WHERE id = $user_id") == 1)) {
        $result = $db->query("SELECT sn.*, u.full_name as author FROM sticky_notes sn LEFT JOIN admin_users u ON sn.user_id = u.id WHERE sn.is_active = 1 ORDER BY sn.created_at DESC");
    } else {
        $stmt = $db->prepare("SELECT sn.*, u.full_name as author FROM sticky_notes sn LEFT JOIN admin_users u ON sn.user_id = u.id WHERE sn.user_id = ? AND sn.is_active = 1 ORDER BY sn.created_at DESC");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
    }
    $notes = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notes[] = $row;
    }
    echo json_encode($notes);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = trim($data['note'] ?? $data['content'] ?? '');
    $color   = $data['color'] ?? '#FFF9C4';

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Note content required']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO sticky_notes (user_id, content, color) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $content);
    $stmt->bindValue(3, htmlspecialchars($color, ENT_QUOTES, 'UTF-8'));
    $stmt->execute();
    $id = $db->lastInsertRowID();

    echo json_encode(['success' => true, 'id' => $id]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = intval($data['id'] ?? $_GET['id'] ?? 0);
    if ($id) {
        // Own note or admin can delete
        if ($role === 'admin') {
            $db->exec("UPDATE sticky_notes SET is_active = 0 WHERE id = $id");
        } else {
            $stmt = $db->prepare("UPDATE sticky_notes SET is_active = 0 WHERE id = ? AND user_id = ?");
            $stmt->bindValue(1, $id);
            $stmt->bindValue(2, $user_id);
            $stmt->execute();
        }
    }
    echo json_encode(['success' => true]);
}