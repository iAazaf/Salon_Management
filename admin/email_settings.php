<?php
// =====================================================
// Elegance Salon Management System
// Admin Email Notification Preferences
// =====================================================

require_once 'includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/notifications.php';
require_once '../includes/mailer.php';

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$adminPage = 'email_settings';
$pageTitle = 'Email Notification Settings';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = isset($_GET['saved']) ? 'Email notification preferences have been saved.' : '';
$error = '';
$settingsAvailable = false;
$settings = get_email_notification_settings($pdo, $settingsAvailable);
$settingLabels = [
    'master_enabled' => 'Enable automated notification emails',
    'appointment_booked' => 'Booking request received',
    'appointment_confirmed' => 'Appointment confirmed',
    'appointment_cancelled' => 'Appointment cancelled',
    'appointment_rescheduled' => 'Appointment rescheduled',
    'appointment_completed' => 'Appointment completed / thank you',
    'reminder_24h' => '24-hour appointment reminder',
    'reminder_2h' => '2-hour appointment reminder',
    'low_stock' => 'Low-stock alerts to administrators',
    'payment_alerts' => 'Payment alerts to customers and administrators',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    $submitted = $_POST['settings'] ?? null;
    $validSubmission = is_string($submittedCsrf)
        && is_string($_SESSION['csrf_token'] ?? null)
        && $_SESSION['csrf_token'] !== ''
        && hash_equals($_SESSION['csrf_token'], $submittedCsrf)
        && is_array($submitted);

    if (!$validSubmission) {
        $error = 'Security validation failed. Refresh the page and try again.';
    } else {
        $unknownKeys = array_diff(array_keys($submitted), array_keys($settingLabels));
        $invalidValues = false;
        foreach ($submitted as $key => $value) {
            if (!is_string($key) || !is_string($value) || $value !== '1') {
                $invalidValues = true;
                break;
            }
        }

        if ($unknownKeys || $invalidValues) {
            $error = 'The submitted preferences are invalid. Refresh the page and try again.';
        } else {
            $newSettings = array_fill_keys(array_keys($settingLabels), 0);
            foreach ($submitted as $key => $value) {
                $newSettings[$key] = 1;
            }

            try {
                $pdo->beginTransaction();
                $save = $pdo->prepare("
                    INSERT INTO email_notification_settings (setting_key, enabled)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
                ");
                foreach ($newSettings as $key => $enabled) {
                    $save->execute([$key, $enabled]);
                }
                $pdo->commit();
                header('Location: email_settings.php?saved=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Email notification settings save error: ' . $e->getMessage());
                $error = 'Could not save preferences. Apply database_migration_email_notifications.sql, then try again.';
                $settings = $newSettings;
                $settingsAvailable = false;
            }
        }
    }
}

$mailReady = check_mail_configuration()['ready'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .email-settings .card-elegant {
            background: var(--black-card);
            border-color: rgba(212, 160, 23, 0.3);
            color: #292929;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }
        .email-settings .card-elegant:hover { border-color: #d4a017; }
        .email-settings .text-muted { color: #68717c !important; }
        .email-settings h6, .email-settings .setting-label { color: #ffffff; }
        .email-settings .setting-row {
            min-height: 74px;
            border-bottom: 1px solid #ece8df;
        }
        .email-settings .setting-row:last-child { border-bottom: 0; }
        .email-settings .form-check-input {
            width: 2.7rem;
            height: 1.45rem;
            cursor: pointer;
            border-color: #b9b2a3;
        }
        .email-settings .form-check-input:checked {
            background-color: #c99a22;
            border-color: #c99a22;
        }
        .email-settings .form-check-input:focus {
            border-color: #c99a22;
            box-shadow: 0 0 0 .2rem rgba(201, 154, 34, .2);
        }
        @media (max-width: 575.98px) {
            .email-settings .setting-row { gap: 1rem; }
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
                    <h5 class="mb-0">Email Notifications</h5>
                    <small class="text-muted">Choose which automated messages Elegance Salon sends</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="appointments.php" class="btn btn-outline-gold btn-sm"><i class="fas fa-calendar-alt me-1"></i> Appointments</a>
                <a href="../logout.php" class="btn btn-sm btn-outline-gold">Logout</a>
            </div>
        </div>

        <main class="content-area email-settings">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!$settingsAvailable): ?>
                <div class="alert alert-warning mb-4" role="alert">
                    <i class="fas fa-database me-2"></i>
                    Email preferences are unavailable until the database migration
                    <code>database_migration_email_notifications.sql</code> is applied.
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <section class="card-elegant h-100">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                  style="width:48px;height:48px;background:#fbf5e5;color:#a77a12;">
                                <i class="fas fa-envelope fa-lg"></i>
                            </span>
                            <div>
                                <h6 class="mb-1">Email transport</h6>
                                <p class="text-muted small mb-0">
                                    <?php echo $mailReady ? 'The configured mail transport is ready.' : 'The server mail transport is not currently configured.'; ?>
                                    Credentials remain in the protected server configuration.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="col-md-4">
                    <section class="card-elegant h-100 d-flex align-items-center gap-3">
                        <span class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                              style="width:48px;height:48px;background:#fbf5e5;color:#a77a12;">
                            <i class="fas fa-shield-alt fa-lg"></i>
                        </span>
                        <div>
                            <h6 class="mb-1">Saved preferences</h6>
                            <p class="text-muted small mb-0"><?php echo $settingsAvailable ? 'Connected to the database' : 'Migration required'; ?></p>
                        </div>
                    </section>
                </div>
            </div>

            <form method="post" action="email_settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                <section class="card-elegant mb-4">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                        <div>
                            <h6 class="mb-1"><i class="fas fa-power-off text-gold me-2"></i>Master email switch</h6>
                            <p class="text-muted small mb-0">Turn off all automated email delivery without changing in-app notifications.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="setting-master_enabled" name="settings[master_enabled]" value="1"
                                   <?php echo !empty($settings['master_enabled']) ? 'checked' : ''; ?>
                                   <?php echo !$settingsAvailable ? 'disabled' : ''; ?>>
                            <label class="visually-hidden" for="setting-master_enabled">Enable automated notification emails</label>
                        </div>
                    </div>
                </section>

                <div class="row g-4">
                    <div class="col-xl-6">
                        <section class="card-elegant h-100">
                            <h6 class="mb-1"><i class="fas fa-calendar-check text-gold me-2"></i>Appointment emails</h6>
                            <p class="text-muted small mb-3">Messages sent to a client when their appointment changes.</p>
                            <?php foreach ([
                                'appointment_booked',
                                'appointment_confirmed',
                                'appointment_cancelled',
                                'appointment_rescheduled',
                                'appointment_completed',
                            ] as $key): ?>
                                <div class="setting-row d-flex justify-content-between align-items-center py-3">
                                    <label class="setting-label mb-0 pe-3" for="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($settingLabels[$key], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                               name="settings[<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>]" value="1"
                                               <?php echo !empty($settings[$key]) ? 'checked' : ''; ?>
                                               <?php echo !$settingsAvailable ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    </div>
                    <div class="col-xl-6">
                        <section class="card-elegant h-100">
                            <h6 class="mb-1"><i class="fas fa-clock text-gold me-2"></i>Appointment reminders</h6>
                            <p class="text-muted small mb-3">Email reminders are sent separately from existing in-app reminders.</p>
                            <?php foreach (['reminder_24h', 'reminder_2h'] as $key): ?>
                                <div class="setting-row d-flex justify-content-between align-items-center py-3">
                                    <label class="setting-label mb-0 pe-3" for="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($settingLabels[$key], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                               name="settings[<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>]" value="1"
                                               <?php echo !empty($settings[$key]) ? 'checked' : ''; ?>
                                               <?php echo !$settingsAvailable ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="border-top pt-3 mt-2">
                                <h6 class="mb-1"><i class="fas fa-bell text-gold me-2"></i>Administrator email alerts</h6>
                                <p class="text-muted small mb-3">Sent to active administrator and receptionist accounts.</p>
                                <?php foreach (['low_stock', 'payment_alerts'] as $key): ?>
                                    <div class="setting-row d-flex justify-content-between align-items-center py-3">
                                        <label class="setting-label mb-0 pe-3" for="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($settingLabels[$key], ENT_QUOTES, 'UTF-8'); ?>
                                        </label>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="setting-<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                                   name="settings[<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>]" value="1"
                                                   <?php echo !empty($settings[$key]) ? 'checked' : ''; ?>
                                                   <?php echo !$settingsAvailable ? 'disabled' : ''; ?>>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-gold px-4 py-2" <?php echo !$settingsAvailable ? 'disabled' : ''; ?>>
                        <i class="fas fa-save me-2"></i>Save email preferences
                    </button>
                </div>
            </form>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
