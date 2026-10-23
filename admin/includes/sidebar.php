<?php
if (!isset($unreadAdminNotifs) && isset($pdo) && !empty($_SESSION['user_id'])) {
    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stCount->execute([(int)$_SESSION['user_id']]);
        $unreadAdminNotifs = (int)$stCount->fetchColumn();
    } catch (Throwable $e) {
        $unreadAdminNotifs = 0;
    }
}
$currentAdminRole = $_SESSION['user_role'] ?? '';
?>
<div class="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between">
        <div>
            <h4><img src="../assets/img/logo.png" class="salon-mark-icon me-2" alt="" aria-hidden="true">ELEGANCE</h4>
            <small><?php echo strtoupper($currentAdminRole); ?> PANEL</small>
        </div>
        <button type="button" class="btn btn-sm btn-link text-gold d-lg-none p-0 text-decoration-none" id="sidebarCloseBtn" aria-label="Close Sidebar" style="font-size: 1.25rem;">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php" class="<?php echo ($adminPage ?? '') === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
        <li><a href="appointments.php" class="<?php echo ($adminPage ?? '') === 'appointments' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Appointments</a></li>
        <li>
            <a href="notifications.php" class="<?php echo ($adminPage ?? '') === 'notifications' ? 'active' : ''; ?> d-flex align-items-center justify-content-between">
                <span><i class="fas fa-bell"></i> Notifications</span>
                <?php if (!empty($unreadAdminNotifs)): ?>
                    <span class="badge bg-danger rounded-pill" style="font-size: 0.68rem;"><?php echo $unreadAdminNotifs; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li><a href="clients.php" class="<?php echo ($adminPage ?? '') === 'clients' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Clients</a></li>
            <li><a href="services.php" class="<?php echo ($adminPage ?? '') === 'services' ? 'active' : ''; ?>"><i class="fas fa-spa"></i> Services</a></li>
     <li><a href="inventory.php" class="<?php echo ($adminPage ?? '') === 'inventory' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> Inventory</a></li>
        <?php if ($currentAdminRole === 'admin'): ?>
            <li><a href="staff.php" class="<?php echo ($adminPage ?? '') === 'staff' ? 'active' : ''; ?>"><i class="fas fa-user-tie"></i> Staff</a></li>
        <?php endif; ?>
        <li><a href="payments.php" class="<?php echo ($adminPage ?? '') === 'payments' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i> Payments</a></li>
        <?php if ($currentAdminRole === 'admin'): ?>
            <li><a href="reports.php" class="<?php echo ($adminPage ?? '') === 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="email_settings.php" class="<?php echo ($adminPage ?? '') === 'email_settings' ? 'active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Email Notifications</a></li>
        <?php endif; ?>
        <li><a href="feedback.php" class="<?php echo ($adminPage ?? '') === 'feedback' ? 'active' : ''; ?>"><i class="fas fa-comments"></i> Feedback</a></li>
        <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
    <div class="px-3 mt-4">
        <div class="p-3 rounded" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(232,197,71,0.15); font-size: 0.8rem; color: var(--cream);">
            <i class="fas fa-quote-left me-1 text-gold"></i>
            “Beauty is not just what you see, it's how you make people feel.”
        </div>
    </div>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('sidebarToggle');
    var closeBtn = document.getElementById('sidebarCloseBtn');
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('show');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('show');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('show')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            closeSidebar();
        });
    }

    document.querySelectorAll('.sidebar-menu a').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                closeSidebar();
            }
        });
    });
});
</script>
