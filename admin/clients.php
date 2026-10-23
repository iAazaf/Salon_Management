<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'clients';
$pageTitle = 'Clients Directory';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// Handle Add Client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $name        = trim($_POST['name'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $gender      = in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true) ? $_POST['gender'] : null;
        $preferences = trim($_POST['preferences'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        if ($name === '' || $phone === '') {
            $error = 'Client name and phone number are required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO clients (name, phone, email, gender, preferences, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email ?: null, $gender, $preferences ?: null, $notes ?: null]);
            $success = "Client '{$name}' has been added successfully.";
        }
    }
}

// Handle Edit Client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_client') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $clientId    = (int)($_POST['client_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $gender      = in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true) ? $_POST['gender'] : null;
        $preferences = trim($_POST['preferences'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        if ($clientId <= 0 || $name === '' || $phone === '') {
            $error = 'Valid client ID, name, and phone number are required.';
        } else {
            $stmt = $pdo->prepare("UPDATE clients SET name = ?, phone = ?, email = ?, gender = ?, preferences = ?, notes = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $email ?: null, $gender, $preferences ?: null, $notes ?: null, $clientId]);
            $success = "Client '{$name}' updated successfully.";
        }
    }
}

// Handle Delete Client (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_client') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF).';
    } elseif (($_SESSION['user_role'] ?? '') !== 'admin') {
        $error = 'Unauthorized: Only administrators can delete client records.';
    } else {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId <= 0) {
            $error = 'Invalid client ID.';
        } else {
            // Check for existing appointments
            $chk = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE client_id = ?");
            $chk->execute([$clientId]);
            $apptCount = (int)$chk->fetchColumn();

            $paymentCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE client_id = ?");
            $paymentCheck->execute([$clientId]);
            $paymentCount = (int)$paymentCheck->fetchColumn();

            if ($apptCount > 0 || $paymentCount > 0) {
                $error = "Cannot delete client: {$apptCount} appointment and {$paymentCount} payment records exist. Client and financial history must be preserved.";
            } else {
                $del = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                $del->execute([$clientId]);
                $success = "Client record deleted successfully.";
            }
        }
    }
}

