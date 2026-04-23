<?php
// cron_payment_reminders.php
// This script is meant to be run once a day via a Cron Job.

date_default_timezone_set('Asia/Manila');

// 1. Database connection settings (Update these for your Hostinger DB)
$servername = "localhost";
$username   = "root"; // e.g., u981351059_user
$password   = "";     // e.g., JssOguF:#*9f
$dbname     = "nuevopuerta"; // e.g., u981351059_nuevopuerta

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// 2. Mailer Functions (Copied from your system to ensure it works identically)
function getSystemMailConfig(): array {
    return [
        'host'       => getenv('SYSTEM_SMTP_HOST') ?: (getenv('SMTP_HOST') ?: 'smtp.gmail.com'),
        'user'       => getenv('SYSTEM_SMTP_USER') ?: (getenv('SMTP_USER') ?: 'carlomallari01471@gmail.com'),
        'pass'       => getenv('SYSTEM_SMTP_PASS') ?: (getenv('SMTP_PASS') ?: 'rsmv pipf ijxf phha'),
        'port'       => (int)(getenv('SYSTEM_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 587),
        'secure'     => getenv('SYSTEM_SMTP_SECURE') ?: getenv('SMTP_SECURE') ?: 'tls',
        'from_email' => getenv('SYSTEM_SMTP_FROM_EMAIL') ?: 'carlomallari01471@gmail.com',
        'from_name'  => getenv('SYSTEM_SMTP_FROM_NAME') ?: 'Nuevo Puerta Real Estate',
    ];
}

function ensureSystemMailerLoaded(): bool {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    $candidatePaths = [
        __DIR__ . '/vendor/phpmailer/src/PHPMailer.php',
        __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'
    ];
    $basePath = null;
    foreach ($candidatePaths as $path) {
        if (file_exists($path)) { $basePath = dirname($path); break; }
    }
    if ($basePath === null) return false;

    require_once $basePath . '/PHPMailer.php';
    require_once $basePath . '/SMTP.php';
    require_once $basePath . '/Exception.php';
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

function sendSystemEmail($to, $toName, $subject, $body) {
    $cfg = getSystemMailConfig();
    if (!ensureSystemMailerLoaded()) return false;

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['pass'];
        $mail->Port       = $cfg['port'];
        $mail->SMTPSecure = $cfg['secure'];

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Simple HTML Wrapper
        $htmlBody = '<div style="font-family:Arial,sans-serif; padding:20px; background:#f6f8fb; color:#333;">';
        $htmlBody .= '<div style="background:#fff; padding:20px; border-radius:8px;">' . nl2br(htmlspecialchars($body)) . '</div>';
        $htmlBody .= '</div>';
        
        $mail->Body = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function insertUserNotification($conn, $userId, $title, $message, $type = 'warning') {
    $titleEsc = mysqli_real_escape_string($conn, $title);
    $msgEsc   = mysqli_real_escape_string($conn, $message);
    $typeEsc  = mysqli_real_escape_string($conn, $type);
    
    $sql = "INSERT INTO user_notifications (user_id, title, message, type, is_read, created_at) 
            VALUES ($userId, '$titleEsc', '$msgEsc', '$typeEsc', 0, NOW())";
    mysqli_query($conn, $sql);
}

// 3. Logic to Check Due Payments
$today = new DateTime('today');
$currentMonth = $today->format('m');
$currentYear = $today->format('Y');

// Get all lots currently on installment
$sql = "SELECT l.id AS lot_id, l.block_number, l.lot_number, l.payment_amount, l.payment_due_day, 
               u.id AS user_id, u.first_name, u.last_name, u.email
        FROM lots l
        JOIN user_accounts u ON l.owner_id = u.id
        WHERE l.status = 'Installment' AND l.payment_amount > 0 AND l.payment_due_day > 0";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($lot = $result->fetch_assoc()) {
        $lotId       = (int)$lot['lot_id'];
        $userId      = (int)$lot['user_id'];
        $dueDay      = (int)$lot['payment_due_day'];
        $amountDue   = (float)$lot['payment_amount'];
        $clientName  = trim($lot['first_name'] . ' ' . $lot['last_name']);
        $clientEmail = $lot['email'];
        $lotLabel    = "Block {$lot['block_number']}, Lot {$lot['lot_number']}";

        // Figure out the exact due date for THIS month
        $daysInMonth = (int)date('t', strtotime("$currentYear-$currentMonth-01"));
        $safeDueDay  = min($dueDay, $daysInMonth);
        $dueDate     = new DateTime("$currentYear-$currentMonth-" . sprintf('%02d', $safeDueDay));

        // How many days away is the due date?
        // %R%a gives us e.g. "+3" (3 days in future) or "-1" (1 day in past)
        $interval = $today->diff($dueDate);
        $daysDiff = (int)$interval->format('%R%a'); 

        // We only want to notify them if it is exactly 3 days before, OR exactly 1 day overdue.
        if ($daysDiff === 3 || $daysDiff === -1) {
            
            // Check if they have ALREADY paid this month so we don't annoy them
            $paidQuery = "SELECT SUM(amount) AS paid_this_month 
                          FROM lot_payment_transactions 
                          WHERE lot_id = $lotId 
                          AND MONTH(payment_date) = $currentMonth 
                          AND YEAR(payment_date) = $currentYear";
            $paidRes = $conn->query($paidQuery);
            $paidRow = $paidRes->fetch_assoc();
            $paidThisMonth = (float)($paidRow['paid_this_month'] ?? 0);

            // If they haven't paid the full monthly amount yet
            if ($paidThisMonth < $amountDue) {
                $balanceNeeded = $amountDue - $paidThisMonth;
                $formattedAmount = "PHP " . number_format($balanceNeeded, 2);
                $formattedDate = $dueDate->format('F j, Y');

                if ($daysDiff === 3) {
                    $subject = "Upcoming Payment Reminder: {$lotLabel}";
                    $message = "Hello {$clientName},\n\nThis is a friendly reminder that your upcoming installment payment of {$formattedAmount} for {$lotLabel} is due on {$formattedDate}.\n\nPlease ensure payment is made at the Main Office to avoid any penalties.\n\nThank you,\nNuevo Puerta Real Estate";
                    $notifType = "info";
                } else { // $daysDiff === -1
                    $subject = "OVERDUE Notice: {$lotLabel}";
                    $message = "Hello {$clientName},\n\nYour installment payment of {$formattedAmount} for {$lotLabel} was due yesterday, {$formattedDate}.\n\nPlease settle your account at the Main Office immediately to keep your account in good standing.\n\nThank you,\nNuevo Puerta Real Estate";
                    $notifType = "error";
                }

                // 1. Send Email
                if (!empty($clientEmail)) {
                    sendSystemEmail($clientEmail, $clientName, $subject, $message);
                }

                // 2. Send Dashboard Notification
                insertUserNotification($conn, $userId, $subject, $message, $notifType);
                
                echo "Sent reminder to {$clientEmail} for Lot {$lotId}<br>";
            }
        }
    }
} else {
    echo "No active installments found.";
}

echo "Cron job completed successfully.";
?>