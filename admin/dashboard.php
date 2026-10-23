<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'dashboard';
$pageTitle = 'Dashboard';

$userId = (int)$_SESSION['user_id'];
$unreadAdminNotifs = get_unread_notification_count($pdo, $userId);

// Stats
$totalAppointments = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$todayBookings = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$totalClients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$lowStock = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= low_stock_threshold")->fetchColumn();
$revenue = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status = 'paid'")->fetchColumn();

// Upcoming appointments
$upcoming = $pdo->query("
    SELECT a.*, c.name as client_name, s.name as service_name 
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed')
    ORDER BY a.appointment_date, a.appointment_time
    LIMIT 8
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                    <h5 class="mb-0">Dashboard</h5>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?></small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell with Dropdown -->
                <div class="dropdown d-inline-block">
                    <button class="btn btn-notification-bell" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-role="notification-bell" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge" data-role="notification-badge" data-count="<?php echo $unreadAdminNotifs; ?>">
                            <?php echo $unreadAdminNotifs > 0 ? ($unreadAdminNotifs > 99 ? '99+' : $unreadAdminNotifs) : ''; ?>
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
                            <a href="notifications.php">
                                View all notifications <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <span class="badge bg-sage"><?php echo ucfirst($_SESSION['user_role']); ?></span>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <!-- KPI Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="kpi-label">Total Appointments</div>
                                <div class="kpi-value"><?php echo number_format($totalAppointments); ?></div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-calendar-alt"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="kpi-label">Today's Bookings</div>
                                <div class="kpi-value"><?php echo $todayBookings; ?></div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-clock"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="kpi-label">Total Clients</div>
                                <div class="kpi-value"><?php echo number_format($totalClients); ?></div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="kpi-label">Low Stock Alerts</div>
                                <div class="kpi-value text-danger"><?php echo $lowStock; ?></div>
                            </div>
                            <div class="kpi-icon" style="background: rgba(220,53,69,0.1); color: #dc3545;"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue + Quick Actions -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card-elegant text-center py-4">
                        <div class="kpi-label">Total Revenue</div>
                        <div class="kpi-value text-gold" style="font-size: 2.2rem;">$<?php echo number_format($revenue, 2); ?></div>
                        <small class="text-muted">From paid invoices</small>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card-elegant p-4">
                        <h6>Quick Actions</h6>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a href="../book.php" class="btn btn-sm btn-gold"><i class="fas fa-plus"></i> New Booking</a>
                            <a href="appointments.php" class="btn btn-sm btn-outline-gold"><i class="fas fa-calendar-check"></i> View Calendar</a>
                            <a href="clients.php" class="btn btn-sm btn-outline-gold"><i class="fas fa-user-plus"></i> Clients</a>
                            <a href="notifications.php" class="btn btn-sm btn-outline-gold"><i class="fas fa-bell"></i> Notification Center</a>
                            <a href="inventory.php" class="btn btn-sm btn-outline-gold"><i class="fas fa-boxes"></i> Inventory</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upcoming Appointments Table -->
            <div class="card-elegant">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Upcoming Appointments</h6>
                    <a href="appointments.php" class="small text-gold">View all &rarr;</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-elegant mb-0">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($upcoming)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No upcoming appointments</td></tr>
                            <?php else: ?>
                                <?php foreach ($upcoming as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['client_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($row['appointment_date'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($row['appointment_time'])); ?></td>
                                    <td>
                                        <span class="badge-status badge-<?php echo $row['status']; ?>">
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
