<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = $_SESSION['user_role'] ?? '';

// Stylists have their own dedicated dashboard
if ($role === 'stylist') {
    header('Location: ../stylist/dashboard.php');
    exit;
}

// Customers cannot open admin panel
if (!in_array($role, ['admin', 'receptionist'], true)) {
    header('Location: ../customer/index.php');
    exit;
}
?>
