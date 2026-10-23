<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Authentication & Role Check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? '';
if ($role !== 'stylist') {
    if (in_array($role, ['admin', 'receptionist'], true)) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../customer/index.php');
    }
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

// 2. Verify stylist user exists and is active
$userId = (int)$_SESSION['user_id'];
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'stylist' AND status = 'active' LIMIT 1");
$stmtUser->execute([$userId]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    session_unset();
    session_destroy();
    header('Location: ../login.php?error=inactive');
    exit;
}

// 3. Fetch linked staff profile
$stmtStaff = $pdo->prepare("SELECT * FROM staff WHERE user_id = ? LIMIT 1");
$stmtStaff->execute([$userId]);
$staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    $insertStaff = $pdo->prepare("
        INSERT INTO staff (user_id, specialization, commission_rate, schedule)
        VALUES (?, 'Professional Stylist', 10.00, 'Mon - Sat: 10:00 AM - 7:00 PM')
    ");
    $insertStaff->execute([$userId]);
    $staffId = (int)$pdo->lastInsertId();
    $stmtStaff->execute([$userId]);
    $staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);
} else {
    $staffId = (int)$staff['id'];
}

// 4. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// 5. Handle Status Update Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $error = 'Security validation failed (CSRF). Please refresh.';
    } else {
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];

        if ($apptId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid appointment or status selected.';
        } else {
            $updateStmt = $pdo->prepare("
                UPDATE appointments 
                SET status = ? 
                WHERE id = ? AND staff_id = ?
            ");
            $updateStmt->execute([$newStatus, $apptId, $staffId]);

            if ($updateStmt->rowCount() > 0) {
                notify_appointment_event($pdo, $apptId, $newStatus);
                $success = "Appointment #{$apptId} status updated to " . ucfirst($newStatus) . ".";
            } else {
                $error = "Appointment #{$apptId} could not be updated. Verify ownership.";
            }
        }
    }
}

// 6. Handle Stylist Profile Update
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $error = 'Security validation failed (CSRF).';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        $bio   = trim($_POST['bio'] ?? '');

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$phone !== '' ? $phone : null, $userId]);
            $pdo->prepare("UPDATE staff SET bio = ? WHERE id = ?")->execute([$bio !== '' ? $bio : null, $staffId]);
            $pdo->commit();
            $success = "Your profile has been updated successfully.";

            $currentUser['phone'] = $phone;
            $staff['bio'] = $bio;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Error updating profile: " . $e->getMessage();
        }
    }
}

// 7. Calculate Stylist-Specific KPI Statistics
$totalAppts = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ?");
$totalAppts->execute([$staffId]);
$totalCount = (int)$totalAppts->fetchColumn();

$todayAppts = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ? AND appointment_date = CURDATE()");
$todayAppts->execute([$staffId]);
$todayCount = (int)$todayAppts->fetchColumn();

$upcomingAppts = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ? AND appointment_date >= CURDATE() AND status IN ('pending', 'confirmed')");
$upcomingAppts->execute([$staffId]);
$upcomingCount = (int)$upcomingAppts->fetchColumn();

$completedAppts = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ? AND status = 'completed'");
$completedAppts->execute([$staffId]);
$completedCount = (int)$completedAppts->fetchColumn();

