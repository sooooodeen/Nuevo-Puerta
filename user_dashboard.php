<?php
// filepath: c:\xampp\htdocs\nuevopuerta\user_dashboard.php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: Login/login.php");
    exit;
}

/* ---------------- DB ---------------- */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
if (function_exists('mysqli_set_charset')) { @mysqli_set_charset($conn, 'utf8mb4'); }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeCol   = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}
function hasTable(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}
function prepOrDie(mysqli $conn, string $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        // Helpful error that shows SQL so we can fix schema mismatches
        die("Prepare failed: " . $conn->error . "<br>SQL: <code>$sql</code>");
    }
    return $stmt;
}

// >>> PAYMENTS AJAX >>>
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

    // History
    if ($action === 'history') {
        if (!hasTable($conn,'payments') || !hasColumn($conn,'payments','amount_paid')) {
            echo json_encode(['success'=>true,'payments'=>[]]); exit;
        }
        $stmt = prepOrDie($conn, "SELECT payment_date AS created_at,
                                         remarks       AS description,
                                         lot_id, amount_paid, status
                                  FROM payments
                                  WHERE user_id=? ORDER BY payment_date DESC LIMIT 20");
        $stmt->bind_param('i',$uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        echo json_encode(['success'=>true,'payments'=>$rows]); exit;
    }

    // Pay
    if ($action === 'pay') {
        if (!hasTable($conn,'payments') || !hasColumn($conn,'payments','amount_paid')) {
            echo json_encode(['success'=>false,'message'=>'Payments table missing']); exit;
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw,true);
        if (!is_array($data)) { echo json_encode(['success'=>false,'message'=>'Bad payload']); exit; }

        $lot_id = (int)($data['lot_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $remarks = trim((string)($data['description'] ?? 'Payment'));
        if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Amount must be > 0']); exit; }

        $method = trim((string)($data['method'] ?? 'Manual'));
        $ref    = trim((string)($data['reference_no'] ?? ''));
        $proof  = '';                 // attach later via separate upload if needed
        $status = 'Pending';

        $stmt = prepOrDie($conn,"INSERT INTO payments
            (user_id, lot_id, amount_paid, payment_date, payment_method, reference_no, proof_file, status, remarks)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)");
        $stmt->bind_param('iidsssss', $uid, $lot_id, $amount, $method, $ref, $proof, $status, $remarks);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) { echo json_encode(['success'=>false,'message'=>'Insert failed']); exit; }

        echo json_encode(['success'=>true,'message'=>'Payment submitted for review','outstanding'=>null]); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}
// <<< END PAYMENTS AJAX <<<

/* ---------------- User ---------------- */
$user_id = (int)$_SESSION['user_id'];
$user = [
    'first_name'    => '',
    'middle_name'   => '',
    'last_name'     => '',
    'username'      => '',
    'email'         => '',
    'address'       => '',
    'mobile_number' => '',
    'photo'         => ''
];

if (hasTable($conn, 'user_accounts')) {
    // build safe column list, ADD photo (and role if exists)
    $cols = ['first_name','middle_name','last_name','username','email','address','mobile_number'];
    if (hasColumn($conn,'user_accounts','photo')) $cols[] = 'photo';
    if (hasColumn($conn,'user_accounts','role'))  $cols[] = 'role';

    $colList = implode(', ', $cols);
    $sql  = "SELECT $colList FROM user_accounts WHERE id = ?";
    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    if ($res && $res->num_rows) { $user = $res->fetch_assoc(); }
    $stmt->close();
} else {
    die("Table `user_accounts` not found.");
}

// After fetching $user (add this if missing)
$user_email = $user['email'] ?? '';

// Baseline session values for change detection
$_SESSION['first_name']    = (string)($user['first_name'] ?? '');
$_SESSION['middle_name']   = (string)($user['middle_name'] ?? '');
$_SESSION['last_name']     = (string)($user['last_name'] ?? '');
$_SESSION['username']      = (string)($user['username'] ?? '');
$_SESSION['email']         = (string)($user['email'] ?? '');
$_SESSION['mobile_number'] = (string)($user['mobile_number'] ?? '');
$_SESSION['address']       = (string)($user['address'] ?? '');

$sidebarName = trim(
    ($user['first_name'] ?? '') . ' ' .
    ($user['middle_name'] ?? '') . ' ' .
    ($user['last_name'] ?? '')
);
$sidebarName = $sidebarName ?: ($user['username'] ?? 'User');
/* ---------- determine role label (User / Agent / Admin) ---------- */
$roleLabel = 'User';
if (hasColumn($conn,'user_accounts','role') && !empty($user['role'])) {
    $roleLabel = ucfirst(trim((string)$user['role']));
} else {
    // check agent_accounts by email
    if (hasTable($conn,'agent_accounts')) {
        $s = $conn->prepare("SELECT id FROM agent_accounts WHERE email = ? LIMIT 1");
        if ($s) {
            $s->bind_param('s', $user_email);
            $s->execute();
            $r = $s->get_result();
            if ($r && $r->num_rows) { $roleLabel = 'Agent'; }
            $s->close();
        }
    }
    // check common admin tables if still unknown
    if ($roleLabel === 'User') {
        foreach (['admin_accounts','admins','administrators'] as $tbl) {
            if (hasTable($conn, $tbl)) {
                $s = $conn->prepare("SELECT id FROM `$tbl` WHERE email = ? LIMIT 1");
                if ($s) {
                    $s->bind_param('s', $user_email);
                    $s->execute();
                    $r = $s->get_result();
                    if ($r && $r->num_rows) { $roleLabel = 'Admin'; $s->close(); break; }
                    $s->close();
                }
            }
        }
    }
}
$user['role_label'] = $roleLabel;

/* ---------------- Recent Activity (viewings) ---------------- */
$recentActivities = [];
if (hasTable($conn, 'viewings')) {
    // Prefer created_at if present, else fallback to preferred_at
    $orderCol = hasColumn($conn, 'viewings', 'created_at') ? 'created_at' : (hasColumn($conn,'viewings','preferred_at')?'preferred_at':'id');
    $sql  = "SELECT id, client_email, lot_no, status, preferred_at, " 
          . (hasColumn($conn,'viewings','created_at') ? "created_at" : "preferred_at AS created_at")
          . " FROM viewings WHERE client_email = ? ORDER BY $orderCol DESC LIMIT 5";
    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { $recentActivities = $res->fetch_all(MYSQLI_ASSOC); }
    $stmt->close();
}

$listings = [];
$lotsOwned = 0;
$reservedLots = 0;

if (hasTable($conn,'lots')) {
    $hasOwnerId   = hasColumn($conn,'lots','owner_id');
    $hasCreatedAt = hasColumn($conn,'lots','created_at');
    $hasStatus    = hasColumn($conn,'lots','status');

    if ($hasOwnerId) {
        // Lots owned by this user
        $sql  = "SELECT id, block_number, lot_number, lot_size, lot_price"
              . ($hasCreatedAt ? ", created_at" : "")
              . " FROM lots WHERE owner_id = ?"
              . ($hasCreatedAt ? " ORDER BY created_at DESC" : "");
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) { $listings = $res->fetch_all(MYSQLI_ASSOC); }
        $stmt->close();

        $lotsOwned = count($listings);

        // Reserved lots by this user (if status is a column)
        if ($hasStatus) {
            $sql  = "SELECT COUNT(*) AS c FROM lots WHERE owner_id = ? AND status = 'Reserved'";
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) { $reservedLots = (int)($res->fetch_assoc()['c'] ?? 0); }
            $stmt->close();
        }
    } else {
        $listings = [];
        $lotsOwned = 0;
        $reservedLots = 0;
    }
}

/* ---------------- Upcoming viewings ---------------- */
$upcomingViewings = [];
if (hasTable($conn,'viewings') && hasTable($conn,'agent_accounts')) {
    $hasStatus = hasColumn($conn,'viewings','status');
    $dateOrder = hasColumn($conn,'viewings','preferred_at') ? 'preferred_at' : 'id';

    $statusListSql = "LOWER(v.status) IN ('scheduled','rescheduled','pending')";
    $excludeDoneSql = "LOWER(v.status) NOT IN ('cancelled','done')";

    $sql = "SELECT v.id, v.lot_no, v.agent_id, v.preferred_at"
         . ($hasStatus ? ", v.status" : ", '' AS status")
         . ", a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email"
         . " FROM viewings v"
         . " LEFT JOIN agent_accounts a ON v.agent_id = a.id"
         . " WHERE v.client_email = ? AND ( $statusListSql OR (v.preferred_at < NOW() AND $excludeDoneSql) )"
         . " ORDER BY v.$dateOrder ASC LIMIT 10";

    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { $upcomingViewings = $res->fetch_all(MYSQLI_ASSOC); }
    $stmt->close();
}

/* ---------------- Assigned Agent (best-effort) ---------------- */
$agent = null;
// 1. Try from upcoming viewings
if (!empty($upcomingViewings) && hasTable($conn,'agent_accounts')) {
    $agentId = (int)($upcomingViewings[0]['agent_id'] ?? 0);
    if ($agentId > 0) {
        $aCols = [];
        foreach (['id','first_name','last_name','email','mobile','phone','is_available','availability'] as $c) {
            if (hasColumn($conn,'agent_accounts',$c)) $aCols[] = $c;
        }
        if (!empty($aCols)) {
            $colList = implode(", ", $aCols);
            $sql  = "SELECT $colList FROM agent_accounts WHERE id = ? LIMIT 1";
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param("i", $agentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows) { $agent = $res->fetch_assoc(); }
            $stmt->close();
        }
    }
}
// 2. If not found, try from owned lots
if (!$agent && !empty($listings) && hasColumn($conn,'lots','agent_id') && hasTable($conn,'agent_accounts')) {
    foreach ($listings as $lot) {
        $agentId = (int)($lot['agent_id'] ?? 0);
        if ($agentId > 0) {
            $aCols = [];
            foreach (['id','first_name','last_name','email','mobile','phone','is_available','availability'] as $c) {
                if (hasColumn($conn,'agent_accounts',$c)) $aCols[] = $c;
            }
            if (!empty($aCols)) {
                $colList = implode(", ", $aCols);
                $sql  = "SELECT $colList FROM agent_accounts WHERE id = ? LIMIT 1";
                $stmt = prepOrDie($conn, $sql);
                $stmt->bind_param("i", $agentId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows) { $agent = $res->fetch_assoc(); }
                $stmt->close();
                break;
            }
        }
    }
}

/* ---------------- Outstanding balance (best-effort) ----------- */
$outstandingBalance = 0.0;
if (!empty($listings) && hasTable($conn,'payments') && hasColumn($conn,'payments','amount_paid')) {
    foreach ($listings as $lot) {
        $lot_id   = (int)$lot['id'];
        $lot_price = (float)$lot['lot_price'];

        $sql  = "SELECT SUM(amount_paid) AS total_paid
                 FROM payments
                 WHERE user_id = ? AND lot_id = ? AND status = 'Paid'";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param("ii", $user_id, $lot_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $totalPaid = 0.0;
        if ($res) { $totalPaid = (float)($res->fetch_assoc()['total_paid'] ?? 0); }
        $stmt->close();

        $outstandingBalance += max(0, $lot_price - $totalPaid);
    }
}

/* ---------------- Close conn early (no more queries) --------- */
$conn->close();

/* ---------- AVATAR SOURCE (FIX) ---------- */
// Use DB-stored photo if present, otherwise default icon.
$defaultAvatarFile = 'assets/Default_photo.jpg';
$avatarSrc = !empty($user['photo'] ?? '') ? $user['photo'] : $defaultAvatarFile;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
:root {
  --green:#2d4e1e; --yellow:#f4d03f; --white:#ffffff; --muted:#6b7280; --bg:#f8f8f8;
}
body{font-family:'Inter',Arial,sans-serif;background:var(--bg);color:var(--green);margin:0}
.dashboard-wrapper{display:flex;min-height:100vh}
.sidebar{width:260px;background:var(--green);color:var(--white);display:flex;flex-direction:column;align-items:center;padding:40px 0 0;box-shadow:2px 0 8px rgba(0,0,0,0.07);position:sticky;top:0;height:100vh}
.sidebar .logo {
  margin-bottom: 30px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.sidebar .logo img{width:90px;border-radius:50%;margin-bottom:10px;}
.sidebar .app-name { font-family: 'Inter', Arial, sans-serif; letter-spacing: 1px; font-weight:700;font-size:22px;margin-bottom:0px;}
.sidebar .profile-summary{text-align:center;margin-bottom:30px;background:rgba(255,255,255,0.08);border-radius:12px;padding:18px 0;width:85%;}
.sidebar .profile-summary .avatar{width:54px;height:54px;border-radius:50%;background:#fff;margin:0 auto 10px;}
.sidebar .profile-summary .avatar img{width:100%;height:100%;border-radius:50%;}
.sidebar .profile-summary .username{font-weight:700;font-size:16px}
.sidebar nav{width:100%}
.sidebar nav a{display:flex;align-items:center;gap:10px;color:var(--white);text-decoration:none;padding:14px 32px;font-weight:600;font-size:16px;border-left:4px solid transparent;transition:color .2s}
.sidebar nav a i {
  font-size: 1.3em;
  margin-right: 12px;
  vertical-align: middle;
}
.sidebar nav a.active,.sidebar nav a:hover{color:var(--yellow);background:rgba(255,255,255,.06)}
.main-content{flex:1;padding:40px 60px}
.section{background:var(--white);border-radius:18px;box-shadow:0 4px 10px rgba(0,0,0,0.07);margin-bottom:40px;padding:32px}
.section h2,
.dashboard-section h2 {
  font-size:2rem;       /* match "My Profile" size */
  font-weight:700;
  margin-bottom:18px;
  color:var(--green);
  line-height:1.1;
}

/* keep other heading styles as-is */
.profile-details{display:grid;grid-template-columns:1fr 1fr;gap:18px 40px;margin-bottom:10px}
.profile-details .label{font-weight:600;color:var(--muted)}
.profile-details .value{color:var(--green)}
.activity-list,.listings-list{list-style:none;padding:0;margin:0}
/* replaced simple list styles with notification card styles */
.activity-list{
  display:flex;
  flex-direction:column;
  gap:12px;
  padding:0;
  margin:0;
}
.notification-card{
  background:#fff;
  border-radius:12px;
  padding:14px;
  display:flex;
  gap:12px;
  align-items:flex-start;
  box-shadow:0 8px 24px rgba(15,23,42,0.04);
  border:1px solid rgba(0,0,0,0.04);
}
.notification-card .avatar{
  width:48px;height:48px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(180deg,rgba(45,78,30,0.06),rgba(45,78,30,0.02));
  color:var(--green);font-size:20px;flex-shrink:0;
}
.notification-card .content{flex:1;min-width:0}
.notification-card .title{font-weight:700;color:var(--green);margin-bottom:6px}
.notification-card .message{color:#283034;margin-bottom:6px;word-break:break-word}
.notification-card .time{font-size:0.9rem;color:var(--muted)}
.empty-notice{background:#fff;border-radius:12px;padding:14px;border:1px dashed #e6e6e6;color:var(--muted)}

/* responsive tweaks */
@media (max-width:700px){
  .notification-card{padding:12px}
  .notification-card .avatar{width:40px;height:40px;font-size:18px}
}
.kpi-row {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  margin-bottom: 24px;
  justify-content: center;
  align-items: stretch;
}
.kpi-card {
  flex: 1 1 180px;
  max-width: 220px;
  min-width: 160px;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 2px 12px rgba(44,78,30,0.07);
  padding: 20px 14px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  margin: 0;
}
.kpi-icon { font-size: 1.8rem; margin-bottom: 6px; }
.kpi-title { font-size: 1rem; }
.kpi-num   { font-size: 1.6rem; font-weight: 700; }
.kpi-desc  { font-size: 0.9rem; }

@media (max-width: 900px){
  .kpi-row { gap: 12px; }
  .kpi-card { min-width: 140px; max-width: 180px; padding: 14px 10px; }
  .kpi-icon { font-size: 1.6rem; }
  .kpi-num  { font-size: 1.4rem; }
}
@media (max-width: 700px){
  .dashboard-wrapper{flex-direction:column}
  .sidebar{position:relative;height:auto;width:100%;padding:16px}
  .main-content{padding:16px}
  .profile-details{grid-template-columns:1fr}
  .kpi-row{flex-direction:column; align-items:center; }
  .kpi-card { width:90%; max-width:350px; margin-bottom:12px; }
}

/* add property cards styles */
.property-grid{
  display:grid;
  grid-template-columns: repeat(auto-fit,minmax(260px,1fr));
  gap:18px;
  margin-top:16px;
}
.property-card{
  background:#fff;
  border-radius:12px;
  box-shadow:0 8px 28px rgba(15,23,42,0.04);
  border:1px solid rgba(0,0,0,0.03);
  overflow:hidden;
  display:flex;
  flex-direction:column;
  transition:transform .12s ease,box-shadow .12s ease;
}
.property-card:hover{ transform:translateY(-6px); box-shadow:0 20px 40px rgba(15,23,42,0.06); }
.property-image{ height:140px; background:linear-gradient(180deg,#f3f7f3,#fff); display:flex; align-items:center; justify-content:center; }
.property-image img{ max-width:100%; max-height:100%; object-fit:cover; display:block; }
.property-body{ padding:14px 16px; display:flex; flex-direction:column; gap:8px; }
.property-title{ font-weight:700; color:var(--green); font-size:1rem; }
.property-meta{ display:flex; gap:12px; color:var(--muted); font-size:0.95rem; }
.property-actions{ display:flex; gap:8px; margin-top:8px; }
.property-actions .btn{ padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; display:inline-block; cursor:pointer; border:0; background:var(--green); color:#fff; }
.property-actions .btn.secondary{ background:#334155; }
@media (max-width:700px){ .property-image{height:120px} }

/* add viewings card styles */
.viewings-grid{
  display: grid;
  grid-template-columns: repeat(auto-fit,minmax(260px,1fr));
  gap: 16px;
  margin-top: 12px;
}
.view-card{
  background:#fff;
  border-radius:12px;
  padding:14px;
  box-shadow:0 8px 24px rgba(15,23,42,0.04);
  border:1px solid rgba(0,0,0,0.03);
  display:flex;
  flex-direction:column;
  gap:10px;
  transition:transform .12s ease,box-shadow .12s ease;
}
.view-card:hover{ transform:translateY(-6px); box-shadow:0 18px 40px rgba(15,23,42,0.06); }
.view-card .top{
  display:flex;justify-content:space-between;align-items:flex-start;gap:12px;
}
.view-card .title{font-weight:700;color:var(--green);font-size:1rem}
.view-card .meta{color:var(--muted);font-size:0.95rem}
.view-card .details{display:flex;gap:12px;flex-wrap:wrap;color:#234;align-items:center}
.view-card .badge{padding:6px 10px;border-radius:999px;font-size:0.85rem;background:#f3f7f3;color:var(--green);border:1px solid rgba(45,78,30,0.06)}
.view-card .actions{display:flex;gap:8px;margin-top:6px}
.view-card .actions .btn{padding:8px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:600}
.view-card .btn.primary{background:var(--green);color:#fff}
.view-card .btn.ghost{background:transparent;border:1px solid rgba(0,0,0,0.06);color:#333}
.empty-notice{background:#fff;border-radius:12px;padding:16px;border:1px dashed #e6e6e6;color:var(--muted)}
@media (max-width:700px){ .viewings-grid{ grid-template-columns: 1fr } }


/* payments styles */
.payments-wrap{display:flex;flex-direction:column;gap:18px}
.payments-card{
  background:#fff;border-radius:12px;padding:18px;border:1px solid rgba(0,0,0,0.04);
  box-shadow:0 8px 24px rgba(15,23,42,0.03);
}
.payments-summary{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.payments-summary .left{display:flex;flex-direction:column;gap:6px}
.payments-summary .balance{font-size:1.6rem;font-weight:800;color:var(--green)}
.payments-actions{display:flex;gap:10px}
.btn.pay{background:var(--green);color:#fff;border-radius:8px;padding:10px 14px;border:0;font-weight:700;cursor:pointer}
.btn.history{background:transparent;color:#334155;border:1px solid rgba(0,0,0,0.06);padding:10px 14px;border-radius:8px;cursor:pointer}
.payments-table{width:100%;border-collapse:collapse;margin-top:12px}
.payments-table th, .payments-table td{padding:10px 12px;text-align:left;border-bottom:1px solid rgba(0,0,0,0.04)}
.payments-table th{font-weight:700;color:var(--muted);font-size:0.95rem}
.empty-pay{background:#fff;border-radius:12px;padding:26px;border:1px dashed #e6e6e6;color:var(--muted);text-align:center}
@media (max-width:700px){ .payments-summary{flex-direction:column;align-items:flex-start} .payments-actions{width:100%;justify-content:flex-start} }

/* --- Pay form grid (replaces old flex rules) --- */
#paymentForm{ display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:14px; align-items:end; }
.pay-item label{display:block;font-weight:600;margin-bottom:6px;}
.pay-item input,.pay-item select{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px}

/* desktop placement */
.pay-lot    { grid-column: span 3; }
.pay-amount { grid-column: span 3; }
.pay-method { grid-column: span 3; }
.pay-ref    { grid-column: span 3; }
.pay-desc   { grid-column: 1 / -1; }   /* full row */
.pay-actions{ grid-column: 1 / -1; display:flex; gap:10px; align-items:center; }

/* medium screens */
@media (max-width:1100px){
  .pay-lot,.pay-amount,.pay-method,.pay-ref{ grid-column: span 6; }
  .pay-desc,.pay-actions{ grid-column: 1 / -1; }
}

/* small screens */
@media (max-width:700px){
  .pay-lot,.pay-amount,.pay-method,.pay-ref,.pay-desc,.pay-actions{ grid-column: 1 / -1; }
}

/* add agent card + modal styles */
.agent-card{
  background:#fff;
  border-radius:12px;
  padding:18px;
  display:flex;
  gap:16px;
  align-items:center;
  box-shadow:0 8px 24px rgba(15,23,42,0.04);
  border:1px solid rgba(0,0,0,0.04);
  max-width:880px;
}
.agent-avatar{
  width:88px;height:88px;border-radius:12px;overflow:hidden;background:linear-gradient(135deg,#f3f7f3,#fff);flex:0 0 88px;display:flex;align-items:center;justify-content:center;
}
.agent-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.agent-info{display:flex;flex-direction:column;gap:6px}
.agent-info .name{font-weight:700;color:var(--green);font-size:1.15rem}
.agent-info .meta{color:var(--muted);font-size:0.95rem}
.agent-actions{margin-left:auto;display:flex;gap:10px;align-items:center}
.btn { padding:8px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:700;background:var(--green);color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
.btn.ghost{background:transparent;color:#374151;border:1px solid rgba(0,0,0,0.06);font-weight:600}
.btn.warn{background:var(--yellow);color:#222}
@media (max-width:700px){ .agent-card{flex-direction:column;align-items:flex-start} .agent-actions{margin-left:0;width:100%;justify-content:flex-start} }

/* agent modal */
.agent-modal-backdrop{position:fixed;inset:0;background:rgba(2,6,23,0.45);display:none;align-items:center;justify-content:center;z-index:1200;padding:20px}
.agent-modal{background:#fff;border-radius:12px;max-width:640px;width:100%;box-shadow:0 20px 60px rgba(2,6,23,0.3);overflow:hidden}
.agent-modal .modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #eee}
.agent-modal .modal-body{padding:16px}
.agent-modal .modal-footer{padding:12px 16px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:10px}
.agent-modal .close-btn{background:transparent;border:0;font-size:20px;cursor:pointer;color:#333}

/* Reschedule modal */
#rescheduleBackdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1300;align-items:center;justify-content:center;padding:20px}
#rescheduleBackdrop .modal-content{
  /* responsive width that always fits: smaller of max and viewport minus padding */
  width: min(560px, calc(100% - 32px)) !important;
  max-width: 560px;
  box-sizing: border-box !important;
}

/* keep modal centered and allow internal scrolling if tall */
#rescheduleBackdrop {
  padding: 16px;
  align-items: center;
  justify-content: center;
}

/* ensure body and inputs use box-sizing so width:100% fits inside modal */
#rescheduleBackdrop .modal-body,
#rescheduleBackdrop .modal-body * {
  box-sizing: border-box;
}

/* force inputs to size correctly and not overflow */
#rescheduleBackdrop input[type="datetime-local"],
#rescheduleBackdrop textarea,
#rescheduleBackdrop input,
#rescheduleBackdrop select {
  width: 100% !important;
  max-width: 100%;
  box-sizing: border-box;
}

/* smaller textarea height so it fits the modal better */
#rescheduleBackdrop textarea {
  min-height: 110px;
  resize: vertical;
  overflow: auto;
}

/* make footer buttons layout inside the modal width */
#rescheduleBackdrop .modal-footer,
#rescheduleBackdrop .modal-body > form {
  width: 100%;
  max-width: 100%;
}
/* enforce overdue styling (keep red) */
.view-card .badge.overdue {
  background: #fff5f5;
  color: #a31b1b;
  border: 1px solid rgba(163,27,27,0.08);
  font-weight:700;
}
.view-card .overdue-note,
.notification-card .overdue-note {
  color: #a31b1b;
  font-weight:700;
  margin-top:6px;
}
/* make sure notifications title shows Overdue red too */
.notification-card .title {
  /* keep regular title green by default */
  color: var(--green);
}
.notification-card .title.overdue {
  color: #a31b1b !important;
}

/* Payments form responsive fix */
#paymentForm{ display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
#paymentForm > div{ flex:1 1 240px; min-width:200px; }
#paymentForm input, #paymentForm select{ width:100%; min-width:0; }
@media (max-width:900px){ #paymentForm > div{ flex-basis:100%; min-width:100%; } }

.pay-form {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}

.pay-item {
  flex: 1 1 240px;
  min-width: 200px;
}

.pay-item label {
  font-weight: 600;
  margin-bottom: 4px;
}

.pay-item select,
.pay-item input {
  width: 100%;
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 6px;
  background: #fff;
}

.pay-item .btn {
  padding: 8px 12px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  border: 0;
  display: inline-block;
  text-decoration: none;
  margin-top: 8px;
}

.pay-item .btn.primary {
  background: var(--green);
  color: #fff;
}

.pay-item .btn.ghost {
  background: transparent;
  color: #374151;
  border: 1px solid rgba(0, 0, 0, 0.06);
}

/* add this block for payment status message */
#paymentStatus {
  margin-left: auto;
  font-weight: 600;
  display: none;
}

/* === FIX payments form layout: use a single CSS grid, neutralize old flex === */
.pay-form{ display: contents !important; }             /* kill the old .pay-form flex */
#paymentForm{
  display: grid !important;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 14px;
  align-items: end;
}
.pay-item{ min-width: 0 !important; }                  /* override earlier min-width:200px */

/* desktop placement */
.pay-lot    { grid-column: span 3; }
.pay-amount { grid-column: span 3; }
.pay-method { grid-column: span 3; }
.pay-ref    { grid-column: span 3; }
.pay-desc   { grid-column: 1 / -1; }                   /* full width row */
.pay-actions{ grid-column: 1 / -1; display:flex; gap:10px; align-items:center; }

/* medium */
@media (max-width:1100px){
  .pay-lot,.pay-amount,.pay-method,.pay-ref{ grid-column: span 6; }
}

/* small */
@media (max-width:700px){
  .pay-lot,.pay-amount,.pay-method,.pay-ref,.pay-desc,.pay-actions{ grid-column: 1 / -1; }
}

.viewings-table-wrap { background:#fff; border-radius:18px; box-shadow:0 4px 10px rgba(0,0,0,0.07); padding:24px; }
.viewings-table {
  width:100%; border-collapse:collapse; font-size:1rem;
}
.viewings-table th, .viewings-table td {
  padding:12px 14px; text-align:left; border-bottom:1px solid #f0f0f0;
}
.viewings-table th {
  background:#f8f8f8; font-weight:700; color:var(--green);
}
.viewings-table tr:last-child td { border-bottom:none; }
.badge {
  display:inline-block;
  padding:6px 14px;
  border-radius:999px;
  font-size:0.95rem;
  font-weight:600;
  background:#f3f7f3;
  color:var(--green);
  border:1px solid rgba(45,78,30,0.06);
}
.badge.overdue { background:#fff5f5; color:#a31b1b; border:1px solid #f4d3d3; }
.badge.cancelled { background:#f4d3d3; color:#a31b1b; }
.badge.done { background:#eaf7e1; color:#2d4e1e; }
.overdue-note { color:#a31b1b; font-size:0.95rem; margin-top:4px; }




  #profile .profile-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 15px 40px rgba(15, 23, 42, 0.06);
    padding: 24px 28px;
    max-width: 1100px;
    margin: 0 auto;
  }
  #profile .profile-tabs {
    display: flex;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 1.5rem;
    gap: 1.5rem;
  }
  #profile .profile-tab {
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    font-size: 0.95rem;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    outline: none;
  }
  .text-green-900 { color: #14532d; }
  .text-gray-600 { color: #4b5563; }
  .border-green-900 { border-bottom: 2px solid #14532d !important; }
  .border-transparent { border-bottom: 2px solid transparent !important; }

  #profile input[type="text"],
  #profile input[type="email"],
  #profile input[type="password"],
  #profile textarea {
    width: 100%;
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 10px 12px;
    font-size: 0.95rem;
  }
  #profile label {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
    display: block;
  }
  #profile .row {
    display: flex;
    gap: 24px;
    margin-bottom: 18px;
  }
  #profile .col {
    flex: 1;
  }
  #profile .avatar-wrap {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    flex: 0 0 80px;
  }
  #profile .avatar-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  #profile .btn {
    border-radius: 999px;
    padding: 8px 18px;
    font-size: 0.9rem;
    cursor: pointer;
    border: 1px solid transparent;
  }
  #profile .btn.ghost {
    background: #ffffff;
    color: #14532d;
    border-color: #cbd5e1;
  }
  #profile .btn.primary {
    background: #14532d;
    color: white;
    border-color: #14532d;
  }


</style>
</head>
<body>
<div class="dashboard-wrapper">
<aside class="sidebar">
  <div class="sidebar-logo-block" style="display:flex; align-items:center; gap:12px; margin-bottom:32px;">
  <img src="assets/f.png"
       alt="Logo"
       style="
         width:58px;
         height:58px;
         border-radius:999px;
         background:rgba(255,255,255,0.1);
         object-fit:contain;
       ">
  
  <div style="line-height:1.15;">
    <h2 style="
      font-weight:700;
      font-size:1.12rem;
      letter-spacing:0.03em;
      margin:0;
      white-space:nowrap;
    ">
      NUEVO PUERTA
    </h2>

    <span style="
      display:block;
      margin-top:2px;
      font-size:0.75rem;
      font-weight:400;
      opacity:0.9;
    ">
      REAL ESTATE
    </span>
  </div>
</div>

 <div class="profile-summary">
    <div class="avatar">
        <img id="sidebarAvatarImg"
             src="<?php echo htmlspecialchars($avatarSrc, ENT_QUOTES); ?>"
             alt="Avatar"
             style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;"
             onerror="this.src='assets/Default_photo.jpg'">
    </div>
    <div class="username" id="sidebarName">
        <?php echo htmlspecialchars($sidebarName, ENT_QUOTES); ?>
    </div>
    <div class="role">
        <?php echo htmlspecialchars($user['role_label'] ?? 'User', ENT_QUOTES); ?>
    </div>
</div>

  <nav>
    <a href="#home" class="sidebar-link active"><i class="fa-solid fa-house" style="color:#fff;"></i> Dashboard</a>
    <a href="#profile" class="sidebar-link"><i class="fa-solid fa-user" style="color:#fff;"></i> Profile</a>
    <a href="#activity" class="sidebar-link"><i class="fa-solid fa-bell" style="color:#fff;"></i> Notifications</a>
    <a href="#properties" class="sidebar-link"><i class="fa-solid fa-building" style="color:#fff;"></i> My Properties</a>
    <a href="#viewings" class="sidebar-link"><i class="fa-solid fa-calendar-days" style="color:#fff;"></i> Viewings</a>
    <a href="#payments" class="sidebar-link"><i class="fa-solid fa-credit-card" style="color:#fff;"></i> Payments</a>
    <a href="#documents" class="sidebar-link"><i class="fa-solid fa-file" style="color:#fff;"></i> Documents</a>
    <a href="#agent" class="sidebar-link"><i class="fa-solid fa-user-tie" style="color:#fff;"></i> Assigned Agent</a>
    <a href="logout.php" id="logout-link"><i class="fa-solid fa-door-open" style="color:#fff;"></i> Logout</a>
  </nav>
</aside>

  <main class="main-content">
    <section class="dashboard-section" id="home" style="display:block;">
      <div style="margin-bottom:18px;">
        <h2 style="font-size:2rem;font-weight:700;">Welcome, <?php echo h($user['first_name']); ?></h2>
        <div style="color:var(--muted);font-size:1.1rem;">Monitor your lots, viewings, and payments.</div>
      </div>
      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-icon">🏡</div>
          <div class="kpi-title">Lots Owned</div>
          <div class="kpi-num"><?php echo $lotsOwned; ?></div>
          <div class="kpi-desc">Total</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-icon">📑</div>
          <div class="kpi-title">Reserved Lots</div>
          <div class="kpi-num"><?php echo $reservedLots; ?></div>
          <div class="kpi-desc">Current</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-icon">📅</div>
          <div class="kpi-title">Upcoming Viewings</div>
          <div class="kpi-num"><?php echo count($upcomingViewings); ?></div>
          <div class="kpi-desc">Scheduled</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-icon">💳</div>
          <div class="kpi-title">Outstanding Balance</div>
          <div class="kpi-num">₱<?php echo number_format($outstandingBalance,2); ?></div>
          <div class="kpi-desc">Needs attention</div>
        </div>
      </div>

      <div style="margin-bottom:24px;">
  <h3 style="font-size:1.1rem;font-weight:600;color:var(--green);margin-bottom:8px;">Recent Activity</h3>
  <?php if (!empty($recentActivities)): ?>
    <ul style="list-style:none;padding:0;margin:0;">
      <?php foreach (array_slice($recentActivities,0,3) as $activity): ?>
        <li style="background:#fff;border-radius:8px;padding:10px 14px;margin-bottom:6px;box-shadow:0 2px 8px rgba(44,78,30,0.04);color:#234;">
          <span style="font-weight:600;"><?php echo h($activity['status'] ?? 'Notification'); ?></span>
          <span style="margin-left:8px;"><?php echo h($activity['lot_no'] ?? ''); ?></span>
          <span style="margin-left:8px;color:var(--muted);"><?php echo h($activity['preferred_at'] ?? ''); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="empty-notice">No recent activity.</div>
  <?php endif; ?>
</div>
    </section>
    <!-- SINGLE profile section (cleaned) -->
<section class="dashboard-section" id="profile" style="display:none;">
  <h2 style="font-size:2rem;font-weight:700;margin-bottom:14px;">My Profile</h2>

  <div class="profile-card">
    <!-- Tabs -->
    <div class="profile-tabs">
      <button id="tab-profile-info"
              type="button"
              class="profile-tab text-green-900 border-green-900">
        Profile Info
      </button>
      <button id="tab-change-password"
              type="button"
              class="profile-tab text-gray-600 border-transparent">
        Change Password
      </button>
    </div>

    <!-- Profile Info -->
    <div id="profile-info-pane">
      <form id="profileForm" onsubmit="return false;">
        <input type="hidden" id="pf_user_id" value="<?php echo (int)$user_id; ?>">

        <div class="row">
          <div class="col">
            <label>First Name</label>
            <input id="pf_first_name" type="text"
                   value="<?php echo h($user['first_name']); ?>"
                   readonly data-original="<?php echo h($user['first_name']); ?>"
                   style="background:#f8f8f8;">
          </div>
          <div class="col">
            <label>Middle Name</label>
            <input id="pf_middle_name" type="text"
                   value="<?php echo h($user['middle_name']); ?>"
                   readonly data-original="<?php echo h($user['middle_name']); ?>"
                   style="background:#f8f8f8;">
          </div>
          <div class="col">
            <label>Last Name</label>
            <input id="pf_last_name" type="text"
                   value="<?php echo h($user['last_name']); ?>"
                   readonly data-original="<?php echo h($user['last_name']); ?>"
                   style="background:#f8f8f8;">
          </div>
        </div>

        <div class="row">
          <div class="col">
            <label>Username</label>
            <input id="pf_username" type="text"
                   value="<?php echo h($user['username']); ?>"
                   readonly data-original="<?php echo h($user['username']); ?>"
                   style="background:#f8f8f8;">
          </div>
          <div class="col">
            <label>Email</label>
            <input id="pf_email" type="email"
                   value="<?php echo h($user['email']); ?>"
                   readonly data-original="<?php echo h($user['email']); ?>"
                   style="background:#f8f8f8;">
          </div>
        </div>

        <div class="row">
          <div class="col">
            <label>Mobile Number</label>
            <input id="pf_mobile" type="text"
                   value="<?php echo h($user['mobile_number']); ?>"
                   readonly data-original="<?php echo h($user['mobile_number']); ?>"
                   style="background:#f8f8f8;">
          </div>
          <div class="col">
            <label>Address</label>
            <input id="pf_address" type="text"
                   value="<?php echo h($user['address']); ?>"
                   readonly data-original="<?php echo h($user['address']); ?>"
                   style="background:#f8f8f8;">
          </div>
        </div>

        <div class="row" style="align-items:center;">
          <div class="avatar-wrap">
            <img id="pf_avatar_preview" src="<?php echo $avatarSrc; ?>" alt="Avatar">
          </div>
          <div class="col">
            <label>Profile Photo</label>
            <input id="pf_photo" type="file" accept="image/*" disabled>
            <input type="hidden" id="pf_photo_path"
                   value="<?php echo h($avatarSrc === $defaultAvatarFile ? '' : $avatarSrc); ?>">
            <div id="pf_photo_status" style="margin-top:6px;display:none;font-size:13px;"></div>
          </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:10px;">
          <div id="profile_status" style="margin-right:auto;color:crimson;display:none;font-weight:600;"></div>
          <button id="editProfileBtn" type="button" class="btn ghost">Edit</button>
          <button id="cancelProfileBtn" type="button" class="btn ghost" style="display:none;">Cancel</button>
          <button id="saveProfileBtn" type="button" class="btn primary" style="display:none;">Save</button>
        </div>
      </form>
    </div>

    <!-- Change Password -->
    <div id="change-password-pane" style="display:none;">
      <form id="changePasswordForm" method="post" action="change_password.php">
        <div class="mb-3">
          <label>Current Password</label>
          <input type="password" name="current_password" placeholder="Current Password" required>
        </div>
        <div class="mb-3">
          <label>New Password</label>
          <input type="password" name="new_password" placeholder="New Password" required>
        </div>
        <div class="mb-3">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        </div>
        <button type="submit" class="btn primary">Change Password</button>
        <div id="changePasswordStatus" style="margin-top:10px;color:crimson;display:none;"></div>
      </form>
    </div>
  </div>
</section>



    <section class="dashboard-section" id="activity" style="display:none;">
      <h2>Notifications</h2>

      <?php if (empty($recentActivities)): ?>
        <div class="empty-notice">You have no notifications at the moment.</div>
      <?php else: ?>
<ul class="activity-list">
  <?php foreach ($recentActivities as $activity):
      $pref = $activity['preferred_at'] ?? '';
      $dateTime = $pref && $pref !== '' ? new DateTime($pref) : null;
      $now = new DateTime();

      $statusRaw = strtolower(trim((string)($activity['status'] ?? '')));
      $pendingStates = ['pending','scheduled','reschedule_requested','rescheduled'];
      $isOverdue = $dateTime && ($dateTime < $now) && in_array($statusRaw, $pendingStates, true);
      $titleText = $isOverdue ? 'Overdue' : (!empty($activity['status']) ? ucfirst($activity['status']) : 'Notification');
      $notifId = (int)($activity['id'] ?? 0);
  ?>
    <li class="notification-card" data-id="<?php echo $notifId; ?>">
      <div class="avatar" aria-hidden="true">🔔</div>
      <div class="content">
        <div class="title"><?php echo h($titleText); ?></div>
        <div class="message">
          <?php
            $lot = isset($activity['lot_no']) && $activity['lot_no'] !== '' ? 'Lot ' . h($activity['lot_no']) : 'Lot —';
            $when = $dateTime ? $dateTime->format('M d, Y') : (isset($activity['created_at']) ? date('M d, Y', strtotime($activity['created_at'])) : '');
            echo $lot . ($when ? ' — ' . $when : '');
          ?>
        </div>
        <?php if (!empty($activity['client_email'])): ?>
          <div class="time"><?php echo h($activity['client_email']); ?></div>
        <?php endif; ?>
        <?php if ($isOverdue): ?>
          <div style="margin-top:8px;color:#a31b1b;font-weight:700;">This scheduled time has passed — please reschedule or cancel if no longer needed.</div>
        <?php endif; ?>
      </div>
      <button class="notif-remove-btn" title="Remove notification" style="background:transparent;border:0;color:#a31b1b;font-size:1.3rem;cursor:pointer;margin-left:8px;">&times;</button>
    </li>
  <?php endforeach; ?>
</ul>
      <?php endif; ?>
    </section>
    <section class="dashboard-section" id="properties" style="display:none;">
      <h2>My Properties</h2>
      <?php if (empty($listings)): ?>
        <div class="empty-notice">You don’t have properties linked to your account yet.</div>
      <?php else: ?>
        <div class="property-grid">
          <?php foreach ($listings as $lot): ?>
            <div class="property-card">
              <div class="property-image">
                <img src="assets/default-property.png" alt="Property Image">
              </div>
              <div class="property-body">
                <div class="property-title">
                  Block <?php echo h($lot['block_number']); ?>, Lot <?php echo h($lot['lot_number']); ?>
                </div>
                <div class="property-meta">
                  <div><?php echo h($lot['lot_size']); ?> sqm</div>
                  <div>₱<?php echo number_format($lot['lot_price'],2); ?></div>
                </div>
                <div class="property-actions">
                  <a href="edit_listing.php?id=<?php echo $lot['id']; ?>" class="btn secondary">Edit</a>
                  <a href="view_listing.php?id=<?php echo $lot['id']; ?>" class="btn">View</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
   <section class="dashboard-section" id="viewings" style="display:none;">
  <h2>Viewings</h2>
  <?php if (empty($upcomingViewings)): ?>
    <div class="empty-notice">You don’t have any upcoming viewings.</div>
  <?php else: ?>
    <div class="viewings-table-wrap">
      <table class="viewings-table">
        <thead>
          <tr>
            <th>Lot</th>
            <th>Date & Time</th>
            <th>Status</th>
            <th>Agent</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($upcomingViewings as $viewing):
            $vid = (int)$viewing['id'];
            $agentName = (!empty($viewing['agent_first']) || !empty($viewing['agent_last']))
              ? trim(($viewing['agent_first'] ?? '') . ' ' . ($viewing['agent_last'] ?? ''))
              : (!empty($viewing['agent_email']) ? $viewing['agent_email'] : (!empty($viewing['agent_id']) ? 'Agent ' . $viewing['agent_id'] : '—'));
            $dateTime = isset($viewing['preferred_at']) && $viewing['preferred_at'] !== '' ? new DateTime($viewing['preferred_at']) : null;
            $now = new DateTime();
            $statusRaw = strtolower(trim((string)($viewing['status'] ?? '')));
            $pendingStates = ['pending','scheduled','reschedule_requested','rescheduled'];
            $isOverdue = $dateTime && ($dateTime < $now) && in_array($statusRaw, $pendingStates, true);
            $badgeText = $isOverdue ? 'Overdue' : (!empty($viewing['status']) ? ucwords(str_replace('_',' ', $viewing['status'])) : 'Scheduled');
            $badgeClass = $isOverdue ? 'overdue' : ($statusRaw === 'done' ? 'done' : ($statusRaw === 'cancelled' ? 'cancelled' : ''));
        ?>
          <tr>
            <td>Lot <?php echo h($viewing['lot_no']); ?></td>
            <td>
              <?php echo $dateTime ? $dateTime->format('M d, Y • h:i A') : '—'; ?>
            </td>
            <td>
              <span class="badge <?php echo $badgeClass; ?>">
                <?php echo h($badgeText); ?>
              </span>
              <?php if ($isOverdue): ?>
                <div class="overdue-note">Please reschedule or cancel if no longer needed.</div>
              <?php endif; ?>
            </td>
            <td><?php echo h($agentName); ?></td>
            <td>
              <a href="#" class="btn ghost reschedule-btn" data-id="<?php echo $vid; ?>" data-agent="<?php echo (int)$viewing['agent_id']; ?>">Reschedule</a>
              <button type="button" class="btn ghost cancel-btn" data-id="<?php echo $vid; ?>">Cancel</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
    <section class="dashboard-section" id="payments" style="display:none;">
  <h2>Payments</h2>
  <div style="margin-bottom:10px;">All payments up to date <?php if ($outstandingBalance == 0): ?>✅<?php endif; ?></div>

  <div class="payments-wrap">
    <div class="payments-card">
      <div class="payments-summary">
        <div class="left">
          <div style="font-weight:600;">Account Balance</div>
          <div class="balance" id="accountBalance">₱<?php echo number_format($outstandingBalance, 2); ?></div>
        </div>
        <div class="payments-actions">
          <button class="btn pay" id="payNowBtn">Pay Now</button>
          <button class="btn history" id="viewHistoryBtn">View Payment History</button>
        </div>
      </div>

      <!-- Inline payment form (hidden by default) -->
      <div id="paymentFormWrap" style="display:none;margin-top:16px;border-top:1px solid #eee;padding-top:16px;">
        <h3 style="margin:0 0 12px;font-size:1.05rem;">Make a Payment</h3>
        <form id="paymentForm" class="pay-form" onsubmit="return false;">
          <div class="pay-item pay-lot">
            <label>Lot</label>
            <select id="pay_lot">
              <option value="0">General</option>
              <?php foreach ($listings as $lot): ?>
                <option value="<?php echo (int)$lot['id']; ?>">
                  Block <?php echo h($lot['block_number']); ?> / Lot <?php echo h($lot['lot_number']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="pay-item pay-amount">
            <label>Amount (₱)</label>
            <input type="number" step="0.01" id="pay_amount">
          </div>

          <div class="pay-item pay-method">
            <label>Method</label>
            <select id="pay_method">
              <option value="Bank Deposit">Bank Deposit</option>
              <option value="GCash">GCash</option>
              <option value="PayMaya">PayMaya</option>
              <option value="Cash">Cash</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="pay-item pay-ref">
            <label>Reference No.</label>
            <input type="text" id="pay_ref" placeholder="Bank/GCash ref no.">
          </div>

          <div class="pay-item pay-desc">
            <label>Description</label>
            <input type="text" id="pay_desc" placeholder="e.g. Reservation fee">
          </div>

          <div class="pay-item pay-actions">
            <button id="submitPaymentBtn" class="btn" type="button">Submit Payment</button>
            <button id="cancelPaymentBtn" class="btn ghost" type="button">Cancel</button>
            <div id="paymentStatus" style="margin-left:auto;font-weight:600;display:none;"></div>
          </div>
        </form>
      </div>

      <!-- Payment history table -->
      <div id="paymentHistoryWrap" style="display:none;margin-top:20px;border-top:1px solid #eee;padding-top:16px;">
        <h3 style="margin:0 0 12px;font-size:1.05rem;">Payment History</h3>
        <table class="payments-table">
          <thead>
            <tr><th>Date</th><th>Description</th><th>Lot</th><th>Amount</th><th>Status</th></tr>
          </thead>
          <tbody id="paymentHistoryBody">
            <tr><td colspan="5" style="text-align:center;color:#666;">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    
  </div>
</section>

<section class="dashboard-section" id="documents" style="display:none;">
  <h2>My Documents</h2>

  <div class="payments-wrap">
    <div class="payments-card">

      <!-- Upload Form -->
      <form id="documentUploadForm" enctype="multipart/form-data"
            style="
              display:flex;
              align-items:center;
              gap:16px;
              margin-bottom:20px;
              width:100%;
            ">

        <label style="font-weight:600; white-space:nowrap;">Upload Document:</label>

        <select name="doc_type" id="doc_type" style="padding:6px 8px;">
          <option value="ID">ID</option>
          <option value="Contract">Contract</option>
          <option value="Other">Other</option>
        </select>

        <input type="file" name="document" id="documentFile" required
               style="padding:6px;">

        <!-- Push Upload button to the right -->
        <div style="margin-left:auto;">
          <button type="submit" class="btn" style="padding:6px 18px;">
            Upload
          </button>
        </div>

        <span id="docUploadStatus" style="margin-left:10px;font-weight:600;"></span>
      </form>

      <!-- Documents List -->
      <div id="documentsList" style="border-top:1px solid #eee; padding-top:16px;">
        <h3 style="margin:0 0 12px;font-size:1.05rem;">Uploaded Documents</h3>

        <table class="payments-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>File</th>
              <th>Date</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="documentsBody">
            <tr>
              <td colspan="5" style="text-align:center;color:#666;">No documents uploaded.</td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</section>




    <section class="dashboard-section" id="agent" style="display:none;">
  <h2>Assigned Agent</h2>
  <?php if ($agent): ?>
    <div class="agent-card">
      <div class="agent-avatar">
        <img src="assets/Default_photo.jpg" alt="Agent Photo" style="width:100%;height:100%;object-fit:cover;">
      </div>
      <div class="agent-info">
        <div class="name"><?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?></div>
        <div class="meta">Email: <?php echo h($agent['email'] ?? ''); ?></div>
        <div class="meta">Phone: <?php echo h($agent['phone'] ?? $agent['mobile'] ?? ''); ?></div>
        <div class="meta">Status: <?php echo h($agent['availability'] ?? ($agent['is_available'] ? 'Available' : 'Unavailable')); ?></div>
      </div>
      <div class="agent-actions">
        <a href="call_agent.php?id=<?php echo $agent['id']; ?>" class="btn">Call</a>
        <a href="message_agent.php?id=<?php echo $agent['id']; ?>" class="btn">Message</a>
        <a href="reschedule_viewing.php?agent_id=<?php echo $agent['id']; ?>" class="btn">Request Reschedule</a>
      </div>
    </div>
  <?php else: ?>
    <div class="empty-notice">No agent assigned yet.</div>
  <?php endif; ?>
</section>
  </main>
</div>
<!-- Logout Confirmation Modal -->
<div id="logoutModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:32px 24px;max-width:350px;margin:auto;">
    <div style="display:flex;align-items:center;margin-bottom:18px;">
      <svg style="width:28px;height:28px;color:#e53e3e;margin-right:12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
      </svg>
      <h3 style="font-size:1.15rem;font-weight:600;color:#222;">Confirm Logout</h3>
    </div>
    <p style="color:#555;margin-bottom:24px;">Are you sure you want to logout? You will need to login again to access your dashboard.</p>
    <div style="display:flex;gap:10px;justify-content:end;">
      <button onclick="closeLogoutModal()" style="padding:8px 18px;border:1px solid #ccc;border-radius:6px;background:#fff;color:#333;cursor:pointer;">Cancel</button>
      <button onclick="proceedLogout()" style="padding:8px 18px;background:#e53e3e;color:#fff;border:none;border-radius:6px;cursor:pointer;">Logout</button>
    </div>
  </div>
</div>

<!-- Reschedule modal -->
<div id="rescheduleBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1300;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:12px;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(2,6,23,0.3);overflow:hidden">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #eee">
      <strong id="rescheduleTitle" style="color:var(--green);font-size:1.05rem">Request Reschedule</strong>
      <button onclick="closeRescheduleModal()" style="background:transparent;border:0;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:16px;" id="rescheduleBody">
      <form id="rescheduleForm" onsubmit="return submitRescheduleForm(event)">
        <input type="hidden" name="viewing_id" id="rs_viewing_id" value="">
        <input type="hidden" name="agent_id" id="rs_agent_id" value="">
        <div style="margin-bottom:10px;">
          <label style="display:block;font-weight:600;margin-bottom:6px;">Preferred new date & time</label>
          <input id="rs_datetime" name="preferred_at" type="datetime-local" style="width:100%;padding:10px;border-radius:6px;border:1px solid #ddd" required>
        </div>
        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:600;margin-bottom:6px;">Message to agent (optional)</label>
          <textarea id="rs_message" name="message" rows="4" style="width:100%;padding:10px;border-radius:6px;border:1px solid #ddd" placeholder="Explain reason or propose availability"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
          <button type="button" class="btn ghost" onclick="closeRescheduleModal()">Cancel</button>
          <button type="submit" class="btn primary">Send Request</button>
        </div>
      </form>
      <div id="rs_status" style="margin-top:12px;display:none;"></div>
    </div>
  </div>
</div>

<!-- Cancel Confirmation Modal -->
<div id="cancelBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1350;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:12px;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(2,6,23,0.3);overflow:hidden;padding:18px;">
    <h3 style="margin:0 0 8px;color:#222;">Cancel Viewing</h3>
    <p style="color:#444;margin-bottom:16px;">Are you sure you want to cancel this viewing?</p>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="button" onclick="closeCancelModal()" style="padding:8px 14px;border-radius:8px;border:1px solid #ccc;background:#fff;cursor:pointer;">No, Go Back</button>
      <button id="confirmCancelBtn" type="button" style="padding:8px 14px;border-radius:8px;background:#e53e3e;color:#fff;border:0;cursor:pointer;">Yes, Cancel</button>
    </div>
    <div id="cancel_status" style="margin-top:12px;display:none;"></div>
  </div>
</div>

<script>
document.querySelectorAll('.sidebar-link').forEach(function(link) {
  link.addEventListener('click', function(e) {
    if (link.getAttribute('href').startsWith('#')) {
      e.preventDefault();
      document.querySelectorAll('.sidebar-link').forEach(function(l) {
        l.classList.remove('active');
      });
      link.classList.add('active');
      document.querySelectorAll('.dashboard-section').forEach(function(sec) {
        sec.style.display = 'none';
      });
      var target = link.getAttribute('href').substring(1);
      var section = document.getElementById(target);
      if (section) section.style.display = 'block';
    }
  });
});

// Logout confirmation modal logic
document.getElementById('logout-link').addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('logoutModal').style.display = 'flex';
});
function closeLogoutModal() {
  document.getElementById('logoutModal').style.display = 'none';
}
function proceedLogout() {
  window.location.href = document.getElementById('logout-link').getAttribute('href');
}

// open modal with viewing + agent info
function openRescheduleModal(viewingId, agentId, label) {
  document.getElementById('rs_viewing_id').value = viewingId || '';
  document.getElementById('rs_agent_id').value = agentId || '';
  document.getElementById('rescheduleTitle').textContent = label ? 'Reschedule ' + label : 'Request Reschedule';
  document.getElementById('rs_status').style.display = 'none';
  document.getElementById('rs_message').value = '';
  document.getElementById('rescheduleBackdrop').style.display = 'flex';
  // focus the datetime control
  setTimeout(function(){ document.getElementById('rs_datetime').focus(); }, 150);
}
function closeRescheduleModal() {
  document.getElementById('rescheduleBackdrop').style.display = 'none';
}

// attach handlers to reschedule buttons
document.querySelectorAll('.reschedule-btn').forEach(function(btn){
  btn.addEventListener('click', function(e){
    e.preventDefault();
    var vid = this.getAttribute('data-id') || this.dataset.id;
    // try to find agent id on button, else on closest .view-card
    var aid = this.getAttribute('data-agent') || (this.closest('.view-card') ? this.closest('.view-card').getAttribute('data-agent-id') : '');
    // optional label (lot or agent name)
    var card = this.closest('.view-card');
    var label = card ? (card.querySelector('.title') ? card.querySelector('.title').textContent.trim() : '') : '';
    openRescheduleModal(vid, aid, label);
  });
});

// submit the reschedule form via AJAX and notify agent
function submitRescheduleForm(e){
  e.preventDefault();
  var viewing_id = document.getElementById('rs_viewing_id').value || '';
  var agent_id = document.getElementById('rs_agent_id').value || '';
  var preferred_at = document.getElementById('rs_datetime').value || '';
  var message = document.getElementById('rs_message').value || '';

  if (!preferred_at) { alert('Please pick a new date & time.'); return false; }

  var statusEl = document.getElementById('rs_status');
  statusEl.style.display = 'block';
  statusEl.style.color = '#333';
  statusEl.textContent = 'Sending request...';

  fetch('request_reschedule.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ viewing_id: viewing_id, agent_id: agent_id, preferred_at: preferred_at, message: message })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data && data.success) {
      statusEl.style.color = 'green';
      statusEl.textContent = data.message || 'Request sent.';
      setTimeout(function(){ closeRescheduleModal(); location.reload(); }, 900);
    } else {
      statusEl.style.color = 'crimson';
      statusEl.textContent = (data && data.message) ? data.message : 'Failed to send request.';
    }
  })
  .catch(function(err){
    console.error(err);
    statusEl.style.color = 'crimson';
    statusEl.textContent = 'Network error. Try again.';
  });

  return false;
}

// close modal on backdrop click & ESC
document.getElementById('rescheduleBackdrop').addEventListener('click', function(e){
  if (e.target === this) closeRescheduleModal();
});
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeRescheduleModal(); });

// Cancel viewing logic
document.querySelectorAll('.cancel-btn').forEach(function(btn){
  btn.addEventListener('click', function(e){
    e.preventDefault();
    var vid = this.getAttribute('data-id') || this.dataset.id;
    // open cancel confirmation modal
    document.getElementById('confirmCancelBtn').setAttribute('data-id', vid);
    document.getElementById('cancelBackdrop').style.display = 'flex';
   });
});
function closeCancelModal() {
  document.getElementById('cancelBackdrop').style.display = 'none';
}
document.getElementById('confirmCancelBtn').addEventListener('click', function() {
  var vid = this.getAttribute('data-id') || '';
  if (!vid) return;

  var statusEl = document.getElementById('cancel_status');
  statusEl.style.display = 'block';
  statusEl.style.color = '#333';
  statusEl.textContent = 'Cancelling viewing...';

  fetch('cancel_viewing.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ viewing_id: vid })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data && data.success) {
      statusEl.style.color = 'green';
      statusEl.textContent = data.message || 'Viewing cancelled.';
      setTimeout(function(){ closeCancelModal(); location.reload(); }, 900);
    } else {
      statusEl.style.color = 'crimson';
      statusEl.textContent = (data && data.message) ? data.message : 'Failed to cancel viewing.';
    }
  })
  .catch(function(err){
    console.error(err);
    statusEl.style.color = 'crimson';
    statusEl.textContent = 'Network error. Try again.';
  });
});

// add profile edit handlers
(function(){
  function setReadonly(readonly){
    var ids = ['pf_first_name','pf_middle_name','pf_last_name','pf_username','pf_email','pf_mobile','pf_address','pf_photo'];
    ids.forEach(function(id){
      var el = document.getElementById(id);
      if (!el) return;
      if (id === 'pf_photo') {
        el.disabled = !!readonly;
      } else {
        el.readOnly = !!readonly;
        el.style.background = readonly ? '#f8f8f8' : '#fff';
      }
    });
  }
  function showStatus(msg, color){
    var s = document.getElementById('profile_status');
    s.style.display = msg ? 'block' : 'none';
    s.style.color = color || '#333';
    s.textContent = msg || '';
  }

  var editBtn = document.getElementById('editProfileBtn');
  var saveBtn = document.getElementById('saveProfileBtn');
  var cancelBtn = document.getElementById('cancelProfileBtn');
  var fileInput = document.getElementById('pf_photo');
  var photoStatus = document.getElementById('pf_photo_status');
  var avatarPreview = document.getElementById('pf_avatar_preview');
  var hiddenPhotoPath = document.getElementById('pf_photo_path');

  if (editBtn) {
    editBtn.addEventListener('click', function(){
      // store originals
      ['pf_first_name','pf_middle_name','pf_last_name','pf_username','pf_email','pf_mobile','pf_address'].forEach(function(id){
        var el = document.getElementById(id);
        if (el && el.getAttribute('data-original') === null) el.setAttribute('data-original', el.value);
      });
      setReadonly(false);
      editBtn.style.display = 'none';
      saveBtn.style.display = 'inline-flex';
      cancelBtn.style.display = 'inline-flex';
      showStatus('', '');
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function(){
      ['pf_first_name','pf_middle_name','pf_last_name','pf_username','pf_email','pf_mobile','pf_address'].forEach(function(id){
        var el = document.getElementById(id);
        if (el && el.getAttribute('data-original') !== null) el.value = el.getAttribute('data-original');
      });
      // revert preview if changed
      var origPhoto = hiddenPhotoPath.value || 'assets/Default_photo.jpg';
      avatarPreview.src = origPhoto;
      fileInput.value = '';
      photoStatus.style.display = 'none';

      setReadonly(true);
      editBtn.style.display = 'inline-flex';
      saveBtn.style.display = 'none';
      cancelBtn.style.display = 'none';
      showStatus('', '');
    });
  }

  // optional local preview when a file is chosen (still disabled until edit)
  if (fileInput) {
    fileInput.addEventListener('change', function(){
      var f = this.files && this.files[0];
      if (!f) return;
      if (!/^image\//.test(f.type)) {
        photoStatus.style.display='block'; 
        photoStatus.style.color='crimson'; 
        photoStatus.textContent='Select an image file.'; 
        return;
      }
      var reader = new FileReader();
      reader.onload = function(e){ avatarPreview.src = e.target.result; };
      reader.readAsDataURL(f);
      photoStatus.style.display='none';
    });
  }

  // on Save: if a file selected upload it first, then call update-user-profile.php with photo path
  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      // clear any previous photo message (only show it when uploading a new file)
      if (photoStatus) { photoStatus.style.display = 'none'; photoStatus.textContent = ''; }

      showStatus('Saving...', '#333');
      const fieldIds = ['pf_first_name','pf_middle_name','pf_last_name','pf_username','pf_email','pf_mobile','pf_address'];
      let anyChanged = false;
      fieldIds.forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        const orig = (el.getAttribute('data-original')||'').trim();
        const cur  = el.value.trim();
        if (orig !== cur) anyChanged = true;
      });
      if (!anyChanged && !(fileInput && fileInput.files && fileInput.files[0])) {
        showStatus('', '');
        setReadonly(true);
        editBtn.style.display='inline-flex';
        saveBtn.style.display='none';
        cancelBtn.style.display='none';
        return; // nothing to do
      }

      var payload = {
        first_name: document.getElementById('pf_first_name').value.trim(),
        middle_name: document.getElementById('pf_middle_name').value.trim(),
        last_name: document.getElementById('pf_last_name').value.trim(),
        username: document.getElementById('pf_username').value.trim(),
        email: document.getElementById('pf_email').value.trim(),
        mobile_number: document.getElementById('pf_mobile').value.trim(),
        address: document.getElementById('pf_address').value.trim()
      };

      if (!payload.first_name || !payload.last_name) {
        showStatus('First and last name are required.', 'crimson');
        return;
      }

      // helper to call profile update
      function sendProfileUpdate(photoPath){
        // include photo path in payload
        if (photoPath) {
          payload.photo = photoPath;
          if (hiddenPhotoPath) hiddenPhotoPath.value = photoPath;
        } else {
          if (hiddenPhotoPath) {
            payload.photo = hiddenPhotoPath.value || '';
          }
        }

        fetch('update-user-profile.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        })
        .then(async function(r){
          let body = await r.text();
          if (!r.ok) {
            throw new Error('HTTP '+r.status+' '+r.statusText+' | '+body);
          }
          try { return JSON.parse(body); } catch(e){ throw new Error('Bad JSON: '+body); }
        })
        .then(function(data){
          if (data.success && data.changed) {
            fieldIds.forEach(id=>{
              const el = document.getElementById(id);
              if (el) el.setAttribute('data-original', el.value.trim());
            });
            showStatus(data.message || 'Profile updated', 'green');
            var nameEl = document.getElementById('sidebarName');
            if (nameEl && (payload.first_name || payload.last_name)) {
              nameEl.textContent = (payload.first_name||'') + (payload.middle_name? ' '+payload.middle_name:'') + ' ' + (payload.last_name||'');
            }
          } else {
            // no changes: clear status (optional)
            showStatus('', ''); // or skip
          }
          setReadonly(true);
          editBtn.style.display = 'inline-flex';
          saveBtn.style.display = 'none';
          cancelBtn.style.display = 'none';
        })
        .catch(function(err){
          console.error(err);
          showStatus('Network/server error: '+err.message, 'crimson');
        });
      }

      // if a new file chosen upload it first
      var f = fileInput && fileInput.files && fileInput.files[0];
      if (f) {
        photoStatus.style.display='block'; photoStatus.style.color='#333'; photoStatus.textContent='Uploading photo...';
        var fd = new FormData();
        fd.append('photo', f);
        fetch('upload-profile-photo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.ok ? r.json() : r.text().then(t=>{ throw new Error(t||'Upload error'); }); })
        .then(function(data){
          if (data && data.success && data.path) {
            photoStatus.style.color='green'; photoStatus.textContent='Photo uploaded';
            const bust = data.path + (data.path.includes('?') ? '&' : '?') + 'v=' + Date.now();
            avatarPreview.src = bust;
            const sideImg = document.getElementById('sidebarAvatarImg');
            if (sideImg) sideImg.src = bust;

            // store raw path for later
            if (hiddenPhotoPath) hiddenPhotoPath.value = data.path;

            sendProfileUpdate(data.path);
          } else {
            photoStatus.style.color='crimson'; photoStatus.textContent = (data && data.message) ? data.message : 'Upload failed';
            showStatus('Photo upload failed. Profile not saved.', 'crimson');
          }
        })
        .catch(function(err){
          console.error(err);
          photoStatus.style.color='crimson'; photoStatus.textContent='Network/server error.';
          showStatus('Photo upload failed. Profile not saved.', 'crimson');
        });
      } else {
        // no new photo, keep photoStatus hidden and keep existing photo path
        sendProfileUpdate(null);
      }
    });
  }

  // initialize readonly
  setReadonly(true);
})();

// --- Payments UI ---
const payBtn = document.getElementById('payNowBtn');
const histBtn = document.getElementById('viewHistoryBtn');
const formWrap = document.getElementById('paymentFormWrap');
const histWrap = document.getElementById('paymentHistoryWrap');
const submitPay = document.getElementById('submitPaymentBtn');
const cancelPay = document.getElementById('cancelPaymentBtn');
const payStatus = document.getElementById('paymentStatus');
const balanceEl = document.getElementById('accountBalance');
const historyBody = document.getElementById('paymentHistoryBody');

function fmtMoney(v){ return '₱'+Number(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function showPayStatus(msg,color){ payStatus.style.display= msg ? 'block':'none'; payStatus.style.color=color||'#333'; payStatus.textContent=msg||''; }

if (payBtn) payBtn.addEventListener('click', ()=>{
  // Toggle Pay form
  const showingForm = formWrap.style.display === 'none';
  formWrap.style.display = showingForm ? 'block':'none';
  // Always hide history + empty message when viewing Pay form
  histWrap.style.display = 'none';
  showPayStatus('', '');
});

if (histBtn) histBtn.addEventListener('click', ()=>{
  // Toggle History
  const showingHist = histWrap.style.display === 'none';
  histWrap.style.display = showingHist ? 'block':'none';
  // Hide form when showing history
  formWrap.style.display = 'none';
  showPayStatus('', '');
  if (showingHist) loadHistory();
});

function loadHistory(){
  historyBody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">Loading…</td></tr>';
  fetch('?action=history')
    .then(r=>r.text())
    .then(txt=>{
      let data; try{ data = JSON.parse(txt);}catch(e){ data={success:true,payments:[]}; }
      const rows = Array.isArray(data.payments) ? data.payments : [];
      if (!rows.length){
        historyBody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">No payment records found.</td></tr>';
        return;
      }
      historyBody.innerHTML = rows.map(p =>
        '<tr>'
        + '<td>'+ (p.created_at || '') +'</td>'
        + '<td>'+ (p.description || '') +'</td>'
        + '<td>'+ (p.lot_id || '—') +'</td>'
        + '<td>'+ fmtMoney(p.amount_paid || 0) +'</td>'
        + '<td>'+ (p.status || 'Paid') +'</td>'
        + '</tr>'
      ).join('');
    })
    .catch(()=>{
      historyBody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">No payment records found.</td></tr>';
    });
}

if (submitPay) submitPay.addEventListener('click', ()=>{
  const amt = parseFloat(document.getElementById('pay_amount').value||'0');
  if (isNaN(amt) || amt<=0){ showPayStatus('Enter a valid amount.','crimson'); return; }
  showPayStatus('Processing…','#333');
  const payload = {
    lot_id: parseInt(document.getElementById('pay_lot').value||'0'),
    amount: amt,
    description: document.getElementById('pay_desc').value.trim(),
    method: document.getElementById('pay_method') ? document.getElementById('pay_method').value : 'Manual',
    reference_no: document.getElementById('pay_ref') ? document.getElementById('pay_ref').value.trim() : ''
  };

  fetch('?action=pay',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json())
    .then(data=>{
      if (!data.success){ showPayStatus(data.message||'Payment failed','crimson'); return; }
      // Pending doesn’t change balance; message clarifies
      showPayStatus(data.message || 'Payment submitted for review','green');
      document.getElementById('pay_amount').value='';
      document.getElementById('pay_desc').value='';
      if (document.getElementById('pay_ref')) document.getElementById('pay_ref').value='';
      if (histWrap.style.display === 'block') loadHistory();
    })
    .catch(()=> showPayStatus('Network error','crimson'));
});

document.querySelectorAll('.notif-remove-btn').forEach(function(btn){
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var li = btn.closest('.notification-card');
    var notifId = li ? li.getAttribute('data-id') : '';
    if (!notifId) return;
    btn.disabled = true;
    btn.textContent = '...';
    fetch('remove_notification.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ id: notifId })
    })
    .then(r=>r.json())
    .then(data=>{
      if (data && data.success) {
        li.style.opacity = '0.5';
        setTimeout(function(){ li.remove(); }, 400);
      } else {
        btn.disabled = false;
        btn.textContent = '×';
        alert(data && data.message ? data.message : 'Failed to remove notification.');
      }
    })
    .catch(()=>{
      btn.disabled = false;
      btn.textContent = '×';
      alert('Network error.');
    });
  });
});

// Tab switching for profile section
document.addEventListener('DOMContentLoaded', function () {

  var tabInfo = document.getElementById('tab-profile-info');
  var tabPass = document.getElementById('tab-change-password');
  var paneInfo = document.getElementById('profile-info-pane');
  var panePass = document.getElementById('change-password-pane');

  if (!tabInfo || !tabPass || !paneInfo || !panePass) {
    console.warn("Profile tab elements not found.");
    return;
  }

  tabInfo.onclick = function() {
    tabInfo.classList.add('text-green-900', 'border-green-900');
    tabInfo.classList.remove('text-gray-600', 'border-transparent');

    tabPass.classList.remove('text-green-900', 'border-green-900');
    tabPass.classList.add('text-gray-600', 'border-transparent');

    paneInfo.style.display = '';
    panePass.style.display = 'none';
  };

  tabPass.onclick = function() {
    tabPass.classList.add('text-green-900', 'border-green-900');
    tabPass.classList.remove('text-gray-600', 'border-transparent');

    tabInfo.classList.remove('text-green-900', 'border-green-900');
    tabInfo.classList.add('text-gray-600', 'border-transparent');

    paneInfo.style.display = 'none';
    panePass.style.display = '';
  };
});

// Optional: AJAX password change handler
document.addEventListener('DOMContentLoaded', function () {
  var cpForm = document.getElementById('changePasswordForm');
  if (!cpForm) return;

  cpForm.onsubmit = function(e){
    e.preventDefault();

    var form   = this;
    var status = document.getElementById('changePasswordStatus');

    function setStatus(msg, color) {
      status.textContent = msg;
      status.style.color = color || '#333';
      status.style.display = msg ? 'block' : 'none';
    }

    var cur = form.current_password.value.trim();
    var np  = form.new_password.value.trim();
    var cp  = form.confirm_password.value.trim();

    if (!cur || !np || !cp) {
      setStatus('Please fill in all fields.', 'crimson');
      return false;
    }

    if (np !== cp) {
      setStatus('Passwords do not match.', 'crimson');
      return false;
    }

    setStatus('Changing password...', '#333');

    fetch(form.action, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        current_password: cur,
        new_password: np
      })
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        setStatus('Password changed successfully.', 'green');
        form.reset();
        setTimeout(() => setStatus('', ''), 1500);
      } else {
        // your PHP uses either 'error' or 'message'
        setStatus(data.message || data.error || 'Failed to change password.', 'crimson');
      }
    })
    .catch(() => {
      setStatus('Network error.', 'crimson');
    });

    return false;
  };
});


