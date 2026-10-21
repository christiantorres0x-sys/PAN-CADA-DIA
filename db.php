<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check config.php and make sure MySQL is running.');
}

// One-time upgrade for existing databases: beneficiary status becomes Active / Inactive only.
if (empty($_SESSION['status_tracking_ok']) && status_tracking_ensure($pdo)) {
    $_SESSION['status_tracking_ok'] = true;
}
if (empty($_SESSION['ben_status_ok']) && beneficiaries_ensure_active_inactive($pdo)) {
    $_SESSION['ben_status_ok'] = true;
}
