<?php
// ============================================================
// order_history.php – Customer's full order history
// Shows all past transactions with items and receipt links
// ============================================================
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'CUSTOMER') {
    header("Location: login.php"); exit;
}

$cust_id   = $_SESSION['cust_id'];
$user_name = $_SESSION['user_name'];

// ── Cart count ────────────────────────────────────────────────
$cartStmt = $pdo->prepare("SELECT COALESCE(SUM(ci.QUANTITY),0) FROM cart c JOIN cart_item ci ON c.CART_ID = ci.CART_ID WHERE c.CUST_ID = ?");
$cartStmt->execute([$cust_id]);
$cartCount = (int)$cartStmt->fetchColumn();

// ── Pagination ────────────────────────────────────────────────
$perPage = 8;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM transaction WHERE CUST_ID = ?");
$countStmt->execute([$cust_id]);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages  = max(1, ceil($totalOrders / $perPage));

// ── Fetch transactions (newest first) ────────────────────────
$stmt = $pdo->prepare("
    SELECT TRANSACTION_NO, TRANSACTION_DATE, PAYMENT_METHOD,
           PAYMENT_STATUS, TOTAL_AMOUNT, DELIVERY_ADDRESS
    FROM transaction
    WHERE CUST_ID = ?
    ORDER BY TRANSACTION_DATE DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$cust_id]);
$transactions = $stmt->fetchAll();

