<?php
require_once 'dbconn.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = intval($_POST['lead_id']);
    $result = $conn->query("SELECT * FROM leads WHERE id=$lead_id");
    $lead = $result->fetch_assoc();

    $stmt = $conn->prepare("INSERT INTO user_accounts (first_name, last_name, email, mobile_number, address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $lead['first_name'], $lead['last_name'], $lead['email'], $lead['phone'], $lead['location']);
    $stmt->execute();
    $user_id = $stmt->insert_id;
    $stmt->close();

    $conn->query("ALTER TABLE leads ADD COLUMN user_id INT DEFAULT NULL");
    $stmt = $conn->prepare("UPDATE leads SET user_id=?, status='converted', converted_at=NOW() WHERE id=?");
    $stmt->bind_param("ii", $user_id, $lead_id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_lead_detail.php?id=$lead_id");
    exit;
}
?>