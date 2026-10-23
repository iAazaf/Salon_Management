<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';

// Allow admin and receptionist to view and print invoices
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'receptionist'], true)) {
    header('Location: ../login.php');
    exit;
}

$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$invoiceNum = isset($_GET['inv']) ? trim($_GET['inv']) : '';

if ($paymentId <= 0 && empty($invoiceNum)) {
    header('Location: payments.php');
    exit;
}

if ($paymentId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address,
               a.appointment_date, a.appointment_time, a.status AS appointment_status,
               s.name AS service_name, s.duration AS service_duration, s.price AS service_price, s.category AS service_category,
               st_u.name AS stylist_name
        FROM payments p
        JOIN clients c ON p.client_id = c.id
        LEFT JOIN appointments a ON p.appointment_id = a.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN staff st ON a.staff_id = st.id
        LEFT JOIN users st_u ON st.user_id = st_u.id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId]);
} else {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address,
               a.appointment_date, a.appointment_time, a.status AS appointment_status,
               s.name AS service_name, s.duration AS service_duration, s.price AS service_price, s.category AS service_category,
               st_u.name AS stylist_name
        FROM payments p
        JOIN clients c ON p.client_id = c.id
        LEFT JOIN appointments a ON p.appointment_id = a.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN staff st ON a.staff_id = st.id
        LEFT JOIN users st_u ON st.user_id = st_u.id
        WHERE p.invoice_number = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceNum]);
}

$invoice = $stmt->fetch();

if (!$invoice) {
    die("Invoice not found. <a href='payments.php'>Return to Payments</a>");
}

$invNumber = !empty($invoice['invoice_number']) ? $invoice['invoice_number'] : ('INV-' . str_pad($invoice['id'], 5, '0', STR_PAD_LEFT));
$paymentDate = !empty($invoice['paid_at']) ? $invoice['paid_at'] : $invoice['created_at'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invNumber); ?> - Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .invoice-card {
            background: #18191c;
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            color: #f5f5f5;
        }
        .text-gold {
            color: #d4af37 !important;
        }
        .border-gold {
            border-color: rgba(212, 175, 55, 0.3) !important;
        }
        .table-invoice th {
            background-color: #222327;
            color: #d4af37;
            font-weight: 600;
            border-color: #2e3036;
        }
        .table-invoice td {
            background-color: transparent;
            color: #e0e0e0;
            border-color: #2e3036;
        }
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 13px;
            }
            .no-print {
                display: none !important;
            }
            .invoice-card {
                background: #ffffff !important;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                color: #000000 !important;
            }
            .text-gold {
                color: #997404 !important;
            }
            .text-white {
                color: #000000 !important;
            }
            .text-muted {
                color: #555555 !important;
            }
            .table-invoice th {
                background-color: #f4f4f4 !important;
                color: #000000 !important;
                border-color: #ccc !important;
            }
            .table-invoice td {
                background-color: #ffffff !important;
                color: #000000 !important;
                border-color: #ddd !important;
            }
            .badge-paid {
                border: 1px solid #198754;
                color: #198754 !important;
            }
        }
    </style>
</head>
<body class="bg-dark py-4">

