<?php
// ============================================================
// profile.php – Customer profile management
// Update personal info (CUST_NAME, CUST_NOPHONE) and password
// ============================================================
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'CUSTOMER') {
    header("Location: login.php"); exit;
}

$cust_id   = $_SESSION['cust_id'];
$username  = $_SESSION['username'];
$user_name = $_SESSION['user_name'];

// ── Fetch current customer record ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.CUST_ID, c.CUST_NAME, c.CUST_NOPHONE, l.USERNAME
    FROM customer c
    JOIN login l ON c.CUST_ID = l.CUST_ID
    WHERE c.CUST_ID = ?
");
$stmt->execute([$cust_id]);
$customer = $stmt->fetch();

// ── Cart count for navbar badge ───────────────────────────────
$cartStmt = $pdo->prepare("
    SELECT COALESCE(SUM(ci.QUANTITY), 0)
    FROM cart c
    JOIN cart_item ci ON c.CART_ID = ci.CART_ID
    WHERE c.CUST_ID = ?
");
$cartStmt->execute([$cust_id]);
$cartCount = (int)$cartStmt->fetchColumn();

$profileError   = '';
$profileSuccess = '';
$passError      = '';
$passSuccess    = '';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update profile details ────────────────────────────────
    if ($action === 'update_profile') {
        $new_name  = trim($_POST['cust_name']    ?? '');
        $new_phone = trim($_POST['cust_nophone'] ?? '');

        if (empty($new_name) || empty($new_phone)) {
            $profileError = 'Full name and phone number are both required.';
        } elseif (!preg_match('/^[0-9]{10,12}$/', $new_phone)) {
            $profileError = 'Phone number must be 10–12 digits with no spaces or dashes.';
        } else {
            // UPDATE customer table (CUST_NAME and CUST_NOPHONE columns)
            $stmt = $pdo->prepare("UPDATE customer SET CUST_NAME = ?, CUST_NOPHONE = ? WHERE CUST_ID = ?");
            $stmt->execute([$new_name, $new_phone, $cust_id]);

            // Sync session display name
            $_SESSION['user_name'] = $new_name;
            $user_name             = $new_name;
            $profileSuccess        = 'Your profile has been updated successfully!';

            // Re-fetch fresh data
            $stmt = $pdo->prepare("SELECT c.CUST_ID, c.CUST_NAME, c.CUST_NOPHONE, l.USERNAME FROM customer c JOIN login l ON c.CUST_ID = l.CUST_ID WHERE c.CUST_ID = ?");
            $stmt->execute([$cust_id]);
            $customer = $stmt->fetch();
        }
    }

    // ── Change password ───────────────────────────────────────
    if ($action === 'change_password') {
        $current_pass = $_POST['current_password']  ?? '';
        $new_pass     = $_POST['new_password']      ?? '';
        $confirm_pass = $_POST['confirm_password']  ?? '';

        // Fetch stored password from login table
        $stmt = $pdo->prepare("SELECT PASSWORD FROM login WHERE USERNAME = ?");
        $stmt->execute([$username]);
        $loginRow = $stmt->fetch();

        // Verify current password (hashed or legacy plain-text)
        $currentValid = password_verify($current_pass, $loginRow['PASSWORD'])
                     || ($current_pass === $loginRow['PASSWORD']);

        if (!$currentValid) {
            $passError = 'Your current password is incorrect.';
        } elseif (strlen($new_pass) < 6) {
            $passError = 'New password must be at least 6 characters.';
        } elseif ($new_pass !== $confirm_pass) {
            $passError = 'New passwords do not match. Please re-enter.';
        } elseif ($new_pass === $current_pass) {
            $passError = 'New password must be different from your current password.';
        } else {
            // Hash and store new password
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("UPDATE login SET PASSWORD = ? WHERE USERNAME = ?");
            $stmt->execute([$hashed, $username]);
            $passSuccess = 'Password changed successfully! Please use your new password next time you log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – My Profile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }
        .navbar-cu { background: var(--cu-dark) !important; }
        .navbar-brand { color: var(--cu-gold) !important; font-weight: 900; letter-spacing: 4px; font-size: 1.3rem; }
        .btn-cu { background: var(--cu-gold); border: none; color: #fff; font-weight: 600; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }
        .form-control:focus { border-color: var(--cu-gold); box-shadow: 0 0 0 .2rem rgba(200,169,110,.2); }
        .cu-card { border: none; border-radius: 14px; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
        .cu-card .card-header { border-radius: 14px 14px 0 0; padding: 1.2rem 1.5rem; border-bottom: 2px solid var(--cu-gold); }
        .avatar-circle {
            width: 80px; height: 80px; border-radius: 50%;
            background: var(--cu-dark); color: var(--cu-gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; font-weight: 900; letter-spacing: 1px;
            flex-shrink: 0;
        }
        .info-row { display: flex; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid #f0f0f0; font-size: .9rem; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #888; font-weight: 500; }
        .info-value { font-weight: 600; color: var(--cu-dark); }
        .input-group-text { background: #f8f9fa; border-right: none; }
        .input-group .form-control { border-left: none; }
    </style>
</head>
<body>

<!-- ─── NAVBAR ───────────────────────────────────────────────── -->
<nav class="navbar navbar-dark navbar-cu navbar-expand-lg sticky-top shadow">
    <div class="container">
        <a class="navbar-brand" href="customer_dashboard.php">CROSSUNDER™</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link text-light"><i class="bi bi-grid me-1"></i>Shop</a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link text-light"><i class="bi bi-bag me-1"></i>My Orders</a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link" style="color:var(--cu-gold);font-weight:600;"><i class="bi bi-person-circle me-1"></i>Profile</a></li>
                <li class="nav-item">
                    <a href="cart.php" class="btn btn-outline-warning btn-sm position-relative">
                        <i class="bi bi-cart3 me-1"></i>Cart
                        <?php if ($cartCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item"><a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width:860px;">

    <!-- Page heading -->
    <h4 class="fw-bold mb-4" style="letter-spacing:2px; color:var(--cu-dark);">
        <i class="bi bi-person-circle me-2" style="color:var(--cu-gold);"></i>MY PROFILE
    </h4>

    <!-- ── Current Profile Summary Card ─── -->
    <div class="cu-card card mb-4">
        <div class="card-header bg-white">
            <span class="fw-bold" style="letter-spacing:1px; color:var(--cu-dark);">
                <i class="bi bi-id-card me-2" style="color:var(--cu-gold);"></i>ACCOUNT OVERVIEW
            </span>
        </div>
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-4 mb-4">
                <div class="avatar-circle">
                    <?= strtoupper(substr($customer['CUST_NAME'], 0, 1)) ?>
                </div>
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($customer['CUST_NAME']) ?></h5>
                    <small class="text-muted"><i class="bi bi-at me-1"></i><?= htmlspecialchars($customer['USERNAME']) ?></small><br>
                    <span class="badge mt-1" style="background:var(--cu-dark); color:var(--cu-gold); letter-spacing:1px;">CUSTOMER</span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label"><i class="bi bi-person me-2"></i>Full Name</span>
                    <span class="info-value"><?= htmlspecialchars($customer['CUST_NAME']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="bi bi-telephone me-2"></i>Phone Number</span>
                    <span class="info-value"><?= htmlspecialchars($customer['CUST_NOPHONE']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="bi bi-person-badge me-2"></i>Username</span>
                    <span class="info-value"><code><?= htmlspecialchars($customer['USERNAME']) ?></code></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="bi bi-shield-check me-2"></i>Account ID</span>
                    <span class="info-value text-muted">#<?= str_pad($customer['CUST_ID'], 4, '0', STR_PAD_LEFT) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── Edit Profile Card ─── -->
        <div class="col-md-6">
            <div class="cu-card card h-100">
                <div class="card-header bg-white">
                    <span class="fw-bold" style="letter-spacing:1px; color:var(--cu-dark);">
                        <i class="bi bi-pencil-square me-2" style="color:var(--cu-gold);"></i>EDIT PROFILE
                    </span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" novalidate>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person text-muted"></i></span>
                                <input type="text" name="cust_name" class="form-control"
                                       value="<?= htmlspecialchars($customer['CUST_NAME']) ?>"
                                       placeholder="Your full name" required>
                            </div>
                            <small class="text-muted">Updates: <code>CUST_NAME</code></small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone text-muted"></i></span>
                                <input type="text" name="cust_nophone" class="form-control"
                                       value="<?= htmlspecialchars($customer['CUST_NOPHONE']) ?>"
                                       placeholder="10–12 digits, no spaces" required>
                            </div>
                            <small class="text-muted">Updates: <code>CUST_NOPHONE</code></small>
                        </div>

                        <div class="p-2 rounded mb-3" style="background:#f8f9fa; border:1px dashed #ddd; font-size:.8rem; color:#888;">
                            <i class="bi bi-info-circle me-1"></i>
                            Username (<code><?= htmlspecialchars($customer['USERNAME']) ?></code>) cannot be changed.
                        </div>

                        <button type="submit" class="btn btn-cu w-100">
                            <i class="bi bi-check-circle me-2"></i>Save Profile Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Change Password Card ─── -->
        <div class="col-md-6">
            <div class="cu-card card h-100">
                <div class="card-header bg-white">
                    <span class="fw-bold" style="letter-spacing:1px; color:var(--cu-dark);">
                        <i class="bi bi-shield-lock me-2" style="color:var(--cu-gold);"></i>CHANGE PASSWORD
                    </span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" novalidate>
                        <input type="hidden" name="action" value="change_password">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
                                <input type="password" name="current_password" id="pw_current" class="form-control" placeholder="Enter current password" required>
                                <button type="button" class="btn btn-outline-secondary border-start-0" onclick="togglePw('pw_current','eye_c')">
                                    <i class="bi bi-eye" id="eye_c"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                                <input type="password" name="new_password" id="pw_new" class="form-control" placeholder="Minimum 6 characters" required>
                                <button type="button" class="btn btn-outline-secondary border-start-0" onclick="togglePw('pw_new','eye_n')">
                                    <i class="bi bi-eye" id="eye_n"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                                <input type="password" name="confirm_password" id="pw_conf" class="form-control" placeholder="Re-enter new password" required>
                                <button type="button" class="btn btn-outline-secondary border-start-0" onclick="togglePw('pw_conf','eye_cf')">
                                    <i class="bi bi-eye" id="eye_cf"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-cu w-100">
                            <i class="bi bi-key me-2"></i>Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick links -->
    <div class="mt-4 d-flex gap-2 flex-wrap">
        <a href="customer_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-grid me-1"></i>Back to Shop
        </a>
        <a href="order_history.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-bag me-1"></i>View My Orders
        </a>
    </div>

</div>

<!-- SweetAlert2 feedback popups -->
<?php if ($profileError): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'error',title:'Update Failed',text:'<?= addslashes($profileError) ?>',confirmButtonColor:'#C8A96E'}));</script>
<?php endif; ?>
<?php if ($profileSuccess): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'success',title:'Profile Updated!',text:'<?= addslashes($profileSuccess) ?>',toast:true,position:'top-end',showConfirmButton:false,timer:3000,timerProgressBar:true}));</script>
<?php endif; ?>
<?php if ($passError): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'error',title:'Password Error',text:'<?= addslashes($passError) ?>',confirmButtonColor:'#C8A96E'}));</script>
<?php endif; ?>
<?php if ($passSuccess): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'success',title:'Password Changed!',text:'<?= addslashes($passSuccess) ?>',toast:true,position:'top-end',showConfirmButton:false,timer:3500,timerProgressBar:true}));</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    inp.type  = (inp.type === 'password') ? 'text' : 'password';
    ico.className = (inp.type === 'text') ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>