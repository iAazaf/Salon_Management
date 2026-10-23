<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$headerOnly = $headerOnly ?? false;
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if (in_array(basename($scriptDirectory), ['admin', 'customer', 'stylist'], true)) {
    $scriptDirectory = dirname($scriptDirectory);
}
$scriptDirectory = str_replace('\\', '/', $scriptDirectory);
$appBasePath = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');

// Calculate unread notifications if logged in
$navUnreadCount = 0;
if (!empty($_SESSION['user_id'])) {
    if (!isset($pdo)) {
        @require_once __DIR__ . '/../config/database.php';
    }
    if (isset($pdo)) {
        try {
            $stNav = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stNav->execute([(int)$_SESSION['user_id']]);
            $navUnreadCount = (int)$stNav->fetchColumn();
        } catch (Throwable $e) {
            $navUnreadCount = 0;
        }
    }
}
?>
<?php if (!$headerOnly): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' | ' : ''; ?>Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Custom CSS (Modular) -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/variables.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/home-enhance.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/auth.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/about.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/services.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/css/notifications.css">
</head>
<body data-base-path="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-elegant sticky-top">
    <div class="container">
         <a class="navbar-brand" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/index.php">
             <img src="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/assets/img/logo.png" class="logo" alt="Elegance Salon">
        <span>ELEGANCE SALON</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'home' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'about' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/about.php">About</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'services' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/services.php">Services</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'book' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/book.php">Book</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'contact' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/contact.php">Contact</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($activePage ?? '') === 'feedback' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/feedback.php">Feedback</a></li>
            </ul>
<div class="d-flex align-items-center gap-2">
    <?php if (isset($_SESSION['user_id'])): ?>
        <?php 
        $currentRole = $_SESSION['user_role'] ?? '';
        ?>

        <!-- Notification Bell with Dropdown -->
        <div class="dropdown d-inline-block">
            <button class="btn btn-notification-bell" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-role="notification-bell" title="Notifications">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" data-role="notification-badge" data-count="<?php echo $navUnreadCount; ?>">
                    <?php echo $navUnreadCount > 0 ? ($navUnreadCount > 99 ? '99+' : $navUnreadCount) : ''; ?>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown-menu">
                <div class="notification-dropdown-header">
                    <h6><i class="fas fa-bell text-gold me-2"></i>Notifications</h6>
                    <button type="button" class="notification-mark-all-btn" data-role="mark-all-read">Mark all as read</button>
                </div>
                <div class="notification-dropdown-body" data-role="notification-list">
                    <div class="text-center py-4 text-muted small">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
                <div class="notification-dropdown-footer">
                    <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/<?php echo in_array($currentRole, ['admin', 'receptionist'], true) ? 'admin/notifications.php' : 'notifications.php'; ?>">
                        View all notifications <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($currentRole === 'stylist'): ?>
            <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/stylist/dashboard.php" class="btn btn-nav">Stylist Portal</a>
        <?php elseif (in_array($currentRole, ['admin', 'receptionist'], true)): ?>
            <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/admin/dashboard.php" class="btn btn-nav">Dashboard</a>
        <?php else: ?>
            <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/customer/index.php" class="btn btn-nav">My Account</a>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/logout.php" class="btn btn-nav-outline">Logout</a>
    <?php else: ?>
        <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/login.php" class="btn btn-nav-outline">Log In</a>
        <a href="<?php echo htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8'); ?>/book.php" class="btn btn-nav">Book Appointment</a>
    <?php endif; ?>
</div>
        </div>
    </div>
</nav>
