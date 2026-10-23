<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Access denied: Please log in to download calendar appointments.");
}

$apptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($apptId <= 0) {
    http_response_code(400);
    die("Invalid appointment ID.");
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'client';

// Fetch appointment details
$stmt = $pdo->prepare("
    SELECT a.*, 
           c.name AS client_name, c.email AS client_email, c.phone AS client_phone,
           s.name AS service_name, s.duration AS service_duration, s.price AS service_price,
           st_u.name AS stylist_name,
           u.id AS client_user_id
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    LEFT JOIN users u ON (u.email = c.email OR u.phone = c.phone)
    JOIN services s ON a.service_id = s.id
    LEFT JOIN staff st ON a.staff_id = st.id
    LEFT JOIN users st_u ON st.user_id = st_u.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([$apptId]);
$appt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appt) {
    http_response_code(404);
    die("Appointment record not found.");
}

// Authorization check: admin/receptionist can export any; stylist can export their own; client can export their own
if ($userRole === 'client') {
    $sessionEmail = $_SESSION['user_email'] ?? '';
    $sessionPhone = $_SESSION['user_phone'] ?? '';
    $owns = false;
    if (!empty($sessionEmail) && strcasecmp($sessionEmail, $appt['client_email'] ?? '') === 0) {
        $owns = true;
    }
    if (!empty($sessionPhone) && $sessionPhone === ($appt['client_phone'] ?? '')) {
        $owns = true;
    }
    if ((int)($appt['client_user_id'] ?? 0) === $userId) {
        $owns = true;
    }
    if (!$owns) {
        http_response_code(403);
        die("Unauthorized access to this appointment calendar file.");
    }
}

// Compute start and end datetime
$startDateTimeStr = $appt['appointment_date'] . ' ' . $appt['appointment_time'];
$startTimestamp   = strtotime($startDateTimeStr);
$durationMins     = !empty($appt['service_duration']) ? (int)$appt['service_duration'] : 60;
$endTimestamp     = $startTimestamp + ($durationMins * 60);

$dtStartUtc = gmdate('Ymd\THis\Z', $startTimestamp);
$dtEndUtc   = gmdate('Ymd\THis\Z', $endTimestamp);
$dtStamp    = gmdate('Ymd\THis\Z');

$stylist = !empty($appt['stylist_name']) ? $appt['stylist_name'] : 'Assigned Stylist';
$service = $appt['service_name'];
$location = "123 Luxury Lane, Clifton, Karachi, Pakistan";
$summary = "Elegance Salon: " . $service . " (" . $stylist . ")";
$description = "Appointment for " . $service . "\\nStylist: " . $stylist . "\\nClient: " . $appt['client_name'] . "\\nStatus: " . ucfirst($appt['status']) . "\\nLocation: Elegance Salon Clifton Karachi\\nContact: +92 330 9066929";

$uid = "elegance-appt-" . $appt['id'] . "-" . md5($startDateTimeStr) . "@elegancesalon.com";

// Output RFC 5545 iCalendar format
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="elegance-appointment-' . $appt['id'] . '.ics"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$ics = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//Elegance Salon//Appointment Scheduler//EN\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "METHOD:PUBLISH\r\n";
$ics .= "BEGIN:VEVENT\r\n";
$ics .= "UID:" . $uid . "\r\n";
$ics .= "DTSTAMP:" . $dtStamp . "\r\n";
$ics .= "DTSTART:" . $dtStartUtc . "\r\n";
$ics .= "DTEND:" . $dtEndUtc . "\r\n";
$ics .= "SUMMARY:" . addcslashes($summary, ",;") . "\r\n";
$ics .= "DESCRIPTION:" . $description . "\r\n";
$ics .= "LOCATION:" . addcslashes($location, ",;") . "\r\n";
$ics .= "STATUS:CONFIRMED\r\n";
$ics .= "END:VEVENT\r\n";
$ics .= "END:VCALENDAR\r\n";

echo $ics;
exit;
