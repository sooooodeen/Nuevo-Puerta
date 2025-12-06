<?php
/* agent_dashboard.php — static sidebar SPA with white icons and section switching */

session_start();

/* ---- Allow both session styles ---- */
if (!isset($_SESSION['agent_id'])) {
  if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header("Location: Login/index.html");
    exit();
  }
}

/* ---- DB hookup ---- */
if (file_exists(__DIR__ . '/dbconn.php')) {
  require_once __DIR__ . '/dbconn.php';
}
if (!isset($conn) || !($conn instanceof mysqli)) {
  $conn = new mysqli("localhost", "root", "", "nuevopuerta");
}
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

date_default_timezone_set('Asia/Manila');

/* ---- Resolve agent id if only username exists ---- */
$agentId = (int)($_SESSION['agent_id'] ?? 0);
if ($agentId === 0) {
  $username = (string)($_SESSION['user'] ?? '');
  if ($username !== '') {
    if ($stmt = $conn->prepare("SELECT id FROM agent_accounts WHERE username=? LIMIT 1")) {
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        $agentId = (int)$row['id'];
        $_SESSION['agent_id'] = $agentId;
      }
      $stmt->close();
    }
  }
}
if ($agentId === 0) {
  header("Location: Login/index.html");
  exit();
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---- CSRF ---- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check() {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
}

/* ---- Safe bootstrap tables ---- */
$conn->query("
  CREATE TABLE IF NOT EXISTS viewings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    client_first_name VARCHAR(100),
    client_last_name  VARCHAR(100),
    client_email      VARCHAR(150),
    client_phone      VARCHAR(20),
    lot_no            VARCHAR(50),
    preferred_at      DATETIME,
    status ENUM('scheduled','rescheduled','completed','no_show_agent','no_show_client','cancelled') DEFAULT 'scheduled',
    client_lat DECIMAL(10,8),
    client_lng DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_viewings_agent (agent_id, preferred_at, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$conn->query("
  CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    name VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(150),
    message TEXT,
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_agent (agent_id, is_read, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---- POST actions (toggle availability / quick viewing status) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  if (isset($_POST['toggle_avail'])) {
    $isAvail = 1;
    if ($stmt = $conn->prepare("SELECT IFNULL(is_available,1) AS a FROM agent_accounts WHERE id=?")) {
      $stmt->bind_param('i', $agentId);
      $stmt->execute();
      $r = $stmt->get_result();
      if ($row = $r->fetch_assoc()) $isAvail = (int)$row['a'];
      $stmt->close();
    }
    $newVal = $isAvail ? 0 : 1;
    if ($stmt = $conn->prepare("UPDATE agent_accounts SET is_available=? WHERE id=?")) {
      $stmt->bind_param('ii', $newVal, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#dashboard');
    exit;
  }

  if (isset($_POST['viewing_action'], $_POST['viewing_id'])) {
    $vid = (int)$_POST['viewing_id'];
    $action = $_POST['viewing_action'];
    $allowed = ['completed','no_show_agent','no_show_client','cancelled','scheduled'];
    if (in_array($action, $allowed, true)) {
      if ($stmt = $conn->prepare("UPDATE viewings SET status=? WHERE id=? AND agent_id=?")) {
        $stmt->bind_param('sii', $action, $vid, $agentId);
        $stmt->execute();
        $stmt->close();
      }
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'dashboard'));
    exit;
  }
}

/* ---- KPIs ---- */
$kpis = [
  'total_sales' => 0,
  'month_sales' => 0,
  'upcoming_viewings' => 0,
  'unread_messages' => 0,
  'is_available' => 1,
  'full_name' => 'Agent',
];

if ($stmt = $conn->prepare("SELECT first_name,last_name,IFNULL(is_available,1) is_available FROM agent_accounts WHERE id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) {
    $kpis['full_name']    = trim(($row['first_name'] ?? 'Agent').' '.($row['last_name'] ?? ''));
    $kpis['is_available'] = (int)$row['is_available'];
  }
  $stmt->close();
}
if ($stmt = $conn->prepare("SELECT COUNT(*) c FROM sales WHERE agent_id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['total_sales'] = (int)$row['c'];
  $stmt->close();
}
if ($stmt = $conn->prepare("
  SELECT COUNT(*) c FROM sales
  WHERE agent_id=? AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['month_sales'] = (int)$row['c'];
  $stmt->close();
}
if ($stmt = $conn->prepare("
  SELECT COUNT(*) c FROM viewings
  WHERE agent_id=? AND status IN ('scheduled','rescheduled','requested') AND preferred_at>=NOW()
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['upcoming_viewings'] = (int)$row['c'];
  $stmt->close();
}
if ($stmt = $conn->prepare("
  SELECT COUNT(*) c FROM messages WHERE agent_id=? AND (is_read=0 OR is_read IS NULL)
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['unread_messages'] = (int)$row['c'];
  $stmt->close();
}

/* ---- Upcoming viewings list (DASHBOARD) ---- */
$viewings = [];
if ($stmt = $conn->prepare("
  SELECT v.id,
         v.client_first_name, v.client_last_name,
         v.client_email, v.client_phone,
         v.preferred_at, v.status,
         v.lot_no,                -- legacy fallback
         l.block_number, l.lot_number,
         ll.location_name
  FROM viewings v
  LEFT JOIN lots l           ON v.lot_id = l.id
  LEFT JOIN lot_locations ll ON v.location_id = ll.id
  WHERE v.agent_id=? AND v.status IN ('scheduled','rescheduled') AND v.preferred_at>=NOW()
  ORDER BY v.preferred_at ASC
  LIMIT 12
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) $viewings = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}


/* ---- (Optional) Load profile data for the Profile section ---- */
$agent = [
  'first_name' => '', 'last_name' => '', 'email' => '',
  'mobile' => '', 'address' => '', 'experience' => 0,
  'total_sales' => 0, 'description' => '', 'profile_picture' => ''
];
if ($stmt = $conn->prepare("SELECT * FROM agent_accounts WHERE id=? LIMIT 1")) {
  $stmt->bind_param("i", $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $agent = $row;
  $stmt->close();
}

/* ---- Fetch ALL viewings for this agent (for the viewings section) ---- */
$all_viewings = [];
if ($stmt = $conn->prepare("
  SELECT v.*, ll.location_name, l.block_number, l.lot_number, l.lot_size, l.lot_price
  FROM viewings v
  LEFT JOIN lot_locations ll ON v.location_id = ll.id
  LEFT JOIN lots l ON v.lot_id = l.id
  WHERE v.agent_id = ?
  ORDER BY v.preferred_at DESC
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) $all_viewings = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ---- Fetch assigned leads for this agent ---- */
$leads = [];
if ($stmt = $conn->prepare("
  SELECT l.*, CONCAT(a.first_name, ' ', a.last_name) AS agent_name
  FROM leads l
  LEFT JOIN agent_accounts a ON l.agent_id = a.id
  WHERE l.agent_id = ?
  ORDER BY l.created_at DESC
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) $leads = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'notifications') {
    $notifications = [];
    $stmt = $conn->prepare("SELECT * FROM agent_notifications WHERE agent_id=? ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit;
}

// Mark notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notif_id = (int)$_POST['mark_read'];
    $stmt = $conn->prepare("UPDATE agent_notifications SET is_read=1 WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $notif_id, $agentId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// Delete notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notif'])) {
    $notif_id = (int)$_POST['delete_notif'];
    $stmt = $conn->prepare("DELETE FROM agent_notifications WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $notif_id, $agentId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// Fetch messages
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'messages') {
    $messages = [];
    $stmt = $conn->prepare("SELECT * FROM messages WHERE agent_id=? ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($messages);
    exit;
}

// Mark message as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_message_read'])) {
    $msg_id = (int)$_POST['mark_message_read'];
    $stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $msg_id, $agentId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// Delete message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $msg_id = (int)$_POST['delete_message'];
    $stmt = $conn->prepare("DELETE FROM messages WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $msg_id, $agentId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'audit_logs') {
    $logs = [];
    $stmt = $conn->prepare("SELECT * FROM agent_audit_logs WHERE agent_id=? ORDER BY created_at DESC LIMIT 100");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($logs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'documents') {
    $docs = [];
    $stmt = $conn->prepare("SELECT * FROM agent_documents WHERE agent_id=? ORDER BY created_at DESC");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'], $_POST['confirm_password'])) {
    if ($_POST['new_password'] === $_POST['confirm_password'] && $_POST['new_password'] !== '') {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE agent_accounts SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $agentId);
        $stmt->execute();
        $stmt->close();
        // Optionally set a success message
    } else {
        // Optionally set an error message
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'user_documents') {
    $docs = [];
    // Only show documents for users assigned to this agent
    // Assumes user_accounts table has an agent_id column
    $stmt = $conn->prepare("
        SELECT d.*, u.first_name, u.last_name 
        FROM user_documents d 
        LEFT JOIN user_accounts u ON d.user_id = u.id 
        WHERE u.agent_id = ? 
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Agent Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* keep sidebar fixed-feel on left */
  .nav-active { background-color: rgb(22 101 52 / .45); }
</style>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="flex min-h-screen">
  <!-- STATIC SIDEBAR -->
  <aside class="w-72 bg-green-900 text-white flex flex-col items-center py-8" style="height: 100vh; position: fixed; left: 0; top: 0; bottom: 0; z-index: 10; overflow: hidden;">
  <div class="flex items-center gap-3 mb-8">
  <img src="logo.png" alt="Logo" class="w-16 h-16 rounded-full bg-white/10 object-contain" />
  <div>
    <h2 class="font-bold text-lg tracking-wide whitespace-nowrap leading-tight">NUEVO PUERTA</h2>
    <span class="text-xs font-normal text-white/90 leading-none block mt-0.5">REAL ESTATE</span>
  </div>
</div>

   <div class="bg-white/10 rounded-xl px-4 py-3 mb-8 w-56 mx-auto flex items-center">
  <div class="mr-3">
    <?php if (!empty($agent['profile_picture'])): ?>
      <img src="<?php echo h($agent['profile_picture']); ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover bg-white" onerror="this.style.display='none'">
    <?php else: ?>
      <div class="w-10 h-10 rounded-full bg-white text-green-900 grid place-items-center font-bold">
        <?php echo strtoupper(substr($kpis['full_name'],0,1)); ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="leading-tight">
    <div class="font-semibold text-sm text-white"><?php echo h($kpis['full_name']); ?></div>
    <div class="text-xs text-white/80">Agent</div>
  </div>
</div>

    <nav class="w-full">
      <ul class="space-y-1 w-full" id="spa-nav">
        <li>
          <a href="#dashboard" data-target="section-dashboard"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <!-- white home icon -->
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7A1 1 0 003 11h1v6a1 1 0 001 1h3a1 1 0 001-1v-3h2v3a1 1 0 001 1h3a1 1 0 001-1v-6h1a1 1 0 00.707-1.707l-7-7z"/>
            </svg>
            Dashboard
          </a>
        </li>
        <li>
          <a href="#profile" data-target="section-profile"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10 10a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 1114 0H3z"/>
            </svg>
            Profile
          </a>
        </li>
        <li>
          <a href="#viewings" data-target="section-viewings"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z"/>
            </svg>
            Viewings
          </a>
        </li>
        <li>
          <a href="#sales" data-target="section-sales"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M3 13h6v8H3zM9 3h6v18H9zM15 9h6v12h-6z"/>
            </svg>
            Sales
          </a>
        </li>
        <li>
          <a href="#notifications" data-target="section-notifications"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V9a6 6 0 10-12 0v7L4 18v1h16v-1l-2-2z"/>
            </svg>
            Notifications
          </a>
        </li>

        <li>
  <a href="#messages" data-target="section-messages"
     class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
    <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
      <path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/>
    </svg>
    Messages
  </a>
</li>

<li>
  <a href="#audit-logs" data-target="section-audit-logs"
     class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
    <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
      <path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm7 7h2v2h-2v-2z"/>
    </svg>
    Audit Logs
  </a>
</li>

<li>
  <a href="#documents" data-target="section-documents"
     class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
    <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
      <path d="M6 2v6h12V2H6zm0 8v12h12V10H6zm2 2h8v8H8v-8z"/>
    </svg>
    Document Review
  </a>
</li>
        <li>
          <a href="#leads" data-target="section-leads"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm7 7h2v2h-2v-2z"/>
            </svg>
            Leads
          </a>
        </li>
        <li>
          <a href="#" onclick="confirmLogout()" class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M16 13v-2H7V8l-5 4 5 4v-3zM20 3h-8v2h8v14h-8v2h8a2 2 0 002-2V5a2 2 0 00-2-2z"/>
            </svg>
            Logout
          </a>
        </li>
      </ul>
    </nav>
  </aside>

  <!-- MAIN PANE -->
  <main class="flex-1 p-8 mt-8 ml-72 overflow-y-auto" style="height: 100vh;">
    <!-- DASHBOARD -->
    <section id="section-dashboard">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-4xl font-bold text-green-900">Welcome, <?php echo h(explode(' ',$kpis['full_name'])[0] ?? 'Agent'); ?></h1>
          <p class="text-gray-700">Monitor client requests for lot viewing.</p>
        </div>
        <form method="post" class="flex items-center gap-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
          <button name="toggle_avail" value="1"
                  class="px-4 py-2 rounded-lg font-semibold text-white bg-green-800 hover:bg-green-900 shadow">
            <?php echo $kpis['is_available'] ? 'Set Unavailable' : 'Set Available'; ?>
          </button>
          <span class="px-3 py-1 rounded-full text-sm border
            <?php echo $kpis['is_available'] ? 'bg-green-50 text-green-800 border-green-300' : 'bg-gray-100 text-gray-700 border-gray-300'; ?>">
            <?php echo $kpis['is_available'] ? 'Available' : 'Unavailable'; ?>
          </span>
        </form>
      </div>

    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mt-6">
  <div class="bg-white rounded-2xl border shadow p-5">
    <div class="flex items-center gap-3 text-green-900 font-semibold">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M3 13h6v8H3zM9 3h6v18H9zM15 9h6v12h-6z"/></svg>
      Total Sales
    </div>
    <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['total_sales']); ?></div>
    <div class="text-xs text-gray-500 mt-1">All time</div>
  </div>
  <div class="bg-white rounded-2xl border shadow p-5">
    <div class="flex items-center gap-3 text-green-900 font-semibold">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#fff">M</text></svg>
      Sales (This Month)
    </div>
    <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['month_sales']); ?></div>
    <div class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></div>
  </div>
  <div class="bg-white rounded-2xl border shadow p-5">
    <div class="flex items-center gap-3 text-green-900 font-semibold">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M17 8a5 5 0 00-10 0c0 3.87 5 9 5 9s5-5.13 5-9z"/><circle cx="12" cy="8" r="2"/></svg>
      Upcoming Viewings
    </div>
    <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['upcoming_viewings']); ?></div>
    <div class="text-xs text-gray-500 mt-1">Scheduled / Rescheduled</div>
  </div>
  <div class="bg-white rounded-2xl border shadow p-5">
    <div class="flex items-center gap-3 text-green-900 font-semibold">
      <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/></svg>
      Unread Messages
    </div>
    <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['unread_messages']); ?></div>
    <div class="text-xs text-gray-500 mt-1">Needs attention</div>
  </div>
</section>

       <!-- Add these sections below KPI cards and above Upcoming Viewings -->
    <section class="mt-8">
      <div class="bg-white rounded-2xl border shadow p-6">
        <div class="font-semibold text-gray-800 mb-4">Recent Activities</div>
        <ul class="text-sm text-gray-700 space-y-2">
          <li>✅ No recent activities yet.</li>
          <!-- Later, fill with PHP: -->
          <!--
          <?php foreach ($recent_activities as $activity): ?>
            <li><?php echo h($activity); ?></li>
          <?php endforeach; ?>
          -->
        </ul>
      </div>
    </section>

   

      <section class="mt-8">
        <div class="bg-white rounded-2xl border shadow p-6">
          <div class="flex items-center gap-2 font-semibold text-gray-800 mb-4">
            <span>👁️</span> Upcoming Viewings
          </div>

          <?php if (empty($viewings)): ?>
            <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm">
              No upcoming viewings.
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="min-w-full border rounded text-sm">
               <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="py-2 px-4 text-left border">Client</th>
                  <th class="py-2 px-4 text-left border">Location &amp; Lot</th>
                  <th class="py-2 px-4 text-left border">Date &amp; Time</th>
                  <th class="py-2 px-4 text-left border">Status</th>
                  <th class="py-2 px-4 text-left border">Contact</th>
                  <th class="py-2 px-4 text-left border">Actions</th>
                </tr>
              </thead>

                <tbody>
                <?php foreach ($viewings as $v): ?>
                  <tr class="border-t">
                    <td class="py-2 px-4 border"><?php echo h($v['client_first_name'].' '.$v['client_last_name']); ?></td>
                    <td class="py-2 px-4 border"><?php echo h($v['lot_no']); ?></td>
                    <td class="py-2 px-4 border"><?php echo date('M d, Y · h:i A', strtotime($v['preferred_at'])); ?></td>
                    <td class="py-2 px-4 border">
                      <?php
                        $st = $v['status'];
                        $badge = 'bg-gray-100 text-gray-700 border-gray-300';
                        if ($st==='scheduled')   $badge = 'bg-green-50 text-green-800 border-green-300';
                        if ($st==='rescheduled') $badge = 'bg-amber-50 text-amber-800 border-amber-300';
                        if ($st==='completed')   $badge = 'bg-blue-50 text-blue-800 border-blue-300';
                        if ($st==='cancelled' || $st==='no_show_agent' || $st==='no_show_client')
                          $badge = 'bg-rose-50 text-rose-800 border-rose-300';
                      ?>
                      <span class="text-xs px-2 py-1 border rounded-full <?php echo $badge; ?>">
                        <?php echo h(ucwords(str_replace('_',' ',$st))); ?>
                      </span>
                    </td>
                    <td class="py-2 px-4 border">
                      <div class="text-sm">
                        <a href="mailto:<?php echo h($v['client_email']); ?>" class="text-green-700 hover:underline block">
                          <?php echo h($v['client_email']); ?>
                        </a>
                        <a href="tel:<?php echo h($v['client_phone']); ?>" class="text-green-700 hover:underline">
                          <?php echo h($v['client_phone']); ?>
                        </a>
                      </div>
                    </td>
                  
<td class="py-2 px-4 border">
  <div class="grid grid-cols-2 gap-2">
    <div>
      <a class="px-2 py-1 text-xs border rounded-lg hover:shadow w-full block text-center"
         href="resched_viewing.php?id=<?php echo (int)$v['id']; ?>">⏱ Resched</a>
    </div>
    <div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
        <button class="px-2 py-1 text-xs border rounded-lg hover:shadow w-full block text-center"
                name="viewing_action" value="completed">✅ Completed</button>
      </form>
    </div>
    <div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
        <button class="px-2 py-1 text-xs border rounded-lg hover:shadow w-full block text-center"
                name="viewing_action" value="no_show_client">🚫 Client No-show</button>
      </form>
    </div>
    <div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
        <button class="px-2 py-1 text-xs border rounded-lg hover:shadow w-full block text-center"
                name="viewing_action" value="cancelled">✖ Cancelled</button>
      </form>
    </div>
  </div>
</td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </section>

    
      

  <!-- PROFILE -->
<section id="section-profile" class="hidden">
  <h2 class="text-3xl font-bold text-green-900 mb-2">Profile</h2>
  <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-bottom: 1.5em;">View and update your personal and contact information.</p>
  <div class="bg-white rounded-2xl border shadow p-6 max-w-4xl">

    <!-- Tabs -->
    <div class="flex border-b mb-6">
      <button id="tab-profile-info" type="button" class="px-6 py-2 font-semibold text-green-900 border-b-2 border-green-900 focus:outline-none">Profile Info</button>
      <button id="tab-change-password" type="button" class="px-6 py-2 font-semibold text-gray-600 border-b-2 border-transparent focus:outline-none">Change Password</button>
    </div>

    <!-- Profile Info Form -->
    <div id="profile-info-pane">
      <form id="edit-profile-form" enctype="multipart/form-data" method="post" action="agentprofile_update.php">
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold mb-1">First Name</label>
            <input type="text" name="first_name" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($agent['first_name'] ?? ''); ?>" required>
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Last Name</label>
            <input type="text" name="last_name" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($agent['last_name'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Email</label>
            <input type="email" name="email" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($agent['email'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Mobile</label>
            <input type="text" name="mobile" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($agent['mobile'] ?? ''); ?>">
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-semibold mb-1">Address</label>
            <input type="text" name="address" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($agent['address'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Experience (Years)</label>
            <input type="number" name="experience" class="w-full border rounded px-3 py-2"
                   value="<?php echo h((string)($agent['experience'] ?? 0)); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Total Sales</label>
            <input type="number" name="total_sales" class="w-full border rounded px-3 py-2"
                   value="<?php echo h((string)($agent['total_sales'] ?? 0)); ?>">
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-semibold mb-1">Description</label>
            <textarea name="description" rows="3" class="w-full border rounded px-3 py-2"><?php
              echo h($agent['description'] ?? '');
            ?></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-semibold mb-1">Profile Picture</label>
            <?php if (!empty($agent['profile_picture'])): ?>
              <img src="<?php echo h($agent['profile_picture']); ?>" class="w-16 h-16 rounded-full object-cover mb-2"
                   onerror="this.style.display='none'">
            <?php endif; ?>
            <input type="file" name="profile_picture" class="w-full border rounded px-3 py-2">
          </div>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <div class="flex justify-end mt-4">
          <button class="bg-green-900 text-white px-6 py-2 rounded hover:bg-green-800">Save</button>
        </div>
      </form>
    </div>

    <!-- Change Password Form -->
    <div id="change-password-pane" style="display:none;">
      <form id="change-password-form" method="post" action="agentprofile_update.php">
        <div class="mb-3">
          <label class="block text-sm font-semibold mb-1">New Password</label>
          <input type="password" name="new_password" class="w-full border rounded px-3 py-2" placeholder="New Password">
        </div>
        <div class="mb-3">
          <label class="block text-sm font-semibold mb-1">Confirm New Password</label>
          <input type="password" name="confirm_password" class="w-full border rounded px-3 py-2" placeholder="Confirm Password">
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <button class="bg-green-900 text-white px-6 py-2 rounded hover:bg-green-800">Change Password</button>
      </form>
    </div>
  </div>
</section>


    

    <!-- SALES (no sales on dashboard; shown here) -->
    <section id="section-sales" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Manage Sales</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review your property sales and transaction history</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div class="mb-4 flex items-center gap-3">
          <label class="text-gray-700">Filter by:</label>
          <select id="filter-select" class="border rounded px-2 py-1">
            <option value="all">All</option>
            <option value="sale_id">Sale ID</option>
            <option value="buyer_name">Buyer Name</option>
            <option value="property_name">Property Name</option>
            <option value="sale_date">Sale Date</option>
          </select>
          <input id="filter-input" type="text" class="border rounded px-2 py-1 ml-2" placeholder="Type to filter..." style="display:none;">
        </div>

        <div class="overflow-x-auto">
          <div id="sale-message" style="display:none;" class="mb-4 p-3 rounded bg-red-100 text-red-800 font-semibold"></div>
          <div id="sale-success-message" style="display:none;" class="bg-green-100 text-green-900 px-4 py-2 rounded mb-4"></div>
          <table class="min-w-full border rounded text-sm">
            <thead>
              <tr class="bg-gray-50 text-gray-700">
                <th class="py-2 px-4 text-left">Property</th>
                <th class="py-2 px-4 text-left">Buyer</th>
                <th class="py-2 px-4 text-left">Sale Price</th>
                <th class="py-2 px-4 text-left">Sale Date</th>
                <th class="py-2 px-4 text-left">Actions</th>
              </tr>
            </thead>
            <tbody id="sales-table-body"></tbody>
          </table>
        </div>

        <div class="mt-6 flex gap-4">
          <button id="add-sale-btn" class="bg-green-800 text-white px-5 py-2 rounded hover:bg-green-900">Add New Sale</button>
          <button id="save-all-sales-btn" class="bg-green-800 text-white px-5 py-2 rounded hover:bg-green-900">Save All Changes</button>
        </div>
      </div>
    </section>

    <!-- NOTIFICATIONS -->
    <section id="section-notifications" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Notifications</h2>
       <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Stay updated with important alerts and system messages</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <p class="text-gray-600">No messages yet.</p>
      </div>
    </section>

    <!-- MESSAGES -->
    <section id="section-messages" class="hidden">
  <h2 class="text-3xl font-bold text-green-900 mb-4">Messages</h2>
 <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">View and respond to your messages</p>
  <div class="bg-white rounded-2xl border shadow p-6">
    <div id="messages-container">
      <p class="text-gray-600">Loading messages...</p>
    </div>
  </div>
</section>

<section id="section-audit-logs" class="hidden">
  <h2 class="text-3xl font-bold text-green-900 mb-4">Audit Logs</h2>
  <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review system audit logs for security and compliance</p>
  <div class="bg-white rounded-2xl border shadow p-6">
    <div id="audit-logs-container">
      <p class="text-gray-600">Loading audit logs...</p>
    </div>
  </div>
</section>


<section id="section-documents" class="hidden">
  <h2 class="text-3xl font-bold text-green-900 mb-4">Document Review</h2>
  <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review and manage your documents</p>
  <div class="bg-white rounded-2xl border shadow p-6">
    <div id="documents-container">
      <p class="text-gray-600">Loading documents...</p>
    </div>
  </div>
</section>

    <!-- MY VIEWINGS -->
    <section id="section-viewings" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Viewing Requests</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Manage and track your assigned viewing requests</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        
        <?php if (empty($all_viewings)): ?>
          <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm">
            No viewing requests assigned to you yet.
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full border rounded text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="py-3 px-4 text-left border">Client</th>
                  <th class="py-3 px-4 text-left border">Contact</th>
                  <th class="py-3 px-4 text-left border">Location & Lot</th>
                  <th class="py-3 px-4 text-left border">Preferred Date</th>
                  <th class="py-3 px-4 text-left border">Status</th>
                  <th class="py-3 px-4 text-left border">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($all_viewings as $v): ?>
                <tr class="border-t hover:bg-gray-50">
                  <td class="py-3 px-4 border">
                    <strong><?php echo h($v['client_first_name'] . ' ' . $v['client_last_name']); ?></strong>
                    <?php if (!empty($v['note'])): ?>
                      <br><small class="text-gray-600">Note: <?php echo h($v['note']); ?></small>
                    <?php endif; ?>
                  </td>
                <td class="py-3 px-4 border">
                  <div class="text-sm">
                    <a href="mailto:<?php echo h($v['client_email']); ?>" class="text-green-700 hover:underline block">
                      <?php echo h($v['client_email']); ?>
                    </a>
                    <a href="tel:<?php echo h($v['client_phone']); ?>" class="text-green-700 hover:underline">
                      <?php echo h($v['client_phone']); ?>
                    </a>
                  </div>
                </td>
               <td class="py-3 px-4 border">
  <div class="text-sm">
    <?php if (!empty($v['location_name']) || (!empty($v['block_number']) && !empty($v['lot_number']))): ?>
      <strong><?php echo h($v['location_name'] ?? ''); ?></strong>
      <?php if (!empty($v['block_number']) && !empty($v['lot_number'])): ?>
        <br><small class="text-gray-600">
          Block <?php echo h($v['block_number']); ?>, Lot <?php echo h($v['lot_number']); ?>
          <?php if (!empty($v['lot_size'])): ?>(<?php echo h($v['lot_size']); ?> sqm)<?php endif; ?>
        </small>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-gray-500">Lot <?php echo h($v['lot_no'] ?: 'N/A'); ?></span>
    <?php endif; ?>
  </div>
</td>

                  <td class="py-3 px-4 border">
                    <?php if ($v['preferred_at']): ?>
                      <div class="text-sm">
                        <?php echo date('M d, Y', strtotime($v['preferred_at'])); ?><br>
                        <small class="text-gray-600"><?php echo date('h:i A', strtotime($v['preferred_at'])); ?></small>
                      </div>
                    <?php else: ?>
                      <span class="text-gray-500 text-sm">Not specified</span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 px-4 border">
                    <?php
                      $st = $v['status'];
                      $badge = 'bg-gray-100 text-gray-700';
                      if ($st === 'requested') $badge = 'bg-yellow-100 text-yellow-800';
                      if ($st === 'scheduled') $badge = 'bg-blue-100 text-blue-800';
                      if ($st === 'completed') $badge = 'bg-green-100 text-green-800';
                      if ($st === 'cancelled') $badge = 'bg-red-100 text-red-800';
                    ?>
                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $badge; ?>">
                      <?php echo h(ucfirst($st)); ?>
                    </span>
                  </td>
                  <td class="py-3 px-4 border">
                    <?php if (in_array($v['status'], ['requested', 'scheduled'])): ?>
                      <div class="flex flex-wrap gap-1">
                        <form method="post" style="display: inline;">
                          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                          <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
                          <input type="hidden" name="redirect_to" value="viewings">
                          <button class="px-2 py-1 text-xs bg-green-600 text-white rounded hover:bg-green-700"
                                  name="viewing_action" value="completed">✓ Complete</button>
                        </form>
                        
                        <form method="post" style="display: inline;">
                          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                          <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
                          <input type="hidden" name="redirect_to" value="viewings">
                          <button class="px-2 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700"
                                  name="viewing_action" value="cancelled">✗ Cancel</button>
                        </form>
                      </div>
                    <?php else: ?>
                      <span class="text-gray-500 text-xs">No actions available</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        
      </div>
    </section>

    <!-- MY LEADS -->
    <section id="section-leads" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">My Assigned Leads</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Manage and track your assigned leads</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <?php if (empty($leads)): ?>
          <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm">
            No leads assigned to you yet.
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full border rounded text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="py-2 px-4 text-left border">Name</th>
                  <th class="py-2 px-4 text-left border">Email</th>
                  <th class="py-2 px-4 text-left border">Phone</th>
                  <th class="py-2 px-4 text-left border">Status</th>
                  <th class="py-2 px-4 text-left border">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($leads as $lead): ?>
                <tr class="border-t">
                  <td class="py-2 px-4 border"><?php echo h($lead['first_name'] . ' ' . $lead['last_name']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['email']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['phone']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['status']); ?></td>
                  <td class="py-2 px-4 border">
                    <a href="lead_timeline.php?id=<?php echo (int)$lead['id']; ?>" class="text-blue-600 hover:underline">
                      Timeline
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>

<!-- SPA NAV / SECTION SWITCHER -->
<script>
 const links = document.querySelectorAll('#spa-nav a[data-target]');
const sections = [
  'section-dashboard',
  'section-profile',
  'section-viewings',
  'section-sales',
  'section-notifications',
  'section-messages',
  'section-leads',
  'section-audit-logs', // <-- comma added
  'section-documents'
].map(id => document.getElementById(id));

function showSection(id) {
  sections.forEach(s => s.classList.toggle('hidden', s.id !== id));
  links.forEach(a => a.classList.toggle('nav-active', a.dataset.target === id));

  if (id === 'section-notifications') {
    loadAgentNotifications();
  }
  if (id === 'section-messages') {
    loadAgentMessages();
  }
  if (id === 'section-audit-logs') {
    loadAgentAuditLogs();
  }
  if (id === 'section-documents') {
    loadAgentDocuments();
  }
}

// handle clicks
links.forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const id = a.dataset.target;
    history.replaceState(null, '', a.getAttribute('href')); // keep hash updated
    showSection(id);
  });
});

// initial route based on hash
const hash = location.hash.replace('#','');
const map = {
  'dashboard': 'section-dashboard',
  'profile': 'section-profile',
  'viewings': 'section-viewings',
  'sales': 'section-sales',
  'notifications': 'section-notifications',
  'messages': 'section-messages',
  'leads': 'section-leads',
  'audit-logs': 'section-audit-logs',
  'documents': 'section-documents'
};
showSection(map[hash] || 'section-dashboard');
</script>

<!-- If you have your sales JS already, include it here -->
<script src="agentdb/js/main.js"></script>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg p-6 max-w-sm mx-4">
    <div class="flex items-center mb-4">
      <svg class="w-6 h-6 text-red-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
      </svg>
      <h3 class="text-lg font-semibold text-gray-900">Confirm Logout</h3>
    </div>
    <p class="text-gray-600 mb-6">Are you sure you want to logout? You will need to login again to access your dashboard.</p>
    <div class="flex gap-3 justify-end">
      <button onclick="closeLogoutModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
        Cancel
      </button>
      <button onclick="proceedLogout()" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
        Logout
      </button>
    </div>
  </div>
</div>

<script>
  // Logout modal functions
  function confirmLogout() {
    document.getElementById('logoutModal').classList.remove('hidden');
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }

  function proceedLogout() {
    window.location.href = 'logout.php';
  }

  // Close modal when clicking outside
  document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) {
      closeLogoutModal();
    }
  });

  function loadAgentNotifications() {
  const container = document.getElementById('notifications-container');
  container.innerHTML = '<p class="text-gray-600">Loading notifications...</p>';
  fetch(window.location.pathname + '?fetch=notifications')
    .then(response => response.json())
    .then(notifications => {
      if (!notifications.length) {
        container.innerHTML = '<p class="text-gray-600">No notifications yet.</p>';
        return;
      }
      container.innerHTML = notifications.map(n => `
        <div class="mb-4 p-4 rounded border ${n.is_read ? 'bg-gray-50' : 'bg-green-50'}">
          <div class="font-semibold text-green-900">${n.title}</div>
          <div class="text-gray-700 mb-2">${n.message}</div>
          <div class="text-xs text-gray-500">${new Date(n.created_at).toLocaleString()}</div>
          <div class="mt-2 flex gap-2">
            ${!n.is_read ? `<button class="px-3 py-1 text-xs bg-green-700 text-white rounded" onclick="markNotificationRead(${n.id})">Mark as Read</button>` : ''}
            <button class="px-3 py-1 text-xs bg-red-600 text-white rounded" onclick="deleteNotification(${n.id})">Delete</button>
          </div>
        </div>
      `).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load notifications.</p>';
    });
}

function markNotificationRead(id) {
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `mark_read=${id}`
  })
  .then(res => res.json())
  .then(() => loadAgentNotifications());
}

function deleteNotification(id) {
  if (!confirm('Delete this notification?')) return;
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `delete_notif=${id}`
  })
  .then(res => res.json())
  .then(() => loadAgentNotifications());
}

// Load notifications when the section is shown
const notificationsLink = document.querySelector('a[data-target="section-notifications"]');
if (notificationsLink) {
  notificationsLink.addEventListener('click', () => {
    loadAgentNotifications();
  });
}

function loadAgentMessages() {
  const container = document.getElementById('messages-container');
  container.innerHTML = '<p class="text-gray-600">Loading messages...</p>';
  fetch(window.location.pathname + '?fetch=messages')
    .then(response => response.json())
    .then(messages => {
      if (!messages.length) {
        container.innerHTML = '<p class="text-gray-600">No messages yet.</p>';
        return;
      }
      container.innerHTML = messages.map(m => `
        <div class="mb-4 p-4 rounded border ${m.is_read ? 'bg-gray-50' : 'bg-blue-50'}">
          <div class="font-semibold text-green-900">${m.name} (${m.email})</div>
          <div class="text-gray-700 mb-2">${m.message}</div>
          <div class="text-xs text-gray-500">${new Date(m.created_at).toLocaleString()}</div>
          <div class="mt-2 flex gap-2">
            ${!m.is_read ? `<button class="px-3 py-1 text-xs bg-blue-700 text-white rounded" onclick="markMessageRead(${m.id})">Mark as Read</button>` : ''}
            <button class="px-3 py-1 text-xs bg-red-600 text-white rounded" onclick="deleteMessage(${m.id})">Delete</button>
          </div>
        </div>
      `).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load messages.</p>';
    });
}

function markMessageRead(id) {
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `mark_message_read=${id}`
  })
  .then(res => res.json())
  .then(() => loadAgentMessages());
}

function deleteMessage(id) {
  if (!confirm('Delete this message?')) return;
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `delete_message=${id}`
  })
  .then(res => res.json())
  .then(() => loadAgentMessages());
}

// Load messages when the section is shown
const messagesLink = document.querySelector('a[data-target="section-messages"]');
if (messagesLink) {
  messagesLink.addEventListener('click', () => {
    loadAgentMessages();
  });
}

function loadAgentAuditLogs() {
  const container = document.getElementById('audit-logs-container');
  container.innerHTML = '<p class="text-gray-600">Loading audit logs...</p>';
  fetch(window.location.pathname + '?fetch=audit_logs')
    .then(response => response.json())
    .then(logs => {
      if (!logs.length) {
        container.innerHTML = '<p class="text-gray-600">No audit logs found.</p>';
        return;
      }
      container.innerHTML = logs.map(log => `
        <div class="mb-4 p-4 rounded border bg-gray-50">
          <div class="font-semibold text-green-900">${log.action}</div>
          <div class="text-gray-700 mb-2">${log.details}</div>
          <div class="text-xs text-gray-500">${new Date(log.created_at).toLocaleString()}</div>
        </div>
      `).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load audit logs.</p>';
    });
}


function loadAgentDocuments() {
  const container = document.getElementById('documents-container');
  container.innerHTML = '<p class="text-gray-600">Loading documents...</p>';
  fetch(window.location.pathname + '?fetch=user_documents')
    .then(response => response.json())
    .then(docs => {
      if (!docs.length) {
        container.innerHTML = '<p class="text-gray-600">No user documents found.</p>';
        return;
      }
      container.innerHTML = `
        <table class="min-w-full border rounded text-sm">
          <thead>
            <tr class="bg-gray-50 text-gray-700">
              <th class="py-2 px-4 text-left">User</th>
              <th class="py-2 px-4 text-left">Type</th>
              <th class="py-2 px-4 text-left">File</th>
              <th class="py-2 px-4 text-left">Date</th>
              <th class="py-2 px-4 text-left">Status</th>
              <th class="py-2 px-4 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            ${docs.map(doc => `
              <tr>
                <td class="py-2 px-4 border">${(doc.first_name || '') + ' ' + (doc.last_name || '')}</td>
                <td class="py-2 px-4 border">${doc.doc_type}</td>
                <td class="py-2 px-4 border"><a href="${doc.file_path}" target="_blank">${doc.file_name}</a></td>
                <td class="py-2 px-4 border">${doc.uploaded_at}</td>
                <td class="py-2 px-4 border">${doc.status}</td>
                <td class="py-2 px-4 border">
                  <a href="${doc.file_path}" target="_blank" class="px-2 py-1 text-xs bg-blue-700 text-white rounded">View</a>
                  <!-- Optionally add Approve/Reject buttons here -->
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load user documents.</p>';
    });
}



document.getElementById('tab-profile-info').onclick = function() {
  this.classList.add('text-green-900', 'border-green-900');
  this.classList.remove('text-gray-600', 'border-transparent');
  document.getElementById('tab-change-password').classList.remove('text-green-900', 'border-green-900');
  document.getElementById('tab-change-password').classList.add('text-gray-600', 'border-transparent');
  document.getElementById('profile-info-pane').style.display = '';
  document.getElementById('change-password-pane').style.display = 'none';
};
document.getElementById('tab-change-password').onclick = function() {
  this.classList.add('text-green-900', 'border-green-900');
  this.classList.remove('text-gray-600', 'border-transparent');
  document.getElementById('tab-profile-info').classList.remove('text-green-900', 'border-green-900');
  document.getElementById('tab-profile-info').classList.add('text-gray-600', 'border-transparent');
  document.getElementById('profile-info-pane').style.display = 'none';
  document.getElementById('change-password-pane').style.display = '';
};

</script>
</body>
</html>
