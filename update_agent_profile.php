<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Optional: Debug session (remove/comment out after testing)
file_put_contents('session_debug.txt', print_r($_SESSION, true));

header('Content-Type: application/json');
require 'db_connection.php';

if (!isset($_SESSION['agent_id']) || empty($_SESSION['agent_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$agent_id = $_SESSION['agent_id'];
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$address = trim($_POST['address'] ?? '');
$experience = intval($_POST['experience'] ?? 0);
$total_sales = intval($_POST['total_sales'] ?? 0);
$description = trim($_POST['description'] ?? '');
$profile_picture_url = null;

// Handle profile picture upload
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    if (!is_dir('uploads')) {
        mkdir('uploads', 0777, true);
    }
    $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $target = "uploads/agent_{$agent_id}_" . time() . '.' . $ext;
    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
        $profile_picture_url = $target;
    }
}

// Update agent info
if ($profile_picture_url) {
    $sql = "UPDATE agent_accounts SET first_name=?, last_name=?, email=?, mobile=?, address=?, experience=?, total_sales=?, description=?, profile_picture=? WHERE id=?";
    $stmt = $conn->prepare($sql); // <-- FIXED
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]); // <-- FIXED
        $conn->close(); // <-- FIXED
        exit;
    }
    $stmt->bind_param("sssssiissi", $first_name, $last_name, $email, $mobile, $address, $experience, $total_sales, $description, $profile_picture_url, $agent_id);
} else {
    $sql = "UPDATE agent_accounts SET first_name=?, last_name=?, email=?, mobile=?, address=?, experience=?, total_sales=?, description=? WHERE id=?";
    $stmt = $conn->prepare($sql); // <-- FIXED
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]); // <-- FIXED
        $conn->close(); // <-- FIXED
        exit;
    }
    $stmt->bind_param("sssssiisi", $first_name, $last_name, $email, $mobile, $address, $experience, $total_sales, $description, $agent_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database update failed: ' . $stmt->error]);
}
$stmt->close();
$conn->close(); // <-- FIXED
exit;