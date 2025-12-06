<?php
require_once 'dbconn.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = intval($_POST['lead_id']);
    $scheduled_at = $_POST['scheduled_at'];
    $meeting_location = $_POST['meeting_location'];
    $schedule_notes = $_POST['schedule_notes'];

    $stmt = $conn->prepare("UPDATE leads SET scheduled_at=?, meeting_location=?, schedule_notes=?, status='scheduled' WHERE id=?");
    $stmt->bind_param("sssi", $scheduled_at, $meeting_location, $schedule_notes, $lead_id);
    $stmt->execute();
    $stmt->close();

    // After updating the lead
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Viewing Scheduled', 'Scheduled for $scheduled_at at $meeting_location')");

    // Send schedule confirmation email
    $to = $lead['email'];
    $subject = "Viewing Scheduled";
    $message = "Your viewing is scheduled for " . $scheduled_at . " at " . $meeting_location;
    mail($to, $subject, $message);

    $note = "Some note"; // You need to define how $note is set
    $preferred_date = "Some date"; // You need to define how $preferred_date is set
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Updated', 'Note: $note, Date: $preferred_date')");
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Cancelled', 'Request cancelled by guest')");

    header("Location: admin_lead_detail.php?id=$lead_id");
    exit;
}
?>