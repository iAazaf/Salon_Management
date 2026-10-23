<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';

$adminPage = 'appointments';
$pageTitle = 'Appointments';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Status changes must be authenticated POSTs with a valid session CSRF token.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? null) === 'update_status') {
    $csrf = $_POST['csrf_token'] ?? null;
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if (!is_string($csrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $postedId = $_POST['appointment_id'] ?? null;
        $postedStatus = $_POST['status'] ?? null;
        $allowedStatuses = ['confirmed', 'completed', 'cancelled', 'no-show', 'pending'];

        if (
            (!is_string($postedId) && !is_int($postedId))
            || !ctype_digit((string)$postedId)
            || (int)$postedId < 1
            || !is_string($postedStatus)
            || !in_array($postedStatus, $allowedStatuses, true)
        ) {
            $error = 'Invalid appointment or status.';
        } else {
            $id = (int)$postedId;
            $stmt = $pdo->prepare("SELECT status FROM appointments WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $currentStatus = $stmt->fetchColumn();

            if ($currentStatus === false) {
                $error = 'Appointment not found. Refresh the page and try again.';
            } elseif ($currentStatus === $postedStatus) {
                $message = "Appointment #$id is already " . $postedStatus . '.';
            } else {
                $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND status = ?");
                $stmt->execute([$postedStatus, $id, $currentStatus]);

                if ($stmt->rowCount() !== 1) {
                    $error = 'The appointment status changed before this update was saved. Refresh the page and try again.';
                } else {
                    notify_appointment_event($pdo, $id, $postedStatus);
                    $message = "Appointment #$id updated to " . $postedStatus . '.';
                }
            }

            if ($error === '') {
                header('Location: appointments.php?msg=' . urlencode($message));
                exit;
            }
        }
    }
}

if (!empty($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Reschedule Appointment via POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule') {
    $isAjaxReschedule = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $csrf = $_POST['csrf_token'] ?? '';
    if (is_string($csrf) && hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        $newDate = trim($_POST['appointment_date'] ?? '');
        $newTime = trim($_POST['appointment_time'] ?? '');

        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $newDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $validDate = $dateValue !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $dateValue->format('Y-m-d') === $newDate;
        $validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $newTime) === 1;

        if ($apptId <= 0 || !$validDate || !$validTime) {
            $error = 'Please provide a valid appointment date and time.';
        } else {
            $timeValue = strlen($newTime) === 5 ? $newTime . ':00' : $newTime;
            $stmt = $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE id = ? AND status IN ('pending', 'confirmed')");
            $stmt->execute([$newDate, $timeValue, $apptId]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT id FROM appointments WHERE id = ? AND status IN ('pending', 'confirmed') LIMIT 1");
                $check->execute([$apptId]);
                if (!$check->fetchColumn()) {
                    $error = 'This appointment could not be rescheduled. It may no longer be pending or confirmed.';
                }
            }

            if ($error === '') {
                notify_appointment_event($pdo, $apptId, 'rescheduled', ['new_date' => $newDate, 'new_time' => $timeValue]);
                $message = "Appointment #{$apptId} successfully rescheduled to " . $dateValue->format('M d, Y') . " at " . date('h:i A', strtotime($timeValue)) . ".";

                if ($isAjaxReschedule) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => true,
                        'appointment_id' => $apptId,
                        'appointment_date' => $newDate,
                        'appointment_time' => $timeValue,
                        'date_label' => $dateValue->format('d M Y'),
                        'time_label' => date('h:i A', strtotime($timeValue)),
                        'message' => $message
                    ]);
                    exit;
                }

                header('Location: appointments.php?msg=' . urlencode($message));
                exit;
            }
        }

        if ($isAjaxReschedule) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($error !== '' && $apptId > 0 && $validDate && $validTime ? 409 : 422);
            echo json_encode(['ok' => false, 'message' => $error]);
            exit;
        }
    } else {
        $error = 'Your session security token is invalid. Refresh the page and try again.';
        if ($isAjaxReschedule) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => $error]);
            exit;
        }
    }
}

