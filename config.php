<?php
// ===== config.php =====
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Base URL for assets/uploads
if (!defined('BASE_URL')) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    define('BASE_URL', $proto . '://' . $host . $dir);
}

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Database
define('DB_PATH', __DIR__ . '/dk_associates.db');

// Upload directories
define('UPLOAD_DIR', 'uploads/');
define('PHOTO_DIR', UPLOAD_DIR . 'photos/');
define('DOCUMENT_DIR', UPLOAD_DIR . 'documents/');
define('QR_DIR', UPLOAD_DIR . 'qr/');

// Create directories if not exist
foreach ([UPLOAD_DIR, PHOTO_DIR, DOCUMENT_DIR, QR_DIR] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Security
define('SESSION_TIMEOUT', 3600); // 1 hour
define('RATE_LIMIT', 60); // requests per minute
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// Email (configure these)
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('FROM_EMAIL', 'noreply@hidk.in');
define('FROM_NAME', 'D K Associates');

// Redis (optional for session/cache)
define('REDIS_HOST', 'localhost');
define('REDIS_PORT', 6379);

/**
 * Helper function to get the database singleton
 */
function db() {
    global $db;
    return $db;
}

// Function to log activities
function logActivity($action, $details = '') {
    $db = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $user_id = $_SESSION['admin_id'] ?? 0;
    
    $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details, ip_address, user_agent) 
                          VALUES (?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $action);
    $stmt->bindValue(3, $details);
    $stmt->bindValue(4, $ip);
    $stmt->bindValue(5, $user_agent);
    $stmt->execute();
}

// Function to send notification
function sendNotification($user_id, $type, $title, $message, $action_url = '') {
    $db = db();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, action_url) 
                          VALUES (?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $type);
    $stmt->bindValue(3, $title);
    $stmt->bindValue(4, $message);
    $stmt->bindValue(5, $action_url);
    $stmt->execute();
}

// Function to check rate limit
function checkRateLimit($key, $limit = RATE_LIMIT, $period = 60) {
    $cache_file = sys_get_temp_dir() . '/ratelimit_' . md5($key);
    $data = [];
    
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        $data = array_filter($data, function($time) use ($period) {
            return $time > time() - $period;
        });
    }
    
    if (count($data) >= $limit) {
        return false;
    }
    
    $data[] = time();
    file_put_contents($cache_file, json_encode($data));
    return true;
}

