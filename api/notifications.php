<?php
// =====================================================
// Elegance Salon Management System
// Notifications AJAX API Endpoint
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_unread_count';

// Validate CSRF token for state-changing requests
if (in_array($action, ['mark_read', 'mark_all_read'], true)) {
    $submittedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionCsrf   = $_SESSION['csrf_token'] ?? '';

    if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired security token (CSRF).']);
        exit;
    }
}

switch ($action) {
    case 'get_unread_count':
        $count = get_unread_notification_count($pdo, $userId);
        echo json_encode([
            'ok' => true,
            'unread_count' => $count
        ]);
        break;

    case 'get_latest':
        $limit = min((int)($_GET['limit'] ?? 6), 15);
        $notifications = get_user_notifications($pdo, $userId, $limit);
        $count = get_unread_notification_count($pdo, $userId);

        $formatted = [];
        foreach ($notifications as $n) {
            $formatted[] = [
                'id'         => (int)$n['id'],
                'title'      => htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'),
                'message'    => htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8'),
                'type'       => $n['type'],
                'link'       => !empty($n['link']) ? $n['link'] : null,
                'is_read'    => (int)$n['is_read'],
                'time_ago'   => format_time_ago($n['created_at']),
                'created_at' => $n['created_at']
            ];
        }

        echo json_encode([
            'ok'            => true,
            'unread_count'  => $count,
            'notifications' => $formatted
        ]);
        break;

    case 'mark_read':
        $notificationId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($notificationId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid notification ID.']);
            exit;
        }

        $success = mark_notification_as_read($pdo, $notificationId, $userId);
        $count   = get_unread_notification_count($pdo, $userId);

        echo json_encode([
            'ok'           => $success,
            'unread_count' => $count
        ]);
        break;

    case 'mark_all_read':
        $success = mark_all_notifications_as_read($pdo, $userId);
        echo json_encode([
            'ok'           => $success,
            'unread_count' => 0
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        break;
}
