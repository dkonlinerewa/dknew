<?php
// ===== api/data_manage.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

// Restricted to Super Admin (ID 1)
if ($_SESSION['admin_id'] != 1) {
    http_response_code(403);
    die(json_encode(['error' => 'Super Admin only']));
}

$db = db();

$action = $_GET['action'] ?? '';
$table = $_GET['table'] ?? '';

$allowedTables = ['workers', 'admin_users', 'applications', 'enquiries', 'attendance', 'salary_records', 'tasks'];

if (!in_array($table, $allowedTables)) {
    die(json_encode(['error' => 'Invalid table']));
}

if ($action === 'export_csv') {
    $results = $db->query("SELECT * FROM $table");
    $filename = $table . "_" . date('Y-m-d_H-i-s') . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Header
    $info = $db->query("PRAGMA table_info($table)");
    $headers = [];
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $headers[] = $row['name'];
    }
    fputcsv($output, $headers);

    // Data
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    return;

} elseif ($action === 'export_vcf') {
    if (!in_array($table, ['workers', 'admin_users'])) {
        die(json_encode(['error' => 'VCF only supported for people tables']));
    }

    $results = $db->query("SELECT * FROM $table");
    $filename = $table . "_" . date('Y-m-d') . ".vcf";

    header('Content-Type: text/vcard');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        echo "BEGIN:VCARD\n";
        echo "VERSION:3.0\n";
        $name = $row['name'] ?? $row['full_name'] ?? 'Unknown';
        echo "FN:$name\n";
        if (isset($row['phone'])) echo "TEL;TYPE=CELL:" . $row['phone'] . "\n";
        if (isset($row['email'])) echo "EMAIL;TYPE=INTERNET:" . $row['email'] . "\n";
        if (isset($row['address'])) echo "ADR;TYPE=HOME:;;" . str_replace("\n", " ", $row['address']) . "\n";
        echo "END:VCARD\n";
    }
    return;

} elseif ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        die(json_encode(['error' => 'No file uploaded']));
    }

    $tmpName = $_FILES['file']['tmp_name'];
    $handle = fopen($tmpName, "r");
    $headers = fgetcsv($handle);

    if (!$headers) {
        die(json_encode(['error' => 'Invalid CSV']));
    }

    $count = 0;
    while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($headers, $row);

        // Build INSERT query
        $cols = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));

        $stmt = $db->prepare("INSERT OR REPLACE INTO $table ($cols) VALUES ($placeholders)");
        $i = 1;
        foreach ($data as $val) {
            $stmt->bindValue($i++, $val);
        }
        $stmt->execute();
        $count++;
    }

    fclose($handle);
    echo json_encode(['success' => true, 'count' => $count]);
    return;
}
