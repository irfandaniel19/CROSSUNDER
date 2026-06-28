<?php
// ============================================================
// staff_dashboard.php – Staff Operational Panel
// Distinct UI from Customer & Admin
// Roles: STAFF or ADMIN (admin can view staff panel too)
// Features: Process Orders | Add Stock | Print Daily Report
// ============================================================
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['STAFF', 'ADMIN'])) {
    header("Location: login.php"); exit;
}

$staff_id  = $_SESSION['staff_id'];
$user_name = $_SESSION['user_name'];
$role      = $_SESSION['role'];

$feedbackMsg  = '';
$feedbackType = 'success';
$activeTab    = $_GET['tab'] ?? 'orders';

// ════════════════════════════════════════════════════════════
// HANDLE POST ACTIONS
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update order status ────────────────────────────────
    if ($action === 'update_status') {
        $txn_no    = (int)$_POST['txn_no'];
        $newStatus = trim($_POST['new_status']);
        $allowed   = ['Paid','Processing','Shipped','Delivered','Cancelled'];
        if (in_array($newStatus, $allowed)) {
            $stmt = $pdo->prepare("UPDATE transaction SET PAYMENT_STATUS = ?, STAFF_ID = COALESCE(STAFF_ID, ?) WHERE TRANSACTION_NO = ?");
            $stmt->execute([$newStatus, $staff_id, $txn_no]);
            $feedbackMsg = 'Order #' . str_pad($txn_no,6,'0',STR_PAD_LEFT) . ' updated to "' . $newStatus . '".';
        }
        $activeTab = 'orders';
    }

    // ── Restock per size (multi-size grid submit) ──────────
    if ($action === 'update_stock_sizes') {
        $fp_brand = trim($_POST['fp_brand'] ?? '');
        $fp_name  = trim($_POST['fp_name']  ?? '');
        $add_qtys = $_POST['add_qty'] ?? []; // [FOOTWEAR_ID => qty_to_add]

        $updated = 0;
        foreach ($add_qtys as $fid => $qty) {
            $qty = max(0, (int)$qty);
            if ($qty > 0) {
                // UPDATE stock — only quantity, nothing else
                $pdo->prepare("UPDATE stock SET QTY_AVAILABLE = QTY_AVAILABLE + ? WHERE FOOTWEAR_ID = ?")
                    ->execute([$qty, (int)$fid]);
                $updated++;
            }
        }

        if ($updated > 0) {
            $feedbackMsg  = "Restocked $updated size(s) of \"$fp_name\" successfully.";
            $feedbackType = 'success';
        } else {
            $feedbackMsg  = 'No quantities were entered. Add at least 1 unit to a size.';
            $feedbackType = 'error';
        }

        // Redirect back to same shoe so staff can verify the updated stock
        $shoe_key = urlencode($fp_brand . '||' . $fp_name);
        header("Location: staff_dashboard.php?tab=stock&selected_shoe={$shoe_key}&msg=" . urlencode($feedbackMsg) . "&type=$feedbackType");
        exit;
    }

    header("Location: staff_dashboard.php?tab=$activeTab&msg=" . urlencode($feedbackMsg) . "&type=$feedbackType");
    exit;
}

if (isset($_GET['msg'])) {
    $feedbackMsg  = urldecode($_GET['msg']);
    $feedbackType = $_GET['type'] ?? 'success';
}

// ════════════════════════════════════════════════════════════
// FETCH DATA
// ════════════════════════════════════════════════════════════

// Orders (paginated)
$perPage     = 12;
$orderPage   = max(1, (int)($_GET['opage'] ?? 1));
$orderOffset = ($orderPage - 1) * $perPage;
$statusFilter = trim($_GET['status'] ?? '');
$searchOrder  = trim($_GET['search'] ?? '');

$where  = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND t.PAYMENT_STATUS = ?"; $params[] = $statusFilter; }
if ($searchOrder)  { $where .= " AND (c.CUST_NAME LIKE ? OR t.TRANSACTION_NO = ?)"; $params[] = "%$searchOrder%"; $params[] = (int)$searchOrder; }

$totalOrderCount = (int)$pdo->prepare("SELECT COUNT(*) FROM transaction t JOIN customer c ON t.CUST_ID = c.CUST_ID $where")->execute($params) ? (function($pdo,$where,$params){ $s=$pdo->prepare("SELECT COUNT(*) FROM transaction t JOIN customer c ON t.CUST_ID=c.CUST_ID $where"); $s->execute($params); return (int)$s->fetchColumn(); })($pdo,$where,$params) : 0;

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM transaction t JOIN customer c ON t.CUST_ID = c.CUST_ID $where");
$cntStmt->execute($params);
$totalOrderCount = (int)$cntStmt->fetchColumn();
$totalOrderPages = max(1, ceil($totalOrderCount / $perPage));

