<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Strict Admin-Only Access: Financial reports & CSV exports are admin-only
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$adminPage = 'reports';
$pageTitle = 'Reports & Analytics';

// Date range filters
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to']   ?? date('Y-m-d');

// 1. CSV EXPORT HANDLER
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $filename = "elegance_report_{$exportType}_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    $output = fopen('php://output', 'w');

    if ($exportType === 'revenue') {
        fputcsv($output, ['Payment ID', 'Invoice #', 'Client Name', 'Amount ($)', 'Method', 'Status', 'Paid At']);
        $stmt = $pdo->prepare("
            SELECT p.id, p.invoice_number, c.name AS client_name, p.amount, p.payment_method, p.payment_status, p.paid_at
            FROM payments p
            JOIN clients c ON c.id = p.client_id
            WHERE DATE(p.paid_at) BETWEEN ? AND ?
            ORDER BY p.paid_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($exportType === 'services') {
        fputcsv($output, ['Rank', 'Service Name', 'Category', 'Price ($)', 'Total Bookings', 'Total Revenue ($)']);
        $stmt = $pdo->prepare("
            SELECT s.name, s.category, s.price, COUNT(a.id) as total_bookings, 
                   COALESCE(SUM(s.price), 0) as total_revenue
            FROM services s
            LEFT JOIN appointments a ON a.service_id = s.id AND DATE(a.appointment_date) BETWEEN ? AND ?
            GROUP BY s.id
            ORDER BY total_bookings DESC, total_revenue DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $rank = 1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$rank++, $row['name'], $row['category'], $row['price'], $row['total_bookings'], $row['total_revenue']]);
        }
    } elseif ($exportType === 'stylists') {
        fputcsv($output, ['Stylist Name', 'Specialization', 'Commission Rate (%)', 'Completed Bookings', 'Service Revenue ($)', 'Commission Earned ($)']);
        $stmt = $pdo->prepare("
            SELECT u.name, s.specialization, s.commission_rate,
                   COUNT(a.id) as completed_count,
                   COALESCE(SUM(srv.price), 0) as total_sales,
                   ROUND(COALESCE(SUM(srv.price * (s.commission_rate / 100)), 0), 2) as commission_earned
            FROM staff s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN appointments a ON a.staff_id = s.id AND a.status = 'completed' AND DATE(a.appointment_date) BETWEEN ? AND ?
            LEFT JOIN services srv ON srv.id = a.service_id
            GROUP BY s.id
            ORDER BY total_sales DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($exportType === 'clients') {
        fputcsv($output, ['Client ID', 'Name', 'Email', 'Phone', 'Total Visits', 'Total Spent ($)', 'Last Visit']);
        $stmt = $pdo->query("
            SELECT c.id, c.name, c.email, c.phone, 
                   COUNT(a.id) as total_visits,
                   COALESCE(SUM(p.amount), 0) as total_spent,
                   MAX(a.appointment_date) as last_visit
            FROM clients c
            LEFT JOIN appointments a ON a.client_id = c.id
            LEFT JOIN payments p ON p.client_id = c.id AND p.payment_status = 'paid'
            GROUP BY c.id
            ORDER BY total_spent DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}

// 2. QUERY METRICS FOR SELECTED DATE RANGE
// Total revenue
$stmtRev = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_revenue, COUNT(*) AS paid_count
    FROM payments 
    WHERE payment_status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?
");
$stmtRev->execute([$dateFrom, $dateTo]);
$revStats = $stmtRev->fetch(PDO::FETCH_ASSOC);
$totalRevenue = (float)$revStats['total_revenue'];
$totalPaidTx  = (int)$revStats['paid_count'];
$avgTicket    = $totalPaidTx > 0 ? ($totalRevenue / $totalPaidTx) : 0;

// Total appointments in range
$stmtAppt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_appts,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_appts,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_appts,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_appts,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_appts
    FROM appointments
    WHERE DATE(appointment_date) BETWEEN ? AND ?
");
$stmtAppt->execute([$dateFrom, $dateTo]);
$apptStats = $stmtAppt->fetch(PDO::FETCH_ASSOC);
$totalAppts     = (int)$apptStats['total_appts'];
$completedAppts = (int)$apptStats['completed_appts'];
$confirmedAppts = (int)$apptStats['confirmed_appts'];
$pendingAppts   = (int)$apptStats['pending_appts'];
$cancelledAppts = (int)$apptStats['cancelled_appts'];

// Daily revenue trend (for Chart.js)
$stmtDaily = $pdo->prepare("
    SELECT DATE(paid_at) AS pay_date, SUM(amount) AS daily_total
    FROM payments
    WHERE payment_status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?
    GROUP BY DATE(paid_at)
    ORDER BY pay_date ASC
");
$stmtDaily->execute([$dateFrom, $dateTo]);
$dailyRows = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartRevenue = [];
foreach ($dailyRows as $r) {
    $chartLabels[] = date('M d', strtotime($r['pay_date']));
    $chartRevenue[] = (float)$r['daily_total'];
}

// Popular services in range
$stmtPopular = $pdo->prepare("
    SELECT s.name, s.category, s.price, 
           COUNT(a.id) AS booking_count,
           COALESCE(SUM(s.price), 0) AS revenue_generated
    FROM services s
    LEFT JOIN appointments a ON a.service_id = s.id AND DATE(a.appointment_date) BETWEEN ? AND ?
    GROUP BY s.id
    ORDER BY booking_count DESC, revenue_generated DESC
    LIMIT 6
");
$stmtPopular->execute([$dateFrom, $dateTo]);
$popularServices = $stmtPopular->fetchAll(PDO::FETCH_ASSOC);

// Stylist performance in range
$stmtStylists = $pdo->prepare("
    SELECT u.name, s.specialization, s.commission_rate,
           COUNT(a.id) AS completed_count,
           COALESCE(SUM(srv.price), 0) AS service_sales,
           ROUND(COALESCE(SUM(srv.price * (s.commission_rate / 100)), 0), 2) AS commission_earned
    FROM staff s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN appointments a ON a.staff_id = s.id AND a.status = 'completed' AND DATE(a.appointment_date) BETWEEN ? AND ?
    LEFT JOIN services srv ON srv.id = a.service_id
    GROUP BY s.id
    ORDER BY service_sales DESC
");
$stmtStylists->execute([$dateFrom, $dateTo]);
$stylistPerformance = $stmtStylists->fetchAll(PDO::FETCH_ASSOC);

// Top spending clients
$topClients = $pdo->query("
    SELECT c.name, c.phone, c.email,
           COUNT(DISTINCT a.id) AS visit_count,
           COALESCE(SUM(p.amount), 0) AS total_paid,
           MAX(a.appointment_date) AS last_booking
    FROM clients c
    LEFT JOIN appointments a ON a.client_id = c.id
    LEFT JOIN payments p ON p.client_id = c.id AND p.payment_status = 'paid'
    GROUP BY c.id
    ORDER BY total_paid DESC, visit_count DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Low stock items summary
$lowStockProducts = $pdo->query("
    SELECT product_name, category, quantity, unit_price, supplier, low_stock_threshold
    FROM inventory
    WHERE quantity <= low_stock_threshold
    ORDER BY quantity ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .report-filter-bar {
            background: var(--black-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
        }
        .chart-box {
            background: var(--black-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
        }
        .nav-tabs-elegant {
            border-bottom: 1px solid var(--border);
        }
        .nav-tabs-elegant .nav-link {
            color: var(--muted);
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s ease;
        }
        .nav-tabs-elegant .nav-link.active {
            color: var(--champagne);
            background: rgba(232, 197, 71, 0.12);
            border-bottom: 2px solid var(--champagne);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-gold btn-sm d-lg-none me-2" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 text-white"><i class="fas fa-chart-line text-gold me-2"></i>Reports & Business Intelligence</h5>
                    <small class="text-muted">Analyze financial revenue, stylist productivity, service popularity & clients</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="dropdown">
                    <button class="btn btn-gold btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-file-export me-1"></i> Export CSV
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li><a class="dropdown-item" href="?export=revenue&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>"><i class="fas fa-money-bill-wave me-2 text-gold"></i>Revenue Transactions</a></li>
                        <li><a class="dropdown-item" href="?export=services&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>"><img src="../assets/img/logo.png" class="salon-mark-icon me-2 text-gold" alt="" aria-hidden="true">Services Ranking</a></li>
                        <li><a class="dropdown-item" href="?export=stylists&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>"><i class="fas fa-user-tie me-2 text-gold"></i>Stylist Commission</a></li>
                        <li><a class="dropdown-item" href="?export=clients"><i class="fas fa-users me-2 text-gold"></i>Client Spend Data</a></li>
                    </ul>
                </div>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <!-- Filter Bar -->
            <form method="GET" class="report-filter-bar mb-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label-elegant small text-gold">Start Date</label>
                        <input type="date" name="from" class="form-control form-control-elegant" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-elegant small text-gold">End Date</label>
                        <input type="date" name="to" class="form-control form-control-elegant" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-gold w-100">
                            <i class="fas fa-filter me-1"></i> Apply Filter
                        </button>
                    </div>
                    <div class="col-md-3">
                        <div class="btn-group w-100">
                            <a href="?from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">Today</a>
                            <a href="?from=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">7D</a>
                            <a href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-t'); ?>" class="btn btn-outline-secondary btn-sm">Month</a>
                        </div>
                    </div>
                </div>
            </form>

            <!-- KPI Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Filtered Revenue</div>
                                <div class="kpi-value text-gold">$<?php echo number_format($totalRevenue, 2); ?></div>
                                <div class="kpi-sub"><?php echo $totalPaidTx; ?> completed payments</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-dollar-sign"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Total Bookings</div>
                                <div class="kpi-value"><?php echo $totalAppts; ?></div>
                                <div class="kpi-sub"><?php echo $completedAppts; ?> completed in range</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Avg. Ticket Size</div>
                                <div class="kpi-value text-success">$<?php echo number_format($avgTicket, 2); ?></div>
                                <div class="kpi-sub">Per paid appointment</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Cancellation Rate</div>
                                <?php 
                                    $cancelRate = $totalAppts > 0 ? round(($cancelledAppts / $totalAppts) * 100, 1) : 0;
                                ?>
                                <div class="kpi-value <?php echo $cancelRate > 20 ? 'text-danger' : 'text-info'; ?>">
                                    <?php echo $cancelRate; ?>%
                                </div>
                                <div class="kpi-sub"><?php echo $cancelledAppts; ?> cancelled bookings</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-ban"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="chart-box">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-white mb-0"><i class="fas fa-chart-area text-gold me-2"></i>Revenue Timeline</h6>
                            <span class="badge bg-dark border border-secondary text-gold">Daily Realized Sales</span>
                        </div>
                        <div style="height: 280px; position: relative;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="chart-box">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-white mb-0"><i class="fas fa-chart-pie text-gold me-2"></i>Status Breakdown</h6>
                            <span class="badge bg-dark border border-secondary text-gold"><?php echo $totalAppts; ?> Total</span>
                        </div>
                        <div style="height: 280px; position: relative;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Tables Navigation -->
            <div class="card-elegant">
                <ul class="nav nav-tabs nav-tabs-elegant mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-services">
                            <img src="../assets/img/logo.png" class="salon-mark-icon me-1" alt="" aria-hidden="true"> Popular Services
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stylists">
                            <i class="fas fa-user-tie me-1"></i> Stylist Performance
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-clients">
                            <i class="fas fa-crown me-1"></i> Top Clients
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-inventory">
                            <i class="fas fa-exclamation-triangle me-1"></i> Low Stock Alerts
                        </button>
                    </li>
                </ul>

                <div class="tab-content p-2">
                    <!-- Tab 1: Popular Services -->
                    <div class="tab-pane fade show active" id="tab-services">
                        <div class="table-responsive">
                            <table class="table table-elegant mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Service</th>
                                        <th>Category</th>
                                        <th>Base Price</th>
                                        <th>Bookings (Range)</th>
                                        <th>Est. Volume Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($popularServices)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">No service data for selected range.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($popularServices as $i => $ps): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($ps['name']); ?></strong></td>
                                                <td><span class="badge bg-dark border border-secondary text-gold"><?php echo htmlspecialchars($ps['category']); ?></span></td>
                                                <td>$<?php echo number_format((float)$ps['price'], 2); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo (int)$ps['booking_count']; ?> bookings</span></td>
                                                <td class="text-gold fw-bold">$<?php echo number_format((float)$ps['revenue_generated'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Stylist Performance -->
                    <div class="tab-pane fade" id="tab-stylists">
                        <div class="table-responsive">
                            <table class="table table-elegant mb-0">
                                <thead>
                                    <tr>
                                        <th>Stylist Name</th>
                                        <th>Specialization</th>
                                        <th>Commission Rate</th>
                                        <th>Completed Appointments</th>
                                        <th>Total Service Sales</th>
                                        <th>Commission Payout</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($stylistPerformance)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">No stylist records found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($stylistPerformance as $sp): ?>
                                            <tr>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($sp['name']); ?></strong></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($sp['specialization'] ?? 'Stylist'); ?></small></td>
                                                <td><?php echo number_format((float)$sp['commission_rate'], 1); ?>%</td>
                                                <td><span class="badge bg-success"><?php echo (int)$sp['completed_count']; ?> jobs</span></td>
                                                <td class="text-white fw-bold">$<?php echo number_format((float)$sp['service_sales'], 2); ?></td>
                                                <td class="text-gold fw-bold">$<?php echo number_format((float)$sp['commission_earned'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 3: Top Clients -->
                    <div class="tab-pane fade" id="tab-clients">
                        <div class="table-responsive">
                            <table class="table table-elegant mb-0">
                                <thead>
                                    <tr>
                                        <th>Client Name</th>
                                        <th>Contact Phone</th>
                                        <th>Email</th>
                                        <th>Total Visits</th>
                                        <th>Lifetime Spend</th>
                                        <th>Last Visited</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($topClients)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">No client records available.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($topClients as $tc): ?>
                                            <tr>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($tc['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($tc['phone']); ?></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($tc['email'] ?? '—'); ?></small></td>
                                                <td><span class="badge bg-info text-dark"><?php echo (int)$tc['visit_count']; ?> visits</span></td>
                                                <td class="text-gold fw-bold">$<?php echo number_format((float)$tc['total_paid'], 2); ?></td>
                                                <td><?php echo $tc['last_booking'] ? date('d M Y', strtotime($tc['last_booking'])) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 4: Low Stock -->
                    <div class="tab-pane fade" id="tab-inventory">
                        <div class="table-responsive">
                            <table class="table table-elegant mb-0">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Current Stock</th>
                                        <th>Reorder Threshold</th>
                                        <th>Supplier</th>
                                        <th>Unit Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($lowStockProducts)): ?>
                                        <tr><td colspan="6" class="text-center text-success py-4"><i class="fas fa-check-circle me-1"></i>All inventory levels are currently above reorder thresholds!</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($lowStockProducts as $lp): ?>
                                            <tr>
                                                <td><strong class="text-danger"><?php echo htmlspecialchars($lp['product_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($lp['category']); ?></td>
                                                <td><span class="badge bg-danger"><?php echo (int)$lp['quantity']; ?> units left</span></td>
                                                <td><?php echo (int)$lp['low_stock_threshold']; ?> units</td>
                                                <td><?php echo htmlspecialchars($lp['supplier'] ?? '—'); ?></td>
                                                <td>$<?php echo number_format((float)$lp['unit_price'], 2); ?></td>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Revenue Timeline Chart
    const revCtx = document.getElementById('revenueChart').getContext('2d');
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartRevenue = <?php echo json_encode($chartRevenue); ?>;

    new Chart(revCtx, {
        type: 'line',
        data: {
            labels: chartLabels.length > 0 ? chartLabels : ['No Data'],
            datasets: [{
                label: 'Revenue ($)',
                data: chartRevenue.length > 0 ? chartRevenue : [0],
                borderColor: '#E8C547',
                backgroundColor: 'rgba(232, 197, 71, 0.15)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#E8C547',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#94A3B8' }
                },
                y: {
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: {
                        color: '#94A3B8',
                        callback: val => '$' + val
                    }
                }
            }
        }
    });

    // 2. Status Doughnut Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Confirmed', 'Pending', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $completedAppts; ?>,
                    <?php echo $confirmedAppts; ?>,
                    <?php echo $pendingAppts; ?>,
                    <?php echo $cancelledAppts; ?>
                ],
                backgroundColor: [
                    '#2DD4BF',
                    '#E8C547',
                    '#F5E6A3',
                    '#EF4444'
                ],
                borderColor: '#1C232C',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#F7F5F0', boxWidth: 12, font: { size: 11 } }
                }
            }
        }
    });
});
</script>
</body>
</html>
