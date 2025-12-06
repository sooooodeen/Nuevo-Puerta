<?php
require_once 'dbconn.php';

// Find viewings scheduled for tomorrow
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$sql = "SELECT * FROM leads WHERE scheduled_at >= '$tomorrow 00:00:00' AND scheduled_at <= '$tomorrow 23:59:59' AND status='scheduled'";
$result = $conn->query($sql);

while ($lead = $result->fetch_assoc()) {
    // Send reminder to guest
    $to = $lead['email'];
    $subject = "Viewing Reminder";
    $message = "This is a reminder for your viewing scheduled on " . $lead['scheduled_at'] . " at " . $lead['meeting_location'] . ".";
    mail($to, $subject, $message);

    // Send reminder to agent if assigned
    if ($lead['agent_id']) {
        $agentRes = $conn->query("SELECT email FROM agent_accounts WHERE id=" . intval($lead['agent_id']));
        if ($agent = $agentRes->fetch_assoc()) {
            $agentTo = $agent['email'];
            $agentSubject = "Viewing Reminder";
            $agentMessage = "You have a viewing scheduled with " . $lead['first_name'] . " " . $lead['last_name'] . " on " . $lead['scheduled_at'] . " at " . $lead['meeting_location'] . ".";
            mail($agentTo, $agentSubject, $agentMessage);
        }
    }
}
?>