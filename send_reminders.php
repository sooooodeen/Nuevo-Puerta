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

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
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

function resolveEmailLogoPath(): ?string {
    $candidates = [
        __DIR__ . '/assets/f.png',
        __DIR__ . '/assets/logo.png',
        __DIR__ . '/logo.png',
        __DIR__ . '/Login/img/Logo.png',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function buildBrandedEmailHtml(string $messageText, ?string $logoSrc = null): string {
    $safeMessage = nl2br(htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8'));
    $year = date('Y');
    $logoHtml = '';

    if ($logoSrc !== null && $logoSrc !== '') {
        $safeLogoSrc = htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8');
        $logoHtml = "<div style=\"margin-bottom:16px;\"><div style=\"display:inline-block;background:#1f3b2d;border-radius:14px;padding:10px 12px;\"><img src=\"{$safeLogoSrc}\" alt=\"Nuevo Puerta Logo\" style=\"display:block;max-width:180px;height:auto;\"></div></div>";
    }

    return '<!DOCTYPE html>'
        . '<html><body style="margin:0;padding:0;background-color:#f6f8fb;font-family:Arial,sans-serif;color:#1f2937;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f8fb;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">'
        . '<tr><td style="padding:22px 24px 14px 24px;">'
        . $logoHtml
        . '<div style="font-size:15px;line-height:1.65;">' . $safeMessage . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px 20px 24px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.5;">'
        . '&copy; ' . $year . ' Nuevo Puerta. All rights reserved.'
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

function sendEmail(string $to, string $toName, string $subject, string $body, ?string &$error = null): bool {
    $error = null;
    $logoPath = resolveEmailLogoPath();

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
            $logoSrc = null;
            if ($logoPath !== null) {
                $mail->addEmbeddedImage($logoPath, 'nuevo_puerta_logo', basename($logoPath));
                $logoSrc = 'cid:nuevo_puerta_logo';
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = buildBrandedEmailHtml($body, $logoSrc);
            $mail->AltBody = $body . "\n\nCopyright (c) " . date('Y') . " Nuevo Puerta. All rights reserved.";
            $mail->send();
            return true;
        } catch (Exception $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    $headers = "From: Nuevo Puerta <no-reply@nuevopuerta.local>\r\n";
    $headers .= "Reply-To: no-reply@nuevopuerta.local\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $inlineLogoSrc = null;
    if ($logoPath !== null) {
        $logoData = @file_get_contents($logoPath);
        if ($logoData !== false) {
            $ext = strtolower((string)pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'application/octet-stream');
            $inlineLogoSrc = 'data:' . $mime . ';base64,' . base64_encode($logoData);
        }
    }

    $htmlBody = buildBrandedEmailHtml($body, $inlineLogoSrc);

    $ok = mail($to, $subject, $htmlBody, $headers);
    if (!$ok) {
        $error = 'mail() failed. Configure SMTP_* environment variables for reliable delivery.';
    }
    return $ok;
}

function getReminderLeadDays(): int {
    $leadDays = (int)(getenv('PAYMENT_REMINDER_LEAD_DAYS') ?: 1);
    return max(1, min($leadDays, 7));
}

function getAdminSummaryRecipient(): string {
    $recipient = trim((string)(getenv('ADMIN_EMAIL') ?: ''));
    if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return $recipient;
    }

    $fallback = trim((string)(getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USER') ?: ''));
    return filter_var($fallback, FILTER_VALIDATE_EMAIL) ? $fallback : '';
}

function ensurePaymentReminderLogTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS payment_reminder_dispatches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lot_id INT NOT NULL,
        user_id INT NOT NULL,
        agent_id INT NULL,
        recipient_type VARCHAR(20) NOT NULL,
        notice_type VARCHAR(40) NOT NULL,
        due_date DATE NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivery_status VARCHAR(20) NOT NULL DEFAULT 'sent',
        error_message TEXT NULL,
        UNIQUE KEY uniq_dispatch (lot_id, recipient_type, notice_type, due_date),
        INDEX idx_dispatch_user (user_id, due_date),
        INDEX idx_dispatch_agent (agent_id, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function normalizeLotStatus(string $status): string {
    $status = trim($status);
    if ($status === 'Installments') return 'Installment';
    if ($status === 'Sold') return 'Paid';
    if ($status === '') return 'Available';
    return $status;
}

function computeDueDateForMonth(DateTime $baseDate, int $dueDay): DateTime {
    $year = (int)$baseDate->format('Y');
    $month = (int)$baseDate->format('m');
    $lastDay = (int)$baseDate->format('t');
    $safeDueDay = max(1, min($dueDay, $lastDay));
    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $safeDueDay));
}

function reserveReminderDispatch(mysqli $conn, int $lotId, int $userId, ?int $agentId, string $recipientType, string $noticeType, string $dueDate): bool {
    $stmt = $conn->prepare("INSERT IGNORE INTO payment_reminder_dispatches (lot_id, user_id, agent_id, recipient_type, notice_type, due_date, delivery_status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    if (!$stmt) return false;
    $agentIdNullable = $agentId ?: null;
    $stmt->bind_param('iiisss', $lotId, $userId, $agentIdNullable, $recipientType, $noticeType, $dueDate);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function finalizeReminderDispatch(mysqli $conn, int $lotId, string $recipientType, string $noticeType, string $dueDate, string $status, ?string $errorMessage): void {
    $stmt = $conn->prepare("UPDATE payment_reminder_dispatches
                           SET delivery_status = ?, error_message = ?, sent_at = NOW()
                           WHERE lot_id = ? AND recipient_type = ? AND notice_type = ? AND due_date = ?");
    if (!$stmt) return;
    $stmt->bind_param('ssisss', $status, $errorMessage, $lotId, $recipientType, $noticeType, $dueDate);
    $stmt->execute();
    $stmt->close();
}

function resolveAgentForLot(mysqli $conn, int $lotId, string $lotNoText, int $lotAgentId, int $userAgentId): int {
    if ($lotAgentId > 0) return $lotAgentId;
    if ($userAgentId > 0) return $userAgentId;

    if (tableExists($conn, 'viewings') && columnExists($conn, 'viewings', 'agent_id') && columnExists($conn, 'viewings', 'lot_id')) {
        $stmt = $conn->prepare("SELECT agent_id FROM viewings WHERE lot_id = ? AND agent_id > 0 ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $lotId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int)($row['agent_id'] ?? 0) > 0) {
                return (int)$row['agent_id'];
            }
        }
    }

    if ($lotNoText !== '' && tableExists($conn, 'viewings') && columnExists($conn, 'viewings', 'agent_id') && columnExists($conn, 'viewings', 'lot_no')) {
        $stmt = $conn->prepare("SELECT agent_id FROM viewings WHERE lot_no = ? AND agent_id > 0 ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $lotNoText);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && (int)($row['agent_id'] ?? 0) > 0) {
                return (int)$row['agent_id'];
            }
        }
    }

    return 0;
}

function hasPaymentBeforeOrOnDue(mysqli $conn, int $lotId, DateTime $dueDate, bool $monthlyCycle): bool {
    $dueDateText = $dueDate->format('Y-m-d');
    if ($monthlyCycle) {
        $monthStart = new DateTime($dueDate->format('Y-m-01'));
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM lot_payment_transactions WHERE lot_id = ? AND DATE(payment_date) BETWEEN ? AND ?");
        if (!$stmt) return false;
        $monthStartText = $monthStart->format('Y-m-d');
        $stmt->bind_param('iss', $lotId, $monthStartText, $dueDateText);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM lot_payment_transactions WHERE lot_id = ? AND DATE(payment_date) <= ?");
        if (!$stmt) return false;
        $stmt->bind_param('is', $lotId, $dueDateText);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['c'] ?? 0)) > 0;
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
    $agentNotified = 0;
    $adminOverdueRows = [];
    $leadDays = getReminderLeadDays();

    if (!tableExists($conn, 'lots') || !tableExists($conn, 'user_accounts') || !tableExists($conn, 'lot_payment_transactions')) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'agent_notified' => 0, 'overdue_rows' => []];
    }

    ensurePaymentReminderLogTable($conn);
    $today = new DateTime(date('Y-m-d'));
    $todayText = $today->format('Y-m-d');

    $hasUserAgent = columnExists($conn, 'user_accounts', 'agent_id');
    $hasLotAgent = columnExists($conn, 'lots', 'agent_id');
    $userAgentSelect = $hasUserAgent ? ', IFNULL(u.agent_id, 0) AS user_agent_id' : ', 0 AS user_agent_id';
    $lotAgentSelect = $hasLotAgent ? ', IFNULL(l.agent_id, 0) AS lot_agent_id' : ', 0 AS lot_agent_id';

    $stmt = $conn->prepare("SELECT
            l.id AS lot_id,
            l.block_number,
            l.lot_number,
            l.payment_deadline,
            l.payment_due_day,
            l.payment_type,
            l.status,
            l.lot_price,
            u.id AS user_id,
            u.email,
            u.first_name,
            u.last_name
            $userAgentSelect
            $lotAgentSelect
        FROM lots l
        INNER JOIN user_accounts u ON u.id = l.owner_id
        WHERE l.owner_id IS NOT NULL
          AND u.email IS NOT NULL
          AND TRIM(u.email) <> ''");

    if (!$stmt) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'agent_notified' => 0, 'overdue_rows' => []];
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $lotId = (int)$row['lot_id'];
        $userId = (int)$row['user_id'];

        $lotStatus = normalizeLotStatus((string)($row['status'] ?? ''));
        $paymentType = trim((string)($row['payment_type'] ?? ''));
        $lotPrice = (float)($row['lot_price'] ?? 0);

        if ($lotStatus === 'Paid' || $paymentType === 'Fully Paid') {
            continue;
        }

        $dueDate = null;
        $monthlyCycle = false;
        $deadlineRaw = trim((string)($row['payment_deadline'] ?? ''));
        $dueDay = (int)($row['payment_due_day'] ?? 0);

        if ($dueDay > 0 && ($lotStatus === 'Installment' || $paymentType === 'Down Payment')) {
            $dueDate = computeDueDateForMonth($today, $dueDay);
            $monthlyCycle = true;
        } elseif ($deadlineRaw !== '') {
            $candidate = DateTime::createFromFormat('Y-m-d', substr($deadlineRaw, 0, 10));
            if ($candidate instanceof DateTime) {
                $dueDate = $candidate;
            }
        }

        if (!$dueDate) {
            $skipped++;
            continue;
        }

        $dueDateText = $dueDate->format('Y-m-d');
        $diffDays = (int)$today->diff($dueDate)->format('%r%a');
        $daysRemaining = max(0, $diffDays);
        $paidByDue = hasPaymentBeforeOrOnDue($conn, $lotId, $dueDate, $monthlyCycle);

        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Client';

        // Notify client ahead of the due date.
        if ($diffDays > 0 && $diffDays <= $leadDays && reserveReminderDispatch($conn, $lotId, $userId, null, 'client', 'client_upcoming', $dueDateText)) {
            $subject = $diffDays === 1 ? 'Payment Due Tomorrow' : 'Upcoming Payment Reminder';
            $duePhrase = $diffDays === 1 ? 'tomorrow' : "in {$daysRemaining} day(s)";
            $body = "Hello {$fullName},\n\n"
                . "This is a reminder that your payment is due {$duePhrase} for:\n"
                  . "Block {$row['block_number']} Lot {$row['lot_number']}\n"
                  . "Due Date: {$dueDateText}\n\n"
                  . "Please prepare your payment before the deadline.\n\n"
                  . "Thank you,\nNuevo Puerta";

            $emailError = null;
            if (sendEmail((string)$row['email'], $fullName, $subject, $body, $emailError)) {
                $sent++;
                finalizeReminderDispatch($conn, $lotId, 'client', 'client_upcoming', $dueDateText, 'sent', null);
            } else {
                $failed++;
                finalizeReminderDispatch($conn, $lotId, 'client', 'client_upcoming', $dueDateText, 'failed', $emailError);
            }
        }

        // Notify client on due date.
        if ($diffDays === 0 && reserveReminderDispatch($conn, $lotId, $userId, null, 'client', 'client_due', $dueDateText)) {
            $subject = 'Payment Due Today';
            $body = "Hello {$fullName},\n\n"
                  . "Your payment is due today for:\n"
                  . "Block {$row['block_number']} Lot {$row['lot_number']}\n"
                  . "Due Date: {$dueDateText}\n\n"
                  . "Please settle your payment to avoid overdue status.\n\n"
                  . "Thank you,\nNuevo Puerta";

            $emailError = null;
            if (sendEmail((string)$row['email'], $fullName, $subject, $body, $emailError)) {
                $sent++;
                finalizeReminderDispatch($conn, $lotId, 'client', 'client_due', $dueDateText, 'sent', null);
            } else {
                $failed++;
                finalizeReminderDispatch($conn, $lotId, 'client', 'client_due', $dueDateText, 'failed', $emailError);
            }
        }

        // Overdue notifications if unpaid by due date.
        if ($diffDays < 0 && !$paidByDue) {
            $adminOverdueRows[] = [
                'client_name' => $fullName,
                'client_email' => (string)$row['email'],
                'lot_label' => 'Block ' . (string)$row['block_number'] . ' Lot ' . (string)$row['lot_number'],
                'due_date' => $dueDateText,
                'days_overdue' => abs($diffDays),
                'amount_due' => $monthlyCycle ? resolveDueDateAmount($row, $lotPrice) : $lotPrice,
            ];

            if (reserveReminderDispatch($conn, $lotId, $userId, null, 'client', 'client_overdue', $dueDateText)) {
                $subject = 'Payment Overdue Notice';
                $body = "Hello {$fullName},\n\n"
                      . "Our records show your payment is overdue for:\n"
                      . "Block {$row['block_number']} Lot {$row['lot_number']}\n"
                      . "Due Date: {$dueDateText}\n\n"
                      . "Please settle your payment as soon as possible.\n\n"
                      . "Thank you,\nNuevo Puerta";

                $emailError = null;
                if (sendEmail((string)$row['email'], $fullName, $subject, $body, $emailError)) {
                    $sent++;
                    finalizeReminderDispatch($conn, $lotId, 'client', 'client_overdue', $dueDateText, 'sent', null);
                } else {
                    $failed++;
                    finalizeReminderDispatch($conn, $lotId, 'client', 'client_overdue', $dueDateText, 'failed', $emailError);
                }
            }

            $lotNoText = trim((string)($row['lot_number'] ?? ''));
            $resolvedAgentId = resolveAgentForLot(
                $conn,
                $lotId,
                $lotNoText,
                (int)($row['lot_agent_id'] ?? 0),
                (int)($row['user_agent_id'] ?? 0)
            );

            if ($resolvedAgentId > 0 && tableExists($conn, 'agent_accounts')) {
                $agentStmt = $conn->prepare("SELECT first_name, last_name, email FROM agent_accounts WHERE id = ? LIMIT 1");
                if ($agentStmt) {
                    $agentStmt->bind_param('i', $resolvedAgentId);
                    $agentStmt->execute();
                    $agentRow = $agentStmt->get_result()->fetch_assoc();
                    $agentStmt->close();

                    $agentEmail = trim((string)($agentRow['email'] ?? ''));
                    if ($agentEmail !== '' && filter_var($agentEmail, FILTER_VALIDATE_EMAIL) && reserveReminderDispatch($conn, $lotId, $userId, $resolvedAgentId, 'agent', 'agent_overdue', $dueDateText)) {
                        $agentName = trim((string)($agentRow['first_name'] ?? '') . ' ' . (string)($agentRow['last_name'] ?? '')) ?: 'Agent';
                        $subject = 'Client Payment Missed Due Date';
                        $body = "Hello {$agentName},\n\n"
                              . "A client assigned to you missed the due date payment:\n"
                              . "Client: {$fullName}\n"
                              . "Lot: Block {$row['block_number']} Lot {$row['lot_number']}\n"
                              . "Due Date: {$dueDateText}\n\n"
                              . "Please follow up and encourage the client to settle payment.\n\n"
                              . "Thank you,\nNuevo Puerta";

                        $emailError = null;
                        if (sendEmail($agentEmail, $agentName, $subject, $body, $emailError)) {
                            $sent++;
                            $agentNotified++;
                            finalizeReminderDispatch($conn, $lotId, 'agent', 'agent_overdue', $dueDateText, 'sent', null);
                        } else {
                            $failed++;
                            finalizeReminderDispatch($conn, $lotId, 'agent', 'agent_overdue', $dueDateText, 'failed', $emailError);
                        }

                        if (tableExists($conn, 'agent_notifications')) {
                            $noteStmt = $conn->prepare("INSERT INTO agent_notifications (agent_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'warning', 0, NOW())");
                            if ($noteStmt) {
                                $title = 'Client payment overdue';
                                $message = "{$fullName} missed payment due date ({$dueDateText}) for Block {$row['block_number']} Lot {$row['lot_number']}.";
                                $noteStmt->bind_param('iss', $resolvedAgentId, $title, $message);
                                $noteStmt->execute();
                                $noteStmt->close();
                            }
                        }
                    }
                }
            }
        }
    }

    $stmt->close();

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'agent_notified' => $agentNotified, 'overdue_rows' => $adminOverdueRows];
}

function resolveDueDateAmount(array $row, float $lotPrice): float {
    $paymentType = trim((string)($row['payment_type'] ?? ''));
    $currentAmount = (float)($row['payment_amount'] ?? 0);
    if ($paymentType === 'Down Payment' && $currentAmount > 0) {
        return $currentAmount;
    }
    return $lotPrice > 0 ? $lotPrice : $currentAmount;
}

function sendAdminOverdueSummary(mysqli $conn, array $overdueRows): array {
    if (empty($overdueRows)) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
    }

    $recipient = getAdminSummaryRecipient();
    if ($recipient === '') {
        return ['sent' => 0, 'failed' => 0, 'skipped' => count($overdueRows)];
    }

    $subject = 'Daily Overdue Payment Summary';
    $lines = [
        'Hello Admin,',
        '',
        'Here is the daily summary of overdue client payments:',
        ''
    ];

    foreach ($overdueRows as $index => $row) {
        $lines[] = sprintf(
            "%d. %s | %s | Due: %s | Overdue: %s day(s) | Amount: PHP %s",
            $index + 1,
            $row['client_name'] ?? 'Client',
            $row['lot_label'] ?? 'Lot',
            $row['due_date'] ?? '-',
            (string)($row['days_overdue'] ?? 0),
            number_format((float)($row['amount_due'] ?? 0), 2)
        );
    }

    $lines[] = '';
    $lines[] = 'Please review these clients and coordinate follow-up with the assigned agents.';
    $lines[] = '';
    $lines[] = 'Thank you,';
    $lines[] = 'Nuevo Puerta';

    $body = implode("\n", $lines);
    $error = null;
    $adminName = 'Admin';
    $sent = sendEmail($recipient, $adminName, $subject, $body, $error);

    return [
        'sent' => $sent ? 1 : 0,
        'failed' => $sent ? 0 : 1,
        'skipped' => 0
    ];
}

$viewing = sendViewingReminders($conn);
$payment = sendPaymentDeadlineReminders($conn);
$adminSummary = sendAdminOverdueSummary($conn, $payment['overdue_rows'] ?? []);

$summary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'viewing' => $viewing,
    'payment' => $payment,
    'admin_summary' => $adminSummary,
];

if (PHP_SAPI === 'cli') {
    echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary);
}

$conn->close();