<?php
// ============================================================
// customer_dashboard.php – Product catalog for CUSTOMER role
// Products are grouped by name — customer selects SIZE + QTY
// Size selection dynamically updates FOOTWEAR_ID via JS
// ============================================================
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'CUSTOMER') {
    header("Location: login.php"); exit;
}

$cust_id   = $_SESSION['cust_id'];
$user_name = $_SESSION['user_name'];

// ── Ensure cart exists ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT CART_ID FROM cart WHERE CUST_ID = ?");
$stmt->execute([$cust_id]);
$cartRow = $stmt->fetch();
if (!$cartRow) {
    $pdo->prepare("INSERT INTO cart (CUST_ID) VALUES (?)")->execute([$cust_id]);
    $stmt->execute([$cust_id]);
    $cartRow = $stmt->fetch();
}
$cart_id = $cartRow['CART_ID'];

// ── Handle Add To Cart (POST) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
    $footwear_id = (int)$_POST['footwear_id'];
    $qty         = max(1, (int)($_POST['qty'] ?? 1));

    // Verify stock
    $stmt = $pdo->prepare("SELECT QTY_AVAILABLE FROM stock WHERE FOOTWEAR_ID = ?");
    $stmt->execute([$footwear_id]);
    $stock = $stmt->fetch();

    if (!$stock || $stock['QTY_AVAILABLE'] < $qty) {
        $_SESSION['cart_msg'] = ['type' => 'danger', 'text' => 'Sorry, not enough stock for the selected size and quantity.'];
    } else {
        // Check if this exact FOOTWEAR_ID (size) is already in cart
        $stmt = $pdo->prepare("SELECT QUANTITY FROM cart_item WHERE CART_ID = ? AND FOOTWEAR_ID = ?");
        $stmt->execute([$cart_id, $footwear_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $new_qty = min($existing['QUANTITY'] + $qty, $stock['QTY_AVAILABLE']);
            $pdo->prepare("UPDATE cart_item SET QUANTITY = ? WHERE CART_ID = ? AND FOOTWEAR_ID = ?")
                ->execute([$new_qty, $cart_id, $footwear_id]);
        } else {
            $pdo->prepare("INSERT INTO cart_item (CART_ID, FOOTWEAR_ID, QUANTITY) VALUES (?, ?, ?)")
                ->execute([$cart_id, $footwear_id, $qty]);
        }

        // Get shoe name + size for friendly message
        $info = $pdo->prepare("SELECT FOOTWEAR_NAME, SIZE FROM footwear WHERE FOOTWEAR_ID = ?");
        $info->execute([$footwear_id]);
        $shoe = $info->fetch();
        $_SESSION['cart_msg'] = [
            'type' => 'success',
            'text' => "{$shoe['FOOTWEAR_NAME']} (Size {$shoe['SIZE']}) added to your cart!"
        ];
    }
    // Preserve current search params on redirect
    header("Location: customer_dashboard.php?" . http_build_query(array_filter([
        'name'      => $_GET['name']      ?? '',
        'brand'     => $_GET['brand']     ?? '',
        'min_price' => $_GET['min_price'] ?? '',
        'max_price' => $_GET['max_price'] ?? '',
        'page'      => $_GET['page']      ?? '',
    ])));
    exit;
}

// ── Cart badge count ──────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(SUM(QUANTITY), 0) FROM cart_item WHERE CART_ID = ?");
$stmt->execute([$cart_id]);
$cartCount = (int)$stmt->fetchColumn();

// ── Dynamic price range from full catalog ─────────────────────
$priceRange = $pdo->query("SELECT MIN(PRICE) AS min_p, MAX(PRICE) AS max_p FROM footwear")->fetch();
$globalMin  = (float)($priceRange['min_p'] ?? 0);
$globalMax  = (float)($priceRange['max_p'] ?? 9999);

// ── UK size ranges (used for gender filtering) ────────────────
$MALE_SIZES   = ['UK 7', 'UK 8', 'UK 9', 'UK 10', 'UK 11', 'UK 12'];
$FEMALE_SIZES = ['UK 4', 'UK 4.5', 'UK 5', 'UK 5.5', 'UK 6', 'UK 6.5'];

