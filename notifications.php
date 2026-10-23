<?php
// =====================================================
// Elegance Salon Management System
// Customer Notification Center
// =====================================================

$pageTitle = "My Notifications";
$activePage = "";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?return_to=notifications.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'client';

// If user is admin/receptionist, route to admin notifications
if (in_array($userRole, ['admin', 'receptionist'], true)) {
    header('Location: admin/notifications.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$successMsg = '';
$errorMsg   = '';

// Handle Actions (Mark All Read / Mark Single Read)
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

require_once __DIR__ . '/includes/header.php';
?>

<main class="container py-5" style="min-height: 75vh;">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">

            <!-- Header Banner -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <div>
                    <h2 class="text-white mb-1" style="font-family: 'Playfair Display', serif;">
                        <i class="fas fa-bell text-gold me-2"></i>Notifications
                    </h2>
                    <p class="text-muted small mb-0">Track your appointment confirmations, updates, and reminders.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-outline-gold btn-sm">
                                <i class="fas fa-check-double me-1"></i> Mark All as Read
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="customer/index.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-calendar-alt me-1"></i> My Appointments
                    </a>
                </div>
            </div>

            <!-- Flash Notices -->
            <?php if ($successMsg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Tabs -->
            <div class="filter-nav mb-4 d-flex gap-2">
                <a href="notifications.php?filter=all" class="filter-pill <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    All <span class="badge-count"><?php echo $totalCount; ?></span>
                </a>
                <a href="notifications.php?filter=unread" class="filter-pill <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                    Unread <span class="badge-count"><?php echo $unreadCount; ?></span>
                </a>
            </div>

            <!-- Notifications List -->
            <div class="card-elegant p-3 p-md-4">
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="far fa-bell-slash fa-3x text-gold mb-3 opacity-50"></i>
                        <h5 class="text-white">No notifications found</h5>
                        <p class="text-muted small mb-0">
                            <?php if ($filter === 'unread'): ?>
                                You have caught up with all your notifications! <a href="notifications.php" class="text-gold">View all</a>.
                            <?php else: ?>
                                You will receive notifications here when you book appointments or when schedules change.
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
                                            <a href="<?php echo htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-gold py-0 px-2" style="font-size: 0.75rem;">
                                                <i class="fas fa-external-link-alt me-1"></i> View Details
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
