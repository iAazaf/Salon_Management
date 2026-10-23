<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'services';
$pageTitle = 'Services Management';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF token mismatch).';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. ADD SERVICE
        if ($action === 'add_service') {
            $name        = trim($_POST['name'] ?? '');
            $category    = trim($_POST['category'] ?? '');
            $duration    = max(5, (int)($_POST['duration'] ?? 30));
            $price       = max(0.01, (float)($_POST['price'] ?? 0));
            $description = trim($_POST['description'] ?? '');
            $status      = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $imagePath   = null;

            if ($name === '' || $category === '') {
                $error = 'Service name and category are required.';
            } else {
                // Handle image upload if provided
                if (!empty($_FILES['service_image']['name']) && $_FILES['service_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/services/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $ext = strtolower(pathinfo($_FILES['service_image']['name'], PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];
                    if (in_array($ext, $allowedExts, true) && $_FILES['service_image']['size'] <= 3 * 1024 * 1024) {
                        $newFilename = 'svc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['service_image']['tmp_name'], $uploadDir . $newFilename)) {
                            $imagePath = 'uploads/services/' . $newFilename;
                        }
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO services (name, category, duration, price, description, status, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $category, $duration, $price, $description, $status, $imagePath]);
                $success = "Service '{$name}' has been added successfully.";
            }
        }

        // 2. EDIT SERVICE
        elseif ($action === 'edit_service') {
            $id          = (int)($_POST['service_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $category    = trim($_POST['category'] ?? '');
            $duration    = max(5, (int)($_POST['duration'] ?? 30));
            $price       = max(0.01, (float)($_POST['price'] ?? 0));
            $description = trim($_POST['description'] ?? '');
            $status      = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

            if ($id <= 0 || $name === '' || $category === '') {
                $error = 'Service ID, name, and category are required.';
            } else {
                // Fetch current image
                $stmtCur = $pdo->prepare("SELECT image FROM services WHERE id = ?");
                $stmtCur->execute([$id]);
                $cur = $stmtCur->fetch(PDO::FETCH_ASSOC);

                $imagePath = $cur['image'] ?? null;

                if (!empty($_FILES['service_image']['name']) && $_FILES['service_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/services/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $ext = strtolower(pathinfo($_FILES['service_image']['name'], PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];
                    if (in_array($ext, $allowedExts, true) && $_FILES['service_image']['size'] <= 3 * 1024 * 1024) {
                        $newFilename = 'svc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['service_image']['tmp_name'], $uploadDir . $newFilename)) {
                            $imagePath = 'uploads/services/' . $newFilename;
                        }
                    }
                }

                $stmt = $pdo->prepare("UPDATE services SET name = ?, category = ?, duration = ?, price = ?, description = ?, status = ?, image = ? WHERE id = ?");
                $stmt->execute([$name, $category, $duration, $price, $description, $status, $imagePath, $id]);
                $success = "Service '{$name}' updated successfully.";
            }
        }

        // 3. TOGGLE STATUS
        elseif ($action === 'toggle_status') {
            $id = (int)($_POST['service_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE services SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Service status toggled.";
            }
        }

        // 4. DELETE SERVICE
        elseif ($action === 'delete_service') {
            $id = (int)($_POST['service_id'] ?? 0);
            if ($id > 0) {
                // Check if existing appointments reference this service
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE service_id = ?");
                $stmtCheck->execute([$id]);
                $apptCount = (int)$stmtCheck->fetchColumn();

                if ($apptCount > 0) {
                    // Soft deactivate instead of hard delete to preserve financial and booking integrity
                    $stmt = $pdo->prepare("UPDATE services SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Service has {$apptCount} existing appointment records. It was safely deactivated instead of deleted.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = "Service deleted successfully.";
                }
            }
        }
    }
}

// Fetch categories for filtering and modals
$categoriesQuery = $pdo->query("SELECT DISTINCT category FROM services WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$allCategories = $categoriesQuery->fetchAll(PDO::FETCH_COLUMN);

// Fetch all services with appointment usage count
$services = $pdo->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM appointments a WHERE a.service_id = s.id) AS appointment_count
    FROM services s 
    ORDER BY s.category ASC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalServices = count($services);
$activeServices = count(array_filter($services, fn($s) => $s['status'] === 'active'));
$inactiveServices = $totalServices - $activeServices;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Elegance Salon Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .service-img-thumb {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--black-card);
        }
        .service-img-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(232, 197, 71, 0.1);
            color: var(--champagne);
            border: 1px solid var(--border);
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="topbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-gold btn-sm d-lg-none me-2" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 text-white"><img src="../assets/img/logo.png" class="salon-mark-icon text-gold me-2" alt="" aria-hidden="true">Services Catalog</h5>
                    <small class="text-muted">Manage beauty, hair, wellness & styling packages</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                    <i class="fas fa-plus-circle me-1"></i> Add Service
                </button>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Metrics Row -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Total Services</div>
                                <div class="kpi-value"><?php echo $totalServices; ?></div>
                                <div class="kpi-sub">Across all categories</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-list-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Active & Available</div>
                                <div class="kpi-value text-success"><?php echo $activeServices; ?></div>
                                <div class="kpi-sub">Visible on booking portal</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Inactive / Archived</div>
                                <div class="kpi-value text-muted"><?php echo $inactiveServices; ?></div>
                                <div class="kpi-sub">Hidden from clients</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-eye-slash"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="card-elegant mb-4 p-3">
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-gold"><i class="fas fa-search"></i></span>
                            <input type="text" id="serviceSearch" class="form-control form-control-elegant" placeholder="Search service name, category, or description...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select id="categoryFilter" class="form-select form-control-elegant">
                            <option value="">All Categories</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select id="statusFilter" class="form-select form-control-elegant">
                            <option value="">All Statuses</option>
                            <option value="active">Active Only</option>
                            <option value="inactive">Inactive Only</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Services Table -->
            <div class="card-elegant">
                <div class="table-responsive">
                    <table class="table table-elegant mb-0" id="servicesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Photo</th>
                                <th>Service Name</th>
                                <th>Category</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>Bookings</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($services)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <img src="../assets/img/logo.png" class="salon-mark-icon fa-2x mb-2 d-block opacity-50" alt="" aria-hidden="true">
                                        No services found. Click "Add Service" to create your first package.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($services as $idx => $s): ?>
                                    <tr class="service-row" 
                                        data-name="<?php echo htmlspecialchars(strtolower($s['name'])); ?>"
                                        data-category="<?php echo htmlspecialchars(strtolower($s['category'] ?? '')); ?>"
                                        data-status="<?php echo htmlspecialchars($s['status']); ?>">
                                        <td><?php echo $idx + 1; ?></td>
                                        <td>
                                            <?php if (!empty($s['image']) && file_exists('../' . $s['image'])): ?>
                                                <img src="../<?php echo htmlspecialchars($s['image']); ?>" alt="Service" class="service-img-thumb">
                                            <?php else: ?>
                                                <div class="service-img-placeholder">
                                                    <i class="fas fa-cut"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-white"><?php echo htmlspecialchars($s['name']); ?></strong>
                                            <?php if (!empty($s['description'])): ?>
                                                <br><small class="text-muted d-inline-block text-truncate" style="max-width: 260px;">
                                                    <?php echo htmlspecialchars($s['description']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-dark border border-secondary text-gold">
                                                <?php echo htmlspecialchars($s['category'] ?? 'General'); ?>
                                            </span>
                                        </td>
                                        <td><i class="far fa-clock text-muted me-1"></i><?php echo (int)$s['duration']; ?> min</td>
                                        <td>
                                            <span class="text-gold fw-bold">$<?php echo number_format((float)$s['price'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo (int)$s['appointment_count']; ?> booked</span>
                                        </td>
                                        <td>
                                            <?php if ($s['status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-gold me-1 btn-edit"
                                                    data-id="<?php echo $s['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($s['name']); ?>"
                                                    data-category="<?php echo htmlspecialchars($s['category'] ?? ''); ?>"
                                                    data-duration="<?php echo $s['duration']; ?>"
                                                    data-price="<?php echo $s['price']; ?>"
                                                    data-description="<?php echo htmlspecialchars($s['description'] ?? ''); ?>"
                                                    data-status="<?php echo $s['status']; ?>"
                                                    title="Edit Service">
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <!-- Toggle Status Form -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status for this service?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary me-1" title="Toggle active/inactive">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            </form>

                                            <!-- Delete Form -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete or deactivate this service?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete or Deactivate">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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

<!-- ================= ADD SERVICE MODAL ================= -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary text-white">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_service">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-plus-circle me-2"></i>Add New Salon Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label-elegant">Service Name *</label>
                            <input type="text" name="name" class="form-control form-control-elegant" placeholder="e.g. Balayage Color & Signature Blowout" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-elegant">Category *</label>
                            <input type="text" name="category" list="categoryOptions" class="form-control form-control-elegant" placeholder="e.g. Hair, Skin, Nails" required>
                            <datalist id="categoryOptions">
                                <option value="Hair">
                                <option value="Skin">
                                <option value="Nails">
                                <option value="Makeup">
                                <option value="Wellness">
                                <option value="Bridal">
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Duration (Minutes) *</label>
                            <input type="number" name="duration" class="form-control form-control-elegant" value="60" min="5" step="5" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Price ($ USD) *</label>
                            <input type="number" name="price" class="form-control form-control-elegant" value="85.00" min="0.01" step="0.50" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-elegant">Description</label>
                            <textarea name="description" class="form-control form-control-elegant" rows="3" placeholder="Explain the treatment, technique, and premium products used..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Service Image (Optional)</label>
                            <input type="file" name="service_image" class="form-control form-control-elegant" accept="image/*">
                            <small class="text-muted">JPG, PNG, WebP up to 3MB.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Initial Status</label>
                            <select name="status" class="form-select form-control-elegant">
                                <option value="active" selected>Active (Available immediately)</option>
                                <option value="inactive">Inactive (Hidden)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-save me-1"></i> Save Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= EDIT SERVICE MODAL ================= -->
<div class="modal fade" id="editServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary text-white">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="edit_service">
                <input type="hidden" name="service_id" id="editServiceId">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-edit me-2"></i>Edit Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label-elegant">Service Name *</label>
                            <input type="text" name="name" id="editName" class="form-control form-control-elegant" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-elegant">Category *</label>
                            <input type="text" name="category" id="editCategory" list="categoryOptions" class="form-control form-control-elegant" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Duration (Minutes) *</label>
                            <input type="number" name="duration" id="editDuration" class="form-control form-control-elegant" min="5" step="5" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Price ($ USD) *</label>
                            <input type="number" name="price" id="editPrice" class="form-control form-control-elegant" min="0.01" step="0.50" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label-elegant">Description</label>
                            <textarea name="description" id="editDescription" class="form-control form-control-elegant" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Replace Image (Optional)</label>
                            <input type="file" name="service_image" class="form-control form-control-elegant" accept="image/*">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-elegant">Status</label>
                            <select name="status" id="editStatus" class="form-select form-control-elegant">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-check-circle me-1"></i> Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Live filter and search
    const searchInput = document.getElementById('serviceSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('.service-row');

    function filterTable() {
        const query = searchInput.value.toLowerCase().trim();
        const cat = categoryFilter.value.toLowerCase().trim();
        const stat = statusFilter.value.trim();

        rows.forEach(row => {
            const name = row.getAttribute('data-name');
            const category = row.getAttribute('data-category');
            const status = row.getAttribute('data-status');

            const matchesQuery = query === '' || name.includes(query) || category.includes(query);
            const matchesCat = cat === '' || category === cat;
            const matchesStat = stat === '' || status === stat;

            row.style.display = (matchesQuery && matchesCat && matchesStat) ? '' : 'none';
        });
    }

    searchInput.addEventListener('input', filterTable);
    categoryFilter.addEventListener('change', filterTable);
    statusFilter.addEventListener('change', filterTable);

    // Edit modal population
    const editModal = new bootstrap.Modal(document.getElementById('editServiceModal'));
    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('editServiceId').value = this.dataset.id;
            document.getElementById('editName').value = this.dataset.name;
            document.getElementById('editCategory').value = this.dataset.category;
            document.getElementById('editDuration').value = this.dataset.duration;
            document.getElementById('editPrice').value = this.dataset.price;
            document.getElementById('editDescription').value = this.dataset.description;
            document.getElementById('editStatus').value = this.dataset.status;
            editModal.show();
        });
    });
});
</script>
</body>
</html>
