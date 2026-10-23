<?php
// =====================================================
// Elegance Salon Management System
// Admin Payments & Invoices Center
// =====================================================

require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'payments';
$pageTitle = 'Payments & Invoices';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Update Payment Status (e.g. mark paid or refunded)
        if ($action === 'update_status') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');

            if ($paymentId > 0 && in_array($newStatus, ['pending', 'paid', 'refunded'], true)) {
                $paidAtSql = ($newStatus === 'paid') ? "NOW()" : "NULL";
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = ?, paid_at = $paidAtSql WHERE id = ?");
                $stmt->execute([$newStatus, $paymentId]);

                // Dispatch notification for payment status change
                notify_payment_status_change($pdo, $paymentId, $newStatus);

                $success = "Payment #{$paymentId} status updated to '" . ucfirst($newStatus) . "'.";
            } else {
                $error = 'Invalid payment record or status.';
            }
        }

        // 2. Scan Pending Payments
        elseif ($action === 'scan_pending') {
            $scanRes = check_pending_payments($pdo);
            $success = "Pending payments scan complete: {$scanRes['checked']} pending invoice(s) scanned, {$scanRes['alerts_sent']} new alert(s) dispatched.";
        }

        // 3. Record New Payment
        elseif ($action === 'record_payment') {
            $clientId       = (int)($_POST['client_id'] ?? 0);
            $appointmentId  = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
            $amount         = max(0.01, (float)($_POST['amount'] ?? 0));
            $paymentMethod  = trim($_POST['payment_method'] ?? 'cash');
            $paymentStatus  = trim($_POST['payment_status'] ?? 'pending');

            if (!in_array($paymentMethod, ['cash', 'card', 'online', 'upi'], true)) {
                $paymentMethod = 'cash';
            }
            if (!in_array($paymentStatus, ['pending', 'paid', 'refunded'], true)) {
                $paymentStatus = 'pending';
            }

            if ($clientId <= 0) {
                $error = 'Please select a valid client.';
            } else {
                // Generate Invoice Number
                $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                $paidAt = ($paymentStatus === 'paid') ? date('Y-m-d H:i:s') : null;

                $insStmt = $pdo->prepare("
                    INSERT INTO payments (appointment_id, client_id, amount, payment_method, payment_status, invoice_number, paid_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStmt->execute([
                    $appointmentId,
                    $clientId,
                    $amount,
                    $paymentMethod,
                    $paymentStatus,
                    $invoiceNumber,
                    $paidAt
                ]);
                $newPayId = (int)$pdo->lastInsertId();

                if ($paymentStatus === 'paid') {
                    notify_payment_status_change($pdo, $newPayId, 'paid');
                } else {
                    check_pending_payments($pdo);
                }

                $success = "Payment recorded successfully with Invoice #{$invoiceNumber}.";
            }
        }
    }
}

// Filter handling
$filterStatus = $_GET['status'] ?? 'all';
$validFilters = ['all', 'pending', 'paid', 'refunded'];
if (!in_array($filterStatus, $validFilters, true)) {
    $filterStatus = 'all';
}

$sql = "
    SELECT p.id, p.appointment_id, p.client_id, p.amount, p.payment_method, p.payment_status,
           p.invoice_number, p.paid_at, p.created_at,
           c.name as client_name, c.email as client_email, c.phone as client_phone,
           a.appointment_date, a.appointment_time,
           s.name as service_name
    FROM payments p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN appointments a ON p.appointment_id = a.id
    LEFT JOIN services s ON a.service_id = s.id
";

if ($filterStatus !== 'all') {
    $sql .= " WHERE p.payment_status = " . $pdo->quote($filterStatus);
}
$sql .= " ORDER BY p.created_at DESC";

$payments = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalRevenue   = (float)$pdo->query("SELECT SUM(amount) FROM payments WHERE payment_status = 'paid'")->fetchColumn();
$totalPending   = (float)$pdo->query("SELECT SUM(amount) FROM payments WHERE payment_status = 'pending'")->fetchColumn();
$pendingCount   = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'pending'")->fetchColumn();

