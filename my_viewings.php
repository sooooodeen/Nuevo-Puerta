<?php
require_once 'dbconn.php';
session_start();

/* -------------------------------------------------
   CONFIG / BASICS
------------------------------------------------- */
date_default_timezone_set('Asia/Manila');
if (function_exists('mysqli_set_charset')) { @mysqli_set_charset($conn, 'utf8mb4'); }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* -------------------------------------------------
   AUTH (CLIENT)
   Expecting user to be logged in as a client.
   We’ll accept any of these session keys:
   - role in ['client','user','customer']
   - AND either: client_id OR user_id OR email
------------------------------------------------- */
$role = $_SESSION['role'] ?? '';
$clientId  = $_SESSION['client_id'] ?? $_SESSION['user_id'] ?? null;
$clientEmail = $_SESSION['email'] ?? null;

$isClient = in_array($role, ['client','user','customer'], true) && ($clientId || $clientEmail);
if (!$isClient) {
  // Fall back: if your app uses a different session shape, adjust here.
  header('Location: Login/login.php');
  exit;
}

/* -------------------------------------------------
   SAFETY: Ensure tables exist (no-op if they do)
------------------------------------------------- */
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
    notes TEXT,
    client_lat DECIMAL(10,8),
    client_lng DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vw_client (client_email, preferred_at, status),
    INDEX idx_vw_agent (agent_id, preferred_at, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* -------------------------------------------------
   CSRF
------------------------------------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function require_csrf(){
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
}

/* -------------------------------------------------
   CLIENT PROFILE FALLBACKS
   If you maintain a "clients" table, you can populate
   from there. For now we rely on session + form inputs.
------------------------------------------------- */
$clientFirst = $_SESSION['first_name'] ?? '';
$clientLast  = $_SESSION['last_name'] ?? '';
$clientPhone = $_SESSION['phone'] ?? '';

/* -------------------------------------------------
   UTIL: Suggest available agent
   - Choose agent marked is_available=1
   - Highest total_sales first (fallback to any)
------------------------------------------------- */
function suggest_agent_id(mysqli $conn): ?int {
  $sql = "
    SELECT id
    FROM agent_accounts
    WHERE COALESCE(is_available,1) = 1
    ORDER BY COALESCE(total_sales,0) DESC, id ASC
    LIMIT 1
  ";
  $res = $conn->query($sql);
  if ($res && $row = $res->fetch_assoc()) {
    return (int)$row['id'];
  }
  // fallback: any agent
  $res = $conn->query("SELECT id FROM agent_accounts ORDER BY id ASC LIMIT 1");
  if ($res && $row = $res->fetch_assoc()) {
    return (int)$row['id'];
  }
  return null;
}

/* -------------------------------------------------
   FLASH
------------------------------------------------- */
$flash_ok = '';
$flash_err = '';

/* -------------------------------------------------
   ACTIONS (Create, Cancel, Quick Resched)
------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Create a new viewing request
  if (isset($_POST['action']) && $_POST['action'] === 'create_viewing') {
    $first = trim($_POST['client_first_name'] ?? $clientFirst);
    $last  = trim($_POST['client_last_name'] ?? $clientLast);
    $email = trim($_POST['client_email'] ?? $clientEmail);
    $phone = trim($_POST['client_phone'] ?? $clientPhone);
    $lotNo = trim($_POST['lot_no'] ?? '');
    $dt    = trim($_POST['preferred_at'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $lat   = $_POST['client_lat'] !== '' ? (float)$_POST['client_lat'] : null;
    $lng   = $_POST['client_lng'] !== '' ? (float)$_POST['client_lng'] : null;

    if ($first === '' || $last === '' || $email === '' || $lotNo === '' || $dt === '') {
      $flash_err = 'Please fill in First/Last name, Email, Lot No, and Date/Time.';
    } else {
      $agentId = suggest_agent_id($conn);
      $stmt = $conn->prepare("
        INSERT INTO viewings (agent_id, client_first_name, client_last_name, client_email, client_phone, lot_no, preferred_at, status, notes, client_lat, client_lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)
      ");
      if ($stmt) {
        // preferred_at: accept "YYYY-MM-DDTHH:MM" from <input type="datetime-local">
        $when = str_replace('T', ' ', $dt);
        $stmt->bind_param(
          'isssssssd' . 'd',
          $agentId,
          $first, $last, $email, $phone, $lotNo, $when, $notes,
          $lat, $lng
        );
        // PHP quirk: for NULL floats, bind as double but pass NULL with null coalesce:
        $lat = $lat ?? null; $lng = $lng ?? null;
        if ($stmt->execute()) {
          $flash_ok = 'Viewing request submitted. We assigned an available agent.';
        } else {
          $flash_err = 'Error creating viewing: '.$stmt->error;
        }
        $stmt->close();
      } else {
        $flash_err = 'Prepare failed: '.$conn->error;
      }
    }
  }

  // Cancel viewing (client-side)
  if (isset($_POST['action']) && $_POST['action'] === 'cancel_viewing') {
    $vid = (int)($_POST['viewing_id'] ?? 0);
    // Only allow cancel if belongs to this client (same email)
    $stmt = $conn->prepare("UPDATE viewings SET status='cancelled' WHERE id=? AND client_email=? AND status IN ('scheduled','rescheduled')");
    if ($stmt) {
      $stmt->bind_param('is', $vid, $clientEmail);
      $stmt->execute();
      if ($stmt->affected_rows > 0) {
        $flash_ok = 'Viewing cancelled.';
      } else {
        $flash_err = 'Unable to cancel (maybe already processed).';
      }
      $stmt->close();
    } else {
      $flash_err = 'Prepare failed: '.$conn->error;
    }
  }

  // Quick reschedule (client picks a new time)
  if (isset($_POST['action']) && $_POST['action'] === 'resched_viewing') {
    $vid = (int)($_POST['viewing_id'] ?? 0);
    $dt  = trim($_POST['new_preferred_at'] ?? '');
    if ($dt === '') {
      $flash_err = 'Please provide a new date/time.';
    } else {
      $when = str_replace('T', ' ', $dt);
      $stmt = $conn->prepare("
        UPDATE viewings
        SET preferred_at=?, status='rescheduled'
        WHERE id=? AND client_email=? AND status IN ('scheduled','rescheduled')
      ");
      if ($stmt) {
        $stmt->bind_param('sis', $when, $vid, $clientEmail);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
          $flash_ok = 'Viewing rescheduled.';
        } else {
          $flash_err = 'Unable to reschedule (maybe already processed).';
        }
        $stmt->close();
      } else {
        $flash_err = 'Prepare failed: '.$conn->error;
      }
    }
  }
}

/* -------------------------------------------------
   FETCH DATA (My upcoming + history)
------------------------------------------------- */
$upcoming = [];
$stmt = $conn->prepare("
  SELECT v.id, v.lot_no, v.preferred_at, v.status, v.notes,
         a.first_name AS agent_first, a.last_name AS agent_last, a.mobile AS agent_mobile, a.email AS agent_email
  FROM viewings v
  LEFT JOIN agent_accounts a ON a.id = v.agent_id
  WHERE v.client_email = ?
    AND v.status IN ('scheduled','rescheduled')
  ORDER BY v.preferred_at ASC
");
if ($stmt) {
  $stmt->bind_param('s', $clientEmail);
  $stmt->execute();
  $upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$history = [];
$stmt = $conn->prepare("
  SELECT v.id, v.lot_no, v.preferred_at, v.status, v.notes,
         a.first_name AS agent_first, a.last_name AS agent_last
  FROM viewings v
  LEFT JOIN agent_accounts a ON a.id = v.agent_id
  WHERE v.client_email = ?
    AND v.status IN ('completed','cancelled','no_show_agent','no_show_client')
  ORDER BY v.preferred_at DESC
  LIMIT 50
");
if ($stmt) {
  $stmt->bind_param('s', $clientEmail);
  $stmt->execute();
  $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Viewings</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --green:#2d4e1e; --bg:#f8f8f8; --ink:#0f172a; --muted:#64748b;
  --card:#fff; --stroke:#e5e7eb; --shadow:0 10px 28px rgba(2,6,23,.08);
}
*{box-sizing:border-box}
body{font-family:'Inter',sans-serif;margin:0;background:var(--bg);color:var(--ink)}
nav{background:var(--green);height:70px;display:flex;align-items:center;justify-content:space-between;padding:0 20px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.12)}
nav a{color:#fff;text-decoration:none;font-weight:600;margin:0 10px}
.wrapper{max-width:1100px;margin:28px auto;padding:0 18px}
h1{margin:8px 0 14px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media (max-width:950px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{border:1px solid var(--stroke);padding:10px 12px;text-align:left;font-size:14px}
.table th{background:#f3f4f6}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--stroke);font-size:12px}
.badge.scheduled{background:#ecfdf5;border-color:#86efac;color:#166534}
.badge.rescheduled{background:#fff7ed;border-color:#fed7aa;color:#92400e}
.badge.completed{background:#e0f2fe;border-color:#93c5fd;color:#1e3a8a}
.badge.cancelled,.badge.no_show_agent,.badge.no_show_client{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.input, .textarea, .select, .datetime{
  width:100%;padding:10px;border:1px solid var(--stroke);border-radius:10px;
}
.btn{all:unset;background:var(--green);color:#fff;padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer}
.btn.secondary{background:#475569}
.flash-ok{margin:8px 0 14px;padding:10px 12px;border-radius:10px;background:#ecfdf5;color:#166534;border:1px solid #86efac}
.flash-err{margin:8px 0 14px;padding:10px 12px;border-radius:10px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.muted{color:var(--muted)}
</style>
</head>
<body>
  <nav>
    <div><strong>El Nuevo Puerta</strong></div>
    <div>
      <a href="index.html">Home</a>
      <a href="userlot.php">Buy</a>
      <a href="findagent.php">Find Agent</a>
      <a href="logout.php">Logout</a>
    </div>
  </nav>

  <div class="wrapper">
    <h1>My Viewings</h1>

    <?php if($flash_ok): ?><div class="flash-ok"><?php echo h($flash_ok); ?></div><?php endif; ?>
    <?php if($flash_err): ?><div class="flash-err"><?php echo h($flash_err); ?></div><?php endif; ?>

    <!-- Create new viewing -->
    <div class="card" style="margin-bottom:18px">
      <h3 style="margin-top:0">Book a Viewing</h3>
      <form method="post" class="row">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_viewing">
        <div style="flex:1 1 200px">
          <label>First Name</label>
          <input class="input" name="client_first_name" value="<?php echo h($clientFirst); ?>" required>
        </div>
        <div style="flex:1 1 200px">
          <label>Last Name</label>
          <input class="input" name="client_last_name" value="<?php echo h($clientLast); ?>" required>
        </div>
        <div style="flex:1 1 240px">
          <label>Email</label>
          <input class="input" type="email" name="client_email" value="<?php echo h($clientEmail); ?>" required>
        </div>
        <div style="flex:1 1 200px">
          <label>Phone</label>
          <input class="input" name="client_phone" value="<?php echo h($clientPhone); ?>">
        </div>
        <div style="flex:1 1 180px">
          <label>Lot No</label>
          <input class="input" name="lot_no" placeholder="e.g., BLK 12 LT 3" required>
        </div>
        <div style="flex:1 1 240px">
          <label>Preferred Date & Time</label>
          <input class="datetime" type="datetime-local" name="preferred_at" required>
        </div>
        <div style="flex:1 1 120px">
          <label>Latitude (optional)</label>
          <input class="input" name="client_lat" type="number" step="0.00000001" placeholder="e.g., 6.9211">
        </div>
        <div style="flex:1 1 120px">
          <label>Longitude (optional)</label>
          <input class="input" name="client_lng" type="number" step="0.00000001" placeholder="e.g., 122.0790">
        </div>
        <div style="flex:1 1 100%">
          <label>Notes (optional)</label>
          <textarea class="textarea" name="notes" rows="3" placeholder="Describe any preference or questions..."></textarea>
        </div>
        <div style="flex:1 1 120px">
          <button class="btn">Submit</button>
        </div>
      </form>
      <div class="muted" style="margin-top:6px">
        Tip: The system will auto-assign the nearest available/active agent (by availability & performance).
      </div>
    </div>

    <div class="grid">
      <!-- Upcoming -->
      <div class="card">
        <h3 style="margin-top:0">Upcoming</h3>
        <?php if(empty($upcoming)): ?>
          <p class="muted">No upcoming viewings.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Lot</th>
                <th>Date & Time</th>
                <th>Status</th>
                <th>Agent</th>
                <th>Contact</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($upcoming as $v): ?>
              <tr>
                <td><?php echo h($v['lot_no']); ?></td>
                <td><?php echo date('M d, Y · h:i A', strtotime($v['preferred_at'])); ?></td>
                <td><span class="badge <?php echo h($v['status']); ?>"><?php echo ucwords(str_replace('_',' ',$v['status'])); ?></span></td>
                <td><?php echo h(trim(($v['agent_first'] ?? '').' '.($v['agent_last'] ?? '')) ?: 'TBD'); ?></td>
                <td>
                  <?php echo h($v['agent_mobile'] ?? ''); ?>
                  <?php if(!empty($v['agent_email'])): ?>
                    <br><a href="mailto:<?php echo h($v['agent_email']); ?>">Email</a>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" class="row" style="gap:6px;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
                    <input type="hidden" name="action" value="resched_viewing">
                    <input class="datetime" type="datetime-local" name="new_preferred_at" style="flex:1 1 180px">
                    <button class="btn secondary" style="flex:0 0 auto">Resched</button>
                  </form>

                  <form method="post" style="margin-top:6px">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
                    <input type="hidden" name="action" value="cancel_viewing">
                    <button class="btn" onclick="return confirm('Cancel this viewing?')">Cancel</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- History -->
      <div class="card">
        <h3 style="margin-top:0">History</h3>
        <?php if(empty($history)): ?>
          <p class="muted">No history yet.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Lot</th>
                <th>Date</th>
                <th>Status</th>
                <th>Agent</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($history as $v): ?>
              <tr>
                <td><?php echo h($v['lot_no']); ?></td>
                <td><?php echo date('M d, Y', strtotime($v['preferred_at'])); ?></td>
                <td><span class="badge <?php echo h($v['status']); ?>"><?php echo ucwords(str_replace('_',' ',$v['status'])); ?></span></td>
                <td><?php echo h(trim(($v['agent_first'] ?? '').' '.($v['agent_last'] ?? '')) ?: ''); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
