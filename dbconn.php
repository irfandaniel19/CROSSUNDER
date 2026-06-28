<?php
// ============================================================
// config/db.php – CROSSUNDER Online System
// CSC264 – Introduction to Web and Mobile Application
// Secure PDO database connection
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'footweardb');    // Change if your DB name differs
define('DB_USER', 'root');           // Default XAMPP user
define('DB_PASS', '');               // Default XAMPP password (blank)

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Fetch as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                     // Use real prepared statements
        ]
    );
} catch (PDOException $e) {
    // Friendly error message – never expose raw PDO errors in production
    die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
            <h2>⚠️ Database Connection Error</h2>
            <p>Could not connect to the database. Please check your <code>config/db.php</code> settings.</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
         </div>');
}
?>
