<?php
// ============================================================
// admin_dashboard.php – Admin Control Panel
// Inventory redesigned: products grouped by brand+name,
// sizes managed as Male (UK 7–12) or Female (UK 4–6.5) grids
// ============================================================
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php"); exit;
}

$user_name = $_SESSION['user_name'];
$activeTab = $_GET['tab'] ?? 'dashboard';

// ── UK Size constants ──────────────────────────────────────────
$MALE_SIZES   = ['UK 7', 'UK 8', 'UK 9', 'UK 10', 'UK 11', 'UK 12'];
$FEMALE_SIZES = ['UK 4', 'UK 4.5', 'UK 5', 'UK 5.5', 'UK 6', 'UK 6.5'];
$ALL_STD_SIZES = array_merge($MALE_SIZES, $FEMALE_SIZES);

// ── Helpers ───────────────────────────────────────────────────
function handleImageUpload($fileKey) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return '';
    $allowed = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
    if (!in_array(mime_content_type($_FILES[$fileKey]['tmp_name']), $allowed)) return '';
    if ($_FILES[$fileKey]['size'] > 5*1024*1024) return '';
    $ext     = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $newName = 'shoe_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
    $dir     = __DIR__.'/images/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dir.$newName) ? $newName : '';
}

function paginate($cur,$total,$base) {
    // HOW IT WORKS: $base contains a placeholder like "ppage=x" or "spage=x"
    // We replace "=x" with "=PAGE_NUMBER" so the correct GET parameter is preserved.
    // e.g. "?tab=inventory&ppage=x" becomes "?tab=inventory&ppage=2"
    if ($total<=1) return '';
    $h='<nav><ul class="pagination pagination-sm mb-0">';
    if ($cur>1)
        $h.='<li class="page-item"><a class="page-link" href="'.str_replace('=x','='.($cur-1),$base).'">&laquo;</a></li>';
    for($i=max(1,$cur-2);$i<=min($total,$cur+2);$i++){
        $active = $i==$cur ? ' active' : '';
        $h.='<li class="page-item'.$active.'"><a class="page-link" href="'.str_replace('=x','='.$i,$base).'">'.$i.'</a></li>';
    }
    if ($cur<$total)
        $h.='<li class="page-item"><a class="page-link" href="'.str_replace('=x','='.($cur+1),$base).'">&raquo;</a></li>';
    return $h.'</ul></nav>';
}

