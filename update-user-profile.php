<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Must be logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Read JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = [];
}

// Helper sanitizer
function clean($v) {
    return trim((string)$v);
}

// Fields expected from frontend (photo included)
$allowed = [
    'first_name',
    'middle_name',
    'last_name',
    'username',
    'email',
    'mobile_number',
    'address',
    'photo'
];

// Normalise incoming values: null means "not sent", so keep DB value later
$values = [];
foreach ($allowed as $k) {
    $values[$k] = array_key_exists($k, $data) ? clean($data[$k]) : null;
}

// ---- DB connection ----
$conn = new mysqli("localhost", "root", "", "nuevopuerta");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// ---- Load current values from DB ----
$stmt = $conn->prepare("
    SELECT first_name, middle_name, last_name, username, email,
           mobile_number, address, photo
    FROM user_accounts
    WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result  = $stmt->get_result();
$current = $result->fetch_assoc();
$stmt->close();

if (!$current) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    $conn->close();
    exit;
}

// ---- Merge new values with existing ones ----
// If $values[field] is null => keep $current[field]
// If $values[field] is "" or any string => use that
$first_name    = ($values['first_name']    === null) ? $current['first_name']    : $values['first_name'];
$middle_name   = ($values['middle_name']   === null) ? $current['middle_name']   : $values['middle_name'];
$last_name     = ($values['last_name']     === null) ? $current['last_name']     : $values['last_name'];
$username      = ($values['username']      === null) ? $current['username']      : $values['username'];
$email         = ($values['email']         === null) ? $current['email']         : $values['email'];
$mobile_number = ($values['mobile_number'] === null) ? $current['mobile_number'] : $values['mobile_number'];
$address       = ($values['address']       === null) ? $current['address']       : $values['address'];
$photo         = ($values['photo']         === null) ? $current['photo']         : $values['photo'];

// ---- Detect if anything actually changed ----
$changed = [];
if ($first_name    !== $current['first_name'])    $changed['first_name']    = $first_name;
if ($middle_name   !== $current['middle_name'])   $changed['middle_name']   = $middle_name;
if ($last_name     !== $current['last_name'])     $changed['last_name']     = $last_name;
if ($username      !== $current['username'])      $changed['username']      = $username;
if ($email         !== $current['email'])         $changed['email']         = $email;
if ($mobile_number !== $current['mobile_number']) $changed['mobile_number'] = $mobile_number;
if ($address       !== $current['address'])       $changed['address']       = $address;
if ($photo         !== $current['photo'])         $changed['photo']         = $photo;

// If nothing changed, short-circuit
if (empty($changed)) {
    echo json_encode([
        'success' => true,
        'changed' => false,
        'message' => 'No changes'
    ]);
    $conn->close();
    exit;
}

// ---- UPDATE DB ----
$sql = "UPDATE user_accounts 
        SET first_name = ?, middle_name = ?, last_name = ?, username = ?, email = ?, 
            mobile_number = ?, address = ?, photo = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Prepare failed: '.$conn->error
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param(
    "ssssssssi",
    $first_name,
    $middle_name,
    $last_name,
    $username,
    $email,
    $mobile_number,
    $address,
    $photo,
    $user_id
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Execute failed: '.$stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$stmt->close();
$conn->close();

// Final JSON response
echo json_encode([
    'success' => true,
    'changed' => true,
    'message' => 'Profile updated successfully',
    'updated' => $changed
]);
exit;
