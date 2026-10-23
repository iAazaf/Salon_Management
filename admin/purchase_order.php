<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'receptionist'], true)) {
    header('Location: ../login.php');
    exit;
}

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if ($productId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
    $stmt->execute([$productId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Automated batch PO: all products at or below threshold
    $stmt = $pdo->query("SELECT * FROM inventory WHERE quantity <= low_stock_threshold ORDER BY supplier, product_name");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If none are low, fall back to all inventory items for full stock requisition
    if (empty($items)) {
        $stmt = $pdo->query("SELECT * FROM inventory ORDER BY supplier, product_name");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
$poDate = date('F d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order <?php echo htmlspecialchars($poNumber); ?> - Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .po-card {
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
        .table-po th {
            background-color: #222327;
            color: #d4af37;
            font-weight: 600;
            border-color: #2e3036;
        }
        .table-po td {
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
            .po-card {
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
            .table-po th {
                background-color: #f4f4f4 !important;
                color: #000000 !important;
                border-color: #ccc !important;
            }
            .table-po td {
                background-color: #ffffff !important;
                color: #000000 !important;
                border-color: #ddd !important;
            }
        }
    </style>
</head>
<body class="bg-dark py-4">

<div class="container my-2">
    <!-- Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="inventory.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Inventory
        </a>
        <div>
            <button onclick="window.print()" class="btn btn-gold px-4">
                <i class="fas fa-print me-2"></i>Print Purchase Order
            </button>
        </div>
    </div>

    <!-- PO Sheet -->
    <div class="po-card p-4 p-md-5">
        <!-- Header -->
        <div class="row align-items-center pb-4 mb-4 border-bottom border-gold">
            <div class="col-sm-7">
                <h2 class="font-serif text-gold mb-1"><i class="fas fa-gem me-2"></i>Elegance Salon & Spa</h2>
                <p class="text-muted mb-0 small">
                    123 Luxury Lane, Clifton, Karachi, Pakistan<br>
                    Inventory & Procurement Department &bull; Phone: +92 330 9066929<br>
                    Email: inventory@elegancesalon.com
                </p>
            </div>
            <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                <h4 class="text-uppercase tracking-wider text-white mb-1">Purchase Order</h4>
                <div class="text-gold font-monospace fw-bold fs-5"><?php echo htmlspecialchars($poNumber); ?></div>
                <div class="small text-muted mt-1">Issue Date: <?php echo $poDate; ?></div>
                <div class="small text-muted">Generated By: <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Authorized Staff'); ?></div>
            </div>
        </div>

        <!-- Purpose Note -->
        <div class="alert alert-dark border-secondary small mb-4 py-2">
            <i class="fas fa-info-circle text-gold me-2"></i>
            <strong>Procurement Requisition:</strong> This automatic purchase order details inventory items currently at or below minimum threshold requiring vendor restocking.
        </div>

        <!-- Items Table -->
        <div class="table-responsive mb-4">
            <table class="table table-po align-middle">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>Product / Item Name</th>
                        <th>Category</th>
                        <th>Supplier / Vendor</th>
                        <th class="text-center">Current Qty</th>
                        <th class="text-center">Min Threshold</th>
                        <th class="text-center">Recommended Order</th>
                        <th class="text-end">Est. Unit Cost</th>
                        <th class="text-end">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grandTotal = 0;
                    $totalOrderUnits = 0;
                    foreach ($items as $idx => $prod): 
                        $curQty = (int)$prod['quantity'];
                        $threshold = (int)$prod['low_stock_threshold'];
                        // Calculate reorder quantity: minimum replenish to 2x threshold, min 10 units
                        $reorderQty = max(10, ($threshold * 2) - $curQty);
                        $unitCost = (float)$prod['unit_price'];
                        $lineTotal = $reorderQty * $unitCost;
                        $grandTotal += $lineTotal;
                        $totalOrderUnits += $reorderQty;
                    ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td>
                            <strong class="text-white"><?php echo htmlspecialchars($prod['product_name']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($prod['category'] ?? 'General'); ?></td>
                        <td><span class="badge bg-dark border border-secondary text-gold"><?php echo htmlspecialchars($prod['supplier'] ?? 'Standard Vendor'); ?></span></td>
                        <td class="text-center <?php echo $curQty <= $threshold ? 'text-danger fw-bold' : ''; ?>"><?php echo $curQty; ?></td>
                        <td class="text-center"><?php echo $threshold; ?></td>
                        <td class="text-center text-gold fw-bold">+<?php echo $reorderQty; ?> units</td>
                        <td class="text-end">$<?php echo number_format($unitCost, 2); ?></td>
                        <td class="text-end fw-semibold text-white">$<?php echo number_format($lineTotal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals Breakdown -->
        <div class="row justify-content-end mb-4">
            <div class="col-sm-5">
                <div class="d-flex justify-content-between py-2 border-bottom border-secondary">
                    <span class="text-muted">Total Units to Restock:</span>
                    <strong class="text-white"><?php echo $totalOrderUnits; ?> Units</strong>
                </div>
                <div class="d-flex justify-content-between py-2 fs-5">
                    <span class="text-gold fw-bold">Estimated Grand Total:</span>
                    <strong class="text-gold fw-bold">$<?php echo number_format($grandTotal, 2); ?></strong>
                </div>
            </div>
        </div>

        <!-- Signatures Area -->
        <div class="row mt-5 pt-4 border-top border-secondary">
            <div class="col-6">
                <div class="border-top border-secondary pt-2 mt-4" style="width: 220px;">
                    <p class="mb-0 text-muted small">Prepared By (Inventory Manager)</p>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="border-top border-secondary pt-2 mt-4 ms-auto" style="width: 220px;">
                    <p class="mb-0 text-muted small">Approved By (Salon Director)</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
