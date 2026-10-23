# Elegance Salon Management System

An existing PHP, MySQL, HTML, CSS, JavaScript, and Bootstrap salon management application for XAMPP.

## Requirements

- XAMPP with Apache, MySQL/MariaDB, and PHP 8.x (checked with PHP 8.2.12 and MariaDB 10.4.32). The database schema and migrations have been tested on MariaDB 10.4.32 only; validate against your specific MySQL version before claiming MySQL compatibility.
- PHP extensions: `pdo_mysql`, `mbstring`, and `openssl`.
- A browser and internet access for the Bootstrap, Font Awesome, and Google Fonts CDN assets.
- PHPMailer is included under `lib/PHPMailer`; Composer and npm are not required.

## XAMPP setup

1. Place the project at `C:\xampp\htdocs\elegance`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Import the project-provided `database.sql` in phpMyAdmin or with the MySQL command-line client. It creates the `elegance_salon` database, all 17 tables, appointment schema alignment, email preference tables and defaults, and demonstration seed records. Use it only for a **fresh installation**; do not import it over an existing working database because the demo seed rows are intended for an empty database.
4. Check `config/database.php`. Its defaults use `localhost`, database `elegance_salon`, and the standard XAMPP `root` account with an empty password. Change these values if your local MySQL credentials differ; do not publish real credentials.
5. Visit `http://localhost/elegance/`. Sign in at `http://localhost/elegance/login.php`.

For an existing database, do not import the full `database.sql` seed script. Apply `database_migration_email_notifications.sql` and `database_migration_schema_alignment.sql` separately; they are repeatable upgrades that preserve existing application data and saved email preferences. The full database export is local backup data, not a required project installation file.

## Application routes

- Login: `/login.php`
- Customer account and appointments: `/customer/index.php`
- Customer appointment booking: `/book.php` (requires sign-in)
- Admin dashboard: `/admin/dashboard.php`
- Admin appointment management: `/admin/appointments.php`
- Stylist dashboard: `/stylist/dashboard.php`

Successful login redirects users according to their account role. Admin and receptionist accounts use the admin portal; customers use the customer account; stylists use the stylist portal. Staff management is restricted to administrators.

## Dependencies and assets

- PHPMailer is vendored in `lib/PHPMailer` and loaded directly by `includes/mailer.php`; no dependency installation step is needed.
- Bootstrap 5.3.3, Font Awesome 6.5.1, and Google Fonts are referenced through CDNs. A network connection is needed to load those remote assets.
- MySQL access uses PDO through `config/database.php`.

## Email notification preferences

Administrators can manage saved notification preferences at `/admin/email_settings.php`. The page controls the master email switch, appointment lifecycle messages, opt-in 24-hour and 2-hour reminder emails, low-stock alerts, and payment alerts. Turning off email delivery does not disable in-app notifications.

Fresh installations receive the email tables and default preferences from `database.sql`. For an existing database, apply `database_migration_email_notifications.sql` before using the settings page. In phpMyAdmin, select `elegance_salon`, open **Import**, choose the migration file, and run it. The migration is repeatable and preserves existing choices.

Email delivery uses the included PHPMailer library. SMTP credentials remain in the ignored, web-protected `config/mail.local.php`, or can be supplied through the `SMTP_USER`, `SMTP_PASS`, and `SMTP_ENABLED` environment variables. The preferences page does not display credentials or send test messages. Never place an actual SMTP password in this README, source control, or a support message.

## Appointment reminder scheduling on Windows/XAMPP

`cron/process_reminders.php` runs the 24-hour and 2-hour in-app reminder processor and the existing low-stock and pending-payment scans. It is also available as a manual admin action in **Notifications**. No matching Windows Scheduled Task was present when this workspace was checked, so reminders are **not automatic until a task is created**.

To schedule the runner:

1. Open **Task Scheduler** and choose **Create Basic Task**.
2. Set a repeating trigger (every 5–10 minutes is recommended).
3. For **Start a program**, set **Program/script** to `C:\xampp\php\php.exe`.
4. Set **Add arguments** to `"C:\xampp\htdocs\elegance\cron\process_reminders.php"`.
5. Set **Start in** to `C:\xampp\htdocs\elegance`, then save and use **Run** once to verify the task.

For a manual run from PowerShell, use:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\elegance\cron\process_reminders.php"
```

The email reminder categories are off by default and must be enabled in Email Notifications. Each email reminder is recorded with its own `email` channel in `appointment_reminders`, separately from its in-app reminder. Failed or already-claimed email events are not sent a second time automatically.

## Troubleshooting

- **Database connection error:** confirm MySQL is running, the database exists, and `config/database.php` matches your local host, database name, username, and password.
- **Missing tables or schema errors:** for a complete restore, import the full SQL export into an empty database as described above. Do not combine that import with the baseline schema script, and do not import it over the working database.
- **PDO/MySQL errors:** enable the PHP `pdo_mysql` extension in the active XAMPP PHP configuration, then restart Apache.
- **Missing icons, fonts, or Bootstrap styling:** check internet access and browser developer tools for CDN request failures.
- **Login or CSRF errors:** allow cookies for `localhost`, use the same hostname consistently, and reload the page to obtain a fresh form token.
- **Email delivery failures:** confirm SMTP is enabled in the protected local server configuration, and check `logs/mail.log` without sharing credentials or sensitive log contents. Failed event attempts are recorded to prevent accidental duplicate emails.
- **PHP errors:** review the XAMPP Apache/PHP error log and the application's `logs/` directory. Do not expose database dumps, logs, or local credential files through the web server.
