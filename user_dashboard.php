<?php
// filepath: user_dashboard.php
session_start();

// 1. LOGIN CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: Login/login.php");
    exit;
}

/* ---------------- DB CONNECTION ---------------- */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ---------------- HELPER FUNCTIONS ---------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Check if a column exists to prevent crashes
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

// Wrapper to prepare SQL and catch errors
function prepOrDie(mysqli $conn, string $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Database Error: " . $conn->error . "<br>SQL: $sql");
    }
    return $stmt;
}

// >>> PAYMENTS AJAX HANDLER (Handles Pay Now & History) >>>
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

    // Fetch History
    if ($action === 'history') {
        if (!hasTable($conn,'payments')) {
            echo json_encode(['success'=>true,'payments'=>[]]); exit;
        }
        $stmt = prepOrDie($conn, "SELECT payment_date AS created_at, remarks AS description, lot_id, amount_paid, status FROM payments WHERE user_id=? ORDER BY payment_date DESC LIMIT 20");
        $stmt->bind_param('i',$uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        echo json_encode(['success'=>true,'payments'=>$rows]); exit;
    }

    // Process Payment
    if ($action === 'pay') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw,true);
        
        $lot_id  = (int)($data['lot_id'] ?? 0);
        $amount  = (float)($data['amount'] ?? 0);
        $remarks = trim((string)($data['description'] ?? 'Payment'));
        $method  = trim((string)($data['method'] ?? 'Manual'));
        
        if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid amount']); exit; }

        // Create payments table if missing (safety check)
        $conn->query("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT, lot_id INT, amount_paid DECIMAL(10,2), 
            payment_date DATETIME, payment_method VARCHAR(50), 
            reference_no VARCHAR(100), status VARCHAR(50), remarks TEXT
        )");

        $stmt = prepOrDie($conn,"INSERT INTO payments (user_id, lot_id, amount_paid, payment_date, payment_method, status, remarks) VALUES (?, ?, ?, NOW(), ?, 'Pending', ?)");
        $stmt->bind_param('iidss', $uid, $lot_id, $amount, $method, $remarks);
        
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Payment submitted successfully']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Database error']);
        }
        $stmt->close();
        exit;
    }
    exit;
}

/* ---------------- 1. FETCH USER DATA (FIXED LOGIN CRASH) ---------------- */
$user_id = (int)$_SESSION['user_id'];
$user = [
    'first_name' => '', 'middle_name' => '', 'last_name' => '', 
    'username' => '', 'email' => '', 'address' => '', 'mobile' => '', 'photo' => ''
];

if (hasTable($conn, 'user_accounts')) {
    // Determine which columns actually exist to prevent "Unknown column" error
    $cols = ['first_name','middle_name','last_name','username','email','address'];
    
    // CHECK BOTH MOBILE COLUMN NAMES
    $hasMobileNum = hasColumn($conn, 'user_accounts', 'mobile_number');
    $hasMobile    = hasColumn($conn, 'user_accounts', 'mobile');
    
    if ($hasMobileNum) $cols[] = 'mobile_number';
    if ($hasMobile)    $cols[] = 'mobile';
    if (hasColumn($conn,'user_accounts','photo')) $cols[] = 'photo';
    if (hasColumn($conn,'user_accounts','role'))  $cols[] = 'role';

    $colList = implode(', ', $cols);
    $stmt = prepOrDie($conn, "SELECT $colList FROM user_accounts WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows) { 
        $userData = $res->fetch_assoc();
        $user = array_merge($user, $userData);
        
        // SMART MOBILE DETECTION: Use whichever column has data
        $m1 = $userData['mobile_number'] ?? '';
        $m2 = $userData['mobile'] ?? '';
        $user['mobile'] = !empty($m1) ? $m1 : $m2;
    } else {
        // User ID in session but not in DB? Force logout.
        session_destroy();
        header("Location: Login/login.php");
        exit;
    }
    $stmt->close();
}

$user_email = $user['email'] ?? '';
$sidebarName = trim(($user['first_name']??'') . ' ' . ($user['last_name']??'')) ?: ($user['username']??'User');
$roleLabel = $user['role'] ?? 'Client';

/* ---------------- 2. FETCH PROPERTIES (LOTS) ---------------- */
$listings = [];
$lotsOwned = 0;
$reservedLots = 0;

