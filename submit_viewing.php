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

// Ensure appointment_type column exists
$conn->query("ALTER TABLE viewings ADD COLUMN IF NOT EXISTS appointment_type VARCHAR(32) DEFAULT 'appointment'");

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

$preferred_down_payment = trim($_POST['preferred_down_payment'] ?? '');
$preferred_term_years = trim($_POST['preferred_term_years'] ?? '');
$preferred_due_day = trim($_POST['preferred_due_day'] ?? '');
$preferred_monthly_amount = trim($_POST['preferred_monthly_amount'] ?? '');
$preferred_remaining_balance = trim($_POST['preferred_remaining_balance'] ?? '');
$preferred_payment_method = trim($_POST['preferred_payment_method'] ?? '');
$preferred_financing_option = trim($_POST['preferred_financing_option'] ?? '');

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

// Attach client preferred installment settings to notes for agent/admin visibility.
$preferredParts = [];
if ($preferred_down_payment !== '' && is_numeric($preferred_down_payment)) {
    $preferredParts[] = 'Down Payment: PHP ' . number_format((float)$preferred_down_payment, 2);
}
if ($preferred_term_years !== '' && ctype_digit($preferred_term_years)) {
    $preferredParts[] = 'Term: ' . (int)$preferred_term_years . ' year(s)';
}
if ($preferred_due_day !== '' && ctype_digit($preferred_due_day)) {
    $preferredParts[] = 'Monthly Due Day: ' . (int)$preferred_due_day;
}
if ($preferred_monthly_amount !== '' && is_numeric($preferred_monthly_amount)) {
    $preferredParts[] = 'Estimated Monthly: PHP ' . number_format((float)$preferred_monthly_amount, 2);
}
if ($preferred_remaining_balance !== '' && is_numeric($preferred_remaining_balance)) {
    $preferredParts[] = 'Remaining Balance: PHP ' . number_format((float)$preferred_remaining_balance, 2);
}
if ($preferred_payment_method !== '' && in_array($preferred_payment_method, ['Cash', 'Bank', 'GCash'], true)) {
    $preferredParts[] = 'Payment Method: ' . $preferred_payment_method;
}
if ($preferred_financing_option !== '' && in_array($preferred_financing_option, ['Spot Cash Payment', 'Bank Financing', 'Pag-IBIG Financing', 'Deffered Cash Payment'], true)) {
    $preferredParts[] = 'Financing Option: ' . $preferred_financing_option;
}

if (!empty($preferredParts)) {
    $preferredText = '[Client Preferred Installment Plan] ' . implode(' | ', $preferredParts);
    $notes = $notes !== '' ? ($notes . "\n" . $preferredText) : $preferredText;
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


$appointmentType = isset($_POST['appointment_type']) && $_POST['appointment_type'] === 'appointment' ? 'appointment' : 'reservation';
$sql = "INSERT INTO viewings
    (agent_id, user_id, client_first_name, client_middle_name, client_last_name, client_email, client_phone,
     lot_no, preferred_at, status, client_lat, client_lng, location_id, lot_id, notes, location, appointment_type, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

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
  appointment_type s
*/
$types = "iissssssssddiisss";

$stmt->bind_param(
    $types,
    $agent_id, $user_id,
    $client_first_name, $client_middle_name, $client_last_name, $client_email, $client_phone,
    $lot_no, $preferred_at, $status,
    $client_lat, $client_lng,
    $location_id, $lot_id,
    $notes, $location,
    $appointmentType
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

    // --- Notify agent if this is an appointment ---
    if (isset($_POST['appointment_type']) && $_POST['appointment_type'] === 'appointment' && $agent_id) {
        // Create agent_notifications table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS agent_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT,
            title VARCHAR(255),
            message TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agent (agent_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $notifTitle = 'New Appointment Request';
        $notifMsg = 'A client has requested an appointment for Lot ' . htmlspecialchars($lot_no, ENT_QUOTES, 'UTF-8') .
            (!empty($preferred_at) ? (' on ' . htmlspecialchars($preferred_at, ENT_QUOTES, 'UTF-8')) : '') .
            '. Please review and approve or decline.';
        $notifStmt = $conn->prepare("INSERT INTO agent_notifications (agent_id, title, message) VALUES (?, ?, ?)");
        if ($notifStmt) {
            $notifStmt->bind_param('iss', $agent_id, $notifTitle, $notifMsg);
            $notifStmt->execute();
            $notifStmt->close();
        }
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
