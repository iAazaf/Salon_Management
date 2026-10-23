<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';

// Strict Admin-Only Access: Receptionists and Stylists cannot manage staff accounts
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$adminPage = 'staff';
$pageTitle = 'Staff Management';

// CSRF Token initialization
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. CREATE NEW STYLIST
        if ($action === 'create_stylist') {
            $name           = trim($_POST['name'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $password       = $_POST['password'] ?? '';
            $phone          = trim($_POST['phone'] ?? '');
            $specialization = trim($_POST['specialization'] ?? '');
            $schedule       = trim($_POST['schedule'] ?? '');
            $commission     = trim($_POST['commission_rate'] ?? '10.00');
            $bio            = trim($_POST['bio'] ?? '');

            if ($name === '' || $email === '' || $password === '') {
                $error = 'Name, email, and password are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please provide a valid email address.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long.';
            } elseif (!is_numeric($commission) || (float)$commission < 0 || (float)$commission > 100) {
                $error = 'Commission rate must be a valid percentage between 0 and 100.';
            } else {
                // Check if email is already registered in users table
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'A user account with this email already exists.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Securely hash password
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        // Insert user record with role 'stylist' and status 'active'
                        $stmtUser = $pdo->prepare("
                            INSERT INTO users (name, email, phone, password, role, status)
                            VALUES (?, ?, ?, ?, 'stylist', 'active')
                        ");
                        $stmtUser->execute([
                            $name,
                            $email,
                            $phone !== '' ? $phone : null,
                            $passwordHash
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

                        // Insert linked staff record
                        $stmtStaff = $pdo->prepare("
                            INSERT INTO staff (user_id, specialization, commission_rate, schedule, bio)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmtStaff->execute([
                            $newUserId,
                            $specialization !== '' ? $specialization : 'General Stylist',
                            (float)$commission,
                            $schedule !== '' ? $schedule : 'Mon - Sat: 10:00 AM - 7:00 PM',
                            $bio !== '' ? $bio : null
                        ]);

                        $pdo->commit();
                        $success = "Stylist account for '{$name}' created successfully. They can now log in using {$email}.";
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Error creating stylist: ' . $e->getMessage();
                    }
                }
            }
        }

        // 2. EDIT STYLIST
        elseif ($action === 'edit_stylist') {
            $staffId        = (int)($_POST['staff_id'] ?? 0);
            $name           = trim($_POST['name'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $specialization = trim($_POST['specialization'] ?? '');
            $schedule       = trim($_POST['schedule'] ?? '');
            $commission     = trim($_POST['commission_rate'] ?? '10.00');
            $bio            = trim($_POST['bio'] ?? '');
            $status         = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $newPassword    = $_POST['new_password'] ?? '';

            if ($staffId <= 0 || $name === '') {
                $error = 'Valid stylist and name are required.';
            } elseif (!is_numeric($commission) || (float)$commission < 0 || (float)$commission > 100) {
                $error = 'Commission rate must be between 0 and 100.';
            } else {
                $stmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ? LIMIT 1");
                $stmt->execute([$staffId]);
                $userId = $stmt->fetchColumn();

                if (!$userId) {
                    $error = 'Stylist record not found.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Update users table
                        if ($newPassword !== '') {
                            if (strlen($newPassword) < 6) {
                                throw new Exception('New password must be at least 6 characters.');
                            }
                            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                            $stmtU = $pdo->prepare("UPDATE users SET name = ?, phone = ?, status = ?, password = ? WHERE id = ?");
                            $stmtU->execute([$name, $phone !== '' ? $phone : null, $status, $hash, $userId]);
                        } else {
                            $stmtU = $pdo->prepare("UPDATE users SET name = ?, phone = ?, status = ? WHERE id = ?");
                            $stmtU->execute([$name, $phone !== '' ? $phone : null, $status, $userId]);
                        }

                        // Update staff table
                        $stmtS = $pdo->prepare("UPDATE staff SET specialization = ?, commission_rate = ?, schedule = ?, bio = ? WHERE id = ?");
                        $stmtS->execute([$specialization, (float)$commission, $schedule, $bio !== '' ? $bio : null, $staffId]);

                        $pdo->commit();
                        $success = "Stylist profile updated successfully.";
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Error updating stylist: ' . $e->getMessage();
                    }
                }
            }
        }

        // 3. TOGGLE STATUS
        elseif ($action === 'toggle_status') {
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT u.id, u.status 
                FROM staff s 
                JOIN users u ON s.user_id = u.id 
                WHERE s.id = ? 
                LIMIT 1
            ");
            $stmt->execute([$staffId]);
            $u = $stmt->fetch();

            if ($u) {
                $nextStatus = ($u['status'] === 'active') ? 'inactive' : 'active';
                $stmtUp = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmtUp->execute([$nextStatus, $u['id']]);
                $success = "Stylist status changed to " . ucfirst($nextStatus) . ".";
            } else {
                $error = 'Stylist record not found.';
            }
        }
    }
}

// Fetch all staff / stylists
$stylists = $pdo->query("
    SELECT s.id as staff_id, s.specialization, s.commission_rate, s.schedule, s.bio,
           u.id as user_id, u.name, u.email, u.phone, u.status, u.created_at,
           (SELECT COUNT(*) FROM appointments a WHERE a.staff_id = s.id) as total_appointments,
           (SELECT COUNT(*) FROM appointments a WHERE a.staff_id = s.id AND a.status = 'completed') as completed_appointments
    FROM staff s
    JOIN users u ON s.user_id = u.id
    ORDER BY u.created_at DESC
")->fetchAll();

$totalStaff = count($stylists);
$activeStaff = 0;
foreach ($stylists as $st) {
    if ($st['status'] === 'active') $activeStaff++;
}
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
    <style>
        .staff-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(13,148,136,0.4), rgba(232,197,71,0.3));
            color: var(--champagne);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            border: 1px solid var(--border);
        }
        .modal-content-elegant {
            background: var(--black-card);
            border: 1px solid var(--border);
            color: var(--cream);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .modal-content-elegant .modal-header {
            border-bottom: 1px solid var(--border);
        }
        .modal-content-elegant .modal-footer {
            border-top: 1px solid var(--border);
        }
        .modal-content-elegant .form-control,
        .modal-content-elegant .form-select {
            background: var(--black-soft);
            border: 1px solid rgba(232, 197, 71, 0.25);
            color: var(--cream);
            border-radius: 10px;
        }
        .modal-content-elegant .form-control:focus,
        .modal-content-elegant .form-select:focus {
            border-color: var(--champagne);
            background: var(--black-soft);
            color: var(--cream);
            box-shadow: 0 0 0 0.2rem rgba(232, 197, 71, 0.15);
        }
        .modal-content-elegant .btn-close {
            filter: invert(1);
        }
        .badge-active {
            background: rgba(13, 148, 136, 0.25);
            color: var(--burgundy-light);
            border: 1px solid rgba(45, 212, 191, 0.35);
        }
        .badge-inactive {
            background: rgba(220, 53, 69, 0.2);
            color: #f87171;
            border: 1px solid rgba(220, 53, 69, 0.35);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-gold btn-sm d-lg-none me-2" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0">Staff Management</h5>
                    <small class="text-muted">Manage Salon Stylists, Schedules &amp; Commission Rates</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#createStylistModal">
                    <i class="fas fa-plus me-1"></i> Add New Stylist
                </button>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-label">Total Stylists</div>
                                <div class="kpi-value"><?php echo $totalStaff; ?></div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-user-tie"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-label">Active Stylists</div>
                                <div class="kpi-value text-success"><?php echo $activeStaff; ?></div>
                            </div>
                            <div class="kpi-icon" style="background: rgba(45, 212, 191, 0.2); color: var(--burgundy-light);"><i class="fas fa-user-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="kpi-label">Stylist Login Portal</div>
                                <div class="kpi-value" style="font-size: 1.1rem; font-weight: 500; margin-top: 10px;">
                                    <a href="../login.php" target="_blank" class="text-gold text-decoration-none">
                                        Open Login Page <i class="fas fa-external-link-alt ms-1 small"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="kpi-icon"><i class="fas fa-key"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stylists Directory -->
            <div class="card-elegant">
                <div class="d-flex justify-content-between align-items-center p-3 border-bottom" style="border-color: var(--border) !important;">
                    <h6 class="mb-0 text-white"><i class="fas fa-users-cog me-2 text-gold"></i>Stylists &amp; Staff Directory</h6>
                    <span class="badge bg-sage"><?php echo $totalStaff; ?> Registered</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-elegant mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Stylist</th>
                                <th>Contact</th>
                                <th>Specialization</th>
                                <th>Work Schedule</th>
                                <th>Commission</th>
                                <th>Appointments</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stylists)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="fas fa-user-tie fa-2x mb-3 d-block text-gold"></i>
                                        No stylists created yet. Click <strong>"Add New Stylist"</strong> above to create your first stylist account.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stylists as $i => $row): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="staff-avatar">
                                                    <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <strong class="text-white d-block"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <small class="text-muted"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <small class="text-white">
                                                <i class="fas fa-phone me-1 text-gold"></i>
                                                <?php echo htmlspecialchars($row['phone'] ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: rgba(13,148,136,0.25); color: var(--burgundy-light); border: 1px solid rgba(45,212,191,0.25);">
                                                <?php echo htmlspecialchars($row['specialization'] ?: 'General Stylist', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted" style="max-width: 180px; display: inline-block;">
                                                <?php echo htmlspecialchars($row['schedule'] ?: 'Mon-Sat: 10AM-7PM', ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="text-gold fw-bold"><?php echo number_format((float)$row['commission_rate'], 1); ?>%</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo (int)$row['total_appointments']; ?> total</span>
                                            <small class="d-block text-muted"><?php echo (int)$row['completed_appointments']; ?> completed</small>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $row['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-gold" 
                                                        title="Edit Stylist"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editStylistModal<?php echo $row['staff_id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Toggle status for this stylist?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="staff_id" value="<?php echo (int)$row['staff_id']; ?>">
                                                    <button type="submit" class="btn <?php echo $row['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>" 
                                                            title="<?php echo $row['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                </form>
                                            </div>

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

<?php foreach ($stylists as $row): ?>
    <div class="modal fade" id="editStylistModal<?php echo (int)$row['staff_id']; ?>" tabindex="-1" aria-labelledby="editStylistModalLabel<?php echo (int)$row['staff_id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-elegant">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="edit_stylist">
                    <input type="hidden" name="staff_id" value="<?php echo (int)$row['staff_id']; ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="editStylistModalLabel<?php echo (int)$row['staff_id']; ?>"><i class="fas fa-user-edit me-2 text-gold"></i>Edit Stylist: <?php echo htmlspecialchars($row['name']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-start">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($row['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Email (Login Identity)</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($row['email']); ?>" readonly disabled>
                            <small class="text-muted">Email identity cannot be modified after registration.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($row['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Specialization</label>
                            <input type="text" name="specialization" class="form-control" value="<?php echo htmlspecialchars($row['specialization'] ?? ''); ?>" placeholder="e.g. Master Colorist &amp; Hair Styling">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Work Schedule</label>
                            <input type="text" name="schedule" class="form-control" value="<?php echo htmlspecialchars($row['schedule'] ?? ''); ?>" placeholder="e.g. Mon-Fri: 9:00 AM - 6:00 PM">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Commission Rate (%)</label>
                            <input type="number" step="0.5" min="0" max="100" name="commission_rate" class="form-control" value="<?php echo htmlspecialchars($row['commission_rate']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo $row['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $row['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Reset Password (leave empty to keep current)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="New password (optional)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Bio / Notes</label>
                            <textarea name="bio" class="form-control" rows="2"><?php echo htmlspecialchars($row['bio'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal: Create New Stylist -->
<div class="modal fade" id="createStylistModal" tabindex="-1" aria-labelledby="createStylistModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-elegant">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create_stylist">

                <div class="modal-header">
                    <h5 class="modal-title" id="createStylistModalLabel">
                        <i class="fas fa-user-plus me-2 text-gold"></i>Create New Stylist Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="small text-muted mb-4">
                        Register a professional stylist account. Passwords will be securely hashed with <code>password_hash()</code>. Stylists will log in at the main login page and be redirected to their dedicated Stylist Dashboard.
                    </p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Elena Vance" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Email Address (Login Username) <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. elena@elegancesalon.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Initial Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="At least 6 characters" minlength="6" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. 03001234567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Specialization</label>
                            <input type="text" name="specialization" class="form-control" placeholder="e.g. Precision Cutting &amp; Balayage">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Commission Rate (%)</label>
                            <input type="number" step="0.5" min="0" max="100" name="commission_rate" class="form-control" value="15.0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">Work Schedule</label>
                            <input type="text" name="schedule" class="form-control" placeholder="e.g. Monday - Friday: 10:00 AM - 7:00 PM">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">Bio / Experience (Optional)</label>
                            <textarea name="bio" class="form-control" rows="2" placeholder="Brief background, certifications or expertise"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm">
                        <i class="fas fa-check me-1"></i> Create Stylist Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