// ── For each transaction, fetch its items ────────────────────
$txnItems = [];
foreach ($transactions as $t) {
    $iStmt = $pdo->prepare("
        SELECT ti.QUANTITY, f.FOOTWEAR_NAME, f.FOOTWEAR_BRAND,
               f.SIZE, f.COLOR, f.PRICE, f.IMAGE_URL
        FROM transaction_item ti
        JOIN footwear f ON ti.FOOTWEAR_ID = f.FOOTWEAR_ID
        WHERE ti.TRANSACTION_NO = ?
    ");
    $iStmt->execute([$t['TRANSACTION_NO']]);
    $txnItems[$t['TRANSACTION_NO']] = $iStmt->fetchAll();
}

$statusColors = ['Paid'=>'primary','Processing'=>'warning','Shipped'=>'info','Delivered'=>'success','Cancelled'=>'danger'];
$statusIcons  = ['Paid'=>'bi-credit-card','Processing'=>'bi-hourglass-split','Shipped'=>'bi-truck','Delivered'=>'bi-check-circle','Cancelled'=>'bi-x-circle'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – My Orders</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }
        .navbar-cu  { background: var(--cu-dark) !important; }
        .navbar-brand { color: var(--cu-gold) !important; font-weight: 900; letter-spacing: 4px; font-size: 1.3rem; }
        .btn-cu { background: var(--cu-gold); border: none; color: #fff; font-weight: 600; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }
        .order-card { border: none; border-radius: 14px; box-shadow: 0 2px 14px rgba(0,0,0,.08); margin-bottom: 1.2rem; overflow: hidden; }
        .order-header { padding: 1rem 1.5rem; background: var(--cu-dark); color: #fff; }
        .order-header .order-num  { color: var(--cu-gold); font-weight: 900; font-size: 1rem; letter-spacing: 1px; }
        .order-header .order-date { font-size: .78rem; opacity: .6; }
        .order-body { padding: 1.2rem 1.5rem; background: #fff; }
        .item-thumb { width: 56px; height: 56px; object-fit: cover; border-radius: 8px; border: 1px solid #f0f0f0; }
        .total-tag { font-size: 1.2rem; font-weight: 900; color: var(--cu-gold); }
        .page-link { color: var(--cu-dark); }
        .page-item.active .page-link { background: var(--cu-gold); border-color: var(--cu-gold); color: #fff; }
        .empty-state { text-align: center; padding: 4rem 1rem; color: #bbb; }
    </style>
</head>
<body>
<!-- NAVBAR -->
<nav class="navbar navbar-dark navbar-cu navbar-expand-lg sticky-top shadow">
    <div class="container">
        <a class="navbar-brand" href="customer_dashboard.php">CROSSUNDER™</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link text-light"><i class="bi bi-grid me-1"></i>Shop</a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link" style="color:var(--cu-gold);font-weight:600;"><i class="bi bi-bag me-1"></i>My Orders</a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link text-light"><i class="bi bi-person-circle me-1"></i>Profile</a></li>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0" style="letter-spacing:2px; color:var(--cu-dark);">
                <i class="bi bi-bag me-2" style="color:var(--cu-gold);"></i>MY ORDERS
            </h4>
            <small class="text-muted"><?= $totalOrders ?> order(s) found</small>
        </div>
        <a href="customer_dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-shop me-1"></i>Continue Shopping
        </a>
    </div>

    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <i class="bi bi-bag-x" style="font-size:4rem;"></i>
        <h5 class="mt-3">No orders yet</h5>
        <p>You haven't placed any orders. Start shopping now!</p>
        <a href="customer_dashboard.php" class="btn btn-cu px-4 mt-2">
            <i class="bi bi-grid me-2"></i>Browse Products
        </a>
    </div>
    <?php else: ?>

    <?php foreach ($transactions as $t): ?>
    <div class="order-card">
        <!-- Order Header -->
        <div class="order-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="order-num"><i class="bi bi-receipt me-2"></i>ORDER #<?= str_pad($t['TRANSACTION_NO'], 6, '0', STR_PAD_LEFT) ?></div>
                <div class="order-date"><i class="bi bi-calendar3 me-1"></i><?= date('d F Y, h:i A', strtotime($t['TRANSACTION_DATE'])) ?></div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-<?= $statusColors[$t['PAYMENT_STATUS']] ?? 'secondary' ?> px-3 py-2" style="font-size:.8rem;">
                    <i class="<?= $statusIcons[$t['PAYMENT_STATUS']] ?? 'bi-circle' ?> me-1"></i>
                    <?= htmlspecialchars($t['PAYMENT_STATUS']) ?>
                </span>
                <a href="receipt.php?txn=<?= $t['TRANSACTION_NO'] ?>" class="btn btn-sm btn-cu" target="_blank">
                    <i class="bi bi-printer me-1"></i>Receipt
                </a>
            </div>
        </div>

        <!-- Order Body: Items + Summary -->
        <div class="order-body">
            <div class="row g-0">
                <!-- Items list -->
                <div class="col-md-8 pe-md-4">
                    <?php foreach ($txnItems[$t['TRANSACTION_NO']] as $item): ?>
                    <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                        <img src="images/<?= htmlspecialchars($item['IMAGE_URL']) ?>"
                             class="item-thumb"
                             onerror="this.src='https://placehold.co/56x56/0f0f1a/C8A96E?text=<?= urlencode($item['FOOTWEAR_BRAND'][0]) ?>'">
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?></div>
                            <div class="text-muted" style="font-size:.78rem;">
                                <?= htmlspecialchars($item['FOOTWEAR_BRAND']) ?> &nbsp;|&nbsp;
                                Size <?= htmlspecialchars($item['SIZE']) ?> &nbsp;|&nbsp;
                                <?= htmlspecialchars($item['COLOR']) ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small fw-semibold">RM <?= number_format($item['PRICE'],2) ?></div>
                            <div class="text-muted" style="font-size:.75rem;">Qty: <?= $item['QUANTITY'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Order summary -->
                <div class="col-md-4 ps-md-4 mt-3 mt-md-0 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Payment</span>
                            <span><?= htmlspecialchars($t['PAYMENT_METHOD']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Deliver to</span>
                            <span class="text-end" style="max-width:60%;"><?= htmlspecialchars($t['DELIVERY_ADDRESS']) ?></span>
                        </div>
                    </div>
                    <div class="mt-3 p-3 rounded" style="background:#0f0f1a;">
                        <div class="text-muted" style="font-size:.7rem; letter-spacing:1px; color:rgba(255,255,255,.5)!important;">TOTAL PAID</div>
                        <div class="total-tag">RM <?= number_format($t['TOTAL_AMOUNT'],2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>">&laquo;</a></li>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <li class="page-item <?= $i==$page?'active':'' ?>">
                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>">&raquo;</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
