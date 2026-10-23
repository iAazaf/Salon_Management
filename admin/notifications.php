<?php
// =====================================================
// Elegance Salon Management System
// Admin Notification Center
// =====================================================

require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'notifications';
$pageTitle = 'Notifications';

$userId = (int)$_SESSION['user_id'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$successMsg = '';
$errorMsg   = '';

// Handle Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $errorMsg = 'Security validation failed (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'mark_all_read') {
            mark_all_notifications_as_read($pdo, $userId);
            $successMsg = 'All notifications marked as read.';
        } elseif ($action === 'mark_read') {
            $notifId = (int)($_POST['id'] ?? 0);
            if ($notifId > 0) {
                mark_notification_as_read($pdo, $notifId, $userId);
                $successMsg = 'Notification marked as read.';
            }
        } elseif ($action === 'run_reminders') {
            // Manual trigger of all system monitors (reminders, low stock, pending payments)
            $remRes = process_appointment_reminders($pdo);
            $invRes = check_low_inventory($pdo);
            $payRes = check_pending_payments($pdo);
            $totalReminders = $remRes['reminders_24h'] + $remRes['reminders_2h'];
            $successMsg = "System monitor completed: {$remRes['reminders_24h']} (24h) & {$remRes['reminders_2h']} (2h) reminders dispatched; {$invRes['alerts_sent']} low-stock alerts and {$payRes['alerts_sent']} pending-payment alerts created.";
            $allErrs = array_merge($remRes['errors'] ?? [], $invRes['errors'] ?? [], $payRes['errors'] ?? []);
            if (!empty($allErrs)) {
                $errorMsg = "Encountered " . count($allErrs) . " issues during processing (see logs/reminders.log).";
            }
        }
    }
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$onlyUnread = ($filter === 'unread') ? true : null;

// Fetch notifications
$notifications = get_user_notifications($pdo, $userId, 50, 0, $onlyUnread);
$unreadCount   = get_unread_notification_count($pdo, $userId);
$totalCount    = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body data-base-path="..">
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-gold btn-sm d-lg-none me-2" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0">Notifications</h5>
                    <small class="text-muted">Real-time alerts for bookings, cancellations &amp; appointment reminders</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <form method="POST" action="" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="run_reminders">
                    <button type="submit" class="btn btn-sm btn-outline-info" title="Manually scan for approaching appointments and dispatch due reminders">
                        <i class="fas fa-sync-alt me-1"></i> Scan &amp; Process Reminders
                    </button>
                </form>

                <?php if ($unreadCount > 0): ?>
                    <form method="POST" action="" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-sm btn-gold">
                            <i class="fas fa-check-double me-1"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>

                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($successMsg): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Pills -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div class="filter-nav mb-0">
                    <a href="notifications.php?filter=all" class="filter-pill <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        All Alerts <span class="badge-count"><?php echo $totalCount; ?></span>
                    </a>
                    <a href="notifications.php?filter=unread" class="filter-pill <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                        Unread <span class="badge-count"><?php echo $unreadCount; ?></span>
                    </a>
                </div>
                <div class="text-muted small">
                    <i class="fas fa-clock me-1 text-gold"></i> Reminders log: <code>logs/reminders.log</code>
                </div>
            </div>

            <!-- Notifications Card -->
            <div class="card-elegant p-3 p-md-4">
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="far fa-bell-slash fa-3x text-gold mb-3 opacity-50"></i>
                        <h5 class="text-white">No notifications</h5>
                        <p class="text-muted small mb-0">
                            <?php if ($filter === 'unread'): ?>
                                All notifications are read. <a href="notifications.php" class="text-gold">View all</a>.
                            <?php else: ?>
                                New bookings, cancellations, and client requests will appear here automatically.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush bg-transparent">
                        <?php foreach ($notifications as $n): ?>
                            <?php
                            $isUnread = !(bool)$n['is_read'];
                            $iconClass = $n['type'] === 'appointment' ? 'fa-calendar-check' :
                                         ($n['type'] === 'inventory' ? 'fa-boxes' :
                                         ($n['type'] === 'payment' ? 'fa-credit-card' : 'fa-bell'));
                            ?>
                            <div class="notification-history-item <?php echo $isUnread ? 'unread' : ''; ?>">
                                <div class="notification-icon-wrap" style="width: 44px; height: 44px; font-size: 1.1rem;">
                                    <i class="fas <?php echo $iconClass; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                        <h6 class="text-white mb-0" style="font-weight: 600;">
                                            <?php echo htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if ($isUnread): ?>
                                                <span class="badge" style="background: rgba(232, 197, 71, 0.25); color: var(--champagne); font-size: 0.7rem; vertical-align: middle;">NEW</span>
                                            <?php endif; ?>
                                        </h6>
                                        <span class="text-gold small" style="white-space: nowrap;">
                                            <i class="far fa-clock me-1"></i><?php echo format_time_ago($n['created_at']); ?>
                                        </span>
                                    </div>
                                    <p class="text-muted small mb-2">
                                        <?php echo htmlspecialchars($n['message'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($n['link'])): ?>
                                            <a href="../<?php echo htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-gold py-0 px-2" style="font-size: 0.75rem;">
                                                <i class="fas fa-arrow-right me-1"></i> View Related
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($isUnread): ?>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                                                <button type="submit" class="btn btn-link text-muted p-0 small text-decoration-none" style="font-size: 0.75rem;">
                                                    <i class="fas fa-check me-1"></i> Mark as read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
