<?php
// filepath: c:\xampp\htdocs\nuevopuerta\admin_lead_detail.php
require_once 'dbconn.php';
session_start();
// Add admin authentication as needed

$id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM leads WHERE id = $id");
$lead = $result ? $result->fetch_assoc() : null;
$events = $conn->query("SELECT * FROM lead_events WHERE lead_id=" . intval($lead['id']) . " ORDER BY event_time ASC");
?>
<!DOCTYPE html>
<html>
<head>
  <title>Lead Detail</title>
</head>
<body>
  <h2>Lead Detail</h2>
  <?php
  if (!$lead) {
      echo "<p>Lead not found.</p>";
      exit;
  }
  ?>
  <p><strong>Name:</strong> <?= htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']) ?></p>
  <p><strong>Email:</strong> <?= htmlspecialchars($lead['email']) ?></p>
  <p><strong>Phone:</strong> <?= htmlspecialchars($lead['phone']) ?></p>
  <p><strong>Location:</strong> <?= htmlspecialchars($lead['location']) ?></p>
  <p><strong>Date:</strong> <?= htmlspecialchars($lead['preferred_date']) ?></p>
  <p><strong>Status:</strong> <?= htmlspecialchars($lead['status']) ?></p>
  <p><strong>Notes:</strong> <?= htmlspecialchars($lead['note']) ?></p>
  <!-- Actions: Assign agent, change status, schedule viewing -->
  <form method="POST" action="admin_lead_action.php">
    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
    <label>
      Assign Agent:
      <select name="agent_id">
        <?php
        $agents = $conn->query("SELECT id, first_name, last_name FROM agent_accounts WHERE status='active'");
        while ($agent = $agents->fetch_assoc()) {
          echo '<option value="'.$agent['id'].'">'.htmlspecialchars($agent['first_name'].' '.$agent['last_name']).'</option>';
        }
        ?>
      </select>
    </label>
    <label>
      Change Status:
      <select name="status">
        <option value="new">New</option>
        <option value="contacted">Contacted</option>
        <option value="scheduled">Scheduled</option>
        <option value="cancelled">Cancelled</option>
        <option value="converted">Converted</option>
      </select>
    </label>
    <button type="submit">Update Lead</button>
  </form>
  <form method="POST" action="admin_lead_schedule.php">
    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
    <label>Schedule Date & Time: <input type="datetime-local" name="scheduled_at" required></label>
    <label>Meeting Location: <input type="text" name="meeting_location"></label>
    <label>Notes: <textarea name="schedule_notes"></textarea></label>
    <button type="submit">Schedule Viewing</button>
  </form>
  <form method="POST" action="admin_lead_convert.php">
    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
    <button type="submit">Convert to User</button>
  </form>
  <form method="POST" action="admin_lead_outcome.php">
    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
    <button name="outcome" value="no_show">Mark as No-Show</button>
    <button name="outcome" value="lot_unavailable">Mark Lot Unavailable</button>
  </form>
  <h3>Lead Timeline</h3>
  <ul>
    <?php while ($event = $events->fetch_assoc()): ?>
      <li>
        <strong><?= htmlspecialchars($event['event_type']) ?>:</strong>
        <?= htmlspecialchars($event['event_detail']) ?> (<?= $event['event_time'] ?>)
      </li>
    <?php endwhile; ?>
  </ul>
</body>
</html>