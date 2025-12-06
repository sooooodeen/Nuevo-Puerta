<?php


session_start();
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Use user_id for user dashboard
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$current = $data['current_password'] ?? '';
$new = $data['new_password'] ?? '';
$user_id = (int)$_SESSION['user_id'];

$conn = new mysqli("localhost", "root", "", "nuevopuerta");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Get current password hash from user_accounts
$stmt = $conn->prepare("SELECT password FROM user_accounts WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($hash);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

if (!password_verify($current, $hash)) {
    echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
    $conn->close();
    exit;
}

$newHash = password_hash($new, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE user_accounts SET password = ? WHERE id = ?");
$stmt->bind_param("si", $newHash, $user_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update password.']);
}
$stmt->close();
$conn->close();