$ordStmt = $pdo->prepare("SELECT t.TRANSACTION_NO, t.TRANSACTION_DATE, t.PAYMENT_METHOD, t.PAYMENT_STATUS, t.TOTAL_AMOUNT, t.DELIVERY_ADDRESS, c.CUST_NAME, c.CUST_NOPHONE, s.STAFF_NAME AS PROCESSED_BY FROM transaction t JOIN customer c ON t.CUST_ID=c.CUST_ID LEFT JOIN staff s ON t.STAFF_ID=s.STAFF_ID $where ORDER BY t.TRANSACTION_DATE DESC LIMIT $perPage OFFSET $orderOffset");
$ordStmt->execute($params);
$orders = $ordStmt->fetchAll();

// Stock list
$stockList = $pdo->query("SELECT f.FOOTWEAR_ID, f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME, f.SIZE, f.COLOR, f.PRICE, s.QTY_AVAILABLE FROM footwear f JOIN stock s ON f.FOOTWEAR_ID=s.FOOTWEAR_ID ORDER BY s.QTY_AVAILABLE ASC, f.FOOTWEAR_BRAND")->fetchAll();

// Products dropdown for add stock form
// Grouped shoe models (distinct brand + name only)
$shoeModels = $pdo->query("SELECT DISTINCT FOOTWEAR_BRAND, FOOTWEAR_NAME FROM footwear ORDER BY FOOTWEAR_BRAND, FOOTWEAR_NAME")->fetchAll();

// Selected shoe model for the restock size grid
$selectedShoe     = $_GET['selected_shoe'] ?? '';
$selectedBrand    = '';
$selectedName     = '';
$selectedVariants = [];
if ($selectedShoe) {
    $parts         = explode('||', urldecode($selectedShoe), 2);
    $selectedBrand = $parts[0] ?? '';
    $selectedName  = $parts[1] ?? '';
    if ($selectedBrand && $selectedName) {
        // Fetch all size variants for this shoe — staff sees size + current stock only
        $varStmt = $pdo->prepare("
            SELECT f.FOOTWEAR_ID, f.SIZE, f.COLOR, s.QTY_AVAILABLE
            FROM footwear f
            JOIN stock s ON f.FOOTWEAR_ID = s.FOOTWEAR_ID
            WHERE f.FOOTWEAR_BRAND = ? AND f.FOOTWEAR_NAME = ?
            ORDER BY CAST(SUBSTRING_INDEX(f.SIZE,' ',-1) AS DECIMAL(5,1))
        ");
        $varStmt->execute([$selectedBrand, $selectedName]);
        $selectedVariants = $varStmt->fetchAll();
    }
}

// Daily Sales Report
$reportDate   = trim($_GET['report_date'] ?? date('Y-m-d'));
$rptStmt      = $pdo->prepare("SELECT t.TRANSACTION_NO, t.TRANSACTION_DATE, t.PAYMENT_METHOD, t.PAYMENT_STATUS, t.TOTAL_AMOUNT, t.DELIVERY_ADDRESS, c.CUST_NAME, c.CUST_NOPHONE FROM transaction t JOIN customer c ON t.CUST_ID=c.CUST_ID WHERE DATE(t.TRANSACTION_DATE)=? ORDER BY t.TRANSACTION_DATE ASC");
$rptStmt->execute([$reportDate]);
$reportTxns = $rptStmt->fetchAll();

$rptSumStmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(TOTAL_AMOUNT),0) as rev, COUNT(DISTINCT CUST_ID) as uniq_cust FROM transaction WHERE DATE(TRANSACTION_DATE)=? AND PAYMENT_STATUS!='Cancelled'");
$rptSumStmt->execute([$reportDate]);
$rptSum = $rptSumStmt->fetch();

$rptItemsStmt = $pdo->prepare("SELECT SUM(ti.QUANTITY) as total_items FROM transaction_item ti JOIN transaction t ON ti.TRANSACTION_NO=t.TRANSACTION_NO WHERE DATE(t.TRANSACTION_DATE)=? AND t.PAYMENT_STATUS!='Cancelled'");
$rptItemsStmt->execute([$reportDate]);
$rptTotalItems = (int)$rptItemsStmt->fetchColumn();

// Top stat numbers
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM transaction WHERE PAYMENT_STATUS IN ('Paid','Processing')")->fetchColumn();
$todayRevenue  = (float)$pdo->query("SELECT COALESCE(SUM(TOTAL_AMOUNT),0) FROM transaction WHERE DATE(TRANSACTION_DATE)=CURDATE() AND PAYMENT_STATUS!='Cancelled'")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM stock WHERE QTY_AVAILABLE<=5")->fetchColumn();
$totalProcessed = (int)$pdo->query("SELECT COUNT(*) FROM transaction WHERE PAYMENT_STATUS IN ('Shipped','Delivered')")->fetchColumn();