// ════════════════════════════════════════════════════════════
// HANDLE ALL POST ACTIONS
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $feedbackMsg  = '';
    $feedbackType = 'success';

    // ── PRODUCT: ADD (multi-size) ──────────────────────────
    if ($action === 'add_product') {
        $brand = trim($_POST['fp_brand']  ?? '');
        $name  = trim($_POST['fp_name']   ?? '');
        $desc  = trim($_POST['fp_desc']   ?? '');
        $color = trim($_POST['fp_color']  ?? '');
        $price = (float)($_POST['fp_price'] ?? 0);
        $supp  = (int)($_POST['fp_supp']  ?? 0);
        $img   = trim($_POST['fp_img']    ?? '');
        $up    = handleImageUpload('fp_image');
        if ($up) $img = $up;

        $size_enabled = $_POST['size_enabled'] ?? [];   // [size => 'on']
        $size_qty     = $_POST['size_qty']     ?? [];   // [size => qty]

        if (empty($brand)||empty($name)||$price<=0||$supp===0||empty($size_enabled)) {
            $feedbackMsg  = 'Brand, name, price, supplier, and at least one size are required.';
            $feedbackType = 'error';
        } else {
            $created = 0;
            foreach ($size_enabled as $size => $enabled) {
                $qty = max(0, (int)($size_qty[$size] ?? 0));
                // Skip if this exact brand+name+size already exists
                $chk = $pdo->prepare("SELECT FOOTWEAR_ID FROM footwear WHERE FOOTWEAR_BRAND=? AND FOOTWEAR_NAME=? AND SIZE=?");
                $chk->execute([$brand,$name,$size]);
                if ($chk->fetch()) continue;

                $pdo->prepare("INSERT INTO footwear (FOOTWEAR_BRAND,FOOTWEAR_NAME,DESCRIPTION,IMAGE_URL,SIZE,COLOR,PRICE,SUPP_ID) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$brand,$name,$desc,$img,$size,$color,$price,$supp]);
                $newId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO stock (FOOTWEAR_ID,QTY_AVAILABLE) VALUES (?,?)")->execute([$newId,$qty]);
                $created++;
            }
            $feedbackMsg = $created > 0
                ? "\"$name\" added with $created size(s)."
                : "No new sizes added — they may already exist.";
        }
        $activeTab = 'inventory';
    }

    // ── PRODUCT: EDIT (multi-size group) ──────────────────
    elseif ($action === 'edit_product') {
        global $ALL_STD_SIZES;
        $orig_brand = trim($_POST['fp_orig_brand'] ?? '');
        $orig_name  = trim($_POST['fp_orig_name']  ?? '');
        $brand      = trim($_POST['fp_brand']  ?? '');
        $name       = trim($_POST['fp_name']   ?? '');
        $desc       = trim($_POST['fp_desc']   ?? '');
        $color      = trim($_POST['fp_color']  ?? '');
        $price      = (float)($_POST['fp_price'] ?? 0);
        $supp       = (int)($_POST['fp_supp']  ?? 0);
        $img        = trim($_POST['fp_img']    ?? '');
        $up         = handleImageUpload('fp_image');
        if ($up) $img = $up;
        elseif (empty($img)) {
            $cur = $pdo->prepare("SELECT IMAGE_URL FROM footwear WHERE FOOTWEAR_BRAND=? AND FOOTWEAR_NAME=? LIMIT 1");
            $cur->execute([$orig_brand,$orig_name]);
            $img = $cur->fetchColumn() ?: '';
        }

        $size_enabled = $_POST['size_enabled'] ?? [];
        $size_qty     = $_POST['size_qty']     ?? [];

        if (empty($brand)||empty($name)||$price<=0||$supp===0) {
            $feedbackMsg  = 'Brand, name, price, and supplier are required.';
            $feedbackType = 'error';
        } else {
            // Get existing variants
            $existStmt = $pdo->prepare("SELECT FOOTWEAR_ID, SIZE FROM footwear WHERE FOOTWEAR_BRAND=? AND FOOTWEAR_NAME=?");
            $existStmt->execute([$orig_brand,$orig_name]);
            $existingVariants = []; // SIZE => FOOTWEAR_ID
            foreach ($existStmt->fetchAll() as $row) $existingVariants[$row['SIZE']] = $row['FOOTWEAR_ID'];

            // Update common fields on all existing variants
            $pdo->prepare("UPDATE footwear SET FOOTWEAR_BRAND=?,FOOTWEAR_NAME=?,DESCRIPTION=?,IMAGE_URL=?,COLOR=?,PRICE=?,SUPP_ID=? WHERE FOOTWEAR_BRAND=? AND FOOTWEAR_NAME=?")
                ->execute([$brand,$name,$desc,$img,$color,$price,$supp,$orig_brand,$orig_name]);

            // Process each checked size
            $enabledSizes = [];
            foreach ($size_enabled as $size => $enabled) {
                $enabledSizes[] = $size;
                $qty = max(0, (int)($size_qty[$size] ?? 0));
                if (isset($existingVariants[$size])) {
                    // Update stock for this existing size
                    $pdo->prepare("UPDATE stock SET QTY_AVAILABLE=? WHERE FOOTWEAR_ID=?")
                        ->execute([$qty, $existingVariants[$size]]);
                } else {
                    // New standard size — create footwear + stock record
                    $pdo->prepare("INSERT INTO footwear (FOOTWEAR_BRAND,FOOTWEAR_NAME,DESCRIPTION,IMAGE_URL,SIZE,COLOR,PRICE,SUPP_ID) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$brand,$name,$desc,$img,$size,$color,$price,$supp]);
                    $newId = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO stock (FOOTWEAR_ID,QTY_AVAILABLE) VALUES (?,?)")->execute([$newId,$qty]);
                }
            }

            // Remove unchecked STANDARD sizes (non-standard/legacy sizes are never auto-deleted)
            foreach ($existingVariants as $size => $fid) {
                $isStandard  = in_array($size, $ALL_STD_SIZES);
                $wasDisabled = !in_array($size, $enabledSizes);
                if ($isStandard && $wasDisabled) {
                    try {
                        $pdo->prepare("DELETE FROM cart_item WHERE FOOTWEAR_ID=?")->execute([$fid]);
                        $pdo->prepare("DELETE FROM stock WHERE FOOTWEAR_ID=?")->execute([$fid]);
                        $pdo->prepare("DELETE FROM footwear WHERE FOOTWEAR_ID=?")->execute([$fid]);
                    } catch (PDOException $e) {
                        // Linked to transactions — set stock to 0 instead of deleting
                        $pdo->prepare("UPDATE stock SET QTY_AVAILABLE=0 WHERE FOOTWEAR_ID=?")->execute([$fid]);
                    }
                }
            }
            $feedbackMsg = "\"$name\" updated successfully.";
        }
        $activeTab = 'inventory';
    }

    // ── PRODUCT: DELETE (all sizes in group) ──────────────
    elseif ($action === 'delete_product') {
        $orig_brand = trim($_POST['fp_orig_brand'] ?? '');
        $orig_name  = trim($_POST['fp_orig_name']  ?? '');
        $existStmt  = $pdo->prepare("SELECT FOOTWEAR_ID FROM footwear WHERE FOOTWEAR_BRAND=? AND FOOTWEAR_NAME=?");
        $existStmt->execute([$orig_brand,$orig_name]);
        $fids = $existStmt->fetchAll(PDO::FETCH_COLUMN);
        $errors = 0;
        foreach ($fids as $fid) {
            try {
                $pdo->prepare("DELETE FROM cart_item WHERE FOOTWEAR_ID=?")->execute([$fid]);
                $pdo->prepare("DELETE FROM stock WHERE FOOTWEAR_ID=?")->execute([$fid]);
                $pdo->prepare("DELETE FROM footwear WHERE FOOTWEAR_ID=?")->execute([$fid]);
            } catch (PDOException $e) { $errors++; }
        }
        $feedbackMsg  = $errors === 0
            ? "\"$orig_name\" and all size variants deleted."
            : "Some sizes could not be deleted (linked to transactions). They've been set to 0 stock.";
        if ($errors > 0) $feedbackType = 'error';
        $activeTab = 'inventory';
    }

    // ── STAFF CRUD ────────────────────────────────────────
    elseif ($action === 'add_staff') {
        $sname=$_POST['sf_name']??''; $sphone=$_POST['sf_phone']??''; $saddr=$_POST['sf_address']??'';
        $sjoin=$_POST['sf_joindate']??''; $salary=(float)($_POST['sf_salary']??0);
        $uname=trim($_POST['sf_username']??''); $pass=$_POST['sf_password']??'';
        $srole=in_array($_POST['sf_role']??'',['STAFF','ADMIN'])?$_POST['sf_role']:'STAFF';
        if (empty($sname)||empty($sphone)||empty($uname)||empty($pass)){
            $feedbackMsg='Name, phone, username, and password are required.'; $feedbackType='error';
        } else {
            $chk=$pdo->prepare("SELECT USERNAME FROM login WHERE USERNAME=?"); $chk->execute([$uname]);
            if($chk->fetch()){ $feedbackMsg="Username \"$uname\" is already taken."; $feedbackType='error'; }
            else {
                $pdo->prepare("INSERT INTO staff (STAFF_NAME,STAFF_NOPHONE,STAFF_ADDRESS,STAFF_JOINDATE,BASIC_SALARY) VALUES (?,?,?,?,?)")->execute([$sname,$sphone,$saddr,$sjoin?:null,$salary]);
                $sid=$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO login (USERNAME,PASSWORD,ROLE,CUST_ID,STAFF_ID) VALUES (?,?,?,NULL,?)")->execute([$uname,password_hash($pass,PASSWORD_DEFAULT),$srole,$sid]);
                $feedbackMsg="Staff \"$sname\" added with login \"$uname\" ($srole).";
            }
        }
        $activeTab='staff';
    }
    elseif ($action === 'edit_staff') {
        $sid=(int)$_POST['sf_id']; $sname=$_POST['sf_name']??''; $sphone=$_POST['sf_phone']??'';
        $saddr=$_POST['sf_address']??''; $sjoin=$_POST['sf_joindate']??''; $salary=(float)($_POST['sf_salary']??0);
        $newpw=trim($_POST['sf_newpassword']??'');
        if(empty($sname)||empty($sphone)){ $feedbackMsg='Name and phone required.'; $feedbackType='error'; }
        else {
            $pdo->prepare("UPDATE staff SET STAFF_NAME=?,STAFF_NOPHONE=?,STAFF_ADDRESS=?,STAFF_JOINDATE=?,BASIC_SALARY=? WHERE STAFF_ID=?")->execute([$sname,$sphone,$saddr,$sjoin?:null,$salary,$sid]);
            if(!empty($newpw)) $pdo->prepare("UPDATE login SET PASSWORD=? WHERE STAFF_ID=?")->execute([password_hash($newpw,PASSWORD_DEFAULT),$sid]);
            $feedbackMsg="Staff \"$sname\" updated.";
        }
        $activeTab='staff';
    }
    elseif ($action === 'delete_staff') {
        $sid=(int)$_POST['sf_id'];
        try { $pdo->prepare("DELETE FROM login WHERE STAFF_ID=?")->execute([$sid]); $pdo->prepare("DELETE FROM staff WHERE STAFF_ID=?")->execute([$sid]); $feedbackMsg='Staff deleted.'; }
        catch(PDOException $e){ $feedbackMsg='Cannot delete: staff is linked to transactions.'; $feedbackType='error'; }
        $activeTab='staff';
    }

    // ── SUPPLIER CRUD ─────────────────────────────────────
    elseif ($action === 'add_supplier') {
        $sname=trim($_POST['sp_name']??''); $sphone=$_POST['sp_phone']??''; $saddr=$_POST['sp_address']??'';
        if(empty($sname)){ $feedbackMsg='Supplier name required.'; $feedbackType='error'; }
        else { $pdo->prepare("INSERT INTO supplier (SUPP_NAME,SUPP_NOPHONE,SUPP_ADDRESS) VALUES (?,?,?)")->execute([$sname,$sphone,$saddr]); $feedbackMsg="Supplier \"$sname\" added."; }
        $activeTab='suppliers';
    }
    elseif ($action === 'edit_supplier') {
        $spid=(int)$_POST['sp_id']; $sname=trim($_POST['sp_name']??''); $sphone=$_POST['sp_phone']??''; $saddr=$_POST['sp_address']??'';
        if(empty($sname)){ $feedbackMsg='Supplier name required.'; $feedbackType='error'; }
        else { $pdo->prepare("UPDATE supplier SET SUPP_NAME=?,SUPP_NOPHONE=?,SUPP_ADDRESS=? WHERE SUPP_ID=?")->execute([$sname,$sphone,$saddr,$spid]); $feedbackMsg="Supplier updated."; }
        $activeTab='suppliers';
    }
    elseif ($action === 'delete_supplier') {
        $spid=(int)$_POST['sp_id'];
        try { $pdo->prepare("DELETE FROM supplier WHERE SUPP_ID=?")->execute([$spid]); $feedbackMsg='Supplier deleted.'; }
        catch(PDOException $e){ $feedbackMsg='Cannot delete: linked to products.'; $feedbackType='error'; }
        $activeTab='suppliers';
    }

    // ── TRANSACTION: Delete single record ─────────────────
    elseif ($action === 'delete_transaction') {
        $txn_no = (int)($_POST['txn_no'] ?? 0);
        if ($txn_no > 0) {
            // Must delete transaction_item first due to foreign key constraint
            $pdo->prepare("DELETE FROM transaction_item WHERE TRANSACTION_NO = ?")->execute([$txn_no]);
            $pdo->prepare("DELETE FROM transaction WHERE TRANSACTION_NO = ?")->execute([$txn_no]);
            $feedbackMsg = 'Transaction #'.str_pad($txn_no,6,'0',STR_PAD_LEFT).' has been deleted.';
        }
        $activeTab = 'reports';
    }

    // ── TRANSACTION: Delete all Cancelled records ─────────
    elseif ($action === 'clear_cancelled') {
        $stmt = $pdo->query("SELECT TRANSACTION_NO FROM transaction WHERE PAYMENT_STATUS = 'Cancelled'");
        $txns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($txns as $txn_no) {
            $pdo->prepare("DELETE FROM transaction_item WHERE TRANSACTION_NO = ?")->execute([$txn_no]);
            $pdo->prepare("DELETE FROM transaction WHERE TRANSACTION_NO = ?")->execute([$txn_no]);
        }
        $count = count($txns);
        $feedbackMsg = $count > 0
            ? "$count cancelled transaction(s) removed successfully."
            : 'No cancelled transactions found.';
        $activeTab = 'reports';
    }

    // ── TRANSACTION: Wipe ALL transaction records (admin only) ──
    elseif ($action === 'clear_all_transactions') {
        $confirm = trim($_POST['confirm_word'] ?? '');
        if ($confirm === 'DELETE ALL') {
            $pdo->exec("DELETE FROM transaction_item");
            $pdo->exec("DELETE FROM transaction");
            $feedbackMsg = 'All transaction records have been permanently cleared.';
        } else {
            $feedbackMsg  = 'Safety confirmation failed. Type exactly "DELETE ALL" to confirm.';
            $feedbackType = 'error';
        }
        $activeTab = 'reports';
    }

    header("Location: admin_dashboard.php?tab=$activeTab&msg=".urlencode($feedbackMsg)."&type=$feedbackType");
    exit;
}

$feedbackMsg  = isset($_GET['msg'])  ? urldecode($_GET['msg'])  : '';
$feedbackType = $_GET['type'] ?? 'success';

// ════════════════════════════════════════════════════════════
// FETCH DATA
// ════════════════════════════════════════════════════════════
$perPage = 10;

// Dashboard stats
$stat = [
    'orders'   => $pdo->query("SELECT COUNT(*) FROM transaction")->fetchColumn(),
    'revenue'  => $pdo->query("SELECT COALESCE(SUM(TOTAL_AMOUNT),0) FROM transaction WHERE PAYMENT_STATUS!='Cancelled'")->fetchColumn(),
    'products' => $pdo->query("SELECT COUNT(DISTINCT CONCAT(FOOTWEAR_BRAND,'||',FOOTWEAR_NAME)) FROM footwear")->fetchColumn(),
    'customers'=> $pdo->query("SELECT COUNT(*) FROM customer")->fetchColumn(),
    'staff'    => $pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn(),
    'lowstock' => $pdo->query("SELECT COUNT(*) FROM stock WHERE QTY_AVAILABLE<=5")->fetchColumn(),
    'suppliers'=> $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn(),
    'pending'  => $pdo->query("SELECT COUNT(*) FROM transaction WHERE PAYMENT_STATUS IN ('Paid','Processing')")->fetchColumn(),
];

// ── Products: fetch all, group by brand+name ──────────────────
$psearch = trim($_GET['psearch'] ?? '');
$pWhere  = $psearch ? "WHERE f.FOOTWEAR_NAME LIKE ? OR f.FOOTWEAR_BRAND LIKE ?" : "";
$pParams = $psearch ? ["%$psearch%","%$psearch%"] : [];

// CAST(SUBSTRING_INDEX(SIZE,' ',-1) AS DECIMAL(5,1)) extracts the number from
// "UK 7" → 7.0, "UK 10" → 10.0, "UK 4.5" → 4.5 — giving correct numeric order
$pStmt = $pdo->prepare("SELECT f.*,s.QTY_AVAILABLE,sp.SUPP_NAME FROM footwear f JOIN stock s ON f.FOOTWEAR_ID=s.FOOTWEAR_ID LEFT JOIN supplier sp ON f.SUPP_ID=sp.SUPP_ID $pWhere ORDER BY f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME, CAST(SUBSTRING_INDEX(f.SIZE,' ',-1) AS DECIMAL(5,1))");
$pStmt->execute($pParams);
$allProductRows = $pStmt->fetchAll();

$productGroups = [];
foreach ($allProductRows as $p) {
    $key = $p['FOOTWEAR_BRAND'].'||'.$p['FOOTWEAR_NAME'];
    if (!isset($productGroups[$key])) {
        $productGroups[$key] = [
            'brand'    => $p['FOOTWEAR_BRAND'], 'name'  => $p['FOOTWEAR_NAME'],
            'desc'     => $p['DESCRIPTION'],    'image' => $p['IMAGE_URL'],
            'color'    => $p['COLOR'],          'price' => (float)$p['PRICE'],
            'supp_id'  => $p['SUPP_ID'],        'supp_name' => $p['SUPP_NAME'],
            'variants' => [],
        ];
    }
    $productGroups[$key]['variants'][$p['SIZE']] = ['id'=>(int)$p['FOOTWEAR_ID'],'qty'=>(int)$p['QTY_AVAILABLE']];
}
$productGroups = array_values($productGroups);
$pCount  = count($productGroups);
$pPages  = max(1, ceil($pCount/$perPage));
$pPage   = max(1, min((int)($_GET['ppage']??1), $pPages));
$pOffset = ($pPage-1)*$perPage;
$pageProducts = array_slice($productGroups, $pOffset, $perPage);

$suppliers_dd = $pdo->query("SELECT SUPP_ID,SUPP_NAME FROM supplier ORDER BY SUPP_NAME")->fetchAll();

// Staff, customers, suppliers, reports (same as before)
$ssearch=$_GET['ssearch']??''; $sPage=max(1,(int)($_GET['spage']??1)); $sOff=($sPage-1)*$perPage;
$sWhere=$ssearch?"WHERE s.STAFF_NAME LIKE ? OR l.USERNAME LIKE ?":""; $sParams=$ssearch?["%$ssearch%","%$ssearch%"]:[];
$sCnt=(function($pdo,$w,$p){$q=$pdo->prepare("SELECT COUNT(*) FROM staff s LEFT JOIN login l ON s.STAFF_ID=l.STAFF_ID $w");$q->execute($p);return(int)$q->fetchColumn();})($pdo,$sWhere,$sParams);
$sPages=max(1,ceil($sCnt/$perPage));
$sStmt=$pdo->prepare("SELECT s.*,l.USERNAME,l.ROLE FROM staff s LEFT JOIN login l ON s.STAFF_ID=l.STAFF_ID $sWhere ORDER BY s.STAFF_NAME LIMIT $perPage OFFSET $sOff"); $sStmt->execute($sParams); $staffList=$sStmt->fetchAll();

$csearch=$_GET['csearch']??''; $cPage=max(1,(int)($_GET['cpage']??1)); $cOff=($cPage-1)*$perPage;
$cWhere=$csearch?"WHERE c.CUST_NAME LIKE ? OR c.CUST_ID=?":""; $cParams=$csearch?["%$csearch%",(int)$csearch]:[];
$cCnt=(function($pdo,$w,$p){$q=$pdo->prepare("SELECT COUNT(*) FROM customer c $w");$q->execute($p);return(int)$q->fetchColumn();})($pdo,$cWhere,$cParams);
$cPages=max(1,ceil($cCnt/$perPage));
$cStmt=$pdo->prepare("SELECT c.*,l.USERNAME FROM customer c LEFT JOIN login l ON c.CUST_ID=l.CUST_ID $cWhere ORDER BY c.CUST_ID DESC LIMIT $perPage OFFSET $cOff"); $cStmt->execute($cParams); $customers=$cStmt->fetchAll();

$spSearch=$_GET['spsearch']??''; $spPage=max(1,(int)($_GET['sppage']??1)); $spOff=($spPage-1)*$perPage;
$spWhere=$spSearch?"WHERE SUPP_NAME LIKE ?":""; $spParams=$spSearch?["%$spSearch%"]:[];
$spCnt=(function($pdo,$w,$p){$q=$pdo->prepare("SELECT COUNT(*) FROM supplier $w");$q->execute($p);return(int)$q->fetchColumn();})($pdo,$spWhere,$spParams);
$spPages=max(1,ceil($spCnt/$perPage));
$spStmt=$pdo->prepare("SELECT s.*,(SELECT COUNT(*) FROM footwear WHERE SUPP_ID=s.SUPP_ID) as prod_count FROM supplier s $spWhere ORDER BY s.SUPP_NAME LIMIT $perPage OFFSET $spOff"); $spStmt->execute($spParams); $supplierList=$spStmt->fetchAll();

$rPage=max(1,(int)($_GET['rpage']??1)); $rOff=($rPage-1)*$perPage;
$rfrom=$_GET['rfrom']??''; $rto=$_GET['rto']??'';
$rWhere="WHERE 1=1"; $rParams=[];
if($rfrom){$rWhere.=" AND DATE(t.TRANSACTION_DATE)>=?";$rParams[]=$rfrom;}
if($rto){$rWhere.=" AND DATE(t.TRANSACTION_DATE)<=?";$rParams[]=$rto;}
$rCnt=(function($pdo,$w,$p){$q=$pdo->prepare("SELECT COUNT(*) FROM transaction t $w");$q->execute($p);return(int)$q->fetchColumn();})($pdo,$rWhere,$rParams);
$rPages=max(1,ceil($rCnt/$perPage));
$rTotal=(function($pdo,$w,$p){$q=$pdo->prepare("SELECT COALESCE(SUM(TOTAL_AMOUNT),0) FROM transaction t $w AND PAYMENT_STATUS!='Cancelled'");$q->execute($p);return(float)$q->fetchColumn();})($pdo,$rWhere,$rParams);
$rStmt=$pdo->prepare("SELECT t.TRANSACTION_NO,t.TRANSACTION_DATE,t.PAYMENT_METHOD,t.PAYMENT_STATUS,t.TOTAL_AMOUNT,t.DELIVERY_ADDRESS,c.CUST_NAME,st.STAFF_NAME AS PROCESSED_BY FROM transaction t JOIN customer c ON t.CUST_ID=c.CUST_ID LEFT JOIN staff st ON t.STAFF_ID=st.STAFF_ID $rWhere ORDER BY t.TRANSACTION_DATE DESC LIMIT $perPage OFFSET $rOff");
$rStmt->execute($rParams); $reportTxns=$rStmt->fetchAll();

// ── Chart data: Monthly sales comparison ──────────────────────
$currMonth = (int)date('m');
$currYear  = (int)date('Y');
$lastMonthTs   = strtotime('first day of last month');
$lastMonth     = (int)date('m', $lastMonthTs);
$lastYear      = (int)date('Y', $lastMonthTs);
$currMonthName = date('F Y');
$lastMonthName = date('F Y', $lastMonthTs);

// Days in each month
$daysInCurr = (int)date('t');
$daysInLast = (int)date('t', $lastMonthTs);
$chartLabels = range(1, max($daysInCurr, $daysInLast));

// Current month daily revenue
$cStmt = $pdo->prepare("SELECT DAY(TRANSACTION_DATE) as d, COALESCE(SUM(TOTAL_AMOUNT),0) as rev FROM transaction WHERE MONTH(TRANSACTION_DATE)=? AND YEAR(TRANSACTION_DATE)=? AND PAYMENT_STATUS!='Cancelled' GROUP BY DAY(TRANSACTION_DATE)");
$cStmt->execute([$currMonth, $currYear]);
$currRows = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Last month daily revenue
$lStmt = $pdo->prepare("SELECT DAY(TRANSACTION_DATE) as d, COALESCE(SUM(TOTAL_AMOUNT),0) as rev FROM transaction WHERE MONTH(TRANSACTION_DATE)=? AND YEAR(TRANSACTION_DATE)=? AND PAYMENT_STATUS!='Cancelled' GROUP BY DAY(TRANSACTION_DATE)");
$lStmt->execute([$lastMonth, $lastYear]);
$lastRows = $lStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$currMonthData = array_map(fn($d) => (float)($currRows[$d] ?? 0), $chartLabels);
$lastMonthData = array_map(fn($d) => (float)($lastRows[$d] ?? 0), $chartLabels);

// Current month total vs last month total
$currMonthTotal = array_sum($currMonthData);
$lastMonthTotal = array_sum($lastMonthData);
$monthDiff      = $lastMonthTotal > 0 ? round((($currMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100, 1) : 0;

// ── Chart data: Best & worst selling shoes ─────────────────────
$topShoesStmt = $pdo->query("
    SELECT CONCAT(f.FOOTWEAR_BRAND,' ',f.FOOTWEAR_NAME) as shoe,
           SUM(ti.QUANTITY) as total_qty,
           COALESCE(SUM(ti.QUANTITY * f.PRICE),0) as total_rev
    FROM transaction_item ti
    JOIN footwear f  ON ti.FOOTWEAR_ID   = f.FOOTWEAR_ID
    JOIN transaction t ON ti.TRANSACTION_NO = t.TRANSACTION_NO
    WHERE t.PAYMENT_STATUS != 'Cancelled'
    GROUP BY f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME
    ORDER BY total_qty DESC LIMIT 5
");
$topShoes = $topShoesStmt->fetchAll();

$lowShoesStmt = $pdo->query("
    SELECT CONCAT(f.FOOTWEAR_BRAND,' ',f.FOOTWEAR_NAME) as shoe,
           SUM(ti.QUANTITY) as total_qty
    FROM transaction_item ti
    JOIN footwear f  ON ti.FOOTWEAR_ID   = f.FOOTWEAR_ID
    JOIN transaction t ON ti.TRANSACTION_NO = t.TRANSACTION_NO
    WHERE t.PAYMENT_STATUS != 'Cancelled'
    GROUP BY f.FOOTWEAR_BRAND, f.FOOTWEAR_NAME
    ORDER BY total_qty ASC LIMIT 5
");
$lowShoes = $lowShoesStmt->fetchAll();

$statusColors=['Paid'=>'primary','Processing'=>'warning','Shipped'=>'info','Delivered'=>'success','Cancelled'=>'danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CROSSUNDER™ – Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--ad-bg:#0f0f1a;--ad-panel:#16162a;--ad-gold:#C8A96E;--ad-goldh:#a8793e;--ad-border:rgba(200,169,110,.15);--ad-text:#e8e4dc;--ad-muted:rgba(232,228,220,.4);--ad-card:#1e1e35;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Segoe UI',sans-serif;background:#f0f0f4;}
/* Sidebar */
.ad-sidebar{width:240px;height:100vh;background:var(--ad-bg);position:fixed;top:0;left:0;display:flex;flex-direction:column;border-right:1px solid var(--ad-border);z-index:1050;overflow-y:auto;}
.ad-brand{padding:1.5rem 1.4rem 1.2rem;border-bottom:1px solid var(--ad-border);}
.ad-brand .name{color:var(--ad-gold);font-weight:900;letter-spacing:4px;font-size:1.2rem;}
.ad-brand .sub{color:var(--ad-muted);font-size:.6rem;letter-spacing:3px;text-transform:uppercase;margin-top:3px;}
.ad-nav-label{font-size:.6rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--ad-muted);padding:.9rem 1.4rem .3rem;}
.ad-nav-link{display:flex;align-items:center;gap:.7rem;padding:.65rem 1.4rem;color:rgba(232,228,220,.55);text-decoration:none;font-size:.85rem;font-weight:500;border-left:3px solid transparent;transition:all .15s;}
.ad-nav-link:hover{color:var(--ad-text);background:rgba(255,255,255,.03);}
.ad-nav-link.active{color:var(--ad-gold);border-left-color:var(--ad-gold);background:rgba(200,169,110,.06);font-weight:700;}
/* Main */
.ad-main{margin-left:240px;min-height:100vh;}
.ad-topbar{background:#fff;padding:.8rem 1.8rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e8e8e8;position:sticky;top:0;z-index:999;box-shadow:0 1px 8px rgba(0,0,0,.06);}
.ad-topbar .page-label{font-weight:800;letter-spacing:2px;font-size:.95rem;color:#1a1a2e;}
/* Stat cards */
.ad-stat{background:var(--ad-card);border-radius:10px;padding:1.1rem 1.3rem;border:1px solid var(--ad-border);color:var(--ad-text);}
.ad-stat .num{font-size:1.8rem;font-weight:900;line-height:1;color:var(--ad-gold);}
.ad-stat .lbl{font-size:.65rem;letter-spacing:2px;text-transform:uppercase;color:var(--ad-muted);margin-top:.3rem;}
/* Cards */
.ad-card{background:#fff;border:none;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);}
.ad-card .ad-card-hdr{padding:1rem 1.5rem;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;}
.ad-card-title{font-weight:800;letter-spacing:2px;font-size:.88rem;color:#1a1a2e;}
.ad-card-footer{padding:.8rem 1.5rem;background:#fafafa;border-top:1px solid #f0f0f0;border-radius:0 0 12px 12px;display:flex;justify-content:space-between;align-items:center;}
/* Panels */
.ad-panel{display:none;padding:1.5rem 1.8rem;animation:adIn .2s ease;}
.ad-panel.active{display:block;}
@keyframes adIn{from{opacity:0;transform:translateY(5px);}to{opacity:1;transform:translateY(0);}}
/* Buttons */
.btn-ad{background:var(--ad-gold);border:none;color:#fff;font-weight:600;letter-spacing:.5px;}
.btn-ad:hover{background:var(--ad-goldh);color:#fff;}
/* Forms */
.form-control:focus,.form-select:focus{border-color:var(--ad-gold);box-shadow:0 0 0 .2rem rgba(200,169,110,.2);}
.table th{font-size:.72rem;letter-spacing:1px;text-transform:uppercase;color:#aaa;border-bottom:2px solid #f0f0f0;}
/* Modal header */
.modal-header-dark{background:var(--ad-bg);}
.modal-header-dark .modal-title{color:var(--ad-gold);letter-spacing:2px;font-weight:800;}
/* Image preview */
.img-preview{width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid #f0f0f0;}

/* ─── SIZE GRID ─────────────────────────────────────────── */
.size-stock-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.45rem;}
.size-stock-tile{
    border:2px solid #dee2e6;border-radius:8px;
    padding:.55rem .3rem;text-align:center;
    background:#f8f9fa;transition:all .15s;
}
.size-stock-tile.tile-active{border-color:var(--ad-gold);background:rgba(200,169,110,.08);}
.size-tile-top{display:flex;align-items:center;justify-content:center;gap:.25rem;margin-bottom:.35rem;}
.size-tile-label{font-weight:700;font-size:.75rem;cursor:pointer;margin:0;line-height:1;color:#333;}
.size-tile-input{font-size:.78rem;text-align:center;padding:.2rem .1rem;border:1px solid #ced4da;border-radius:4px;width:100%;}
.size-tile-input:disabled{background:#e9ecef;color:#adb5bd;}
/* Size badges in table */
.size-badge-grid{display:flex;flex-wrap:wrap;gap:.2rem;max-width:260px;}
.sz-chip{font-size:.68rem;padding:.18rem .4rem;border-radius:4px;font-weight:600;white-space:nowrap;}

/* Pagination */
.page-link{color:var(--ad-gold);}
.page-item.active .page-link{background:var(--ad-gold);border-color:var(--ad-gold);}

/* Dashboard grid */
.dash-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;}
@media(max-width:900px){.dash-grid{grid-template-columns:repeat(2,1fr);}.ad-sidebar{display:none;}.ad-main{margin-left:0;}}
</style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ══════════════════════════════════ -->
<div class="ad-sidebar">
    <div class="ad-brand">
        <div class="name">CROSSUNDER™</div>
        <div class="sub">Admin Panel</div>
        <div class="d-flex align-items-center gap-2 mt-2">
            <span style="background:rgba(200,169,110,.15);color:var(--ad-gold);font-size:.6rem;letter-spacing:2px;padding:.25rem .6rem;border-radius:20px;border:1px solid var(--ad-border);">
                <i class="bi bi-shield-check me-1"></i>ADMIN
            </span>
            <small style="color:var(--ad-muted);font-size:.72rem;"><?= htmlspecialchars($user_name) ?></small>
        </div>
    </div>
    <nav style="flex:1;padding:.5rem 0;">
        <div class="ad-nav-label">Overview</div>
        <a href="#" class="ad-nav-link <?= $activeTab==='dashboard'?'active':'' ?>" onclick="adTab('dashboard')"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="ad-nav-label">People</div>
        <a href="#" class="ad-nav-link <?= $activeTab==='staff'?'active':'' ?>" onclick="adTab('staff')"><i class="bi bi-person-badge"></i>Staff Management<span class="badge bg-secondary ms-auto"><?= $stat['staff'] ?></span></a>
        <a href="#" class="ad-nav-link <?= $activeTab==='customers'?'active':'' ?>" onclick="adTab('customers')"><i class="bi bi-people"></i>Customers<span class="badge bg-secondary ms-auto"><?= $stat['customers'] ?></span></a>
        <div class="ad-nav-label">Inventory</div>
        <a href="#" class="ad-nav-link <?= $activeTab==='inventory'?'active':'' ?>" onclick="adTab('inventory')"><i class="bi bi-box-seam"></i>Products & Stock<?php if($stat['lowstock']>0): ?><span class="badge bg-danger ms-auto"><?= $stat['lowstock'] ?></span><?php endif; ?></a>
        <a href="#" class="ad-nav-link <?= $activeTab==='suppliers'?'active':'' ?>" onclick="adTab('suppliers')"><i class="bi bi-truck"></i>Suppliers<span class="badge bg-secondary ms-auto"><?= $stat['suppliers'] ?></span></a>
        <div class="ad-nav-label">Reports</div>
        <a href="#" class="ad-nav-link <?= $activeTab==='reports'?'active':'' ?>" onclick="adTab('reports')"><i class="bi bi-bar-chart-line"></i>Sales Reports<?php if($stat['pending']>0): ?><span class="badge bg-warning text-dark ms-auto"><?= $stat['pending'] ?></span><?php endif; ?></a>
        <a href="staff_dashboard.php" class="ad-nav-link"><i class="bi bi-person-workspace"></i>Staff View</a>
    </nav>
    <div style="padding:1rem 1.4rem;border-top:1px solid var(--ad-border);">
        <a href="logout.php" class="ad-nav-link" style="color:#e74c3c;border-left-color:transparent;"><i class="bi bi-box-arrow-right"></i>Logout</a>
    </div>
</div>

<!-- ═══════════════ MAIN AREA ════════════════════════════════ -->
<div class="ad-main">
<div class="ad-topbar">
    <div><span class="page-label" id="adPageLabel">DASHBOARD</span><small class="text-muted ms-2" style="font-size:.75rem;">CROSSUNDER™ Admin</small></div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge" style="background:var(--ad-gold);color:#fff;letter-spacing:1px;font-size:.7rem;">ADMINISTRATOR</span>
        <small class="text-muted"><?= htmlspecialchars($user_name) ?></small>
    </div>
</div>

<!-- ═══════════ DASHBOARD ════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='dashboard'?'active':'' ?>" id="ad-dashboard">
    <div class="dash-grid mb-4">
        <?php $stats=[['orders','receipt','Total Orders'],['revenue','cash-stack','Revenue (RM)'],['products','box-seam','Shoe Models'],['customers','people','Customers'],['staff','person-badge','Staff'],['suppliers','truck','Suppliers'],['lowstock','exclamation-triangle','Low Stock'],['pending','hourglass-split','Pending']];
        foreach($stats as $s): ?>
        <div class="ad-stat">
            <div class="num"><?= $s[0]==='revenue'?'RM '.number_format($stat[$s[0]],0):$stat[$s[0]] ?></div>
            <div class="lbl"><i class="bi bi-<?= $s[1] ?> me-1"></i><?= $s[2] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="ad-card">
        <div class="ad-card-hdr"><span class="ad-card-title"><i class="bi bi-receipt me-2" style="color:var(--ad-gold);"></i>RECENT ORDERS</span><a href="#" class="btn btn-sm btn-ad" onclick="adTab('reports')">View All</a></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3">Order #</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($pdo->query("SELECT t.TRANSACTION_NO,t.TRANSACTION_DATE,t.PAYMENT_STATUS,t.TOTAL_AMOUNT,c.CUST_NAME FROM transaction t JOIN customer c ON t.CUST_ID=c.CUST_ID ORDER BY t.TRANSACTION_DATE DESC LIMIT 8")->fetchAll() as $rt): ?>
                <tr>
                    <td class="ps-3 fw-bold" style="color:var(--ad-goldh);">#<?= str_pad($rt['TRANSACTION_NO'],6,'0',STR_PAD_LEFT) ?></td>
                    <td class="small"><?= date('d M y H:i',strtotime($rt['TRANSACTION_DATE'])) ?></td>
                    <td><?= htmlspecialchars($rt['CUST_NAME']) ?></td>
                    <td class="fw-bold" style="color:#27ae60;">RM <?= number_format($rt['TOTAL_AMOUNT'],2) ?></td>
                    <td><span class="badge bg-<?= $statusColors[$rt['PAYMENT_STATUS']]??'secondary' ?>"><?= htmlspecialchars($rt['PAYMENT_STATUS']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════ INVENTORY ════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='inventory'?'active':'' ?>" id="ad-inventory">
    <div class="ad-card">
        <div class="ad-card-hdr">
            <span class="ad-card-title"><i class="bi bi-box-seam me-2" style="color:var(--ad-gold);"></i>PRODUCTS & STOCK — Grouped by Model</span>
            <div class="d-flex gap-2 flex-wrap">
                <form method="GET" class="d-flex gap-1">
                    <input type="hidden" name="tab" value="inventory">
                    <input type="text" name="psearch" class="form-control form-control-sm" placeholder="Search brand/name…" value="<?= htmlspecialchars($psearch) ?>" style="width:180px;">
                    <button class="btn btn-ad btn-sm"><i class="bi bi-search"></i></button>
                    <?php if($psearch): ?><a href="?tab=inventory" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?>
                </form>
                <button class="btn btn-ad btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Shoe Model
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3" style="width:70px;">Image</th>
                        <th>Brand</th>
                        <th>Name</th>
                        <th>Color</th>
                        <th class="text-end">Price</th>
                        <th>Sizes & Stock</th>
                        <th class="text-center">Total Qty</th>
                        <th>Supplier</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($pageProducts)): ?>
                <tr><td colspan="9" class="text-center py-5 text-muted">No products found.</td></tr>
                <?php else: foreach($pageProducts as $g):
                    $totalQty  = array_sum(array_column($g['variants'],'qty'));
                    $groupJson = htmlspecialchars(json_encode($g), ENT_QUOTES);
                ?>
                <tr>
                    <td class="ps-3">
                        <img src="images/<?= htmlspecialchars($g['image']) ?>"
                             style="width:55px;height:55px;object-fit:cover;border-radius:8px;"
                             onerror="this.src='https://placehold.co/55x55/0f0f1a/C8A96E?text=<?= urlencode($g['brand'][0]) ?>'">
                    </td>
                    <td><span class="badge" style="background:#0f0f1a;color:#C8A96E;"><?= htmlspecialchars($g['brand']) ?></span></td>
                    <td class="fw-semibold"><?= htmlspecialchars($g['name']) ?><br><small class="text-muted fw-normal"><?= htmlspecialchars(substr($g['color'],0,20)) ?></small></td>
                    <td class="small text-muted"><?= htmlspecialchars($g['color']) ?></td>
                    <td class="text-end fw-bold">RM <?= number_format($g['price'],2) ?></td>
                    <td>
                        <!-- Size stock chips -->
                        <div class="size-badge-grid">
                            <?php foreach($g['variants'] as $size => $v):
                                $chipClass = $v['qty']==0 ? 'bg-danger' : ($v['qty']<=5 ? 'bg-warning text-dark' : 'bg-success');
                            ?>
                            <span class="sz-chip <?= $chipClass ?>" title="<?= htmlspecialchars($size) ?>">
                                <?= htmlspecialchars($size) ?>: <?= $v['qty'] ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="text-center fw-bold <?= $totalQty==0?'text-danger':($totalQty<=10?'text-warning':'text-success') ?>"><?= $totalQty ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($g['supp_name']??'—') ?></td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditProduct(<?= $groupJson ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" id="delForm_<?= md5($g['brand'].$g['name']) ?>"
                              onsubmit="return confirmGroupDelete('<?= addslashes($g['brand']) ?>','<?= addslashes($g['name']) ?>', this)">
                            <input type="hidden" name="action"       value="delete_product">
                            <input type="hidden" name="fp_orig_brand" value="<?= htmlspecialchars($g['brand']) ?>">
                            <input type="hidden" name="fp_orig_name"  value="<?= htmlspecialchars($g['name']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ad-card-footer">
            <small class="text-muted"><?= $pCount ?> shoe model(s) | <?= $stat['lowstock'] ?> size(s) low/out of stock</small>
            <?= paginate($pPage,$pPages,'?tab=inventory&psearch='.urlencode($psearch).'&ppage=x') ?>
        </div>
    </div>
</div>

<!-- ═══════════ STAFF ════════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='staff'?'active':'' ?>" id="ad-staff">
    <div class="ad-card">
        <div class="ad-card-hdr">
            <span class="ad-card-title"><i class="bi bi-person-badge me-2" style="color:var(--ad-gold);"></i>STAFF MANAGEMENT</span>
            <div class="d-flex gap-2 flex-wrap">
                <form method="GET" class="d-flex gap-1"><input type="hidden" name="tab" value="staff"><input type="text" name="ssearch" class="form-control form-control-sm" placeholder="Search name/username…" value="<?= htmlspecialchars($ssearch) ?>" style="width:180px;"><button class="btn btn-ad btn-sm"><i class="bi bi-search"></i></button><?php if($ssearch): ?><a href="?tab=staff" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?></form>
                <button class="btn btn-ad btn-sm" data-bs-toggle="modal" data-bs-target="#addStaffModal"><i class="bi bi-person-plus me-1"></i>Add Staff</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3">ID</th><th>Name</th><th>Phone</th><th>Address</th><th>Join Date</th><th>Salary (RM)</th><th>Username</th><th>Role</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if(empty($staffList)): ?><tr><td colspan="9" class="text-center py-5 text-muted">No staff found.</td></tr><?php else: foreach($staffList as $sf): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $sf['STAFF_ID'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($sf['STAFF_NAME']) ?></td>
                    <td class="small"><?= htmlspecialchars($sf['STAFF_NOPHONE']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($sf['STAFF_ADDRESS']??'—') ?></td>
                    <td class="small"><?= $sf['STAFF_JOINDATE']?date('d M Y',strtotime($sf['STAFF_JOINDATE'])):'—' ?></td>
                    <td><?= $sf['BASIC_SALARY']?number_format($sf['BASIC_SALARY'],2):'—' ?></td>
                    <td><code class="small"><?= htmlspecialchars($sf['USERNAME']??'—') ?></code></td>
                    <td><span class="badge bg-<?= $sf['ROLE']==='ADMIN'?'danger':'secondary' ?>"><?= htmlspecialchars($sf['ROLE']??'—') ?></span></td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditStaff(<?= htmlspecialchars(json_encode($sf)) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this staff member?')"><input type="hidden" name="action" value="delete_staff"><input type="hidden" name="sf_id" value="<?= $sf['STAFF_ID'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ad-card-footer"><small class="text-muted"><?= $sCnt ?> staff</small><?= paginate($sPage,$sPages,'?tab=staff&ssearch='.urlencode($ssearch).'&spage=x') ?></div>
    </div>
</div>

<!-- ═══════════ CUSTOMERS ════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='customers'?'active':'' ?>" id="ad-customers">
    <div class="ad-card">
        <div class="ad-card-hdr">
            <span class="ad-card-title"><i class="bi bi-people me-2" style="color:var(--ad-gold);"></i>CUSTOMER RECORDS</span>
            <form method="GET" class="d-flex gap-1"><input type="hidden" name="tab" value="customers"><input type="text" name="csearch" class="form-control form-control-sm" placeholder="Name or ID…" value="<?= htmlspecialchars($csearch) ?>" style="width:200px;"><button class="btn btn-ad btn-sm"><i class="bi bi-search"></i></button><?php if($csearch): ?><a href="?tab=customers" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?></form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3">ID</th><th>Name</th><th>Phone</th><th>Username</th><th>Orders</th></tr></thead>
                <tbody>
                <?php if(empty($customers)): ?><tr><td colspan="5" class="text-center py-5 text-muted">No customers found.</td></tr><?php else: foreach($customers as $cu): $oc=$pdo->prepare("SELECT COUNT(*) FROM transaction WHERE CUST_ID=?"); $oc->execute([$cu['CUST_ID']]); $oCount=$oc->fetchColumn(); ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $cu['CUST_ID'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($cu['CUST_NAME']) ?></td>
                    <td><?= htmlspecialchars($cu['CUST_NOPHONE']) ?></td>
                    <td><code class="small"><?= htmlspecialchars($cu['USERNAME']??'—') ?></code></td>
                    <td><span class="badge bg-<?= $oCount>0?'success':'secondary' ?>"><?= $oCount ?> order(s)</span></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ad-card-footer"><small class="text-muted"><?= $cCnt ?> customer(s)</small><?= paginate($cPage,$cPages,'?tab=customers&csearch='.urlencode($csearch).'&cpage=x') ?></div>
    </div>
</div>

<!-- ═══════════ SUPPLIERS ════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='suppliers'?'active':'' ?>" id="ad-suppliers">
    <div class="ad-card">
        <div class="ad-card-hdr">
            <span class="ad-card-title"><i class="bi bi-truck me-2" style="color:var(--ad-gold);"></i>SUPPLIER MANAGEMENT</span>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-1"><input type="hidden" name="tab" value="suppliers"><input type="text" name="spsearch" class="form-control form-control-sm" placeholder="Search supplier…" value="<?= htmlspecialchars($spSearch) ?>" style="width:180px;"><button class="btn btn-ad btn-sm"><i class="bi bi-search"></i></button><?php if($spSearch): ?><a href="?tab=suppliers" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?></form>
                <button class="btn btn-ad btn-sm" data-bs-toggle="modal" data-bs-target="#addSupplierModal"><i class="bi bi-plus-lg me-1"></i>Add Supplier</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3">ID</th><th>Supplier Name</th><th>Phone</th><th>Address</th><th class="text-center">Products</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if(empty($supplierList)): ?><tr><td colspan="6" class="text-center py-5 text-muted">No suppliers found.</td></tr><?php else: foreach($supplierList as $sp): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $sp['SUPP_ID'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($sp['SUPP_NAME']) ?></td>
                    <td><?= htmlspecialchars($sp['SUPP_NOPHONE']??'—') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($sp['SUPP_ADDRESS']??'—') ?></td>
                    <td class="text-center"><span class="badge bg-secondary"><?= $sp['prod_count'] ?></span></td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditSupplier(<?= htmlspecialchars(json_encode($sp)) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete? Will fail if linked to products.')"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="sp_id" value="<?= $sp['SUPP_ID'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ad-card-footer"><small class="text-muted"><?= $spCnt ?> supplier(s)</small><?= paginate($spPage,$spPages,'?tab=suppliers&spsearch='.urlencode($spSearch).'&sppage=x') ?></div>
    </div>
</div>

<!-- ═══════════ REPORTS ══════════════════════════════════════ -->
<div class="ad-panel <?= $activeTab==='reports'?'active':'' ?>" id="ad-reports">
    <div class="ad-card">
        <div class="ad-card-hdr">
            <span class="ad-card-title"><i class="bi bi-bar-chart-line me-2" style="color:var(--ad-gold);"></i>SALES REPORTS</span>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <!-- Date filter -->
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="hidden" name="tab" value="reports">
                    <label class="small fw-semibold mb-0">From:</label>
                    <input type="date" name="rfrom" class="form-control form-control-sm" style="width:145px;" value="<?= htmlspecialchars($rfrom) ?>">
                    <label class="small fw-semibold mb-0">To:</label>
                    <input type="date" name="rto" class="form-control form-control-sm" style="width:145px;" value="<?= htmlspecialchars($rto) ?>">
                    <button class="btn btn-ad btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <?php if($rfrom||$rto): ?><a href="?tab=reports" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?>
                </form>
                <!-- Clear Cancelled button -->
                <form method="POST" onsubmit="return confirm('Delete ALL cancelled transactions? This cannot be undone.')">
                    <input type="hidden" name="action" value="clear_cancelled">
                    <button type="submit" class="btn btn-sm btn-outline-warning fw-semibold">
                        <i class="bi bi-trash2 me-1"></i>Clear Cancelled
                    </button>
                </form>
                <!-- Clear ALL button -->
                <button class="btn btn-sm btn-outline-danger fw-semibold"
                        data-bs-toggle="modal" data-bs-target="#clearAllModal">
                    <i class="bi bi-nuclear me-1"></i>Clear All Records
                </button>
            </div>
        </div>
        <div class="d-flex gap-4 px-4 py-3" style="background:#fafafa;border-bottom:1px solid #f0f0f0;">
            <div><div class="fw-bold" style="color:var(--ad-goldh);font-size:1.4rem;">RM <?= number_format($rTotal,2) ?></div><small class="text-muted">Filtered Revenue</small></div>
            <div class="vr"></div>
            <div><div class="fw-bold" style="font-size:1.4rem;"><?= $rCnt ?></div><small class="text-muted">Transactions</small></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3">Order #</th><th>Date</th><th>Customer</th><th>Payment</th><th class="text-end">Amount</th><th>Status</th><th>By</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php foreach($reportTxns as $rt): ?>
                <tr>
                    <td class="ps-3 fw-bold" style="color:var(--ad-goldh);">#<?= str_pad($rt['TRANSACTION_NO'],6,'0',STR_PAD_LEFT) ?></td>
                    <td class="small"><?= date('d M y H:i',strtotime($rt['TRANSACTION_DATE'])) ?></td>
                    <td><?= htmlspecialchars($rt['CUST_NAME']) ?></td>
                    <td class="small"><?= htmlspecialchars($rt['PAYMENT_METHOD']) ?></td>
                    <td class="text-end fw-bold" style="color:#27ae60;">RM <?= number_format($rt['TOTAL_AMOUNT'],2) ?></td>
                    <td><span class="badge bg-<?= $statusColors[$rt['PAYMENT_STATUS']]??'secondary' ?>"><?= htmlspecialchars($rt['PAYMENT_STATUS']) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars($rt['PROCESSED_BY']??'—') ?></td>
                    <td class="text-center">
                        <form method="POST" onsubmit="return confirm('Delete transaction #<?= str_pad($rt['TRANSACTION_NO'],6,'0',STR_PAD_LEFT) ?>? This cannot be undone.')">
                            <input type="hidden" name="action"  value="delete_transaction">
                            <input type="hidden" name="txn_no"  value="<?= $rt['TRANSACTION_NO'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this transaction">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ad-card-footer"><small class="text-muted"><?= $rCnt ?> record(s)</small><?= paginate($rPage,$rPages,'?tab=reports&rfrom='.urlencode($rfrom).'&rto='.urlencode($rto).'&rpage=x') ?></div>
    </div>
</div>
</div><!-- /ad-main -->

<!-- ════════════════════════ MODALS ══════════════════════════ -->

<!-- ADD PRODUCT (with size grid) -->
<div class="modal fade" id="addProductModal" tabindex="-1">
<div class="modal-dialog modal-xl"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>ADD NEW SHOE MODEL</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_product">
        <div class="modal-body">
            <!-- Row 1: Common fields -->
            <div class="row g-3 mb-3">
                <div class="col-md-3"><label class="form-label small fw-semibold">Brand *</label><input type="text" name="fp_brand" class="form-control" placeholder="e.g. Nike" required></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Shoe Name *</label><input type="text" name="fp_name" class="form-control" placeholder="e.g. Air Max 270" required></div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Color</label><input type="text" name="fp_color" class="form-control" placeholder="e.g. Black"></div>
                <div class="col-md-3"><label class="form-label small fw-semibold">Price (RM) *</label><input type="number" name="fp_price" class="form-control" step="0.01" min="0.01" required></div>
                <div class="col-md-5"><label class="form-label small fw-semibold">Description</label><input type="text" name="fp_desc" class="form-control" placeholder="Short description"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Supplier *</label><select name="fp_supp" class="form-select" required><option value="">Select supplier</option><?php foreach($suppliers_dd as $sp): ?><option value="<?= $sp['SUPP_ID'] ?>"><?= htmlspecialchars($sp['SUPP_NAME']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Image Upload</label>
                    <input type="file" name="fp_image" class="form-control form-control-sm" accept="image/*" onchange="previewImg(this,'addImgPrev')">
                    <input type="text" name="fp_img" class="form-control form-control-sm mt-1" placeholder="Or type filename: shoe.jpg">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <img id="addImgPrev" src="https://placehold.co/70x70/0f0f1a/C8A96E?text=IMG" style="width:70px;height:70px;object-fit:cover;border-radius:8px;">
                </div>
            </div>

            <hr class="my-2">

            <!-- Gender selector -->
            <div class="mb-3">
                <label class="form-label small fw-semibold"><i class="bi bi-rulers me-1"></i>Shoe Category & Sizes *</label>
                <div class="d-flex gap-4 mb-3">
                    <div class="form-check">
                        <input type="radio" name="add_gender" id="add_gm" value="male" class="form-check-input" checked onchange="toggleSizeGrid('male','add')">
                        <label class="form-check-label fw-semibold" for="add_gm"><i class="bi bi-gender-male me-1 text-primary"></i>Male — UK 7 to UK 12</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" name="add_gender" id="add_gf" value="female" class="form-check-input" onchange="toggleSizeGrid('female','add')">
                        <label class="form-check-label fw-semibold" for="add_gf"><i class="bi bi-gender-female me-1 text-danger"></i>Female — UK 4 to UK 6.5</label>
                    </div>
                </div>

                <!-- Male size grid -->
                <div id="add_male_grid">
                    <small class="text-muted d-block mb-2"><i class="bi bi-info-circle me-1"></i>Tick the sizes available for this shoe. Enter stock quantity for each size.</small>
                    <div class="size-stock-grid">
                        <?php foreach($MALE_SIZES as $sz):
                            $key = str_replace([' ','.'],'_',$sz); ?>
                        <div class="size-stock-tile tile-active" id="add_tile_<?= $key ?>">
                            <div class="size-tile-top">
                                <input type="checkbox" name="size_enabled[<?= $sz ?>]" id="add_sz_<?= $key ?>" class="sz-check form-check-input" checked onchange="toggleSizeTile(this,'add')">
                                <label for="add_sz_<?= $key ?>" class="size-tile-label"><?= $sz ?></label>
                            </div>
                            <input type="number" name="size_qty[<?= $sz ?>]" id="add_sqty_<?= $key ?>" min="0" value="0" class="size-tile-input">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Female size grid (hidden) -->
                <div id="add_female_grid" style="display:none;">
                    <small class="text-muted d-block mb-2"><i class="bi bi-info-circle me-1"></i>Tick the sizes available for this shoe. Enter stock quantity for each size.</small>
                    <div class="size-stock-grid">
                        <?php foreach($FEMALE_SIZES as $sz):
                            $key = str_replace([' ','.'],'_',$sz); ?>
                        <div class="size-stock-tile tile-active" id="add_tile_<?= $key ?>">
                            <div class="size-tile-top">
                                <input type="checkbox" name="size_enabled[<?= $sz ?>]" id="add_sz_<?= $key ?>" class="sz-check form-check-input" checked onchange="toggleSizeTile(this,'add')">
                                <label for="add_sz_<?= $key ?>" class="size-tile-label"><?= $sz ?></label>
                            </div>
                            <input type="number" name="size_qty[<?= $sz ?>]" id="add_sqty_<?= $key ?>" min="0" value="0" class="size-tile-input">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-plus-lg me-1"></i>Add Shoe Model</button></div>
    </form>
</div></div></div>

<!-- EDIT PRODUCT (with size grid) -->
<div class="modal fade" id="editProductModal" tabindex="-1">
<div class="modal-dialog modal-xl"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>EDIT SHOE MODEL</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action"       value="edit_product">
        <input type="hidden" name="fp_orig_brand" id="e_fp_orig_brand">
        <input type="hidden" name="fp_orig_name"  id="e_fp_orig_name">
        <div class="modal-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><label class="form-label small fw-semibold">Brand *</label><input type="text" name="fp_brand" id="e_fp_brand" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Shoe Name *</label><input type="text" name="fp_name" id="e_fp_name" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Color</label><input type="text" name="fp_color" id="e_fp_color" class="form-control"></div>
                <div class="col-md-3"><label class="form-label small fw-semibold">Price (RM) *</label><input type="number" name="fp_price" id="e_fp_price" class="form-control" step="0.01" min="0.01" required></div>
                <div class="col-md-5"><label class="form-label small fw-semibold">Description</label><input type="text" name="fp_desc" id="e_fp_desc" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Supplier *</label><select name="fp_supp" id="e_fp_supp" class="form-select" required><option value="">Select supplier</option><?php foreach($suppliers_dd as $sp): ?><option value="<?= $sp['SUPP_ID'] ?>"><?= htmlspecialchars($sp['SUPP_NAME']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label small fw-semibold">Replace Image</label><input type="file" name="fp_image" class="form-control form-control-sm" accept="image/*" onchange="previewImg(this,'editImgPrev')"><input type="text" name="fp_img" id="e_fp_img" class="form-control form-control-sm mt-1" placeholder="Keep existing filename"></div>
                <div class="col-md-1 d-flex align-items-end"><img id="editImgPrev" src="" style="width:70px;height:70px;object-fit:cover;border-radius:8px;" onerror="this.src='https://placehold.co/70x70/0f0f1a/C8A96E?text=IMG'"></div>
            </div>
            <hr class="my-2">
            <div class="mb-3">
                <label class="form-label small fw-semibold"><i class="bi bi-rulers me-1"></i>Size Stock Management</label>
                <div class="d-flex gap-4 mb-3">
                    <div class="form-check"><input type="radio" name="edit_gender" id="edit_gm" value="male" class="form-check-input" checked onchange="toggleSizeGrid('male','edit')"><label class="form-check-label fw-semibold" for="edit_gm"><i class="bi bi-gender-male me-1 text-primary"></i>Male — UK 7 to UK 12</label></div>
                    <div class="form-check"><input type="radio" name="edit_gender" id="edit_gf" value="female" class="form-check-input" onchange="toggleSizeGrid('female','edit')"><label class="form-check-label fw-semibold" for="edit_gf"><i class="bi bi-gender-female me-1 text-danger"></i>Female — UK 4 to UK 6.5</label></div>
                </div>
                <div class="p-2 rounded mb-3" style="background:#fff8e1;border:1px solid #ffe082;font-size:.78rem;color:#795548;">
                    <i class="bi bi-info-circle me-1"></i><strong>How it works:</strong> Checked sizes are saved/updated. Unchecking a UK size removes that variant (if not in orders). Non-UK sizes (e.g. legacy EU sizes) are never auto-deleted.
                </div>
                <!-- Male size grid edit -->
                <div id="edit_male_grid">
                    <div class="size-stock-grid">
                        <?php foreach($MALE_SIZES as $sz):
                            $key = str_replace([' ','.'],'_',$sz); ?>
                        <div class="size-stock-tile" id="edit_tile_<?= $key ?>">
                            <div class="size-tile-top">
                                <input type="checkbox" name="size_enabled[<?= $sz ?>]" id="edit_sz_<?= $key ?>" class="sz-check form-check-input" onchange="toggleSizeTile(this,'edit')">
                                <label for="edit_sz_<?= $key ?>" class="size-tile-label"><?= $sz ?></label>
                            </div>
                            <input type="number" name="size_qty[<?= $sz ?>]" id="edit_sqty_<?= $key ?>" min="0" value="0" class="size-tile-input" disabled>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Female size grid edit (hidden) -->
                <div id="edit_female_grid" style="display:none;">
                    <div class="size-stock-grid">
                        <?php foreach($FEMALE_SIZES as $sz):
                            $key = str_replace([' ','.'],'_',$sz); ?>
                        <div class="size-stock-tile" id="edit_tile_<?= $key ?>">
                            <div class="size-tile-top">
                                <input type="checkbox" name="size_enabled[<?= $sz ?>]" id="edit_sz_<?= $key ?>" class="sz-check form-check-input" onchange="toggleSizeTile(this,'edit')">
                                <label for="edit_sz_<?= $key ?>" class="size-tile-label"><?= $sz ?></label>
                            </div>
                            <input type="number" name="size_qty[<?= $sz ?>]" id="edit_sqty_<?= $key ?>" min="0" value="0" class="size-tile-input" disabled>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-check-lg me-1"></i>Save Changes</button></div>
    </form>
</div></div></div>

<!-- ADD STAFF -->
<div class="modal fade" id="addStaffModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>ADD STAFF MEMBER</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="add_staff"><div class="modal-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label small fw-semibold">Full Name *</label><input type="text" name="sf_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Phone *</label><input type="text" name="sf_phone" class="form-control" required></div>
        <div class="col-12"><label class="form-label small fw-semibold">Address</label><input type="text" name="sf_address" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Join Date</label><input type="date" name="sf_joindate" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Salary (RM)</label><input type="number" name="sf_salary" class="form-control" step="0.01" min="0" placeholder="0.00"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Role</label><select name="sf_role" class="form-select"><option value="STAFF">STAFF</option><option value="ADMIN">ADMIN</option></select></div>
        <hr class="my-1">
        <div class="col-md-6"><label class="form-label small fw-semibold">Login Username *</label><input type="text" name="sf_username" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Password *</label><input type="password" name="sf_password" class="form-control" required></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-person-plus me-1"></i>Add Staff</button></div>
    </form>
</div></div></div>

<!-- EDIT STAFF -->
<div class="modal fade" id="editStaffModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>EDIT STAFF</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="edit_staff"><input type="hidden" name="sf_id" id="e_sf_id"><div class="modal-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label small fw-semibold">Full Name *</label><input type="text" name="sf_name" id="e_sf_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Phone *</label><input type="text" name="sf_phone" id="e_sf_phone" class="form-control" required></div>
        <div class="col-12"><label class="form-label small fw-semibold">Address</label><input type="text" name="sf_address" id="e_sf_address" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Join Date</label><input type="date" name="sf_joindate" id="e_sf_joindate" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Salary (RM)</label><input type="number" name="sf_salary" id="e_sf_salary" class="form-control" step="0.01" min="0"></div>
        <div class="col-12"><label class="form-label small fw-semibold">New Password <small class="text-muted fw-normal">(blank = keep current)</small></label><input type="password" name="sf_newpassword" class="form-control" placeholder="Leave blank to keep unchanged"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-check-lg me-1"></i>Save</button></div>
    </form>
</div></div></div>

<!-- ADD SUPPLIER -->
<div class="modal fade" id="addSupplierModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-truck me-2"></i>ADD SUPPLIER</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="add_supplier"><div class="modal-body"><div class="row g-3">
        <div class="col-12"><label class="form-label small fw-semibold">Supplier Name *</label><input type="text" name="sp_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="sp_phone" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Address</label><input type="text" name="sp_address" class="form-control"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-plus-lg me-1"></i>Add Supplier</button></div>
    </form>
</div></div></div>

<!-- EDIT SUPPLIER -->
<div class="modal fade" id="editSupplierModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header modal-header-dark"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>EDIT SUPPLIER</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="edit_supplier"><input type="hidden" name="sp_id" id="e_sp_id"><div class="modal-body"><div class="row g-3">
        <div class="col-12"><label class="form-label small fw-semibold">Supplier Name *</label><input type="text" name="sp_name" id="e_sp_name" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Phone</label><input type="text" name="sp_phone" id="e_sp_phone" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Address</label><input type="text" name="sp_address" id="e_sp_address" class="form-control"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-ad"><i class="bi bi-check-lg me-1"></i>Save</button></div>
    </form>
</div></div></div>

<!-- CLEAR ALL TRANSACTIONS — Safety Modal -->
<div class="modal fade" id="clearAllModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#c0392b;">
        <h5 class="modal-title text-white fw-bold letter-spacing-1">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>CLEAR ALL TRANSACTION RECORDS
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="clear_all_transactions">
        <div class="modal-body">
            <!-- Warning box -->
            <div class="p-3 rounded mb-4" style="background:#fff5f5; border:1px solid #ffcccc;">
                <p class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-circle me-1"></i>This will permanently delete:</p>
                <ul class="mb-0 small text-danger">
                    <li>ALL <?= $pdo->query("SELECT COUNT(*) FROM transaction")->fetchColumn() ?> transaction record(s)</li>
                    <li>ALL <?= $pdo->query("SELECT COUNT(*) FROM transaction_item")->fetchColumn() ?> transaction item record(s)</li>
                </ul>
                <p class="small text-muted mt-2 mb-0">This action <strong>cannot be undone</strong>. Use this only to clear test/dummy data.</p>
            </div>
            <!-- Safety confirmation word -->
            <div class="mb-3">
                <label class="form-label fw-semibold small">
                    Type <code class="text-danger">DELETE ALL</code> to confirm:
                </label>
                <input type="text" name="confirm_word" class="form-control"
                       placeholder="DELETE ALL" autocomplete="off" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger fw-bold">
                <i class="bi bi-trash3 me-1"></i>Permanently Delete All
            </button>
        </div>
    </form>
</div></div></div>

<?php if($feedbackMsg): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'<?= $feedbackType==='success'?'success':'error' ?>',title:'<?= $feedbackType==='success'?'Done!':'Error' ?>',text:'<?= addslashes($feedbackMsg) ?>',toast:true,position:'top-end',showConfirmButton:false,timer:4500,timerProgressBar:true}));</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Size constants (from PHP) ─────────────────────────────────
const MALE_SIZES   = <?= json_encode($MALE_SIZES) ?>;
const FEMALE_SIZES = <?= json_encode($FEMALE_SIZES) ?>;
const ALL_STD_SIZES = [...MALE_SIZES, ...FEMALE_SIZES];

function sizeKey(s) { return s.replace(/ /g,'_').replace(/\./g,'_'); }

// ── Toggle size tile checked/unchecked ────────────────────────
function toggleSizeTile(checkbox, prefix) {
    const tile = document.getElementById((prefix||'add')+'_tile_'+sizeKey(
        checkbox.name.replace('size_enabled[','').replace(']','')
    ));
    const qty = checkbox.closest('.size-stock-tile').querySelector('input[type="number"]');
    if (checkbox.checked) {
        tile && tile.classList.add('tile-active');
        qty.disabled = false;
    } else {
        tile && tile.classList.remove('tile-active');
        qty.disabled = true;
        qty.value = 0;
    }
}

// ── Toggle male/female size grid ──────────────────────────────
function toggleSizeGrid(gender, prefix) {
    ['male','female'].forEach(g => {
        const grid = document.getElementById(prefix+'_'+g+'_grid');
        if (grid) grid.style.display = (g===gender) ? 'block' : 'none';
    });
    // Uncheck and disable all tiles in the NOW-hidden grid
    const hiddenGender = gender==='male' ? 'female' : 'male';
    const hiddenGrid = document.getElementById(prefix+'_'+hiddenGender+'_grid');
    if (hiddenGrid) {
        hiddenGrid.querySelectorAll('.sz-check').forEach(cb => {
            cb.checked = false;
            const qty = cb.closest('.size-stock-tile').querySelector('input[type="number"]');
            const tileId = prefix+'_tile_'+sizeKey(cb.name.replace('size_enabled[','').replace(']',''));
            const tile = document.getElementById(tileId);
            if (tile) tile.classList.remove('tile-active');
            if (qty) { qty.disabled=true; qty.value=0; }
        });
    }
}

// ── Open Edit Product modal ───────────────────────────────────
function openEditProduct(data) {
    // Fill common fields
    document.getElementById('e_fp_orig_brand').value = data.brand;
    document.getElementById('e_fp_orig_name').value  = data.name;
    document.getElementById('e_fp_brand').value  = data.brand;
    document.getElementById('e_fp_name').value   = data.name;
    document.getElementById('e_fp_desc').value   = data.desc  || '';
    document.getElementById('e_fp_img').value    = data.image || '';
    document.getElementById('e_fp_color').value  = data.color || '';
    document.getElementById('e_fp_price').value  = data.price;
    document.getElementById('e_fp_supp').value   = data.supp_id;
    document.getElementById('editImgPrev').src   = 'images/'+(data.image||'');

    // Determine gender from existing variant sizes
    const variantSizes = Object.keys(data.variants);
    const isMale = variantSizes.some(s => MALE_SIZES.includes(s));
    const gender = isMale ? 'male' : 'female';
    document.getElementById('edit_g'+gender[0]).checked = true;
    toggleSizeGrid(gender, 'edit');

    // Reset ALL standard size tiles
    ALL_STD_SIZES.forEach(size => {
        const k   = sizeKey(size);
        const cb  = document.getElementById('edit_sz_'+k);
        const qty = document.getElementById('edit_sqty_'+k);
        const tile= document.getElementById('edit_tile_'+k);
        if (cb)  { cb.checked=false; }
        if (qty) { qty.value=0; qty.disabled=true; }
        if (tile){ tile.classList.remove('tile-active'); }
    });

    // Pre-fill variant sizes that match standard UK sizes
    Object.entries(data.variants).forEach(([size,v]) => {
        const k   = sizeKey(size);
        const cb  = document.getElementById('edit_sz_'+k);
        const qty = document.getElementById('edit_sqty_'+k);
        const tile= document.getElementById('edit_tile_'+k);
        if (cb)  { cb.checked=true; }
        if (qty) { qty.value=v.qty; qty.disabled=false; }
        if (tile){ tile.classList.add('tile-active'); }
    });

    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

// ── Delete product group confirmation ─────────────────────────
function confirmGroupDelete(brand, name, form) {
    const count = form.querySelector('input[name="fp_orig_name"]') ? '?' : '?';
    if (!confirm('Delete ALL size variants of "'+name+'" ('+brand+')?\nThis cannot be undone.')) return false;
    return true;
}

// ── Populate Edit Staff modal ─────────────────────────────────
function openEditStaff(d) {
    document.getElementById('e_sf_id').value      =d.STAFF_ID;
    document.getElementById('e_sf_name').value    =d.STAFF_NAME;
    document.getElementById('e_sf_phone').value   =d.STAFF_NOPHONE;
    document.getElementById('e_sf_address').value =d.STAFF_ADDRESS||'';
    document.getElementById('e_sf_joindate').value=d.STAFF_JOINDATE||'';
    document.getElementById('e_sf_salary').value  =d.BASIC_SALARY||'';
    new bootstrap.Modal(document.getElementById('editStaffModal')).show();
}
// ── Populate Edit Supplier modal ──────────────────────────────
function openEditSupplier(d) {
    document.getElementById('e_sp_id').value     =d.SUPP_ID;
    document.getElementById('e_sp_name').value   =d.SUPP_NAME;
    document.getElementById('e_sp_phone').value  =d.SUPP_NOPHONE||'';
    document.getElementById('e_sp_address').value=d.SUPP_ADDRESS||'';
    new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
}
// ── Image preview helper ──────────────────────────────────────
function previewImg(input,previewId){
    const f=input.files[0]; if(!f) return;
    const r=new FileReader(); r.onload=e=>document.getElementById(previewId).src=e.target.result; r.readAsDataURL(f);
}
// ── Tab navigation ────────────────────────────────────────────
const adTitles={dashboard:'DASHBOARD',staff:'STAFF MANAGEMENT',customers:'CUSTOMER RECORDS',inventory:'PRODUCTS & STOCK',suppliers:'SUPPLIERS',reports:'SALES REPORTS'};
function adTab(n){
    document.querySelectorAll('.ad-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.ad-nav-link').forEach(l=>l.classList.remove('active'));
    document.getElementById('ad-'+n).classList.add('active');
    document.getElementById('adPageLabel').textContent=adTitles[n]||n.toUpperCase();
    document.querySelectorAll('.ad-nav-link').forEach(l=>{
        const oc=l.getAttribute('onclick')||'';
        if(oc.includes("'"+n+"'")) l.classList.add('active');
    });
}
document.addEventListener('DOMContentLoaded',()=>adTab('<?= htmlspecialchars($activeTab) ?>'));
</script>
</body></html>
