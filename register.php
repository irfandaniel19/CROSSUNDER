<?php
// ============================================================
// register.php – Customer self-registration
// Creates a record in both `customer` and `login` tables
// ============================================================
session_start();
require_once 'dbconn.php';

// Redirect if already logged in
if (isset($_SESSION['role'])) {
    header("Location: index.php"); exit;
}

$error   = '';
$success = '';

// ── Handle POST registration ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize inputs (matching DB columns exactly)
    $cust_name    = trim($_POST['cust_name']    ?? '');
    $cust_nophone = trim($_POST['cust_nophone'] ?? '');
    $username     = trim($_POST['username']     ?? '');
    $password     = $_POST['password']          ?? '';
    $confirm_pass = $_POST['confirm_password']  ?? '';

    // ── Validation ───────────────────────────────────────────
    if (empty($cust_name) || empty($cust_nophone) || empty($username) || empty($password) || empty($confirm_pass)) {
        $error = 'All fields are required. Please fill in the complete form.';
    } elseif (strlen($username) < 4) {
        $error = 'Username must be at least 4 characters long.';
    } elseif (!preg_match('/^[0-9]{10,12}$/', $cust_nophone)) {
        $error = 'Phone number must be 10–12 digits with no spaces or dashes.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_pass) {
        $error = 'Passwords do not match. Please re-enter.';
    } else {
        // Check if username already exists in login table
        $stmt = $pdo->prepare("SELECT USERNAME FROM login WHERE USERNAME = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'That username is already taken. Please choose a different one.';
        } else {
            // ── INSERT into customer table ───────────────────
            $stmt = $pdo->prepare("INSERT INTO customer (CUST_NAME, CUST_NOPHONE) VALUES (?, ?)");
            $stmt->execute([$cust_name, $cust_nophone]);
            $new_cust_id = $pdo->lastInsertId(); // Get the auto-incremented CUST_ID

            // ── Hash the password securely ───────────────────
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // ── INSERT into login table ──────────────────────
            // ROLE = 'CUSTOMER', STAFF_ID = NULL (as per schema rules)
            $stmt = $pdo->prepare("INSERT INTO login (USERNAME, PASSWORD, ROLE, CUST_ID, STAFF_ID) VALUES (?, ?, 'CUSTOMER', ?, NULL)");
            $stmt->execute([$username, $hashed_password, $new_cust_id]);

            // ── Also create a blank cart for the new customer ─
            $stmt = $pdo->prepare("INSERT INTO cart (CUST_ID) VALUES (?)");
            $stmt->execute([$new_cust_id]);

            $success = 'Registration successful! You can now log in with your credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Register</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body {
            background: linear-gradient(135deg, #0f0f1a 0%, #1a1a30 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;
            font-family: 'Segoe UI', sans-serif;
        }
        .reg-wrapper { width: 100%; max-width: 500px; }
        .brand-header {
            background: var(--cu-dark); color: var(--cu-gold); text-align: center;
            padding: 2rem; border-radius: 12px 12px 0 0;
            border: 1px solid rgba(200,169,110,.3); border-bottom: none;
        }
        .brand-header h1 { font-weight: 900; letter-spacing: 6px; font-size: 1.8rem; margin: 0; }
        .brand-header p  { font-size: .65rem; letter-spacing: 3px; margin: .3rem 0 0; opacity: .6; }
        .reg-body {
            background: #fff; padding: 2rem 2.5rem 2.5rem;
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
        .section-label { font-size: .7rem; letter-spacing: 2px; text-transform: uppercase; color: var(--cu-gold); font-weight: 700; margin-bottom: .5rem; }
        a { color: var(--cu-gold); }
        a:hover { color: var(--cu-gold-hover); }
    </style>
</head>
<body>
<div class="reg-wrapper">
    <div class="brand-header">
        <h1>CROSSUNDER™</h1>
        <p>CREATE YOUR ACCOUNT</p>
    </div>
    <div class="reg-body">
        <h6 class="text-center text-muted mb-4" style="letter-spacing:1px;">CUSTOMER REGISTRATION</h6>

        <form method="POST" novalidate>

            <!-- ── Personal Information ── -->
            <p class="section-label"><i class="bi bi-person-vcard me-1"></i>Personal Information</p>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person text-muted"></i></span>
                    <input type="text" name="cust_name" class="form-control"
                           placeholder="e.g. Ahmad bin Kassim"
                           value="<?= htmlspecialchars($_POST['cust_name'] ?? '') ?>" required>
                </div>
                <small class="text-muted">Maps to: <code>CUST_NAME</code> in customer table</small>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold small">Phone Number <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone text-muted"></i></span>
                    <input type="text" name="cust_nophone" class="form-control"
                           placeholder="e.g. 0123456789 (10–12 digits, no spaces)"
                           value="<?= htmlspecialchars($_POST['cust_nophone'] ?? '') ?>" required>
                </div>
                <small class="text-muted">Maps to: <code>CUST_NOPHONE</code> in customer table</small>
            </div>

            <!-- ── Login Credentials ── -->
            <p class="section-label"><i class="bi bi-shield-lock me-1"></i>Login Credentials</p>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-at text-muted"></i></span>
                    <input type="text" name="username" class="form-control"
                           placeholder="Minimum 4 characters"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                <small class="text-muted">Maps to: <code>USERNAME</code> in login table</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" name="password" id="pw1" class="form-control"
                           placeholder="Minimum 6 characters" required>
                    <button type="button" class="btn btn-outline-secondary border-start-0"
                            onclick="togglePass('pw1','eye1')"><i class="bi bi-eye" id="eye1"></i></button>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                    <input type="password" name="confirm_password" id="pw2" class="form-control"
                           placeholder="Re-enter your password" required>
                    <button type="button" class="btn btn-outline-secondary border-start-0"
                            onclick="togglePass('pw2','eye2')"><i class="bi bi-eye" id="eye2"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-cu w-100 rounded-pill">
                <i class="bi bi-person-plus me-2"></i>CREATE ACCOUNT
            </button>
        </form>

        <hr class="my-3">
        <div class="text-center">
            <small class="text-muted">Already have an account? <a href="login.php">Sign in here</a></small>
        </div>
    </div>
</div>

<!-- SweetAlert2 popups for error and success -->
<?php if ($error): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({ icon: 'error', title: 'Registration Failed', text: '<?= addslashes($error) ?>', confirmButtonColor: '#C8A96E' });
});
</script>
<?php endif; ?>

<?php if ($success): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success', title: 'Account Created!',
        text: '<?= addslashes($success) ?>',
        confirmButtonColor: '#C8A96E', confirmButtonText: 'Go to Login'
    }).then(() => { window.location.href = 'login.php'; });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    inp.type = (inp.type === 'password') ? 'text' : 'password';
    ico.className = (inp.type === 'text') ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
