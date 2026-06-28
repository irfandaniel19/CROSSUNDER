<?php
// ============================================================
// cart.php – Customer shopping cart management
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

// ── Get this customer's cart ──────────────────────────────────
$stmt = $pdo->prepare("SELECT CART_ID FROM cart WHERE CUST_ID = ?");
$stmt->execute([$cust_id]);
$cartRow = $stmt->fetch();
$cart_id = $cartRow['CART_ID'] ?? null;

// ── Handle POST actions (update qty / remove item) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cart_id) {
    $action      = $_POST['action']      ?? '';
    $footwear_id = (int)($_POST['footwear_id'] ?? 0);

    if ($action === 'update' && $footwear_id > 0) {
        $new_qty = max(1, (int)$_POST['quantity']);
        // Enforce stock limit
        $stmt = $pdo->prepare("SELECT QTY_AVAILABLE FROM stock WHERE FOOTWEAR_ID = ?");
        $stmt->execute([$footwear_id]);
        $stock = $stmt->fetch();
        $new_qty = min($new_qty, $stock['QTY_AVAILABLE'] ?? 1);

        $stmt = $pdo->prepare("UPDATE cart_item SET QUANTITY = ? WHERE CART_ID = ? AND FOOTWEAR_ID = ?");
        $stmt->execute([$new_qty, $cart_id, $footwear_id]);
        $_SESSION['cart_msg'] = ['type' => 'success', 'text' => 'Cart updated.'];
    }

    if ($action === 'remove' && $footwear_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM cart_item WHERE CART_ID = ? AND FOOTWEAR_ID = ?");
        $stmt->execute([$cart_id, $footwear_id]);
        $_SESSION['cart_msg'] = ['type' => 'warning', 'text' => 'Item removed from cart.'];
    }

    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM cart_item WHERE CART_ID = ?");
        $stmt->execute([$cart_id]);
        $_SESSION['cart_msg'] = ['type' => 'info', 'text' => 'Cart cleared.'];
    }

    header("Location: cart.php"); exit;
}

// ── Fetch cart items with product details ─────────────────────
$cartItems = [];
$cartTotal = 0;
if ($cart_id) {
    $stmt = $pdo->prepare("
        SELECT ci.FOOTWEAR_ID, ci.QUANTITY,
               f.FOOTWEAR_NAME, f.FOOTWEAR_BRAND, f.IMAGE_URL,
               f.SIZE, f.COLOR, f.PRICE,
               s.QTY_AVAILABLE
        FROM cart_item ci
        JOIN footwear f ON ci.FOOTWEAR_ID = f.FOOTWEAR_ID
        JOIN stock    s ON ci.FOOTWEAR_ID = s.FOOTWEAR_ID
        WHERE ci.CART_ID = ?
        ORDER BY f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME
    ");
    $stmt->execute([$cart_id]);
    $cartItems = $stmt->fetchAll();
    foreach ($cartItems as $item) {
        $cartTotal += $item['PRICE'] * $item['QUANTITY'];
    }
}

// ── Cart count for navbar ─────────────────────────────────────
$cartCount = 0;
if ($cart_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(QUANTITY), 0) FROM cart_item WHERE CART_ID = ?");
    $stmt->execute([$cart_id]);
    $cartCount = (int)$stmt->fetchColumn();
}

