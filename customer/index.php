<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? '';
if ($role === 'stylist') {
    header('Location: ../stylist/dashboard.php');
    exit;
}
if (in_array($role, ['admin', 'receptionist'], true)) {
    header('Location: ../admin/dashboard.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$name = $_SESSION['user_name'] ?? 'Customer';
$email = '';
$phone = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->prepare(
    "SELECT name, email, phone FROM users WHERE id = ? LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $name = $user['name'] ?: $name;
    $email = trim((string) ($user['email'] ?? ''));
    $phone = trim((string) ($user['phone'] ?? ''));
}

$clientMatch = "LOWER(TRIM(c.email)) = LOWER(TRIM(?))";
$params = [$email];

if ($phone !== '') {
    $normalizedClientPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(c.phone), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
    $normalizedAccountPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(?), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
    $clientMatch .= " OR (
        $normalizedClientPhone = $normalizedAccountPhone
        AND (
            c.email IS NULL
            OR TRIM(c.email) = ''
            OR LOWER(TRIM(c.email)) = LOWER(TRIM(?))
        )
    )";
    $params[] = $phone;
    $params[] = $email;
}

// Handle Cancel Appointment by Customer
$actionMsg = '';
$actionErr = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $actionErr = 'Security validation failed (CSRF). Please refresh and try again.';
    } else {
        $cancelId = (int)($_POST['appointment_id'] ?? 0);
        if ($cancelId > 0) {
            $chkStmt = $pdo->prepare("
                SELECT a.id 
                FROM appointments a
                JOIN clients c ON a.client_id = c.id
                WHERE a.id = ? AND ($clientMatch) AND a.status IN ('pending', 'confirmed')
                LIMIT 1
            ");
            $chkStmt->execute(array_merge([$cancelId], $params));
            if ($chkStmt->fetch()) {
                $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$cancelId]);
                notify_appointment_event($pdo, $cancelId, 'cancelled_by_customer');
                $actionMsg = "Appointment #{$cancelId} has been successfully cancelled.";
            } else {
                $actionErr = "Appointment cannot be cancelled (it may already be completed, cancelled, or not found).";
            }
        }
    }
}

$appointments = [];
$appointmentError = false;

