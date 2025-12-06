<?php
require_once 'dbconn.php';
$ref = intval($_GET['ref']);
$token = $_GET['token'] ?? '';

$stmt = $conn->prepare("SELECT first_name, last_name, status, scheduled_at, meeting_location, agent_id FROM leads WHERE id=? AND track_token=?");
$stmt->bind_param("is", $ref, $token);
$stmt->execute();
$result = $stmt->get_result();
$lead = $result->fetch_assoc();
$stmt->close();

if (!$lead) {
    echo "<p>Invalid or expired tracking link.</p>";
    exit;
}

// Get agent info if assigned
$agent_name = '';
if ($lead['agent_id']) {
    $a = $conn->query("SELECT first_name, last_name FROM agent_accounts WHERE id=" . intval($lead['agent_id']));
    if ($agent = $a->fetch_assoc()) {
        $agent_name = htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']);
    }
}
?>
<h2>Request Status</h2>
<p>Name: <strong><?= htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']) ?></strong></p>
<p>Status: <strong><?= htmlspecialchars($lead['status']) ?></strong></p>
<?php if ($lead['scheduled_at']): ?>
  <p>Scheduled Date: <strong><?= htmlspecialchars($lead['scheduled_at']) ?></strong></p>
<?php endif; ?>
<?php if ($lead['meeting_location']): ?>
  <p>Meeting Location: <strong><?= htmlspecialchars($lead['meeting_location']) ?></strong></p>
<?php endif; ?>
<?php if ($agent_name): ?>
  <p>Assigned Agent: <strong><?= $agent_name ?></strong></p>
<?php endif; ?>
<?php if ($lead['status'] !== 'cancelled'): ?>
  <form method="POST" action="track_lead_update.php?ref=<?= $ref ?>&token=<?= urlencode($token) ?>">
    <label>Update Notes or Preferred Date:</label><br>
    <textarea name="note"><?= htmlspecialchars($lead['note']) ?></textarea><br>
    <input type="date" name="preferred_date" value="<?= htmlspecialchars($lead['preferred_date']) ?>"><br>
    <button type="submit" name="action" value="update">Update Request</button>
    <button type="submit" name="action" value="cancel" onclick="return confirm('Are you sure you want to cancel this request?');">Cancel Request</button>
  </form>
<?php else: ?>
  <p>This request has been cancelled.</p>
<?php endif; ?>