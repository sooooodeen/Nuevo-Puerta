<?php
require_once __DIR__ . '/dbconn.php';

date_default_timezone_set('Asia/Manila');

if (file_exists(__DIR__ . '/vendor/phpmailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
} else {
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
}

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

function shouldUseSmtp(): bool {
    return (bool)(
        getenv('SMTP_HOST') &&
        getenv('SMTP_USER') &&
        getenv('SMTP_PASS') &&
        getenv('SMTP_FROM_EMAIL')
    );
}

function sendEmail(string $to, string $toName, string $subject, string $body, ?string &$error = null): bool {
    $error = null;

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid recipient email.';
        return false;
    }

    if (shouldUseSmtp()) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = (string)getenv('SMTP_USER');
            $mail->Password = (string)getenv('SMTP_PASS');
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->SMTPSecure = (string)(getenv('SMTP_SECURE') ?: 'tls');

            $fromEmail = (string)getenv('SMTP_FROM_EMAIL');
            $fromName = (string)(getenv('SMTP_FROM_NAME') ?: 'Nuevo Puerta');

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to, $toName);
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (Exception $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    $headers = "From: Nuevo Puerta <no-reply@nuevopuerta.local>\r\n";
    $headers .= "Reply-To: no-reply@nuevopuerta.local\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $ok = mail($to, $subject, $body, $headers);
    if (!$ok) {
        $error = 'mail() failed. Configure SMTP_* environment variables for reliable delivery.';
    }
    return $ok;
}

function ensurePaymentReminderLogTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS payment_reminder_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lot_id INT NOT NULL,
        user_id INT NOT NULL,
        reminder_for_date DATE NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivery_status VARCHAR(20) NOT NULL DEFAULT 'sent',
        error_message TEXT NULL,
        UNIQUE KEY uniq_lot_date (lot_id, reminder_for_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sendViewingReminders(mysqli $conn): array {
    $sent = 0;
    $failed = 0;

    if (!tableExists($conn, 'leads')) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $tomorrowEnd = date('Y-m-d 23:59:59', strtotime('+1 day'));

    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, agent_id, scheduled_at, meeting_location
                            FROM leads
                            WHERE scheduled_at BETWEEN ? AND ?
                              AND status = 'scheduled'");
    if (!$stmt) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $stmt->bind_param('ss', $tomorrowStart, $tomorrowEnd);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($lead = $res->fetch_assoc()) {
        $clientName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        if ($clientName === '') {
            $clientName = 'Client';
        }

        $subject = 'Viewing Reminder';
        $body = "Hello {$clientName},\n\n"
              . "This is a reminder for your viewing scheduled on {$lead['scheduled_at']} at {$lead['meeting_location']}.\n\n"
              . "Thank you,\nNuevo Puerta";

        $emailError = null;
        if (sendEmail((string)($lead['email'] ?? ''), $clientName, $subject, $body, $emailError)) {
            $sent++;
        } else {
            $failed++;
        }

        $agentId = (int)($lead['agent_id'] ?? 0);
        if ($agentId > 0 && tableExists($conn, 'agent_accounts')) {
            $agentStmt = $conn->prepare("SELECT email, first_name, last_name FROM agent_accounts WHERE id = ? LIMIT 1");
            if ($agentStmt) {
                $agentStmt->bind_param('i', $agentId);
                $agentStmt->execute();
                $agentRow = $agentStmt->get_result()->fetch_assoc();
                $agentStmt->close();

                if ($agentRow) {
                    $agentEmail = (string)($agentRow['email'] ?? '');
                    $agentName = trim(($agentRow['first_name'] ?? '') . ' ' . ($agentRow['last_name'] ?? '')) ?: 'Agent';
                    $agentBody = "Hello {$agentName},\n\n"
                               . "You have a viewing with {$clientName} on {$lead['scheduled_at']} at {$lead['meeting_location']}.\n\n"
                               . "Thanks,\nNuevo Puerta";
                    if (sendEmail($agentEmail, $agentName, 'Viewing Reminder', $agentBody, $emailError)) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                }
            }
        }
    }

    $stmt->close();
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => 0];
}

function sendPaymentDeadlineReminders(mysqli $conn): array {
    $sent = 0;
    $failed = 0;
    $skipped = 0;

    if (!tableExists($conn, 'lots') || !tableExists($conn, 'user_accounts')) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    ensurePaymentReminderLogTable($conn);
    $today = date('Y-m-d');

    $stmt = $conn->prepare("SELECT
            l.id AS lot_id,
            l.block_number,
            l.lot_number,
            l.payment_deadline,
            u.id AS user_id,
            u.email,
            u.first_name,
            u.last_name
        FROM lots l
        INNER JOIN user_accounts u ON u.id = l.owner_id
        WHERE l.owner_id IS NOT NULL
          AND l.payment_type = 'Down Payment'
          AND l.payment_deadline IS NOT NULL
          AND DATE(l.payment_deadline) BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");

    if (!$stmt) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $insertLog = $conn->prepare("INSERT IGNORE INTO payment_reminder_logs (lot_id, user_id, reminder_for_date, delivery_status) VALUES (?, ?, ?, 'pending')");
    $updateLog = $conn->prepare("UPDATE payment_reminder_logs SET delivery_status = ?, error_message = ?, sent_at = NOW() WHERE lot_id = ? AND reminder_for_date = ?");

    if (!$insertLog || !$updateLog) {
        $stmt->close();
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    }

    while ($row = $res->fetch_assoc()) {
        $lotId = (int)$row['lot_id'];
        $userId = (int)$row['user_id'];

        $insertLog->bind_param('iis', $lotId, $userId, $today);
        $insertLog->execute();

        if ($insertLog->affected_rows === 0) {
            $skipped++;
            continue;
        }

        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Client';
        $deadline = (string)$row['payment_deadline'];
        $daysDiff = (int)floor((strtotime($deadline) - strtotime($today)) / 86400);

        if ($daysDiff < 0) {
            $timingText = 'This payment deadline is now overdue.';
        } elseif ($daysDiff === 0) {
            $timingText = 'This payment deadline is due today.';
        } elseif ($daysDiff === 1) {
            $timingText = 'This payment deadline is due tomorrow.';
        } else {
            $timingText = "This payment deadline is due in {$daysDiff} days.";
        }

        $subject = 'Payment Deadline Reminder';
        $body = "Hello {$fullName},\n\n"
              . "Reminder for your Down Payment lot:\n"
              . "Block {$row['block_number']} Lot {$row['lot_number']}\n"
              . "Deadline: {$deadline}\n\n"
              . "{$timingText}\n\n"
              . "Please contact Nuevo Puerta support if you need assistance.\n\n"
              . "Thank you,\nNuevo Puerta";

        $emailError = null;
        $deliveryStatus = 'sent';
        $errorMessage = null;

        if (sendEmail((string)$row['email'], $fullName, $subject, $body, $emailError)) {
            $sent++;
        } else {
            $failed++;
            $deliveryStatus = 'failed';
            $errorMessage = $emailError;
        }

        $updateLog->bind_param('ssis', $deliveryStatus, $errorMessage, $lotId, $today);
        $updateLog->execute();
    }

    $insertLog->close();
    $updateLog->close();
    $stmt->close();

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

$viewing = sendViewingReminders($conn);
$payment = sendPaymentDeadlineReminders($conn);

$summary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'viewing' => $viewing,
    'payment' => $payment,
];

if (PHP_SAPI === 'cli') {
    echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary);
}

$conn->close();