try {
    $stmt = $pdo->prepare("
        SELECT a.*, s.name AS service_name
        FROM appointments a
        INNER JOIN services s ON s.id = a.service_id
        INNER JOIN clients c ON c.id = a.client_id
        WHERE ($clientMatch)
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('My Appointments error: ' . $e->getMessage());
    $appointmentError = true;
}

$unreadNotifCount = get_unread_notification_count($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account | Elegance Salon</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        :root {
            --black: #0C0F14;
            --black-soft: #151A21;
            --black-card: #1C232C;
            --burgundy: #0D9488;
            --burgundy-dark: #0F766E;
            --burgundy-light: #2DD4BF;
            --champagne: #E8C547;
            --champagne-dark: #D4A017;
            --champagne-light: #F5E6A3;
            --cream: #F7F5F0;
            --white: #FFFFFF;
            --muted: #94A3B8;
            --border: rgba(232, 197, 71, 0.18);
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.50);
            --radius: 18px;
            --transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background: var(--black);
            min-height: 100vh;
            color: var(--cream);
            font-family: 'Poppins', sans-serif;
        }

        .welcome-card {
            background:
                linear-gradient(110deg, rgba(12, 15, 20, 0.94), rgba(21, 26, 33, 0.76)),
                url('../assets/img/hero.jpeg') center 42% / cover no-repeat;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        .welcome-card h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            margin: 0.3rem 0;
            overflow-wrap: anywhere;
        }

        .panel {
            background: var(--black-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.4rem;
            margin-bottom: 1.2rem;
            height: 100%;
            box-shadow: var(--shadow);
        }

        .panel h3 {
            font-size: 1.05rem;
            margin-bottom: 1rem;
            color: var(--champagne);
        }

        .panel p {
            overflow-wrap: anywhere;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.85rem;
        }

        .quick-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.45rem;
            padding: 1.15rem 0.75rem;
            background: var(--black-soft);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--cream);
            text-decoration: none;
            text-align: center;
            transition: var(--transition);
            position: relative;
        }

        .quick-btn i {
            color: var(--champagne);
        }

        .quick-btn:hover {
            border-color: var(--burgundy-light);
            background: rgba(13, 148, 136, 0.10);
            transform: translateY(-3px);
            color: var(--cream);
        }

        .appt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 0;
            border-bottom: 1px solid var(--border);
            gap: 1rem;
            flex-wrap: wrap;
        }

        .appt-row:last-child {
            border-bottom: 0;
        }

        .appt-details {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .badge-soft {
            font-size: 0.75rem;
            padding: 0.3rem 0.7rem;
            border-radius: 999px;
            background: rgba(13, 148, 136, 0.15);
            border: 1px solid rgba(45, 212, 191, 0.25);
            color: var(--burgundy-light);
            white-space: nowrap;
        }

        .badge-cancelled {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #f87171;
        }

        .eyebrow {
            font-size: 0.72rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--champagne);
        }

        .muted-text {
            color: var(--muted);
        }

        .gold-link {
            color: var(--champagne);
            text-decoration: none;
        }

        .gold-link:hover {
            color: var(--champagne-light);
            text-decoration: underline;
        }

        .empty-state {
            text-align: center;
            padding: 1.5rem 0.5rem;
        }

        .empty-state i {
            display: block;
            color: var(--burgundy-light);
            font-size: 2rem;
            margin-bottom: 0.8rem;
        }

        @media (max-width: 768px) {
            .welcome-card {
                padding: 1.5rem 1.25rem;
            }
            .welcome-card h1 {
                font-size: 1.55rem;
            }
            .panel {
                padding: 1.25rem 1rem;
            }
        }

        @media (max-width: 480px) {
            .welcome-card {
                padding: 1.25rem 1rem;
                border-radius: 14px;
            }
            .welcome-card h1 {
                font-size: 1.35rem;
            }
            .panel {
                padding: 1.1rem 0.85rem;
                border-radius: 14px;
            }
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.6rem;
            }
            .quick-btn {
                padding: 0.9rem 0.5rem;
                font-size: 0.82rem;
            }
            .quick-btn i {
                font-size: 1.25rem;
            }
            .appt-row {
                padding: 0.75rem 0;
            }
        }

        @media (max-width: 340px) {
            .welcome-card {
                padding: 1rem 0.75rem;
            }
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body data-base-path="..">

<?php
$pageTitle = "My Account";
$activePage = '';
$headerOnly = true;
require_once __DIR__ . '/../includes/header.php';
?>

<main class="container py-5">

    <?php if ($actionMsg): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($actionErr): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($actionErr, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close filter-invert" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="welcome-card">
        <span class="eyebrow">Customer Account</span>
        <h1>
            Welcome, <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <p class="mb-0 muted-text">
            Book services, track appointments, and manage your visits.
        </p>
    </section>

    <section class="panel">
        <h3><i class="fas fa-bolt me-2"></i>Quick Actions</h3>

        <div class="quick-actions">
            <a href="../book.php" class="quick-btn">
                <i class="fas fa-calendar-plus"></i><span>Book Now</span>
            </a>
            <a href="../notifications.php" class="quick-btn">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="badge bg-danger rounded-pill position-absolute" style="top: 8px; right: 8px; font-size: 0.68rem;">
                        <?php echo $unreadNotifCount; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="../services.php" class="quick-btn">
               <i class="fas fa-spa"></i><span>Services</span>
            </a>
            <a href="../contact.php" class="quick-btn">
                <i class="fas fa-envelope"></i><span>Contact</span>
            </a>
            <a href="../feedback.php" class="quick-btn">
                <i class="fas fa-star"></i><span>Feedback</span>
            </a>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-4">
            <section class="panel">
                <h3><i class="fas fa-user me-2"></i>Profile</h3>

                <p class="mb-2">
                    <strong>Name</strong><br>
                    <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <p class="mb-2">
                    <strong>Email</strong><br>
                    <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <p class="mb-3">
                    <strong>Phone</strong><br>
                    <?php echo htmlspecialchars($phone ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <a href="../notifications.php" class="btn btn-sm btn-outline-gold w-100">
                    <i class="fas fa-bell me-1"></i> Notification Center
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-1"><?php echo $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </a>
            </section>
        </div>

        <div class="col-lg-8">
            <section class="panel">
                <h3><i class="fas fa-calendar-check me-2"></i>My Appointments</h3>

                <?php if ($appointmentError): ?>

                    <div class="empty-state">
                        <i class="fas fa-triangle-exclamation"></i>
                        <p class="mb-0">
                            Appointments could not be loaded. Please try again.
                        </p>
                    </div>

                <?php elseif (empty($appointments)): ?>

                    <div class="empty-state">
                        <i class="far fa-calendar"></i>
                        <p class="muted-text mb-2">
                            No appointments found for your account yet.
                        </p>
                        <a href="../book.php" class="gold-link">
                            Book your first visit
                        </a>
                    </div>

                <?php else: ?>

                    <?php foreach ($appointments as $a): ?>
                        <?php
                        $apptStatus = (string)($a['status'] ?? 'pending');
                        $canCancel = in_array($apptStatus, ['pending', 'confirmed'], true);
                        ?>
                        <div class="appt-row">
                            <div class="appt-details">
                                <strong>
                                    <?php echo htmlspecialchars(
                                        $a['service_name'] ?? 'Salon Service',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </strong>
                                <br>

                                <small class="muted-text">
                                    <?php
                                    $date = $a['appointment_date'] ?? '';
                                    $time = $a['appointment_time'] ?? '';

                                    echo $date
                                        ? htmlspecialchars(date('M d, Y', strtotime($date)), ENT_QUOTES, 'UTF-8')
                                        : 'Date unavailable';

                                    echo ' · ';

                                    echo $time
                                        ? htmlspecialchars(date('h:i A', strtotime($time)), ENT_QUOTES, 'UTF-8')
                                        : 'Time unavailable';
                                    ?>
                                </small>
                                <?php if (!empty($a['notes'])): ?>
                                    <small class="muted-text d-block mt-1">
                                        <?php echo htmlspecialchars((string) $a['notes'], ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-soft <?php echo $apptStatus === 'cancelled' ? 'badge-cancelled' : ''; ?>">
                                    <?php echo htmlspecialchars(
                                        ucfirst($apptStatus),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </span>

                                <a href="../appointment_ics.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-outline-gold btn-sm py-0 px-2" style="font-size: 0.75rem;" title="Add to Google, Apple, or Outlook Calendar">
                                    <i class="fas fa-calendar-plus me-1"></i> .ics
                                </a>

                                <?php if ($canCancel): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to cancel this appointment?');" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="cancel_appointment">
                                        <input type="hidden" name="appointment_id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;" title="Cancel this booking">
                                            Cancel
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

                <div class="mt-3">
                    <a href="../book.php" class="gold-link">
                        <i class="fas fa-plus me-1"></i>Book another appointment
                    </a>
                </div>
            </section>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/notifications.js"></script>
</body>
</html>
