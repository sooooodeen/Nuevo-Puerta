<?php
// filepath: c:\xampp\htdocs\nuevopuerta\admin_leads.php
require_once 'dbconn.php';
session_start();
// Add admin authentication as needed

$result = $conn->query("SELECT * FROM leads ORDER BY created_at DESC");

$metrics = [];
$statuses = ['new','contacted','scheduled','viewed','converted'];
foreach ($statuses as $s) {
  $res = $conn->query("SELECT COUNT(*) AS c FROM leads WHERE status='$s'");
  $metrics[$s] = $res->fetch_assoc()['c'];
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Leads List</title>
</head>
<body>
  <h2>Leads List</h2>
  <div>
    <h3>Lead Funnel Metrics</h3>
    <ul>
      <?php foreach($metrics as $status=>$count): ?>
        <li><?= ucfirst($status) ?>: <?= $count ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <table border="1">
    <tr>
      <th>Name</th><th>Email</th><th>Phone</th><th>Location</th><th>Date</th><th>Status</th><th>Actions</th>
    </tr>
    <?php while ($row = $result->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
      <td><?= htmlspecialchars($row['email']) ?></td>
      <td><?= htmlspecialchars($row['phone']) ?></td>
      <td><?= htmlspecialchars($row['location']) ?></td>
      <td><?= htmlspecialchars($row['preferred_date']) ?></td>
      <td><?= htmlspecialchars($row['status']) ?></td>
      <td>
        <a href="admin_lead_detail.php?id=<?= $row['id'] ?>">View</a>
      </td>
    </tr>
    <?php endwhile; ?>
  </table>
</body>
</html>