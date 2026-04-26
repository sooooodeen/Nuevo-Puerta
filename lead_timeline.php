<?php
require_once 'dbconn.php';
session_start();

$lead_id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM lead_events WHERE lead_id=$lead_id ORDER BY event_time ASC");
?>
<ul>
  <?php while ($event = $result->fetch_assoc()): ?>
    <li>
      <strong><?= htmlspecialchars($event['event_type']) ?>:</strong>
      <?= htmlspecialchars($event['event_detail']) ?> (<?= $event['event_time'] ?>)
    </li>
  <?php endwhile; ?>
</ul>