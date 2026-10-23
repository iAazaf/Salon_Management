<?php
// =====================================================
// Elegance Salon Management System
// Mail & Messaging Base Configuration
// =====================================================

$baseConfig = [
    // In-App notifications are active by default.
    'in_app_enabled' => true,

    // Default Email / SMTP Configuration
    'email' => [
        'enabled'     => false,
        'smtp_host'   => 'smtp.gmail.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls', // 'tls' (STARTTLS, port 587) or 'ssl' (port 465)
        'smtp_user'   => '',    // Enter your full Gmail address in config/mail.local.php
        'smtp_pass'   => '',    // Enter your 16-character Google App Password in config/mail.local.php
        'from_email'  => '',    // Defaults to smtp_user if left blank
        'from_name'   => 'Elegance Salon',
    ],

    // WhatsApp Reminder Configuration (Direct click-to-chat links work out of the box)
    'whatsapp' => [
        'enabled'     => false,
        'api_url'     => '',
        'api_token'   => '',
        'salon_phone' => '923309066929',
    ],

    'cron_token' => 'elegance_reminder_secret_2026',
    'log_file'   => __DIR__ . '/../logs/reminders.log',
];

// Load local overrides if config/mail.local.php exists (contains your personal credentials, excluded from git)
$localConfigFile = __DIR__ . '/mail.local.php';
if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        if (isset($localConfig['email']) && is_array($localConfig['email'])) {
            $baseConfig['email'] = array_merge($baseConfig['email'], $localConfig['email']);
        }
        if (isset($localConfig['whatsapp']) && is_array($localConfig['whatsapp'])) {
            $baseConfig['whatsapp'] = array_merge($baseConfig['whatsapp'], $localConfig['whatsapp']);
        }
    }
}

// Environment variables fallback (if set in server environment)
if (getenv('SMTP_USER')) {
    $baseConfig['email']['smtp_user'] = getenv('SMTP_USER');
}
if (getenv('SMTP_PASS')) {
    $baseConfig['email']['smtp_pass'] = getenv('SMTP_PASS');
}
if (getenv('SMTP_ENABLED') !== false) {
    $baseConfig['email']['enabled'] = filter_var(getenv('SMTP_ENABLED'), FILTER_VALIDATE_BOOLEAN);
}

return $baseConfig;