// Assign Stylist via POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_stylist') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $staffId = (int)($_POST['staff_id'] ?? 0);
        if ($appointmentId > 0) {
            $assignedStaff = $staffId > 0 ? $staffId : null;
            $stmt = $pdo->prepare("UPDATE appointments SET staff_id = ? WHERE id = ?");
            $stmt->execute([$assignedStaff, $appointmentId]);

            if ($assignedStaff) {
                // Notify assigned stylist
                $stStmt = $pdo->prepare("SELECT user_id FROM staff WHERE id = ?");
                $stStmt->execute([$assignedStaff]);
                $stUserId = (int)$stStmt->fetchColumn();
                if ($stUserId) {
                    create_notification($pdo, $stUserId, "New Appointment Assigned (#$appointmentId)", "You have been assigned to appointment #$appointmentId.", 'appointment', 'stylist/dashboard.php');
                }
            }
            $message = 'Stylist assigned successfully.';
        }
    }
}

// Fetch active stylists for assignment
$stylists = $pdo->query("
    SELECT s.id as staff_id, u.name, s.specialization 
    FROM staff s 
    JOIN users u ON s.user_id = u.id 
    WHERE u.status = 'active' 
    ORDER BY u.name ASC
")->fetchAll();

$appointments = $pdo->query("
    SELECT a.*, c.name as client_name, c.phone as client_phone, c.email as client_email,
           s.name as service_name, s.price,
           u.name as stylist_name, st.specialization as stylist_specialization
    FROM appointments a
    JOIN clients c ON a.client_id = c.id
    JOIN services s ON a.service_id = s.id
    LEFT JOIN staff st ON a.staff_id = st.id
    LEFT JOIN users u ON st.user_id = u.id
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
")->fetchAll();

// ── Visual monthly calendar data (preserves existing table query above) ──
$calYear  = isset($_GET['cal_y']) ? (int)$_GET['cal_y'] : (int)date('Y');
$calMonth = isset($_GET['cal_m']) ? (int)$_GET['cal_m'] : (int)date('n');
if ($calMonth < 1 || $calMonth > 12) {
    $calMonth = (int)date('n');
}
if ($calYear < 2000 || $calYear > 2100) {
    $calYear = (int)date('Y');
}
$calFirstDay   = new DateTimeImmutable(sprintf('%04d-%02d-01', $calYear, $calMonth));
$calDaysInMonth = (int)$calFirstDay->format('t');
$calStartWeekday = (int)$calFirstDay->format('w'); // 0=Sun
$calPrev = $calFirstDay->modify('-1 month');
$calNext = $calFirstDay->modify('+1 month');
$calMonthLabel = $calFirstDay->format('F Y');

// Group appointments for the visible month by date (Y-m-d)
$calendarAppts = [];
$monthStart = $calFirstDay->format('Y-m-d');
$monthEnd   = $calFirstDay->format('Y-m-t');
foreach ($appointments as $row) {
    $d = $row['appointment_date'];
    if ($d >= $monthStart && $d <= $monthEnd) {
        $calendarAppts[$d][] = $row;
    }
}

$unreadAdminNotifs = get_unread_notification_count($pdo, (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .badge-stylist {
            background: rgba(13, 148, 136, 0.25);
            color: var(--burgundy-light);
            border: 1px solid rgba(45, 212, 191, 0.35);
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .badge-unassigned {
            background: rgba(232, 197, 71, 0.15);
            color: var(--champagne);
            border: 1px dashed rgba(232, 197, 71, 0.4);
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .modal-content-elegant {
            background: var(--black-card) !important;
            border: 1px solid var(--border) !important;
            color: var(--cream) !important;
            border-radius: var(--radius) !important;
        }
        .modal-content-elegant .form-select,
        .modal-content-elegant .form-control {
            background: var(--black-soft) !important;
            border: 1px solid rgba(232, 197, 71, 0.25) !important;
            color: var(--cream) !important;
        }
        .modal-content-elegant .form-control:focus,
        .modal-content-elegant .form-select:focus {
            background: var(--black-soft) !important;
            border-color: var(--champagne) !important;
            color: var(--cream) !important;
        }
        input[type="date"]::-webkit-calendar-picker-indicator,
        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
        /* Monthly visual calendar */
        .appt-calendar-wrap { margin-bottom: 1.5rem; }
        .appt-calendar-nav {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;
        }
        .appt-calendar-nav h6 { margin: 0; color: var(--cream); font-weight: 600; }
        .appt-cal-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
        }
        .appt-cal-dow {
            text-align: center; font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: 0.04em; color: var(--champagne); padding: 0.35rem 0; opacity: 0.85;
        }
        .appt-cal-day {
            min-height: 72px; background: var(--black-soft);
            border: 1px solid var(--border); border-radius: 8px;
            padding: 4px 6px; cursor: default; position: relative;
            transition: border-color 0.15s, background 0.15s;
        }
        .appt-cal-day.has-appts { cursor: pointer; }
        .appt-cal-day.has-appts:hover {
            border-color: var(--champagne); background: rgba(232, 197, 71, 0.08);
        }
        .appt-cal-day.other-month { opacity: 0.35; pointer-events: none; }
        .appt-cal-day.today { border-color: var(--burgundy-light); box-shadow: inset 0 0 0 1px rgba(13, 148, 136, 0.4); }
        .appt-cal-day-num { font-size: 0.8rem; font-weight: 600; color: var(--cream); }
        .appt-cal-dots { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 4px; }
        .appt-cal-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--champagne);
        }
        .appt-cal-dot.status-pending { background: #e8c547; }
        .appt-cal-dot.status-confirmed { background: #2dd4bf; }
        .appt-cal-dot.status-completed { background: #6ee7b7; }
        .appt-cal-dot.status-cancelled { background: #f87171; }
        .appt-cal-dot.status-no-show { background: #a78bfa; }
        .appt-cal-count {
            position: absolute; top: 4px; right: 6px;
            font-size: 0.65rem; color: var(--champagne); font-weight: 600;
        }
        .appt-cal-legend {
            display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.6rem;
            font-size: 0.72rem; color: var(--muted, #aaa);
        }
        .appt-cal-legend span { display: inline-flex; align-items: center; gap: 4px; }
        @media (max-width: 576px) {
            .appt-cal-day { min-height: 48px; padding: 2px 3px; }
            .appt-cal-day-num { font-size: 0.7rem; }
        }
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
                <h5 class="mb-0">Appointments</h5>
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

                <a href="staff.php" class="btn btn-sm btn-outline-gold"><i class="fas fa-user-tie me-1"></i> Staff Directory</a>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>
        <div class="content-area">
            <div data-role="reschedule-feedback" class="alert alert-success d-none mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i><span data-role="reschedule-feedback-message"></span>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Visual Monthly Calendar -->
            <div class="card-elegant appt-calendar-wrap">
                <div class="appt-calendar-nav">
                    <a class="btn btn-sm btn-outline-gold" href="?cal_y=<?php echo (int)$calPrev->format('Y'); ?>&amp;cal_m=<?php echo (int)$calPrev->format('n'); ?>" title="Previous month">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <h6><i class="fas fa-calendar-alt me-2 text-gold"></i><?php echo htmlspecialchars($calMonthLabel); ?></h6>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-gold" href="?cal_y=<?php echo (int)date('Y'); ?>&amp;cal_m=<?php echo (int)date('n'); ?>">Today</a>
                        <a class="btn btn-sm btn-outline-gold" href="?cal_y=<?php echo (int)$calNext->format('Y'); ?>&amp;cal_m=<?php echo (int)$calNext->format('n'); ?>" title="Next month">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <div class="appt-cal-grid">
                    <?php
                    $dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    foreach ($dowLabels as $dlab): ?>
                        <div class="appt-cal-dow"><?php echo $dlab; ?></div>
                    <?php endforeach;

                    // Leading empty cells from previous month
                    for ($i = 0; $i < $calStartWeekday; $i++): ?>
                        <div class="appt-cal-day other-month"></div>
                    <?php endfor;

                    $todayStr = date('Y-m-d');
                    for ($day = 1; $day <= $calDaysInMonth; $day++):
                        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
                        $dayAppts = $calendarAppts[$dateStr] ?? [];
                        $hasAppts = count($dayAppts) > 0;
                        $isToday = ($dateStr === $todayStr);
                        $classes = 'appt-cal-day';
                        if ($hasAppts) $classes .= ' has-appts';
                        if ($isToday) $classes .= ' today';
                        $payload = [];
                        foreach ($dayAppts as $a) {
                            $payload[] = [
                                'id' => (int)$a['id'],
                                'time' => date('h:i A', strtotime($a['appointment_time'])),
                                'client' => $a['client_name'],
                                'service' => $a['service_name'],
                                'stylist' => $a['stylist_name'] ?: 'Unassigned',
                                'status' => $a['status'],
                            ];
                        }
                        $dataJson = htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="<?php echo $classes; ?>"
                             data-date="<?php echo $dateStr; ?>"
                             data-appts="<?php echo $dataJson; ?>"
                             <?php if ($hasAppts): ?>role="button" tabindex="0" title="<?php echo count($dayAppts); ?> appointment(s)"<?php endif; ?>>
                            <span class="appt-cal-day-num"><?php echo $day; ?></span>
                            <?php if ($hasAppts): ?>
                                <span class="appt-cal-count"><?php echo count($dayAppts); ?></span>
                                <div class="appt-cal-dots">
                                    <?php foreach (array_slice($dayAppts, 0, 5) as $a): ?>
                                        <span class="appt-cal-dot status-<?php echo htmlspecialchars($a['status']); ?>" title="<?php echo htmlspecialchars($a['status']); ?>"></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor;

                    // Trailing empty cells to complete the grid
                    $totalCells = $calStartWeekday + $calDaysInMonth;
                    $remainder = $totalCells % 7;
                    if ($remainder > 0) {
                        for ($i = 0; $i < (7 - $remainder); $i++): ?>
                            <div class="appt-cal-day other-month"></div>
                        <?php endfor;
                    }
                    ?>
                </div>
                <div class="appt-cal-legend">
                    <span><span class="appt-cal-dot status-pending"></span> Pending</span>
                    <span><span class="appt-cal-dot status-confirmed"></span> Confirmed</span>
                    <span><span class="appt-cal-dot status-completed"></span> Completed</span>
                    <span><span class="appt-cal-dot status-cancelled"></span> Cancelled</span>
                    <span><span class="appt-cal-dot status-no-show"></span> No-show</span>
                </div>
            </div>

            <!-- Day detail modal -->
            <div class="modal fade" id="calDayModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content modal-content-elegant">
                        <div class="modal-header border-bottom border-secondary">
                            <h6 class="modal-title text-white" id="calDayModalTitle">
                                <i class="fas fa-calendar-day me-2 text-gold"></i>Appointments
                            </h6>
                            <button type="button" class="btn-close filter-invert" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="calDayModalBody">
                            <p class="text-muted small mb-0">No appointments on this day.</p>
                        </div>
                        <div class="modal-footer border-top border-secondary">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
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
                                <th>Date &amp; Time</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Price</th>
                                <th>Assigned Stylist</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($appointments)): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No appointments found</td></tr>
                            <?php else: ?>
                                <?php foreach ($appointments as $i => $row): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <strong data-role="appointment-date" data-appointment-id="<?php echo (int)$row['id']; ?>"><?php echo date('d M Y', strtotime($row['appointment_date'])); ?></strong>
                                        <small class="d-block text-muted" data-role="appointment-time" data-appointment-id="<?php echo (int)$row['id']; ?>"><?php echo date('h:i A', strtotime($row['appointment_time'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="d-block fw-medium text-white"><?php echo htmlspecialchars($row['client_name']); ?></span>
                                        <small class="text-muted d-block">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($row['client_phone']); ?>
                                            <?php if (!empty($row['client_phone'])): ?>
                                                <?php $waLink = generate_whatsapp_reminder_link($row['client_phone'], $row['client_name'], $row['service_name'], $row['appointment_date'], $row['appointment_time']); ?>
                                                <a href="<?php echo htmlspecialchars($waLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="text-success ms-1" title="Send WhatsApp Reminder">
                                                    <i class="fab fa-whatsapp"></i>
                                                </a>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td><span class="text-gold fw-bold">$<?php echo number_format($row['price'], 2); ?></span></td>
                                    <td>
                                        <?php if (!empty($row['stylist_name'])): ?>
                                            <span class="badge-stylist d-inline-block">
                                                <i class="fas fa-user-check me-1"></i><?php echo htmlspecialchars($row['stylist_name']); ?>
                                            </span>
                                            <button type="button" class="btn btn-link btn-sm text-gold p-0 ms-1" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $row['id']; ?>" title="Change Stylist">
                                                <i class="fas fa-exchange-alt small"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn badge-unassigned border-0" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $row['id']; ?>" title="Assign a stylist">
                                                <i class="fas fa-plus me-1"></i>Assign Stylist
                                            </button>
                                        <?php endif; ?>

                                    </td>
                                    <td><span class="badge-status badge-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="../appointment_ics.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-secondary" title="Export to Calendar (.ics)">
                                                <i class="fas fa-calendar-plus"></i>
                                            </a>
                                            <?php if ($row['status'] === 'pending'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <button type="submit" class="btn btn-outline-success" title="Confirm">✓</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($row['status'], ['pending','confirmed'])): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="status" value="completed">
                                                    <button type="submit" class="btn btn-outline-primary" title="Complete">✔</button>
                                                </form>
                                                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#rescheduleModal<?php echo $row['id']; ?>" title="Reschedule Date &amp; Time">
                                                    <i class="fas fa-clock"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this appointment?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" class="btn btn-outline-danger" title="Cancel">✕</button>
                                                </form>
                                            <?php endif; ?>
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

<?php foreach ($appointments as $row): ?>
    <div class="modal fade" id="assignModal<?php echo (int)$row['id']; ?>" tabindex="-1" aria-labelledby="assignModalLabel<?php echo (int)$row['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-elegant">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="assign_stylist">
                    <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">

                    <div class="modal-header border-bottom border-secondary">
                        <h6 class="modal-title text-white" id="assignModalLabel<?php echo (int)$row['id']; ?>">
                            <i class="fas fa-user-tag me-2 text-gold"></i>Assign Stylist — Appointment #<?php echo (int)$row['id']; ?>
                        </h6>
                        <button type="button" class="btn-close filter-invert" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-start">
                        <p class="small text-muted mb-2">
                            Client: <strong><?php echo htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong> |
                            Service: <strong><?php echo htmlspecialchars($row['service_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </p>
                        <label class="form-label small text-muted">Select Stylist</label>
                        <select name="staff_id" class="form-select">
                            <option value="0"> Unassigned </option>
                            <?php foreach ($stylists as $st): ?>
                                <option value="<?php echo (int)$st['staff_id']; ?>" <?php echo ((int)$row['staff_id'] === (int)$st['staff_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($st['specialization'] ?: 'Stylist', ENT_QUOTES, 'UTF-8'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-check me-1"></i> Save Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (in_array($row['status'], ['pending', 'confirmed'], true)): ?>
        <div class="modal fade" id="rescheduleModal<?php echo (int)$row['id']; ?>" tabindex="-1" aria-labelledby="rescheduleModalLabel<?php echo (int)$row['id']; ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content modal-content-elegant">
                    <form method="POST" data-role="reschedule-form" data-appointment-id="<?php echo (int)$row['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="reschedule">
                        <input type="hidden" name="appointment_id" value="<?php echo (int)$row['id']; ?>">

                        <div class="modal-header border-bottom border-secondary">
                            <h6 class="modal-title text-white" id="rescheduleModalLabel<?php echo (int)$row['id']; ?>">
                                <i class="fas fa-calendar-alt me-2 text-gold"></i>Reschedule Appointment #<?php echo (int)$row['id']; ?>
                            </h6>
                            <button type="button" class="btn-close filter-invert" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <div class="alert alert-danger d-none" data-role="reschedule-error" role="alert"></div>
                            <p class="small text-muted mb-3">
                                Client: <strong><?php echo htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                Service: <strong><?php echo htmlspecialchars($row['service_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </p>
                            <div class="mb-3">
                                <label class="form-label small text-muted" for="rescheduleDate<?php echo (int)$row['id']; ?>">New Date</label>
                                <input type="date" id="rescheduleDate<?php echo (int)$row['id']; ?>" name="appointment_date" class="form-control" value="<?php echo htmlspecialchars($row['appointment_date'], ENT_QUOTES, 'UTF-8'); ?>" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small text-muted" for="rescheduleTime<?php echo (int)$row['id']; ?>">New Time</label>
                                <input type="time" id="rescheduleTime<?php echo (int)$row['id']; ?>" name="appointment_time" class="form-control" value="<?php echo htmlspecialchars($row['appointment_time'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer border-top border-secondary">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-gold btn-sm">
                                <i class="fas fa-save me-1"></i> Save New Schedule
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const feedback = document.querySelector('[data-role="reschedule-feedback"]');
    const feedbackMessage = document.querySelector('[data-role="reschedule-feedback-message"]');

    // Monthly calendar day click → detail modal
    const calDayModalEl = document.getElementById('calDayModal');
    const calDayModal = calDayModalEl ? new bootstrap.Modal(calDayModalEl) : null;
    const calDayTitle = document.getElementById('calDayModalTitle');
    const calDayBody = document.getElementById('calDayModalBody');

    function openCalDay(dateStr, appts) {
        if (!calDayModal || !calDayBody) return;
        const niceDate = new Date(dateStr + 'T12:00:00').toLocaleDateString(undefined, {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });
        if (calDayTitle) {
            calDayTitle.innerHTML = '<i class="fas fa-calendar-day me-2 text-gold"></i>' + niceDate;
        }
        if (!appts || appts.length === 0) {
            calDayBody.innerHTML = '<p class="text-muted small mb-0">No appointments on this day.</p>';
        } else {
            let html = '<div class="list-group list-group-flush">';
            appts.forEach(function (a) {
                const statusClass = 'badge-status badge-' + (a.status || 'pending');
                html += '<div class="list-group-item bg-transparent border-secondary text-start px-0 py-2">';
                html += '<div class="d-flex justify-content-between align-items-start gap-2">';
                html += '<div><strong class="text-white">' + escapeHtml(a.time) + '</strong>';
                html += '<div class="small text-muted mt-1">';
                html += '<i class="fas fa-user me-1"></i>' + escapeHtml(a.client) + '<br>';
                html += '<i class="fas fa-spa me-1"></i>' + escapeHtml(a.service) + '<br>';
                html += '<i class="fas fa-user-tie me-1"></i>' + escapeHtml(a.stylist);
                html += '</div></div>';
                html += '<span class="' + statusClass + '">' + escapeHtml((a.status || '').charAt(0).toUpperCase() + (a.status || '').slice(1)) + '</span>';
                html += '</div></div>';
            });
            html += '</div>';
            calDayBody.innerHTML = html;
        }
        calDayModal.show();
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    document.querySelectorAll('.appt-cal-day.has-appts').forEach(function (cell) {
        cell.addEventListener('click', function () {
            let appts = [];
            try { appts = JSON.parse(cell.getAttribute('data-appts') || '[]'); } catch (e) {}
            openCalDay(cell.getAttribute('data-date'), appts);
        });
        cell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                cell.click();
            }
        });
    });

    document.querySelectorAll('[data-role="reschedule-form"]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;

            const modal = form.closest('.modal');
            const errorMessage = form.querySelector('[data-role="reschedule-error"]');
            const submitButton = form.querySelector('button[type="submit"]');
            const originalButtonContent = submitButton.innerHTML;
            errorMessage.textContent = '';
            errorMessage.classList.add('d-none');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: new FormData(form),
                    credentials: 'same-origin'
                });
                const result = await response.json();

                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'The appointment could not be rescheduled.');
                }

                const appointmentId = String(result.appointment_id);
                const dateCell = document.querySelector('[data-role="appointment-date"][data-appointment-id="' + appointmentId + '"]');
                const timeCell = document.querySelector('[data-role="appointment-time"][data-appointment-id="' + appointmentId + '"]');
                if (!dateCell || !timeCell) {
                    throw new Error('The appointment was saved, but its updated schedule could not be refreshed. Reload the page to see the latest data.');
                }

                dateCell.textContent = result.date_label;
                timeCell.textContent = result.time_label;
                feedbackMessage.textContent = result.message;
                feedback.classList.remove('d-none');

                bootstrap.Modal.getInstance(modal).hide();
            } catch (error) {
                errorMessage.textContent = error.message || 'The appointment could not be rescheduled. Please try again.';
                errorMessage.classList.remove('d-none');
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonContent;
            }
        });
    });
});
</script>
</body>
</html>
