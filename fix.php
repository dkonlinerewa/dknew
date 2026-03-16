<?php
// ===== fix_login.php =====
// Ultra-simple login fix script
// Access: http://yoursite.com/fix_login.php

// Turn on error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Login</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; margin: 10px 0; }
        code { background: #f4f4f4; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>🔧 Simple Login Fix</h1>";

try {
    // Connect to database
    require_once 'config.php';
    $db = new SQLite3(DB_PATH);
    
    // 1. Check if login_attempts table exists and clear it
    echo "<div class='success'>✓ Checking database...</div>";
    
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $found = false;
    while ($table = $tables->fetchArray()) {
        if ($table['name'] == 'login_attempts') {
            $found = true;
            $db->exec("DELETE FROM login_attempts");
            echo "<div class='success'>✓ Cleared login_attempts table</div>";
        }
    }
    
    if (!$found) {
        echo "<div class='success'>✓ No login_attempts table found (that's fine)</div>";
    }
    
    // 2. Reset all users' failed login counts
    $columns = $db->query("PRAGMA table_info(admin_users)");
    $has_failed = false;
    while ($col = $columns->fetchArray()) {
        if ($col['name'] == 'failed_login_count') {
            $has_failed = true;
            $db->exec("UPDATE admin_users SET failed_login_count = 0");
            echo "<div class='success'>✓ Reset failed login counts</div>";
        }
    }
    
    // 3. Delete existing admin and create new one
    echo "<div class='success'>✓ Recreating admin user...</div>";
    
    // Delete any existing admin users
    $db->exec("DELETE FROM admin_users WHERE username = 'admin'");
    
    // Create new admin with simple password
    $password = 'Pass@123';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("INSERT INTO admin_users 
        (username, password_hash, full_name, email, role, is_active) 
        VALUES (?, ?, ?, ?, ?, 1)");
    
    $stmt->bindValue(1, 'admin');
    $stmt->bindValue(2, $hash);
    $stmt->bindValue(3, 'Administrator');
    $stmt->bindValue(4, 'admin@localhost.com');
    $stmt->bindValue(5, 'admin');
    
    $stmt->execute();
    
    // 4. Verify
    $check = $db->querySingle("SELECT * FROM admin_users WHERE username = 'admin'", true);
    
    if ($check) {
        echo "<div class='success' style='font-size: 18px;'>";
        echo "<h2>✅ LOGIN FIXED!</h2>";
        echo "<p><strong>Username:</strong> admin</p>";
        echo "<p><strong>Password:</strong> Pass@123</p>";
        echo "<p><strong>User ID:</strong> " . $check['id'] . "</p>";
        echo "<p><strong>Role:</strong> " . $check['role'] . "</p>";
        echo "</div>";
        
        // Test password
        $test = password_verify('Pass@123', $check['password_hash']);
        if ($test) {
            echo "<div class='success'>✓ Password verification: OK</div>";
        } else {
            echo "<div class='error'>✗ Password verification failed</div>";
        }
    } else {
        echo "<div class='error'>✗ Failed to create admin user</div>";
    }
    
    // 5. Show all users
    echo "<h3>Current Users:</h3>";
    $users = $db->query("SELECT id, username, role, is_active FROM admin_users");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Active</th></tr>";
    while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $user['id'] . "</td>";
        echo "<td>" . $user['username'] . "</td>";
        echo "<td>" . $user['role'] . "</td>";
        echo "<td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 6. Session cleanup
    session_start();
    session_destroy();
    echo "<div class='success'>✓ Session cleared</div>";
    
    // 7. Login link
    echo "<div style='margin-top: 20px;'>";
    echo "<a href='admin.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Login Page</a>";
    echo "</div>";
    
    // 8. Delete script option
    echo "<div style='margin-top: 20px;'>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='delete' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>Delete This Script</button>";
    echo "</form>";
    echo "</div>";
    
    if (isset($_POST['delete'])) {
        if (unlink(__FILE__)) {
            echo "<script>alert('Script deleted!'); window.location.href='admin.php';</script>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}

echo "</body></html>";
?>