// Fetch clients for record payment modal
$clientsList = $pdo->query("SELECT id, name, phone, email FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$unreadAdminNotifs = get_unread_notification_count($pdo, (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .modal { z-index: 1060; }
        .modal-content-elegant {
            background: var(--black-card);
            border: 1px solid var(--border);
            color: var(--cream);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .modal-content-elegant .modal-header { border-bottom: 1px solid var(--border); }
        .modal-content-elegant .modal-footer { border-top: 1px solid var(--border); }
        .modal-content-elegant .form-label { color: #fff !important; }
        .modal-content-elegant .form-control, .modal-content-elegant .form-select {
            background: var(--black-soft);
            border: 1px solid rgba(232, 197, 71, 0.25);
            color: var(--cream);
            border-radius: 10px;
        }
        .modal-content-elegant .form-control:focus, .modal-content-elegant .form-select:focus {
            border-color: var(--champagne);
            background: var(--black-soft);
            color: var(--cream);
            box-shadow: 0 0 0 0.2rem rgba(232, 197, 71, 0.15);
        }
        .modal-content-elegant .btn-close { filter: invert(1); }
    </style>
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
                    <h5 class="mb-0">Payments &amp; Invoices</h5>
                    <small class="text-muted">Track Revenue, Outstanding Invoices &amp; Real-time Payment Alerts</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
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

                <form method="POST" action="" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="scan_pending">
                    <button type="submit" class="btn btn-sm btn-outline-info" title="Scan and dispatch alerts for overdue/pending payments">
                        <i class="fas fa-sync-alt me-1"></i> Scan Pending Alerts
                    </button>
                </form>

                <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                    <i class="fas fa-plus me-1"></i> Record Payment
                </button>

                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Metrics Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(13,148,136,0.2); color: var(--teal-light);">
                            <i class="fas fa-dollar-sign fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Collected (Paid)</div>
                            <h4 class="mb-0 text-white">$<?php echo number_format($totalRevenue, 2); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(232,197,71,0.2); color: var(--champagne);">
                            <i class="fas fa-hourglass-half fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Pending Amount</div>
                            <h4 class="mb-0 text-white">$<?php echo number_format($totalPending, 2); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(220,53,69,0.2); color: #f87171;">
                            <i class="fas fa-receipt fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Pending Invoices</div>
                            <h4 class="mb-0 text-white"><?php echo $pendingCount; ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Navigation -->
            <div class="filter-nav mb-4">
                <a href="payments.php?status=all" class="filter-pill <?php echo $filterStatus === 'all' ? 'active' : ''; ?>">All Payments</a>
                <a href="payments.php?status=pending" class="filter-pill <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="badge-count"><?php echo $pendingCount; ?></span>
                </a>
                <a href="payments.php?status=paid" class="filter-pill <?php echo $filterStatus === 'paid' ? 'active' : ''; ?>">Paid</a>
                <a href="payments.php?status=refunded" class="filter-pill <?php echo $filterStatus === 'refunded' ? 'active' : ''; ?>">Refunded</a>
            </div>

            <div class="card-elegant">
                <div class="table-responsive">
                    <table class="table table-elegant mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Invoice</th>
                                <th>Client</th>
                                <th>Service / Appt</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No payment records found.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $i => $row): 
                                    $status = $row['payment_status'];
                                    $badgeClass = ($status === 'paid') ? 'badge-confirmed' : (($status === 'pending') ? 'badge-pending' : 'badge-cancelled');
                                ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <strong class="text-gold"><?php echo htmlspecialchars($row['invoice_number'] ?? ('PAY-' . $row['id'])); ?></strong>
                                    </td>
                                    <td>
                                        <div class="text-white"><?php echo htmlspecialchars($row['client_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['client_phone'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['service_name'])): ?>
                                            <div class="text-white small"><?php echo htmlspecialchars($row['service_name']); ?></div>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted small">Direct / Non-Appt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-white">$<?php echo number_format((float)$row['amount'], 2); ?></strong>
                                    </td>
                                    <td><span class="text-uppercase small"><?php echo htmlspecialchars($row['payment_method']); ?></span></td>
                                    <td>
                                        <span class="badge-status <?php echo $badgeClass; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <a href="invoice.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-gold py-1 px-2 me-1" target="_blank">
                                            <i class="fas fa-file-invoice me-1"></i> Invoice
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-gold py-1 px-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="#updatePaymentModal"
                                                data-id="<?php echo (int)$row['id']; ?>"
                                                data-invoice="<?php echo htmlspecialchars($row['invoice_number'] ?? ('PAY-' . $row['id']), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-amount="<?php echo number_format((float)$row['amount'], 2); ?>"
                                                data-status="<?php echo htmlspecialchars($row['payment_status'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-client="<?php echo htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-edit me-1"></i> Update
                                        </button>
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

<!-- Update Payment Status Modal -->
<div class="modal fade" id="updatePaymentModal" tabindex="-1" aria-labelledby="updatePaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-elegant">
            <div class="modal-header">
                <h5 class="modal-title" id="updatePaymentModalLabel"><i class="fas fa-money-check text-gold me-2"></i>Update Payment Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="payment_id" id="modalPayId" value="">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Invoice</label>
                        <input type="text" class="form-control" id="modalPayInvoice" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Client</label>
                        <input type="text" class="form-control" id="modalPayClient" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <input type="text" class="form-control" id="modalPayAmount" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="modalPayStatus" required>
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="refunded">Refunded</option>
                        </select>
                        <small class="text-muted">Updating to Paid or Refunded will notify the client and salon staff.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i> Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-elegant">
            <div class="modal-header">
                <h5 class="modal-title" id="recordPaymentModalLabel"><i class="fas fa-receipt text-gold me-2"></i>Record New Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="record_payment">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Client <span class="text-danger">*</span></label>
                        <select class="form-select" name="client_id" required>
                            <option value=""> Choose Client </option>
                            <?php foreach ($clientsList as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>">
                                    <?php echo htmlspecialchars($c['name'] . ' (' . ($c['phone'] ?? $c['email']) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount ($) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required placeholder="e.g. 50.00">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="card">Credit/Debit Card</option>
                                <option value="online">Online Transfer</option>
                                <option value="upi">UPI / Mobile Pay</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Initial Status</label>
                            <select class="form-select" name="payment_status">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-check me-1"></i> Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const updateModal = document.getElementById('updatePaymentModal');
    if (updateModal) {
        updateModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            document.getElementById('modalPayId').value = btn.getAttribute('data-id');
            document.getElementById('modalPayInvoice').value = btn.getAttribute('data-invoice');
            document.getElementById('modalPayClient').value = btn.getAttribute('data-client');
            document.getElementById('modalPayAmount').value = '$' + btn.getAttribute('data-amount');
            document.getElementById('modalPayStatus').value = btn.getAttribute('data-status');
        });
    }
});
</script>
</body>
</html>
