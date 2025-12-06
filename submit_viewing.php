<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

header('Content-Type: application/json');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  echo json_encode(['success' => false, 'error' => $conn->connect_error]);
  exit;
}

// Read POST
$agent_id          = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;
$user_id           = null; // guests have no account
$client_first_name = trim($_POST['firstName'] ?? '');
$client_last_name  = trim($_POST['lastName'] ?? '');
$client_email      = trim($_POST['email'] ?? '');
$client_phone      = trim($_POST['phone'] ?? '');
$lot_no            = trim($_POST['lot_no'] ?? '');
$preferred_at      = trim($_POST['preferredDateTime'] ?? '');
$status            = 'pending';
$client_lat        = ($_POST['latitude']  ?? '') === '' ? null : floatval($_POST['latitude']);
$client_lng        = ($_POST['longitude'] ?? '') === '' ? null : floatval($_POST['longitude']);

// Basic validation
if ($client_first_name === '' || $client_last_name === '' || $client_email === '' || $client_phone === '') {
  echo json_encode(['success' => false, 'error' => 'Missing required fields']);
  exit;
}

$sql = "INSERT INTO viewings
(agent_id, user_id, client_first_name, client_last_name, client_email, client_phone, lot_no, preferred_at, status, client_lat, client_lng, location_id, lot_id, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);

// Types: agent_id (i), user_id (i), then 7 strings, then 2 doubles
// => "iisssssssdd"
$types = "iisssssssddii";
$stmt->bind_param(
  $types,
  $agent_id, $user_id,
  $client_first_name, $client_last_name, $client_email, $client_phone,
  $lot_no, $preferred_at, $status,
  $client_lat, $client_lng,
  $location_id, $lot_id
);

if ($stmt->execute()) {
  echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
  echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
