<?php
// ============================================================
// config/db.php – CROSSUNDER Online System
// Secure Hybrid PDO database connection (Local + Cloud Ready)
// ============================================================

// If environment variables exist (Render), use them. Otherwise, fall back to XAMPP.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'footweardb');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         
            PDO::ATTR_EMULATE_PREPARES   => false,                     
        ]
    );
} catch (PDOException $e) {
    die('<div style=\"font-family:sans-serif;padding:40px;text-align:center;\">
            <h2>⚠️ Database Connection Error</h2>
            <p>Could not connect to the database.</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
         </div>');
}
?>