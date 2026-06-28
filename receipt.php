<?php
// ============================================================
// receipt.php – Digital receipt after successful checkout
// Accessible by logged-in CUSTOMER for their own transactions
// ============================================================
session_start();
require_once 'dbconn.php';

// ── Access control ────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'CUSTOMER') {
    header("Location: login.php"); exit;
}

$cust_id   = $_SESSION['cust_id'];
$user_name = $_SESSION['user_name'];
$txnNo     = (int)($_GET['txn'] ?? 0);

if ($txnNo === 0) {
    header("Location: customer_dashboard.php"); exit;
}

// ── Fetch transaction (must belong to this customer) ─────────
$stmt = $pdo->prepare("
    SELECT t.*, c.CUST_NAME, c.CUST_NOPHONE
    FROM transaction t
    JOIN customer c ON t.CUST_ID = c.CUST_ID
    WHERE t.TRANSACTION_NO = ? AND t.CUST_ID = ?
");
$stmt->execute([$txnNo, $cust_id]);
$txn = $stmt->fetch();

if (!$txn) {
    // Transaction not found or doesn't belong to this customer
    header("Location: customer_dashboard.php"); exit;
}

// ── Fetch transaction items ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT ti.QUANTITY, ti.FOOTWEAR_ID,
           f.FOOTWEAR_NAME, f.FOOTWEAR_BRAND,
           f.SIZE, f.COLOR, f.PRICE
    FROM transaction_item ti
    JOIN footwear f ON ti.FOOTWEAR_ID = f.FOOTWEAR_ID
    WHERE ti.TRANSACTION_NO = ?
");
$stmt->execute([$txnNo]);
$txnItems = $stmt->fetchAll();

// Clear the last_txn session key
unset($_SESSION['last_txn']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Receipt #<?= $txnNo ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }

        /* ── Print styles ── */
        @media print {
            .no-print   { display: none !important; }
            body        { background: #fff; }
            .receipt-wrapper { box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
        }

        .receipt-wrapper {
            max-width: 680px;
            margin: 2rem auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,.12);
            overflow: hidden;
        }
        .receipt-header {
            background: var(--cu-dark);
            color: #fff;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
        }
        .receipt-header .brand  { color: var(--cu-gold); font-size: 2rem; font-weight: 900; letter-spacing: 6px; }
        .receipt-header .tagline { font-size: .65rem; letter-spacing: 3px; opacity: .6; text-transform: uppercase; }
        .receipt-header .success-icon { font-size: 3rem; color: #2ecc71; margin-bottom: .5rem; }

        .receipt-body { padding: 2rem; }

        .meta-row { display: flex; justify-content: space-between; font-size: .88rem; margin-bottom: .5rem; }
        .meta-row .label { color: #888; }
        .meta-row .value { font-weight: 600; }

        .divider-dashed { border-top: 2px dashed #e0e0e0; margin: 1.2rem 0; }

        .item-table th { font-size: .78rem; letter-spacing: 1px; text-transform: uppercase; color: #888; border-bottom: 2px solid #f0f0f0; padding: .6rem 0; }
        .item-table td { padding: .6rem 0; vertical-align: top; border-bottom: 1px solid #f8f8f8; }

        .total-section { background: var(--cu-dark); border-radius: 10px; padding: 1.2rem 1.5rem; color: #fff; }
        .total-section .total-label { font-size: .8rem; letter-spacing: 2px; color: rgba(255,255,255,.6); }
        .total-section .total-amount { font-size: 2rem; font-weight: 900; color: var(--cu-gold); }

        .receipt-footer { background: #fafafa; padding: 1.5rem 2rem; text-align: center; border-top: 1px solid #f0f0f0; }
        .receipt-footer small { color: #aaa; font-size: .75rem; }

        .status-badge { padding: .35rem .8rem; border-radius: 20px; font-size: .75rem; font-weight: 700; letter-spacing: 1px; }
        .status-paid { background: #d4edda; color: #155724; }
    </style>
</head>
<body>

<!-- ─── ACTIONS BAR (no-print) ───────────────────────────────── -->
<div class="no-print py-3" style="background: var(--cu-dark);">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="customer_dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-shop me-1"></i>Continue Shopping
        </a>
        <span class="text-warning fw-bold" style="letter-spacing:3px; font-size:.85rem;">CROSSUNDER™</span>
        <button onclick="window.print()" class="btn btn-sm" style="background:var(--cu-gold); color:#fff; font-weight:600;">
            <i class="bi bi-printer me-1"></i>Print Receipt
        </button>
    </div>
</div>

<!-- ─── RECEIPT CARD ──────────────────────────────────────────── -->
<div class="container py-4">
<div class="receipt-wrapper">

    <!-- Header -->
    <div class="receipt-header">
        <div class="success-icon"><i class="bi bi-check-circle-fill"></i></div>
        <div class="brand">CROSSUNDER™</div>
        <div class="tagline">Designed For Every Step You Take</div>
        <div class="mt-3">
            <h5 class="mb-0" style="letter-spacing:2px;">PAYMENT RECEIPT</h5>
            <small style="opacity:.6;">Thank you for your purchase!</small>
        </div>
    </div>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Transaction metadata -->
        <div class="meta-row">
            <span class="label">Receipt No.</span>
            <span class="value">#<?= str_pad($txn['TRANSACTION_NO'], 6, '0', STR_PAD_LEFT) ?></span>
        </div>
        <div class="meta-row">
            <span class="label">Date &amp; Time</span>
            <span class="value"><?= date('d M Y, h:i A', strtotime($txn['TRANSACTION_DATE'])) ?></span>
        </div>
        <div class="meta-row">
            <span class="label">Payment Status</span>
            <span><span class="status-badge status-paid"><i class="bi bi-check me-1"></i><?= htmlspecialchars($txn['PAYMENT_STATUS']) ?></span></span>
        </div>
        <div class="meta-row">
            <span class="label">Payment Method</span>
            <span class="value"><?= htmlspecialchars($txn['PAYMENT_METHOD']) ?></span>
        </div>

        <div class="divider-dashed"></div>

        <!-- Customer info -->
        <div class="meta-row">
            <span class="label">Customer Name</span>
            <span class="value"><?= htmlspecialchars($txn['CUST_NAME']) ?></span>
        </div>
        <div class="meta-row">
            <span class="label">Phone</span>
            <span class="value"><?= htmlspecialchars($txn['CUST_NOPHONE']) ?></span>
        </div>
        <div class="meta-row">
            <span class="label">Delivery Address</span>
            <span class="value text-end" style="max-width:60%;"><?= htmlspecialchars($txn['DELIVERY_ADDRESS']) ?></span>
        </div>

        <div class="divider-dashed"></div>

        <!-- Items table -->
        <table class="table item-table mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($txnItems as $item): ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars($item['FOOTWEAR_NAME']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($item['FOOTWEAR_BRAND']) ?> | Size: <?= htmlspecialchars($item['SIZE']) ?> | <?= htmlspecialchars($item['COLOR']) ?></small>
                </td>
                <td class="text-center"><?= $item['QUANTITY'] ?></td>
                <td class="text-end">RM <?= number_format($item['PRICE'], 2) ?></td>
                <td class="text-end fw-semibold">RM <?= number_format($item['PRICE'] * $item['QUANTITY'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="divider-dashed"></div>

        <!-- Total -->
        <div class="total-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="total-label">TOTAL PAID</div>
                    <div class="total-amount">RM <?= number_format($txn['TOTAL_AMOUNT'], 2) ?></div>
                </div>
                <i class="bi bi-bag-check-fill" style="font-size:2.5rem; color:var(--cu-gold); opacity:.5;"></i>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="receipt-footer">
        <small>Thank you for shopping with <strong style="color:var(--cu-gold);">CROSSUNDER™</strong>.<br>
        This is your official digital receipt. Please keep it for your records.<br>
        For inquiries, contact us at support@crossunder.com</small>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
