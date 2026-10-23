<?php
// =====================================================
// Elegance Salon Management System
// Automatic Appointment Reminders Task Runner
// =====================================================

// Check execution mode: CLI or Web
$isCli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';
$mailConfig = require __DIR__ . '/../config/mail.php';

// If called via Web, enforce authentication or secret token check
if (!$isCli) {
    $providedToken = $_GET['token'] ?? '';
    $expectedToken = $mailConfig['cron_token'] ?? '';
    $isAdmin = (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'receptionist'], true));

    if (!$isAdmin && ($providedToken === '' || !hash_equals($expectedToken, $providedToken))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden: Invalid security token or insufficient permissions to execute reminders processor." . PHP_EOL;
        exit;
    }
}

// Run the reminder processor and periodic system monitors
$start = microtime(true);
$reminderResults  = process_appointment_reminders($pdo);
$inventoryResults = check_low_inventory($pdo);
$paymentResults   = check_pending_payments($pdo);
$duration = round((microtime(true) - $start) * 1000, 2);

$allErrors = array_merge(
    $reminderResults['errors'] ?? [],
    $inventoryResults['errors'] ?? [],
    $paymentResults['errors'] ?? []
);

if ($isCli) {
    echo "====================================================\n";
    echo "ELEGANCE SALON — AUTOMATED NOTIFICATIONS RUNNER\n";
    echo "Executed at: " . date('Y-m-d H:i:s') . "\n";
    echo "Duration:    " . $duration . " ms\n";
    echo "----------------------------------------------------\n";
    echo "1. APPOINTMENT REMINDERS:\n";
    echo "   - 24-Hour Reminders Dispatched: " . $reminderResults['reminders_24h'] . "\n";
    echo "   - 2-Hour Reminders Dispatched:  " . $reminderResults['reminders_2h'] . "\n";
    echo "2. LOW INVENTORY MONITOR:\n";
    echo "   - Low Stock Products Found:     " . $inventoryResults['checked'] . "\n";
    echo "   - New Low Stock Alerts Created: " . $inventoryResults['alerts_sent'] . "\n";
    echo "3. PENDING PAYMENTS MONITOR:\n";
    echo "   - Pending Payments Found:       " . $paymentResults['checked'] . "\n";
    echo "   - New Payment Alerts Created:   " . $paymentResults['alerts_sent'] . "\n";
    echo "----------------------------------------------------\n";
    if (!empty($allErrors)) {
        echo "Errors encountered (" . count($allErrors) . "):\n";
        foreach ($allErrors as $err) {
            echo " - " . $err . "\n";
        }
    } else {
        echo "Status: SUCCESS (All tasks executed without errors)\n";
    }
    echo "====================================================\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'                  => empty($allErrors),
        'timestamp'           => date('Y-m-d H:i:s'),
        'duration_ms'         => $duration,
        'appointment_reminders' => [
            'reminders_24h'   => $reminderResults['reminders_24h'],
            'reminders_2h'    => $reminderResults['reminders_2h'],
        ],
        'low_inventory'       => [
            'products_scanned'=> $inventoryResults['checked'],
            'alerts_created'  => $inventoryResults['alerts_sent'],
        ],
        'pending_payments'    => [
            'payments_scanned'=> $paymentResults['checked'],
            'alerts_created'  => $paymentResults['alerts_sent'],
        ],
        'errors'              => $allErrors,
        'log_path'            => 'logs/reminders.log'
    ], JSON_PRETTY_PRINT);
}
