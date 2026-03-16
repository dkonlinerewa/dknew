<?php
// ===== api/login.php =====
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);
$device_id = $_POST['device_id'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
$db = db();

// Check failed attempts
$stmt = $db->prepare("SELECT COUNT(*) as attempts FROM login_attempts 
                      WHERE (ip_address = ? OR device_id = ?) 
                      AND attempt_time > datetime('now', '-15 minutes') 
                      AND success = 0");
$stmt->bindValue(1, $ip);
$stmt->bindValue(2, $device_id);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$failed_attempts = $row['attempts'] ?? 0;

// Check if blocked (3 attempts = 15 min, 5 attempts = 12 hours)
if ($failed_attempts >= 5) {
    // Check if 12 hours have passed
    $stmt = $db->prepare("SELECT attempt_time FROM login_attempts 
                          WHERE (ip_address = ? OR device_id = ?) 
                          AND success = 0 
                          ORDER BY attempt_time DESC LIMIT 1");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $device_id);
    $result = $stmt->execute();
    $last_attempt = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($last_attempt && strtotime($last_attempt['attempt_time']) > time() - 43200) { // 12 hours
        http_response_code(429);
        echo json_encode(['error' => 'Account locked due to multiple failed attempts. Please try again after 12 hours.']);
        exit;
    }
} elseif ($failed_attempts >= 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many login attempts. Please try again after 15 minutes.']);
    exit;
}

// Get user
$stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
$stmt->bindValue(1, $username);
$result = $stmt->execute();
$user = $result->fetchArray(SQLITE3_ASSOC);

$login_success = 0;

if ($user && password_verify($password, $user['password_hash'])) {
    $login_success = 1;
    
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    
    if ($remember) {
        // Set persistent cookie (30 days)
        $token = bin2hex(random_bytes(32));
        setcookie('remember_token', $token, time() + 2592000, '/', '', true, true);
        
        // Store token in database
        $stmt = $db->prepare("UPDATE admin_users SET remember_token = ? WHERE id = ?");
        $stmt->bindValue(1, password_hash($token, PASSWORD_DEFAULT));
        $stmt->bindValue(2, $user['id']);
        $stmt->execute();
    }
    
    // Update last login
    $stmt = $db->prepare("UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->bindValue(1, $user['id']);
    $stmt->execute();
    
    logActivity('login', 'User logged in');
    
    // Clear failed attempts
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR device_id = ?");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $device_id);
    $stmt->execute();
    
} else {
    logActivity('failed_login', "Failed login attempt for username: $username");
}

// Log attempt
$stmt = $db->prepare("INSERT INTO login_attempts (username, ip_address, device_id, success) VALUES (?, ?, ?, ?)");
$stmt->bindValue(1, $username);
$stmt->bindValue(2, $ip);
$stmt->bindValue(3, $device_id);
$stmt->bindValue(4, $login_success);
$stmt->execute();

if ($login_success) {
    header('Location: ../admin.php?tab=dashboard');
    exit;
} else {
    header('Location: ../admin.php?page=login&error=1');
    exit;
}
?>