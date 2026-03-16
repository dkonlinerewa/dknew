<?php
// ===== api/recruitment.php =====
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();

// Get recruitment pipeline data
$pipeline = [
    'applications' => '',
    'screening' => '',
    'interviews' => '',
    'offers' => '',
    'onboarding' => ''
];

// Applications (new)
$result = $db->query("SELECT * FROM applications WHERE status = 'new' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['applications'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-gray-400'>{$row['created_at']}</span>
                <button onclick='moveToScreening({$row['id']})' class='text-xs text-blue-600'>Screen</button>
            </div>
        </div>";
}

// Screening
$result = $db->query("SELECT * FROM applications WHERE status = 'screening' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['screening'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-gray-400'>Score: {$row['screening_score']}</span>
                <button onclick='scheduleInterview({$row['id']})' class='text-xs text-green-600'>Interview</button>
            </div>
        </div>";
}

// Interviews
$result = $db->query("SELECT * FROM applications WHERE status = 'interview' AND interview_date IS NOT NULL ORDER BY interview_date ASC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['interviews'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <p class='text-xs text-gray-400'>" . date('d M Y', strtotime($row['interview_date'])) . "</p>
            <div class='flex justify-between mt-1'>
                <button onclick='addFeedback({$row['id']})' class='text-xs text-purple-600'>Feedback</button>
                <button onclick='makeOffer({$row['id']})' class='text-xs text-green-600'>Offer</button>
            </div>
        </div>";
}

// Offers
$result = $db->query("SELECT * FROM applications WHERE status = 'offer' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['offers'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <div class='flex justify-between mt-1'>
                <span class='text-xs text-green-600'>Offer Sent</span>
                <button onclick='startOnboarding({$row['id']})' class='text-xs text-blue-600'>Onboard</button>
            </div>
        </div>";
}

// Onboarding
$result = $db->query("SELECT * FROM applications WHERE status = 'onboarding' ORDER BY created_at DESC LIMIT 5");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $pipeline['onboarding'] .= "
        <div class='bg-white p-2 rounded shadow-sm mb-2'>
            <p class='font-medium text-sm'>{$row['applicant_name']}</p>
            <p class='text-xs text-gray-600'>{$row['position']}</p>
            <p class='text-xs text-gray-400'>{$row['onboarding_status']}</p>
        </div>";
}

echo json_encode($pipeline);