<?php
require_once 'dbconn.php';

$ref = intval($_GET['ref']);
$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    // Validate token and lead
    $stmt = $conn->prepare("SELECT id FROM leads WHERE id=? AND track_token=?");
    $stmt->bind_param("is", $ref, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result->fetch_assoc()) {
        echo "Invalid request.";
        exit;
    }
    $stmt->close();

    if ($action === 'update') {
        $note = trim($_POST['note']);
        $preferred_date = trim($_POST['preferred_date']);
        $stmt = $conn->prepare("UPDATE leads SET note=?, preferred_date=? WHERE id=?");
        $stmt->bind_param("ssi", $note, $preferred_date, $ref);
        $stmt->execute();
        $stmt->close();
        $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($ref, 'Guest Updated', 'Note: $note, Date: $preferred_date')");
        header("Location: track_lead.php?ref=$ref&token=$token");
        exit;
    } elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE leads SET status='cancelled' WHERE id=?");
        $stmt->bind_param("i", $ref);
        $stmt->execute();
        $stmt->close();
        $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($ref, 'Guest Cancelled', 'Request cancelled by guest')");
        // After updating the lead
        $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($ref, 'Status Changed', 'Changed to cancelled')");
        header("Location: track_lead.php?ref=$ref&token=$token");
        exit;
    }
} else {
    // Track lead viewing
    $stmt = $conn->prepare("SELECT id, scheduled_at, meeting_location FROM leads WHERE id=?");
    $stmt->bind_param("i", $ref);
    $stmt->execute();
    $result = $stmt->get_result();
    $lead = $result->fetch_assoc();
    $stmt->close();

    if ($lead) {
        $scheduled_at = $lead['scheduled_at'];
        $meeting_location = $lead['meeting_location'];
        $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($ref, 'Viewing Scheduled', 'Scheduled for $scheduled_at at $meeting_location')");
    }
}
?>