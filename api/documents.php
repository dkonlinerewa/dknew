<?php
// ===== api/documents.php =====
require_once '../config.php';
require_once '../vendor/autoload.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$db = db();
$user_id = $_SESSION['admin_id'];
$type = $_GET['type'] ?? '';

// Get user data
$user = $db->querySingle("SELECT * FROM admin_users WHERE id = $user_id", true);

// Get default template for this document type
$valid_types = ['offer_letter', 'joining_letter', 'salary_slip', 'annual_statement', 'profile_summary', 'experience_certificate'];
$template_type = in_array($type, $valid_types) ? $type : 'custom';

$template = $db->querySingle("SELECT * FROM templates WHERE template_type = '$template_type' AND is_default = 1", true);

if (!$template) {
    // Fallback to default content
    $template = [
        'content' => getDefaultTemplate($type, $user),
        'css' => ''
    ];
}

// Generate document based on type
switch ($type) {
    case 'salary_slip':
        generateSalarySlip($db, $user, $template);
        break;
    case 'annual_statement':
        generateAnnualStatement($db, $user, $template);
        break;
    case 'offer_letter':
    case 'joining_letter':
    case 'experience_certificate':
        generateLetter($user, $type, $template);
        break;
    case 'profile_summary':
        generateProfileSummary($user, $template);
        break;
    default:
        generateCustomDocument($user, $template);
}

function getDefaultTemplate($type, $user)
{
    $templates = [
        'offer_letter' => '<h1>Offer Letter</h1><p>Dear {full_name},</p><p>We are pleased to offer you the position of {role} at D K Associates.</p>',
        'joining_letter' => '<h1>Joining Letter</h1><p>Dear {full_name},</p><p>This confirms your joining as {role} effective from {joining_date}.</p>',
        'salary_slip' => '<h1>Salary Slip</h1><p>Employee: {full_name}</p><p>Month: {month}</p>',
        'profile_summary' => '<h1>Employee Profile</h1><p>Name: {full_name}</p><p>Employee Code: {employee_code}</p>'
    ];

    return $templates[$type] ?? '<h1>Document</h1><p>Generated for {full_name}</p>';
}

function generateSalarySlip($db, $user, $template)
{
    $month = $_GET['month'] ?? date('Y-m');
    $year = substr($month, 0, 4);
    $month_name = date('F Y', strtotime($month . '-01'));

    // Get attendance for the month
    $attendance = $db->querySingle("SELECT COUNT(*) as days FROM attendance WHERE user_id = {$user['id']} AND strftime('%Y-%m', date) = '$month' AND status IN ('ontime', 'late')");
    $attended_days = $attendance['days'] ?? 0;

    // Get salary record
    $salary = $db->querySingle("SELECT * FROM salary_records WHERE user_id = {$user['id']} AND month = '$month'", true);

    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{month}', '{year}', '{attended_days}', '{basic_salary}', '{allowances}', '{deductions}', '{net_salary}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        $month_name,
        $year,
        $attended_days,
        $user['salary_basic'] ?? 0,
        $user['salary_allowance'] ?? 0,
        $user['salary_deductions'] ?? 0,
        ($user['salary_basic'] ?? 0) + ($user['salary_allowance'] ?? 0) - ($user['salary_deductions'] ?? 0)
    ],
        $template['content']
    );

    generatePDF($content, $template['css'], "Salary_Slip_{$user['employee_code']}_{$month}.pdf");
}

function generateAnnualStatement($db, $user, $template)
{
    $year = $_GET['year'] ?? date('Y');

    // Get yearly salary summary
    $salary_data = $db->query("SELECT * FROM salary_records WHERE user_id = {$user['id']} AND strftime('%Y', month) = '$year'");

    $total_earned = 0;
    $total_deductions = 0;
    $months_data = '';

    while ($row = $salary_data->fetchArray(SQLITE3_ASSOC)) {
        $total_earned += $row['final_salary'] ?? 0;
        $total_deductions += $row['deductions'] ?? 0;
        $months_data .= "<tr><td>" . date('F Y', strtotime($row['month'] . '-01')) . "</td><td>{$row['final_salary']}</td></tr>";
    }

    $content = str_replace(
    ['{full_name}', '{employee_code}', '{year}', '{total_earned}', '{total_deductions}', '{months_data}'],
    [$user['full_name'], $user['employee_code'], $year, $total_earned, $total_deductions, $months_data],
        $template['content']
    );

    generatePDF($content, $template['css'], "Annual_Statement_{$user['employee_code']}_{$year}.pdf");
}

function generateLetter($user, $type, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{date}', '{joining_date}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        date('d F Y'),
        date('d F Y', strtotime($user['created_at']))
    ],
        $template['content']
    );

    generatePDF($content, $template['css'], ucfirst($type) . "_{$user['employee_code']}.pdf");
}

function generateProfileSummary($user, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{email}', '{phone}', '{department}', '{reporting_head}', '{blood_group}', '{emergency_contact}', '{joining_date}'],
    [
        $user['full_name'],
        $user['role'],
        $user['employee_code'],
        $user['email'],
        $user['phone'],
        $user['department'],
        getUserName($user['reporting_head']),
        $user['blood_group'],
        $user['emergency_contact'],
        date('d F Y', strtotime($user['created_at']))
    ],
        $template['content']
    );

    generatePDF($content, $template['css'], "Profile_{$user['employee_code']}.pdf");
}

function generateCustomDocument($user, $template)
{
    $content = str_replace(
    ['{full_name}', '{role}', '{employee_code}', '{email}', '{phone}', '{date}'],
    [$user['full_name'], $user['role'], $user['employee_code'], $user['email'], $user['phone'], date('d F Y')],
        $template['content']
    );

    generatePDF($content, $template['css'], "Document_{$user['employee_code']}.pdf");
}

function generatePDF($content, $css, $filename)
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    $pdf->SetCreator('D K Associates');
    $pdf->SetAuthor('D K Associates');
    $pdf->SetTitle($filename);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $pdf->AddPage();

    $html = "<html><head><style>{$css}</style></head><body>{$content}</body></html>";

    $pdf->writeHTML($html, true, false, true, false, '');

    ob_end_clean();
    $pdf->Output($filename, 'D');
}

function getUserName($user_id)
{
    global $db;
    if (!$user_id)
        return 'Not Assigned';
    $user = $db->querySingle("SELECT full_name FROM admin_users WHERE id = $user_id", true);
    return $user['full_name'] ?? 'Not Assigned';
}
?>