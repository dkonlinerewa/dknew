<?php
if (!file_exists('config.php')) {
    die('Configuration file not found.');
}
require_once 'config.php';
if (!defined('DB_PATH')) {
    die('Database configuration error.');
}
$dataDir = dirname(DB_PATH);
if (!file_exists($dataDir)) {
    mkdir($dataDir, 0755, true);
}
try {
    $db = new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

$is_staff_online = false;
try {
    if (isset($db)) {
        $online_check = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE last_active > datetime('now', '-5 minutes') AND (role = 'admin' OR care_permission = 1)");
        if ($online_check > 0) {
            $is_staff_online = true;
        }
    }
} catch (Exception $e) {}

    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("BEGIN IMMEDIATE TRANSACTION");
    $tables = [
        'applications' => "CREATE TABLE IF NOT EXISTS applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_type TEXT,
            name TEXT,
            father_husband_name TEXT,
            dob TEXT,
            gender TEXT,
            marital_status TEXT,
            phone TEXT,
            email TEXT,
            current_address TEXT,
            permanent_address TEXT,
            qualification TEXT,
            experience TEXT,
            post_applied TEXT,
            computer_skills TEXT,
            availability TEXT,
            communication_skills TEXT,
            business_name TEXT,
            work_locality TEXT,
            skill_description TEXT,
            desired_location TEXT,
            desired_job_profile TEXT,
            current_job_role TEXT,
            notice_period TEXT,
            current_ctc TEXT,
            expected_ctc TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'service_enquiries' => "CREATE TABLE IF NOT EXISTS service_enquiries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            phone TEXT,
            email TEXT,
            service_date TEXT,
            service_category TEXT,
            service_description TEXT,
            address TEXT,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'general_contacts' => "CREATE TABLE IF NOT EXISTS general_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            phone TEXT,
            subject TEXT,
            message TEXT,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'open_positions' => "CREATE TABLE IF NOT EXISTS open_positions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            location TEXT,
            type TEXT,
            salary TEXT,
            description TEXT,
            requirements TEXT,
            urgent INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'chat_messages' => "CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT,
            sender_type TEXT,
            sender_name TEXT,
            message TEXT,
            receiver_type TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'team_members' => "CREATE TABLE IF NOT EXISTS team_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            position TEXT,
            bio TEXT,
            photo_url TEXT,
            display_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'site_settings' => "CREATE TABLE IF NOT EXISTS site_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE,
            setting_value TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'chat_sessions' => "CREATE TABLE IF NOT EXISTS chat_sessions (
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
        'chat_queue' => "CREATE TABLE IF NOT EXISTS chat_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT,
            agent_id INTEGER DEFAULT 0,
            status TEXT DEFAULT 'waiting',
            assigned_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'admin_users' => "CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password_hash TEXT,
            full_name TEXT,
            email TEXT,
            role TEXT DEFAULT 'staff',
            care_permission INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            last_login DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    foreach ($tables as $createSql) {
        $db->exec($createSql);
    }
    $defaultSettings = [
        ['site_logo', '🏢'],
        ['site_favicon', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/icons/building.svg'],
        ['site_title', 'D K Associates'],
        ['site_tagline', 'Workforce for Everyday Help'],
        ['company_phone', '07662-455311'],
        ['company_whatsapp', '+919329578335'],
        ['company_email', 'care@hidk.in'],
        ['company_address', '2nd Floor, Utopia Tower, Above Shriram Finance, Near College Chowk Flyover, Rewa, MP - 486001'],
        ['company_hours', '7 Days: 10:00 AM - 6:30 PM'],
        ['founded_year', '2025']
    ];
    foreach ($defaultSettings as $setting) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bindValue(1, $setting[0]);
        $stmt->bindValue(2, $setting[1]);
        $stmt->execute();
    }
    $count = $db->querySingle("SELECT COUNT(*) FROM team_members");
    if ($count == 0) {
        $sampleTeam = [
            ['Jyoti Mishra', 'Owner & CEO', 'Visionary leader driving company growth', 'https://via.placeholder.com/300x250?text=Jyoti', 1],
            ['Deepak Mishra', 'CTO', 'Technology strategist and innovation expert', 'https://via.placeholder.com/300x250?text=Deepak', 2],
            ['Puneet Tiwari', 'Operations Manager', 'Ensuring smooth day-to-day operations', 'https://via.placeholder.com/300x250?text=Puneet', 3],
            ['Diksha Mishra', 'Business Development', 'Driving growth and new partnerships', 'https://via.placeholder.com/300x250?text=Diksha', 5],
            ['Pankaj Tiwari', 'Recruitment', 'Connecting the right talent with opportunities', 'https://via.placeholder.com/300x250?text=Pankaj', 4],
            ['Rahul Sharma', 'Advisor & Consultant', 'Providing strategic guidance and expertise', 'https://via.placeholder.com/300x250?text=Rahul', 6]
        ];
        foreach ($sampleTeam as $member) {
            $stmt = $db->prepare("INSERT INTO team_members (name, position, bio, photo_url, display_order, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->bindValue(1, $member[0]);
            $stmt->bindValue(2, $member[1]);
            $stmt->bindValue(3, $member[2]);
            $stmt->bindValue(4, $member[3]);
            $stmt->bindValue(5, $member[4]);
            $stmt->execute();
        }
    }
    $count = $db->querySingle("SELECT COUNT(*) FROM open_positions");
    if ($count == 0) {
        $sampleJobs = [
            ['Electrician', 'Rewa', 'Full-time', '₹15,000 - ₹25,000', 'Need experienced electrician for residential and commercial projects', 'ITI with 2+ years experience, must have own tools', 1],
            ['Plumber', 'Rewa', 'Full-time', '₹12,000 - ₹22,000', 'Looking for skilled plumber for daily work', 'Experience in all types of plumbing work, own tools preferred', 1],
            ['Housekeeping Staff', 'Rewa', 'Full-time', '₹8,000 - ₹12,000', 'Need housekeeping staff for hotels and homes', 'Previous experience preferred, training provided', 0],
            ['Driver', 'Rewa', 'Full-time', '₹10,000 - ₹15,000', 'Need drivers for cars and small vehicles', 'Valid driving license with 2+ years experience', 1],
            ['Cook', 'Rewa', 'Part-time', '₹6,000 - ₹10,000', 'Need cook for home cooking', 'Experience in vegetarian and non-vegetarian cooking', 0],
            ['Security Guard', 'Rewa', 'Full-time', '₹9,000 - ₹12,000', 'Security guards for residential and commercial buildings', 'Physically fit, night shift availability', 1]
        ];
        foreach ($sampleJobs as $job) {
            $stmt = $db->prepare("INSERT INTO open_positions (title, location, type, salary, description, requirements, urgent, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->bindValue(1, $job[0]);
            $stmt->bindValue(2, $job[1]);
            $stmt->bindValue(3, $job[2]);
            $stmt->bindValue(4, $job[3]);
            $stmt->bindValue(5, $job[4]);
            $stmt->bindValue(6, $job[5]);
            $stmt->bindValue(7, $job[6]);
            $stmt->execute();
        }
    }
    $db->exec("COMMIT TRANSACTION");
} catch (Exception $e) {
    die("Unable to connect to database.");
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->bindValue(1, $key);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}
$site_logo = getSetting($db, 'site_logo', '🏢');
$site_favicon = getSetting($db, 'site_favicon', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/icons/building.svg');
$site_title = getSetting($db, 'site_title', 'D K Associates');
$site_tagline = getSetting($db, 'site_tagline', 'Workforce for Everyday Help');
$company_phone = getSetting($db, 'company_phone', '07662-455311');
$company_whatsapp = getSetting($db, 'company_whatsapp', '+919329578335');
$company_email = getSetting($db, 'company_email', 'care@hidk.in');
$company_address = getSetting($db, 'company_address', '2nd Floor, Utopia Tower, Above Shriram Finance, Near College Chowk Flyover, Rewa, MP - 486001');
$company_hours = getSetting($db, 'company_hours', '7 Days: 10:00 AM - 6:30 PM');
$founded_year = getSetting($db, 'founded_year', '2025');
class VisitorCounter {
    private $dataFile = 'data/visitor_data.json';
    private $onlineFile = 'data/online_users.json';
    private $onlineTimeout = 300;
    private $ip;
    public function __construct() {
        $this->ip = $this->getVisitorIP();
        $this->initializeFiles();
    }
    private function getVisitorIP() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return $ip;
    }
    private function initializeFiles() {
        $defaultData = ['total' => 0, 'today' => 0, 'total_visits' => 0, 'today_visits' => 0, 'date' => date('Y-m-d'), 'unique_ips' => []];
        if (!file_exists($this->dataFile)) {
            if (!is_dir('data')) mkdir('data', 0755, true);
            file_put_contents($this->dataFile, json_encode($defaultData));
        }
        if (!file_exists($this->onlineFile)) {
            if (!is_dir('data')) mkdir('data', 0755, true);
            file_put_contents($this->onlineFile, json_encode(['users' => []]));
        }
    }
    public function updateCounters() {
        if (!file_exists($this->dataFile)) return;
        $data = json_decode(file_get_contents($this->dataFile), true);
        $today = date('Y-m-d');
        if ($data['date'] !== $today) {
            $data['today'] = 0;
            $data['today_visits'] = 0;
            $data['date'] = $today;
            $data['unique_ips'] = [];
        }
        $data['total_visits']++;
        $data['today_visits']++;
        if (!in_array($this->ip, $data['unique_ips'])) {
            $data['unique_ips'][] = $this->ip;
            $data['today']++;
            $data['total']++;
        }
        file_put_contents($this->dataFile, json_encode($data));
        $this->updateOnlineUsers();
    }
    private function updateOnlineUsers() {
        if (!file_exists($this->onlineFile)) return;
        $data = json_decode(file_get_contents($this->onlineFile), true);
        $currentTime = time();
        $validUsers = [];
        foreach ($data['users'] as $user) {
            if (($currentTime - $user['last_seen']) < $this->onlineTimeout) {
                $validUsers[] = $user;
            }
        }
        $userFound = false;
        foreach ($validUsers as &$user) {
            if ($user['ip'] === $this->ip) {
                $user['last_seen'] = $currentTime;
                $userFound = true;
                break;
            }
        }
        if (!$userFound) {
            $validUsers[] = [
                'ip' => $this->ip, 
                'last_seen' => $currentTime, 
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ];
        }
        $data['users'] = $validUsers;
        file_put_contents($this->onlineFile, json_encode($data));
    }
    public function getCounterData() {
        $data = ['total' => 0, 'today' => 0, 'total_visits' => 0, 'today_visits' => 0];
        $onlineCount = 0;
        if (file_exists($this->dataFile)) {
            $data = json_decode(file_get_contents($this->dataFile), true);
        }
        if (file_exists($this->onlineFile)) {
            $onlineData = json_decode(file_get_contents($this->onlineFile), true);
            $currentTime = time();
            foreach ($onlineData['users'] as $user) {
                if (($currentTime - $user['last_seen']) < $this->onlineTimeout) $onlineCount++;
            }
        }
        return [
            'total' => number_format($data['total']),
            'today' => number_format($data['today']),
            'online' => number_format($onlineCount),
            'total_visits' => number_format($data['total_visits']),
            'today_visits' => number_format($data['today_visits'])
        ];
    }
}
$visitorCounter = new VisitorCounter();
$visitorCounter->updateCounters();
$counterData = $visitorCounter->getCounterData();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['device_id'])) {
    if (isset($_COOKIE['device_id'])) {
        $_SESSION['device_id'] = $_COOKIE['device_id'];
    } else {
        $_SESSION['device_id'] = bin2hex(random_bytes(16));
        setcookie('device_id', $_SESSION['device_id'], time() + 86400, '/', '', true, true);
    }
}
$form_submitted = false;
$form_success = false;
$form_message = '';
$form_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $form_submitted = true;
    $form_type = $_POST['form_type'] ?? '';
    try {
        if ($form_type === 'permanent') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $post_applied = htmlspecialchars(trim($_POST['post_applied'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (empty($name) || empty($phone) || empty($post_applied)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, post_applied, computer_skills, availability, communication_skills) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'permanent');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $_POST['email'] ?? '');
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $post_applied);
                $stmt->bindValue(14, $_POST['computer_skills'] ?? '');
                $stmt->bindValue(15, $_POST['availability'] ?? '');
                $skills = isset($_POST['communication_skills']) ? implode(',', $_POST['communication_skills']) : '';
                $stmt->bindValue(16, $skills);
                $stmt->execute();
                $form_success = true;
                $form_message = "Application submitted successfully! We'll contact you soon.";
            }
        } elseif ($form_type === 'skilled') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (empty($name) || empty($phone)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, business_name, work_locality, skill_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'skilled');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $_POST['email'] ?? '');
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $_POST['business_name'] ?? '');
                $stmt->bindValue(14, $_POST['work_locality'] ?? '');
                $stmt->bindValue(15, $_POST['skill_description'] ?? '');
                $stmt->execute();
                $form_success = true;
                $form_message = "Registration submitted successfully! We'll help grow your business.";
            }
        } elseif ($form_type === 'placement') {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (empty($name) || empty($phone) || empty($email)) {
                $form_message = "Please fill in all required fields.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_message = "Please enter a valid email address.";
            } else {
                $location = isset($_POST['desired_location']) ? implode(',', $_POST['desired_location']) : '';
                $stmt = $db->prepare("INSERT INTO applications (form_type, name, father_husband_name, dob, gender, marital_status, phone, email, current_address, permanent_address, qualification, experience, desired_location, desired_job_profile, current_job_role, notice_period, current_ctc, expected_ctc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, 'placement');
                $stmt->bindValue(2, $name);
                $stmt->bindValue(3, $_POST['father_husband_name'] ?? '');
                $stmt->bindValue(4, $_POST['dob'] ?? '');
                $stmt->bindValue(5, $_POST['gender'] ?? '');
                $stmt->bindValue(6, $_POST['marital_status'] ?? '');
                $stmt->bindValue(7, $phone);
                $stmt->bindValue(8, $email);
                $stmt->bindValue(9, $_POST['current_address'] ?? '');
                $stmt->bindValue(10, $_POST['permanent_address'] ?? '');
                $stmt->bindValue(11, $_POST['qualification'] ?? '');
                $stmt->bindValue(12, $_POST['experience'] ?? '');
                $stmt->bindValue(13, $location);
                $stmt->bindValue(14, $_POST['desired_job_profile'] ?? '');
                $stmt->bindValue(15, $_POST['current_job_role'] ?? '');
                $stmt->bindValue(16, $_POST['notice_period'] ?? '');
                $stmt->bindValue(17, $_POST['current_ctc'] ?? '');
                $stmt->bindValue(18, $_POST['expected_ctc'] ?? '');
                $stmt->execute();
                $form_success = true;
                $form_message = "Placement request submitted successfully!";
            }
        } elseif (isset($_POST['service_enquiry'])) {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (empty($name)) {
                $form_message = "Please enter your name.";
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $form_message = "Please enter a valid 10-digit phone number.";
            } else {
                $stmt = $db->prepare("INSERT INTO service_enquiries (name, phone, email, service_date, service_category, service_description, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $phone);
                $stmt->bindValue(3, $_POST['email'] ?? '');
                $stmt->bindValue(4, $_POST['service_date'] ?? '');
                $stmt->bindValue(5, $_POST['service_category'] ?? '');
                $stmt->bindValue(6, $_POST['service_description'] ?? '');
                $stmt->bindValue(7, $_POST['address'] ?? '');
                $stmt->execute();
                $form_success = true;
                $form_message = "Service enquiry submitted! We'll contact you within 15 minutes.";
            }
        } elseif (isset($_POST['general_contact'])) {
            $name = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $email = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
            if (empty($name)) {
                $form_message = "Please enter your name.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $form_message = "Please enter a valid email address.";
            } else {
                $stmt = $db->prepare("INSERT INTO general_contacts (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $email);
                $stmt->bindValue(3, $_POST['phone'] ?? '');
                $stmt->bindValue(4, $_POST['subject'] ?? '');
                $stmt->bindValue(5, $_POST['message'] ?? '');
                $stmt->execute();
                $form_success = true;
                $form_message = "Message sent! We'll respond within 24 hours.";
            }
        }
    } catch (Exception $e) {
        $form_message = "Unable to process your request. Please try again.";
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$activeTab = $_GET['tab'] ?? 'home';
$activeBusinessTab = $_GET['sub'] ?? 'features';
$activeJobsTab = $_GET['sub'] ?? 'openings';
$activeContactTab = $_GET['sub'] ?? 'service';
$currentYear = date("Y");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_title); ?> - <?php echo htmlspecialchars($site_tagline); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($site_favicon); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-custom fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="#" onclick="switchTab('home')">
                <div class="logo-container">
                    <?php if (!empty($site_logo) && $site_logo !== '🏢'): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';">
                    <?php else: ?>
                        <span>🏢</span>
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <?php echo htmlspecialchars($site_title); ?>
                    <div class="brand-tagline"><?php echo htmlspecialchars($site_tagline); ?></div>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'home' ? 'active' : ''; ?>" href="#" onclick="switchTab('home'); closeNavbar();">
                            <i class="bi bi-house-door"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'services' ? 'active' : ''; ?>" href="#" onclick="switchTab('services'); closeNavbar();">
                            <i class="bi bi-grid"></i> Services
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'business' ? 'active' : ''; ?>" href="#" onclick="switchTab('business'); closeNavbar();">
                            <i class="bi bi-graph-up"></i> Business
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>" href="#" onclick="switchTab('jobs'); closeNavbar();">
                            <i class="bi bi-briefcase"></i> Jobs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'placement' ? 'active' : ''; ?>" href="#" onclick="switchTab('placement'); closeNavbar();">
                            <i class="bi bi-person-plus"></i> Placement
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'team' ? 'active' : ''; ?>" href="#" onclick="switchTab('team'); closeNavbar();">
                            <i class="bi bi-people"></i> Team
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom <?php echo $activeTab === 'contact' ? 'active' : ''; ?>" href="#" onclick="switchTab('contact'); closeNavbar();">
                            <i class="bi bi-envelope"></i> Contact
                        </a>
                    </li>
                    <li class="nav-item ms-lg-3">
                        <span class="counter-badge">
                            <i class="bi bi-people"></i> <?php echo $counterData['online']; ?> Online
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php if ($form_submitted && !empty($form_message)): ?>
    <div class="container mt-5 pt-5">
        <div class="alert <?php echo $form_success ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
            <i class="bi <?php echo $form_success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($form_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>
    <main class="mt-5 pt-4">
        <div class="tab-pane <?php echo $activeTab === 'home' ? 'active' : ''; ?>" id="home-tab">
            <section class="hero-section">
                <div class="container">
                    <div class="row align-items-center min-vh-50">
                        <div class="col-lg-8" data-aos="fade-up">
                            <span class="hero-badge">
                                <i class="bi bi-star-fill me-2"></i> Rewa's #1 Workforce Solutions
                            </span>
                            <h1 class="hero-title">Your Trusted Partner for<br><span style="color: var(--secondary);">Everyday Help</span></h1>
                            <p class="hero-subtitle">Connecting skilled local professionals with individuals, families, and businesses since <?php echo htmlspecialchars($founded_year); ?>.</p>
                            <div class="d-flex gap-3 flex-wrap">
                                <button class="btn btn-primary-custom" onclick="switchTab('services')" style="width: auto;">
                                    <i class="bi bi-grid me-2"></i> Explore Services
                                </button>
                                <button class="btn btn-outline-custom" onclick="switchTab('contact')" style="width: auto;">
                                    <i class="bi bi-telephone me-2"></i> Contact Us
                                </button>
                            </div>
                            <div class="mt-4 d-flex gap-4">
                                <div>
                                    <i class="bi bi-clock text-white fs-4"></i>
                                    <span class="ms-2"><?php echo htmlspecialchars($company_hours); ?></span>
                                </div>
                                <div>
                                    <i class="bi bi-people text-white fs-4"></i>
                                    <span class="ms-2">100+ Professionals</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <div class="container mt-5">
                <div class="row g-4">
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                        <div class="stat-card">
                            <div class="stat-number">100+</div>
                            <div class="stat-label">Professionals</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="200">
                        <div class="stat-card">
                            <div class="stat-number">50+</div>
                            <div class="stat-label">Services</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="300">
                        <div class="stat-card">
                            <div class="stat-number">15min</div>
                            <div class="stat-label">Response</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="400">
                        <div class="stat-card">
                            <div class="stat-number">98%</div>
                            <div class="stat-label">Satisfaction</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="container mt-5">
                <h2 class="section-title" data-aos="fade-right">Our Services</h2>
                <div class="row g-4 mt-3">
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-brush"></i>
                            </div>
                            <h4>Cleaning & Housekeeping</h4>
                            <p class="text-muted">Professional cleaning services for homes and offices</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-tools"></i>
                            </div>
                            <h4>Repair & Maintenance</h4>
                            <p class="text-muted">Electrical, plumbing, carpentry and more</p>
                        </div>
                    </div>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                        <div class="service-card">
                            <div class="service-icon-wrapper">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <h4>Teaching & Training</h4>
                            <p class="text-muted">Home tutoring, computer skills, language training</p>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <button class="btn btn-outline-custom" onclick="switchTab('services')" style="width: auto;">View All Services <i class="bi bi-arrow-right ms-2"></i></button>
                </div>
            </div>
            <div class="container mt-5">
                <div class="card-modern" data-aos="fade-up">
                    <div class="card-header-gradient">
                        <i class="bi bi-headset card-icon"></i>
                        <h3 class="h2">Need Help? We're Here 24/7</h3>
                        <p>Get service within 15 minutes - Call us now!</p>
                    </div>
                    <div class="card-body-modern">
                        <div class="row">
                            <div class="col-md-4 mb-3 mb-md-0">
                                <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="btn btn-outline-custom w-100">
                                    <i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($company_phone); ?>
                                </a>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" class="btn btn-outline-custom w-100" target="_blank">
                                    <i class="bi bi-whatsapp me-2"></i> WhatsApp
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="btn btn-outline-custom w-100">
                                    <i class="bi bi-envelope me-2"></i> Email Us
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="why-choose-us-section mt-4" data-aos="fade-up">
                    <div class="bg-light p-4 rounded-4">
                        <h5 class="fw-bold text-primary mb-3">Why Choose Us?</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p><i class="bi bi-clock text-warning me-2"></i> <strong>15-Minute Processing:</strong> Fastest service activation in Rewa</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-people text-warning me-2"></i> <strong>Local Professionals:</strong> Verified skilled workers from Rewa</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-currency-rupee text-warning me-2"></i> <strong>Transparent Pricing:</strong> No hidden charges</p>
                            </div>
                            <div class="col-md-6">
                                <p><i class="bi bi-shield-check text-warning me-2"></i> <strong>Quality Guarantee:</strong> Satisfaction guaranteed</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'services' ? 'active' : ''; ?>" id="services-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Our Comprehensive Services</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Choose from our wide range of professional workforce solutions</p>
                <div class="row g-4">
                    <?php
                    $services = [
                        ['Cleaning & Housekeeping', 'bi-brush', ['Deep Cleaning', 'Regular Housekeeping', 'Laundry & Ironing', 'Pest Control']],
                        ['Repair & Maintenance', 'bi-tools', ['Electrical Repairs', 'Plumbing', 'Carpentry', 'Painting']],
                        ['Teaching & Training', 'bi-mortarboard', ['Academic Tutoring', 'Language Training', 'Computer Skills', 'Yoga & Fitness']],
                        ['Personal Assistance', 'bi-person-badge', ['Errand Running', 'Virtual Assistant', 'Driver on Hire', 'Event Assistance']],
                        ['Creative & Crafting', 'bi-palette', ['Custom Furniture', 'Graphic Design', 'Invitations', 'Social Media Creatives']],
                        ['Office & Professional Support', 'bi-briefcase', ['Data Entry', 'Social Media Management', 'Website Design', 'HR Support']],
                        ['Specialized Services', 'bi-star', ['CCTV Installation', 'Property Services', 'Photography', 'Astrology']]
                    ];
                    foreach ($services as $index => $service):
                    ?>
                    <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <div class="card-modern">
                            <div class="card-header-gradient text-center">
                                <i class="bi <?php echo $service[1]; ?> card-icon"></i>
                                <h4><?php echo $service[0]; ?></h4>
                            </div>
                            <div class="card-body-modern">
                                <ul class="list-unstyled">
                                    <?php foreach ($service[2] as $item): ?>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i><?php echo $item; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button class="btn btn-primary-custom mt-3" onclick="switchTab('contact')">Enquire Now</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <h2 class="section-title mt-5" data-aos="fade-right">Promoted Services</h2>
                <div class="row g-4 mt-3">
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="100">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-house-heart"></i>
                            </div>
                            <h5>Complete Home Maintenance</h5>
                            <p class="text-muted small">Monthly subscription package</p>
                            <h4 class="text-secondary">₹7,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="200">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-building"></i>
                            </div>
                            <h5>Office Support Package</h5>
                            <p class="text-muted small">For small businesses</p>
                            <h4 class="text-secondary">₹14,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="300">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-person-workspace"></i>
                            </div>
                            <h5>Home Tutoring Package</h5>
                            <p class="text-muted small">3 subjects + computer basics</p>
                            <h4 class="text-secondary">₹4,999/mo</h4>
                        </div>
                    </div>
                    <div class="col-md-3" data-aos="fade-up" data-aos-delay="400">
                        <div class="service-card" style="border-top: 4px solid var(--secondary);">
                            <div class="service-icon-wrapper" style="background: var(--gradient-secondary);">
                                <i class="bi bi-car-front"></i>
                            </div>
                            <h5>Driver-on-Hire</h5>
                            <p class="text-muted small">4/8/12 hours daily</p>
                            <h4 class="text-secondary">₹11,999/mo</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'business' ? 'active' : ''; ?>" id="business-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Business Upgrade for Skilled Workers</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Boost your independent service business with our professional support</p>
                <div class="custom-tab-nav mb-4">
                    <button class="btn <?php echo $activeBusinessTab === 'features' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchBusinessSubTab('features')">
                        <i class="bi bi-stars me-2"></i>Features
                    </button>
                    <button class="btn <?php echo $activeBusinessTab === 'registration' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchBusinessSubTab('registration')">
                        <i class="bi bi-pencil-square me-2"></i>Business Registration
                    </button>
                </div>
                <div class="business-tab-content">
                    <?php if ($activeBusinessTab === 'features'): ?>
                    <div id="features-content">
                        <div class="row g-4">
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi-person-badge fs-1 text-primary mb-3"></i>
                                        <h5>Client Referral Network</h5>
                                        <p class="text-muted">Get connected to a steady stream of clients through our established network.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi-graph-up fs-1 text-primary mb-3"></i>
                                        <h5>Business Growth Support</h5>
                                        <p class="text-muted">Guidance on pricing, service expansion, and customer management.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                                <div class="card-modern">
                                    <div class="card-body-modern text-center">
                                        <i class="bi bi bi-diagram-3 fs-1 text-primary mb-3"></i>
                                        <h5>Partnership Opportunities</h5>
                                        <p class="text-muted">Collaborate on larger projects with us handling client acquisition.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-light p-4 rounded-4 mt-4">
                            <h5 class="fw-bold text-primary mb-3">Perfect for:</h5>
                            <div class="row g-2">
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Electricians</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Plumbers</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Carpenters</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Painters</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Home Tutors</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Drivers</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Cooks</span></div>
                                <div class="col-6 col-md-3"><span class="badge bg-primary text-white p-2 w-100">Photographers</span></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div id="registration-content">
                        <div class="card-modern">
                            <div class="card-body-modern">
                                <form method="POST" id="skilled-form">
                                    <input type="hidden" name="form_type" value="skilled">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Your Primary Skill/Service *</label>
                                                <select class="form-control" name="desired_post" required>
                                                    <option value="">Select Your Main Service</option>
                                                    <optgroup label="Home Services">
                                                        <option value="House help (Maids)">House help (Maids)</option>
                                                        <option value="Deep Cleaning">Deep Cleaning</option>
                                                        <option value="Laundry & Drycleaner">Laundry & Drycleaner</option>
                                                        <option value="Errand Runner">Errand Runner</option>
                                                    </optgroup>
                                                    <optgroup label="Technical Services">
                                                        <option value="Electrician">Electrician</option>
                                                        <option value="Plumber">Plumber</option>
                                                        <option value="Carpenter">Carpenter</option>
                                                        <option value="Painter">Painter</option>
                                                        <option value="CCTV Installation">CCTV Installation</option>
                                                    </optgroup>
                                                    <optgroup label="Professional Services">
                                                        <option value="Driver">Driver</option>
                                                        <option value="Office Attendant">Office Attendant</option>
                                                        <option value="Cook">Cook</option>
                                                        <option value="Waiter">Waiter</option>
                                                        <option value="Insurance Advisor">Insurance Advisor</option>
                                                        <option value="Property Brokers">Property Brokers</option>
                                                    </optgroup>
                                                    <optgroup label="Creative Services">
                                                        <option value="Decoration (flowers)">Decoration (flowers)</option>
                                                        <option value="Interior Decoration">Interior Decoration</option>
                                                        <option value="Photography & Videography">Photography & Videography</option>
                                                        <option value="Beautician">Beautician</option>
                                                    </optgroup>
                                                    <optgroup label="Education & Training">
                                                        <option value="Home Tutor">Home Tutor</option>
                                                        <option value="Language Trainer">Language Trainer</option>
                                                        <option value="Fitness Coach">Fitness Coach</option>
                                                        <option value="Dance Tutor">Dance Tutor</option>
                                                    </optgroup>
                                                    <optgroup label="Digital Services">
                                                        <option value="Data Entry">Data Entry</option>
                                                        <option value="Web Design">Web Design</option>
                                                        <option value="SEO">SEO</option>
                                                        <option value="UI/UX Support">UI/UX Support</option>
                                                        <option value="Social Media Creatives">Social Media Creatives</option>
                                                        <option value="Online Services">Online Services</option>
                                                    </optgroup>
                                                    <option value="Other">Other (Please specify)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="other_post_container" style="display: none;">
                                            <div class="form-floating-custom">
                                                <label>Please specify your skill/service</label>
                                                <input type="text" class="form-control" name="other_post">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Business/Professional Name</label>
                                                <input type="text" class="form-control" name="business_name" placeholder="e.g., Rajesh Electricals">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Full Name *</label>
                                                <input type="text" class="form-control" name="name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Father/Husband Name *</label>
                                                <input type="text" class="form-control" name="father_husband_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Date of Birth *</label>
                                                <input type="date" class="form-control" name="dob" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Gender *</label>
                                                <select class="form-control" name="gender" required>
                                                    <option value="">Select Gender</option>
                                                    <option value="Male">Male</option>
                                                    <option value="Female">Female</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Marital Status *</label>
                                                <select class="form-control" name="marital_status" required>
                                                    <option value="">Select Marital Status</option>
                                                    <option value="Unmarried">Unmarried</option>
                                                    <option value="Married">Married</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Phone Number *</label>
                                                <input type="tel" class="form-control" name="phone" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Service Area/Locality *</label>
                                                <input type="text" class="form-control" name="work_locality" placeholder="e.g., Rewa City Center" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Email Address</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Current Address *</label>
                                                <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Permanent Address *</label>
                                                <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="copyAddress2" onchange="copyAddress('2')">
                                                    <label class="form-check-label" for="copyAddress2">Same as Current Address</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Highest Qualification *</label>
                                                <select class="form-control" name="qualification" required>
                                                    <option value="">Select Qualification</option>
                                                    <option value="Below 12th">Below 12th</option>
                                                    <option value="Higher Secondary">Higher Secondary</option>
                                                    <option value="Graduate & Above">Graduate & Above</option>
                                                    <option value="Others">Diploma and ITI</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Years of Experience *</label>
                                                <select class="form-control" name="experience" required>
                                                    <option value="">Select Experience</option>
                                                    <option value="Less than 1 year">Less than 1 year</option>
                                                    <option value="1-3 years">1-3 years</option>
                                                    <option value="3-5 years">3-5 years</option>
                                                    <option value="5-10 years">5-10 years</option>
                                                    <option value="10+ years">10+ years</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Service Description & Specializations *</label>
                                                <textarea class="form-control" name="skill_description" rows="4" placeholder="Describe your services, specialties, types of work you handle, etc." required></textarea>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary-custom">Register for Business Upgrade</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>" id="jobs-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Job Vacancies</h2>
                <div class="custom-tab-nav mb-4">
                    <button class="btn <?php echo $activeJobsTab === 'openings' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchJobsSubTab('openings')">
                        <i class="bi bi-list-ul me-2"></i>Open Positions
                    </button>
                    <button class="btn <?php echo $activeJobsTab === 'apply' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchJobsSubTab('apply')">
                        <i class="bi bi-pencil-square me-2"></i>Apply Now
                    </button>
                </div>
                <div class="jobs-tab-content">
                    <?php if ($activeJobsTab === 'openings'): ?>
                    <div id="openings-content">
                        <?php
                        try {
                            $openPositions = $db->query("SELECT * FROM open_positions WHERE is_active = 1 ORDER BY urgent DESC, created_at DESC");
                            if ($openPositions) {
                                $found = false;
                                while ($job = $openPositions->fetchArray(SQLITE3_ASSOC)):
                                    $found = true;
                        ?>
                        <div class="job-card" data-aos="fade-up">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <h4 class="fw-bold text-primary"><?php echo htmlspecialchars($job['title']); ?></h4>
                                <?php if ($job['urgent']): ?>
                                <span class="urgent-badge"><i class="bi bi-exclamation-triangle me-1"></i>Urgent</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 mb-3 flex-wrap">
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($job['location']); ?></span>
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($job['type']); ?></span>
                                <span class="badge bg-light text-dark p-2"><i class="bi bi-currency-rupee me-1"></i><?php echo htmlspecialchars($job['salary']); ?></span>
                            </div>
                            <p><strong>Description:</strong> <?php echo htmlspecialchars($job['description']); ?></p>
                            <p><strong>Requirements:</strong> <?php echo htmlspecialchars($job['requirements']); ?></p>
                            <button class="btn btn-primary-custom" onclick="switchJobsSubTab('apply');" style="width: auto;">
                                Apply Now <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                        <?php
                                endwhile;
                                if (!$found) {
                                    echo '<p class="text-muted">No open positions at the moment. Please check back later.</p>';
                                }
                            }
                        } catch (Exception $e) {
                            echo '<p class="text-muted">Unable to load positions at this time.</p>';
                        }
                        ?>
                    </div>
                    <?php else: ?>
                    <div id="apply-content">
                        <div class="card-modern">
                            <div class="card-body-modern">
                                <form method="POST" id="permanent-form">
                                    <input type="hidden" name="form_type" value="permanent">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Position Applied For *</label>
                                                <select class="form-control" name="post_applied" id="post_applied" required>
                                                    <option value="">Select Position</option>
                                                    <?php
                                                    try {
                                                        $jobTitles = $db->query("SELECT DISTINCT title FROM open_positions WHERE is_active = 1");
                                                        while ($jobTitle = $jobTitles->fetchArray(SQLITE3_ASSOC)) {
                                                            echo '<option value="' . htmlspecialchars($jobTitle['title']) . '">' . htmlspecialchars($jobTitle['title']) . '</option>';
                                                        }
                                                    } catch (Exception $e) {
                                                    }
                                                    ?>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6" id="other_position_container" style="display: none;">
                                            <div class="form-floating-custom">
                                                <label>Please specify position</label>
                                                <input type="text" class="form-control" name="other_position">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Full Name *</label>
                                                <input type="text" class="form-control" name="name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Father/Husband Name *</label>
                                                <input type="text" class="form-control" name="father_husband_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Date of Birth *</label>
                                                <input type="date" class="form-control" name="dob" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Gender *</label>
                                                <select class="form-control" name="gender" required>
                                                    <option value="">Select Gender</option>
                                                    <option value="Male">Male</option>
                                                    <option value="Female">Female</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Marital Status *</label>
                                                <select class="form-control" name="marital_status" required>
                                                    <option value="">Select Marital Status</option>
                                                    <option value="Unmarried">Unmarried</option>
                                                    <option value="Married">Married</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Phone Number *</label>
                                                <input type="tel" class="form-control" name="phone" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Email Address</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Current Address *</label>
                                                <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating-custom">
                                                <label>Permanent Address *</label>
                                                <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="copyAddress" onchange="copyAddress('')">
                                                    <label class="form-check-label" for="copyAddress">Same as Current Address</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Highest Qualification *</label>
                                                <select class="form-control" name="qualification" required>
                                                    <option value="">Select Qualification</option>
                                                    <option value="Below 12th">Below 12th</option>
                                                    <option value="Higher Secondary">Higher Secondary</option>
                                                    <option value="Graduate & Above">Graduate & Above</option>
                                                    <option value="Others">Diploma and ITI</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Years of Experience</label>
                                                <select class="form-control" name="experience">
                                                    <option value="">Select Experience</option>
                                                    <option value="Less than 1 year">Less than 1 year</option>
                                                    <option value="1-3 years">1-3 years</option>
                                                    <option value="3-5 years">3-5 years</option>
                                                    <option value="5-10 years">5-10 years</option>
                                                    <option value="10+ years">10+ years</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Computer Skills</label>
                                                <select class="form-control" name="computer_skills">
                                                    <option value="">Select</option>
                                                    <option value="Basic">Basic</option>
                                                    <option value="Intermediate">Intermediate</option>
                                                    <option value="Advanced">Advanced</option>
                                                    <option value="None">None</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating-custom">
                                                <label>Availability</label>
                                                <select class="form-control" name="availability">
                                                    <option value="">Select</option>
                                                    <option value="Immediate">Immediate</option>
                                                    <option value="Within 15 days">Within 15 days</option>
                                                    <option value="Within 30 days">Within 30 days</option>
                                                    <option value="After notice period">After notice period</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="fw-semibold mb-2">Communication Skills</label>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="Hindi">
                                                        <label class="form-check-label">Hindi</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="English">
                                                        <label class="form-check-label">English</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="communication_skills[]" value="Other Regional">
                                                        <label class="form-check-label">Other Regional</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary-custom">Submit Application</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'placement' ? 'active' : ''; ?>" id="placement-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Placement Help</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Get assistance finding the right job for your skills and experience</p>
                <div class="card-modern" data-aos="fade-up">
                    <div class="card-body-modern">
                        <form method="POST" id="placement-form">
                            <input type="hidden" name="form_type" value="placement">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Full Name *</label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Father/Husband Name *</label>
                                        <input type="text" class="form-control" name="father_husband_name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Date of Birth *</label>
                                        <input type="date" class="form-control" name="dob" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Gender *</label>
                                        <select class="form-control" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Marital Status *</label>
                                        <select class="form-control" name="marital_status" required>
                                            <option value="">Select Marital Status</option>
                                            <option value="Unmarried">Unmarried</option>
                                            <option value="Married">Married</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Phone Number *</label>
                                        <input type="tel" class="form-control" name="phone" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Email *</label>
                                        <input type="email" class="form-control" name="email" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current Address *</label>
                                        <textarea class="form-control" name="current_address" rows="2" required></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Permanent Address *</label>
                                        <textarea class="form-control" name="permanent_address" rows="2" required></textarea>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="copyAddress3" onchange="copyAddress('3')">
                                            <label class="form-check-label" for="copyAddress3">Same as Current Address</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Highest Qualification *</label>
                                        <select class="form-control" name="qualification" required>
                                            <option value="">Select Qualification</option>
                                            <option value="Below 12th">Below 12th</option>
                                            <option value="Higher Secondary">Higher Secondary</option>
                                            <option value="Graduate & Above">Graduate & Above</option>
                                            <option value="Others">Diploma and ITI</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Work Experience</label>
                                        <textarea class="form-control" name="experience" rows="2" placeholder="Describe your work experience"></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Desired Job Profile *</label>
                                        <input type="text" class="form-control" name="desired_job_profile" required placeholder="e.g., Software Developer, Marketing Manager">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current Job Role</label>
                                        <input type="text" class="form-control" name="current_job_role" placeholder="e.g., Senior Developer, Unemployed">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Notice Period *</label>
                                        <select class="form-control" name="notice_period" required>
                                            <option value="">Select Notice Period</option>
                                            <option value="Immediate">Immediate Joining</option>
                                            <option value="15 Days">15 Days</option>
                                            <option value="30 Days">30 Days</option>
                                            <option value="45 Days">45 Days</option>
                                            <option value="60 Days">60 Days</option>
                                            <option value="90 Days">90 Days</option>
                                            <option value="Serving Notice">Serving Notice</option>
                                            <option value="Unemployed">Currently Unemployed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Current/Last Salary (₹)</label>
                                        <input type="text" class="form-control" name="current_ctc" placeholder="e.g., 5,00,000 per annum">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating-custom">
                                        <label>Expected Salary (₹)</label>
                                        <input type="text" class="form-control" name="expected_ctc" placeholder="e.g., 7,00,000 per annum">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="fw-semibold mb-2">Preferred Job Location *</label>
                                    <div class="row g-2">
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Rewa" id="loc_rewa">
                                                <label class="form-check-label" for="loc_rewa">Rewa</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Satna" id="loc_satna">
                                                <label class="form-check-label" for="loc_satna">Satna</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Jabalpur" id="loc_jabalpur">
                                                <label class="form-check-label" for="loc_jabalpur">Jabalpur</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Bhopal" id="loc_bhopal">
                                                <label class="form-check-label" for="loc_bhopal">Bhopal</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Indore" id="loc_indore">
                                                <label class="form-check-label" for="loc_indore">Indore</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="desired_location[]" value="Other" id="loc_other">
                                                <label class="form-check-label" for="loc_other">Other</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary-custom">Submit for Placement Help</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'team' ? 'active' : ''; ?>" id="team-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Our Leadership Team</h2>
                <p class="text-muted mb-5" data-aos="fade-right" data-aos-delay="100">Meet the experts behind our success</p>
                <div class="row g-4">
                    <?php
                    try {
                        $teamMembers = $db->query("SELECT * FROM team_members WHERE is_active = 1 ORDER BY display_order ASC");
                        $hasMembers = false;
                        if ($teamMembers) {
                            while ($member = $teamMembers->fetchArray(SQLITE3_ASSOC)):
                                $hasMembers = true;
                    ?>
                    <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo ($member['display_order'] ?? 0) * 100; ?>">
                        <div class="team-card">
                            <img src="<?php echo htmlspecialchars($member['photo_url'] ?? 'https://via.placeholder.com/300x250?text=Team+Member'); ?>" alt="<?php echo htmlspecialchars($member['name']); ?>" class="team-img" onerror="this.src='https://via.placeholder.com/300x250?text=Photo+Not+Available'">
                            <div class="team-info">
                                <h4 class="team-name"><?php echo htmlspecialchars($member['name']); ?></h4>
                                <p class="team-position"><?php echo htmlspecialchars($member['position']); ?></p>
                                <p class="text-muted small"><?php echo htmlspecialchars($member['bio']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php
                            endwhile;
                        }
                        if (!$hasMembers) {
                            throw new Exception("No team members found");
                        }
                    } catch (Exception $e) {
                        ?>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="100">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Jyoti" alt="Jyoti Mishra" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Jyoti Mishra</h4>
                                    <p class="team-position">Owner & CEO</p>
                                    <p class="text-muted small">Visionary leader driving company growth</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="200">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Deepak" alt="Deepak Mishra" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Deepak Mishra</h4>
                                    <p class="team-position">CTO</p>
                                    <p class="text-muted small">Technology strategist and innovation expert</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="300">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Puneet" alt="Puneet Tiwari" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Puneet Tiwari</h4>
                                    <p class="team-position">Operations Manager</p>
                                    <p class="text-muted small">Ensuring smooth day-to-day operations</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="400">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Pankaj" alt="Pankaj Tiwari" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Pankaj Tiwari</h4>
                                    <p class="team-position">Recruitment</p>
                                    <p class="text-muted small">Connecting the right talent with opportunities</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="500">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Diksha" alt="Diksha Mishra" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Diksha Mishra</h4>
                                    <p class="team-position">Business Development</p>
                                    <p class="text-muted small">Driving growth and new partnerships</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="600">
                            <div class="team-card">
                                <img src="https://via.placeholder.com/300x250?text=Rahul" alt="Rahul Sharma" class="team-img">
                                <div class="team-info">
                                    <h4 class="team-name">Rahul Sharma</h4>
                                    <p class="team-position">Advisor & Consultant</p>
                                    <p class="text-muted small">Providing strategic guidance and expertise</p>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="tab-pane <?php echo $activeTab === 'contact' ? 'active' : ''; ?>" id="contact-tab">
            <div class="container py-5">
                <h2 class="section-title" data-aos="fade-right">Contact Us</h2>
                <p class="text-primary fw-bold mb-4" data-aos="fade-right" data-aos-delay="100"><i class="bi bi-bolt me-2"></i>Get service in just 15 minutes - Call us now!</p>
                <div class="row g-4">
                    <div class="col-lg-5" data-aos="fade-up">
                        <div class="card-modern h-100" style="background: var(--gradient-primary); color: white;">
                            <div class="card-body-modern">
                                <h4 class="text-white mb-4"><i class="bi bi-info-circle me-2"></i>Contact Information</h4>
                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-telephone fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Office Phone:</p>
                                        <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="text-white text-decoration-none"><?php echo htmlspecialchars($company_phone); ?></a>
                                    </div>
                                </div>
                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-whatsapp fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">WhatsApp Business:</p>
                                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" class="text-white text-decoration-none" target="_blank"><?php echo htmlspecialchars($company_whatsapp); ?></a>
                                    </div>
                                </div>
                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-envelope fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Service Email:</p>
                                        <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="text-white text-decoration-none"><?php echo htmlspecialchars($company_email); ?></a>
                                    </div>
                                </div>
                                <div class="d-flex gap-3 mb-4">
                                    <i class="bi bi-geo-alt fs-3"></i>
                                    <div>
                                        <p class="mb-1 fw-bold">Headquarters:</p>
                                        <p class="mb-1">Rewa City, MP - 486001</p>
                                        <p class="small opacity-75"><?php echo htmlspecialchars($company_address); ?></p>
                                    </div>
                                </div>
                                <div class="bg-white bg-opacity-10 p-3 rounded-4">
                                    <h5 class="text-white mb-2">Business Hours</h5>
                                    <p class="mb-1"><i class="bi bi-clock me-2"></i><?php echo htmlspecialchars($company_hours); ?></p>
                                    <p class="mb-0"><i class="bi bi-exclamation-circle me-2"></i>Emergency services available 24/7</p>
                                </div>
                                <button class="btn btn-light w-100 mt-4" id="downloadVCF">
                                    <i class="bi bi-card-heading me-2"></i>Download Contact Card
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7" data-aos="fade-up" data-aos-delay="200">
                        <div class="custom-tab-nav mb-4">
                            <button class="btn <?php echo $activeContactTab === 'service' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchContactSubTab('service')">
                                <i class="bi bi-tools me-2"></i>Service Enquiry
                            </button>
                            <button class="btn <?php echo $activeContactTab === 'general' ? 'btn-primary' : 'btn-outline-primary'; ?>" onclick="switchContactSubTab('general')">
                                <i class="bi bi-envelope me-2"></i>General Contact
                            </button>
                        </div>
                        <div class="contact-tab-content">
                            <?php if ($activeContactTab === 'service'): ?>
                            <div id="service-content">
                                <div class="card-modern">
                                    <div class="card-body-modern">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="service_enquiry" value="1">
                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Full Name *</label>
                                                        <input type="text" class="form-control" name="name" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Phone Number *</label>
                                                        <input type="tel" class="form-control" name="phone" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Email Address</label>
                                                        <input type="email" class="form-control" name="email">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Preferred Service Date</label>
                                                        <input type="date" class="form-control" name="service_date">
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Service Category *</label>
                                                        <select class="form-control" name="service_category" required>
                                                            <option value="">Select a service category</option>
                                                            <option value="cleaning">Cleaning & Housekeeping</option>
                                                            <option value="repair">Repair & Maintenance</option>
                                                            <option value="teaching">Teaching & Training</option>
                                                            <option value="assistance">Personal Assistance</option>
                                                            <option value="mponline">CSC & MP Online Services</option>
                                                            <option value="business">Business Assistance</option>
                                                            <option value="event">Event Assistance</option>
                                                            <option value="property">Property Buy/Sale/Rent Assistance</option>
                                                            <option value="placement">Placement Services</option>
                                                            <option value="other">Other Services</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Service Description *</label>
                                                        <textarea class="form-control" name="service_description" rows="3" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Address for Service</label>
                                                        <textarea class="form-control" name="address" rows="2"></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary-custom">Submit Service Request</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div id="general-content">
                                <div class="card-modern">
                                    <div class="card-body-modern">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="general_contact" value="1">
                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Full Name *</label>
                                                        <input type="text" class="form-control" name="name" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Email Address *</label>
                                                        <input type="email" class="form-control" name="email" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-floating-custom">
                                                        <label>Phone Number</label>
                                                        <input type="tel" class="form-control" name="phone">
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Subject *</label>
                                                        <select class="form-control" name="subject" required>
                                                            <option value="">Select a subject</option>
                                                            <option value="partnership">Business Partnership</option>
                                                            <option value="feedback">Feedback & Suggestions</option>
                                                            <option value="complaint">Complaint</option>
                                                            <option value="other">Other Inquiry</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-floating-custom">
                                                        <label>Message *</label>
                                                        <textarea class="form-control" name="message" rows="4" required></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary-custom">Send Message</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="footer-logo-container mb-3">
                        <?php if (!empty($site_logo) && $site_logo !== '🏢'): ?>
                            <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='🏢';">
                        <?php else: ?>
                            <span>🏢</span>
                        <?php endif; ?>
                    </div>
                    <h5><?php echo htmlspecialchars($site_title); ?></h5>
                    <p class="text-white-50"><?php echo htmlspecialchars($site_tagline); ?></p>
                </div>
                <div class="col-lg-2">
                    <h5>Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#" onclick="switchTab('home'); closeNavbar();">Home</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Services</a></li>
                        <li><a href="#" onclick="switchTab('jobs'); closeNavbar();">Jobs</a></li>
                        <li><a href="#" onclick="switchTab('team'); closeNavbar();">Team</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5>Services</h5>
                    <ul class="footer-links">
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Cleaning & Housekeeping</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Repair & Maintenance</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Teaching & Training</a></li>
                        <li><a href="#" onclick="switchTab('services'); closeNavbar();">Personal Assistance</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5>Contact Info</h5>
                    <ul class="footer-links">
                        <li><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($company_phone); ?></li>
                        <li><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($company_email); ?></li>
                        <li><i class="bi bi-geo-alt me-2"></i> Rewa, MP</li>
                    </ul>
                </div>
            </div>
            <hr class="my-4 bg-white opacity-25">
            <div class="row">
                <div class="col-md-6">
                    <p class="small text-white-50 mb-0">&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="small text-white-50 mb-0">
                        <i class="bi bi-people me-1"></i> Total Visitors: <?php echo $counterData['total']; ?> |
                        <i class="bi bi-calendar-day me-1"></i> Today: <?php echo $counterData['today']; ?>
                    </p>
                </div>
            </div>
        </div>
    </footer>
    <button class="chat-toggle" id="chatToggle">
        <i class="bi bi-chat-dots"></i>
    </button>
    <div class="quick-contact-popup" id="quickContactPopup">
        <div class="bg-primary text-white p-3">
            <h6 class="mb-0"><i class="bi bi-headset me-2"></i>Quick Contact</h6>
        </div>
                <?php if ($is_staff_online): ?>
        <a href="#" class="quick-contact-item" id="openLiveChat">
            <i class="bi bi-chat" style="background: var(--primary);"></i>
            <div>
                <strong>Live Chat</strong>
                <p class="small text-muted mb-0">Chat with our team</p>
            </div>
        </a>
        <?php endif; ?>
        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>" target="_blank" class="quick-contact-item">
            <i class="bi bi-whatsapp" style="background: #25D366;"></i>
            <div>
                <strong>WhatsApp</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_whatsapp); ?></p>
            </div>
        </a>
        <a href="mailto:<?php echo htmlspecialchars($company_email); ?>" class="quick-contact-item">
            <i class="bi bi-envelope" style="background: #EA4335;"></i>
            <div>
                <strong>Email</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_email); ?></p>
            </div>
        </a>
        <a href="tel:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>" class="quick-contact-item">
            <i class="bi bi-telephone" style="background: #34A853;"></i>
            <div>
                <strong>Call</strong>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars($company_phone); ?></p>
            </div>
        </a>
    </div>
    <div class="chat-widget" id="chatWidget">
        <div class="chat-header">
            <h5><i class="bi bi-chat-dots me-2"></i>Live Chat - <?php echo htmlspecialchars($site_title); ?></h5>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-danger px-2 py-1" id="endChatBtn" style="display:none; font-size:0.75rem;" title="Terminate Chat">
                    <i class="bi bi-x-octagon me-1"></i>End Chat
                </button>
                <button class="btn-close btn-close-white" id="closeChat" title="Minimize"></button>
            </div>
        </div>
        <div id="chatInitForm" class="chat-init-form">
            <h6 class="mb-3">Please provide your details to start chat</h6>
            <form id="chatInitFormElement">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="mb-3">
                    <label class="form-label">Your Name *</label>
                    <input type="text" class="form-control" name="guest_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact Reason *</label>
                    <select class="form-control" name="contact_reason" required>
                        <option value="">Select reason</option>
                        <option value="service_enquiry">Service Enquiry</option>
                        <option value="job_application">Job Application</option>
                        <option value="business_upgrade">Business Upgrade</option>
                        <option value="placement_help">Placement Help</option>
                        <option value="general_query">General Query</option>
                        <option value="feedback">Feedback</option>
                        <option value="complaint">Complaint</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" name="guest_email">
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" class="form-control" name="guest_phone">
                </div>
                <button type="submit" class="btn btn-primary-custom">Start Chat</button>
            </form>
        </div>
        <div id="chatMessagesContainer" style="display:none;">
            <div class="chat-messages" id="chatMessages">
                <div class="chat-message admin">
                    <div class="message-bubble">
                        Welcome! How can we help you today?
                    </div>
                    <div class="message-time">Just now</div>
                </div>
            </div>
            <div class="chat-input-area">
                <input type="text" id="chatInput" placeholder="Type your message..." autocomplete="off">
                <button type="button" id="chatSendBtn" class="chat-send-btn">
                    <i class="bi bi-send"></i>
                </button>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            duration: 800,
            once: true
        });
        window.addEventListener('scroll', function() {
            const nav = document.getElementById('mainNav');
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });
        function closeNavbar() {
            const navbarCollapse = document.getElementById('navbarNav');
            if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                const bsCollapse = new bootstrap.Collapse(navbarCollapse);
                bsCollapse.hide();
            }
        }
        function switchTab(tabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            history.pushState(null, null, url);
            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });
            const selectedTab = document.getElementById(tabId + '-tab');
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            document.querySelectorAll('.nav-link-custom').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('onclick')?.includes(tabId)) {
                    link.classList.add('active');
                }
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => { 
                if (typeof AOS !== 'undefined') {
                    AOS.refresh(); 
                }
            }, 100);
        }
        function switchBusinessSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'business');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }
        function switchJobsSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'jobs');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }
        function switchContactSubTab(subTabId) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'contact');
            url.searchParams.set('sub', subTabId);
            history.pushState(null, null, url);
            location.reload();
        }
        function copyAddress(suffix = '') {
            const currentAddress = document.querySelector('textarea[name="current_address"]');
            const permanentAddress = document.querySelector('textarea[name="permanent_address"]');
            if (currentAddress && permanentAddress) {
                permanentAddress.value = currentAddress.value;
            }
        }
        document.getElementById('downloadVCF')?.addEventListener('click', function() {
            const vcfContent = `BEGIN:VCARD
VERSION:3.0
FN:<?php echo addslashes($site_title); ?>
ORG:<?php echo addslashes($site_title); ?> - <?php echo addslashes($site_tagline); ?>
TEL;TYPE=WORK,VOICE:<?php echo preg_replace('/[^0-9]/', '', $company_phone); ?>
TEL;TYPE=CELL,VOICE:<?php echo preg_replace('/[^0-9]/', '', $company_whatsapp); ?>
EMAIL;TYPE=INTERNET:<?php echo addslashes($company_email); ?>
ADR;TYPE=WORK:<?php echo addslashes($company_address); ?>;Rewa;Madhya Pradesh;486001;India
URL:https://hidk.in/
NOTE:Workforce solutions for everyday help. Working hours: <?php echo addslashes($company_hours); ?>. Emergency services available.
END:VCARD`;
            const blob = new Blob([vcfContent], {type: 'text/vcard'});
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'DK Associates Contact.vcf';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        });
        const chatToggle = document.getElementById('chatToggle');
        const quickContactPopup = document.getElementById('quickContactPopup');
        const chatWidget = document.getElementById('chatWidget');
        const closeChat = document.getElementById('closeChat');
        const openLiveChat = document.getElementById('openLiveChat');
        const endChatBtn = document.getElementById('endChatBtn');
        const chatSendBtn = document.getElementById('chatSendBtn');
        const chatInput = document.getElementById('chatInput');
        const chatMessages = document.getElementById('chatMessages');
        const chatInitForm = document.getElementById('chatInitForm');
        const chatMessagesContainer = document.getElementById('chatMessagesContainer');
        const chatInitFormElement = document.getElementById('chatInitFormElement');
        let chatPollInterval = null;
        let lastMessageId = 0;
        let currentSessionId = null;
        chatToggle?.addEventListener('click', function() {
            quickContactPopup?.classList.toggle('show');
        });
        openLiveChat?.addEventListener('click', function(e) {
            e.preventDefault();
            quickContactPopup?.classList.remove('show');
            chatWidget?.classList.add('show');
        });
        closeChat?.addEventListener('click', function() {
            chatWidget?.classList.remove('show');
        });
        endChatBtn?.addEventListener('click', function() {
            if (confirm('Are you sure you want to end this chat session?')) {
                terminateChatSession();
            }
        });
        function terminateChatSession() {
            if (currentSessionId) {
                fetch('admin.php?action=guest_chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'terminate_session', 
                        session_id: currentSessionId 
                    })
                }).catch(() => {});
                currentSessionId = null;
                if (chatPollInterval) {
                    clearInterval(chatPollInterval);
                    chatPollInterval = null;
                }
            }
            chatWidget?.classList.remove('show');
            if (chatInitForm) chatInitForm.style.display = 'block';
            if (chatMessagesContainer) chatMessagesContainer.style.display = 'none';
            if (chatMessages) {
                chatMessages.innerHTML = `
                    <div class="chat-message admin">
                        <div class="message-bubble">Welcome! How can we help you today?</div>
                        <div class="message-time">Just now</div>
                    </div>`;
            }
            if (endChatBtn) endChatBtn.style.display = 'none';
            if (chatInitFormElement) chatInitFormElement.reset();
        }
        if (chatInitFormElement) {
            chatInitFormElement.addEventListener('submit', function(e) {
                e.preventDefault();
                const guestName = this.querySelector('[name="guest_name"]').value.trim();
                const reason = this.querySelector('[name="contact_reason"]').value;
                if (!guestName || !reason) return;
                const sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2,8);
                currentSessionId = sessionId;
                chatInitForm.style.display = 'none';
                chatMessagesContainer.style.display = 'block';
                endChatBtn.style.display = 'inline-flex';
                const email = this.querySelector('[name="guest_email"]').value.trim();
                const phone = this.querySelector('[name="guest_phone"]').value.trim();
                fetch('admin.php?action=guest_chat', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'start_session',
                        session_id: sessionId,
                        guest_name: guestName,
                        guest_email: email,
                        guest_phone: phone,
                        contact_reason: reason
                    })
                }).then(r => r.json()).then(data => {
                    if (data.success && data.welcome_message) {
                        chatMessages.innerHTML = `
                            <div class="chat-message admin">
                                <div class="message-bubble">${data.welcome_message.replace(/</g,'&lt;')}</div>
                                <div class="message-time">Just now</div>
                            </div>`;
                    }
                }).catch(() => {});
                                startChatPolling(sessionId);
            });
        }
        function startChatPolling(sessionId) {
            if (chatPollInterval) clearInterval(chatPollInterval);
            chatPollInterval = setInterval(function() {
                fetch('admin.php?action=guest_chat&action=get_messages&session_id=${encodeURIComponent(sessionId)}&since_id=${lastMessageId}')
                    .then(r => r.json())
                    .then(data => {
                        if (!data.messages) return;
                        data.messages.forEach(msg => {
                            if (msg.id > lastMessageId) lastMessageId = msg.id;
                            if (msg.sender_type === 'admin' || msg.sender_type === 'system') {
                                const messageDiv = document.createElement('div');
                                messageDiv.className = 'chat-message admin';
                                messageDiv.innerHTML = `
                                    <div class="message-bubble">${msg.message.replace(/</g,'&lt;')}</div>
                                    <div class="message-time">${msg.time || ''}</div>
                                `;
                                chatMessages.appendChild(messageDiv);
                                chatMessages.scrollTop = chatMessages.scrollHeight;
                            }
                        });
                        if (data.session_status === 'terminated') {
                            clearInterval(chatPollInterval);
                            chatPollInterval = null;
                            const messageDiv = document.createElement('div');
                            messageDiv.className = 'chat-message admin';
                            messageDiv.innerHTML = `<div class="message-bubble" style="background:#fee2e2;color:#991b1b;">This chat session has been ended by an agent.</div>`;
                            chatMessages.appendChild(messageDiv);
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                            currentSessionId = null;
                            endChatBtn.style.display = 'none';
                        }
                    }).catch(() => {});
            }, 2000);
        }
        function sendMessage() {
            const msg = chatInput.value.trim();
            if (!msg || !currentSessionId) return;
            const time = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-message guest';
            messageDiv.innerHTML = `
                <div class="message-bubble">${msg.replace(/</g,'&lt;')}</div>
                <div class="message-time">${time}</div>
            `;
            chatMessages.appendChild(messageDiv);
            chatInput.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;
            fetch('admin.php?action=guest_chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'send_message', 
                    session_id: currentSessionId, 
                    message: msg 
                })
            }).catch(() => {});
        }
        if (chatSendBtn) {
            chatSendBtn.addEventListener('click', sendMessage);
        }
        if (chatInput) {
                            chatInput.addEventListener('input', function() {
                    if (currentSessionId) {
                        fetch('admin.php?action=guest_chat', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'typing', session_id: currentSessionId, sender_type: 'guest' })
                        }).catch(() => {});
                    }
                });
                chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }
        document.addEventListener('click', function(e) {
            if (chatToggle && quickContactPopup && chatWidget) {
                if (!chatToggle.contains(e.target) && !quickContactPopup.contains(e.target) && !chatWidget.contains(e.target)) {
                    quickContactPopup.classList.remove('show');
                }
            }
        });
        const desiredPost = document.querySelector('select[name="desired_post"]');
        if (desiredPost) {
            desiredPost.addEventListener('change', function() {
                const otherContainer = document.getElementById('other_post_container');
                if (otherContainer) {
                    otherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
                }
            });
        }
        const postApplied = document.getElementById('post_applied');
        if (postApplied) {
            postApplied.addEventListener('change', function() {
                const otherContainer = document.getElementById('other_position_container');
                if (otherContainer) {
                    otherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
                }
            });
        }
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        if (tabParam && document.getElementById(tabParam + '-tab')) {
            switchTab(tabParam);
        } else {
            switchTab('home');
        }
    </script>
    <script src="functions.js"></script>
</body>
</html>
<?php $db->close(); ?>