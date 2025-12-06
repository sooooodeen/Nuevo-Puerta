<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$viewing_id  = isset($input['viewing_id']) ? (int)$input['viewing_id'] : 0;
$agent_id    = isset($input['agent_id']) ? (int)$input['agent_id'] : 0;
$preferred_at= trim($input['preferred_at'] ?? '');
$message     = trim($input['message'] ?? '');

if (!$viewing_id || !$preferred_at || !$agent_id) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing required fields']);
    exit;
}

/* DB connection — adjust if your config differs */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

function hasTable($conn, $t){ $safe=$conn->real_escape_string($t); $r=$conn->query("SHOW TABLES LIKE '$safe'"); return $r && $r->num_rows>0; }
function hasColumn($conn,$t,$c){ $t=$conn->real_escape_string($t); $c=$conn->real_escape_string($c); $r=$conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return $r && $r->num_rows>0; }

$now = date('Y-m-d H:i:s');
$statusLabel = 'reschedule_requested';

/* 1) Update viewing (avoid referencing non-existent updated_at) */
$updSql = "UPDATE viewings SET preferred_at = ?, status = ? WHERE id = ?";
$stmt = $conn->prepare($updSql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$conn->error]);
    exit;
}
$stmt->bind_param('ssi',$preferred_at,$statusLabel,$viewing_id);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Failed to update viewing']);
    exit;
}

/* 2) Try in-app notification (notifications table if present) */
$notifSent = false;
if (hasTable($conn,'notifications')) {
    $cols = [];
    foreach (['recipient_type','recipient_id','title','message','is_read','created_at'] as $c) {
        if (hasColumn($conn,'notifications',$c)) $cols[] = $c;
    }
    if (!empty($cols)) {
        $fields = implode(',', $cols);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $types = '';
        $vals = [];
        foreach ($cols as $c) {
            if ($c === 'recipient_type') { $types .= 's'; $vals[] = 'agent'; }
            elseif ($c === 'recipient_id') { $types .= 'i'; $vals[] = $agent_id; }
            elseif ($c === 'title') { $types .= 's'; $vals[] = "Reschedule request for viewing #{$viewing_id}"; }
            elseif ($c === 'message') { $types .= 's'; $vals[] = "Client requests new time: {$preferred_at}\n\nMessage: {$message}"; }
            elseif ($c === 'is_read') { $types .= 'i'; $vals[] = 0; }
            elseif ($c === 'created_at') { $types .= 's'; $vals[] = $now; }
            else { $types .= 's'; $vals[] = ''; }
        }
        $ins = $conn->prepare("INSERT INTO notifications ($fields) VALUES ($placeholders)");
        if ($ins) {
            $ins->bind_param($types, ...$vals);
            $ins->execute();
            $ins->close();
            $notifSent = true;
        }
    }
}

/* 3) Fallback: insert into messages table if available */
$msgInserted = false;
if (!$notifSent && hasTable($conn,'messages')) {
    $has_sender = hasColumn($conn,'messages','sender_id');
    $has_recipient = hasColumn($conn,'messages','recipient_id');
    $has_subject = hasColumn($conn,'messages','subject');
    $has_body = hasColumn($conn,'messages','message') || hasColumn($conn,'messages','body') || hasColumn($conn,'messages','msg');
    $created_col = hasColumn($conn,'messages','created_at') ? 'created_at' : null;

    if ($has_recipient && $has_body) {
        $fields = []; $place = []; $types = ''; $vals = [];
        if ($has_sender) { $fields[] = 'sender_id'; $place[]='?'; $types.='i'; $vals[] = (int)$_SESSION['user_id']; }
        if ($has_recipient) { $fields[] = 'recipient_id'; $place[]='?'; $types.='i'; $vals[] = $agent_id; }
        if ($has_subject) { $fields[] = 'subject'; $place[]='?'; $types.='s'; $vals[] = "Reschedule request (#{$viewing_id})"; }
        $bodyCol = hasColumn($conn,'messages','message') ? 'message' : (hasColumn($conn,'messages','body') ? 'body' : 'msg');
        $fields[] = $bodyCol; $place[]='?'; $types.='s'; $vals[] = "Client requests new time: {$preferred_at}\n\nMessage: {$message}";
        if ($created_col) { $fields[] = $created_col; $place[]='?'; $types.='s'; $vals[] = $now; }

        $insSql = "INSERT INTO messages (" . implode(',', $fields) . ") VALUES (" . implode(',', $place) . ")";
        $ins = $conn->prepare($insSql);
        if ($ins) {
            $ins->bind_param($types, ...$vals);
            $ins->execute();
            $ins->close();
            $msgInserted = true;
        }
    }
}

/* 4) Email agent if email exists */
$agentEmail = null;
$res = $conn->query("SELECT email, first_name, last_name FROM agent_accounts WHERE id = ".(int)$agent_id." LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $agentEmail = $row['email'] ?? null;
    $agentName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}
if ($agentEmail) {
    $subject = "Reschedule request for viewing #{$viewing_id}";
    $body = "Hello {$agentName},\n\nClient requested new time: {$preferred_at}\n\nMessage:\n{$message}\n\nPlease login to the dashboard to confirm.";
    @mail($agentEmail, $subject, $body, "From: no-reply@nuevopuerta.local");
}

$conn->close();

echo json_encode([
    'success' => true,
    'message' => ($notifSent || $msgInserted) ? 'Request sent and agent notified.' : 'Request saved. Agent will be notified if possible.'
]);
exit;
?>