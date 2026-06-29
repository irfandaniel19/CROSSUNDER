<?php
// Secure PDO database connection - Cloud Ready
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');          // ADDED: Port support
define('DB_NAME', getenv('DB_NAME') ?: 'footweardb');    
define('DB_USER', getenv('DB_USER') ?: 'root');           
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');               

try {
    // ADDED: ;port=" . DB_PORT . " inside the connection string
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // If your cloud host strictly requires SSL, uncomment the line below:
            // PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, 
        ]
    );
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
            <h2>⚠️ Database Connection Error</h2>
            <p>Could not connect to the database. Please check your environment configurations.</p>
            <small>' . htmlspecialchars($e->getMessage()) . '</small>
         </div>');
}
?>
