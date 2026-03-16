<?php
// ===== api/templates.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        // Get single template
        $stmt = $db->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->bindValue(1, $_GET['id']);
        $result = $stmt->execute();
        $template = $result->fetchArray(SQLITE3_ASSOC);
        echo json_encode($template);
    } else {
        // Get templates by type
        $type = $_GET['type'] ?? 'staff_id';
        $stmt = $db->prepare("SELECT * FROM templates WHERE template_type = ? ORDER BY is_default DESC, name ASC");
        $stmt->bindValue(1, $type);
        $result = $stmt->execute();
        
        $html = '';
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $default_badge = $row['is_default'] ? '<span class="ml-2 bg-green-100 text-green-800 text-xs px-2 py-1 rounded">Default</span>' : '';
            $html .= "<div class='border rounded p-3 mb-2 flex justify-between items-center'>";
            $html .= "<div>";
            $html .= "<h5 class='font-bold'>{$row['name']} {$default_badge}</h5>";
            $html .= "<p class='text-xs text-gray-500'>Created: " . date('d/m/Y', strtotime($row['created_at'])) . "</p>";
            $html .= "</div>";
            $html .= "<div class='flex space-x-2'>";
            $html .= "<button onclick='editTemplate({$row['id']})' class='text-blue-600'><i class='fas fa-edit'></i></button>";
            $html .= "<button onclick='previewTemplate()' class='text-green-600'><i class='fas fa-eye'></i></button>";
            $html .= "<button onclick='deleteTemplate({$row['id']})' class='text-red-600'><i class='fas fa-trash'></i></button>";
            $html .= "</div>";
            $html .= "</div>";
        }
        
        if (empty($html)) {
            $html = "<p class='text-gray-500 text-center py-4'>No templates found. Create your first template.</p>";
        }
        
        echo $html;
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['id'])) {
        // Update existing template
        if (isset($data['is_default']) && $data['is_default']) {
            // Clear default flag for this type
            $stmt = $db->prepare("UPDATE templates SET is_default = 0 WHERE template_type = ?");
            $stmt->bindValue(1, $data['type']);
            $stmt->execute();
        }
        
        $stmt = $db->prepare("UPDATE templates SET name = ?, content = ?, css = ?, is_default = ? WHERE id = ?");
        $stmt->bindValue(1, $data['name']);
        $stmt->bindValue(2, $data['content']);
        $stmt->bindValue(3, $data['css'] ?? '');
        $stmt->bindValue(4, $data['is_default'] ? 1 : 0);
        $stmt->bindValue(5, $data['id']);
        $stmt->execute();
    } else {
        // Create new template
        if (isset($data['is_default']) && $data['is_default']) {
            // Clear default flag for this type
            $stmt = $db->prepare("UPDATE templates SET is_default = 0 WHERE template_type = ?");
            $stmt->bindValue(1, $data['type']);
            $stmt->execute();
        }
        
        $stmt = $db->prepare("INSERT INTO templates (template_type, name, content, css, is_default, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $data['type']);
        $stmt->bindValue(2, $data['name']);
        $stmt->bindValue(3, $data['content']);
        $stmt->bindValue(4, $data['css'] ?? '');
        $stmt->bindValue(5, $data['is_default'] ? 1 : 0);
        $stmt->bindValue(6, $user_id);
        $stmt->execute();
    }
    
    logActivity('template_saved', "Saved template: {$data['name']}");
    
    echo json_encode(['success' => true]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $db->prepare("DELETE FROM templates WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}
?>