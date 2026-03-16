<?php
// ===== api/export.php =====
require_once '../config.php';
require_once '../vendor/autoload.php';

use Fpdf\Fpdf;

if (!isset($_SESSION['admin_id'])) {
    die('Unauthorized');
}

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'pdf';
$id = $_GET['id'] ?? 0;

$db = db();

switch ($type) {
    case 'id_card':
        exportIDCard($db, $id, $format);
        break;
    case 'worker_id':
        exportWorkerID($db, $id, $format);
        break;
    case 'quotation':
        exportQuotation($db, $id, $format);
        break;
    case 'report':
        exportReport($db, $format);
        break;
}

function exportIDCard($db, $user_id, $format) {
    $user = $db->querySingle("SELECT * FROM admin_users WHERE id = $user_id", true);
    
    if ($format === 'pdf') {
        
        $pdf = new Fpdf('L', 'mm', '86x54'); // Credit card size
        $pdf->AddPage();
        
        // Design ID card
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');
        
        if ($user['photo_url']) {
            $pdf->Image('..' . $user['photo_url'], 10, 15, 20, 20);
        }
        
        $pdf->SetXY(35, 15);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, $user['full_name'], 0, 1);
        
        $pdf->SetX(35);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, ucfirst($user['role']), 0, 1);
        
        $pdf->SetX(35);
        $pdf->Cell(0, 4, 'ID: ' . $user['employee_id'], 0, 1);
        
        // Add QR code
        if ($user['qr_code'] && file_exists('..' . $user['qr_code'])) {
            $pdf->Image('..' . $user['qr_code'], 60, 35, 15, 15);
        }
        
        $pdf->Output('D', 'ID_Card_' . $user['employee_id'] . '.pdf');
    }
}

function exportWorkerID($db, $worker_id, $format) {
    $worker = $db->querySingle("SELECT * FROM workers WHERE id = $worker_id", true);
    
    if ($format === 'pdf') {
        
        $pdf = new Fpdf('L', 'mm', '86x54');
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, 'Worker ID Card', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, $worker['name'], 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 4, 'ID: ' . $worker['worker_id'], 0, 1, 'C');
        
        // Add QR code if available
        if (!empty($worker['qr_code']) && file_exists('..' . $worker['qr_code'])) {
            $pdf->Image('..' . $worker['qr_code'], 35, 25, 20, 20);
        }
        
        $pdf->Output('D', 'Worker_ID_' . $worker['worker_id'] . '.pdf');
    }
}

function exportQuotation($db, $id, $format) {
    $quotation = $db->querySingle("SELECT * FROM quotations WHERE id = $id", true);

    if (!$quotation) {
        die('Quotation not found.');
    }

    if ($format === 'pdf') {

        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();

        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Quotation', 0, 1, 'C');
        $pdf->Ln(5);

        // Quotation details
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Quotation No:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['quotation_number'] ?? $id, 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Date:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['created_at'] ?? date('Y-m-d'), 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 7, 'Client:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, $quotation['client_name'] ?? 'N/A', 0, 1);
        $pdf->Ln(5);

        // Items table header
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(90, 8, 'Description', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Unit Price', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Total', 1, 1, 'C', true);

        // Items rows
        $items = json_decode($quotation['items'] ?? '[]', true);
        $pdf->SetFont('Arial', '', 9);
        $grand_total = 0;
        foreach ((array)$items as $item) {
            $line_total = ($item['qty'] ?? 1) * ($item['unit_price'] ?? 0);
            $grand_total += $line_total;
            $pdf->Cell(90, 7, $item['description'] ?? '', 1, 0);
            $pdf->Cell(30, 7, $item['qty'] ?? 1, 1, 0, 'C');
            $pdf->Cell(35, 7, number_format($item['unit_price'] ?? 0, 2), 1, 0, 'R');
            $pdf->Cell(35, 7, number_format($line_total, 2), 1, 1, 'R');
        }

        // Grand total
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(155, 8, 'Grand Total', 1, 0, 'R');
        $pdf->Cell(35, 8, number_format($grand_total, 2), 1, 1, 'R');

        $pdf->Output('D', 'Quotation_' . ($quotation['quotation_number'] ?? $id) . '.pdf');
    }
}

function exportReport($db, $format) {
    if ($format === 'pdf') {

        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();

        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'D K Associates', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Summary Report', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
        $pdf->Ln(5);

        // Workers summary
        $worker_count = $db->querySingle("SELECT COUNT(*) FROM workers") ?? 0;
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, 'Workers', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, 'Total Workers: ' . $worker_count, 0, 1);
        $pdf->Ln(3);

        // Users summary
        $user_count = $db->querySingle("SELECT COUNT(*) FROM admin_users") ?? 0;
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, 'Users', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, 'Total Users: ' . $user_count, 0, 1);

        $pdf->Output('D', 'Report_' . date('Ymd') . '.pdf');
    }
}