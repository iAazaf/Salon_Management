<?php
session_start();
require_once 'config/database.php';

$error = '';

/*
|--------------------------------------------------------------------------
| Redirect logged-in users according to their role
|--------------------------------------------------------------------------
*/
function redirect_by_role(string $role): void
{
    if ($role === 'stylist') {
        header('Location: stylist/dashboard.php');
    } elseif ($role === 'admin' || $role === 'receptionist') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: customer/index.php');
    }

    exit;
}

if (isset($_SESSION['user_id'])) {
    redirect_by_role($_SESSION['user_role'] ?? 'client');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Registration handler
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedCsrf) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)
    ) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {

        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $termsAccepted = isset($_POST['terms_accepted']);

        if (
            $name === '' ||
            $email === '' ||
            $password === '' ||
            $confirmPassword === ''
        ) {
            $error = 'Please fill in all required fields.';

        } elseif (strlen($name) > 150) {
            $error = 'Your name is too long.';

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';

        } elseif (strlen($email) > 254) {
            $error = 'Please use a shorter email address.';

        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';

        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';

        } elseif (!$termsAccepted) {
            $error = 'Please accept the terms to continue.';

        } else {

            try {
                /*
                 * Keep both inserts together. If either fails,
                 * roll back so a partial registration is not saved.
                 */
                $pdo->beginTransaction();

                $check = $pdo->prepare(
                    'SELECT id FROM users WHERE email = ? LIMIT 1'
                );
                $check->execute([$email]);

                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->rollBack();
                    $error = 'This email is already registered. Please sign in.';

                } else {

                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    /*
                     * Public registration always creates a client.
                     * Never accept a role from the submitted form.
                     */
                    $stmt = $pdo->prepare(
                        "INSERT INTO users
                            (name, email, phone, password, role, status)
                         VALUES (?, ?, ?, ?, 'client', 'active')"
                    );

                    $stmt->execute([
                        $name,
                        $email,
                        $phone !== '' ? $phone : null,
                        $hash
                    ]);

                    /*
                     * IMPORTANT:
                     * Capture the users ID immediately, before inserting
                     * anything into another auto-increment table.
                     */
                    $userId = (int) $pdo->lastInsertId();

                    /*
                     * Sync customer details for appointment booking.
                     */
                    $clientCheck = $pdo->prepare(
                        'SELECT id FROM clients WHERE email = ? LIMIT 1'
                    );
                    $clientCheck->execute([$email]);

                    if (!$clientCheck->fetch(PDO::FETCH_ASSOC)) {
                        $clientInsert = $pdo->prepare(
                            'INSERT INTO clients (name, email, phone)
                             VALUES (?, ?, ?)'
                        );

                        $clientInsert->execute([
                            $name,
                            $email,
                            $phone !== '' ? $phone : 'N/A'
                        ]);
                    }

                    $pdo->commit();

                    /*
                     * Automatically sign in the newly registered client.
                     */
                    session_regenerate_id(true);

                    unset($_SESSION['csrf_token']);

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = 'client';

                    header('Location: customer/index.php');
                    exit;
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Keep technical database errors out of the public page.
                error_log('Elegance registration error: ' . $e->getMessage());

                $error = 'Registration could not be completed. Please try again.';
            }
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | Elegance Salon</title>
    <meta name="description"
          content="Create your Elegance Salon customer account and manage your appointments online.">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap"
        rel="stylesheet">

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .register-box {
            max-width: 520px;
        }
        .row-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        @media (max-width: 576px) {
            .row-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>

<body>
<div class="login-wrapper">

    <div class="login-left">
        <div class="text-center" style="max-width: 440px;">
            <img src="assets/img/logo.png" class="salon-mark-icon fa-4x mb-4" alt="" aria-hidden="true">
            <h2 class="text-white mb-3" style="font-family: 'Playfair Display', serif;">ELEGANCE SALON</h2>
            <p style="color: var(--cream); font-size: 1.15rem; margin-bottom: 0.5rem;">Your beauty journey starts <em style="color: var(--champagne); font-style: italic;">here.</em></p>
            <p class="small" style="color: var(--champagne);">Join our exclusive salon community for seamless booking & personalized care.</p>
            <div class="mt-4 text-start d-none d-md-block" style="background: rgba(12, 15, 20, 0.45); border: 1px solid var(--border); border-radius: 8px; padding: 18px 22px;">
                <div class="d-flex align-items-center gap-2 mb-2 text-white small">
                    <i class="fas fa-check-circle text-gold"></i>
                    <span>Instant real-time appointment booking</span>
                </div>
                <div class="d-flex align-items-center gap-2 mb-2 text-white small">
                    <i class="fas fa-check-circle text-gold"></i>
                    <span>Track your appointment history & status</span>
                </div>
                <div class="d-flex align-items-center gap-2 text-white small">
                    <i class="fas fa-check-circle text-gold"></i>
                    <span>Personalized stylist & service preferences</span>
                </div>
            </div>
        </div>
    </div>

    <div class="login-right">
        <div class="login-box register-box">
            <h2 style="font-family: 'Playfair Display', serif; color: var(--cream);">Create Account</h2>
            <p class="subtitle" style="color: var(--muted);">Customer sign up — takes less than a minute</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2" role="alert">
                    <?= e($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="on">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token'] ?? ''); ?>">

                <div class="mb-3">
                    <label class="form-label-elegant" for="signup-name">
                        Full Name *
                    </label>
                    <input
                        type="text"
                        id="signup-name"
                        name="name"
                        class="form-control form-control-elegant"
                        placeholder="Your full name"
                        autocomplete="name"
                        maxlength="150"
                        required
                        value="<?= e($_POST['name'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label-elegant" for="signup-email">
                        Email Address *
                    </label>
                    <input
                        type="email"
                        id="signup-email"
                        name="email"
                        class="form-control form-control-elegant"
                        placeholder="you@example.com"
                        autocomplete="email"
                        maxlength="254"
                        required
                        value="<?= e($_POST['email'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label-elegant" for="signup-phone">
                        Phone Number
                    </label>
                    <input
                        type="tel"
                        id="signup-phone"
                        name="phone"
                        class="form-control form-control-elegant"
                        placeholder="03XX-XXXXXXX"
                        autocomplete="tel"
                        value="<?= e($_POST['phone'] ?? ''); ?>">
                </div>

                <div class="row-2 mb-3">
                    <div class="mb-3 mb-sm-0">
                        <label class="form-label-elegant" for="signup-password">
                            Password *
                        </label>
                        <input
                            type="password"
                            id="signup-password"
                            name="password"
                            class="form-control form-control-elegant"
                            placeholder="Min. 6 characters"
                            autocomplete="new-password"
                            minlength="6"
                            required>
                    </div>

                    <div>
                        <label class="form-label-elegant" for="signup-confirm">
                            Confirm Password *
                        </label>
                        <input
                            type="password"
                            id="signup-confirm"
                            name="confirm_password"
                            class="form-control form-control-elegant"
                            placeholder="Re-enter password"
                            autocomplete="new-password"
                            minlength="6"
                            required>
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        id="signup-terms"
                        name="terms_accepted"
                        value="1"
                        required
                        <?= isset($_POST['terms_accepted']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="signup-terms" style="color: var(--muted);">
                        I agree to the terms of service and privacy policy.
                    </label>
                </div>

                <button type="submit" class="btn btn-gold w-100 mb-3">
                    <i class="fas fa-user-plus me-1" aria-hidden="true"></i>
                    Create Customer Account
                </button>
            </form>

            <p class="text-center small mt-3" style="color: var(--muted);">
                Already a member?
                <a href="login.php" class="text-gold" style="font-weight:600;">Sign In</a>
            </p>

            <div class="text-center mt-2">
                <a href="index.php" class="small text-sage">
                    <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                    Back to Home
                </a>
            </div>

        </div>
    </div>

</div>
</body>
</html>