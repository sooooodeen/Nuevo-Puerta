<?php
// filepath: c:\xampp\htdocs\nuevopuerta\admin_lead_action.php
require_once 'dbconn.php';
session_start();
// Add admin authentication as needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = intval($_POST['lead_id']);
    $agent_id = intval($_POST['agent_id']);
    $status = $_POST['status'];

    // You may want to add agent_id and status columns to your leads table if not present
    $conn->query("ALTER TABLE leads ADD COLUMN agent_id INT DEFAULT NULL");

    $stmt = $conn->prepare("UPDATE leads SET agent_id=?, status=? WHERE id=?");
    $stmt->bind_param("isi", $agent_id, $status, $lead_id);
    $stmt->execute();
    $stmt->close();

    // After updating the lead
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Status Changed', 'Changed to $status')");

    header("Location: admin_lead_detail.php?id=$lead_id");
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lead_id = intval($_GET['id']);

    // Fetch lead details
    $stmt = $conn->prepare("SELECT * FROM leads WHERE id=?");
    $stmt->bind_param("i", $lead_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lead = $result->fetch_assoc();
    $stmt->close();

    // If lead exists, mark as viewed
    if ($lead) {
        $scheduled_at = $lead['scheduled_at'];
        $meeting_location = $lead['meeting_location'];

        $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Viewing Scheduled', 'Scheduled for $scheduled_at at $meeting_location')");
    }
}

$conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Updated', 'Note: $note, Date: $preferred_date')");
$conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Cancelled', 'Request cancelled by guest')");
?>