// Estimated Commission earned on completed appointments
$commRate = (float)($staff['commission_rate'] ?? 10.00);
$commStmt = $pdo->prepare("
    SELECT COALESCE(SUM(s.price * (? / 100.0)), 0)
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    WHERE a.staff_id = ? AND a.status = 'completed'
");
$commStmt->execute([$commRate, $staffId]);
$earnedCommission = (float)$commStmt->fetchColumn();

// 8. Fetch Assigned Appointments (Filtered)
$filter = $_GET['filter'] ?? 'all';
$sql = "
    SELECT a.*, c.name as client_name, c.email as client_email, c.phone as client_phone,
           c.preferences as client_preferences, c.notes as client_history,
           s.name as service_name, s.duration, s.price, s.category as service_category
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    JOIN services s ON a.service_id = s.id
    WHERE a.staff_id = ?
";
$params = [$staffId];

if ($filter === 'today') {
    $sql .= " AND a.appointment_date = CURDATE()";
} elseif ($filter === 'upcoming') {
    $sql .= " AND a.appointment_date >= CURDATE() AND a.status IN ('pending', 'confirmed')";
} elseif ($filter === 'completed') {
    $sql .= " AND a.status = 'completed'";
} elseif ($filter === 'cancelled') {
    $sql .= " AND a.status IN ('cancelled', 'no-show')";
}

$sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";

$apptStmt = $pdo->prepare($sql);
$apptStmt->execute($params);
$myAppointments = $apptStmt->fetchAll(PDO::FETCH_ASSOC);

// Page headers
$pageTitle = 'Stylist Portal';
$activePage = 'stylist';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4 py-lg-5">

    <!-- Flash Messages -->
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

    <!-- Stylist Profile Hero Card (Consistent with Homepage Card Style) -->
    <div class="card-elegant mb-4">
        <div class="row align-items-center g-4">
            <div class="col-auto">
                <div class="stylist-avatar" style="width: 76px; height: 76px; border-radius: 50%; background: var(--black-soft); border: 2px solid var(--champagne); display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--champagne); font-family: 'Playfair Display', serif;">
                    <?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?>
                </div>
            </div>
            <div class="col">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="section-kicker mb-0">STYLIST PORTFOLIO</span>
                    <span class="badge bg-dark border border-secondary text-gold">
                        <?php echo number_format($commRate, 1); ?>% Commission
                    </span>
                    <span class="badge bg-success">
                        Active Stylist
                    </span>
                </div>

                <h2 class="h3 mb-2" style="color: var(--cream); font-family: 'Playfair Display', serif;">
                    <?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8'); ?>
                </h2>

                <p class="text-gold mb-2" style="font-size: 0.95rem;">
                    <i class="fas fa-cut me-1"></i>
                    <?php echo htmlspecialchars($staff['specialization'] ?: 'Professional Hair & Beauty Stylist', ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <div class="d-flex flex-wrap gap-4 text-gray small">
                    <span><i class="fas fa-envelope me-1 text-gold"></i><?php echo htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span><i class="fas fa-phone me-1 text-gold"></i><?php echo htmlspecialchars($currentUser['phone'] ?: 'No phone added', ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($staff['schedule'])): ?>
                        <span><i class="fas fa-clock me-1 text-gold"></i><?php echo htmlspecialchars($staff['schedule'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($staff['bio'])): ?>
                    <p class="text-white small mt-2 mb-0 fst-italic">
                        "<?php echo htmlspecialchars($staff['bio'], ENT_QUOTES, 'UTF-8'); ?>"
                    </p>
                <?php endif; ?>
            </div>
            <div class="col-md-auto text-md-end">
                <button type="button" class="btn btn-outline-gold btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    <i class="fas fa-user-edit me-1"></i> Edit Profile
                </button>
            </div>
        </div>
    </div>

    <!-- KPI Metric Cards (Matching Homepage Stats Layout) -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Today's Clients</div>
                        <div class="kpi-value"><?php echo $todayCount; ?></div>
                        <small class="text-white">Appointments today</small>
                    </div>
                    <div class="kpi-icon"><i class="fas fa-calendar-day"></i></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Upcoming Schedule</div>
                        <div class="kpi-value text-gold"><?php echo $upcomingCount; ?></div>
                        <small class="text-gray">Pending & confirmed</small>
                    </div>
                    <div class="kpi-icon"><i class="fas fa-hourglass-half"></i></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Completed Services</div>
                        <div class="kpi-value text-success"><?php echo $completedCount; ?></div>
                        <small class="text-gray">Total treatments finished</small>
                    </div>
                    <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="kpi-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpi-label">Earned Commission</div>
                        <div class="kpi-value text-gold">$<?php echo number_format($earnedCommission, 2); ?></div>
                        <small class="text-gray"><?php echo number_format($commRate, 1); ?>% of completed</small>
                    </div>
                    <div class="kpi-icon"><i class="fas fa-coins"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointments Section -->
    <div class="card-elegant">
        <!-- Header & Filter Navigation -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pb-3 mb-3 border-bottom" style="border-color: var(--border) !important;">
            <div>
                <h4 class="mb-0 text-white" style="font-family: 'Playfair Display', serif;">
                    <i class="fas fa-calendar-check me-2 text-gold"></i>Assigned Appointments
                </h4>
                <p class="text-muted small mb-0">Manage client schedules and update treatment statuses</p>
            </div>

            <!-- Filter Buttons -->
            <div class="d-flex flex-wrap gap-2">
                <a href="?filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-gold' : 'btn-outline-gold'; ?>">
                    All (<?php echo $totalCount; ?>)
                </a>
                <a href="?filter=today" class="btn btn-sm <?php echo $filter === 'today' ? 'btn-gold' : 'btn-outline-gold'; ?>">
                    Today (<?php echo $todayCount; ?>)
                </a>
                <a href="?filter=upcoming" class="btn btn-sm <?php echo $filter === 'upcoming' ? 'btn-gold' : 'btn-outline-gold'; ?>">
                    Upcoming (<?php echo $upcomingCount; ?>)
                </a>
                <a href="?filter=completed" class="btn btn-sm <?php echo $filter === 'completed' ? 'btn-gold' : 'btn-outline-gold'; ?>">
                    Completed (<?php echo $completedCount; ?>)
                </a>
                <a href="?filter=cancelled" class="btn btn-sm <?php echo $filter === 'cancelled' ? 'btn-gold' : 'btn-outline-gold'; ?>">
                    Cancelled
                </a>
            </div>
        </div>

        <!-- Appointments Table -->
        <div class="table-responsive">
            <table class="table table-elegant mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Client</th>
                        <th>Service Requested</th>
                        <th>Duration &amp; Fee</th>
                        <th>Status</th>
                        <th class="text-end">Update Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myAppointments)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="fas fa-calendar-alt fa-2x mb-2 d-block text-gold opacity-50"></i>
                                <h6 class="text-white">No appointments found</h6>
                                <p class="text-muted small mb-0">
                                    <?php if ($filter !== 'all'): ?>
                                        No bookings under "<?php echo htmlspecialchars(ucfirst($filter)); ?>". <a href="dashboard.php" class="text-gold">View all</a>
                                    <?php else: ?>
                                        You currently have no clients assigned.
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($myAppointments as $i => $row): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <strong class="text-white d-block"><?php echo date('D, d M Y', strtotime($row['appointment_date'])); ?></strong>
                                    <span class="text-gold small">
                                        <i class="far fa-clock me-1"></i><?php echo date('h:i A', strtotime($row['appointment_time'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-white d-block">
                                        <i class="fas fa-user me-1 text-gold small"></i><?php echo htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>
                                    <small class="text-muted d-block">
                                        <a href="tel:<?php echo htmlspecialchars($row['client_phone'], ENT_QUOTES, 'UTF-8'); ?>" class="text-muted text-decoration-none">
                                            <i class="fas fa-phone me-1 small"></i><?php echo htmlspecialchars($row['client_phone'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </small>
                                    <?php if (!empty($row['notes'])): ?>
                                        <small class="text-muted d-block fst-italic mt-1" style="max-width: 220px;">
                                            Note: <?php echo htmlspecialchars($row['notes'], ENT_QUOTES, 'UTF-8'); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-white d-block"><?php echo htmlspecialchars($row['service_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($row['service_category'])): ?>
                                        <span class="badge bg-dark border border-secondary text-gold"><?php echo htmlspecialchars($row['service_category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-gold d-block">$<?php echo number_format($row['price'], 2); ?></strong>
                                    <small class="text-muted"><?php echo (int)$row['duration']; ?> min</small>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline-flex justify-content-end">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">

                                        <select name="status" class="form-select form-select-sm form-control-elegant" style="width: auto;" onchange="this.form.submit()">
                                            <option value="" disabled>Change Status...</option>
                                            <option value="pending" <?php echo $row['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="confirmed" <?php echo $row['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="completed" <?php echo $row['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $row['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="no-show" <?php echo $row['status'] === 'no-show' ? 'selected' : ''; ?>>No-Show</option>
                                        </select>
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

<!-- Modal: Update Contact & Bio -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary text-white">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-user-edit me-2"></i>Update Stylist Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-elegant">Full Name</label>
                        <input type="text" class="form-control form-control-elegant" value="<?php echo htmlspecialchars($currentUser['name']); ?>" disabled>
                        <small class="text-muted">Account name is managed by salon administrators.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Email Address</label>
                        <input type="email" class="form-control form-control-elegant" value="<?php echo htmlspecialchars($currentUser['email']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Phone Number</label>
                        <input type="text" name="phone" class="form-control form-control-elegant" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>" placeholder="e.g. 03001234567">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-elegant">Bio / Personal Greeting</label>
                        <textarea name="bio" class="form-control form-control-elegant" rows="3" placeholder="Share your styling specialty, experience or welcome note..."><?php echo htmlspecialchars($staff['bio'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-gold"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