// ── Search / filter inputs ────────────────────────────────────
$searchName   = trim($_GET['name']      ?? '');
$searchBrand  = trim($_GET['brand']     ?? '');
$genderFilter = $_GET['gender'] ?? 'all'; // 'all', 'male', 'female'
$minPrice     = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : $globalMin;
$maxPrice     = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : $globalMax;

// ── Fetch all matching products (all size variants) ───────────
// No LIMIT here — we group in PHP and paginate by unique shoe
$sql    = "SELECT f.FOOTWEAR_ID, f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME, f.DESCRIPTION,
                  f.IMAGE_URL, f.SIZE, f.COLOR, f.PRICE, s.QTY_AVAILABLE
           FROM footwear f
           JOIN stock s ON f.FOOTWEAR_ID = s.FOOTWEAR_ID
           WHERE f.PRICE BETWEEN ? AND ?";
$params = [$minPrice, $maxPrice];

if ($searchName !== '') {
    $sql .= " AND f.FOOTWEAR_NAME LIKE ?";
    $params[] = "%$searchName%";
}
if ($searchBrand !== '') {
    $sql .= " AND f.FOOTWEAR_BRAND LIKE ?";
    $params[] = "%$searchBrand%";
}

// ── Gender filter: restrict sizes shown based on selection ─────
if ($genderFilter === 'male') {
    $placeholders = implode(',', array_fill(0, count($MALE_SIZES), '?'));
    $sql .= " AND f.SIZE IN ($placeholders)";
    $params = array_merge($params, $MALE_SIZES);
} elseif ($genderFilter === 'female') {
    $placeholders = implode(',', array_fill(0, count($FEMALE_SIZES), '?'));
    $sql .= " AND f.SIZE IN ($placeholders)";
    $params = array_merge($params, $FEMALE_SIZES);
}

$sql .= " ORDER BY f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME, CAST(SUBSTRING_INDEX(f.SIZE,' ',-1) AS DECIMAL(5,1))";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allProducts = $stmt->fetchAll();

// ── Group products by BRAND + NAME (one card per unique shoe) ─
// Each "group" represents one shoe model with multiple size variants
$grouped = [];
foreach ($allProducts as $p) {
    // Key: brand + name uniquely identifies a shoe model
    $key = strtolower($p['FOOTWEAR_BRAND'] . '||' . $p['FOOTWEAR_NAME']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'brand'       => $p['FOOTWEAR_BRAND'],
            'name'        => $p['FOOTWEAR_NAME'],
            'description' => $p['DESCRIPTION'],
            'image_url'   => $p['IMAGE_URL'],
            'variants'    => [],  // Each variant = one size option
        ];
    }
    $grouped[$key]['variants'][] = [
        'id'    => (int)$p['FOOTWEAR_ID'],
        'size'  => $p['SIZE'],
        'color' => $p['COLOR'],
        'price' => (float)$p['PRICE'],
        'qty'   => (int)$p['QTY_AVAILABLE'],
    ];
}
$grouped = array_values($grouped);

// ── Paginate the grouped shoes (12 per page) ──────────────────
$perPage     = 12;
$totalGroups = count($grouped);
$totalPages  = max(1, ceil($totalGroups / $perPage));
$page        = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset      = ($page - 1) * $perPage;
$pageGroups  = array_slice($grouped, $offset, $perPage);

// ── Distinct brands for filter dropdown ───────────────────────
$brands = $pdo->query("SELECT DISTINCT FOOTWEAR_BRAND FROM footwear ORDER BY FOOTWEAR_BRAND")->fetchAll(PDO::FETCH_COLUMN);

// ── Flash message ─────────────────────────────────────────────
$cartMsg = $_SESSION['cart_msg'] ?? null;
unset($_SESSION['cart_msg']);

