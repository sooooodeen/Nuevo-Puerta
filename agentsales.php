<?php
require_once 'dbconn.php';
session_start();

if (!isset($_SESSION['agent_id'])) {
  header('Location: Login/login.php');
  exit;
}

$agentId = (int)$_SESSION['agent_id'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------
   Ensure sales table exists (no error if already there)
------------------------------------------------------------------ */
$conn->query("
  CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    lot_no VARCHAR(60) NULL,
    amount DECIMAL(12,2) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_agent (agent_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ------------------------------------------------------------------
   Handle new sale submit
------------------------------------------------------------------ */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
  $lotNo  = trim($_POST['lot_no'] ?? '');
  $amount = trim($_POST['amount'] ?? '');
  $notes  = trim($_POST['notes'] ?? '');

  if ($lotNo === '' || $amount === '') {
    $flash = 'Lot No and Amount are required.';
  } elseif (!is_numeric($amount)) {
    $flash = 'Amount must be a number.';
  } else {
    $amt = (float)$amount;
    $stmt = $conn->prepare("
      INSERT INTO sales (agent_id, lot_no, amount, notes)
      VALUES (?, ?, ?, ?)
    ");
    if ($stmt) {
      $stmt->bind_param('isds', $agentId, $lotNo, $amt, $notes);
      if ($stmt->execute()) {
        $flash = 'Sale recorded successfully.';
      } else {
        $flash = 'Database error: '.$stmt->error;
      }
      $stmt->close();
    } else {
      $flash = 'Prepare failed: '.$conn->error;
    }
  }
}

/* ------------------------------------------------------------------
   Load recent sales for this agent
------------------------------------------------------------------ */
$sales = [];
if ($stmt = $conn->prepare("
  SELECT id, lot_no, amount, notes, created_at
  FROM sales
  WHERE agent_id = ?
  ORDER BY created_at DESC
  LIMIT 50
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ------------------------------------------------------------------
   (Optional) Fetch agent name for header
------------------------------------------------------------------ */
$fullName = 'Agent';
if ($stmt = $conn->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS n FROM agent_accounts WHERE id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $fullName = $row && $row['n'] ? $row['n'] : $fullName;
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Sales</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --green:#2d4e1e; --bg:#f6f7f8; --ink:#0f172a; --muted:#64748b;
  --card:#fff; --stroke:#e5e7eb; --shadow:0 10px 28px rgba(2,6,23,.08);
}
*{box-sizing:border-box}
body{font-family:'Inter',sans-serif;margin:0;background:var(--bg);color:var(--ink)}
nav{background:var(--green);height:70px;display:flex;align-items:center;justify-content:space-between;padding:0 20px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.12)}
nav a{color:#fff;text-decoration:none;font-weight:600;margin:0 10px}
.wrapper{max-width:1100px;margin:28px auto;padding:0 18px}
h2{margin:10px 0 16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media (max-width:950px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
label{display:block;font-weight:700;margin-bottom:6px}
input[type=text], input[type=number], textarea{
  width:100%;padding:12px;border:1px solid var(--stroke);border-radius:10px;background:#fff
}
textarea{min-height:110px;resize:vertical}
.row{margin-bottom:12px}
.btn{all:unset;background:var(--green);color:#fff;padding:12px 16px;border-radius:10px;font-weight:700;cursor:pointer}
.flash{margin:8px 0 14px;padding:10px 12px;border-radius:10px;background:#ecfdf5;color:#166534;border:1px solid #86efac}
.table{width:100%;border-collapse:collapse;margin-top:8px}
.table th,.table td{border:1px solid var(--stroke);padding:10px 12px;text-align:left;font-size:14px}
.table th{background:#f3f4f6}
.muted{color:var(--muted)}
</style>
</head>
<body>
  <nav>
    <div><strong>El Nuevo Puerta</strong></div>
    <div>
      <a href="agent_dashboard.php">Dashboard</a>
      <a href="agentprofile.php">Profile</a>
      <a href="agentsales.php">Sales</a>
      <a href="agentnotification.php">Notifications</a>
      <a href="logout.php">Logout</a>
    </div>
  </nav>

  <div class="wrapper">
    <h2>Sales &mdash; <?php echo h($fullName); ?></h2>
    <?php if($flash): ?><div class="flash"><?php echo h($flash); ?></div><?php endif; ?>

    <div class="grid">
      <!-- Add Sale -->
      <div class="card">
        <h3 style="margin-top:0">Add New Sale</h3>
        <form method="post">
          <div class="row">
            <label for="lot_no">Lot No</label>
            <input type="text" id="lot_no" name="lot_no" placeholder="e.g., BLK 12 LT 3" required>
          </div>
          <div class="row">
            <label for="amount">Amount (₱)</label>
            <input type="number" step="0.01" min="0" id="amount" name="amount" placeholder="e.g., 150000.00" required>
          </div>
          <div class="row">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" placeholder="Add context about the sale (buyer, terms, etc.)"></textarea>
          </div>
          <button class="btn" type="submit" name="add_sale" value="1">Save Sale</button>
        </form>
      </div>

      <!-- Recent Sales -->
      <div class="card">
        <h3 style="margin-top:0">Recent Sales</h3>
        <?php if(empty($sales)): ?>
          <p class="muted">No sales yet.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Lot No</th>
                <th>Amount</th>
                <th>Notes</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($sales as $s): ?>
              <tr>
                <td><?php echo h($s['lot_no']); ?></td>
                <td><?php echo is_null($s['amount']) ? '—' : '₱ '.number_format((float)$s['amount'],2); ?></td>
                <td><?php echo h($s['notes']); ?></td>
                <td><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
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
