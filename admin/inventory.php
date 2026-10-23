<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'inventory';
$pageTitle = 'Inventory';

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

        if ($action === 'update_stock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $newQuantity = max(0, (int)($_POST['quantity'] ?? 0));
            $threshold = max(1, (int)($_POST['threshold'] ?? 10));

            $stmtOld = $pdo->prepare("SELECT quantity, low_stock_threshold, product_name FROM inventory WHERE id = ?");
            $stmtOld->execute([$productId]);
            $oldProd = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if ($oldProd) {
                $oldQty = (int)$oldProd['quantity'];
                $oldThreshold = (int)$oldProd['low_stock_threshold'];

                $upStmt = $pdo->prepare("UPDATE inventory SET quantity = ?, low_stock_threshold = ?, last_updated = NOW() WHERE id = ?");
                $upStmt->execute([$newQuantity, $threshold, $productId]);

                // Check replenishment or low stock
                if ($oldQty <= $oldThreshold && $newQuantity > $threshold) {
                    notify_inventory_replenished($pdo, $productId, $newQuantity);
                } elseif ($newQuantity <= $threshold) {
                    check_low_inventory($pdo);
                }

                $success = "Stock updated for '{$oldProd['product_name']}'. Current stock: {$newQuantity} (Threshold: {$threshold}).";
            } else {
                $error = 'Product not found.';
            }
        } elseif ($action === 'add_product') {
            $productName = trim($_POST['product_name'] ?? '');
            $category    = trim($_POST['category'] ?? '');
            $quantity    = max(0, (int)($_POST['quantity'] ?? 0));
            $unitPrice   = max(0, (float)($_POST['unit_price'] ?? 0));
            $supplier    = trim($_POST['supplier'] ?? '');
            $threshold   = max(1, (int)($_POST['threshold'] ?? 10));

            if ($productName === '') {
                $error = 'Product name is required.';
            } else {
                $insStmt = $pdo->prepare("
                    INSERT INTO inventory (product_name, category, quantity, unit_price, supplier, low_stock_threshold, last_updated)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStmt->execute([$productName, $category, $quantity, $unitPrice, $supplier, $threshold]);
                $newId = (int)$pdo->lastInsertId();

                if ($quantity <= $threshold) {
                    check_low_inventory($pdo);
                }

                $success = "Product '{$productName}' added to inventory.";
            }
        } elseif ($action === 'scan_low_stock') {
            $scanRes = check_low_inventory($pdo);
            $success = "Low stock scan complete: {$scanRes['checked']} low-stock items detected, {$scanRes['alerts_sent']} new alert(s) sent.";
        } elseif ($action === 'delete_product') {
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                $error = 'Unauthorized: Only administrators can remove products from inventory.';
            } else {
                $productId = (int)($_POST['product_id'] ?? 0);
                if ($productId > 0) {
                    $delStmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                    $delStmt->execute([$productId]);
                    $success = 'Product has been removed from inventory.';
                } else {
                    $error = 'Invalid product ID.';
                }
            }
        }
    }
}

