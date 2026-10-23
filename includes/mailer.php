<?php
// =====================================================
// Elegance Salon Management System
// Reusable PHPMailer SMTP Helper
// =====================================================

require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Get mail configuration array.
 */
function get_mail_config(): array {
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config/mail.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = [];
        }
    }
    return $config;
}

/**
 * Log mail messages safely to file (without logging passwords).
 */
function log_mail_event(string $level, string $message, array $context = []): void {
    $config = get_mail_config();
    $logFile = $config['log_file'] ?? (__DIR__ . '/../logs/mail.log');
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Mask any accidental sensitive data in context
    if (isset($context['smtp_pass'])) {
        $context['smtp_pass'] = '***REDACTED***';
    }

    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $entry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;

    @file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Check if SMTP is configured and enabled.
 * Returns: ['ready' => bool, 'reason' => string]
 */
function check_mail_configuration(): array {
    $config = get_mail_config();
    $email = $config['email'] ?? [];

    if (empty($email['enabled'])) {
        return [
            'ready' => false,
            'reason' => 'Email delivery is currently disabled. Set enabled => true in config/mail.local.php.'
        ];
    }

    if (empty($email['smtp_user'])) {
        return [
            'ready' => false,
            'reason' => 'SMTP Username is empty. Please enter your Gmail address in config/mail.local.php.'
        ];
    }

    if (empty($email['smtp_pass'])) {
        return [
            'ready' => false,
            'reason' => 'SMTP Password is empty. Please enter your 16-character Google App Password in config/mail.local.php.'
        ];
    }

    return [
        'ready' => true,
        'reason' => 'Configured'
    ];
}

/**
 * Create and configure a PHPMailer instance.
 *
 * @throws Exception
 */
function create_phpmailer_instance(bool $debug = false): PHPMailer {
    $config = get_mail_config();
    $email = $config['email'] ?? [];

    $mail = new PHPMailer(true);

    // Server settings
    $mail->isSMTP();
    $mail->Host       = $email['smtp_host'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = trim($email['smtp_user'] ?? '');
    $mail->Password   = trim($email['smtp_pass'] ?? '');
    
    // Secure encryption: STARTTLS or SSL
    $secure = strtolower($email['smtp_secure'] ?? 'tls');
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int)($email['smtp_port'] ?? 465);
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($email['smtp_port'] ?? 587);
    }

    // Sender details
    $fromEmail = !empty($email['from_email']) ? trim($email['from_email']) : $mail->Username;
    $fromName  = !empty($email['from_name']) ? trim($email['from_name']) : 'Elegance Salon';
    $mail->setFrom($fromEmail, $fromName);
    $mail->CharSet = 'UTF-8';

    if ($debug) {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    } else {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
    }

    return $mail;
}

/**
 * Send an email via PHPMailer.
 * 
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML content
 * @param string $plainTextBody Plain text fallback
 * @return array ['success' => bool, 'message' => string, 'error' => ?string]
 */
function send_app_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainTextBody = ''): array {
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid recipient email address.',
            'error'   => 'INVALID_EMAIL'
        ];
    }

    $configStatus = check_mail_configuration();
    if (!$configStatus['ready']) {
        log_mail_event('WARNING', 'Email not sent: ' . $configStatus['reason'], ['to' => $toEmail, 'subject' => $subject]);
        return [
            'success' => false,
            'message' => $configStatus['reason'],
            'error'   => 'CONFIG_INCOMPLETE'
        ];
    }

    try {
        $mail = create_phpmailer_instance(false);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainTextBody ?: strip_tags($htmlBody);

        $mail->send();

        log_mail_event('INFO', "Email successfully sent to $toEmail", ['subject' => $subject]);
        return [
            'success' => true,
            'message' => 'Email sent successfully.',
            'error'   => null
        ];
    } catch (Exception $e) {
        // Safe sanitized error message - never leak passwords
        $safeError = $mail->ErrorInfo ?: $e->getMessage();
        log_mail_event('ERROR', "PHPMailer error sending to $toEmail: $safeError", ['subject' => $subject]);

        return [
            'success' => false,
            'message' => 'SMTP delivery failed: ' . htmlspecialchars($safeError, ENT_QUOTES, 'UTF-8'),
            'error'   => $safeError
        ];
    } catch (Throwable $e) {
        log_mail_event('CRITICAL', "Unexpected exception sending mail to $toEmail: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Unexpected mail error occurred.',
            'error'   => $e->getMessage()
        ];
    }
}

/**
 * Generate a beautifully branded Elegance Salon HTML email template.
 */
function render_salon_email_template(string $title, string $preheader, string $contentHtml): string {
    return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { margin: 0; padding: 0; background-color: #0c0f14; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; color: #f7f5f0; }
        table { border-collapse: collapse; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #0c0f14; padding: 30px 10px; }
        .main { background-color: #1c232c; margin: 0 auto; width: 100%; max-width: 600px; border-radius: 12px; border: 1px solid rgba(232, 197, 71, 0.25); overflow: hidden; }
        .header { background: linear-gradient(135deg, #151a21, #0c0f14); padding: 35px 25px; text-align: center; border-bottom: 1px solid rgba(232, 197, 71, 0.2); }
        .header h1 { margin: 0; font-family: Georgia, serif; color: #e8c547; font-size: 26px; letter-spacing: 2px; }
        .header p { margin: 6px 0 0; color: #94a3b8; font-size: 13px; letter-spacing: 1px; text-transform: uppercase; }
        .body-content { padding: 35px 30px; font-size: 15px; line-height: 1.7; color: #e2e8f0; }
        .card-box { background-color: #151a21; border-radius: 8px; border: 1px solid rgba(232, 197, 71, 0.15); padding: 20px; margin: 25px 0; }
        .card-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .badge-pending { display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 4px; background: rgba(232, 197, 71, 0.2); color: #e8c547; border: 1px solid #e8c547; }
        .badge-confirmed { display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 4px; background: rgba(13, 148, 136, 0.25); color: #2dd4bf; border: 1px solid #0d9488; }
        .badge-cancelled { display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 4px; background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid #ef4444; }
        .footer { background-color: #111419; padding: 25px 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid rgba(255, 255, 255, 0.08); }
        .footer a { color: #e8c547; text-decoration: none; }
        .gold-text { color: #e8c547; font-weight: bold; }
    </style>
</head>
<body>
    <div style="display:none;font-size:1px;color:#0c0f14;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
        ' . htmlspecialchars($preheader) . '
    </div>
    <table class="wrapper">
        <tr>
            <td align="center">
                <table class="main">
                    <tr>
                        <td class="header">
                            <h1>ELEGANCE SALON</h1>
                            <p>Luxury Hair &bull; Skin &bull; Wellness</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="body-content">
                            ' . $contentHtml . '
                        </td>
                    </tr>
                    <tr>
                        <td class="footer">
                            <p style="margin: 0 0 8px;">123 Luxury Lane, Clifton, Karachi &bull; Phone: +92 330 9066929</p>
                            <p style="margin: 0 0 8px;">Email: <a href="mailto:info@elegancesalon.com">info@elegancesalon.com</a> &bull; Web: <a href="http://localhost/elegance/">www.elegancesalon.com</a></p>
                            <p style="margin: 0; color: #64748b;">&copy; ' . date('Y') . ' Elegance Salon. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}