// ── Flash message ─────────────────────────────────────────────
$cartMsg = $_SESSION['cart_msg'] ?? null;
unset($_SESSION['cart_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – My Cart</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }
        .navbar-cu { background: var(--cu-dark) !important; }
        .navbar-brand { color: var(--cu-gold) !important; font-weight: 900; letter-spacing: 4px; font-size: 1.3rem; }
        .btn-cu  { background: var(--cu-gold);  border: none; color: #fff; font-weight: 600; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }
        .cart-badge { font-size: .65rem; }
        .cart-img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; }
        .summary-card { background: var(--cu-dark); color: #fff; border-radius: 12px; padding: 1.5rem; }
        .summary-card .total { font-size: 1.8rem; font-weight: 900; color: var(--cu-gold); }
        .form-control:focus { border-color: var(--cu-gold); box-shadow: 0 0 0 .2rem rgba(200,169,110,.2); }
        .empty-cart { background: #fff; border-radius: 12px; padding: 4rem; text-align: center; color: #bbb; }
    </style>
</head>
<body>

<!-- ─── NAVBAR ───────────────────────────────────────────────── -->
<nav class="navbar navbar-dark navbar-cu navbar-expand-lg sticky-top shadow">
    <div class="container">
        <a class="navbar-brand" href="customer_dashboard.php">CROSSUNDER™</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item">
                    <span class="nav-link text-light"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user_name) ?></span>
                </li>
                <li class="nav-item">
                    <a href="cart.php" class="btn btn-warning btn-sm position-relative">
                        <i class="bi bi-cart3 me-1"></i>Cart
                        <?php if ($cartCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="customer_dashboard.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-shop me-1"></i>Continue Shopping
                    </a>
                </li>
                <li class="nav-item">
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h4 class="fw-bold mb-4" style="letter-spacing:2px; color:var(--cu-dark);">
        <i class="bi bi-cart3 me-2" style="color:var(--cu-gold);"></i>MY SHOPPING CART
    </h4>

    <?php if (empty($cartItems)): ?>
    <!-- Empty cart state -->
    <div class="empty-cart">
        <i class="bi bi-cart-x" style="font-size:4rem;"></i>
        <h5 class="mt-3">Your cart is empty</h5>
        <p>Browse our catalog and add some sneakers!</p>
        <a href="customer_dashboard.php" class="btn btn-cu px-4">
            <i class="bi bi-grid me-2"></i>Shop Now
        </a>
    </div>

    <?php else: ?>
    <div class="row g-4">

        <!-- ─── CART ITEMS TABLE ───────────────────────────── -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead style="background:#f8f9fa;">
                                <tr>
                                    <th class="py-3 ps-3">Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cartItems as $item): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <img src="images/<?= htmlspecialchars($item['IMAGE_URL']) ?>"
                                             class="cart-img"
                                             onerror="this.src='https://placehold.co/80x80/1a1a2e/C8A96E?text=<?= urlencode($item['FOOTWEAR_BRAND']) ?>'">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($item['FOOTWEAR_BRAND']) ?></small><br>
                                            <small class="text-muted">Size: <?= htmlspecialchars($item['SIZE']) ?> | <?= htmlspecialchars($item['COLOR']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>RM <?= number_format($item['PRICE'], 2) ?></td>
                                <!-- Inline quantity update form -->
                                <td>
                                    <form method="POST" class="d-flex align-items-center gap-1">
                                        <input type="hidden" name="action"      value="update">
                                        <input type="hidden" name="footwear_id" value="<?= $item['FOOTWEAR_ID'] ?>">
                                        <input type="number" name="quantity" value="<?= $item['QUANTITY'] ?>"
                                               min="1" max="<?= $item['QTY_AVAILABLE'] ?>"
                                               class="form-control form-control-sm" style="width:65px;">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Update">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </form>
                                </td>
                                <td class="fw-semibold">RM <?= number_format($item['PRICE'] * $item['QUANTITY'], 2) ?></td>
                                <!-- Remove item -->
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="action"      value="remove">
                                        <input type="hidden" name="footwear_id" value="<?= $item['FOOTWEAR_ID'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Remove this item?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <form method="POST">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Clear the entire cart?')">
                            <i class="bi bi-trash3 me-1"></i>Clear Cart
                        </button>
                    </form>
                    <a href="customer_dashboard.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Continue Shopping
                    </a>
                </div>
            </div>
        </div>

        <!-- ─── ORDER SUMMARY SIDEBAR ─────────────────────── -->
        <div class="col-lg-4">
            <div class="summary-card">
                <h6 style="letter-spacing:2px; color:var(--cu-gold);">ORDER SUMMARY</h6>
                <hr style="border-color:rgba(200,169,110,.3);">
                <?php foreach ($cartItems as $item): ?>
                <div class="d-flex justify-content-between small mb-1">
                    <span><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?> × <?= $item['QUANTITY'] ?></span>
                    <span>RM <?= number_format($item['PRICE'] * $item['QUANTITY'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <hr style="border-color:rgba(200,169,110,.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold" style="letter-spacing:1px;">TOTAL</span>
                    <div class="total">RM <?= number_format($cartTotal, 2) ?></div>
                </div>
                <div class="mt-3">
                    <a href="checkout.php" class="btn btn-cu w-100 py-2">
                        <i class="bi bi-bag-check me-2"></i>PROCEED TO CHECKOUT
                    </a>
                </div>
                <small class="d-block text-center mt-2" style="color:rgba(255,255,255,.5); font-size:.75rem;">
                    <i class="bi bi-shield-lock me-1"></i>Secure checkout
                </small>
            </div>
        </div>

    </div>
    <?php endif; ?>
</div>

<!-- Toast feedback -->
<?php if ($cartMsg): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: '<?= $cartMsg['type'] ?>',
        title: '<?= addslashes($cartMsg['text']) ?>',
        toast: true, position: 'top-end', showConfirmButton: false,
        timer: 2000, timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