// Fetch all clients with visit counts and lifetime spend
$clients = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT a.id) as total_visits,
           MAX(a.appointment_date) as last_visit,
           COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount ELSE 0 END), 0) as total_spent
    FROM clients c
    LEFT JOIN appointments a ON a.client_id = c.id
    LEFT JOIN payments p ON p.client_id = c.id
    GROUP BY c.id
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalClients = count($clients);
$repeatClients = count(array_filter($clients, fn($c) => (int)$c['total_visits'] > 1));
$newClients = count(array_filter($clients, fn($c) => strtotime($c['created_at']) >= strtotime('-30 days')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
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
                    <h5 class="mb-0 text-white"><i class="fas fa-users text-gold me-2"></i>Client Management</h5>
                    <small class="text-muted">Registered salon guests, visit history & loyalty</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-user-plus me-1"></i> Add Client
                </button>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Metrics -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Total Clients</div>
                                <div class="kpi-value"><?php echo $totalClients; ?></div>
                                <div class="kpi-sub">Registered guest accounts</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">Repeat Visitors</div>
                                <div class="kpi-value text-gold"><?php echo $repeatClients; ?></div>
                                <div class="kpi-sub">Clients with 2+ visits</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-heart"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="kpi-label">New This Month</div>
                                <div class="kpi-value text-success"><?php echo $newClients; ?></div>
                                <div class="kpi-sub">Joined in the last 30 days</div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-user-check"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search Control -->
            <div class="card-elegant mb-4 p-3">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-gold"><i class="fas fa-search"></i></span>
                            <input type="text" id="clientSearch" class="form-control form-control-elegant" placeholder="Search by client name, email, or phone number...">
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-2 mt-md-0">
                        <span class="text-muted small">Showing <?php echo count($clients); ?> clients</span>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card-elegant">
                <div class="table-responsive">
                    <table class="table table-elegant mb-0" id="clientsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client Name</th>
                                <th>Contact Information</th>
                                <th>Gender</th>
                                <th>Total Visits</th>
                                <th>Total Spent</th>
                                <th>Preferences / Notes</th>
                                <th>Last Appointment</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clients)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="fas fa-users fa-2x mb-2 d-block opacity-50"></i>
                                        No registered clients found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clients as $i => $row): ?>
                                    <tr class="client-row"
                                        data-name="<?php echo htmlspecialchars(strtolower($row['name'])); ?>"
                                        data-phone="<?php echo htmlspecialchars($row['phone']); ?>"
                                        data-email="<?php echo htmlspecialchars(strtolower($row['email'] ?? '')); ?>">
                                        <td><?php echo $i + 1; ?></td>
                                        <td>
                                            <strong class="text-white d-block"><?php echo htmlspecialchars($row['name']); ?></strong>
                                            <small class="text-muted">Since <?php echo date('M Y', strtotime($row['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="text-white"><i class="fas fa-phone me-1 text-gold small"></i><?php echo htmlspecialchars($row['phone']); ?></div>
                                            <?php if (!empty($row['email'])): ?>
                                                <small class="text-muted"><i class="fas fa-envelope me-1 small"></i><?php echo htmlspecialchars($row['email']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-dark border border-secondary text-gold">
                                                <?php echo htmlspecialchars(ucfirst($row['gender'] ?? 'Not specified')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo (int)$row['total_visits']; ?> visits</span>
                                        </td>
                                        <td>
                                            <strong class="text-gold">$<?php echo number_format((float)$row['total_spent'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['preferences'])): ?>
                                                <div class="text-gold small mb-1"><i class="fas fa-heart me-1"></i><?php echo htmlspecialchars($row['preferences']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['notes'])): ?>
                                                <small class="text-muted d-block text-truncate" style="max-width: 180px;">
                                                    <?php echo htmlspecialchars($row['notes']); ?>
                                                </small>
                                            <?php elseif (empty($row['preferences'])): ?>
                                                <span class="text-muted small">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo !empty($row['last_visit']) ? date('d M Y', strtotime($row['last_visit'])) : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-gold py-1 px-2 edit-client-btn"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-phone="<?php echo htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-email="<?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-gender="<?php echo htmlspecialchars($row['gender'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-preferences="<?php echo htmlspecialchars($row['preferences'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-notes="<?php echo htmlspecialchars($row['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </button>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete client \'<?php echo htmlspecialchars(addslashes($row['name'])); ?>\'?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="delete_client">
                                                    <input type="hidden" name="client_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Client">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
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

<!-- Modal: Add Client -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary text-white">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_client">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-user-plus me-2"></i>Register New Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-elegant">Full Name *</label>
                        <input type="text" name="name" class="form-control form-control-elegant" placeholder="e.g. Olivia Martin" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Phone Number *</label>
                        <input type="text" name="phone" class="form-control form-control-elegant" placeholder="e.g. 03001234567" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-elegant" placeholder="client@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Gender</label>
                        <select name="gender" class="form-select form-control-elegant">
                            <option value="">Select Gender...</option>
                            <option value="female">Female</option>
                            <option value="male">Male</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Preferences (e.g. Preferred Stylist, Hair Treatment)</label>
                        <input type="text" name="preferences" class="form-control form-control-elegant" placeholder="e.g. Prefers organic hair products, stylist Sarah">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Client Notes</label>
                        <textarea name="notes" class="form-control form-control-elegant" rows="2" placeholder="Allergies, general notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-save me-1"></i> Save Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Client -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary text-white">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit_client">
                <input type="hidden" name="client_id" id="editClientId">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-user-edit me-2"></i>Edit Client Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-elegant">Full Name *</label>
                        <input type="text" name="name" id="editClientName" class="form-control form-control-elegant" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Phone Number *</label>
                        <input type="text" name="phone" id="editClientPhone" class="form-control form-control-elegant" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Email Address</label>
                        <input type="email" name="email" id="editClientEmail" class="form-control form-control-elegant">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Gender</label>
                        <select name="gender" id="editClientGender" class="form-select form-control-elegant">
                            <option value="">Select Gender...</option>
                            <option value="female">Female</option>
                            <option value="male">Male</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Preferences (e.g. Preferred Stylist, Hair Treatment)</label>
                        <input type="text" name="preferences" id="editClientPreferences" class="form-control form-control-elegant">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Client Notes</label>
                        <textarea name="notes" id="editClientNotes" class="form-control form-control-elegant" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-save me-1"></i> Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('clientSearch');
    const rows = document.querySelectorAll('.client-row');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            rows.forEach(row => {
                const name = row.getAttribute('data-name');
                const phone = row.getAttribute('data-phone');
                const email = row.getAttribute('data-email');
                const matches = query === '' || name.includes(query) || phone.includes(query) || email.includes(query);
                row.style.display = matches ? '' : 'none';
            });
        });
    }

    const editModal = new bootstrap.Modal(document.getElementById('editClientModal'));
    document.querySelectorAll('.edit-client-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('editClientId').value = this.dataset.id;
            document.getElementById('editClientName').value = this.dataset.name;
            document.getElementById('editClientPhone').value = this.dataset.phone;
            document.getElementById('editClientEmail').value = this.dataset.email || '';
            document.getElementById('editClientGender').value = this.dataset.gender || '';
            document.getElementById('editClientPreferences').value = this.dataset.preferences || '';
            document.getElementById('editClientNotes').value = this.dataset.notes || '';
            editModal.show();
        });
    });
});
</script>
</body>
</html>
