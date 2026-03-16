<?php
// ===== api/reports.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$user_id = $_SESSION['admin_id'];
$user_role = $_SESSION['admin_role'];

// Ensure reports table exists
$db->exec("CREATE TABLE IF NOT EXISTS daily_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    report_date DATE NOT NULL,
    content TEXT NOT NULL,
    tasks_completed TEXT,
    blockers TEXT,
    mood INTEGER DEFAULT 3,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, report_date)
)");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date  = $_GET['date'] ?? date('Y-m-d');
    $month = $_GET['month'] ?? date('Y-m');
    $target_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

    // Build user visibility scope
    if (in_array($user_role, ['admin', 'manager'])) {
        // Admin/Manager see all under them
        $where = "1=1";
    } else {
        // Staff/Lead only see their own + direct reports
        $directReports = [];
        $res = $db->query("SELECT id FROM admin_users WHERE reporting_head = $user_id");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $directReports[] = $row['id'];
        }
        $directReports[] = $user_id;
        $ids = implode(',', $directReports);
        $where = "r.user_id IN ($ids)";
    }

    if ($target_user) {
        $where .= " AND r.user_id = $target_user";
    }

    if (isset($_GET['date'])) {
        $safeDate = SQLite3::escapeString($date);
        $where .= " AND r.report_date = '$safeDate'";
    } else {
        $safeMonth = SQLite3::escapeString($month);
        $where .= " AND strftime('%Y-%m', r.report_date) = '$safeMonth'";
    }

    $result = $db->query("SELECT r.*, u.full_name, u.role, u.department 
                          FROM daily_reports r 
                          JOIN admin_users u ON r.user_id = u.id 
                          WHERE $where 
                          ORDER BY r.report_date DESC, u.full_name ASC");
    $reports = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $reports[] = $row;
    }
    echo json_encode($reports);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $report_date    = $data['report_date'] ?? date('Y-m-d');
    $content        = $data['content'] ?? '';
    $tasks_completed = $data['tasks_completed'] ?? '';
    $blockers       = $data['blockers'] ?? '';
    $mood           = intval($data['mood'] ?? 3);

    if (empty($content)) {
        echo json_encode(['error' => 'Report content is required']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO daily_reports 
        (user_id, report_date, content, tasks_completed, blockers, mood, created_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $report_date);
    $stmt->bindValue(3, $content);
    $stmt->bindValue(4, $tasks_completed);
    $stmt->bindValue(5, $blockers);
    $stmt->bindValue(6, $mood);
    $stmt->execute();

    logActivity('daily_report', "Submitted daily report for $report_date");
    echo json_encode(['success' => true]);
}
?>