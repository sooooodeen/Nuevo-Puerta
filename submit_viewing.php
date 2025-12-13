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

// ---------------- READ POST DATA ----------------
$agent_id          = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;
$user_id           = null; // guests have no account

$client_first_name  = trim($_POST['client_first_name']  ?? '');
$client_middle_name = trim($_POST['client_middle_name'] ?? '');
$client_last_name   = trim($_POST['client_last_name']   ?? '');
$client_email       = trim($_POST['client_email']       ?? '');
$client_phone       = trim($_POST['client_phone']       ?? '');
$location           = trim($_POST['location']           ?? '');
$notes              = trim($_POST['notes']              ?? '');

$lot_no_raw         = trim($_POST['lot_no']             ?? '');
$preferred_at       = trim($_POST['preferredDateTime']  ?? '');
$status             = 'pending';

$client_lat         = ($_POST['client_lat']  ?? '') === '' ? null : floatval($_POST['client_lat']);
$client_lng         = ($_POST['client_lng'] ?? '') === '' ? null : floatval($_POST['client_lng']);

// NEW: get location_id and lot_id from POST
$location_id       = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
$lot_id            = isset($_POST['lot_id'])      && $_POST['lot_id']      !== '' ? (int)$_POST['lot_id']      : null;

// ---------------- BASIC VALIDATION ----------------
if ($client_first_name === '' || $client_last_name === '' || $client_email === '' || $client_phone === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// ---------------- RESOLVE REAL lot_no FROM lot_id (if possible) ----------------
$lot_no = $lot_no_raw;

if ($lot_id !== null) {
    $lotStmt = $conn->prepare("SELECT lot_number FROM lots WHERE id = ?");
    if ($lotStmt) {
        $lotStmt->bind_param("i", $lot_id);
        $lotStmt->execute();
        $lotResult = $lotStmt->get_result();
        if ($lotRow = $lotResult->fetch_assoc()) {
            $lot_no = $lotRow['lot_number']; // trust DB value
        }
        $lotStmt->close();
    }
}

// ---------------- INSERT VIEWING ----------------

$sql = "INSERT INTO viewings
    (agent_id, user_id, client_first_name, client_middle_name, client_last_name, client_email, client_phone,
     lot_no, preferred_at, status, client_lat, client_lng, location_id, lot_id, notes, location, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

/*
 Types:
  agent_id      i
  user_id       i
  client_first  s
  client_middle s
  client_last   s
  email         s
  phone         s
  lot_no        s (can be stored as string or int)
  preferred_at  s
  status        s
  client_lat    d
  client_lng    d
  location_id   i
  lot_id        i
  notes         s
  location      s
*/
$types = "iissssssssddiiss";

$stmt->bind_param(
    $types,
    $agent_id, $user_id,
    $client_first_name, $client_middle_name, $client_last_name, $client_email, $client_phone,
    $lot_no, $preferred_at, $status,
    $client_lat, $client_lng,
    $location_id, $lot_id,
    $notes, $location
);

if ($stmt->execute()) {
    // Fetch full lot details if lot_id is present
    $lot_details = null;
    if ($lot_id) {
        $lotQuery = $conn->prepare("SELECT block_number, lot_number, lot_size, lot_price FROM lots WHERE id = ?");
        $lotQuery->bind_param("i", $lot_id);
        $lotQuery->execute();
        $lotResult = $lotQuery->get_result();
        if ($lotRow = $lotResult->fetch_assoc()) {
            $lot_details = $lotRow;
        }
        $lotQuery->close();
    }
    echo json_encode([
        'success' => true,
        'id' => $stmt->insert_id,
        'lot_details' => $lot_details
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
