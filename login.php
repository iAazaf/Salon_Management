<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';

$error = '';

function safe_return_destination(): ?string {
    // Only allow a known local destination; never accept arbitrary external URLs.
    $destination = $_POST['return_to'] ?? $_GET['return_to'] ?? '';
    if (preg_match('/^book\.php(?:\?service_id=\d+)?$/', $destination)) {
        return $destination;
    }
    return null;
}

function redirect_by_role(string $role): void {
    $destination = safe_return_destination();
    if ($destination !== null) {
        header('Location: ' . $destination);
    } elseif ($role === 'admin' || $role === 'receptionist') {
        header('Location: admin/dashboard.php');
    } elseif ($role === 'stylist') {
        header('Location: stylist/dashboard.php');
    } else {
        header('Location: customer/index.php');
    }
    exit;
}

if (isset($_SESSION['user_id'])) {
    redirect_by_role($_SESSION['user_role'] ?? '');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    $sessionCsrf = $_SESSION['csrf_token'] ?? '';
    if (!is_string($csrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $ok = false;
            $needsRehash = false;
            if ($user && password_verify($password, $user['password'])) {
                $ok = true;
                $needsRehash = password_needs_rehash($user['password'], PASSWORD_DEFAULT);
            }

            if ($ok) {
                if ($needsRehash) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upStmt->execute([$newHash, (int)$user['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                redirect_by_role($user['role'] ?? '');
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-left">
        <div class="text-center">
            <img src="assets/img/logo.png" class="salon-mark-icon fa-4x mb-4" alt="" aria-hidden="true">
            <h2 class="text-white mb-3" style="font-family: 'Playfair Display', serif;">ELEGANCE SALON</h2>
            <p style="color: var(--cream); font-size: 1.1rem;">Beauty. Relaxation. Confidence.</p>
            <p class="mt-3 small" style="color: var(--champagne);">Where elegance meets exceptional care.</p>
        </div>
    </div>
    <div class="login-right">
        <div class="login-box">
            <h2 style="font-family: 'Playfair Display', serif; color: var(--cream);">Welcome Back</h2>
            <p class="subtitle" style="color: var(--muted);">Sign in to continue your beauty journey</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if (safe_return_destination() !== null): ?>
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars(safe_return_destination(), ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label-elegant">Email Address</label>
                    <input type="email" name="email" class="form-control form-control-elegant" placeholder="Enter your email" required value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label-elegant">Password</label>
                    <input type="password" name="password" class="form-control form-control-elegant" placeholder="Enter your password" required>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember">
                        <label class="form-check-label small" for="remember">Remember me</label>
                    </div>
                    <a href="#" class="small text-gold">Forgot Password?</a>
                </div>
                <button type="submit" class="btn btn-gold w-100 mb-3">
                    <i class="fas fa-lock me-1"></i> Sign In
                </button>
            </form>
            <p class="text-center small mt-3" style="color: var(--muted);">
                New customer?
                <a href="register.php" class="text-gold" style="font-weight:600;">Create Account</a>
            </p>
            <div class="text-center mt-2">
                <a href="index.php" class="small text-sage"><i class="fas fa-arrow-left me-1"></i> Back to Home</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>