$statusColors = ['Paid'=>'primary','Processing'=>'warning','Shipped'=>'info','Delivered'=>'success','Cancelled'=>'danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Staff Panel</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ── STAFF THEME: Navy blue operational interface ── */
        :root {
            --sf-primary : #1e3a5f;
            --sf-accent  : #2980b9;
            --sf-light   : #3498db;
            --sf-success : #27ae60;
            --sf-warn    : #e67e22;
            --sf-danger  : #c0392b;
            --sf-bg      : #eef2f7;
            --sf-card    : #ffffff;
        }
        * { box-sizing: border-box; }
        body { background: var(--sf-bg); font-family: 'Segoe UI', sans-serif; margin: 0; }

        /* ── Top navbar ── */
        .sf-navbar {
            background: var(--sf-primary);
            padding: .9rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky; top:0; z-index:999;
            box-shadow: 0 2px 12px rgba(0,0,0,.25);
        }
        .sf-brand { color: #fff; font-weight: 900; letter-spacing: 4px; font-size: 1.2rem; text-decoration: none; }
        .sf-brand span { color: #3498db; }
        .sf-role-badge { background: var(--sf-accent); color: #fff; padding: .2rem .7rem; border-radius: 20px; font-size: .7rem; letter-spacing: 2px; font-weight: 700; }
        .sf-navbar .btn-outline-light { border-color:rgba(255,255,255,.4); color:#fff; }
        .sf-navbar .btn-outline-light:hover { background:rgba(255,255,255,.1); }

        /* ── Tab bar ── */
        .sf-tabbar {
            background: var(--sf-primary);
            padding: 0 1.5rem;
            border-top: 1px solid rgba(255,255,255,.08);
        }
        .sf-tabbar .nav-link {
            color: rgba(255,255,255,.55);
            padding: .8rem 1.2rem;
            font-size: .85rem;
            font-weight: 600;
            letter-spacing: .5px;
            border-bottom: 3px solid transparent;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .sf-tabbar .nav-link:hover  { color: #fff; }
        .sf-tabbar .nav-link.active { color: var(--sf-light); border-bottom-color: var(--sf-light); }

        /* ── Stat cards ── */
        .sf-stat { background: var(--sf-card); border-radius: 10px; padding: 1.1rem 1.4rem; border-left: 4px solid transparent; }
        .sf-stat .s-num { font-size: 1.9rem; font-weight: 900; line-height: 1; }
        .sf-stat .s-lbl { font-size: .72rem; letter-spacing: 1.5px; text-transform: uppercase; color: #888; margin-top: .3rem; }

        /* ── Cards ── */
        .sf-card { background: var(--sf-card); border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.07); }
        .sf-card .sf-card-header { padding: 1rem 1.4rem; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
        .sf-card-title { font-weight: 800; letter-spacing: 1.5px; font-size: .9rem; color: var(--sf-primary); }

        /* ── Buttons ── */
        .btn-sf { background: var(--sf-accent); border: none; color: #fff; font-weight: 600; }
        .btn-sf:hover { background: var(--sf-primary); color: #fff; }
        .form-control:focus, .form-select:focus { border-color: var(--sf-accent); box-shadow: 0 0 0 .2rem rgba(41,128,185,.2); }

        /* ── Table ── */
        .table th { font-size: .75rem; letter-spacing: 1px; text-transform: uppercase; color: #999; border-bottom: 2px solid #f0f0f0; font-weight: 700; }

        /* ── Stock level indicators ── */
        .stock-empty { color: #c0392b; font-weight: 700; }
        .stock-low   { color: #e67e22; font-weight: 700; }
        .stock-ok    { color: #27ae60; font-weight: 600; }
        tr.row-critical { background: #fff5f5 !important; }
        tr.row-low      { background: #fffcf0 !important; }

        /* ── Tab panels ── */
        .sf-panel { display: none; }
        .sf-panel.active { display: block; animation: sfIn .2s ease; }
        @keyframes sfIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

        /* ── Add Stock Form ── */
        .add-stock-form { background: var(--sf-primary); border-radius: 12px; padding: 1.5rem; color: #fff; }
        .add-stock-form label { color: rgba(255,255,255,.8); font-size: .82rem; font-weight: 600; letter-spacing: .5px; }
        .add-stock-form .form-control, .add-stock-form .form-select { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.2); color: #fff; }
        .add-stock-form .form-control::placeholder { color: rgba(255,255,255,.4); }
        .add-stock-form .form-select option { color: #000; background: #fff; }
        .add-stock-form .form-control:focus, .add-stock-form .form-select:focus { background: rgba(255,255,255,.15); border-color: var(--sf-light); color: #fff; box-shadow: none; }

        /* ── Report print styles ── */
        @media print {
            .no-print { display: none !important; }
            .sf-navbar, .sf-tabbar, .sf-stat-row, .report-controls { display: none !important; }
            body { background: #fff; }
            .report-printable { box-shadow: none !important; border: none !important; }
            .report-header-print { display: block !important; }
        }
        .report-header-print { display: none; text-align: center; margin-bottom: 1.5rem; }

        /* ── Pagination ── */
        .page-link { color: var(--sf-accent); }
        .page-item.active .page-link { background: var(--sf-accent); border-color: var(--sf-accent); }
    </style>
</head>
<body>

<!-- ═══════════ NAVBAR ═══════════════════════════════════════ -->
<div class="sf-navbar no-print">
    <div class="d-flex align-items-center gap-3">
        <a href="staff_dashboard.php" class="sf-brand">CROSS<span>UNDER</span>™</a>
        <span class="sf-role-badge"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($role) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-white-50 small d-none d-md-inline"><i class="bi bi-person me-1"></i><?= htmlspecialchars($user_name) ?></span>
        <?php if ($role === 'ADMIN'): ?>
        <a href="admin_dashboard.php" class="btn btn-sm" style="background:var(--sf-accent);color:#fff;font-weight:600;">
            <i class="bi bi-speedometer2 me-1"></i>Admin Panel
        </a>
        <?php endif; ?>
        <a href="logout.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-box-arrow-right me-1"></i>Logout
        </a>
    </div>
</div>

<!-- ═══════════ TAB BAR ══════════════════════════════════════ -->
<div class="sf-tabbar no-print">
    <ul class="nav">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab==='orders'?'active':'' ?>" href="#" onclick="sfTab('orders')">
                <i class="bi bi-receipt"></i> Orders
                <?php if ($pendingOrders > 0): ?><span class="badge bg-warning text-dark" style="font-size:.6rem;"><?= $pendingOrders ?></span><?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab==='stock'?'active':'' ?>" href="#" onclick="sfTab('stock')">
                <i class="bi bi-box-arrow-in-down"></i> Add Stock
                <?php if ($lowStockCount > 0): ?><span class="badge bg-danger" style="font-size:.6rem;"><?= $lowStockCount ?></span><?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab==='report'?'active':'' ?>" href="#" onclick="sfTab('report')">
                <i class="bi bi-printer"></i> Daily Sales Report
            </a>
        </li>
    </ul>
</div>

<div class="container-fluid px-4 py-3">

    <!-- ── STAT CARDS ────────────────────────────────────────── -->
    <div class="row g-3 mb-3 sf-stat-row no-print">
        <div class="col-6 col-md-3">
            <div class="sf-stat" style="border-color:var(--sf-warn);">
                <div class="s-num" style="color:var(--sf-warn);"><?= $pendingOrders ?></div>
                <div class="s-lbl">Pending Orders</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sf-stat" style="border-color:var(--sf-success);">
                <div class="s-num" style="color:var(--sf-success);">RM <?= number_format($todayRevenue,0) ?></div>
                <div class="s-lbl">Today's Revenue</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sf-stat" style="border-color:<?= $lowStockCount>0 ? 'var(--sf-danger)' : 'var(--sf-success)' ?>;">
                <div class="s-num" style="color:<?= $lowStockCount>0 ? 'var(--sf-danger)' : 'var(--sf-success)' ?>;"><?= $lowStockCount ?></div>
                <div class="s-lbl">Low Stock Items</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sf-stat" style="border-color:var(--sf-accent);">
                <div class="s-num" style="color:var(--sf-accent);"><?= $totalProcessed ?></div>
                <div class="s-lbl">Shipped / Delivered</div>
            </div>
        </div>
    </div>

    <!-- ══════════════ TAB: ORDERS ══════════════════════════ -->
    <div class="sf-panel <?= $activeTab==='orders'?'active':'' ?>" id="sf-orders">
        <div class="sf-card">
            <div class="sf-card-header">
                <span class="sf-card-title"><i class="bi bi-receipt me-2" style="color:var(--sf-accent);"></i>CUSTOMER ORDERS</span>
                <!-- Filter bar -->
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="hidden" name="tab" value="orders">
                    <input type="text" name="search" class="form-control form-control-sm" style="width:160px;"
                           placeholder="Search name or #ID" value="<?= htmlspecialchars($searchOrder) ?>">
                    <select name="status" class="form-select form-select-sm" style="width:140px;">
                        <option value="">All Statuses</option>
                        <?php foreach (array_keys($statusColors) as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sf btn-sm"><i class="bi bi-search"></i></button>
                    <a href="?tab=orders" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Order #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th class="text-center">Update</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">No orders found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $o):
                        $iCount = $pdo->prepare("SELECT COALESCE(SUM(QUANTITY),0) FROM transaction_item WHERE TRANSACTION_NO=?");
                        $iCount->execute([$o['TRANSACTION_NO']]);
                        $itemCount = $iCount->fetchColumn();
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold" style="color:var(--sf-accent);">#<?= str_pad($o['TRANSACTION_NO'],6,'0',STR_PAD_LEFT) ?></td>
                        <td class="small"><?= date('d M y H:i', strtotime($o['TRANSACTION_DATE'])) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($o['CUST_NAME']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($o['CUST_NOPHONE']) ?></td>
                        <td class="small"><span class="badge bg-secondary"><?= $itemCount ?> item(s)</span></td>
                        <td class="fw-bold" style="color:var(--sf-success);">RM <?= number_format($o['TOTAL_AMOUNT'],2) ?></td>
                        <td class="small"><?= htmlspecialchars($o['PAYMENT_METHOD']) ?></td>
                        <td><span class="badge bg-<?= $statusColors[$o['PAYMENT_STATUS']]??'secondary' ?>"><?= htmlspecialchars($o['PAYMENT_STATUS']) ?></span></td>
                        <td>
                            <form method="POST" class="d-flex gap-1 justify-content-center">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="txn_no" value="<?= $o['TRANSACTION_NO'] ?>">
                                <select name="new_status" class="form-select form-select-sm" style="width:115px;">
                                    <?php foreach (array_keys($statusColors) as $s): ?>
                                    <option value="<?= $s ?>" <?= $o['PAYMENT_STATUS']===$s?'selected':'' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sf btn-sm"><i class="bi bi-check-lg"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <?php if ($totalOrderPages > 1): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-muted">Showing <?= (($orderPage-1)*$perPage)+1 ?>–<?= min($orderPage*$perPage, $totalOrderCount) ?> of <?= $totalOrderCount ?> orders</small>
                <nav><ul class="pagination pagination-sm mb-0">
                    <?php if ($orderPage>1): ?><li class="page-item"><a class="page-link" href="?tab=orders&opage=<?= $orderPage-1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($searchOrder) ?>">&laquo;</a></li><?php endif; ?>
                    <?php for ($i=max(1,$orderPage-2); $i<=min($totalOrderPages,$orderPage+2); $i++): ?>
                    <li class="page-item <?= $i==$orderPage?'active':'' ?>"><a class="page-link" href="?tab=orders&opage=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($searchOrder) ?>"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <?php if ($orderPage<$totalOrderPages): ?><li class="page-item"><a class="page-link" href="?tab=orders&opage=<?= $orderPage+1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($searchOrder) ?>">&raquo;</a></li><?php endif; ?>
                </ul></nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════════ TAB: ADD STOCK ═══════════════════════ -->
    <div class="sf-panel <?= $activeTab==='stock'?'active':'' ?>" id="sf-stock">
        <div class="row g-4">

            <!-- Restock Form (multi-size grid) -->
            <div class="col-12">
                <div class="add-stock-form">

                    <!-- Header row -->
                    <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
                        <h6 style="letter-spacing:2px; margin:0;">
                            <i class="bi bi-box-arrow-in-down me-2" style="color:var(--sf-light);"></i>RESTOCK MANAGEMENT
                        </h6>
                        <!-- Staff restriction notice -->
                        <div style="font-size:.71rem; color:rgba(255,255,255,.5); background:rgba(255,255,255,.05); padding:.35rem .75rem; border-radius:20px; border:1px solid rgba(255,255,255,.12); white-space:nowrap;">
                            <i class="bi bi-shield-lock me-1" style="color:var(--sf-light);"></i>
                            Stock quantities only — contact Admin to change product details
                        </div>
                    </div>

                    <!-- STEP 1: Select shoe model (GET form — auto-submits on change) -->
                    <form method="GET" id="shoeSelectForm">
                        <input type="hidden" name="tab" value="stock">
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-9">
                                <label style="color:rgba(255,255,255,.75); font-size:.78rem; font-weight:700; letter-spacing:1px; text-transform:uppercase;">
                                    Step 1 — Select Shoe Model
                                </label>
                                <select name="selected_shoe" class="form-select mt-1"
                                        onchange="document.getElementById('shoeSelectForm').submit()">
                                    <option value="">-- Choose a shoe model to restock --</option>
                                    <?php foreach ($shoeModels as $sm):
                                        $shoeKey   = $sm['FOOTWEAR_BRAND'] . '||' . $sm['FOOTWEAR_NAME'];
                                        $isSelected = (urldecode($selectedShoe) === $shoeKey);
                                    ?>
                                    <option value="<?= htmlspecialchars(urlencode($shoeKey)) ?>"
                                            <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sm['FOOTWEAR_BRAND']) ?> — <?= htmlspecialchars($sm['FOOTWEAR_NAME']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn w-100 fw-bold"
                                        style="background:var(--sf-light);color:#fff;letter-spacing:.5px;">
                                    <i class="bi bi-search me-1"></i>Load Sizes
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- STEP 2: Size stock grid (shown after shoe is selected) -->
                    <?php if (!empty($selectedVariants)): ?>
                    <div style="border-top:1px solid rgba(255,255,255,.12); padding-top:1.2rem;">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <label style="color:rgba(255,255,255,.75); font-size:.78rem; font-weight:700; letter-spacing:1px; text-transform:uppercase;">
                                    Step 2 — Enter Quantity to Add per Size
                                </label>
                                <div style="font-size:.8rem; color:var(--sf-light); margin-top:.2rem; font-weight:600;">
                                    <?= htmlspecialchars($selectedBrand) ?> — <?= htmlspecialchars($selectedName) ?>
                                </div>
                            </div>
                            <small style="color:rgba(255,255,255,.35); font-size:.72rem;">
                                <i class="bi bi-info-circle me-1"></i>Leave as 0 to skip that size
                            </small>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action"   value="update_stock_sizes">
                            <input type="hidden" name="fp_brand" value="<?= htmlspecialchars($selectedBrand) ?>">
                            <input type="hidden" name="fp_name"  value="<?= htmlspecialchars($selectedName) ?>">

                            <!-- Size cards grid — one card per available size -->
                            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); gap:.65rem; margin-bottom:1.3rem;">
                                <?php foreach ($selectedVariants as $v):
                                    $qty    = $v['QTY_AVAILABLE'];
                                    $qColor = $qty == 0 ? '#e74c3c' : ($qty <= 5 ? '#e67e22' : '#2ecc71');
                                    $qBg    = $qty == 0 ? 'rgba(231,76,60,.2)' : ($qty <= 5 ? 'rgba(230,126,34,.2)' : 'rgba(46,204,113,.15)');
                                    $qLabel = $qty == 0 ? 'Out of Stock' : ($qty <= 5 ? "Low: $qty" : "In Stock: $qty");
                                ?>
                                <div style="background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.13); border-radius:10px; padding:.85rem .7rem; text-align:center; transition:border-color .2s;"
                                     onmouseover="this.style.borderColor='rgba(52,152,219,.5)'" onmouseout="this.style.borderColor='rgba(255,255,255,.13)'">

                                    <!-- Size label -->
                                    <div style="font-weight:900; font-size:1rem; color:#fff; letter-spacing:.5px; line-height:1; margin-bottom:.35rem;">
                                        <?= htmlspecialchars($v['SIZE']) ?>
                                    </div>

                                    <!-- Color label -->
                                    <div style="font-size:.68rem; color:rgba(255,255,255,.35); margin-bottom:.6rem;">
                                        <?= htmlspecialchars($v['COLOR']) ?>
                                    </div>

                                    <!-- Current stock badge (READ ONLY — staff cannot change this directly) -->
                                    <div style="background:<?= $qBg ?>; color:<?= $qColor ?>; font-size:.68rem; font-weight:700; padding:.22rem .5rem; border-radius:20px; margin-bottom:.7rem; display:inline-block;">
                                        <?= $qLabel ?>
                                    </div>

                                    <!-- Add Qty label -->
                                    <div style="font-size:.66rem; color:rgba(255,255,255,.45); margin-bottom:.3rem; letter-spacing:.5px; text-transform:uppercase;">
                                        Add Qty
                                    </div>

                                    <!-- Staff enters how many units to ADD -->
                                    <input type="number"
                                           name="add_qty[<?= $v['FOOTWEAR_ID'] ?>]"
                                           min="0" value="0"
                                           style="width:100%; text-align:center; background:rgba(255,255,255,.1); border:1.5px solid rgba(255,255,255,.2); border-radius:8px; color:#fff; padding:.4rem .2rem; font-weight:800; font-size:1rem; outline:none;"
                                           onfocus="this.style.borderColor='rgba(52,152,219,.7)'"
                                           onblur="this.style.borderColor='rgba(255,255,255,.2)'">
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Submit button -->
                            <button type="submit" class="btn w-100 py-2 fw-bold"
                                    style="background:var(--sf-light);color:#fff;font-size:.9rem;letter-spacing:1.5px;">
                                <i class="bi bi-database-add me-2"></i>CONFIRM & UPDATE STOCK IN SYSTEM
                            </button>
                        </form>
                    </div>

                    <?php elseif ($selectedShoe): ?>
                    <!-- Shoe selected but no variants found -->
                    <div style="border-top:1px solid rgba(255,255,255,.1); padding-top:1rem; color:rgba(255,255,255,.4); font-size:.85rem; text-align:center;">
                        <i class="bi bi-exclamation-circle me-1"></i>No size variants found for this shoe. Contact Admin to add sizes.
                    </div>

                    <?php else: ?>
                    <!-- No shoe selected yet -->
                    <div style="border-top:1px solid rgba(255,255,255,.1); padding-top:1rem; color:rgba(255,255,255,.3); font-size:.82rem;">
                        <i class="bi bi-arrow-up me-1" style="color:var(--sf-light);"></i>
                        Select a shoe model above — its sizes and current stock will appear here.
                    </div>
                    <?php endif; ?>

                    <!-- Bottom disclaimer -->
                    <div class="mt-3" style="font-size:.7rem; color:rgba(255,255,255,.3); border-top:1px solid rgba(255,255,255,.07); padding-top:.75rem;">
                        <i class="bi bi-lock me-1"></i>
                        Staff can only update stock quantities (QTY_AVAILABLE in stock table).
                        To add new shoe models, new sizes, images, pricing, or suppliers — please contact the Administrator.
                    </div>
                </div>
            </div>

            <!-- Current Stock Table -->
            <div class="col-12">
                <div class="sf-card">
                    <div class="sf-card-header">
                        <span class="sf-card-title"><i class="bi bi-boxes me-2" style="color:var(--sf-accent);"></i>CURRENT STOCK LEVELS</span>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="badge bg-danger"><?= $lowStockCount ?> Low / Out</span>
                            <small class="text-muted">Sorted lowest first</small>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead style="background:#f8fafc;">
                            <tr>
                                <th class="ps-3">Brand</th>
                                <th>Product Name</th>
                                <th>Size</th>
                                <th>Color</th>
                                <th class="text-end pe-3">Price</th>
                                <th class="text-center">Stock Level</th>
                                <th class="text-center">Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stockList as $s): ?>
                            <tr class="<?= $s['QTY_AVAILABLE']==0?'row-critical':($s['QTY_AVAILABLE']<=5?'row-low':'') ?>">
                                <td class="ps-3">
                                    <span class="badge" style="background:var(--sf-primary);color:#fff;letter-spacing:.5px;"><?= htmlspecialchars($s['FOOTWEAR_BRAND']) ?></span>
                                </td>
                                <td class="fw-semibold"><?= htmlspecialchars($s['FOOTWEAR_NAME']) ?></td>
                                <td><?= htmlspecialchars($s['SIZE']) ?></td>
                                <td><?= htmlspecialchars($s['COLOR']) ?></td>
                                <td class="text-end pe-3">RM <?= number_format($s['PRICE'],2) ?></td>
                                <td class="text-center">
                                    <span class="fw-bold <?= $s['QTY_AVAILABLE']==0?'stock-empty':($s['QTY_AVAILABLE']<=5?'stock-low':'stock-ok') ?>"
                                          style="font-size:1.1rem;"><?= $s['QTY_AVAILABLE'] ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($s['QTY_AVAILABLE']==0): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Out of Stock</span>
                                    <?php elseif ($s['QTY_AVAILABLE']<=5): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Low Stock</span>
                                    <?php else: ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════ TAB: DAILY SALES REPORT ═══════════════ -->
    <div class="sf-panel <?= $activeTab==='report'?'active':'' ?>" id="sf-report">

        <!-- Controls (hidden on print) -->
        <div class="sf-card mb-3 no-print report-controls">
            <div class="sf-card-header">
                <span class="sf-card-title"><i class="bi bi-calendar-check me-2" style="color:var(--sf-accent);"></i>DAILY SALES REPORT</span>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="tab" value="report">
                        <label class="fw-semibold small mb-0">Report Date:</label>
                        <input type="date" name="report_date" class="form-control form-control-sm" style="width:160px;"
                               value="<?= htmlspecialchars($reportDate) ?>"
                               max="<?= date('Y-m-d') ?>">
                        <button class="btn btn-sf btn-sm"><i class="bi bi-search me-1"></i>View</button>
                    </form>
                    <button onclick="window.print()" class="btn btn-sm fw-bold" style="background:var(--sf-primary);color:#fff;letter-spacing:1px;">
                        <i class="bi bi-printer me-1"></i>PRINT REPORT
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Printable Report Area ── -->
        <div class="sf-card report-printable p-4">

            <!-- Print-only header (hidden on screen) -->
            <div class="report-header-print">
                <h2 style="font-weight:900; letter-spacing:4px;">CROSSUNDER™</h2>
                <h5>DAILY SALES REPORT</h5>
                <p>Date: <?= date('d F Y', strtotime($reportDate)) ?> | Prepared by: <?= htmlspecialchars($user_name) ?> (<?= $role ?>)</p>
                <hr>
            </div>

            <!-- Screen header -->
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <div>
                    <h5 class="fw-bold mb-0" style="color:var(--sf-primary); letter-spacing:1px;">
                        Sales Report: <?= date('d F Y', strtotime($reportDate)) ?>
                    </h5>
                    <small class="text-muted">Generated by: <?= htmlspecialchars($user_name) ?></small>
                </div>
            </div>

            <!-- Summary stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="text-center p-3 rounded" style="background:var(--sf-primary); color:#fff;">
                        <div style="font-size:1.8rem; font-weight:900; color:var(--sf-light);"><?= $rptSum['cnt'] ?></div>
                        <div style="font-size:.7rem; letter-spacing:2px; opacity:.7;">TOTAL ORDERS</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-center p-3 rounded" style="background:#1a4a2e; color:#fff;">
                        <div style="font-size:1.5rem; font-weight:900; color:#2ecc71;">RM <?= number_format($rptSum['rev'],2) ?></div>
                        <div style="font-size:.7rem; letter-spacing:2px; opacity:.7;">TOTAL REVENUE</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-center p-3 rounded" style="background:#1a2a4a; color:#fff;">
                        <div style="font-size:1.8rem; font-weight:900; color:var(--sf-light);"><?= $rptTotalItems ?></div>
                        <div style="font-size:.7rem; letter-spacing:2px; opacity:.7;">ITEMS SOLD</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-center p-3 rounded" style="background:#2a1a3a; color:#fff;">
                        <div style="font-size:1.8rem; font-weight:900; color:#9b59b6;"><?= $rptSum['uniq_cust'] ?></div>
                        <div style="font-size:.7rem; letter-spacing:2px; opacity:.7;">UNIQUE CUSTOMERS</div>
                    </div>
                </div>
            </div>

            <!-- Report transactions table -->
            <?php if (empty($reportTxns)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
                <p class="mt-2">No transactions recorded for <?= date('d F Y', strtotime($reportDate)) ?>.</p>
            </div>
            <?php else: ?>
            <table class="table table-bordered align-middle" style="font-size:.85rem;">
                <thead style="background:var(--sf-primary); color:#fff;">
                    <tr>
                        <th class="text-white" style="width:90px;">Order #</th>
                        <th class="text-white">Time</th>
                        <th class="text-white">Customer</th>
                        <th class="text-white">Items Purchased</th>
                        <th class="text-white">Payment</th>
                        <th class="text-white">Status</th>
                        <th class="text-white text-end">Amount (RM)</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $runningTotal = 0;
                foreach ($reportTxns as $rt):
                    $rtItems = $pdo->prepare("SELECT ti.QUANTITY, f.FOOTWEAR_NAME, f.FOOTWEAR_BRAND FROM transaction_item ti JOIN footwear f ON ti.FOOTWEAR_ID=f.FOOTWEAR_ID WHERE ti.TRANSACTION_NO=?");
                    $rtItems->execute([$rt['TRANSACTION_NO']]);
                    $rtItemsList = $rtItems->fetchAll();
                    if ($rt['PAYMENT_STATUS'] !== 'Cancelled') $runningTotal += $rt['TOTAL_AMOUNT'];
                ?>
                <tr>
                    <td class="fw-bold" style="color:var(--sf-accent);">#<?= str_pad($rt['TRANSACTION_NO'],6,'0',STR_PAD_LEFT) ?></td>
                    <td><?= date('H:i', strtotime($rt['TRANSACTION_DATE'])) ?></td>
                    <td><?= htmlspecialchars($rt['CUST_NAME']) ?><br><small class="text-muted"><?= htmlspecialchars($rt['CUST_NOPHONE']) ?></small></td>
                    <td>
                        <?php foreach ($rtItemsList as $ri): ?>
                        <div style="font-size:.8rem;"><?= htmlspecialchars($ri['FOOTWEAR_BRAND'] . ' ' . $ri['FOOTWEAR_NAME']) ?> × <?= $ri['QUANTITY'] ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td><?= htmlspecialchars($rt['PAYMENT_METHOD']) ?></td>
                    <td><span class="badge bg-<?= $statusColors[$rt['PAYMENT_STATUS']]??'secondary' ?>"><?= htmlspecialchars($rt['PAYMENT_STATUS']) ?></span></td>
                    <td class="text-end fw-bold <?= $rt['PAYMENT_STATUS']==='Cancelled'?'text-muted text-decoration-line-through':'' ?>">
                        <?= number_format($rt['TOTAL_AMOUNT'],2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;">
                        <td colspan="6" class="text-end fw-bold" style="letter-spacing:1px;">TOTAL REVENUE (excl. Cancelled)</td>
                        <td class="text-end fw-bold" style="font-size:1.1rem; color:var(--sf-success);">RM <?= number_format($runningTotal,2) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>

            <!-- Print footer -->
            <div style="margin-top:2rem; font-size:.78rem; color:#aaa; display:flex; justify-content:space-between;">
                <span>CROSSUNDER™ — Report Date: <?= date('d F Y', strtotime($reportDate)) ?></span>
                <span>Printed by: <?= htmlspecialchars($user_name) ?> | <?= date('d/m/Y H:i') ?></span>
            </div>

        </div><!-- /report-printable -->
    </div>

</div><!-- /container -->

<!-- SweetAlert feedback -->
<?php if ($feedbackMsg): ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({
        icon:'<?= $feedbackType==='success'?'success':'error' ?>',
        title:'<?= $feedbackType==='success'?'Done!':'Error' ?>',
        text:'<?= addslashes($feedbackMsg) ?>',
        toast:true, position:'top-end', showConfirmButton:false,
        timer:3500, timerProgressBar:true
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const tabLabels = { orders:'orders', stock:'stock', report:'report' };
function sfTab(name) {
    document.querySelectorAll('.sf-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.sf-tabbar .nav-link').forEach(l=>l.classList.remove('active'));
    document.getElementById('sf-'+name).classList.add('active');
    event.currentTarget.classList.add('active');
}
// Activate correct tab on load
document.addEventListener('DOMContentLoaded',()=>{
    const t = '<?= htmlspecialchars($activeTab) ?>';
    document.querySelectorAll('.sf-panel').forEach(p=>p.classList.remove('active'));
    const panel = document.getElementById('sf-'+t);
    if(panel) panel.classList.add('active');
    document.querySelectorAll('.sf-tabbar .nav-link').forEach(l=>{
        if(l.getAttribute('onclick') && l.getAttribute('onclick').includes("'"+t+"'")) l.classList.add('active');
    });
});
</script>
</body>
</html>
