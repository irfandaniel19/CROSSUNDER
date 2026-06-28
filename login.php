<?php
// ============================================================
// login.php – Unified login for CUSTOMER, STAFF, and ADMIN
// Routes to correct dashboard based on role
// ============================================================
session_start();
require_once 'dbconn.php';

// If already logged in, redirect to correct dashboard
if (isset($_SESSION['role'])) {
    $map = ['CUSTOMER' => 'customer_dashboard.php', 'STAFF' => 'staff_dashboard.php', 'ADMIN' => 'admin_dashboard.php'];
    header("Location: " . ($map[$_SESSION['role']] ?? 'login.php'));
    exit;
}

$error = '';

// ── Handle POST login submission ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Fetch user record with joined customer/staff name
        $stmt = $pdo->prepare("
            SELECT l.USERNAME, l.PASSWORD, l.ROLE, l.CUST_ID, l.STAFF_ID,
                   c.CUST_NAME, s.STAFF_NAME
            FROM login l
            LEFT JOIN customer c ON l.CUST_ID  = c.CUST_ID
            LEFT JOIN staff    s ON l.STAFF_ID = s.STAFF_ID
            WHERE l.USERNAME = ?
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // Support both password_hash() (new registrations) and plain-text (demo seed data)
            $valid = password_verify($password, $user['PASSWORD'])
                  || ($password === $user['PASSWORD']);

            if ($valid) {
                // ── Set session variables ────────────────────
                $_SESSION['username']  = $user['USERNAME'];
                $_SESSION['role']      = $user['ROLE'];
                $_SESSION['cust_id']   = $user['CUST_ID'];
                $_SESSION['staff_id']  = $user['STAFF_ID'];
                $_SESSION['user_name'] = $user['CUST_NAME'] ?? $user['STAFF_NAME'] ?? $user['USERNAME'];

                // ── Redirect by role ─────────────────────────
                $map = ['CUSTOMER' => 'customer_dashboard.php', 'STAFF' => 'staff_dashboard.php', 'ADMIN' => 'admin_dashboard.php'];
                header("Location: " . ($map[$user['ROLE']] ?? 'login.php'));
                exit;
            } else {
                $error = 'Incorrect password. Please try again.';
            }
        } else {
            $error = 'Username not found. Please check your credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body {
            background: linear-gradient(135deg, #0f0f1a 0%, #1a1a30 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .login-wrapper { width: 100%; max-width: 440px; padding: 1rem; }
        .brand-header {
            background: var(--cu-dark);
            color: var(--cu-gold);
            text-align: center;
            padding: 2.5rem 2rem 2rem;
            border-radius: 12px 12px 0 0;
            border: 1px solid rgba(200,169,110,.3);
            border-bottom: none;
        }
        .brand-header h1 { font-weight: 900; letter-spacing: 6px; font-size: 2.2rem; margin: 0; }
        .brand-header p  { font-size: .65rem; letter-spacing: 3px; margin: .4rem 0 0; opacity: .6; text-transform: uppercase; }
        .login-body {
            background: #fff;
            padding: 2rem 2.5rem 2.5rem;
            border-radius: 0 0 12px 12px;
            border: 1px solid rgba(200,169,110,.3);
            border-top: 3px solid var(--cu-gold);
            box-shadow: 0 25px 60px rgba(0,0,0,.5);
        }
        .btn-cu { background: var(--cu-gold); border: none; color: #fff; font-weight: 700; letter-spacing: 1px; padding: .75rem; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }
        .form-control:focus { border-color: var(--cu-gold); box-shadow: 0 0 0 .2rem rgba(200,169,110,.2); }
        .input-group-text { background: #f8f9fa; border-right: none; }
        .input-group .form-control { border-left: none; }
        .divider { border-color: #e0e0e0; }
        a { color: var(--cu-gold); text-decoration: none; }
        a:hover { color: var(--cu-gold-hover); text-decoration: underline; }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="brand-header">
        <h1>CROSSUNDER™</h1>
        <p>Designed For Every Step You Take</p>
    </div>
    <div class="login-body">
        <h6 class="text-center text-muted mb-4" style="letter-spacing:1px;">SIGN IN TO YOUR ACCOUNT</h6>

        <form method="POST" novalidate>
            <!-- Username field -->
            <div class="mb-3">
                <label class="form-label fw-semibold small">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-fill text-muted"></i></span>
                    <input type="text" name="username" class="form-control"
                           placeholder="Enter your username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                </div>
            </div>

            <!-- Password field -->
            <div class="mb-4">
                <label class="form-label fw-semibold small">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                    <input type="password" name="password" id="passwordInput" class="form-control"
                           placeholder="Enter your password" required>
                    <button type="button" class="btn btn-outline-secondary border-start-0"
                            onclick="togglePass()"><i class="bi bi-eye" id="eyeIcon"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-cu w-100 rounded-pill">
                <i class="bi bi-box-arrow-in-right me-2"></i>LOGIN
            </button>
        </form>

        <hr class="divider my-3">
        <div class="text-center">
            <small class="text-muted">New customer?
                <a href="register.php">Create an account</a>
            </small>
        </div>
    </div>
</div>

<?php if ($error): ?>
<!-- SweetAlert2 popup for login errors -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Login Failed',
        text: '<?= addslashes($error) ?>',
        confirmButtonColor: '#C8A96E',
        confirmButtonText: 'Try Again',
        background: '#fff',
        customClass: { confirmButton: 'px-4' }
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass() {
    const inp = document.getElementById('passwordInput');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        ico.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>