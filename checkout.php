<?php
// ============================================================
// checkout.php – Checkout flow with database transaction
// Critical: stock deduction uses PDO beginTransaction / commit
// Only accessible by CUSTOMER role
// ============================================================
session_start();
require_once 'dbconn.php';

// ── Access control ────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'CUSTOMER') {
    header("Location: login.php"); exit;
}

$cust_id   = $_SESSION['cust_id'];
$user_name = $_SESSION['user_name'];

// ── Fetch customer's cart ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT CART_ID FROM cart WHERE CUST_ID = ?");
$stmt->execute([$cust_id]);
$cartRow = $stmt->fetch();
$cart_id = $cartRow['CART_ID'] ?? null;

// Fetch cart items (with stock + price info)
$cartItems = [];
$cartTotal = 0;
if ($cart_id) {
    $stmt = $pdo->prepare("
        SELECT ci.FOOTWEAR_ID, ci.QUANTITY,
               f.FOOTWEAR_NAME, f.FOOTWEAR_BRAND,
               f.SIZE, f.COLOR, f.PRICE,
               s.QTY_AVAILABLE
        FROM cart_item ci
        JOIN footwear f ON ci.FOOTWEAR_ID = f.FOOTWEAR_ID
        JOIN stock    s ON ci.FOOTWEAR_ID = s.FOOTWEAR_ID
        WHERE ci.CART_ID = ?
    ");
    $stmt->execute([$cart_id]);
    $cartItems = $stmt->fetchAll();
    foreach ($cartItems as $item) {
        $cartTotal += $item['PRICE'] * $item['QUANTITY'];
    }
}

// Redirect if cart is empty
if (empty($cartItems)) {
    header("Location: cart.php"); exit;
}

$checkoutError = '';

// ── Handle Checkout POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $payment_method   = trim($_POST['payment_method']   ?? '');

    if (empty($delivery_address) || empty($payment_method)) {
        $checkoutError = 'Please provide your delivery address and payment method.';
    } else {
        // ════════════════════════════════════════════════════
        // DATABASE TRANSACTION BLOCK
        // All-or-nothing: if any step fails, everything rolls back
        // ════════════════════════════════════════════════════
        $pdo->beginTransaction();
        try {
            // 1. Re-check stock for EVERY item before committing
            foreach ($cartItems as $item) {
                $stmt = $pdo->prepare("SELECT QTY_AVAILABLE FROM stock WHERE FOOTWEAR_ID = ?");
                $stmt->execute([$item['FOOTWEAR_ID']]);
                $stock = $stmt->fetch();
                if (!$stock || $stock['QTY_AVAILABLE'] < $item['QUANTITY']) {
                    throw new Exception("Sorry — '{$item['FOOTWEAR_NAME']}' does not have enough stock (Available: " . ($stock['QTY_AVAILABLE'] ?? 0) . ", Requested: {$item['QUANTITY']}).");
                }
            }

            // 2. Insert the main TRANSACTION record
            $stmt = $pdo->prepare("
                INSERT INTO transaction
                    (TRANSACTION_DATE, PAYMENT_METHOD, PAYMENT_STATUS, TOTAL_AMOUNT, DELIVERY_ADDRESS, CUST_ID, STAFF_ID)
                VALUES
                    (NOW(), ?, 'Paid', ?, ?, ?, NULL)
            ");
            $stmt->execute([$payment_method, $cartTotal, $delivery_address, $cust_id]);
            $txnNo = $pdo->lastInsertId(); // Capture new TRANSACTION_NO

            // 3. Insert each cart item into TRANSACTION_ITEM + deduct stock
            foreach ($cartItems as $item) {
                // Insert into transaction_item
                $stmt = $pdo->prepare("INSERT INTO transaction_item (TRANSACTION_NO, FOOTWEAR_ID, QUANTITY) VALUES (?, ?, ?)");
                $stmt->execute([$txnNo, $item['FOOTWEAR_ID'], $item['QUANTITY']]);

                // Deduct from stock (atomic: QTY_AVAILABLE cannot go below 0)
                $stmt = $pdo->prepare("UPDATE stock SET QTY_AVAILABLE = QTY_AVAILABLE - ? WHERE FOOTWEAR_ID = ?");
                $stmt->execute([$item['QUANTITY'], $item['FOOTWEAR_ID']]);
            }

            // 4. Clear the customer's cart
            $stmt = $pdo->prepare("DELETE FROM cart_item WHERE CART_ID = ?");
            $stmt->execute([$cart_id]);

            // 5. COMMIT — all steps succeeded
            $pdo->commit();

            // Store transaction number in session and redirect to receipt
            $_SESSION['last_txn'] = $txnNo;
            header("Location: receipt.php?txn=" . $txnNo);
            exit;

        } catch (Exception $e) {
            // ROLLBACK — something failed, undo all changes
            $pdo->rollBack();
            $checkoutError = $e->getMessage();
        }
    }
}

// ── Cart count for navbar ─────────────────────────────────────
$cartCount = count($cartItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Checkout</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }
        .navbar-cu { background: var(--cu-dark) !important; }
        .navbar-brand { color: var(--cu-gold) !important; font-weight: 900; letter-spacing: 4px; font-size: 1.3rem; }
        .btn-cu { background: var(--cu-gold); border: none; color: #fff; font-weight: 700; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }
        .form-control:focus, .form-select:focus { border-color: var(--cu-gold); box-shadow: 0 0 0 .2rem rgba(200,169,110,.2); }
        .summary-box { background: var(--cu-dark); color: #fff; border-radius: 12px; padding: 1.5rem; }
        .total-amt { font-size: 1.8rem; font-weight: 900; color: var(--cu-gold); }
        .step-badge { background: var(--cu-gold); color: #fff; border-radius: 50%; width: 28px; height: 28px; display:inline-flex; align-items:center; justify-content:center; font-size:.85rem; font-weight:700; margin-right:.5rem; flex-shrink:0; }
        .checkout-card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark navbar-cu navbar-expand-lg sticky-top shadow">
    <div class="container">
        <a class="navbar-brand" href="customer_dashboard.php">CROSSUNDER™</a>
        <div class="navbar-nav ms-auto">
            <a href="cart.php" class="btn btn-outline-warning btn-sm me-2">
                <i class="bi bi-cart3 me-1"></i>Cart (<?= $cartCount ?>)
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h4 class="fw-bold mb-4" style="letter-spacing:2px; color:var(--cu-dark);">
        <i class="bi bi-bag-check me-2" style="color:var(--cu-gold);"></i>CHECKOUT
    </h4>

    <div class="row g-4">

        <!-- ─── LEFT: Checkout Form ────────────────────── -->
        <div class="col-lg-7">
            <div class="card checkout-card mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3" style="letter-spacing:1px;">
                        <span class="step-badge">1</span>DELIVERY DETAILS
                    </h6>

                    <form method="POST" id="checkoutForm">
                        <!-- Delivery Address -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Delivery Address <span class="text-danger">*</span></label>
                            <textarea name="delivery_address" class="form-control" rows="3"
                                      placeholder="Enter your full delivery address..."
                                      required><?= htmlspecialchars($_POST['delivery_address'] ?? '') ?></textarea>
                        </div>

                        <hr>
                        <h6 class="fw-bold mb-3" style="letter-spacing:1px;">
                            <span class="step-badge">2</span>PAYMENT METHOD
                        </h6>

                        <!-- Payment Method -->
                        <div class="mb-4">
                            <div class="row g-2">
                                <?php
                                $paymentOptions = [
                                    ['Credit Card', 'bi-credit-card'],
                                    ['Debit Card', 'bi-credit-card-2-back'],
                                    ['Online Banking', 'bi-bank'],
                                    ['E-Wallet', 'bi-wallet2'],
                                    ['Cash on Delivery', 'bi-cash-coin'],
                                ];
                                foreach ($paymentOptions as [$label, $icon]):
                                    $selected = ($_POST['payment_method'] ?? '') === $label;
                                ?>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_method"
                                           id="pm_<?= md5($label) ?>" value="<?= $label ?>"
                                           <?= $selected ? 'checked' : '' ?> required>
                                    <label class="btn btn-outline-secondary w-100 text-start" for="pm_<?= md5($label) ?>">
                                        <i class="bi <?= $icon ?> me-2"></i><?= $label ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <hr>
                        <h6 class="fw-bold mb-3" style="letter-spacing:1px;">
                            <span class="step-badge">3</span>REVIEW &amp; CONFIRM
                        </h6>

                        <!-- Order items mini-summary inside form -->
                        <div class="rounded" style="background:#f8f9fa; padding:1rem; margin-bottom:1.5rem;">
                            <?php foreach ($cartItems as $item): ?>
                            <div class="d-flex justify-content-between small py-1 border-bottom">
                                <span>
                                    <strong><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($item['FOOTWEAR_BRAND']) ?> | Size <?= htmlspecialchars($item['SIZE']) ?> | Qty: <?= $item['QUANTITY'] ?></small>
                                </span>
                                <span class="fw-semibold">RM <?= number_format($item['PRICE'] * $item['QUANTITY'], 2) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="btn btn-cu w-100 py-3" style="font-size:1rem; letter-spacing:2px;">
                            <i class="bi bi-lock me-2"></i>CONFIRM ORDER & PAY
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ─── RIGHT: Order Summary ───────────────────── -->
        <div class="col-lg-5">
            <div class="summary-box">
                <h6 style="letter-spacing:2px; color:var(--cu-gold);">ORDER SUMMARY</h6>
                <hr style="border-color:rgba(200,169,110,.3);">

                <?php foreach ($cartItems as $item): ?>
                <div class="d-flex justify-content-between mb-2 small">
                    <div>
                        <div><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?></div>
                        <small style="color:rgba(255,255,255,.5);"><?= htmlspecialchars($item['FOOTWEAR_BRAND']) ?> × <?= $item['QUANTITY'] ?></small>
                    </div>
                    <span>RM <?= number_format($item['PRICE'] * $item['QUANTITY'], 2) ?></span>
                </div>
                <?php endforeach; ?>

                <hr style="border-color:rgba(200,169,110,.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <span style="letter-spacing:1px; font-weight:600;">TOTAL PAYABLE</span>
                    <div class="total-amt">RM <?= number_format($cartTotal, 2) ?></div>
                </div>

                <div class="mt-3 p-2 rounded" style="background:rgba(255,255,255,.05); font-size:.75rem; color:rgba(255,255,255,.6);">
                    <i class="bi bi-shield-check me-1" style="color:var(--cu-gold);"></i>
                    Stock is deducted automatically upon successful payment. Your order cannot be reversed after confirmation.
                </div>
            </div>

            <div class="mt-3">
                <a href="cart.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-arrow-left me-2"></i>Back to Cart
                </a>
            </div>
        </div>

    </div>
</div>

<!-- SweetAlert for checkout error -->
<?php if ($checkoutError): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'error',
        title: 'Checkout Failed',
        text: '<?= addslashes($checkoutError) ?>',
        confirmButtonColor: '#C8A96E'
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