$products = $pdo->query("SELECT * FROM inventory ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$unreadAdminNotifs = get_unread_notification_count($pdo, (int)$_SESSION['user_id']);

$lowStockCount = 0;
foreach ($products as $p) {
    if ((int)$p['quantity'] <= (int)$p['low_stock_threshold']) {
        $lowStockCount++;
    }
}
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
        .modal-content-elegant .form-control:focus {
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
                    <h5 class="mb-0">Inventory &amp; Supplies</h5>
                    <small class="text-muted">Stock Tracking &amp; Automated Low-Inventory Alerts</small>
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
                    <input type="hidden" name="action" value="scan_low_stock">
                    <button type="submit" class="btn btn-sm btn-outline-info" title="Scan inventory and dispatch low-stock alerts to admins">
                        <i class="fas fa-sync-alt me-1"></i> Scan Stock Alerts
                    </button>
                </form>

                <a href="purchase_order.php" target="_blank" class="btn btn-sm btn-outline-gold" title="Generate printable purchase order / restock requisition">
                    <i class="fas fa-file-invoice-dollar me-1"></i> Reorder Slip (PO)
                </a>

                <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-1"></i> Add Product
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

            <!-- Inventory Stats Bar -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(13,148,136,0.2); color: var(--teal-light);">
                            <i class="fas fa-boxes fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Products</div>
                            <h4 class="mb-0 text-white"><?php echo count($products); ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(220,53,69,0.2); color: #f87171;">
                            <i class="fas fa-exclamation-circle fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Low Stock Items</div>
                            <h4 class="mb-0 text-white"><?php echo $lowStockCount; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-elegant p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(232,197,71,0.2); color: var(--champagne);">
                            <i class="fas fa-bell fa-lg"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Alert System</div>
                            <h6 class="mb-0 text-white">Active (<span class="text-gold">Automatic</span>)</h6>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-elegant">
                <div class="table-responsive">
                    <table class="table table-elegant mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Min Threshold</th>
                                <th>Unit Price</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $i => $row): 
                                $isLow = (int)$row['quantity'] <= (int)$row['low_stock_threshold'];
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <strong class="text-white"><?php echo htmlspecialchars($row['product_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($row['category'] ?? '-'); ?></td>
                                <td>
                                    <span class="<?php echo $isLow ? 'text-danger fw-bold' : 'text-white'; ?>">
                                        <?php echo (int)$row['quantity']; ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$row['low_stock_threshold']; ?></td>
                                <td>$<?php echo number_format((float)$row['unit_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['supplier'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($isLow): ?>
                                        <span class="badge-status badge-lowstock"><i class="fas fa-exclamation-triangle me-1"></i>Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-confirmed"><i class="fas fa-check me-1"></i>In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($isLow): ?>
                                        <a href="purchase_order.php?product_id=<?php echo (int)$row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-warning py-1 px-2 me-1" title="Generate PO Requisition">
                                            <i class="fas fa-file-invoice-dollar"></i> PO
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-gold py-1 px-2 me-1"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#restockModal"
                                            data-id="<?php echo (int)$row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-qty="<?php echo (int)$row['quantity']; ?>"
                                            data-threshold="<?php echo (int)$row['low_stock_threshold']; ?>">
                                         <i class="fas fa-edit me-1"></i> Update
                                    </button>
                                    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to remove \'<?php echo htmlspecialchars(addslashes($row['product_name'])); ?>\' from inventory?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Product">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Restock / Update Modal -->
<div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-elegant">
            <div class="modal-header">
                <h5 class="modal-title" id="restockModalLabel"><i class="fas fa-boxes text-gold me-2"></i>Update Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="product_id" id="modalProductId" value="">

                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Updating quantity above threshold will automatically mark related low-stock alerts as resolved.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" class="form-control" id="modalProductName" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="modalQuantity">Current Stock / Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="quantity" id="modalQuantity" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="modalThreshold">Low Stock Alert Threshold <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="threshold" id="modalThreshold" min="1" required>
                        <small class="text-muted">An automated alert will trigger when quantity &le; threshold.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-elegant">
            <div class="modal-header">
                <h5 class="modal-title" id="addProductModalLabel"><i class="fas fa-plus text-gold me-2"></i>Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_product">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" required placeholder="e.g. Keratin Serum 250ml">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="category" placeholder="e.g. Hair Care">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Initial Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="quantity" min="0" value="20" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Min Threshold <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="threshold" min="1" value="10" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Unit Price ($)</label>
                            <input type="number" step="0.01" class="form-control" name="unit_price" value="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Supplier</label>
                            <input type="text" class="form-control" name="supplier" placeholder="e.g. L'Oreal Paris">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-check me-1"></i> Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const restockModal = document.getElementById('restockModal');
    if (restockModal) {
        restockModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            document.getElementById('modalProductId').value = btn.getAttribute('data-id');
            document.getElementById('modalProductName').value = btn.getAttribute('data-name');
            document.getElementById('modalQuantity').value = btn.getAttribute('data-qty');
            document.getElementById('modalThreshold').value = btn.getAttribute('data-threshold');
        });
    }
});
</script>
</body>
</html>