// --- Documents UI ---
function loadDocuments(){
  const body = document.getElementById('documentsBody');
  body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">Loading…</td></tr>';
  fetch('list_documents.php')
    .then(r=>r.json())
    .then(data=>{
      const docs = Array.isArray(data.documents) ? data.documents : [];
      if (!docs.length){
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">No documents uploaded.</td></tr>';
        return;
      }
      body.innerHTML = docs.map(d=>
        `<tr>
          <td>${d.doc_type||''}</td>
          <td><a href="${d.file_path}" target="_blank">${d.file_name}</a></td>
          <td>${d.uploaded_at||''}</td>
          <td>${d.status||''}</td>
          <td><a href="${d.file_path}" target="_blank" class="btn ghost">View</a></td>
        </tr>`
      ).join('');
    })
    .catch(()=>{ body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">No documents uploaded.</td></tr>'; });
}
document.querySelectorAll('.sidebar-link[href="#documents"]').forEach(function(link){
  link.addEventListener('click', function(){ loadDocuments(); });
});
const docForm = document.getElementById('documentUploadForm');
if (docForm) docForm.onsubmit = function(e){
  e.preventDefault();
  const status = document.getElementById('docUploadStatus');
  status.textContent = 'Uploading...';
  const fd = new FormData(docForm);
  fetch('upload_document.php', { method:'POST', body:fd })
    .then(r=>r.json())
    .then(data=>{
      if (data.success){
        status.textContent = 'Uploaded!';
        docForm.reset();
        loadDocuments();
      } else {
        status.textContent = data.message||'Upload failed';
      }
    })
    .catch(()=>{ status.textContent = 'Network error'; });
};

</script>

</body>
</html>