<div class="container my-2">
    <!-- Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="payments.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Payments
        </a>
        <div>
            <button onclick="window.print()" class="btn btn-gold px-4">
                <i class="fas fa-print me-2"></i>Print Invoice
            </button>
        </div>
    </div>

    <!-- Printable Invoice Sheet -->
    <div class="invoice-card p-4 p-md-5">
        <!-- Header -->
        <div class="row align-items-center pb-4 mb-4 border-bottom border-gold">
            <div class="col-sm-7">
                <h2 class="font-serif text-gold mb-1"><i class="fas fa-gem me-2"></i>Elegance Salon</h2>
                <p class="text-muted mb-0 small">
                    123 Luxury Lane, Clifton, Karachi, Pakistan<br>
                    Phone: +92 330 9066929 &bull; Email: info@elegancesalon.com<br>
                    Web: www.elegancesalon.com
                </p>
            </div>
            <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                <h4 class="text-uppercase tracking-wider text-white mb-1">Receipt / Invoice</h4>
                <div class="text-gold font-monospace fw-bold fs-5"><?php echo htmlspecialchars($invNumber); ?></div>
                <div class="small text-muted mt-1">Date: <?php echo date('M d, Y h:i A', strtotime($paymentDate)); ?></div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="row mb-4">
            <div class="col-sm-6 mb-3 mb-sm-0">
                <h6 class="text-gold text-uppercase fw-semibold mb-2">Billed To</h6>
                <div class="fw-bold text-white fs-6"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                <div class="text-muted small"><i class="fas fa-phone-alt me-2"></i><?php echo htmlspecialchars($invoice['client_phone']); ?></div>
                <?php if (!empty($invoice['client_email'])): ?>
                    <div class="text-muted small"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($invoice['client_email']); ?></div>
                <?php endif; ?>
                <?php if (!empty($invoice['client_address'])): ?>
                    <div class="text-muted small"><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($invoice['client_address']); ?></div>
                <?php endif; ?>
            </div>
            <div class="col-sm-6 text-sm-end">
                <h6 class="text-gold text-uppercase fw-semibold mb-2">Payment Details</h6>
                <div class="small"><span class="text-muted">Payment Method:</span> <strong class="text-white text-uppercase"><?php echo htmlspecialchars($invoice['payment_method']); ?></strong></div>
                <div class="small mt-1"><span class="text-muted">Payment Status:</span> 
                    <?php if ($invoice['payment_status'] === 'paid'): ?>
                        <span class="badge bg-success-subtle text-success border border-success px-2 py-1">PAID</span>
                    <?php elseif ($invoice['payment_status'] === 'pending'): ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1">PENDING</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1"><?php echo strtoupper($invoice['payment_status']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($invoice['appointment_date'])): ?>
                    <div class="small mt-1"><span class="text-muted">Appointment Date:</span> <span class="text-white"><?php echo date('M d, Y', strtotime($invoice['appointment_date'])); ?> at <?php echo date('h:i A', strtotime($invoice['appointment_time'])); ?></span></div>
                <?php endif; ?>
                <?php if (!empty($invoice['stylist_name'])): ?>
                    <div class="small mt-1"><span class="text-muted">Attending Stylist:</span> <span class="text-white"><?php echo htmlspecialchars($invoice['stylist_name']); ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-invoice align-middle">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Item / Service</th>
                        <th>Category</th>
                        <th class="text-center">Duration</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>
                            <strong class="text-white"><?php echo htmlspecialchars($invoice['service_name'] ?? 'Salon Service / Package'); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($invoice['service_category'] ?? 'General'); ?></td>
                        <td class="text-center"><?php echo !empty($invoice['service_duration']) ? ($invoice['service_duration'] . ' mins') : '-'; ?></td>
                        <td class="text-end fw-semibold text-white">$<?php echo number_format((float)$invoice['amount'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Totals Breakdown -->
        <div class="row justify-content-end mb-4">
            <div class="col-sm-5">
                <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-muted">Subtotal:</span>
                    <strong class="text-white">$<?php echo number_format((float)$invoice['amount'], 2); ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-muted">Tax / VAT (0% Included):</span>
                    <strong class="text-white">$0.00</strong>
                </div>
                <div class="d-flex justify-content-between py-2 fs-5">
                    <span class="text-gold fw-bold">Total Paid:</span>
                    <strong class="text-gold fw-bold">$<?php echo number_format((float)$invoice['amount'], 2); ?></strong>
                </div>
            </div>
        </div>

        <!-- Footer / Terms -->
        <div class="pt-4 border-top border-gold text-center text-muted small">
            <p class="mb-1">Thank you for visiting Elegance Salon! We appreciate your business.</p>
            <p class="mb-0">Appointments can be rescheduled or cancelled up to 24 hours in advance.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
