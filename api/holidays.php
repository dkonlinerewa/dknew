<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

// Initialize table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    FOREIGN KEY(created_by) REFERENCES admin_users(id)
)");

// Check if this is an event action request
$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? 'holiday';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Return HTML rows for both holidays and events
        $html = '';

        // Get Holidays
        $stmtHolidays = $db->prepare("SELECT id, 'holiday' as type, date as start_date, NULL as end_date, 'holiday' as event_type, description, created_by 
                                      FROM holidays");
        $resHolidays = $stmtHolidays->execute();
        $items = [];
        while ($row = $resHolidays->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }

        // Get Events
        $stmtEvents = $db->prepare("SELECT id, 'event' as type, start_date, end_date, event_type, title || ' - ' || description as description, created_by 
                                    FROM events");
        $resEvents = $stmtEvents->execute();
        while ($row = $resEvents->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }

        // Sort items by date descending
        usort($items, function($a, $b) {
            return strtotime($b['start_date']) - strtotime($a['start_date']);
        });

        foreach ($items as $row) {
            $formattedDate = date('d M Y', strtotime($row['start_date']));
            if (!empty($row['end_date'])) {
                $formattedDate .= ' to ' . date('d M Y', strtotime($row['end_date']));
            }
            
            $badgeColor = 'bg-gray-100 text-gray-800';
            if ($row['event_type'] === 'holiday') {
                $badgeColor = 'bg-blue-100 text-blue-800';
            } elseif ($row['event_type'] === 'weekly-off') {
                $badgeColor = 'bg-yellow-100 text-yellow-800';
            } elseif ($row['event_type'] === 'general') {
                $badgeColor = 'bg-green-100 text-green-800';
            }

            $badge = '<span class="px-2 py-0.5 rounded text-xs font-semibold ' . $badgeColor . '">' . ucfirst($row['event_type']) . '</span>';
            
            $html .= '<tr class="border-b hover:bg-gray-50">';
            $html .= '<td class="p-2 font-medium">' . $formattedDate . ' ' . $badge . '</td>';
            $html .= '<td class="p-2">' . htmlspecialchars($row['description']) . '</td>';
            $html .= '<td class="p-2">';
            $html .= '<button @click="deleteHoliday(' . $row['id'] . ', \'' . $row['type'] . '\')" class="text-red-500 hover:text-red-700 ml-2" title="Delete"><i class="fas fa-trash"></i></button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        
        if (empty($html)) {
            $html = '<tr><td colspan="3" class="p-4 text-center text-gray-500">No holidays or events declared</td></tr>';
        }
        
        header('Content-Type: text/html');
        echo $html;
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'event') {
            if (empty($data['title']) || empty($data['start_date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Title, Start Date, and Description are required']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO events (title, description, event_type, start_date, end_date, target_type, created_by) VALUES (:title, :description, :event_type, :start_date, :end_date, :target_type, :user)");
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':event_type', $data['event_type'], SQLITE3_TEXT);
            $stmt->bindValue(':start_date', $data['start_date'], SQLITE3_TEXT);
            $stmt->bindValue(':end_date', $data['end_date'] ?: null, SQLITE3_TEXT);
            $stmt->bindValue(':target_type', $data['target_type'] ?? 'all', SQLITE3_TEXT);
            $stmt->bindValue(':user', $_SESSION['admin_id'], SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
            }

        } else {
            // Holiday POST
            if (empty($data['date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Date and description are required']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO holidays (date, description, created_by) VALUES (:date, :desc, :user)");
            $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':user', $_SESSION['admin_id'], SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
            }
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            exit;
        }

        if ($type === 'event') {
            if (empty($data['title']) || empty($data['start_date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Title, Start Date, and Description are required']);
                exit;
            }

            $stmt = $db->prepare("UPDATE events SET title = :title, description = :description, event_type = :event_type, start_date = :start_date, end_date = :end_date, target_type = :target_type WHERE id = :id");
            $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':event_type', $data['event_type'], SQLITE3_TEXT);
            $stmt->bindValue(':start_date', $data['start_date'], SQLITE3_TEXT);
            $stmt->bindValue(':end_date', $data['end_date'] ?: null, SQLITE3_TEXT);
            $stmt->bindValue(':target_type', $data['target_type'] ?? 'all', SQLITE3_TEXT);
            $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
        } else {
            if (empty($data['date']) || empty($data['description'])) {
                echo json_encode(['success' => false, 'error' => 'Date and description are required']);
                exit;
            }

            $stmt = $db->prepare("UPDATE holidays SET date = :date, description = :desc WHERE id = :id");
            $stmt->bindValue(':date', $data['date'], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $data['description'], SQLITE3_TEXT);
            $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
        }
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            exit;
        }

        if ($type === 'event') {
            $stmt = $db->prepare("DELETE FROM events WHERE id = :id");
        } else {
            $stmt = $db->prepare("DELETE FROM holidays WHERE id = :id");
        }
        
        $stmt->bindValue(':id', $_GET['id'], SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $db->lastErrorMsg()]);
        }
        break;
}
