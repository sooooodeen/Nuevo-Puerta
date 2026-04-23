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

$cancellation_reason = '';
if (isset($input['cancellation_reason'])) {
    $cancellation_reason = trim($input['cancellation_reason']);
} elseif (isset($_POST['cancellation_reason'])) {
    $cancellation_reason = trim($_POST['cancellation_reason']);
}
if (!$id) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing viewing id']);
    exit;
}

$servername = "localhost"; $username="root"; $password=""; $dbname="nuevopuerta";
$conn = new mysqli($servername,$username,$password,$dbname);
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connect error']); exit; }
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/includes/email_branding.php';

// optional: ensure the logged-in user owns the viewing
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT client_email, client_first_name, client_last_name, preferred_at, location_id, lot_id FROM viewings WHERE id = ? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
if (!($res && $row = $res->fetch_assoc())) {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Viewing not found']);
    exit;
}
$client_email = $row['client_email'];
$client_name = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));
$preferred_at = $row['preferred_at'];
$location_id = $row['location_id'];
$lot_id = $row['lot_id'];
$stmt->close();

// Fetch location and lot details for email (optional, fallback to IDs if not found)
$location_details = '';
if ($location_id && $lot_id) {
    $stmt = $conn->prepare("SELECT ll.location_name, l.block_number, l.lot_number, l.lot_size FROM lot_locations ll JOIN lots l ON l.location_id = ll.id WHERE ll.id = ? AND l.id = ? LIMIT 1");
    $stmt->bind_param('ii', $location_id, $lot_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $loc = $res->fetch_assoc()) {
        $location_details = $loc['location_name'] . "\nBlock " . $loc['block_number'] . ", Lot " . $loc['lot_number'] . " (" . $loc['lot_size'] . " sqm)";
    }
    $stmt->close();
}
if (!$location_details) {
    $location_details = "Location ID: $location_id, Lot ID: $lot_id";
}

// perform cancel (only if not already cancelled/done)
$upd = $conn->prepare("UPDATE viewings SET status = 'cancelled', cancellation_reason = ? WHERE id = ? AND LOWER(status) NOT IN ('cancelled','done')");
$upd->bind_param('si', $cancellation_reason, $id);
$ok = $upd->execute();
$affected = $upd->affected_rows;
$upd->close();
$conn->close();

if ($ok && $affected > 0) {
    // Send email notification to client using PHPMailer and Gmail SMTP
    if (filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                // SMTP config (change these for production)
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'carlomallari01471@gmail.com'; // <-- Set your real Gmail address here
                $mail->Password = 'rsmv pipf ijxf phha'; // <-- Your app password
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('carlomallari01471@gmail.com', 'Nuevo Puerta'); // <-- Set your real Gmail address here
                $mail->addReplyTo('carlomallari01471@gmail.com', 'Nuevo Puerta');
                $mail->addAddress($client_email, $client_name);

                $mail->isHTML(true);
                $mail->Subject = 'Your Viewing Has Been Cancelled';
                $bodyHtml = '<p>Dear ' . htmlspecialchars($client_name !== '' ? $client_name : 'Client', ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>We regret to inform you that your scheduled property viewing has been cancelled.</p>';
                if ($cancellation_reason) {
                    $bodyHtml .= '<p><strong>Reason:</strong> ' . htmlspecialchars($cancellation_reason, ENT_QUOTES, 'UTF-8') . '</p>';
                }
                $bodyHtml .= '<p><strong>Viewing Details:</strong><br>' . nl2br(htmlspecialchars("Date & Time: {$preferred_at}\n{$location_details}", ENT_QUOTES, 'UTF-8')) . '</p>';
                $bodyHtml .= '<p>If you have any questions, please contact us.</p>';
                $mail->Body = buildNovoPuertaEmailHtml(
                    'Your Viewing Has Been Cancelled',
                    $bodyHtml,
                    ['intro' => 'Your scheduled property viewing has been cancelled.', 'footer_note' => 'Please keep this email for your records.']
                );
                $mail->AltBody = buildNovoPuertaEmailAltBody(
                    "Dear {$client_name},\n\nYour scheduled property viewing has been cancelled." . ($cancellation_reason ? "\n\nReason: {$cancellation_reason}" : '') . "\n\nViewing Details:\nDate & Time: {$preferred_at}\n{$location_details}\n\nIf you have any questions, please contact us."
                );
                $mail->send();
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                // Log or handle email errors
                error_log('PHPMailer error: ' . $mail->ErrorInfo);
                // Optionally, return error in response (for debugging, remove in production)
                echo json_encode(['success'=>false,'message'=>'Viewing cancelled, but email failed to send.','error'=>$mail->ErrorInfo]);
                exit;
            }
        }
        echo json_encode(['success'=>true,'message'=>'Viewing cancelled.']);
    } else {
    // either already cancelled/done or update failed
    echo json_encode(['success'=>false,'message'=>'Unable to cancel (maybe already cancelled/done).']);
}
?>