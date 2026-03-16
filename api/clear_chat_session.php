<?php
// ===== api/clear_chat_session.php =====
require_once '../config.php';

session_start();
unset($_SESSION['chat_session_id']);
echo json_encode(['success' => true]);
?>