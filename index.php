<?php
// ============================================================
// index.php – Entry point: redirect based on session role
// ============================================================
session_start();

if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'CUSTOMER': header("Location: customer_dashboard.php"); exit;
        case 'STAFF':    header("Location: staff_dashboard.php");    exit;
        case 'ADMIN':    header("Location: admin_dashboard.php");    exit;
    }
}

// Not logged in → go to login
header("Location: login.php");
exit;
?>
