<?php
require_once 'includes/auth_check.php';
require_once '../config/database.php';
$adminPage = 'feedback';
$pageTitle = 'Feedback';
$feedbacks = $pdo->query("SELECT * FROM feedback ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | Elegance Salon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>
        <div class="topbar">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-gold btn-sm d-lg-none me-2" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="mb-0">Client Feedback</h5>
            </div>
        </div>
        <div class="content-area ms-5">
            <div class="card-elegant">
                <div class="table-responsive">
                    <table class="table table-elegant mb-0 m">
                        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Rating</th><th>Message</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php if (empty($feedbacks)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No client feedback submitted yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($feedbacks as $i => $f): ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td><strong class="text-white"><?php echo htmlspecialchars($f['client_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($f['email'] ?? '—'); ?></td>
                                    <td>
                                        <span class="text-gold" style="font-size: 1.1rem; letter-spacing: 2px;">
                                            <?php echo str_repeat('★', (int)$f['rating']); ?>
                                        </span>
                                        <span class="text-muted" style="font-size: 1.1rem; letter-spacing: 2px;">
                                            <?php echo str_repeat('☆', max(0, 5 - (int)$f['rating'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($f['message']); ?></td>
                                    <td><small class="text-muted"><?php echo date('d M Y', strtotime($f['created_at'])); ?></small></td>
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
</body>
</html>
