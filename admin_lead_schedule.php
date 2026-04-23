<?php
require_once 'dbconn.php';
session_start();
require_once __DIR__ . '/includes/email_branding.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = intval($_POST['lead_id']);
    $scheduled_at = $_POST['scheduled_at'];
    $meeting_location = $_POST['meeting_location'];
    $schedule_notes = $_POST['schedule_notes'];

    $lead = null;
    $leadStmt = $conn->prepare("SELECT email, first_name, last_name FROM leads WHERE id=? LIMIT 1");
    if ($leadStmt) {
        $leadStmt->bind_param("i", $lead_id);
        $leadStmt->execute();
        $leadRes = $leadStmt->get_result();
        $lead = $leadRes ? $leadRes->fetch_assoc() : null;
        $leadStmt->close();
    }

    $stmt = $conn->prepare("UPDATE leads SET scheduled_at=?, meeting_location=?, schedule_notes=?, status='scheduled' WHERE id=?");
    $stmt->bind_param("sssi", $scheduled_at, $meeting_location, $schedule_notes, $lead_id);
    $stmt->execute();
    $stmt->close();

    // After updating the lead
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Viewing Scheduled', 'Scheduled for $scheduled_at at $meeting_location')");

    // Send schedule confirmation email
    $to = $lead['email'] ?? '';
    $subject = "Viewing Scheduled";
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $clientName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $messageHtml = '<p>Hello ' . htmlspecialchars($clientName !== '' ? $clientName : 'Client', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your viewing is scheduled for <strong>' . htmlspecialchars($scheduled_at, ENT_QUOTES, 'UTF-8') . '</strong> at <strong>' . htmlspecialchars($meeting_location, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Please check your dashboard for the next steps.</p>';
        $html = buildNovoPuertaEmailHtml(
            $subject,
            $messageHtml,
            ['intro' => 'Your viewing has been scheduled.', 'footer_note' => 'Please keep this email for your records.']
        );
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Nuevo Puerta <no-reply@nuevopuerta.local>',
        ];
        mail($to, $subject, $html, implode("\r\n", $headers));
    }

    $note = "Some note"; // You need to define how $note is set
    $preferred_date = "Some date"; // You need to define how $preferred_date is set
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Updated', 'Note: $note, Date: $preferred_date')");
    $conn->query("INSERT INTO lead_events (lead_id, event_type, event_detail) VALUES ($lead_id, 'Guest Cancelled', 'Request cancelled by guest')");

    header("Location: admin_lead_detail.php?id=$lead_id");
    exit;
}
?>