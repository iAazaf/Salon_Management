<?php
// Database Configuration for Elegance Salon

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Karachi');
} else {
    date_default_timezone_set('Asia/Karachi');
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'elegance_salon');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // For development - show error. In production, log it.
    die("Database connection failed: " . $e->getMessage());
}
?>
