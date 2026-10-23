<?php
// =====================================================
// Elegance Salon Management System
// In-App Notifications & Appointment Reminder Service
// =====================================================

if (!defined('ELEGANCE_NOTIFICATIONS_LOADED')) {
    define('ELEGANCE_NOTIFICATIONS_LOADED', true);
}

/**
 * Insert a notification for a specific user into the database.
 */
function create_notification(PDO $pdo, ?int $userId, string $title, string $message, string $type = 'appointment', ?string $link = null): int {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $userId,
            mb_substr($title, 0, 150),
            $message,
            $type,
            $link
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('create_notification error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Broadcast an in-app notification to all active administrators and receptionists.
 */
function notify_admins(PDO $pdo, string $title, string $message, string $type = 'appointment', ?string $link = null): int {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'receptionist') AND status = 'active'");
        $adminUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $insertedCount = 0;
        foreach ($adminUsers as $adminId) {
            if (create_notification($pdo, (int)$adminId, $title, $message, $type, $link) > 0) {
                $insertedCount++;
            }
        }
        return $insertedCount;
    } catch (Throwable $e) {
        error_log('notify_admins error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Return the supported persisted email notification preferences.
 */
function email_notification_setting_defaults(): array {
    return [
        'master_enabled' => 1,
        'appointment_booked' => 1,
        'appointment_confirmed' => 1,
        'appointment_cancelled' => 1,
        'appointment_rescheduled' => 1,
        'appointment_completed' => 1,
        'reminder_24h' => 0,
        'reminder_2h' => 0,
        'low_stock' => 0,
        'payment_alerts' => 0,
    ];
}

/**
 * Load persisted email notification preferences.
 */
function get_email_notification_settings(PDO $pdo, ?bool &$available = null): array {
    $settings = email_notification_setting_defaults();
    $available = false;

    try {
        $stmt = $pdo->query("SELECT setting_key, enabled FROM email_notification_settings");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['setting_key'];
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (int)$row['enabled'] === 1 ? 1 : 0;
            }
        }
        $available = true;
    } catch (Throwable $e) {
        error_log('get_email_notification_settings error: ' . $e->getMessage());
    }

    return $settings;
}

/**
 * Claim and send one email event. A unique event key provides at-most-once delivery
 * when callers are retried or concurrent workers process the same event.
 */
function send_notification_email_once(
    PDO $pdo,
    string $settingKey,
    string $eventKey,
    string $eventType,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody
): bool {
    $settingsAvailable = false;
    $settings = get_email_notification_settings($pdo, $settingsAvailable);
    if (
        !$settingsAvailable
        || empty($settings['master_enabled'])
        || empty($settings[$settingKey])
    ) {
        return false;
    }

    try {
        $claim = $pdo->prepare("
            INSERT INTO email_notification_log (event_key, event_type, status, created_at)
            VALUES (?, ?, 'sending', NOW())
        ");
        $claim->execute([$eventKey, $eventType]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000') {
            return false;
        }
        error_log('send_notification_email_once claim error: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('send_notification_email_once claim error: ' . $e->getMessage());
        return false;
    }

    try {
        require_once __DIR__ . '/mailer.php';
        $result = send_app_email($toEmail, $toName, $subject, $htmlBody);
        $status = !empty($result['success']) ? 'sent' : 'failed';
        $update = $pdo->prepare("
            UPDATE email_notification_log
            SET status = ?, completed_at = NOW()
            WHERE event_key = ?
        ");
        $update->execute([$status, $eventKey]);

        return $status === 'sent';
    } catch (Throwable $e) {
        try {
            $update = $pdo->prepare("
                UPDATE email_notification_log
                SET status = 'failed', completed_at = NOW()
                WHERE event_key = ?
            ");
            $update->execute([$eventKey]);
        } catch (Throwable $logError) {
            error_log('send_notification_email_once status update error: ' . $logError->getMessage());
        }
        error_log('send_notification_email_once delivery error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a notification email to active admin/receptionist accounts once per recipient.
 */
function send_admin_notification_emails_once(
    PDO $pdo,
    string $settingKey,
    string $eventKey,
    string $subject,
    string $message
): void {
    $settingsAvailable = false;
    $settings = get_email_notification_settings($pdo, $settingsAvailable);
    if (!$settingsAvailable || empty($settings['master_enabled']) || empty($settings[$settingKey])) {
        return;
    }

    try {
        require_once __DIR__ . '/mailer.php';
        $stmt = $pdo->query("
            SELECT id, name, email
            FROM users
            WHERE role IN ('admin', 'receptionist') AND status = 'active'
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
            $email = trim((string)$recipient['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $safeName = htmlspecialchars((string)$recipient['name'], ENT_QUOTES, 'UTF-8');
            $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $body = '<p>Hello <strong>' . $safeName . '</strong>,</p><p>' . $safeMessage . '</p>';
            $html = render_salon_email_template($subject, 'An Elegance Salon system notification.', $body);
            $recipientKey = $eventKey . ':' . hash('sha256', strtolower($email));

            send_notification_email_once(
                $pdo,
                $settingKey,
                $recipientKey,
                $settingKey,
                $email,
                (string)$recipient['name'],
                $subject,
                $html
            );
        }
    } catch (Throwable $e) {
        error_log('send_admin_notification_emails_once error: ' . $e->getMessage());
    }
}

/**
 * Dispatch notifications for key appointment lifecycle events.
 * Events: 'booked', 'confirmed', 'completed', 'cancelled_by_customer', 'cancelled_by_admin', 'cancelled_by_stylist', 'rescheduled'
 */
function notify_appointment_event(PDO $pdo, int $appointmentId, string $event, array $extra = []): void {
    try {
        // Fetch appointment with client, service, and assigned staff user details
        $stmt = $pdo->prepare("
            SELECT a.*,
                   c.id as client_id, c.name as client_name, c.email as client_email, c.phone as client_phone,
                   s.name as service_name, s.price, s.duration,
                   cu.id as customer_user_id,
                   su.id as stylist_user_id, su.name as stylist_name
            FROM appointments a
            JOIN clients c ON a.client_id = c.id
            JOIN services s ON a.service_id = s.id
            LEFT JOIN users cu ON LOWER(TRIM(cu.email)) = LOWER(TRIM(c.email))
            LEFT JOIN staff st ON a.staff_id = st.id
            LEFT JOIN users su ON st.user_id = su.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$appointmentId]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appt) {
            return;
        }

        $formattedDate = date('M d, Y', strtotime($appt['appointment_date']));
        $formattedTime = date('h:i A', strtotime($appt['appointment_time']));
        $serviceName   = $appt['service_name'];
        $clientName    = $appt['client_name'];
        $customerUserId = !empty($appt['customer_user_id']) ? (int)$appt['customer_user_id'] : null;
        $stylistUserId  = !empty($appt['stylist_user_id']) ? (int)$appt['stylist_user_id'] : null;

        switch ($event) {
            case 'booked':
                // 1. Notify Customer
                if ($customerUserId) {
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Appointment Requested: $serviceName",
                        "Your appointment for $serviceName on $formattedDate at $formattedTime has been requested. We will confirm your slot shortly.",
                        'appointment',
                        'customer/index.php'
                    );
                }
                // 2. Notify Admins
                notify_admins(
                    $pdo,
                    "New Booking #$appointmentId: $clientName",
                    "A new appointment (#$appointmentId) was booked by $clientName for $serviceName on $formattedDate at $formattedTime.",
                    'appointment',
                    'admin/appointments.php'
                );
                break;

            case 'confirmed':
                // 1. Notify Customer
                if ($customerUserId) {
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Appointment Confirmed!",
                        "Great news! Your appointment for $serviceName on $formattedDate at $formattedTime has been confirmed. We look forward to your visit.",
                        'appointment',
                        'customer/index.php'
                    );
                }
                // 2. Notify Assigned Stylist
                if ($stylistUserId) {
                    create_notification(
                        $pdo,
                        $stylistUserId,
                        "Appointment Confirmed (#$appointmentId)",
                        "Appointment #$appointmentId for $clientName ($serviceName) on $formattedDate at $formattedTime is confirmed.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }
                break;

            case 'completed':
                // Notify Customer
                if ($customerUserId) {
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Thank you for visiting Elegance!",
                        "Your $serviceName appointment on $formattedDate is complete. We hope you loved your experience! Please consider leaving us feedback.",
                        'appointment',
                        'feedback.php'
                    );
                }
                break;

            case 'cancelled_by_customer':
                // 1. Notify Customer
                if ($customerUserId) {
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Appointment Cancelled",
                        "Your appointment for $serviceName on $formattedDate at $formattedTime has been cancelled as requested.",
                        'appointment',
                        'customer/index.php'
                    );
                }
                // 2. Notify Admins
                notify_admins(
                    $pdo,
                    "Booking Cancelled by Client (#$appointmentId)",
                    "$clientName cancelled their $serviceName appointment scheduled for $formattedDate at $formattedTime.",
                    'appointment',
                    'admin/appointments.php'
                );
                // 3. Notify Stylist
                if ($stylistUserId) {
                    create_notification(
                        $pdo,
                        $stylistUserId,
                        "Assigned Appointment Cancelled (#$appointmentId)",
                        "Client $clientName cancelled appointment #$appointmentId for $serviceName on $formattedDate.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }
                break;

            case 'cancelled':
            case 'cancelled_by_admin':
            case 'cancelled_by_stylist':
                // 1. Notify Customer
                if ($customerUserId) {
                    $reason = !empty($extra['reason']) ? " Reason: " . $extra['reason'] : "";
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Appointment Notice: Cancelled",
                        "Your appointment for $serviceName on $formattedDate at $formattedTime was cancelled by the salon.$reason Please reschedule anytime.",
                        'appointment',
                        'customer/index.php'
                    );
                }
                // 2. Notify Admins if not cancelled by admin
                if ($event === 'cancelled_by_stylist') {
                    notify_admins(
                        $pdo,
                        "Appointment #$appointmentId Cancelled by Stylist",
                        "Stylist {$appt['stylist_name']} cancelled appointment #$appointmentId for $clientName.",
                        'appointment',
                        'admin/appointments.php'
                    );
                }
                // 3. Notify Stylist if cancelled by admin
                if ($stylistUserId && $event !== 'cancelled_by_stylist') {
                    create_notification(
                        $pdo,
                        $stylistUserId,
                        "Appointment #$appointmentId Cancelled",
                        "Appointment #$appointmentId for $clientName has been cancelled.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }
                break;

            case 'rescheduled':
                $newDateStr = !empty($extra['new_date']) ? date('M d, Y', strtotime($extra['new_date'])) : $formattedDate;
                $newTimeStr = !empty($extra['new_time']) ? date('h:i A', strtotime($extra['new_time'])) : $formattedTime;

                // 1. Notify Customer
                if ($customerUserId) {
                    create_notification(
                        $pdo,
                        $customerUserId,
                        "Appointment Rescheduled",
                        "Your appointment for $serviceName has been rescheduled to $newDateStr at $newTimeStr. We look forward to seeing you!",
                        'appointment',
                        'customer/index.php'
                    );
                }
                // 2. Notify Admins
                notify_admins(
                    $pdo,
                    "Appointment #$appointmentId Rescheduled",
                    "Appointment for $clientName ($serviceName) rescheduled to $newDateStr at $newTimeStr.",
                    'appointment',
                    'admin/appointments.php'
                );
                // 3. Notify Stylist
                if ($stylistUserId) {
                    create_notification(
                        $pdo,
                        $stylistUserId,
                        "Appointment #$appointmentId Rescheduled",
                        "Your assigned appointment for $clientName was rescheduled to $newDateStr at $newTimeStr.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }
                break;
        }

        // =====================================================
        // DISPATCH EMAIL NOTIFICATION VIA PHPMailer (if client has email)
        // =====================================================
        $clientEmail = trim($appt['client_email'] ?? '');
        if (!empty($clientEmail) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/mailer.php';

            $mailTitle = '';
            $mailPreheader = '';
            $statusBadge = '';
            $mainMessage = '';
            $settingKey = '';

            switch ($event) {
                case 'booked':
                    $settingKey = 'appointment_booked';
                    $mailTitle = "Appointment Request Received: $serviceName";
                    $mailPreheader = "Your appointment for $serviceName on $formattedDate has been requested.";
                    $statusBadge = '<span class="badge-pending">REQUEST PENDING</span>';
                    $mainMessage = "Hello <strong>" . htmlspecialchars($clientName) . "</strong>,<br><br>Thank you for choosing Elegance Salon! We have received your booking request for <strong>" . htmlspecialchars($serviceName) . "</strong>. Our salon team will review and confirm your slot shortly.";
                    break;

                case 'confirmed':
                    $settingKey = 'appointment_confirmed';
                    $mailTitle = "Appointment Confirmed: $serviceName";
                    $mailPreheader = "Your appointment for $serviceName on $formattedDate at $formattedTime is confirmed!";
                    $statusBadge = '<span class="badge-confirmed">CONFIRMED</span>';
                    $mainMessage = "Hello <strong>" . htmlspecialchars($clientName) . "</strong>,<br><br>Great news! Your salon appointment has been officially <strong>confirmed</strong>. We look forward to pampering you.";
                    break;

                case 'completed':
                    $settingKey = 'appointment_completed';
                    $mailTitle = "Thank you for visiting Elegance Salon!";
                    $mailPreheader = "We hope you loved your $serviceName experience.";
                    $statusBadge = '<span class="badge-confirmed">COMPLETED</span>';
                    $mainMessage = "Hello <strong>" . htmlspecialchars($clientName) . "</strong>,<br><br>Your appointment for <strong>" . htmlspecialchars($serviceName) . "</strong> has been marked as complete. Thank you for spending your day with us! We would love to hear your thoughts — please consider sharing your feedback with our team.";
                    break;

                case 'cancelled':
                case 'cancelled_by_customer':
                case 'cancelled_by_admin':
                case 'cancelled_by_stylist':
                    $settingKey = 'appointment_cancelled';
                    $mailTitle = "Appointment Notice: Cancelled (#$appointmentId)";
                    $mailPreheader = "Your appointment for $serviceName on $formattedDate has been cancelled.";
                    $statusBadge = '<span class="badge-cancelled">CANCELLED</span>';
                    $reasonText = !empty($extra['reason']) ? "<br><em>Reason: " . htmlspecialchars($extra['reason']) . "</em>" : "";
                    $mainMessage = "Hello <strong>" . htmlspecialchars($clientName) . "</strong>,<br><br>This is a notice that your appointment for <strong>" . htmlspecialchars($serviceName) . "</strong> scheduled for $formattedDate at $formattedTime has been cancelled.$reasonText<br><br>You are welcome to reschedule at your convenience through our website.";
                    break;

                case 'rescheduled':
                    $settingKey = 'appointment_rescheduled';
                    $newDateStr = !empty($extra['new_date']) ? date('M d, Y', strtotime($extra['new_date'])) : $formattedDate;
                    $newTimeStr = !empty($extra['new_time']) ? date('h:i A', strtotime($extra['new_time'])) : $formattedTime;
                    $mailTitle = "Appointment Rescheduled: $serviceName";
                    $mailPreheader = "Your appointment has been rescheduled to $newDateStr at $newTimeStr.";
                    $statusBadge = '<span class="badge-confirmed">RESCHEDULED</span>';
                    $mainMessage = "Hello <strong>" . htmlspecialchars($clientName) . "</strong>,<br><br>Your appointment for <strong>" . htmlspecialchars($serviceName) . "</strong> has been updated to <strong>$newDateStr</strong> at <strong>$newTimeStr</strong>.";
                    break;
            }

            if (!empty($mailTitle)) {
                $stylistDisplay = !empty($appt['stylist_name']) ? htmlspecialchars($appt['stylist_name']) : 'Assigned Salon Professional';
                $priceDisplay = !empty($appt['price']) ? ('$' . number_format((float)$appt['price'], 2)) : 'Standard Rate';

                $bodyHtml = '
                    <p style="margin: 0 0 16px; font-size: 16px;">' . $mainMessage . '</p>
                    
                    <div class="card-box">
                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <td style="padding: 8px 0; color: #94a3b8;">Booking Reference:</td>
                                <td style="padding: 8px 0; text-align: right; font-weight: bold; color: #e8c547;">ELG-' . str_pad((string)$appointmentId, 5, '0', STR_PAD_LEFT) . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <td style="padding: 8px 0; color: #94a3b8;">Service:</td>
                                <td style="padding: 8px 0; text-align: right; color: #f7f5f0; font-weight: bold;">' . htmlspecialchars($serviceName) . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <td style="padding: 8px 0; color: #94a3b8;">Date &amp; Time:</td>
                                <td style="padding: 8px 0; text-align: right; color: #f7f5f0;">' . $formattedDate . ' at ' . $formattedTime . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <td style="padding: 8px 0; color: #94a3b8;">Stylist:</td>
                                <td style="padding: 8px 0; text-align: right; color: #f7f5f0;">' . $stylistDisplay . '</td>
                            </tr>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <td style="padding: 8px 0; color: #94a3b8;">Estimated Price:</td>
                                <td style="padding: 8px 0; text-align: right; color: #e8c547; font-weight: bold;">' . $priceDisplay . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: #94a3b8;">Status:</td>
                                <td style="padding: 8px 0; text-align: right;">' . $statusBadge . '</td>
                            </tr>
                        </table>
                    </div>

                    <p style="margin: 20px 0 0; font-size: 13px; color: #94a3b8; line-height: 1.6;">
                        <strong>Salon Address:</strong> 123 Luxury Lane, Clifton, Karachi<br>
                        <strong>Need to make changes?</strong> Call us at <a href="tel:+923309066929" style="color: #e8c547;">+92 330 9066929</a> or chat on WhatsApp.
                    </p>
                ';

                $fullEmailHtml = render_salon_email_template($mailTitle, $mailPreheader, $bodyHtml);

                if ($settingKey !== '') {
                    $eventFingerprint = hash('sha256', json_encode([
                        $event,
                        $appt['status'],
                        $appt['appointment_date'],
                        $appt['appointment_time'],
                        $appt['updated_at'] ?? '',
                        $extra['reason'] ?? '',
                        $extra['new_date'] ?? '',
                        $extra['new_time'] ?? '',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

                    send_notification_email_once(
                        $pdo,
                        $settingKey,
                        "appointment:$appointmentId:$event:$eventFingerprint",
                        'appointment_' . $event,
                        $clientEmail,
                        $clientName,
                        $mailTitle,
                        $fullEmailHtml
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('notify_appointment_event error: ' . $e->getMessage());
    }
}

/**
 * Get count of unread notifications for a user.
 */
function get_unread_notification_count(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('get_unread_notification_count error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Fetch notifications for a user with pagination.
 */
function get_user_notifications(PDO $pdo, int $userId, int $limit = 30, int $offset = 0, ?bool $onlyUnread = null): array {
    try {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$userId];

        if ($onlyUnread === true) {
            $sql .= " AND is_read = 0";
        } elseif ($onlyUnread === false) {
            $sql .= " AND is_read = 1";
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_user_notifications error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Mark a specific notification as read.
 */
function mark_notification_as_read(PDO $pdo, int $notificationId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('mark_notification_as_read error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for a given user.
 */
function mark_all_notifications_as_read(PDO $pdo, int $userId): bool {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return true;
    } catch (Throwable $e) {
        error_log('mark_all_notifications_as_read error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Helper to format readable relative timestamp (e.g. '5 mins ago', 'Yesterday').
 */
function format_time_ago(string $datetime): string {
    $time = strtotime($datetime);
    if (!$time) return 'Recently';

    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 172800) {
        return 'Yesterday at ' . date('h:i A', $time);
    } else {
        return date('M d, Y h:i A', $time);
    }
}

/**
 * Generate a direct WhatsApp click-to-chat reminder link.
 */
function generate_whatsapp_reminder_link(string $clientPhone, string $clientName, string $serviceName, string $date, string $time): string {
    // Strip non-digits from phone number
    $cleanPhone = preg_replace('/\D+/', '', $clientPhone);
    // If phone starts with '03', convert to Pakistan country code '923'
    if (str_starts_with($cleanPhone, '03')) {
        $cleanPhone = '92' . substr($cleanPhone, 1);
    }

    $msg = "Hello {$clientName}! This is a friendly reminder from Elegance Salon about your appointment for '{$serviceName}' scheduled on " . date('M d, Y', strtotime($date)) . " at " . date('h:i A', strtotime($time)) . ". We look forward to seeing you!";
    return "https://wa.me/" . rawurlencode($cleanPhone) . "?text=" . rawurlencode($msg);
}

/**
 * Send an opted-in appointment reminder email, tracked separately from in-app reminders.
 */
function send_appointment_reminder_email(PDO $pdo, array $appointment, string $reminderType, string $formattedDate, string $formattedTime): void {
    $settingKey = $reminderType === '24h' ? 'reminder_24h' : 'reminder_2h';
    $email = trim((string)($appointment['client_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $settingsAvailable = false;
    $settings = get_email_notification_settings($pdo, $settingsAvailable);
    if (!$settingsAvailable || empty($settings['master_enabled']) || empty($settings[$settingKey])) {
        return;
    }

    require_once __DIR__ . '/mailer.php';
    if (!check_mail_configuration()['ready']) {
        log_mail_event('WARNING', 'Appointment reminder email skipped because SMTP is not ready.', [
            'appointment_id' => (int)$appointment['id'],
            'reminder_type' => $reminderType,
        ]);
        return;
    }

    $appointmentId = (int)$appointment['id'];
    try {
        $claim = $pdo->prepare("
            INSERT INTO appointment_reminders (appointment_id, reminder_type, channel, sent_at)
            VALUES (?, ?, 'email', NOW())
        ");
        $claim->execute([$appointmentId, $reminderType]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000') {
            return;
        }
        error_log('send_appointment_reminder_email claim error: ' . $e->getMessage());
        return;
    } catch (Throwable $e) {
        error_log('send_appointment_reminder_email claim error: ' . $e->getMessage());
        return;
    }

    $serviceName = (string)$appointment['service_name'];
    $subject = $reminderType === '24h'
        ? 'Reminder: Your appointment is tomorrow'
        : 'Reminder: Your appointment starts soon';
    $body = '<p>Hello <strong>' . htmlspecialchars((string)$appointment['client_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p>This is a friendly reminder about your appointment for <strong>'
        . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8')
        . '</strong> on ' . htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8')
        . ' at ' . htmlspecialchars($formattedTime, ENT_QUOTES, 'UTF-8') . '.</p>';
    $html = render_salon_email_template($subject, 'Your Elegance Salon appointment is coming up.', $body);

    send_notification_email_once(
        $pdo,
        $settingKey,
        "appointment_reminder:$appointmentId:$reminderType",
        'appointment_reminder_' . $reminderType,
        $email,
        (string)$appointment['client_name'],
        $subject,
        $html
    );
}

/**
 * Automatic Appointment Reminders Engine.
 * Automatically scans the database for approaching appointments:
 * - 24-hour reminder window
 * - 2-hour reminder window
 * Guarantees duplicate prevention via appointment_reminders table with unique constraint.
 * Logs all actions to logs/reminders.log.
 */
function process_appointment_reminders(PDO $pdo): array {
    $results = [
        'reminders_24h' => 0,
        'reminders_2h'  => 0,
        'errors'        => [],
        'timestamp'     => date('Y-m-d H:i:s')
    ];

    $logFile = __DIR__ . '/../logs/reminders.log';
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0755, true);
    }

    $logEntry = function(string $msg) use ($logFile) {
        $formatted = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        @file_put_contents($logFile, $formatted, FILE_APPEND);
    };

    try {
        // =================================================================
        // 1. Process 24-Hour Reminders
        // Identify appointments scheduled within the next 24 hours (and > 2h)
        // that have NOT yet received a '24h' reminder.
        // Status must be active (pending or confirmed).
        // =================================================================
        $sql24h = "
            SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.staff_id,
                   c.name as client_name, c.email as client_email, c.phone as client_phone,
                   s.name as service_name,
                   u.id as customer_user_id,
                   st.user_id as staff_user_id
            FROM appointments a
            JOIN clients c ON a.client_id = c.id
            JOIN services s ON a.service_id = s.id
            LEFT JOIN users u ON LOWER(TRIM(u.email)) = LOWER(TRIM(c.email))
            LEFT JOIN staff st ON a.staff_id = st.id
            WHERE a.status IN ('pending', 'confirmed')
              AND TIMESTAMP(a.appointment_date, a.appointment_time) > DATE_ADD(NOW(), INTERVAL 2 HOUR)
              AND TIMESTAMP(a.appointment_date, a.appointment_time) <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM appointment_reminders ar 
                  WHERE ar.appointment_id = a.id AND ar.reminder_type = '24h' AND ar.channel = 'in_app'
              )
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
        ";

        $stmt24h = $pdo->query($sql24h);
        $appts24h = $stmt24h->fetchAll(PDO::FETCH_ASSOC);

        foreach ($appts24h as $row) {
            $apptId = (int)$row['id'];
            $custUserId = !empty($row['customer_user_id']) ? (int)$row['customer_user_id'] : null;
            $staffUserId = !empty($row['staff_user_id']) ? (int)$row['staff_user_id'] : null;
            $formattedDate = date('M d, Y', strtotime($row['appointment_date']));
            $formattedTime = date('h:i A', strtotime($row['appointment_time']));

            $ownsTransaction = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTransaction = true;
            }

            try {
                // Record reminder in appointment_reminders to prevent duplicate sends
                $remStmt = $pdo->prepare("
                    INSERT INTO appointment_reminders (appointment_id, reminder_type, channel, sent_at)
                    VALUES (?, '24h', 'in_app', NOW())
                ");
                $remStmt->execute([$apptId]);

                // Create customer in-app notification if they have an active user account
                if ($custUserId) {
                    create_notification(
                        $pdo,
                        $custUserId,
                        "Reminder: Upcoming Appointment Tomorrow",
                        "Friendly reminder: Your appointment for {$row['service_name']} is scheduled for tomorrow, $formattedDate at $formattedTime. We look forward to welcoming you!",
                        'appointment',
                        'customer/index.php'
                    );
                }

                // Create assigned stylist in-app notification if assigned
                if ($staffUserId) {
                    create_notification(
                        $pdo,
                        $staffUserId,
                        "Upcoming Appointment Tomorrow",
                        "Reminder: You have an appointment with {$row['client_name']} for {$row['service_name']} tomorrow, $formattedDate at $formattedTime.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }

                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                send_appointment_reminder_email($pdo, $row, '24h', $formattedDate, $formattedTime);
                $results['reminders_24h']++;
                $logEntry("SUCCESS: 24h reminder generated for appointment #$apptId ({$row['client_name']} - {$row['service_name']})");
            } catch (Throwable $ex) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results['errors'][] = "Appt #$apptId (24h): " . $ex->getMessage();
                $logEntry("ERROR: Failed 24h reminder for #$apptId: " . $ex->getMessage());
            }
        }

        // =================================================================
        // 2. Process 2-Hour Reminders
        // Identify appointments scheduled within the next 2 hours
        // that have NOT yet received a '2h' reminder.
        // =================================================================
        $sql2h = "
            SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.staff_id,
                   c.name as client_name, c.email as client_email, c.phone as client_phone,
                   s.name as service_name,
                   u.id as customer_user_id,
                   st.user_id as staff_user_id
            FROM appointments a
            JOIN clients c ON a.client_id = c.id
            JOIN services s ON a.service_id = s.id
            LEFT JOIN users u ON LOWER(TRIM(u.email)) = LOWER(TRIM(c.email))
            LEFT JOIN staff st ON a.staff_id = st.id
            WHERE a.status IN ('pending', 'confirmed')
              AND TIMESTAMP(a.appointment_date, a.appointment_time) > NOW()
              AND TIMESTAMP(a.appointment_date, a.appointment_time) <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM appointment_reminders ar 
                  WHERE ar.appointment_id = a.id AND ar.reminder_type = '2h' AND ar.channel = 'in_app'
              )
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
        ";

        $stmt2h = $pdo->query($sql2h);
        $appts2h = $stmt2h->fetchAll(PDO::FETCH_ASSOC);

        foreach ($appts2h as $row) {
            $apptId = (int)$row['id'];
            $custUserId = !empty($row['customer_user_id']) ? (int)$row['customer_user_id'] : null;
            $staffUserId = !empty($row['staff_user_id']) ? (int)$row['staff_user_id'] : null;
            $formattedTime = date('h:i A', strtotime($row['appointment_time']));

            $ownsTransaction = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTransaction = true;
            }

            try {
                // Record reminder in appointment_reminders to prevent duplicate sends
                $remStmt = $pdo->prepare("
                    INSERT INTO appointment_reminders (appointment_id, reminder_type, channel, sent_at)
                    VALUES (?, '2h', 'in_app', NOW())
                ");
                $remStmt->execute([$apptId]);

                // Create customer in-app notification if they have an active user account
                if ($custUserId) {
                    create_notification(
                        $pdo,
                        $custUserId,
                        "Appointment Starting in 2 Hours",
                        "Your appointment for {$row['service_name']} starts today at $formattedTime. Please aim to arrive 5–10 minutes early. See you soon!",
                        'appointment',
                        'customer/index.php'
                    );
                }

                // Create assigned stylist in-app notification if assigned
                if ($staffUserId) {
                    create_notification(
                        $pdo,
                        $staffUserId,
                        "Appointment Starting in 2 Hours",
                        "Reminder: Your appointment with {$row['client_name']} for {$row['service_name']} starts today at $formattedTime.",
                        'appointment',
                        'stylist/dashboard.php'
                    );
                }

                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                $formattedDate = date('M d, Y', strtotime($row['appointment_date']));
                send_appointment_reminder_email($pdo, $row, '2h', $formattedDate, $formattedTime);
                $results['reminders_2h']++;
                $logEntry("SUCCESS: 2h reminder generated for appointment #$apptId ({$row['client_name']} - {$row['service_name']})");
            } catch (Throwable $ex) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results['errors'][] = "Appt #$apptId (2h): " . $ex->getMessage();
                $logEntry("ERROR: Failed 2h reminder for #$apptId: " . $ex->getMessage());
            }
        }

        $logEntry("RUN COMPLETE: {$results['reminders_24h']} 24h reminders, {$results['reminders_2h']} 2h reminders.");
    } catch (Throwable $e) {
        $results['errors'][] = $e->getMessage();
        $logEntry("FATAL ERROR in process_appointment_reminders: " . $e->getMessage());
    }

    return $results;
}

/**
 * Scan inventory table for products where quantity <= low_stock_threshold.
 * Creates in-app notifications for admins, avoiding duplicate unread alerts.
 */
function check_low_inventory(PDO $pdo): array {
    $results = [
        'checked'     => 0,
        'alerts_sent' => 0,
        'errors'      => []
    ];

    try {
        $stmt = $pdo->query("
            SELECT id, product_name, category, quantity, low_stock_threshold, unit_price, last_updated
            FROM inventory
            WHERE quantity <= low_stock_threshold
            ORDER BY quantity ASC
        ");
        $lowProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['checked'] = count($lowProducts);

        foreach ($lowProducts as $prod) {
            $prodId = (int)$prod['id'];
            $prodName = $prod['product_name'];
            $qty = (int)$prod['quantity'];
            $threshold = (int)$prod['low_stock_threshold'];
            $title = "Low Stock Alert: " . $prodName;
            $msg = "Product '{$prodName}' has reached low stock: {$qty} units remaining (Minimum threshold: {$threshold}). Please restock soon.";

            // Check if an unread notification already exists for this low inventory product
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE type = 'inventory'
                  AND is_read = 0
                  AND (message LIKE ? OR title LIKE ?)
            ");
            $likeStr = "%$prodName%";
            $checkStmt->execute([$likeStr, $likeStr]);
            $alreadyNotified = ((int)$checkStmt->fetchColumn()) > 0;

            if (!$alreadyNotified) {
                $link = "admin/inventory.php";

                notify_admins($pdo, $title, $msg, 'inventory', $link);
                $results['alerts_sent']++;
            }

            send_admin_notification_emails_once(
                $pdo,
                'low_stock',
                "inventory-low:$prodId:$qty:$threshold:{$prod['last_updated']}",
                $title,
                $msg
            );
        }
    } catch (Throwable $e) {
        $results['errors'][] = $e->getMessage();
        error_log('check_low_inventory error: ' . $e->getMessage());
    }

    return $results;
}

/**
 * Notify admin when inventory has been replenished above the threshold.
 * Also marks previous unread low-stock alerts for this product as read.
 */
function notify_inventory_replenished(PDO $pdo, int $productId, int $newQuantity): bool {
    try {
        $stmt = $pdo->prepare("SELECT product_name, low_stock_threshold FROM inventory WHERE id = ?");
        $stmt->execute([$productId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) return false;

        $prodName = $prod['product_name'];
        $threshold = (int)$prod['low_stock_threshold'];

        if ($newQuantity > $threshold) {
            // Mark existing unread low-stock alerts for this product as read
            $resolveStmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE type = 'inventory'
                  AND is_read = 0
                  AND (message LIKE ? OR title LIKE ?)
            ");
            $likeStr = "%$prodName%";
            $resolveStmt->execute([$likeStr, $likeStr]);

            // Create replenished notice
            $title = "Inventory Restocked: " . $prodName;
            $msg = "Stock for '{$prodName}' was updated to {$newQuantity} units (exceeds threshold of {$threshold}). Alert condition resolved.";
            notify_admins($pdo, $title, $msg, 'inventory', 'admin/inventory.php');
            return true;
        }
        return false;
    } catch (Throwable $e) {
        error_log('notify_inventory_replenished error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Scan payments table for payments with payment_status = 'pending'.
 * Alerts admin/receptionists about outstanding payments with invoice reference and amount.
 * Avoids duplicate unread alerts.
 */
function check_pending_payments(PDO $pdo): array {
    $results = [
        'checked'     => 0,
        'alerts_sent' => 0,
        'errors'      => []
    ];

    try {
        $stmt = $pdo->query("
            SELECT p.id, p.appointment_id, p.amount, p.payment_method, p.payment_status,
                   p.invoice_number, p.created_at,
                   c.name as client_name, c.email as client_email,
                   s.name as service_name
            FROM payments p
            JOIN clients c ON p.client_id = c.id
            LEFT JOIN appointments a ON p.appointment_id = a.id
            LEFT JOIN services s ON a.service_id = s.id
            WHERE p.payment_status = 'pending'
            ORDER BY p.created_at DESC
        ");
        $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['checked'] = count($pendingPayments);

        foreach ($pendingPayments as $pay) {
            $payId = (int)$pay['id'];
            $invoice = !empty($pay['invoice_number']) ? $pay['invoice_number'] : ('PAY-' . str_pad($payId, 5, '0', STR_PAD_LEFT));
            $amount = number_format((float)$pay['amount'], 2);
            $clientName = $pay['client_name'];
            $serviceName = !empty($pay['service_name']) ? " for '{$pay['service_name']}'" : "";
            $title = "Pending Payment: Invoice #{$invoice}";
            $msg = "Payment of \${$amount} from {$clientName}{$serviceName} (Invoice #{$invoice}) is currently pending.";

            // Check if unread notification already exists for this invoice or payment
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE type = 'payment'
                  AND is_read = 0
                  AND (message LIKE ? OR message LIKE ?)
            ");
            $likeInvoice = "%$invoice%";
            $likePayId = "%Payment #$payId%";
            $checkStmt->execute([$likeInvoice, $likePayId]);
            $alreadyNotified = ((int)$checkStmt->fetchColumn()) > 0;

            if (!$alreadyNotified) {
                $link = "admin/payments.php";

                notify_admins($pdo, $title, $msg, 'payment', $link);
                $results['alerts_sent']++;
            }

            send_admin_notification_emails_once(
                $pdo,
                'payment_alerts',
                "payment-pending:$payId",
                $title,
                $msg
            );
        }
    } catch (Throwable $e) {
        $results['errors'][] = $e->getMessage();
        error_log('check_pending_payments error: ' . $e->getMessage());
    }

    return $results;
}

/**
 * Notify when payment status changes (e.g. marked 'paid' or 'refunded').
 * Notifies admins/receptionists and the customer if linked.
 */
function notify_payment_status_change(PDO $pdo, int $paymentId, string $newStatus): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_status, p.invoice_number, p.client_id, p.paid_at,
                   c.name as client_name, c.email as client_email,
                   u.id as customer_user_id
            FROM payments p
            JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON LOWER(TRIM(u.email)) = LOWER(TRIM(c.email))
            WHERE p.id = ?
        ");
        $stmt->execute([$paymentId]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) return false;

        $invoice = !empty($pay['invoice_number']) ? $pay['invoice_number'] : ('PAY-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT));
        $amount = number_format((float)$pay['amount'], 2);
        $clientName = $pay['client_name'];
        $custUserId = !empty($pay['customer_user_id']) ? (int)$pay['customer_user_id'] : null;
        $paymentEventVersion = !empty($pay['paid_at']) ? (string)$pay['paid_at'] : 'unpaid';

        // If newly marked paid:
        if ($newStatus === 'paid') {
            // 1. Mark any previous unread "pending payment" alerts for this invoice as read
            $resolveStmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE type = 'payment'
                  AND is_read = 0
                  AND (message LIKE ? OR message LIKE ?)
            ");
            $resolveStmt->execute(["%$invoice%", "%Payment #$paymentId%"]);

            // 2. Notify admins
            $adminTitle = "Payment Received: Invoice #{$invoice}";
            $adminMsg = "Payment of \${$amount} received from {$clientName} (Invoice #{$invoice}).";
            notify_admins($pdo, $adminTitle, $adminMsg, 'payment', 'admin/payments.php');

            // 3. Notify customer if user account exists
            if ($custUserId) {
                create_notification(
                    $pdo,
                    $custUserId,
                    "Payment Confirmed - \${$amount}",
                    "Your payment of \${$amount} for Invoice #{$invoice} has been successfully processed. Thank you!",
                    'payment',
                    'customer/index.php'
                );
            }
            send_admin_notification_emails_once(
                $pdo,
                'payment_alerts',
                "payment:$paymentId:paid:$paymentEventVersion",
                $adminTitle,
                $adminMsg
            );
            $clientEmail = trim((string)$pay['client_email']);
            if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $customerSubject = "Payment Confirmed - \${$amount}";
                $customerBody = '<p>Hello <strong>' . htmlspecialchars((string)$clientName, ENT_QUOTES, 'UTF-8')
                    . '</strong>,</p><p>Your payment of $' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8')
                    . ' for invoice <strong>' . htmlspecialchars($invoice, ENT_QUOTES, 'UTF-8')
                    . '</strong> has been successfully processed. Thank you!</p>';
                $customerHtml = render_salon_email_template(
                    $customerSubject,
                    'Your Elegance Salon payment has been confirmed.',
                    $customerBody
                );
                send_notification_email_once(
                    $pdo,
                    'payment_alerts',
                    "payment:$paymentId:paid:$paymentEventVersion:customer",
                    'payment_paid',
                    $clientEmail,
                    $clientName,
                    $customerSubject,
                    $customerHtml
                );
            }
            return true;
        } elseif ($newStatus === 'refunded') {
            $adminTitle = "Payment Refunded: Invoice #{$invoice}";
            $adminMsg = "A refund of \${$amount} was issued to {$clientName} (Invoice #{$invoice}).";
            notify_admins($pdo, $adminTitle, $adminMsg, 'payment', 'admin/payments.php');

            if ($custUserId) {
                create_notification(
                    $pdo,
                    $custUserId,
                    "Payment Refund Processed",
                    "A refund of \${$amount} for Invoice #{$invoice} has been processed.",
                    'payment',
                    'customer/index.php'
                );
            }
            send_admin_notification_emails_once(
                $pdo,
                'payment_alerts',
                "payment:$paymentId:refunded:$paymentEventVersion",
                $adminTitle,
                $adminMsg
            );
            $clientEmail = trim((string)$pay['client_email']);
            if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $customerSubject = 'Payment Refund Processed';
                $customerBody = '<p>Hello <strong>' . htmlspecialchars((string)$clientName, ENT_QUOTES, 'UTF-8')
                    . '</strong>,</p><p>A refund of $' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8')
                    . ' for invoice <strong>' . htmlspecialchars($invoice, ENT_QUOTES, 'UTF-8')
                    . '</strong> has been processed.</p>';
                $customerHtml = render_salon_email_template(
                    $customerSubject,
                    'An update about your Elegance Salon payment.',
                    $customerBody
                );
                send_notification_email_once(
                    $pdo,
                    'payment_alerts',
                    "payment:$paymentId:refunded:$paymentEventVersion:customer",
                    'payment_refunded',
                    $clientEmail,
                    $clientName,
                    $customerSubject,
                    $customerHtml
                );
            }
            return true;
        }

        return false;
    } catch (Throwable $e) {
        error_log('notify_payment_status_change error: ' . $e->getMessage());
        return false;
    }
}
