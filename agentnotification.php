<?php
require_once 'dbconn.php';
session_start();

if (!isset($_SESSION['agent_id'])) {
  header('Location: Login/login.php');
  exit;
}

$agentId = (int)$_SESSION['agent_id'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* -----------------------------------------------------------------------------
   Ensure messages table exists (safe no-op if already present)
----------------------------------------------------------------------------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    name VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    message TEXT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_agent (agent_id, is_read, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* -----------------------------------------------------------------------------
   Actions (mark read / unread / mark all read)
----------------------------------------------------------------------------- */
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['mark_read'])) {
    $id = (int)$_POST['mark_read'];
    $stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE id=? AND agent_id=?");
    if ($stmt) {
      $stmt->bind_param('ii', $id, $agentId);
      $stmt->execute();
      $stmt->close();
      $flash = 'Marked as read.';
    }
  }

  if (isset($_POST['mark_unread'])) {
    $id = (int)$_POST['mark_unread'];
    $stmt = $conn->prepare("UPDATE messages SET is_read=0 WHERE id=? AND agent_id=?");
    if ($stmt) {
      $stmt->bind_param('ii', $id, $agentId);
      $stmt->execute();
      $stmt->close();
      $flash = 'Marked as unread.';
    }
  }

  if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE agent_id=? AND (is_read=0 OR is_read IS NULL)");
    if ($stmt) {
      $stmt->bind_param('i', $agentId);
      $stmt->execute();
      $stmt->close();
      $flash = 'All messages marked as read.';
    }
  }
}

/* -----------------------------------------------------------------------------
   Fetch data
----------------------------------------------------------------------------- */
$unreadCount = 0;
if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE agent_id=? AND (is_read=0 OR is_read IS NULL)")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $unreadCount = (int)($r['c'] ?? 0);
  $stmt->close();
}

$messages = [];
if ($stmt = $conn->prepare("
  SELECT id, name, phone, email, message, is_read, created_at
  FROM messages
  WHERE agent_id=?
  ORDER BY is_read ASC, created_at DESC
  LIMIT 200
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* Optional: show agent name in header */
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
<title>Notifications</title>
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
.card{background:var(--card);border:1px solid var(--stroke);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.flash{margin:8px 0 14px;padding:10px 12px;border-radius:10px;background:#ecfdf5;color:#166534;border:1px solid #86efac}
.badge{padding:6px 10px;border-radius:999px;font-size:12px;border:1px solid var(--stroke);background:#f8fafc;color:#1f2937}
.list{margin-top:12px}
.item{display:grid;grid-template-columns:1fr 140px;gap:12px;border:1px solid var(--stroke);border-radius:14px;padding:14px;background:#fff;margin-bottom:10px}
.meta{color:var(--muted);font-size:13px}
.actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.btn{all:unset;background:var(--green);color:#fff;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer}
.btn.secondary{background:#475569}
.empty{padding:22px;border:1px dashed var(--stroke);border-radius:14px;background:#fff;color:var(--muted)}
.unread{border-color:#86efac; box-shadow:0 0 0 3px rgba(134,239,172,.25)}
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
    <div class="header">
      <h2>Notifications — <?php echo h($fullName); ?></h2>
      <form method="post">
        <button class="btn" name="mark_all_read" value="1">Mark all as read</button>
        <span class="badge"><?php echo $unreadCount; ?> unread</span>
      </form>
    </div>

    <?php if($flash): ?><div class="flash"><?php echo h($flash); ?></div><?php endif; ?>

    <div class="card">
      <?php if(empty($messages)): ?>
        <div class="empty">No messages yet.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach($messages as $m): ?>
            <div class="item <?php echo (int)$m['is_read']===0 ? 'unread' : ''; ?>">
              <div>
                <div style="font-weight:700;margin-bottom:4px">
                  <?php echo h($m['name'] ?: 'Unknown'); ?>
                  <span class="meta"> • <?php echo h($m['email'] ?: '—'); ?> • <?php echo h($m['phone'] ?: '—'); ?></span>
                </div>
                <div class="meta"><?php echo date('M d, Y h:i A', strtotime($m['created_at'])); ?></div>
                <div style="margin-top:8px;"><?php echo nl2br(h($m['message'])); ?></div>
              </div>
              <div class="actions">
                <form method="post">
                  <?php if ((int)$m['is_read'] === 0): ?>
                    <button class="btn" name="mark_read" value="<?php echo (int)$m['id']; ?>">Mark read</button>
                  <?php else: ?>
                    <button class="btn secondary" name="mark_unread" value="<?php echo (int)$m['id']; ?>">Mark unread</button>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