// Build base URL for pagination links (preserving filters)
$filterQuery = http_build_query(array_filter([
    'name'      => $searchName,
    'brand'     => $searchBrand,
    'min_price' => $minPrice != $globalMin ? $minPrice : '',
    'max_price' => $maxPrice != $globalMax ? $maxPrice : '',
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CROSSUNDER™ – Shop</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --cu-dark: #0f0f1a; --cu-gold: #C8A96E; --cu-gold-hover: #a8793e; }
        body { background: #f4f4f6; font-family: 'Segoe UI', sans-serif; }

        /* ── Navbar ── */
        .navbar-cu { background: var(--cu-dark) !important; }
        .navbar-brand { color: var(--cu-gold) !important; font-weight: 900; letter-spacing: 4px; font-size: 1.3rem; }

        /* ── Buttons ── */
        .btn-cu { background: var(--cu-gold); border: none; color: #fff; font-weight: 600; }
        .btn-cu:hover { background: var(--cu-gold-hover); color: #fff; }

        /* ── Search card ── */
        .search-card { background: #fff; border-radius: 12px; padding: 1.4rem 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
        .form-control:focus, .form-select:focus { border-color: var(--cu-gold); box-shadow: 0 0 0 .2rem rgba(200,169,110,.2); }

        /* ── Product cards ── */
        .product-card {
            border: none;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 14px rgba(0,0,0,.08);
            transition: transform .2s, box-shadow .2s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 28px rgba(0,0,0,.14); }
        .product-img {
            height: 210px;
            object-fit: cover;
            background: #f0f0f0;
            width: 100%;
        }
        .brand-badge { background: var(--cu-dark); color: var(--cu-gold); font-size: .68rem; letter-spacing: 1px; }
        .shoe-name   { font-size: .95rem; font-weight: 800; color: var(--cu-dark); line-height: 1.2; }
        .shoe-desc   { font-size: .78rem; color: #888; line-height: 1.4; }

        /* ── Size selector buttons ── */
        .size-label { font-size: .65rem; letter-spacing: 2px; text-transform: uppercase; color: #aaa; font-weight: 700; margin-bottom: .4rem; }
        .size-btn-group { display: flex; flex-wrap: wrap; gap: .3rem; }
        .size-btn-group input[type="radio"] { display: none; }
        .size-btn {
            padding: .3rem .6rem;
            border: 1.5px solid #ddd;
            border-radius: 6px;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            background: #fff;
            color: var(--cu-dark);
            transition: all .15s;
            user-select: none;
            line-height: 1;
        }
        .size-btn:hover { border-color: var(--cu-gold); color: var(--cu-gold); }
        input[type="radio"].size-radio:checked + .size-btn {
            background: var(--cu-dark);
            border-color: var(--cu-dark);
            color: var(--cu-gold);
        }
        .size-btn.out-of-stock {
            opacity: .4;
            cursor: not-allowed;
            text-decoration: line-through;
        }

        /* ── Price & stock display ── */
        .price-display { font-size: 1.25rem; font-weight: 900; color: var(--cu-dark); }
        .stock-ok    { color: #27ae60; font-size: .78rem; font-weight: 600; }
        .stock-low   { color: #e67e22; font-size: .78rem; font-weight: 600; }
        .stock-empty { color: #e74c3c; font-size: .78rem; font-weight: 600; }

        /* ── Qty input ── */
        .qty-input { width: 65px; text-align: center; }

        /* ── Pagination ── */
        .page-link { color: var(--cu-dark); }
        .page-item.active .page-link { background: var(--cu-gold); border-color: var(--cu-gold); color: #fff; }

        /* ── Empty state ── */
        .no-results { background: #fff; border-radius: 12px; padding: 3rem; text-align: center; color: #bbb; }

        /* ── Color dot ── */
        .color-dot {
            display: inline-block;
            width: 10px; height: 10px;
            border-radius: 50%;
            border: 1px solid #ddd;
            margin-right: 4px;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<!-- ─── NAVBAR ─────────────────────────────────────────────── -->
<nav class="navbar navbar-dark navbar-cu navbar-expand-lg sticky-top shadow">
    <div class="container">
        <a class="navbar-brand" href="customer_dashboard.php">CROSSUNDER™</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item">
                    <a href="customer_dashboard.php" class="nav-link" style="color:var(--cu-gold); font-weight:600;">
                        <i class="bi bi-grid me-1"></i>Shop
                    </a>
                </li>
                <li class="nav-item">
                    <a href="order_history.php" class="nav-link text-light">
                        <i class="bi bi-bag me-1"></i>My Orders
                    </a>
                </li>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link text-light">
                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($user_name) ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="cart.php" class="btn btn-outline-warning btn-sm position-relative">
                        <i class="bi bi-cart3 me-1"></i>Cart
                        <?php if ($cartCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $cartCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">

    <!-- ─── SEARCH & FILTER ─────────────────────────────────── -->
    <div class="search-card mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <!-- Name search -->
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Search by Name</label>
                <input type="text" name="name" class="form-control"
                       placeholder="e.g. Air Max, Ultraboost…"
                       value="<?= htmlspecialchars($searchName) ?>">
            </div>
            <!-- Brand filter -->
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Filter by Brand</label>
                <select name="brand" class="form-select">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $b): ?>
                    <option value="<?= htmlspecialchars($b) ?>" <?= $searchBrand===$b?'selected':'' ?>>
                        <?= htmlspecialchars($b) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Min price -->
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Min Price (RM)</label>
                <input type="number" name="min_price" class="form-control"
                       step="1" min="<?= $globalMin ?>" max="<?= $globalMax ?>"
                       value="<?= $minPrice ?>" placeholder="<?= $globalMin ?>">
            </div>
            <!-- Max price -->
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Max Price (RM)</label>
                <input type="number" name="max_price" class="form-control"
                       step="1" min="<?= $globalMin ?>" max="<?= $globalMax ?>"
                       value="<?= $maxPrice ?>" placeholder="<?= $globalMax ?>">
            </div>
            <!-- Action buttons -->
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-cu flex-fill">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                <a href="customer_dashboard.php" class="btn btn-outline-secondary" title="Clear filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
        <small class="text-muted mt-2 d-block">
            <i class="bi bi-info-circle me-1"></i>
            Catalog price range: <strong>RM <?= number_format($globalMin,2) ?></strong> – <strong>RM <?= number_format($globalMax,2) ?></strong>
            &nbsp;|&nbsp; <?= $totalGroups ?> shoe model(s) found
        </small>
    </div>

    <!-- ─── PRODUCT GRID ────────────────────────────────────── -->
    <?php if (empty($pageGroups)): ?>
    <div class="no-results">
        <i class="bi bi-search" style="font-size:3rem; color:#ddd;"></i>
        <p class="mt-3 mb-0">No products match your search.</p>
        <a href="customer_dashboard.php" class="btn btn-cu mt-3">View All Products</a>
    </div>

    <?php else: ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-4">

        <?php foreach ($pageGroups as $idx => $group):
            // Find the first IN-STOCK variant to default to, otherwise first variant
            $defaultVariant = null;
            foreach ($group['variants'] as $v) {
                if ($v['qty'] > 0) { $defaultVariant = $v; break; }
            }
            if (!$defaultVariant) $defaultVariant = $group['variants'][0];
            $allOutOfStock = !array_filter($group['variants'], fn($v) => $v['qty'] > 0);
        ?>
        <div class="col">
            <div class="product-card bg-white">

                <!-- Product image -->
                <img src="images/<?= htmlspecialchars($group['image_url']) ?>"
                     class="product-img"
                     alt="<?= htmlspecialchars($group['name']) ?>"
                     onerror="this.src='https://placehold.co/300x210/0f0f1a/C8A96E?text=<?= urlencode($group['brand']) ?>'">

                <div class="card-body d-flex flex-column p-3" style="flex:1;">

                    <!-- Brand -->
                    <span class="badge brand-badge mb-2 align-self-start">
                        <?= htmlspecialchars($group['brand']) ?>
                    </span>

                    <!-- Shoe name -->
                    <h6 class="shoe-name mb-1"><?= htmlspecialchars($group['name']) ?></h6>

                    <!-- Description -->
                    <p class="shoe-desc mb-3" style="flex-grow:1;">
                        <?= htmlspecialchars(substr($group['description'] ?? '', 0, 55)) ?>…
                    </p>

                    <!-- ── SIZE SELECTOR ──────────────────────── -->
                    <div class="mb-3">
                        <div class="size-label"><i class="bi bi-rulers me-1"></i>Select Size</div>
                        <div class="size-btn-group">
                            <?php foreach ($group['variants'] as $vi => $v):
                                $isDefault  = ($v['id'] === $defaultVariant['id']);
                                $outOfStock = ($v['qty'] <= 0);
                            ?>
                            <input type="radio"
                                   class="size-radio"
                                   name="size_group_<?= $idx ?>"
                                   id="size_<?= $idx ?>_<?= $vi ?>"
                                   value="<?= $v['id'] ?>"
                                   data-price="<?= $v['price'] ?>"
                                   data-qty="<?= $v['qty'] ?>"
                                   data-color="<?= htmlspecialchars($v['color']) ?>"
                                   data-max="<?= $v['qty'] ?>"
                                   <?= $isDefault  ? 'checked'  : '' ?>
                                   <?= $outOfStock ? 'disabled' : '' ?>
                                   onchange="onSizeChange(this, <?= $idx ?>)">
                            <label class="size-btn <?= $outOfStock ? 'out-of-stock' : '' ?>"
                                   for="size_<?= $idx ?>_<?= $vi ?>"
                                   title="<?= $outOfStock ? 'Out of stock' : 'Size '.$v['size'].' — '.$v['qty'].' left' ?>">
                                <?= htmlspecialchars($v['size']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── COLOUR DISPLAY (updates dynamically) ── -->
                    <div class="mb-2" id="color_<?= $idx ?>" style="font-size:.78rem; color:#666;">
                        <span class="color-dot" id="color_dot_<?= $idx ?>" style="background:<?= strtolower($defaultVariant['color']) ?>;"></span>
                        <span id="color_name_<?= $idx ?>"><?= htmlspecialchars($defaultVariant['color']) ?></span>
                    </div>

                    <!-- ── DYNAMIC PRICE ── -->
                    <div class="price-display mb-1" id="price_<?= $idx ?>">
                        RM <?= number_format($defaultVariant['price'], 2) ?>
                    </div>

                    <!-- ── DYNAMIC STOCK INDICATOR ── -->
                    <div class="mb-3" id="stock_<?= $idx ?>">
                        <?php if ($defaultVariant['qty'] <= 0): ?>
                        <small class="stock-empty"><i class="bi bi-x-circle me-1"></i>Out of Stock</small>
                        <?php elseif ($defaultVariant['qty'] <= 5): ?>
                        <small class="stock-low"><i class="bi bi-exclamation-triangle me-1"></i>Only <?= $defaultVariant['qty'] ?> left!</small>
                        <?php else: ?>
                        <small class="stock-ok"><i class="bi bi-check-circle me-1"></i>In Stock (<?= $defaultVariant['qty'] ?>)</small>
                        <?php endif; ?>
                    </div>

                    <!-- ── ADD TO CART FORM ── -->
                    <?php if (!$allOutOfStock): ?>
                    <form method="POST" class="mt-auto">
                        <input type="hidden" name="action"      value="add_to_cart">
                        <!-- This hidden field is updated by JS when size changes -->
                        <input type="hidden" name="footwear_id" id="fid_<?= $idx ?>" value="<?= $defaultVariant['id'] ?>">

                        <div class="d-flex gap-2 align-items-center">
                            <div class="d-flex align-items-center border rounded overflow-hidden" style="flex-shrink:0;">
                                <button type="button" class="btn btn-sm border-0 px-2"
                                        onclick="adjustQty('qty_<?= $idx ?>', -1)">−</button>
                                <input type="number" name="qty" id="qty_<?= $idx ?>"
                                       value="1" min="1" max="<?= $defaultVariant['qty'] ?>"
                                       class="qty-input border-0 form-control form-control-sm text-center p-0"
                                       style="height:32px;">
                                <button type="button" class="btn btn-sm border-0 px-2"
                                        onclick="adjustQty('qty_<?= $idx ?>', 1)">+</button>
                            </div>
                            <button type="submit" class="btn btn-cu btn-sm flex-fill"
                                    id="btn_<?= $idx ?>">
                                <i class="bi bi-cart-plus me-1"></i>Add to Cart
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <button class="btn btn-secondary btn-sm w-100 mt-auto" disabled>
                        <i class="bi bi-slash-circle me-1"></i>All Sizes Sold Out
                    </button>
                    <?php endif; ?>

                </div><!-- /card-body -->
            </div><!-- /product-card -->
        </div><!-- /col -->
        <?php endforeach; ?>
    </div><!-- /row -->

    <!-- ─── PAGINATION ──────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= $filterQuery ?>&page=<?= $page-1 ?>">&laquo; Prev</a>
            </li>
            <?php endif; ?>

            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <li class="page-item <?= $i===$page?'active':'' ?>">
                <a class="page-link" href="?<?= $filterQuery ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= $filterQuery ?>&page=<?= $page+1 ?>">Next &raquo;</a>
            </li>
            <?php endif; ?>
        </ul>
        <p class="text-center text-muted small">
            Showing <?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage, $totalGroups) ?> of <?= $totalGroups ?> shoe model(s)
        </p>
    </nav>
    <?php endif; ?>

    <?php endif; // end if pageGroups not empty ?>
</div><!-- /container -->

<!-- ─── SWEETALERT FEEDBACK ─────────────────────────────────── -->
<?php if ($cartMsg): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: '<?= $cartMsg['type'] === 'success' ? 'success' : 'error' ?>',
        title: '<?= $cartMsg['type'] === 'success' ? 'Added to Cart!' : 'Error' ?>',
        text: '<?= addslashes($cartMsg['text']) ?>',
        toast: true, position: 'top-end',
        showConfirmButton: false,
        timer: 2800, timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Called whenever a size radio button is selected ────────────
function onSizeChange(radio, idx) {
    const price   = parseFloat(radio.dataset.price);
    const qty     = parseInt(radio.dataset.qty);
    const fid     = radio.value;
    const color   = radio.dataset.color || '';

    // 1. Update hidden FOOTWEAR_ID so the correct size goes to cart
    const fidInput = document.getElementById('fid_' + idx);
    if (fidInput) fidInput.value = fid;

    // 2. Update displayed price
    const priceEl = document.getElementById('price_' + idx);
    if (priceEl) priceEl.textContent = 'RM ' + price.toFixed(2);

    // 3. Update stock indicator
    const stockEl = document.getElementById('stock_' + idx);
    if (stockEl) {
        if (qty <= 0) {
            stockEl.innerHTML = '<small class="stock-empty"><i class="bi bi-x-circle me-1"></i>Out of Stock</small>';
        } else if (qty <= 5) {
            stockEl.innerHTML = '<small class="stock-low"><i class="bi bi-exclamation-triangle me-1"></i>Only ' + qty + ' left!</small>';
        } else {
            stockEl.innerHTML = '<small class="stock-ok"><i class="bi bi-check-circle me-1"></i>In Stock (' + qty + ')</small>';
        }
    }

    // 4. Update color display
    const colorName = document.getElementById('color_name_' + idx);
    const colorDot  = document.getElementById('color_dot_'  + idx);
    if (colorName) colorName.textContent = color;
    if (colorDot)  colorDot.style.background = color.toLowerCase();

    // 5. Update qty input max and reset to 1
    const qtyInput = document.getElementById('qty_' + idx);
    if (qtyInput) {
        qtyInput.max   = qty > 0 ? qty : 1;
        qtyInput.value = 1;
    }

    // 6. Enable/disable Add to Cart button
    const btn = document.getElementById('btn_' + idx);
    if (btn) btn.disabled = (qty <= 0);
}

// ── +/− quantity button helper ────────────────────────────────
function adjustQty(inputId, delta) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    let val = parseInt(inp.value) + delta;
    val = Math.max(1, Math.min(val, parseInt(inp.max) || 99));
    inp.value = val;
}
</script>
</body>
</html>