if (hasTable($conn,'lots') && hasColumn($conn,'lots','owner_id')) {
    $cols = "id, block_number, lot_number, lot_size, lot_price";
    if (hasColumn($conn,'lots','agent_id')) $cols .= ", agent_id";
    
    $stmt = prepOrDie($conn, "SELECT $cols FROM lots WHERE owner_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { 
        $listings = $res->fetch_all(MYSQLI_ASSOC); 
        $lotsOwned = count($listings);
    }
    $stmt->close();

    if (hasColumn($conn,'lots','status')) {
        $stmt = prepOrDie($conn, "SELECT COUNT(*) as c FROM lots WHERE owner_id = ? AND status = 'Reserved'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reservedLots = $row['c'] ?? 0;
        $stmt->close();
    }
}

/* ---------------- 3. FETCH VIEWINGS & NOTIFICATIONS ---------------- */
$upcomingViewings = [];
$recentActivities = [];

if (hasTable($conn,'viewings')) {
    // FIX: Add 'v.' prefix to explicitly select status from the viewings table
    $statusCol = hasColumn($conn,'viewings','status') ? 'v.status' : "'' as status";
    
    $agentJoin = hasTable($conn,'agent_accounts') ? "LEFT JOIN agent_accounts a ON v.agent_id = a.id" : "";
    $agentCols = hasTable($conn,'agent_accounts') ? ", a.first_name as agent_first, a.last_name as agent_last, a.email as agent_email" : "";

    // Fetch Viewings
    $sql = "SELECT v.id, v.lot_no, v.preferred_at, $statusCol $agentCols 
            FROM viewings v $agentJoin 
            WHERE v.client_email = ? 
            ORDER BY v.preferred_at DESC LIMIT 20";
            
    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { 
        $data = $res->fetch_all(MYSQLI_ASSOC);
        // Split into upcoming and recent for notifications
        foreach($data as $row) {
            $upcomingViewings[] = $row; 
            $recentActivities[] = $row; 
        }
    }
    $stmt->close();
}

/* ---------------- 4. CALCULATE OUTSTANDING BALANCE ---------------- */
$outstandingBalance = 0.0;
if (!empty($listings) && hasTable($conn,'payments') && hasColumn($conn,'payments','amount_paid')) {
    foreach ($listings as $lot) {
        $l_id = (int)$lot['id'];
        $price = (float)($lot['lot_price'] ?? 0);
        
        $stmt = prepOrDie($conn, "SELECT SUM(amount_paid) as paid FROM payments WHERE user_id=? AND lot_id=?");
        $stmt->bind_param("ii", $user_id, $l_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $paid = (float)($row['paid'] ?? 0);
        $stmt->close();
        
        $outstandingBalance += max(0, $price - $paid);
    }
}

/* ---------------- 5. FETCH ASSIGNED AGENT ---------------- */
$agent = null;
// Try to get agent from the most recent viewing
if (!empty($upcomingViewings) && !empty($upcomingViewings[0]['first_name'])) {
    $agent = [
        'first_name' => $upcomingViewings[0]['first_name'],
        'last_name' => $upcomingViewings[0]['last_name'],
        'email' => $upcomingViewings[0]['agent_email'] ?? 'N/A',
        'photo_path' => 'assets/Default_photo.jpg' // Default
    ];
}

$conn->close();

// Avatar handling
$avatarSrc = !empty($user['photo']) ? $user['photo'] : 'assets/Default_photo.jpg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Dashboard | Nuevo Puerta</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
:root { --green:#14532d; --light-green:#e6f4ea; --white:#ffffff; --bg:#f8f9fa; --text:#333; --muted:#666; --red:#dc3545; }
body { font-family:'Poppins', sans-serif; background:var(--bg); margin:0; color:var(--text); overflow-x: hidden; }
.dashboard-wrapper { display:flex; min-height:100vh; }

/* --- Sidebar --- */
.sidebar { width:280px; background:var(--green); color:var(--white); padding:30px 0; position:fixed; top:0; bottom:0; overflow-y:auto; transition:0.3s; z-index:1000; }
.sidebar-logo { text-align:center; margin-bottom:30px; padding:0 20px; }
.sidebar-logo img { width:70px; height:70px; border-radius:50%; background:rgba(255,255,255,0.1); padding:5px; margin-bottom:10px; }
.sidebar-logo h2 { margin:0; font-size:20px; font-weight:700; letter-spacing:1px; }
.sidebar-logo span { font-size:12px; opacity:0.8; letter-spacing:2px; text-transform:uppercase; }

.sidebar-user { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.1); margin:0 20px 30px; padding:12px; border-radius:12px; }
.sidebar-user img { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#fff; }
.sidebar-user div { line-height:1.2; }
.sidebar-user h3 { margin:0; font-size:14px; font-weight:600; }
.sidebar-user span { font-size:11px; opacity:0.8; }

.nav-link { display:flex; align-items:center; gap:12px; padding:14px 25px; color:rgba(255,255,255,0.8); text-decoration:none; transition:0.2s; border-left:4px solid transparent; font-size:15px; }
.nav-link:hover, .nav-link.active { background:rgba(255,255,255,0.1); color:#fff; border-left-color:#fff; }
.nav-link i { width:20px; text-align:center; font-size:18px; }

/* --- Main Content --- */
.main-content { margin-left:280px; flex:1; padding:30px 40px; }
.section { display:none; animation: fadeIn 0.4s ease; }
.section.active { display:block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

h2 { font-size:28px; font-weight:700; color:var(--green); margin-bottom:5px; }
.subtitle { color:var(--muted); margin-bottom:25px; display:block; }

/* --- Cards --- */
.card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:30px; }
.stat-card { background:var(--white); padding:20px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.05); display:flex; align-items:center; gap:16px; border:1px solid #eee; }
.stat-icon { width:56px; height:56px; background:var(--light-green); border-radius:10px; display:flex; align-items:center; justify-content:center; color:var(--green); font-size:24px; flex-shrink:0; }
.stat-info h3 { margin:0; font-size:28px; font-weight:700; color:var(--text); line-height:1; }
.stat-info span { font-size:14px; color:var(--muted); }

/* --- Tables & Lists --- */
.content-box { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.05); padding:25px; border:1px solid #eee; margin-bottom:30px; }
.box-header { font-size:18px; font-weight:700; margin-bottom:15px; color:var(--green); border-bottom:1px solid #f0f0f0; padding-bottom:15px; }

table { width:100%; border-collapse:collapse; min-width:600px; }
th, td { padding:14px 15px; text-align:left; border-bottom:1px solid #f0f0f0; font-size:14px; }
th { font-weight:600; color:var(--muted); background:#f9f9f9; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; }
tr:last-child td { border-bottom:none; }

.notification-list { list-style:none; padding:0; margin:0; }
.notif-item { padding:15px; border-bottom:1px solid #f0f0f0; display:flex; align-items:start; gap:15px; }
.notif-item:last-child { border-bottom:none; }
.notif-icon { width:36px; height:36px; background:#eef2ff; color:#4f46e5; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
.notif-content strong { display:block; font-size:14px; margin-bottom:2px; }
.notif-content span { font-size:12px; color:var(--muted); }

/* --- Forms --- */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { margin-bottom:15px; }
.form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:13px; color:var(--text); }
.form-control { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:14px; box-sizing:border-box; transition:0.2s; }
.form-control:focus { border-color:var(--green); outline:none; }
.form-control[readonly] { background:#f9f9f9; color:#666; }

.btn { padding:10px 24px; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s; display:inline-block; }
.btn-primary { background:var(--green); color:var(--white); }
.btn-primary:hover { background:#0f4223; }
.btn-secondary { background:#e9ecef; color:#333; }

.badge { padding:4px 10px; border-radius:50px; font-size:11px; font-weight:700; text-transform:uppercase; }
.badge.scheduled { background:#e0f2fe; color:#0284c7; }
.badge.pending { background:#fef3c7; color:#d97706; }
.badge.paid { background:#dcfce7; color:#166534; }

/* --- Agent Card --- */
.agent-card { display:flex; align-items:center; gap:20px; background:#f9f9f9; padding:20px; border-radius:12px; border:1px solid #eee; }
.agent-img { width:80px; height:80px; border-radius:50%; object-fit:cover; }
.agent-details h3 { margin:0 0 5px; font-size:18px; }
.agent-details p { margin:0; font-size:14px; color:var(--muted); }

/* Mobile */
@media (max-width: 900px) {
    .sidebar { width:0; padding:0; overflow:hidden; }
    .main-content { margin-left:0; padding:20px; }
    .mobile-menu-btn { display:block; }
}
</style>
</head>
<body>

<div class="dashboard-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="assets/f.png" alt="Logo" onerror="this.src='assets/Default_photo.jpg'">
            <h2>NUEVO PUERTA</h2>
            <span>Real Estate</span>
        </div>
        
        <div class="sidebar-user">
            <img src="<?php echo h($avatarSrc); ?>" alt="User">
            <div>
                <h3><?php echo h($sidebarName); ?></h3>
                <span><?php echo h($roleLabel); ?></span>
            </div>
        </div>

        <nav>
            <a href="#dashboard" class="nav-link active" onclick="switchTab('dashboard', this)"><i class="fa fa-th-large"></i> Dashboard</a>
            <a href="#profile" class="nav-link" onclick="switchTab('profile', this)"><i class="fa fa-user"></i> My Profile</a>
            <a href="#properties" class="nav-link" onclick="switchTab('properties', this)"><i class="fa fa-building"></i> Properties</a>
            <a href="#payments" class="nav-link" onclick="switchTab('payments', this)"><i class="fa fa-credit-card"></i> Payments</a>
            <a href="#viewings" class="nav-link" onclick="switchTab('viewings', this)"><i class="fa fa-calendar-check"></i> Viewings</a>
            <a href="#documents" class="nav-link" onclick="switchTab('documents', this)"><i class="fa fa-folder-open"></i> Documents</a>
            <a href="#notifications" class="nav-link" onclick="switchTab('notifications', this)"><i class="fa fa-bell"></i> Notifications</a>
            <a href="#agent" class="nav-link" onclick="switchTab('agent', this)"><i class="fa fa-user-tie"></i> My Agent</a>
            <a href="logout.php" class="nav-link" style="margin-top:20px; color:#ffadad;"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-content">
        
        <div id="dashboard" class="section active">
            <h2>Dashboard</h2>
            <span class="subtitle">Welcome back, <?php echo h($user['first_name']); ?></span>

            <div class="card-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa fa-home"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $lotsOwned; ?></h3>
                        <span>Properties Owned</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa fa-file-signature"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $reservedLots; ?></h3>
                        <span>Reserved Lots</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fee2e2; color:#dc2626;"><i class="fa fa-coins"></i></div>
                    <div class="stat-info">
                        <h3>₱<?php echo number_format($outstandingBalance, 2); ?></h3>
                        <span>Balance Due</span>
                    </div>
                </div>
            </div>

            <div class="content-box">
                <div class="box-header">Recent Viewings & Activity</div>
                <?php if(empty($recentActivities)): ?>
                    <p style="color:var(--muted); text-align:center;">No recent activity.</p>
                <?php else: ?>
                    <ul class="notification-list">
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-calendar-day"></i></div>
                            <div class="notif-content">
                                <strong>Viewing for Lot <?php echo h($act['lot_no']); ?></strong>
                                <span><?php echo date('F d, Y h:i A', strtotime($act['preferred_at'])); ?> • Status: <?php echo h($act['status']); ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div id="profile" class="section">
            <h2>My Profile</h2>
            <span class="subtitle">Manage your personal information</span>

            <div class="content-box">
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" class="form-control" value="<?php echo h($user['first_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" class="form-control" value="<?php echo h($user['last_name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" class="form-control" value="<?php echo h($user['username']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="text" class="form-control" value="<?php echo h($user['email']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" class="form-control" value="<?php echo h($user['mobile']); ?>" readonly>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Address</label>
                        <input type="text" class="form-control" value="<?php echo h($user['address']); ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div id="properties" class="section">
            <h2>My Properties</h2>
            <span class="subtitle">List of lots you own or have reserved</span>

            <div class="card-grid">
                <?php if(empty($listings)): ?>
                    <div class="content-box" style="grid-column: span 3; text-align:center;">
                        <p>No properties found linked to this account.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($listings as $lot): ?>
                    <div class="stat-card" style="display:block; border-left:5px solid var(--green);">
                        <h4 style="margin:0 0 10px; color:var(--green); font-size:18px;">
                            Block <?php echo h($lot['block_number']); ?>, Lot <?php echo h($lot['lot_number']); ?>
                        </h4>
                        <div style="font-size:14px; margin-bottom:5px;">
                            <strong>Size:</strong> <?php echo h($lot['lot_size']); ?> sqm
                        </div>
                        <div style="font-size:14px; margin-bottom:15px;">
                            <strong>Price:</strong> ₱<?php echo number_format($lot['lot_price'], 2); ?>
                        </div>
                        <button class="btn btn-secondary" style="width:100%; font-size:12px;">View Details</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="payments" class="section">
            <h2>Payments</h2>
            <span class="subtitle">Manage your balance and transactions</span>

            <div class="content-box">
                <div class="box-header">Make a Payment</div>
                <form id="paymentForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Select Property</label>
                            <select id="pay_lot" class="form-control">
                                <?php foreach($listings as $lot): ?>
                                    <option value="<?php echo $lot['id']; ?>">Blk <?php echo $lot['block_number']; ?> - Lot <?php echo $lot['lot_number']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount (₱)</label>
                            <input type="number" id="pay_amount" class="form-control" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select id="pay_method" class="form-control">
                                <option>Bank Deposit</option>
                                <option>GCash</option>
                                <option>Over the Counter</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Reference No.</label>
                            <input type="text" id="pay_ref" class="form-control" placeholder="Optional">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes / Description</label>
                        <input type="text" id="pay_desc" class="form-control" placeholder="e.g. Monthly Amortization">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="submitPayment()">Submit Payment</button>
                    <span id="payMsg" style="margin-left:10px; font-weight:600;"></span>
                </form>
            </div>

            <div class="content-box">
                <div class="box-header">Payment History</div>
                <div id="paymentHistoryTable">Loading...</div>
            </div>
        </div>

        <div id="viewings" class="section">
            <h2>My Viewings</h2>
            <span class="subtitle">Scheduled visits to properties</span>

            <div class="content-box">
                <table>
                    <thead>
                        <tr><th>Lot</th><th>Date & Time</th><th>Agent</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($upcomingViewings)): ?>
                            <tr><td colspan="4" style="text-align:center;">No viewings found.</td></tr>
                        <?php else: ?>
                            <?php foreach($upcomingViewings as $v): ?>
                            <tr>
                                <td><?php echo h($v['lot_no']); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($v['preferred_at'])); ?></td>
                                <td>
                                    <?php echo h(($v['agent_first']??'') . ' ' . ($v['agent_last']??'')); ?>
                                </td>
                                <td><span class="badge scheduled"><?php echo h($v['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="documents" class="section">
            <h2>Documents</h2>
            <span class="subtitle">Contracts and uploaded files</span>
            <div class="content-box">
                <p style="text-align:center; color:var(--muted);">Document management coming soon.</p>
            </div>
        </div>

        <div id="notifications" class="section">
            <h2>Notifications</h2>
            <span class="subtitle">System alerts</span>
            <div class="content-box">
                <ul class="notification-list">
                    <?php if(!empty($recentActivities)): ?>
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-bell"></i></div>
                            <div class="notif-content">
                                <strong>System Notification</strong>
                                <span>Your viewing for Lot <?php echo h($act['lot_no']); ?> is <?php echo h($act['status']); ?>.</span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li style="padding:15px; text-align:center;">No new notifications.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div id="agent" class="section">
            <h2>My Agent</h2>
            <span class="subtitle">Your assigned sales agent</span>
            <div class="content-box">
                <?php if($agent): ?>
                <div class="agent-card">
                    <img src="assets/Default_photo.jpg" class="agent-img" alt="Agent">
                    <div class="agent-details">
                        <h3><?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?></h3>
                        <p><i class="fa fa-envelope"></i> <?php echo h($agent['email']); ?></p>
                        <div style="margin-top:10px;">
                            <button class="btn btn-primary btn-sm">Message</button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <p style="text-align:center;">No agent assigned to you yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<script>
function switchTab(id, link) {
    // Hide all sections
    document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
    // Deactivate nav links
    document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
    
    // Show target section
    document.getElementById(id).classList.add('active');
    // Activate clicked link
    link.classList.add('active');
}

function submitPayment() {
    const btn = document.querySelector('#paymentForm button');
    const msg = document.getElementById('payMsg');
    const amt = document.getElementById('pay_amount').value;
    const lot = document.getElementById('pay_lot').value;
    const method = document.getElementById('pay_method').value;
    const desc = document.getElementById('pay_desc').value;

    if(amt <= 0) { alert("Please enter a valid amount."); return; }

    btn.disabled = true;
    btn.innerText = "Processing...";

    fetch('?action=pay', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ amount: amt, lot_id: lot, method: method, description: desc })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = "Submit Payment";
        if(data.success) {
            msg.innerHTML = '<span style="color:green">' + data.message + '</span>';
            document.getElementById('pay_amount').value = '';
            loadHistory();
        } else {
            msg.innerHTML = '<span style="color:red">' + data.message + '</span>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        msg.innerText = "Error processing request.";
    });
}

function loadHistory() {
    const container = document.getElementById('paymentHistoryTable');
    fetch('?action=history')
    .then(r => r.json())
    .then(data => {
        if(!data.payments || data.payments.length === 0) {
            container.innerHTML = "<p style='text-align:center; color:#888;'>No payment records found.</p>";
            return;
        }
        let html = '<table><thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
        data.payments.forEach(p => {
            html += `<tr>
                <td>${p.created_at}</td>
                <td>${p.description}</td>
                <td>₱${parseFloat(p.amount_paid).toFixed(2)}</td>
                <td><span class="badge ${p.status === 'Paid' ? 'paid' : 'pending'}">${p.status}</span></td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    });
}

// Initial Load
loadHistory();
</script>

</body>
</html>