// Function to ensure database schema is up to date
function ensureDatabaseSchema($db) {
    try {
        $db->exec("BEGIN IMMEDIATE TRANSACTION");

        // Table definitions
        $tables = [
            "admin_users" => "CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT,
                email TEXT,
                full_name TEXT,
                role TEXT DEFAULT 'staff',
                department TEXT,
                team_id INTEGER,
                manager_id INTEGER,
                phone TEXT,
                blood_group TEXT,
                emergency_contact TEXT,
                employee_id TEXT UNIQUE,
                employee_code TEXT,
                photo_url TEXT,
                qr_code TEXT,
                is_active INTEGER DEFAULT 1,
                last_login DATETIME,
                two_factor_secret TEXT,
                ip_whitelist TEXT,
                remember_token TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                monthly_salary REAL DEFAULT 0,
                daily_rate REAL DEFAULT 0,
                salary_basic REAL DEFAULT 0,
                salary_allowance REAL DEFAULT 0,
                salary_deductions REAL DEFAULT 0,
                is_online INTEGER DEFAULT 0,
                device_id TEXT,
                reporting_head INTEGER DEFAULT 0,
                care_permission INTEGER DEFAULT 0,
                reporting_office TEXT,
                failed_login_count INTEGER DEFAULT 0,
                last_failed_login DATETIME,
                geo_override_lat REAL,
                geo_override_lng REAL,
                geo_override_radius INTEGER,
                supervisor INTEGER DEFAULT 0,
                tech_permission INTEGER DEFAULT 0,
                admin_permission INTEGER DEFAULT 0
            )",
            "workers" => "CREATE TABLE IF NOT EXISTS workers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                worker_id TEXT UNIQUE,
                worker_code TEXT,
                name TEXT,
                father_name TEXT,
                dob DATE,
                gender TEXT,
                phone TEXT,
                email TEXT,
                address TEXT,
                skills TEXT,
                experience TEXT,
                qualification TEXT,
                photo_url TEXT,
                id_card_url TEXT,
                qr_code TEXT,
                documents TEXT,
                status TEXT DEFAULT 'active',
                assigned_manager INTEGER,
                rating REAL DEFAULT 0,
                blood_group TEXT,
                supervisor TEXT,
                reporting_head INTEGER DEFAULT 0,
                bank_details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "tasks" => "CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                description TEXT,
                assigned_to INTEGER,
                assigned_by INTEGER,
                status TEXT DEFAULT 'pending',
                priority TEXT,
                due_date DATETIME,
                completed_at DATETIME,
                attachments TEXT,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "pending_changes" => "CREATE TABLE IF NOT EXISTS pending_changes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                record_id INTEGER NOT NULL,
                field_changes TEXT NOT NULL,
                requested_by INTEGER NOT NULL,
                status TEXT DEFAULT 'pending',
                reviewed_by INTEGER,
                reviewed_at DATETIME,
                review_note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "chat_messages" => "CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT,
                sender_type TEXT,
                sender_name TEXT,
                sender_id INTEGER DEFAULT 0,
                receiver_id INTEGER DEFAULT 0,
                receiver_type TEXT,
                message TEXT,
                attachments TEXT,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                device_id TEXT,
                chat_stage TEXT,
                chat_data TEXT,
                is_queued INTEGER DEFAULT 0,
                type TEXT DEFAULT 'guest'
            )",
            "attendance" => "CREATE TABLE IF NOT EXISTS attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                user_type TEXT,
                punch_in DATETIME,
                punch_out DATETIME,
                punch_in_location TEXT,
                punch_out_location TEXT,
                status TEXT,
                late_minutes INTEGER DEFAULT 0,
                early_leaving_minutes INTEGER DEFAULT 0,
                date DATE,
                device_id TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "leaves" => "CREATE TABLE IF NOT EXISTS leaves (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                user_type TEXT,
                leave_type TEXT,
                start_date DATE,
                end_date DATE,
                reason TEXT,
                status TEXT DEFAULT 'pending',
                approved_by INTEGER,
                approved_at DATETIME,
                approval_reason TEXT,
                documents TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "quotations" => "CREATE TABLE IF NOT EXISTS quotations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                quote_number TEXT UNIQUE,
                customer_name TEXT,
                customer_email TEXT,
                customer_phone TEXT,
                items TEXT,
                subtotal REAL,
                tax REAL,
                total REAL,
                status TEXT DEFAULT 'draft',
                created_by INTEGER,
                assigned_to INTEGER,
                valid_until DATE,
                terms TEXT,
                notes TEXT,
                pdf_url TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "enquiries" => "CREATE TABLE IF NOT EXISTS enquiries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                phone TEXT,
                service_type TEXT,
                description TEXT,
                status TEXT DEFAULT 'pending',
                assigned_to INTEGER,
                converted_to_quote INTEGER,
                response_time INTEGER,
                communication_log TEXT,
                action_status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "events" => "CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT,
                event_type TEXT,
                start_date DATE NOT NULL,
                end_date DATE,
                target_type TEXT,
                target_ids TEXT,
                is_approved INTEGER DEFAULT 1,
                approved_by INTEGER,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "site_settings" => "CREATE TABLE IF NOT EXISTS site_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT,
                setting_type TEXT
            )",
            "notifications" => "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                type TEXT,
                title TEXT,
                message TEXT,
                is_read INTEGER DEFAULT 0,
                action_url TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "applications" => "CREATE TABLE IF NOT EXISTS applications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                applicant_name TEXT,
                applicant_email TEXT,
                applicant_phone TEXT,
                position TEXT,
                resume_url TEXT,
                status TEXT DEFAULT 'new',
                screening_score INTEGER,
                interview_date DATETIME,
                interview_feedback TEXT,
                offer_sent INTEGER DEFAULT 0,
                onboarding_status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "salary_records" => "CREATE TABLE IF NOT EXISTS salary_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                month TEXT NOT NULL,
                attended_days INTEGER DEFAULT 0,
                total_offs INTEGER DEFAULT 0,
                base_salary REAL DEFAULT 0,
                bonus REAL DEFAULT 0,
                deductions REAL DEFAULT 0,
                final_salary REAL DEFAULT 0,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, month)
            )",
            "activity_log" => "CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT,
                details TEXT,
                ip_address TEXT,
                user_agent TEXT,
                device_id TEXT,
                success INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "login_attempts" => "CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                ip_address TEXT,
                device_id TEXT,
                attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                success INTEGER DEFAULT 0
            )",
            "profile_update_requests" => "CREATE TABLE IF NOT EXISTS profile_update_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                request_type TEXT,
                old_data TEXT,
                new_data TEXT,
                status TEXT DEFAULT 'pending',
                approved_by INTEGER,
                approved_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "expense_requests" => "CREATE TABLE IF NOT EXISTS expense_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                amount REAL,
                category TEXT,
                description TEXT,
                receipt_url TEXT,
                status TEXT DEFAULT 'pending',
                approved_by INTEGER,
                approved_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "document_requests" => "CREATE TABLE IF NOT EXISTS document_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                document_type TEXT,
                reason TEXT,
                status TEXT DEFAULT 'pending',
                document_url TEXT,
                approved_by INTEGER,
                approved_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "templates" => "CREATE TABLE IF NOT EXISTS templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_type TEXT,
                name TEXT,
                content TEXT,
                css TEXT,
                is_default INTEGER DEFAULT 0,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "directory_contacts" => "CREATE TABLE IF NOT EXISTS directory_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                profession TEXT,
                contact_number TEXT,
                locality TEXT,
                notes TEXT,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "sticky_notes" => "CREATE TABLE IF NOT EXISTS sticky_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                content TEXT,
                color TEXT DEFAULT 'yellow',
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "open_positions" => "CREATE TABLE IF NOT EXISTS open_positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                location TEXT,
                type TEXT,
                salary TEXT,
                urgent INTEGER DEFAULT 0,
                description TEXT,
                requirements TEXT,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "chat_sessions" => "CREATE TABLE IF NOT EXISTS chat_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT UNIQUE,
                guest_name TEXT,
                guest_email TEXT,
                guest_phone TEXT,
                contact_reason TEXT,
                device_id TEXT,
                status TEXT DEFAULT 'active',
                assigned_to INTEGER DEFAULT 0,
                last_activity DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "chat_queue" => "CREATE TABLE IF NOT EXISTS chat_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT,
                device_id TEXT,
                agent_id INTEGER DEFAULT 0,
                status TEXT DEFAULT 'waiting',
                message TEXT,
                chat_data TEXT,
                chat_stage TEXT,
                assigned_to INTEGER DEFAULT 0,
                assigned_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "daily_reports" => "CREATE TABLE IF NOT EXISTS daily_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                report_date DATE NOT NULL,
                content TEXT NOT NULL,
                tasks_completed TEXT,
                blockers TEXT,
                mood INTEGER DEFAULT 3,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, report_date)
            )",
            "team_members" => "CREATE TABLE IF NOT EXISTS team_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                position TEXT,
                bio TEXT,
                photo_url TEXT,
                display_order INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($tables as $name => $sql) {
            $db->exec($sql);
        }

        // Indexes
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to, status)",
            "CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(date, user_id)",
            "CREATE INDEX IF NOT EXISTS idx_chat_receiver ON chat_messages(receiver_id, is_read)",
            "CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read)",
            "CREATE INDEX IF NOT EXISTS idx_leaves_dates ON leaves(start_date, end_date, status)",
            "CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(ip_address, device_id)",
            "CREATE INDEX IF NOT EXISTS idx_activity_log ON activity_log(user_id, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_chat_sessions ON chat_sessions(device_id, status)"
        ];
        foreach ($indexes as $sql) {
            $db->exec($sql);
        }

        // Column Migrations
        $columnMigrations = [
            "admin_users" => [
                'is_online' => 'INTEGER DEFAULT 0',
                'last_activity' => 'DATETIME',
                'device_id' => 'TEXT',
                'monthly_salary' => 'REAL DEFAULT 0',
                'daily_rate' => 'REAL DEFAULT 0',
                'salary_basic' => 'REAL DEFAULT 0',
                'salary_allowance' => 'REAL DEFAULT 0',
                'salary_deductions' => 'REAL DEFAULT 0',
                'bank_name' => 'TEXT',
                'account_number' => 'TEXT',
                'ifsc_code' => 'TEXT',
                'upi_id' => 'TEXT',
                'offer_letter' => 'TEXT',
                'joining_letter' => 'TEXT',
                'id_proof' => 'TEXT',
                'banking_details' => 'TEXT',
                'geo_override_lat'    => 'REAL',
                'geo_override_lng'    => 'REAL',
                'geo_override_radius' => 'INTEGER',
                'reporting_head'      => 'INTEGER DEFAULT 0',
                'care_permission'     => 'INTEGER DEFAULT 0',
                'tech_permission'     => 'INTEGER DEFAULT 0',
                'admin_permission'    => 'INTEGER DEFAULT 0',
                'supervisor'          => 'INTEGER DEFAULT 0',
                'reporting_office'    => 'TEXT',
                'failed_login_count'  => 'INTEGER DEFAULT 0',
                'last_failed_login'   => 'DATETIME',
                'employee_code'       => 'TEXT',
                'worker_code'         => 'TEXT',
                'blood_group'         => 'TEXT',
                'remember_token'      => 'TEXT'
            ],
            "workers" => [
                'worker_code' => 'TEXT',
                'reporting_head' => 'INTEGER DEFAULT 0',
                'blood_group' => 'TEXT',
                'supervisor' => 'TEXT',
                'id_card_url' => 'TEXT',
                'bank_details' => 'TEXT'
            ],
            "chat_messages" => [
                'device_id' => 'TEXT',
                'chat_stage' => 'TEXT',
                'chat_data' => 'TEXT',
                'is_queued' => 'INTEGER DEFAULT 0',
                'session_id' => 'TEXT',
                'sender_type' => 'TEXT',
                'sender_name' => 'TEXT',
                'sender_id' => 'INTEGER DEFAULT 0',
                'receiver_id' => 'INTEGER DEFAULT 0',
                'receiver_type' => 'TEXT',
                'attachments' => 'TEXT',
                'type' => "TEXT DEFAULT 'guest'"
            ],
            "site_settings" => [
                'setting_type' => 'TEXT'
            ]
        ];

        foreach ($columnMigrations as $table => $cols_to_add) {
            $res = $db->query("PRAGMA table_info($table)");
            $existing_cols = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $existing_cols[] = $row['name']; }
            foreach ($cols_to_add as $col => $type) {
                if (!in_array($col, $existing_cols)) {
                    $db->exec("ALTER TABLE $table ADD COLUMN $col $type");
                }
            }
        }

        // Default settings
        $existing = $db->querySingle("SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE 'geofence%'");
        if ($existing == 0) {
            $defaults = [
                ['geofence_enabled', '0', 'boolean'],
                ['geofence_lat',     '24.5374', 'text'],
                ['geofence_lng',     '81.2978', 'text'],
                ['geofence_radius',  '500', 'number'],
                ['geofence_address', 'Head Office, Rewa, MP', 'text'],
            ];
            foreach ($defaults as $d) {
                $db->exec("INSERT OR IGNORE INTO site_settings (setting_key, setting_value, setting_type) VALUES ('{$d[0]}', '{$d[1]}', '{$d[2]}')");
            }
        }

        $db->exec("COMMIT TRANSACTION");
    } catch (Exception $e) {
        if (isset($db)) $db->exec("ROLLBACK TRANSACTION");
    }
}

// Initialize Database Connection
try {
    $db = new \SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec("PRAGMA journal_mode = WAL");
    ensureDatabaseSchema($db);
} catch (Exception $e) {
    die("Fatal Error: Could not connect to SQLite database. " . $e->getMessage());
}
