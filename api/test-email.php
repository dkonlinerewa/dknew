<?php
require_once '../config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    die();
}

$data = json_decode(file_get_contents('php://input'), true);
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $data['smtp_host'] ?? '';
    $mail->SMTPAuth = true;
    $mail->Username = $data['smtp_user'] ?? '';
    $mail->Password = $data['smtp_pass'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $data['smtp_port'] ?? 587;
    $mail->setFrom($data['from_email'] ?? '', 'Test');
    $mail->addAddress($data['from_email'] ?? '');
    $mail->isHTML(true);
    $mail->Subject = 'Test Email';
    $mail->Body = 'Test';
    $mail->send();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $mail->ErrorInfo]);
}
