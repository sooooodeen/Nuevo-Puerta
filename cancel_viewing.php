<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}

// accept JSON POST or GET?id=
$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['viewing_id']) ? (int)$input['viewing_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing viewing id']);
    exit;
}

$servername = "localhost"; $username="root"; $password=""; $dbname="nuevopuerta";
$conn = new mysqli($servername,$username,$password,$dbname);
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connect error']); exit; }
$conn->set_charset('utf8mb4');

// optional: ensure the logged-in user owns the viewing
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT client_email FROM viewings WHERE id = ? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
if (!($res && $row = $res->fetch_assoc())) {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Viewing not found']);
    exit;
}
$stmt->close();

// perform cancel (only if not already cancelled/done)
$upd = $conn->prepare("UPDATE viewings SET status = 'cancelled' WHERE id = ? AND LOWER(status) NOT IN ('cancelled','done')");
$upd->bind_param('i',$id);
$ok = $upd->execute();
$affected = $upd->affected_rows;
$upd->close();
$conn->close();

if ($ok && $affected > 0) {
    echo json_encode(['success'=>true,'message'=>'Viewing cancelled.']);
} else {
    // either already cancelled/done or update failed
    echo json_encode(['success'=>false,'message'=>'Unable to cancel (maybe already cancelled/done).']);
}
?>
