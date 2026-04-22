<?php
require_once 'dbconn.php';

$ip_address = $_SERVER['REMOTE_ADDR'];
$res = $conn->query("SELECT COUNT(*) AS c FROM leads WHERE ip_address='$ip_address' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$count = $res ? $res->fetch_assoc()['c'] : 0;
if ($count >= 3) {
    die("Rate limit exceeded. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $phone      = trim($_POST['phone']);
    $location   = trim($_POST['location']);
    $preferred_date = trim($_POST['preferred_date']);
    $note       = trim($_POST['note']);
    $consent    = isset($_POST['consent']) ? 1 : 0;
    $location_id = $_POST['location_id'] ?? null;
    $lot_id = $_POST['lot_id'] ?? null;

    $sql = "SELECT id FROM leads WHERE email=? AND phone=? AND lot_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $email, $phone, $lot_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        // Show message or attach as new activity
        die("We already have your request; we’ll follow up.");
    }
    $stmt->close();

    // After validating and before inserting the lead
    $track_token = bin2hex(random_bytes(16)); // generates a secure token

    $sql = "INSERT INTO leads (first_name, last_name, email, phone, location, preferred_date, note, consent, source, status, ip_address, track_token, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'guest', 'new', ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssis", $first_name, $last_name, $email, $phone, $location, $preferred_date, $note, $consent, $ip_address, $track_token);
    $stmt->execute();
    $ref = $conn->insert_id;
    $stmt->close();

    // Insert into viewings table
    $stmt = $conn->prepare("INSERT INTO viewings (client_first_name, client_last_name, client_email, client_phone, location_id, lot_id, preferred_at, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("ssssisss", $first_name, $last_name, $email, $phone, $location_id, $lot_id, $preferred_date, $note);
    $stmt->execute();
    $stmt->close();

    // Redirect with token
    header("Location: thank_you.html?ref=$ref&token=$track_token");
    exit;
}

// submit_lead.php (after saving lead)
$to = $email;
$subject = "Your Viewing Request Received";
$message = "Thank you for your request! Reference #: $ref";
mail($to, $subject, $message);

// admin_lead_schedule.php (after scheduling)
$to = $lead['email'];
$subject = "Viewing Scheduled";
$message = "Your viewing is scheduled for " . $scheduled_at . " at " . $meeting_location;
mail($to, $subject, $message);
?>