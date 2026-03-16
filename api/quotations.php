<?php
// ===== api/quotations.php =====
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
    $result = $db->query("SELECT q.*, u.full_name as created_by_name 
                          FROM quotations q 
                          LEFT JOIN admin_users u ON q.created_by = u.id 
                          ORDER BY q.created_at DESC");
    
    $html = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $status_colors = [
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'accepted' => 'bg-green-100 text-green-800',
            'rejected' => 'bg-red-100 text-red-800',
            'expired' => 'bg-yellow-100 text-yellow-800'
        ];
        $status_class = $status_colors[$row['status']] ?? 'bg-gray-100 text-gray-800';
        
        $html .= "<tr class='border-b'>";
        $html .= "<td class='py-2'>{$row['quote_number']}</td>";
        $html .= "<td class='py-2'>{$row['customer_name']}</td>";
        $html .= "<td class='py-2'>₹" . number_format($row['total']) . "</td>";
        $html .= "<td class='py-2'><span class='px-2 py-1 rounded-full text-xs $status_class'>" . ucfirst($row['status']) . "</span></td>";
        $html .= "<td class='py-2'>{$row['valid_until']}</td>";
        $html .= "<td class='py-2'>
                    <button onclick='viewQuote({$row['id']})' class='text-blue-600 mr-2'><i class='fas fa-eye'></i></button>
                    <button onclick='downloadQuote({$row['id']})' class='text-green-600 mr-2'><i class='fas fa-download'></i></button>
                    <button onclick='duplicateQuote({$row['id']})' class='text-yellow-600'><i class='fas fa-copy'></i></button>
                  </td>";
        $html .= "</tr>";
    }
    
    echo $html;
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Generate quote number
    $year = date('Y');
    $month = date('m');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM quotations WHERE strftime('%Y-%m', created_at) = ?");
    $stmt->bindValue(1, "$year-$month");
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $seq = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
    $quote_number = "Q$year$month$seq";
    
    // Calculate totals
    $subtotal = 0;
    foreach ($data['items'] as $item) {
        $subtotal += $item['quantity'] * $item['unit_price'];
    }
    $tax = $subtotal * 0.18;
    $total = $subtotal + $tax;
    
    $stmt = $db->prepare("INSERT INTO quotations 
        (quote_number, customer_name, customer_email, customer_phone, items, subtotal, tax, total, status, created_by, valid_until, terms) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bindValue(1, $quote_number);
    $stmt->bindValue(2, $data['customer_name']);
    $stmt->bindValue(3, $data['customer_email'] ?? '');
    $stmt->bindValue(4, $data['customer_phone'] ?? '');
    $stmt->bindValue(5, json_encode($data['items']));
    $stmt->bindValue(6, $subtotal);
    $stmt->bindValue(7, $tax);
    $stmt->bindValue(8, $total);
    $stmt->bindValue(9, 'draft');
    $stmt->bindValue(10, $user_id);
    $stmt->bindValue(11, $data['valid_until'] ?? '');
    $stmt->bindValue(12, $data['terms'] ?? '');
    $stmt->execute();
    
    $quote_id = $db->lastInsertRowID();
    
    logActivity('quote_created', "Created quotation: $quote_number");
    
    echo json_encode(['success' => true, 'id' => $quote_id, 'number' => $quote_number]);
}
 elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $db->prepare("DELETE FROM quotations WHERE id = ?");
    $stmt->bindValue(1, $id);
    $stmt->execute();
    
    logActivity('quote_deleted', "Deleted quotation ID: $id");
    echo json_encode(['success' => true]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID is required']);
        exit;
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($data['items'] as $item) {
        $subtotal += $item['quantity'] * $item['unit_price'];
    }
    $tax = $subtotal * 0.18;
    $total = $subtotal + $tax;

    $stmt = $db->prepare("UPDATE quotations SET customer_name = ?, customer_email = ?, customer_phone = ?, items = ?, subtotal = ?, tax = ?, total = ?, status = ?, valid_until = ?, terms = ? WHERE id = ?");
    $stmt->bindValue(1, $data['customer_name']);
    $stmt->bindValue(2, $data['customer_email'] ?? '');
    $stmt->bindValue(3, $data['customer_phone'] ?? '');
    $stmt->bindValue(4, json_encode($data['items']));
    $stmt->bindValue(5, $subtotal);
    $stmt->bindValue(6, $tax);
    $stmt->bindValue(7, $total);
    $stmt->bindValue(8, $data['status'] ?? 'draft');
    $stmt->bindValue(9, $data['valid_until'] ?? '');
    $stmt->bindValue(10, $data['terms'] ?? '');
    $stmt->bindValue(11, $data['id']);

    if ($stmt->execute()) {
        logActivity('quote_updated', "Updated quotation ID: " . $data['id']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
    }

} elseif (isset($_GET['duplicate'])) {
    $id = $_GET['duplicate'];
    // Get original quote
    $original = $db->querySingle("SELECT * FROM quotations WHERE id = $id", true);
    if ($original) {
        // Generate new quote number
        $year = date('Y');
        $month = date('m');
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM quotations WHERE strftime('%Y-%m', created_at) = ?");
        $stmt->bindValue(1, "$year-$month");
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $seq = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
        $quote_number = "Q$year$month$seq";
        // Insert duplicate
        $stmt = $db->prepare("INSERT INTO quotations 
            (quote_number, customer_name, customer_email, customer_phone, items, subtotal, tax, total, status, created_by, valid_until, terms) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)");
        $stmt->bindValue(1, $quote_number);
        $stmt->bindValue(2, $original['customer_name']);
        $stmt->bindValue(3, $original['customer_email']);
        $stmt->bindValue(4, $original['customer_phone']);
        $stmt->bindValue(5, $original['items']);
        $stmt->bindValue(6, $original['subtotal']);
        $stmt->bindValue(7, $original['tax']);
        $stmt->bindValue(8, $original['total']);
        $stmt->bindValue(9, $user_id);
        $stmt->bindValue(10, $original['valid_until']);
        $stmt->bindValue(11, $original['terms']);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Quote not found']);
    }
    exit;
}
?>