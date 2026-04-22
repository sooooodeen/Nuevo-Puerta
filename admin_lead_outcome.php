<?php
require_once 'dbconn.php';
session_start();

$lead_id = intval($_POST['lead_id']);
$outcome = $_POST['outcome'];

if ($outcome === 'no_show') {
    $status = 'no_show';
} elseif ($outcome === 'lot_unavailable') {
    $status = 'lot_unavailable';
}

$stmt = $conn->prepare("UPDATE leads SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $lead_id);
$stmt->execute();
$stmt->close();

header("Location: admin_lead_detail.php?id=$lead_id");
exit;
?>