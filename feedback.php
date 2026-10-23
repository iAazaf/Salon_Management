<?php
$pageTitle = "Feedback";
$activePage = "feedback";
require_once 'config/database.php';
require_once 'includes/header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Security validation failed (CSRF token mismatch). Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rating = (int)($_POST['rating'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (empty($name) || empty($message) || $rating < 1 || $rating > 5) {
            $error = 'Please fill all required fields and select a rating.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO feedback (client_name, email, rating, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $rating, $message]);
            $success = 'Thank you for your valuable feedback!';
        }
    }
}
?>

<section class="py-5">
    <div class="container">
        <div class="section-title">
            <h2>Share Your Feedback</h2>
            <div class="divider"></div>
            <p>Your opinion helps us improve</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-7">
                <?php if ($success): ?>
                    <div class="alert alert-success text-center"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger text-center"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="card-elegant">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <label class="form-label-elegant">Your Name *</label>
                            <input type="text" name="name" class="form-control form-control-elegant" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-elegant">Email</label>
                            <input type="email" name="email" class="form-control form-control-elegant">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-elegant">Rating *</label>
                            <select name="rating" class="form-control form-control-elegant" required>
                                <option value="">Select rating</option>
                                <option value="5">★★★★★ Excellent</option>
                                <option value="4">★★★★☆ Very Good</option>
                                <option value="3">★★★☆☆ Good</option>
                                <option value="2">★★☆☆☆ Fair</option>
                                <option value="1">★☆☆☆☆ Poor</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label-elegant">Your Feedback *</label>
                            <textarea name="message" class="form-control form-control-elegant" rows="4" required placeholder="Tell us about your experience..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-gold w-100">Submit Feedback</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
