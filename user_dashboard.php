<?php
// filepath: user_dashboard.php
session_start();

require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/includes/email_branding.php';

// 1. LOGIN CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: Login/login.php");
    exit;
}

/* ---------------- DB CONNECTION ---------------- */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ---------------- HELPER FUNCTIONS ---------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function resolveSystemEmailLogoPath(): ?string {
    $candidates = [
        __DIR__ . '/assets/f.png',
        __DIR__ . '/assets/logo.png',
        __DIR__ . '/logo.png',
        __DIR__ . '/Login/img/Logo.png',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function buildSystemEmailHtml(string $bodyText, ?string $logoSrc = null): string {
    return buildNovoPuertaEmailHtml(
        'Nuevo Puerta Update',
        nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')),
        [
            'intro' => 'This is an automated message from Nuevo Puerta Real Estate.',
            'footer_note' => 'Please keep this email for your records.',
        ]
    );
}

function sendSystemEmailSimple(string $toEmail, string $toName, string $subject, string $body, ?string &$errorInfo = null): bool {
    $systemSmtpUser = trim((string)(getenv('SYSTEM_SMTP_USER') ?: ''));
    $systemSmtpPass = trim((string)(getenv('SYSTEM_SMTP_PASS') ?: ''));
    $legacySmtpHost = trim((string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com'));
    $legacySmtpUser = trim((string)(getenv('SMTP_USER') ?: 'carlomallari01471@gmail.com'));
    $legacySmtpPass = trim((string)(getenv('SMTP_PASS') ?: 'rsmv pipf ijxf phha'));

    $useSystemCredentials = ($systemSmtpUser !== '' && $systemSmtpPass !== '');
    $smtpHost = trim((string)(getenv('SYSTEM_SMTP_HOST') ?: $legacySmtpHost));
    $smtpUser = $useSystemCredentials ? $systemSmtpUser : $legacySmtpUser;
    $smtpPass = $useSystemCredentials ? $systemSmtpPass : $legacySmtpPass;
    $smtpPort = (int)(getenv('SYSTEM_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 587);
    $smtpSecure = trim((string)(getenv('SYSTEM_SMTP_SECURE') ?: getenv('SMTP_SECURE') ?: 'tls'));
    $fromEmail = trim((string)(getenv('SYSTEM_SMTP_FROM_EMAIL') ?: $smtpUser));
    $fromName = trim((string)(getenv('SYSTEM_SMTP_FROM_NAME') ?: 'Nuevo Puerta Real Estate'));
    $logoPath = resolveSystemEmailLogoPath();

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $errorInfo = 'Invalid recipient email.';
        return false;
    }

    $useSmtp = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '');

    if (!$useSmtp) {
        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'X-Mailer: PHP/' . phpversion();

        $inlineLogoSrc = null;
        if ($logoPath !== null) {
            $logoData = @file_get_contents($logoPath);
            if ($logoData !== false) {
                $ext = strtolower((string)pathinfo($logoPath, PATHINFO_EXTENSION));
                $mime = $ext === 'png' ? 'image/png' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'application/octet-stream');
                $inlineLogoSrc = 'data:' . $mime . ';base64,' . base64_encode($logoData);
            }
        }

        $htmlBody = buildSystemEmailHtml($body, $inlineLogoSrc);
        $ok = mail($toEmail, $subject, $htmlBody, $headers);
        if (!$ok) {
            $errorInfo = 'mail() failed. Configure SMTP_* environment variables for reliable delivery.';
        }
        return $ok;
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = $smtpSecure;
            $mail->Port = $smtpPort;

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
            $logoSrc = null;
            if ($logoPath !== null) {
                $mail->addEmbeddedImage($logoPath, 'nuevo_puerta_logo', basename($logoPath));
                $logoSrc = 'cid:nuevo_puerta_logo';
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = buildSystemEmailHtml($body, $logoSrc);
            $mail->AltBody = $body . "\n\nCopyright (c) " . date('Y') . " Nuevo Puerta Real Estate. All rights reserved.";
            $mail->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $errorInfo = $mail->ErrorInfo;
            error_log('User dashboard email send failed: ' . $mail->ErrorInfo);
        }
    }
    return false;
}

// Check if a column exists to prevent crashes
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeCol   = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}

function hasTable(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

function ensureAgentReviewsTable(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS agent_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        user_id INT NOT NULL,
        rating TINYINT NOT NULL,
        review_text TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_agent_user_review (agent_id, user_id),
        INDEX idx_agent_reviews_agent (agent_id, created_at),
        INDEX idx_agent_reviews_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return (bool)$conn->query($sql);
}

function formatLotLocationLabel(array $lot): string {
    $locationName = trim((string)($lot['location_name'] ?? $lot['barangay_name'] ?? $lot['barangay'] ?? ''));
    $blockNumber = trim((string)($lot['block_number'] ?? ''));
    $lotNumber = trim((string)($lot['lot_number'] ?? ''));
    $parts = [];

    if ($locationName !== '') {
        $parts[] = 'Barangay ' . $locationName;
    }
    if ($blockNumber !== '') {
        $parts[] = 'Block ' . $blockNumber;
    }
    if ($lotNumber !== '') {
        $parts[] = 'Lot ' . $lotNumber;
    }

    return !empty($parts) ? implode(', ', $parts) : 'N/A';
}

// Wrapper to prepare SQL and catch errors
function prepOrDie(mysqli $conn, string $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Database Error: " . $conn->error . "<br>SQL: $sql");
    }
    return $stmt;
}

function ensureLotStatusHistoryTable(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS lot_status_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lot_id INT NOT NULL,
        event_type VARCHAR(32) NOT NULL,
        event_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        previous_owner_id INT NULL,
        previous_owner_name VARCHAR(255) NULL,
        previous_owner_email VARCHAR(255) NULL,
        paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        company_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        remarks TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lot_status_history_lot (lot_id),
        INDEX idx_lot_status_history_event (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    return (bool)$conn->query($sql);
}

function fetchLotPaymentSummaries(mysqli $conn, array $lotIds): array {
    $summary = [];
    $lotIds = array_values(array_filter($lotIds, static fn($value) => is_numeric($value) && $value > 0));
    if (empty($lotIds)) {
        return $summary;
    }

    $idCsv = implode(',', array_map('intval', $lotIds));

    if (hasTable($conn, 'lot_payment_transactions')) {
        $sql = "SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid, MAX(payment_date) AS last_payment_date
                FROM lot_payment_transactions
                WHERE lot_id IN ($idCsv)
                GROUP BY lot_id";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $lotId = (int)($row['lot_id'] ?? 0);
                if ($lotId <= 0) {
                    continue;
                }
                $summary[$lotId]['total_paid'] = ($summary[$lotId]['total_paid'] ?? 0.0) + (float)($row['total_paid'] ?? 0);
                $lastDate = trim((string)($row['last_payment_date'] ?? ''));
                if ($lastDate !== '') {
                    $existing = trim((string)($summary[$lotId]['last_payment_date'] ?? ''));
                    if ($existing === '' || $lastDate > $existing) {
                        $summary[$lotId]['last_payment_date'] = $lastDate;
                    }
                }
            }
        }
    }

    if (hasTable($conn, 'payments')) {
        $sql = "SELECT lot_id, IFNULL(SUM(amount_paid), 0) AS total_paid, MAX(payment_date) AS last_payment_date
                FROM payments
                WHERE lot_id IN ($idCsv)
                GROUP BY lot_id";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $lotId = (int)($row['lot_id'] ?? 0);
                if ($lotId <= 0) {
                    continue;
                }
                $summary[$lotId]['total_paid'] = ($summary[$lotId]['total_paid'] ?? 0.0) + (float)($row['total_paid'] ?? 0);
                $lastDate = trim((string)($row['last_payment_date'] ?? ''));
                if ($lastDate !== '') {
                    $existing = trim((string)($summary[$lotId]['last_payment_date'] ?? ''));
                    if ($existing === '' || $lastDate > $existing) {
                        $summary[$lotId]['last_payment_date'] = $lastDate;
                    }
                }
            }
        }
    }

    return $summary;
}

function recordLotHistoryEvent(mysqli $conn, int $lotId, string $eventType, ?int $previousOwnerId, ?string $previousOwnerName, ?string $previousOwnerEmail, float $paidAmount, float $refundAmount, float $companyAmount, string $remarks): bool {
    if (!ensureLotStatusHistoryTable($conn)) {
        return false;
    }

    $previousOwnerName = $previousOwnerName ?? '';
    $previousOwnerEmail = $previousOwnerEmail ?? '';

    // Ensure is_read column exists
    $hasIsReadCol = $conn->query("SHOW COLUMNS FROM lot_status_history LIKE 'is_read'");
    if (!$hasIsReadCol || $hasIsReadCol->num_rows === 0) {
      $conn->query("ALTER TABLE lot_status_history ADD COLUMN is_read TINYINT(1) DEFAULT 0");
    }

    $stmt = prepOrDie($conn, "INSERT INTO lot_status_history (lot_id, event_type, event_date, previous_owner_id, previous_owner_name, previous_owner_email, paid_amount, refund_amount, company_amount, remarks, is_read) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->bind_param(
        'isissddds',
        $lotId,
        $eventType,
        $previousOwnerId,
        $previousOwnerName,
        $previousOwnerEmail,
        $paidAmount,
        $refundAmount,
        $companyAmount,
        $remarks
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getLotHistoryRows(mysqli $conn, int $lotId): array {
    $rows = [];
    if (!hasTable($conn, 'lot_status_history')) {
        return $rows;
    }

    $stmt = prepOrDie($conn, "SELECT event_type, event_date, previous_owner_id, previous_owner_name, previous_owner_email, paid_amount, refund_amount, company_amount, remarks, created_at FROM lot_status_history WHERE lot_id = ? ORDER BY event_date DESC, id DESC");
    $stmt->bind_param('i', $lotId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function autoForfeitOverdueLots(mysqli $conn): array {
    $overdue = [];
    if (!hasTable($conn, 'lots') || !hasColumn($conn, 'lots', 'owner_id')) {
        return $overdue;
    }

    $lotSql = "SELECT id, owner_id, lot_price, status, payment_type FROM lots WHERE owner_id IS NOT NULL";
    $result = $conn->query($lotSql);
    if (!$result) {
        return $overdue;
    }

    $candidates = [];
    while ($lot = $result->fetch_assoc()) {
        $lotId = (int)($lot['id'] ?? 0);
        if ($lotId <= 0) {
            continue;
        }
        $candidates[$lotId] = $lot;
    }
    $result->free();

    if (empty($candidates)) {
        return $overdue;
    }

    $summaries = fetchLotPaymentSummaries($conn, array_keys($candidates));
    $threshold = new DateTime('today');
    $threshold->modify('-90 days');

    foreach ($candidates as $lotId => $lot) {
        $paidAmount = (float)($summaries[$lotId]['total_paid'] ?? 0.0);
        $lastPaymentDate = trim((string)($summaries[$lotId]['last_payment_date'] ?? ''));
        $lotPrice = (float)($lot['lot_price'] ?? 0);
        $remainingBalance = max(0.0, $lotPrice - $paidAmount);

        if ($paidAmount <= 0 || $remainingBalance <= 0 || $lastPaymentDate === '') {
            continue;
        }

        try {
            $lastDate = new DateTime($lastPaymentDate);
        } catch (Exception $e) {
            continue;
        }

        if ($lastDate > $threshold) {
            continue;
        }

        $status = strtolower(trim((string)($lot['status'] ?? '')));
        if (in_array($status, ['paid', 'sold', 'fully paid'], true)) {
            continue;
        }

        $ownerId = (int)($lot['owner_id'] ?? 0);
        $ownerName = '';
        $ownerEmail = '';
        if ($ownerId > 0 && hasTable($conn, 'user_accounts') && hasColumn($conn, 'user_accounts', 'email')) {
            $stmtUser = prepOrDie($conn, "SELECT first_name, last_name, email FROM user_accounts WHERE id = ? LIMIT 1");
            $stmtUser->bind_param('i', $ownerId);
            $stmtUser->execute();
            $userRow = $stmtUser->get_result()->fetch_assoc();
            $stmtUser->close();
            if ($userRow) {
                $ownerName = trim((string)($userRow['first_name'] ?? '') . ' ' . (string)($userRow['last_name'] ?? '')) ?: '';
                $ownerEmail = trim((string)($userRow['email'] ?? ''));
            }
        }

        $refundAmount = round($paidAmount * 0.2, 2);
        $companyAmount = round($paidAmount - $refundAmount, 2);
        if (!ensureLotStatusHistoryTable($conn)) {
            continue;
        }

        $conn->begin_transaction();
        $stmtUpdate = prepOrDie($conn, "UPDATE lots SET owner_id = NULL, status = 'Available' WHERE id = ?");
        $stmtUpdate->bind_param('i', $lotId);
        $updated = $stmtUpdate->execute();
        $stmtUpdate->close();

        $historyOk = recordLotHistoryEvent(
            $conn,
            $lotId,
            'forfeiture',
            $ownerId,
            $ownerName,
            $ownerEmail,
            $paidAmount,
            $refundAmount,
            $companyAmount,
            'Auto-forfeiture after 3 months of payment discontinuation.'
        );

        if ($updated && $historyOk && $conn->commit()) {
            $overdue[] = $lotId;
        } else {
            $conn->rollback();
        }
    }

    return $overdue;
}

// Automatically apply forfeiture for overdue lots when the dashboard loads.
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    autoForfeitOverdueLots($conn);
}

// >>> PAYMENTS AJAX HANDLER (Handles Pay Now & History) >>>
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    $action = $_GET['action'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $userEmailForAccess = '';

    if (hasTable($conn, 'user_accounts') && hasColumn($conn, 'user_accounts', 'email')) {
        $stmtEmail = prepOrDie($conn, "SELECT email FROM user_accounts WHERE id = ? LIMIT 1");
        $stmtEmail->bind_param('i', $uid);
        $stmtEmail->execute();
        $emailRow = $stmtEmail->get_result()->fetch_assoc();
        $stmtEmail->close();
        $userEmailForAccess = trim((string)($emailRow['email'] ?? ''));
    }
    
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

    // Fetch History
    if ($action === 'history') {
        if (!hasTable($conn,'payments')) {
            echo json_encode(['success'=>true,'payments'=>[]]); exit;
        }
        $stmt = prepOrDie($conn, "SELECT payment_date AS created_at, remarks AS description, lot_id, amount_paid, status FROM payments WHERE user_id=? ORDER BY payment_date DESC LIMIT 20");
        $stmt->bind_param('i',$uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        echo json_encode(['success'=>true,'payments'=>$rows]); exit;
    }

    // Process Payment
    if ($action === 'pay') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw,true);
        
        $lot_id  = (int)($data['lot_id'] ?? 0);
        $amount  = (float)($data['amount'] ?? 0);
        $remarks = trim((string)($data['description'] ?? 'Payment'));
        $method  = trim((string)($data['method'] ?? 'Manual'));
        
        if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid amount']); exit; }

        // Create payments table if missing (safety check)
        $conn->query("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT, lot_id INT, amount_paid DECIMAL(10,2), 
            payment_date DATETIME, payment_method VARCHAR(50), 
            reference_no VARCHAR(100), status VARCHAR(50), remarks TEXT
        )");

        $stmt = prepOrDie($conn,"INSERT INTO payments (user_id, lot_id, amount_paid, payment_date, payment_method, status, remarks) VALUES (?, ?, ?, NOW(), ?, 'Pending', ?)");
        $stmt->bind_param('iidss', $uid, $lot_id, $amount, $method, $remarks);
        
        if ($stmt->execute()) {
            $emailSent = false;
            $emailError = '';

            if ($userEmailForAccess === '') {
                $userEmailForAccess = trim((string)($_SESSION['email'] ?? ''));
            }

            if ($userEmailForAccess !== '') {
                $lotLabel = 'Lot #' . $lot_id;
                if ($lot_id > 0 && hasTable($conn, 'lots')) {
                    $hasLocationJoin = hasTable($conn, 'lot_locations')
                        && hasColumn($conn, 'lots', 'location_id')
                        && hasColumn($conn, 'lot_locations', 'location_name');

                    $lotSql = "SELECT l.block_number, l.lot_number" . ($hasLocationJoin ? ", ll.location_name" : "") . "
                               FROM lots l" . ($hasLocationJoin ? " LEFT JOIN lot_locations ll ON ll.id = l.location_id" : "") . "
                               WHERE l.id = ? LIMIT 1";
                    $lotStmt = prepOrDie($conn, $lotSql);
                    $lotStmt->bind_param('i', $lot_id);
                    $lotStmt->execute();
                    $lotRow = $lotStmt->get_result()->fetch_assoc();
                    $lotStmt->close();

                    if ($lotRow) {
                        $lotLabel = 'Block ' . (string)($lotRow['block_number'] ?? 'N/A') . ', Lot ' . (string)($lotRow['lot_number'] ?? 'N/A');
                        $locationName = trim((string)($lotRow['location_name'] ?? ''));
                        if ($locationName !== '') {
                            $lotLabel .= ' (' . $locationName . ')';
                        }
                    }
                }

                $recipientName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
                $subject = 'Payment Submission Received';
                $body = "Hello " . ($recipientName !== '' ? $recipientName : 'Client') . ",\n\n"
                    . "We received your payment submission in Nuevo Puerta.\n"
                    . "Property: {$lotLabel}\n"
                    . "Amount: PHP " . number_format($amount, 2) . "\n"
                    . "Method: {$method}\n"
                    . "Submitted on: " . date('F j, Y g:i A') . "\n"
                    . "Remarks: " . ($remarks !== '' ? $remarks : '-') . "\n\n"
                    . "Status: Pending verification by admin\n\n"
                    . "Please keep this email as your transaction record.\n\n"
                    . "Thank you,\nNuevo Puerta";

                $emailSent = sendSystemEmailSimple($userEmailForAccess, $recipientName, $subject, $body, $emailError);
            } else {
                $emailError = 'No recipient email found for this account.';
            }

            echo json_encode([
                'success' => true,
                'message' => 'Payment submitted successfully',
                'email_sent' => $emailSent,
                'email_error' => $emailSent ? null : ($emailError !== '' ? $emailError : null)
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Database error']);
        }
        $stmt->close();
        exit;
    }

    // Send message to agent
    if ($action === 'send_agent_message') {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $agent_id = (int)($data['agent_id'] ?? 0);
            $message  = trim((string)($data['message'] ?? ''));

            if ($agent_id <= 0 || $message === '') {
                echo json_encode(['success' => false, 'message' => 'Agent and message are required.']);
                exit;
            }

            // Ensure messages table exists (compatible with agentnotification.php)
            $conn->query("CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                name VARCHAR(100) NULL,
                phone VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                message TEXT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_messages_agent (agent_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Add missing columns if they don't exist
            if (!hasColumn($conn, 'messages', 'is_read')) {
                $conn->query("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
            }
            if (!hasColumn($conn, 'messages', 'created_at')) {
                $conn->query("ALTER TABLE messages ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            }

            // Get sender details
            $senderName  = 'Client';
            $senderPhone = '';
            $senderEmail = '';

            if (hasTable($conn, 'user_accounts')) {
                $cols = ['first_name', 'last_name', 'email'];
                if (hasColumn($conn, 'user_accounts', 'mobile_number')) {
                    $cols[] = 'mobile_number';
                } elseif (hasColumn($conn, 'user_accounts', 'mobile')) {
                    $cols[] = 'mobile';
                }

                $colList = implode(', ', $cols);
                $stmtUser = $conn->prepare("SELECT $colList FROM user_accounts WHERE id = ? LIMIT 1");
                if ($stmtUser) {
                    $stmtUser->bind_param('i', $uid);
                    $stmtUser->execute();
                    $row = $stmtUser->get_result()->fetch_assoc();
                    $stmtUser->close();

                    if ($row) {
                        $senderName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        if ($senderName === '') $senderName = 'Client';
                        $senderEmail = (string)($row['email'] ?? '');
                        $senderPhone = (string)($row['mobile_number'] ?? ($row['mobile'] ?? ''));
                    }
                }
            }

            // Build INSERT based on actual columns
            $insertCols = ['agent_id', 'name', 'phone', 'email', 'message'];
            $insertVals = ['?', '?', '?', '?', '?'];
            $bindTypes  = 'issss';
            $bindParams = [&$agent_id, &$senderName, &$senderPhone, &$senderEmail, &$message];

            if (hasColumn($conn, 'messages', 'is_read')) {
                $insertCols[] = 'is_read';
                $insertVals[] = '0';
            }
            if (hasColumn($conn, 'messages', 'created_at')) {
                $insertCols[] = 'created_at';
                $insertVals[] = 'NOW()';
            }

            $sql = "INSERT INTO messages (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmtIns = $conn->prepare($sql);
            if (!$stmtIns) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit;
            }
            $stmtIns->bind_param($bindTypes, $agent_id, $senderName, $senderPhone, $senderEmail, $message);
            $ok = $stmtIns->execute();
            $stmtIns->close();

            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Message sent to agent.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send message.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'submit_agent_review') {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $agentId = (int)($data['agent_id'] ?? 0);
            $rating = (int)($data['rating'] ?? 0);
            $reviewText = trim((string)($data['review'] ?? ''));

            if ($agentId <= 0 || $rating < 1 || $rating > 5) {
                echo json_encode(['success' => false, 'message' => 'Please select a valid rating (1 to 5).']);
                exit;
            }

            if (mb_strlen($reviewText) > 2000) {
                echo json_encode(['success' => false, 'message' => 'Review is too long (max 2000 characters).']);
                exit;
            }

            $userOwnsLotForAgent = false;
            if (hasTable($conn, 'lots') && hasColumn($conn, 'lots', 'owner_id') && hasColumn($conn, 'lots', 'agent_id')) {
                $stmtLot = prepOrDie($conn, "SELECT 1 FROM lots WHERE owner_id = ? AND agent_id = ? LIMIT 1");
                $stmtLot->bind_param('ii', $uid, $agentId);
                $stmtLot->execute();
                $userOwnsLotForAgent = (bool)$stmtLot->get_result()->fetch_assoc();
                $stmtLot->close();
            }

            $userViewedWithAgent = false;
            if (!$userOwnsLotForAgent && $userEmailForAccess !== '' && hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'agent_id') && hasColumn($conn, 'viewings', 'client_email')) {
                $stmtView = prepOrDie($conn, "SELECT 1 FROM viewings WHERE agent_id = ? AND LOWER(TRIM(client_email)) = LOWER(TRIM(?)) LIMIT 1");
                $stmtView->bind_param('is', $agentId, $userEmailForAccess);
                $stmtView->execute();
                $userViewedWithAgent = (bool)$stmtView->get_result()->fetch_assoc();
                $stmtView->close();
            }

            if (!$userOwnsLotForAgent && !$userViewedWithAgent) {
                echo json_encode(['success' => false, 'message' => 'You can only review your assigned/engaged agent.']);
                exit;
            }

            if (!ensureAgentReviewsTable($conn)) {
                echo json_encode(['success' => false, 'message' => 'Could not prepare reviews table.']);
                exit;
            }

            $stmtExists = prepOrDie($conn, "SELECT id FROM agent_reviews WHERE agent_id = ? AND user_id = ? LIMIT 1");
            $stmtExists->bind_param('ii', $agentId, $uid);
            $stmtExists->execute();
            $existingRow = $stmtExists->get_result()->fetch_assoc();
            $stmtExists->close();

            if ($existingRow) {
                $stmtUpdate = prepOrDie($conn, "UPDATE agent_reviews SET rating = ?, review_text = ?, updated_at = NOW() WHERE id = ?");
                $reviewId = (int)$existingRow['id'];
                $stmtUpdate->bind_param('isi', $rating, $reviewText, $reviewId);
                $ok = $stmtUpdate->execute();
                $stmtUpdate->close();
            } else {
                $stmtInsert = prepOrDie($conn, "INSERT INTO agent_reviews (agent_id, user_id, rating, review_text, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $stmtInsert->bind_param('iiis', $agentId, $uid, $rating, $reviewText);
                $ok = $stmtInsert->execute();
                $stmtInsert->close();
            }

            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Thank you. Your review has been saved.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save review.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'mark_notification_read') {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $notifId = (int)($data['id'] ?? 0);
            $source = trim((string)($data['source'] ?? ''));

            if ($notifId <= 0 || ($source !== 'notifications' && $source !== 'user_notifications')) {
                echo json_encode(['success' => false, 'message' => 'Invalid notification payload.']);
                exit;
            }

            if (!hasTable($conn, $source) || !hasColumn($conn, $source, 'is_read')) {
                echo json_encode(['success' => false, 'message' => 'Notification source does not support read state.']);
                exit;
            }

            $whereSql = 'id = ?';
            $bindTypes = 'i';
            $bindVals = [$notifId];

            if ($source === 'user_notifications' && hasColumn($conn, 'user_notifications', 'user_id')) {
                $whereSql .= ' AND user_id = ?';
                $bindTypes .= 'i';
                $bindVals[] = $uid;
            } elseif ($source === 'notifications') {
                if (hasColumn($conn, 'notifications', 'recipient_type') && hasColumn($conn, 'notifications', 'recipient_id')) {
                    $whereSql .= ' AND recipient_type = ? AND recipient_id = ?';
                    $bindTypes .= 'si';
                    $recipientType = 'user';
                    $bindVals[] = $recipientType;
                    $bindVals[] = $uid;
                } elseif (hasColumn($conn, 'notifications', 'recipient_id')) {
                    $whereSql .= ' AND recipient_id = ?';
                    $bindTypes .= 'i';
                    $bindVals[] = $uid;
                } elseif (hasColumn($conn, 'notifications', 'user_id')) {
                    $whereSql .= ' AND user_id = ?';
                    $bindTypes .= 'i';
                    $bindVals[] = $uid;
                }
            }

            $sql = "UPDATE {$source} SET is_read = 1 WHERE {$whereSql} LIMIT 1";
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param($bindTypes, ...$bindVals);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if (!$ok) {
                echo json_encode(['success' => false, 'message' => 'Unable to update notification.']);
                exit;
            }

            echo json_encode(['success' => true, 'updated' => max(0, (int)$affected)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'lot_history') {
        $lotId = (int)($_GET['lot_id'] ?? 0);
        if ($lotId <= 0 || !hasTable($conn, 'lots')) {
            echo json_encode(['success' => false, 'message' => 'Invalid lot.']);
            exit;
        }

        $allowed = false;
        if (hasColumn($conn, 'lots', 'owner_id')) {
            $stmtAllowed = prepOrDie($conn, "SELECT 1 FROM lots WHERE id = ? AND owner_id = ? LIMIT 1");
            $stmtAllowed->bind_param('ii', $lotId, $uid);
            $stmtAllowed->execute();
            $allowed = (bool)$stmtAllowed->get_result()->fetch_assoc();
            $stmtAllowed->close();
        }

        if (!$allowed && $userEmailForAccess !== '' && hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'lot_id') && hasColumn($conn, 'viewings', 'client_email')) {
            $stmtAllowed = prepOrDie($conn, "SELECT 1 FROM viewings WHERE lot_id = ? AND LOWER(TRIM(client_email)) = LOWER(TRIM(?)) LIMIT 1");
            $stmtAllowed->bind_param('is', $lotId, $userEmailForAccess);
            $stmtAllowed->execute();
            $allowed = (bool)$stmtAllowed->get_result()->fetch_assoc();
            $stmtAllowed->close();
        }

        if (!$allowed) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access to lot history.']);
            exit;
        }

        echo json_encode(['success' => true, 'history' => getLotHistoryRows($conn, $lotId)]);
        exit;
    }

    if ($action === 'surrender_preview') {
        $lotId = (int)($_GET['lot_id'] ?? 0);
        if ($lotId <= 0 || !hasTable($conn, 'lots') || !hasColumn($conn, 'lots', 'owner_id')) {
            echo json_encode(['success' => false, 'message' => 'Invalid lot preview request.']);
            exit;
        }

        $stmtLot = prepOrDie($conn, "SELECT owner_id FROM lots WHERE id = ? LIMIT 1");
        $stmtLot->bind_param('i', $lotId);
        $stmtLot->execute();
        $lotRow = $stmtLot->get_result()->fetch_assoc();
        $stmtLot->close();

        if (!$lotRow || (int)($lotRow['owner_id'] ?? 0) !== $uid) {
            echo json_encode(['success' => false, 'message' => 'Lot not owned by current user.']);
            exit;
        }

        $summaries = fetchLotPaymentSummaries($conn, [$lotId]);
        $paidAmount = round((float)($summaries[$lotId]['total_paid'] ?? 0.0), 2);
        $refundAmount = round($paidAmount * 0.2, 2);
        $companyAmount = round($paidAmount - $refundAmount, 2);

        echo json_encode([
            'success' => true,
            'paid_amount' => $paidAmount,
            'refund_amount' => $refundAmount,
            'company_amount' => $companyAmount
        ]);
        exit;
    }

    if ($action === 'surrender_lot') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $lotId = (int)($data['lot_id'] ?? 0);

        if ($lotId <= 0 || !hasTable($conn, 'lots') || !hasColumn($conn, 'lots', 'owner_id')) {
            echo json_encode(['success' => false, 'message' => 'Invalid lot surrender request.']);
            exit;
        }

        $stmtLot = prepOrDie($conn, "SELECT owner_id, status, payment_type, lot_price FROM lots WHERE id = ? LIMIT 1");
        $stmtLot->bind_param('i', $lotId);
        $stmtLot->execute();
        $lotRow = $stmtLot->get_result()->fetch_assoc();
        $stmtLot->close();

        if (!$lotRow || (int)($lotRow['owner_id'] ?? 0) !== $uid) {
            echo json_encode(['success' => false, 'message' => 'Lot not owned by current user.']);
            exit;
        }

        $summaries = fetchLotPaymentSummaries($conn, [$lotId]);
        $paidAmount = round((float)($summaries[$lotId]['total_paid'] ?? 0.0), 2);
        $refundAmount = round($paidAmount * 0.2, 2);
        $companyAmount = round($paidAmount - $refundAmount, 2);

        $surrenderReason = trim((string)($data['reason'] ?? ''));
        $surrenderRemarks = 'Client surrendered lot.';
        if ($surrenderReason !== '') {
            $surrenderRemarks .= ' Reason: ' . $surrenderReason . '.';
        }
        $surrenderRemarks .= ' 20% refunded to client, company retains 80%. Lot returned to available inventory.';

        $ownerName = '';
        $ownerEmail = '';
        if (hasTable($conn, 'user_accounts')) {
            $stmtUser = prepOrDie($conn, "SELECT first_name, last_name, email FROM user_accounts WHERE id = ? LIMIT 1");
            $stmtUser->bind_param('i', $uid);
            $stmtUser->execute();
            $userRow = $stmtUser->get_result()->fetch_assoc();
            $stmtUser->close();
            if ($userRow) {
                $ownerName = trim((string)($userRow['first_name'] ?? '') . ' ' . (string)($userRow['last_name'] ?? ''));
                $ownerEmail = trim((string)($userRow['email'] ?? ''));
            }
        }

        if (!ensureLotStatusHistoryTable($conn)) {
            echo json_encode(['success' => false, 'message' => 'Unable to prepare surrender history.']);
            exit;
        }

        $conn->begin_transaction();
        $stmtUpdate = prepOrDie($conn, "UPDATE lots SET owner_id = NULL, status = 'Available' WHERE id = ?");
        $stmtUpdate->bind_param('i', $lotId);
        $updateOk = $stmtUpdate->execute();
        $stmtUpdate->close();

        $historyOk = recordLotHistoryEvent(
            $conn,
            $lotId,
            'surrender',
            $uid,
            $ownerName,
            $ownerEmail,
            $paidAmount,
            $refundAmount,
            $companyAmount,
            $surrenderRemarks
        );

        if ($updateOk && $historyOk && $conn->commit()) {
            echo json_encode(['success' => true, 'message' => 'Lot surrendered successfully.', 'refund_amount' => $refundAmount, 'company_amount' => $companyAmount]);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to surrender lot.']);
        }
        exit;
    }

    // Fetch payment transactions for user's own lots
    if ($action === 'lot_payments') {
        $lotId = (int)($_GET['lot_id'] ?? 0);
        $transactions = [];
        $lots = [];

        if ($lotId > 0) {
            $lotSql = "SELECT id, block_number, lot_number, lot_price, payment_type, status, payment_deadline";
            if (hasColumn($conn, 'lots', 'location_name')) {
                $lotSql .= ", location_name";
            }
            $lotSql .= " FROM lots WHERE id = ? AND owner_id = ? LIMIT 1";

            $stmt = $conn->prepare($lotSql);
            if ($stmt) {
                $stmt->bind_param('ii', $lotId, $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                $lot = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            } else {
                $lot = null;
            }

            if ($lot) {
                $lots[] = $lot;
            }
        } else {
            if (hasTable($conn, 'lots')) {
                $lotsSql = "SELECT id, block_number, lot_number, lot_price, payment_type, status, payment_deadline";
                if (hasColumn($conn, 'lots', 'location_name')) {
                    $lotsSql .= ", location_name";
                }
                $lotsSql .= " FROM lots WHERE owner_id = ? ORDER BY id DESC";

                $stmt = $conn->prepare($lotsSql);
                if ($stmt) {
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $lots = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                }
            }
        }

        if (!empty($lots)) {
            $paymentCols = "id, amount_paid AS amount, payment_date";
            if (hasColumn($conn, 'payments', 'payment_method')) {
                $paymentCols .= ", payment_method";
            } else {
                $paymentCols .= ", '' AS payment_method";
            }
            if (hasColumn($conn, 'payments', 'remarks')) {
                $paymentCols .= ", remarks";
            } else {
                $paymentCols .= ", '' AS remarks";
            }

            $paymentsSql = "SELECT {$paymentCols} FROM payments WHERE lot_id = ? ORDER BY payment_date ASC, id ASC";
            $legacySql = "SELECT id, amount, payment_date, payment_method, remarks FROM lot_payment_transactions WHERE lot_id = ?";
            $legacyHasUserId = hasColumn($conn, 'lot_payment_transactions', 'user_id');
            if ($legacyHasUserId) {
                $legacySql .= " AND user_id = ?";
            }
            $legacySql .= " ORDER BY payment_date ASC, id ASC";

            foreach ($lots as $lot) {
                $lotIdValue = (int)($lot['id'] ?? 0);
                if ($lotIdValue <= 0) {
                    continue;
                }

                $combinedPayments = [];

                if (hasTable($conn, 'payments')) {
                    $stmt = $conn->prepare($paymentsSql);
                    if ($stmt) {
                        $stmt->bind_param('i', $lotIdValue);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $payments = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                        $stmt->close();
                        $combinedPayments = array_merge($combinedPayments, $payments);
                    }
                }

                if (hasTable($conn, 'lot_payment_transactions')) {
                    $stmt = $conn->prepare($legacySql);
                    if ($stmt) {
                        if ($legacyHasUserId) {
                            $stmt->bind_param('ii', $lotIdValue, $uid);
                        } else {
                            $stmt->bind_param('i', $lotIdValue);
                        }
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $legacyPayments = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                        $stmt->close();
                        $combinedPayments = array_merge($combinedPayments, $legacyPayments);
                    }
                }

                if (empty($combinedPayments)) {
                    continue;
                }

                usort($combinedPayments, function($a, $b) {
                    $aDate = $a['payment_date'] ?? '';
                    $bDate = $b['payment_date'] ?? '';
                    if ($aDate === $bDate) {
                        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
                    }
                    return strcmp($aDate, $bDate);
                });

                $runningTotal = 0;
                foreach ($combinedPayments as $payment) {
                    $amount = (float)($payment['amount'] ?? 0);
                    $runningTotal += $amount;
                    $transactions[] = [
                        'id' => (int)($payment['id'] ?? 0),
                        'lot_id' => $lotIdValue,
                        'block_number' => $lot['block_number'] ?? '',
                        'lot_number' => $lot['lot_number'] ?? '',
                        'location_name' => $lot['location_name'] ?? '',
                        'lot_price' => (float)($lot['lot_price'] ?? 0),
                        'payment_type' => $lot['payment_type'] ?? '',
                        'status' => $lot['status'] ?? '',
                        'payment_deadline' => $lot['payment_deadline'] ?? '',
                        'amount_paid_so_far' => $runningTotal,
                        'amount' => $amount,
                        'payment_date' => $payment['payment_date'] ?? '',
                        'payment_method' => $payment['payment_method'] ?? '',
                        'remarks' => $payment['remarks'] ?? ''
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
        exit;
    }

    exit;
}

/* ---------------- PROFILE UPDATE ---------------- */
$profile_update_success = '';
$profile_update_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_action']) && $_POST['profile_action'] === 'update_profile') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) {
        $profile_update_error = 'Not authenticated.';
    } elseif (!hasTable($conn, 'user_accounts')) {
        $profile_update_error = 'User table not available.';
    } else {
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $middle_name = trim((string)($_POST['middle_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $username_in = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if ($first_name === '' || $last_name === '' || $email === '') {
            $profile_update_error = 'First name, last name, and email are required.';
        } else {
            $fields = [];
            $types = '';
            $values = [];

            if (hasColumn($conn, 'user_accounts', 'first_name')) {
                $fields[] = 'first_name=?';
                $types .= 's';
                $values[] = $first_name;
            }
            if (hasColumn($conn, 'user_accounts', 'middle_name')) {
                $fields[] = 'middle_name=?';
                $types .= 's';
                $values[] = $middle_name;
            }
            if (hasColumn($conn, 'user_accounts', 'last_name')) {
                $fields[] = 'last_name=?';
                $types .= 's';
                $values[] = $last_name;
            }
            if (hasColumn($conn, 'user_accounts', 'username')) {
                $fields[] = 'username=?';
                $types .= 's';
                $values[] = $username_in;
            }
            if (hasColumn($conn, 'user_accounts', 'email')) {
                $fields[] = 'email=?';
                $types .= 's';
                $values[] = $email;
            }

            if (hasColumn($conn, 'user_accounts', 'mobile_number')) {
                $fields[] = 'mobile_number=?';
                $types .= 's';
                $values[] = $mobile;
            } elseif (hasColumn($conn, 'user_accounts', 'mobile')) {
                $fields[] = 'mobile=?';
                $types .= 's';
                $values[] = $mobile;
            }

            if (hasColumn($conn, 'user_accounts', 'address')) {
                $fields[] = 'address=?';
                $types .= 's';
                $values[] = $address;
            }

            if (empty($fields)) {
                $profile_update_error = 'No updatable fields found.';
            } else {
                $sql = 'UPDATE user_accounts SET ' . implode(', ', $fields) . ' WHERE id=?';
                $stmt = prepOrDie($conn, $sql);
                $types .= 'i';
                $values[] = $uid;
                $stmt->bind_param($types, ...$values);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $profile_update_success = 'Profile updated successfully.';
                } else {
                    $profile_update_error = 'Failed to update profile.';
                }
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($profile_update_error !== '') {
            echo json_encode(['success' => false, 'message' => $profile_update_error]);
        } else {
            echo json_encode(['success' => true, 'message' => $profile_update_success ?: 'Profile updated successfully.']);
        }
        exit;
    }
}

/* ---------------- 1. FETCH USER DATA (FIXED LOGIN CRASH) ---------------- */
$user_id = (int)$_SESSION['user_id'];
$user = [
    'first_name' => '', 'middle_name' => '', 'last_name' => '', 
    'username' => '', 'email' => '', 'address' => '', 'mobile' => '', 'photo' => ''
];

if (hasTable($conn, 'user_accounts')) {
    // Determine which columns actually exist to prevent "Unknown column" error
    $cols = ['first_name','middle_name','last_name','username','email','address'];
    
    // CHECK BOTH MOBILE COLUMN NAMES
    $hasMobileNum = hasColumn($conn, 'user_accounts', 'mobile_number');
    $hasMobile    = hasColumn($conn, 'user_accounts', 'mobile');
    
    if ($hasMobileNum) $cols[] = 'mobile_number';
    if ($hasMobile)    $cols[] = 'mobile';
    if (hasColumn($conn,'user_accounts','photo')) $cols[] = 'photo';
    if (hasColumn($conn,'user_accounts','role'))  $cols[] = 'role';

    $colList = implode(', ', $cols);
    $stmt = prepOrDie($conn, "SELECT $colList FROM user_accounts WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows) { 
        $userData = $res->fetch_assoc();
        $user = array_merge($user, $userData);
        
        // SMART MOBILE DETECTION: Use whichever column has data
        $m1 = $userData['mobile_number'] ?? '';
        $m2 = $userData['mobile'] ?? '';
        $user['mobile'] = !empty($m1) ? $m1 : $m2;
    } else {
        // User ID in session but not in DB? Force logout.
        session_destroy();
        header("Location: Login/login.php");
        exit;
    }
    $stmt->close();
}

$user_email = $user['email'] ?? '';
$sidebarName = trim(($user['first_name']??'') . ' ' . ($user['last_name']??'')) ?: ($user['username']??'User');
$roleLabel = $user['role'] ?? 'Client';

/* ---------------- 2. FETCH PROPERTIES (LOTS) ---------------- */
$listings = [];
$lotsOwned = 0;
$reservedLots = 0;

if (hasTable($conn,'lots') && hasColumn($conn,'lots','owner_id')) {
    $hasLocationJoin = hasTable($conn, 'lot_locations')
        && hasColumn($conn, 'lots', 'location_id')
        && hasColumn($conn, 'lot_locations', 'location_name');

    $cols = "l.id, l.block_number, l.lot_number, l.lot_size, l.lot_price";
    if ($hasLocationJoin) {
        $cols .= ", ll.location_name";
    }
    if (hasColumn($conn,'lots','payment_type'))    $cols .= ", l.payment_type";
    if (hasColumn($conn,'lots','status'))         $cols .= ", l.status";
    if (hasColumn($conn,'lots','agent_id'))      $cols .= ", l.agent_id";
    if (hasColumn($conn,'lots','payment_amount')) $cols .= ", l.payment_amount";
    if (hasColumn($conn,'lots','down_payment_amount')) $cols .= ", l.down_payment_amount";
    if (hasColumn($conn,'lots','payment_deadline')) $cols .= ", l.payment_deadline";
    if (hasColumn($conn,'lots','payment_term_years')) $cols .= ", l.payment_term_years";
    if (hasColumn($conn,'lots','payment_due_day')) $cols .= ", l.payment_due_day";

    $hasViewingLink = hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'client_email');
    $hasViewingUserLink = hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'user_id');
    $hasViewingPhoneLink = hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'client_phone');
    $hasViewingNameLink = hasTable($conn, 'viewings')
        && hasColumn($conn, 'viewings', 'client_first_name')
        && hasColumn($conn, 'viewings', 'client_last_name');
    $hasSalesLink = hasTable($conn, 'sales') && hasColumn($conn, 'sales', 'buyer') && hasColumn($conn, 'sales', 'property');
    $hasSalesLotNo = hasTable($conn, 'sales') && hasColumn($conn, 'sales', 'buyer') && hasColumn($conn, 'sales', 'lot_no');
    $hasPaymentOwnershipLink = hasTable($conn, 'lot_payment_transactions')
        && hasColumn($conn, 'lot_payment_transactions', 'lot_id')
        && hasColumn($conn, 'lot_payment_transactions', 'user_id');
    $hasLegacyPaymentsLink = hasTable($conn, 'payments')
        && hasColumn($conn, 'payments', 'lot_id')
        && hasColumn($conn, 'payments', 'user_id');

    $userEmail = trim((string)($user['email'] ?? ''));
    $userFullName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    $userFirstName = trim((string)($user['first_name'] ?? ''));
    $userLastName = trim((string)($user['last_name'] ?? ''));
    $userMobileDigits = preg_replace('/\D+/', '', (string)($user['mobile'] ?? ''));

    // Repair orphan payment links: admin payment entry can save user_id as NULL when owner_id was not set yet.
    if ($hasPaymentOwnershipLink) {
        $txRepairConditions = ["l.owner_id = ?"];
        $txRepairBindTypes = 'i';
        $txRepairBindValues = [$user_id];

        if ($hasViewingUserLink) {
            $txRepairConditions[] = "EXISTS (
                SELECT 1
                FROM viewings vru
                WHERE vru.lot_id = l.id AND vru.user_id = ?
            )";
            $txRepairBindTypes .= 'i';
            $txRepairBindValues[] = $user_id;
        }
        if ($hasViewingLink && $userEmail !== '') {
            $txRepairConditions[] = "EXISTS (
                SELECT 1
                FROM viewings vre
                WHERE vre.lot_id = l.id AND LOWER(TRIM(vre.client_email)) = LOWER(TRIM(?))
            )";
            $txRepairBindTypes .= 's';
            $txRepairBindValues[] = $userEmail;
        }
        if ($hasViewingPhoneLink && $userMobileDigits !== '') {
            $txRepairConditions[] = "EXISTS (
                SELECT 1
                FROM viewings vrp
                WHERE vrp.lot_id = l.id
                  AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(vrp.client_phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?
            )";
            $txRepairBindTypes .= 's';
            $txRepairBindValues[] = $userMobileDigits;
        }
        if ($hasViewingNameLink && $userFirstName !== '' && $userLastName !== '') {
            $txRepairConditions[] = "EXISTS (
                SELECT 1
                FROM viewings vrn
                WHERE vrn.lot_id = l.id
                  AND LOWER(TRIM(vrn.client_first_name)) = LOWER(TRIM(?))
                  AND LOWER(TRIM(vrn.client_last_name)) = LOWER(TRIM(?))
            )";
            $txRepairBindTypes .= 'ss';
            $txRepairBindValues[] = $userFirstName;
            $txRepairBindValues[] = $userLastName;
        }

        $txRepairWhereSql = '(' . implode(' OR ', $txRepairConditions) . ')';
        $txRepairSql = "UPDATE lot_payment_transactions t
            INNER JOIN lots l ON l.id = t.lot_id
            SET t.user_id = ?
            WHERE (t.user_id IS NULL OR t.user_id = 0)
              AND $txRepairWhereSql";
        $stmt = prepOrDie($conn, $txRepairSql);
        $stmt->bind_param('i' . $txRepairBindTypes, $user_id, ...$txRepairBindValues);
        $stmt->execute();
        $stmt->close();

        $ownerRepairSql = "UPDATE lots l
            SET l.owner_id = ?
            WHERE (l.owner_id IS NULL OR l.owner_id = 0)
              AND EXISTS (
                SELECT 1
                FROM lot_payment_transactions t
                WHERE t.lot_id = l.id AND t.user_id = ?
            )";
        $stmt = prepOrDie($conn, $ownerRepairSql);
        $stmt->bind_param('ii', $user_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    $visibilityConditions = ["l.owner_id = ?"];
    if ($hasViewingLink) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM viewings v
            WHERE v.lot_id = l.id AND v.client_email = ?
        )";
    }
    if ($hasViewingUserLink) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM viewings vu
            WHERE vu.lot_id = l.id AND vu.user_id = ?
        )";
    }
    if ($hasViewingPhoneLink && $userMobileDigits !== '') {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM viewings vp
            WHERE vp.lot_id = l.id
              AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(vp.client_phone), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?
        )";
    }
    if ($hasViewingNameLink && $userFirstName !== '' && $userLastName !== '') {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM viewings vn
            WHERE vn.lot_id = l.id
              AND LOWER(TRIM(vn.client_first_name)) = LOWER(TRIM(?))
              AND LOWER(TRIM(vn.client_last_name)) = LOWER(TRIM(?))
        )";
    }
    if ($hasPaymentOwnershipLink) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM lot_payment_transactions t
            WHERE t.lot_id = l.id AND t.user_id = ?
        )";
    }
    if ($hasLegacyPaymentsLink) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM payments p
            WHERE p.lot_id = l.id AND p.user_id = ?
        )";
    }
    if ($hasSalesLink) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM sales s
            WHERE (
                LOWER(TRIM(s.buyer)) = LOWER(TRIM(?))
                OR LOWER(TRIM(s.buyer)) = LOWER(TRIM(?))
                OR (LOWER(TRIM(s.buyer)) LIKE LOWER(CONCAT('%', TRIM(?), '%'))
                    AND LOWER(TRIM(s.buyer)) LIKE LOWER(CONCAT('%', TRIM(?), '%')))
            )
            AND (
                CAST(l.id AS CHAR) = TRIM(s.property)
                OR LOWER(REPLACE(TRIM(s.property), ',', '')) = LOWER(CONCAT('block ', l.block_number, ' lot ', l.lot_number))
                OR LOWER(REPLACE(TRIM(s.property), ',', '')) = LOWER(CONCAT('lot ', l.lot_number, ' block ', l.block_number))
                OR LOWER(TRIM(s.property)) = LOWER(CONCAT(l.block_number, '-', l.lot_number))
                OR LOWER(REPLACE(TRIM(s.property), ',', '')) LIKE LOWER(CONCAT('%block ', l.block_number, '%lot ', l.lot_number, '%'))
                OR LOWER(REPLACE(TRIM(s.property), ',', '')) LIKE LOWER(CONCAT('%lot ', l.lot_number, '%block ', l.block_number, '%'))
            )
        )";
    }
    if ($hasSalesLotNo) {
        $visibilityConditions[] = "EXISTS (
            SELECT 1
            FROM sales s2
            WHERE (
                LOWER(TRIM(s2.buyer)) = LOWER(TRIM(?))
                OR LOWER(TRIM(s2.buyer)) = LOWER(TRIM(?))
            )
            AND TRIM(CAST(s2.lot_no AS CHAR)) = TRIM(CAST(l.lot_number AS CHAR))
        )";
    }

    $visibilityWhereSql = '(' . implode(' OR ', $visibilityConditions) . ')';

    $sql = "SELECT DISTINCT $cols FROM lots l" . ($hasLocationJoin ? "\n        LEFT JOIN lot_locations ll ON ll.id = l.location_id" : "") . "
        WHERE $visibilityWhereSql";

    $stmt = prepOrDie($conn, $sql);
    $visibilityBindTypes = 'i';
    $visibilityBindValues = [$user_id];
    if ($hasViewingLink) {
        $visibilityBindTypes .= 's';
        $visibilityBindValues[] = $userEmail;
    }
    if ($hasViewingUserLink) {
        $visibilityBindTypes .= 'i';
        $visibilityBindValues[] = $user_id;
    }
    if ($hasViewingPhoneLink && $userMobileDigits !== '') {
        $visibilityBindTypes .= 's';
        $visibilityBindValues[] = $userMobileDigits;
    }
    if ($hasViewingNameLink && $userFirstName !== '' && $userLastName !== '') {
        $visibilityBindTypes .= 'ss';
        $visibilityBindValues[] = $userFirstName;
        $visibilityBindValues[] = $userLastName;
    }
    if ($hasPaymentOwnershipLink) {
        $visibilityBindTypes .= 'i';
        $visibilityBindValues[] = $user_id;
    }
    if ($hasLegacyPaymentsLink) {
        $visibilityBindTypes .= 'i';
        $visibilityBindValues[] = $user_id;
    }
    if ($hasSalesLink) {
        $visibilityBindTypes .= 'ssss';
        $visibilityBindValues[] = $userEmail;
        $visibilityBindValues[] = $userFullName;
        $visibilityBindValues[] = $userFirstName;
        $visibilityBindValues[] = $userLastName;
    }
    if ($hasSalesLotNo) {
        $visibilityBindTypes .= 'ss';
        $visibilityBindValues[] = $userEmail;
        $visibilityBindValues[] = $userFullName;
    }
    $stmt->bind_param($visibilityBindTypes, ...$visibilityBindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $listings = $res->fetch_all(MYSQLI_ASSOC);
        $lotsOwned = count($listings);
    }
    $stmt->close();

    // Count reserved lots (owned or reserved by this user)
    if (hasColumn($conn,'lots','status')) {
        $sql = "SELECT COUNT(DISTINCT l.id) as c FROM lots l
            WHERE $visibilityWhereSql AND l.status = 'Reserved'";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param($visibilityBindTypes, ...$visibilityBindValues);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reservedLots = $row['c'] ?? 0;
        $stmt->close();
    }
}

/* ---------------- 3. FETCH VIEWINGS & NOTIFICATIONS ---------------- */
$upcomingViewings = [];
$recentActivities = [];
$systemNotifications = [];

if (hasTable($conn,'viewings')) {
    // FIX: Add 'v.' prefix to explicitly select status from the viewings table
    $statusCol = hasColumn($conn,'viewings','status') ? 'v.status' : "'' as status";
    
    $agentJoin = hasTable($conn,'agent_accounts') ? "LEFT JOIN agent_accounts a ON v.agent_id = a.id" : "";
    $agentCols = hasTable($conn,'agent_accounts') ? ", a.first_name as agent_first, a.last_name as agent_last, a.email as agent_email" : "";

    // Fetch Viewings
    $sql = "SELECT v.id, v.lot_no, v.preferred_at, $statusCol $agentCols 
            FROM viewings v $agentJoin 
            WHERE v.client_email = ? 
            ORDER BY v.preferred_at DESC LIMIT 20";
            
    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { 
        $data = $res->fetch_all(MYSQLI_ASSOC);
        // Split into upcoming and recent for notifications
        foreach($data as $row) {
            $upcomingViewings[] = $row; 
            $recentActivities[] = $row; 
        }
    }
    $stmt->close();
}

if (hasTable($conn, 'notifications')) {
    $selectCols = [];
    if (hasColumn($conn, 'notifications', 'id')) $selectCols[] = 'id';
    if (hasColumn($conn, 'notifications', 'title')) $selectCols[] = 'title';
    if (hasColumn($conn, 'notifications', 'message')) $selectCols[] = 'message';
    if (hasColumn($conn, 'notifications', 'type')) $selectCols[] = 'type';
    if (hasColumn($conn, 'notifications', 'is_read')) $selectCols[] = 'is_read';
    if (hasColumn($conn, 'notifications', 'created_at')) $selectCols[] = 'created_at';

    if (!empty($selectCols)) {
        $whereSql = '';
        $types = '';
        $params = [];

        if (hasColumn($conn, 'notifications', 'recipient_type') && hasColumn($conn, 'notifications', 'recipient_id')) {
            $whereSql = ' WHERE recipient_type = ? AND recipient_id = ? ';
            $types = 'si';
            $recipientType = 'user';
            $params = [$recipientType, $user_id];
        } elseif (hasColumn($conn, 'notifications', 'recipient_id')) {
            $whereSql = ' WHERE recipient_id = ? ';
            $types = 'i';
            $params = [$user_id];
        } elseif (hasColumn($conn, 'notifications', 'user_id')) {
            $whereSql = ' WHERE user_id = ? ';
            $types = 'i';
            $params = [$user_id];
        }

        if ($whereSql !== '') {
            $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM notifications ' . $whereSql . ' ORDER BY created_at DESC LIMIT 20';
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $systemNotifications = $res->fetch_all(MYSQLI_ASSOC);
                foreach ($systemNotifications as &$notification) {
                    $notification['notif_source'] = 'notifications';
                }
                unset($notification);
            }
            $stmt->close();
        }
    }
}

if (empty($systemNotifications) && hasTable($conn, 'user_notifications')) {
    $selectCols = [];
    if (hasColumn($conn, 'user_notifications', 'id')) $selectCols[] = 'id';
    if (hasColumn($conn, 'user_notifications', 'title')) $selectCols[] = 'title';
    if (hasColumn($conn, 'user_notifications', 'message')) $selectCols[] = 'message';
    if (hasColumn($conn, 'user_notifications', 'type')) $selectCols[] = 'type';
    if (hasColumn($conn, 'user_notifications', 'is_read')) $selectCols[] = 'is_read';
    if (hasColumn($conn, 'user_notifications', 'created_at')) $selectCols[] = 'created_at';

    if (!empty($selectCols) && hasColumn($conn, 'user_notifications', 'user_id')) {
        $orderCol = hasColumn($conn, 'user_notifications', 'created_at') ? 'created_at' : 'id';
        $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM user_notifications WHERE user_id = ? ORDER BY ' . $orderCol . ' DESC LIMIT 20';
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $systemNotifications = $res->fetch_all(MYSQLI_ASSOC);
            foreach ($systemNotifications as &$notification) {
                $notification['notif_source'] = 'user_notifications';
            }
            unset($notification);
        }
        $stmt->close();
    }
}

$unreadNotificationCount = 0;
if (!empty($systemNotifications)) {
    $hasReadFlag = false;
    foreach ($systemNotifications as $notification) {
        if (array_key_exists('is_read', $notification)) {
            $hasReadFlag = true;
            if ((int)($notification['is_read'] ?? 0) === 0) {
                $unreadNotificationCount++;
            }
        }
    }

    if (!$hasReadFlag) {
        $unreadNotificationCount = count($systemNotifications);
    }
}

/* ---------------- 4. CALCULATE OUTSTANDING BALANCE ---------------- */
$outstandingBalance = 0.0;

/* ---------------- 5. DASHBOARD ADD-ONS: AGENT MESSAGING + DOC PROGRESS + PAYMENT DEADLINE ---------------- */
$userAgents = [];

if (hasTable($conn, 'agent_accounts')) {
    // Agents linked to user's lots
    if (hasTable($conn, 'lots') && hasColumn($conn, 'lots', 'owner_id') && hasColumn($conn, 'lots', 'agent_id')) {
        $sql = "SELECT DISTINCT a.id, a.first_name, a.last_name, a.email
                FROM lots l
                INNER JOIN agent_accounts a ON a.id = l.agent_id
                WHERE l.owner_id = ?";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $userAgents[(int)$row['id']] = $row;
            }
        }
        $stmt->close();
    }

    // Agents linked to user's viewings
    if (hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'agent_id') && hasColumn($conn, 'viewings', 'client_email')) {
        $sql = "SELECT DISTINCT a.id, a.first_name, a.last_name, a.email
                FROM viewings v
                INNER JOIN agent_accounts a ON a.id = v.agent_id
                WHERE v.client_email = ?";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('s', $user_email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $userAgents[(int)$row['id']] = $row;
            }
        }
        $stmt->close();
    }
}

$documentStats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'progress_percent' => 0
];

$documentItems = [];
$contractDocs = [];
$approvedContractDocs = [];
$approvedContractsByLot = [];
$approvedGeneralContracts = [];
$agreementDocs = [];
$approvedAgreementDocs = [];
$approvedAgreementsByLot = [];
$approvedGeneralAgreements = [];
$approvedLegalDocsByLot = [];

$previousPayments = [];

if (hasTable($conn, 'user_documents') && hasColumn($conn, 'user_documents', 'user_id')) {
    if (hasColumn($conn, 'user_documents', 'status')) {
        $stmt = prepOrDie($conn, "SELECT status, COUNT(*) AS total FROM user_documents WHERE user_id = ? GROUP BY status");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $status = strtolower(trim((string)($row['status'] ?? 'pending')));
                $count = (int)($row['total'] ?? 0);
                $documentStats['total'] += $count;

                if (in_array($status, ['approved', 'accepted', 'verified'], true)) {
                    $documentStats['approved'] += $count;
                } elseif (in_array($status, ['rejected', 'declined', 'denied'], true)) {
                    $documentStats['rejected'] += $count;
                } else {
                    $documentStats['pending'] += $count;
                }
            }
        }
        $stmt->close();
    } else {
        $stmt = prepOrDie($conn, "SELECT COUNT(*) AS total FROM user_documents WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $documentStats['total'] = (int)($row['total'] ?? 0);
        $documentStats['pending'] = $documentStats['total'];
        $stmt->close();
    }

    if ($documentStats['total'] > 0) {
        $documentStats['progress_percent'] = (int)round(($documentStats['approved'] / $documentStats['total']) * 100);
    }

    // Ensure lot_id column exists
    $conn->query("ALTER TABLE user_documents ADD COLUMN IF NOT EXISTS lot_id INT NULL");

    // Full document list for Contracts and Agreements copies
    $docSql = "SELECT id, file_name, file_path, uploaded_at, doc_type, status, lot_id
               FROM user_documents
               WHERE user_id = ?
               ORDER BY uploaded_at DESC
               LIMIT 120";
    $stmt = prepOrDie($conn, $docSql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $documentItems = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();

    foreach ($documentItems as $doc) {
        $docType = strtolower(trim((string)($doc['doc_type'] ?? '')));
        $status = strtolower(trim((string)($doc['status'] ?? '')));
        $lotId = $doc['lot_id'] ?? null;
        if (str_contains($docType, 'contract')) {
            $contractDocs[] = $doc;
            if ($status === 'approved') {
                $approvedContractDocs[] = $doc;
                if ($lotId !== null) {
                    $approvedContractsByLot[$lotId][] = $doc;
                    $lotKey = (int)$lotId;
                    if (!isset($approvedLegalDocsByLot[$lotKey])) {
                        $approvedLegalDocsByLot[$lotKey] = [];
                    }
                    $approvedLegalDocsByLot[$lotKey][] = [
                        'id' => (int)($doc['id'] ?? 0),
                        'doc_type' => (string)($doc['doc_type'] ?? 'Copy of Contract'),
                        'file_name' => (string)($doc['file_name'] ?? 'Contract File'),
                        'file_path' => (string)($doc['file_path'] ?? ''),
                        'uploaded_at' => (string)($doc['uploaded_at'] ?? ''),
                    ];
                } else {
                    $approvedGeneralContracts[] = $doc;
                }
            }
        }
        if (str_contains($docType, 'agreement') || str_contains($docType, 'waiver') || str_contains($docType, 'terms')) {
            $agreementDocs[] = $doc;
            if ($status === 'approved') {
                $approvedAgreementDocs[] = $doc;
                if ($lotId !== null) {
                    $approvedAgreementsByLot[$lotId][] = $doc;
                    $lotKey = (int)$lotId;
                    if (!isset($approvedLegalDocsByLot[$lotKey])) {
                        $approvedLegalDocsByLot[$lotKey] = [];
                    }
                    $approvedLegalDocsByLot[$lotKey][] = [
                        'id' => (int)($doc['id'] ?? 0),
                        'doc_type' => (string)($doc['doc_type'] ?? 'Copy of Agreement'),
                        'file_name' => (string)($doc['file_name'] ?? 'Agreement File'),
                        'file_path' => (string)($doc['file_path'] ?? ''),
                        'uploaded_at' => (string)($doc['uploaded_at'] ?? ''),
                    ];
                } else {
                    $approvedGeneralAgreements[] = $doc;
                }
            }
        }
    }
}

// Previous payments history for user-owned lots
if (hasTable($conn, 'lot_payment_transactions') && hasTable($conn, 'lots')) {
    $hasLocationJoin = hasTable($conn, 'lot_locations')
        && hasColumn($conn, 'lots', 'location_id')
        && hasColumn($conn, 'lot_locations', 'location_name');

    $paySql = "SELECT t.payment_date, t.amount, t.payment_method, t.remarks,
                      t.lot_id,
                      l.block_number, l.lot_number" . ($hasLocationJoin ? ", ll.location_name" : "") . "
               FROM lot_payment_transactions t
               INNER JOIN lots l ON l.id = t.lot_id" . ($hasLocationJoin ? "\n               LEFT JOIN lot_locations ll ON ll.id = l.location_id" : "") . "
               WHERE (
                    t.user_id = ?
                    OR l.owner_id = ?
                    OR EXISTS (
                        SELECT 1
                        FROM viewings v
                        WHERE v.lot_id = l.id AND v.client_email = ?
                    )
               )
               ORDER BY t.payment_date ASC, t.id ASC
               LIMIT 120";
    $stmt = prepOrDie($conn, $paySql);
    $stmt->bind_param('iis', $user_id, $user_id, $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $previousPayments = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$paymentsByLot = [];
$paymentRowsByLot = [];
$paidMonthsByLot = [];
foreach ($previousPayments as $paymentRow) {
    $paymentLotId = (int)($paymentRow['lot_id'] ?? 0);
    if ($paymentLotId <= 0) {
        continue;
    }
    $paymentsByLot[$paymentLotId] = ($paymentsByLot[$paymentLotId] ?? 0.0) + (float)($paymentRow['amount'] ?? 0);
    if (!isset($paymentRowsByLot[$paymentLotId])) {
        $paymentRowsByLot[$paymentLotId] = [];
    }
    $paymentRowsByLot[$paymentLotId][] = $paymentRow;

    $paymentDateRaw = trim((string)($paymentRow['payment_date'] ?? ''));
    if ($paymentDateRaw !== '') {
        $monthKey = date('Y-m', strtotime($paymentDateRaw));
        if ($monthKey) {
            if (!isset($paidMonthsByLot[$paymentLotId])) {
                $paidMonthsByLot[$paymentLotId] = [];
            }
            $paidMonthsByLot[$paymentLotId][$monthKey] = true;
        }
    }
}

$paidTotalsByLot = [];
foreach ($listings as $lot) {
    $lotId = (int)($lot['id'] ?? 0);
    if ($lotId <= 0) {
        continue;
    }

    $txPaid = (float)($paymentsByLot[$lotId] ?? 0);
    $downPaymentAmount = (float)($lot['down_payment_amount'] ?? 0);

    $downPaymentAlreadyRecorded = false;
    if ($downPaymentAmount > 0 && !empty($paymentRowsByLot[$lotId])) {
        foreach ($paymentRowsByLot[$lotId] as $txRow) {
            $txAmount = (float)($txRow['amount'] ?? 0);
            if (abs($txAmount - $downPaymentAmount) < 0.01) {
                $downPaymentAlreadyRecorded = true;
                break;
            }
        }
    }

    $paidTotal = $txPaid + (($downPaymentAmount > 0 && !$downPaymentAlreadyRecorded) ? $downPaymentAmount : 0);
    $paidTotalsByLot[$lotId] = $paidTotal;

    $price = (float)($lot['lot_price'] ?? 0);
    $outstandingBalance += max(0, $price - $paidTotal);
}

/**
 * Build a due date using a reference month and due-day value.
 */
function buildDueDateForMonth(DateTime $referenceMonth, int $dueDay): DateTime {
    $year = (int)$referenceMonth->format('Y');
    $month = (int)$referenceMonth->format('m');
    $daysInMonth = (int)$referenceMonth->format('t');
    $day = max(1, min($dueDay, $daysInMonth));

    $due = clone $referenceMonth;
    $due->setDate($year, $month, $day);
    $due->setTime(0, 0, 0);
    return $due;
}

/**
 * Compute the next unpaid installment due date for a lot.
 */
function computeNextLotDueDate(array $lot, float $paidTotal): ?DateTime {
    $paymentType = trim((string)($lot['payment_type'] ?? ''));
    if ($paymentType !== 'Down Payment') {
        return null;
    }

    $deadlineRaw = trim((string)($lot['payment_deadline'] ?? ''));
    if ($deadlineRaw === '') {
        return null;
    }

    try {
        $anchor = new DateTime($deadlineRaw);
    } catch (Exception $e) {
        return null;
    }

    $dueDay = (int)($lot['payment_due_day'] ?? 0);
    if ($dueDay < 1 || $dueDay > 31) {
        $dueDay = (int)$anchor->format('j');
    }

    $termYears = (int)($lot['payment_term_years'] ?? 0);
    $maxMonths = $termYears > 0 ? ($termYears * 12) : 120;

    $monthlyAmount = resolveLotInstallmentMonthlyAmount($lot);
    $downPayment = (float)($lot['down_payment_amount'] ?? 0);
    $installmentPaid = max(0.0, $paidTotal - $downPayment);

    $paidMonthsCount = 0;
    if ($monthlyAmount > 0) {
        $paidMonthsCount = (int)floor(($installmentPaid + 0.0001) / $monthlyAmount);
    }
    if ($paidMonthsCount < 0) {
        $paidMonthsCount = 0;
    }

    if ($paidMonthsCount >= $maxMonths) {
        return null;
    }

    $targetMonth = clone $anchor;
    if ($paidMonthsCount > 0) {
        $targetMonth->modify('+' . $paidMonthsCount . ' month');
    }

    return buildDueDateForMonth($targetMonth, $dueDay);
}

function resolveLotInstallmentMonthlyAmount(array $lot): float {
    $monthlyAmount = (float)($lot['payment_amount'] ?? 0);
    if ($monthlyAmount > 0) {
        return $monthlyAmount;
    }

    $lotPrice = (float)($lot['lot_price'] ?? 0);
    $downPayment = (float)($lot['down_payment_amount'] ?? 0);
    $installmentBalance = max($lotPrice - $downPayment, 0);
    $termYears = (int)($lot['payment_term_years'] ?? 0);

    if ($termYears > 0 && $installmentBalance > 0) {
        return $installmentBalance / ($termYears * 12);
    }

    return $installmentBalance;
}

function computeNextInstallmentDueAmount(array $lot, float $paidTotal): float {
    $lotPrice = (float)($lot['lot_price'] ?? 0);
    $downPayment = (float)($lot['down_payment_amount'] ?? 0);
    $monthlyAmount = resolveLotInstallmentMonthlyAmount($lot);

    $installmentBalance = max($lotPrice - $downPayment, 0);
    $installmentPaid = max(0, $paidTotal - $downPayment);
    $remainingBalance = max(0, $installmentBalance - $installmentPaid);

    if ($remainingBalance <= 0) {
        return 0.0;
    }

    $baseDue = $monthlyAmount > 0 ? min($monthlyAmount, $remainingBalance) : $remainingBalance;
    if ($monthlyAmount <= 0) {
        return round($baseDue, 2);
    }

    $currentCyclePaid = fmod($installmentPaid, $monthlyAmount);
    if (!is_finite($currentCyclePaid)) {
        $currentCyclePaid = 0.0;
    }

    return round(max(0, $baseDue - $currentCyclePaid), 2);
}

$nextPaymentCardAmount = 'N/A';
$nextPaymentCardDate = 'No due date set';

$paymentReminder = [
    'text' => 'No payment deadline set.',
    'date' => null,
    'days_left' => null,
    'overdue' => false
];
$downPaymentDeadlines = [];

if (hasTable($conn, 'lots') && hasColumn($conn, 'lots', 'payment_deadline')) {
    $nextDeadline = null;

    foreach ($listings as $lot) {
        $lotId = (int)($lot['id'] ?? 0);
        $paidTotalForLot = (float)($paidTotalsByLot[$lotId] ?? 0);
        $candidate = computeNextLotDueDate($lot, $paidTotalForLot);
        if (!$candidate) {
            continue;
        }

        $today = new DateTime('today');
        $daysLeftEach = (int)$today->diff($candidate)->format('%r%a');
        if ($daysLeftEach < 0) {
            $deadlineText = 'Overdue by ' . abs($daysLeftEach) . ' day(s)';
        } elseif ($daysLeftEach === 0) {
            $deadlineText = 'Next payment: ' . $candidate->format('M d, Y') . ' (Due today)';
        } else {
            $deadlineText = 'Next payment: ' . $candidate->format('M d, Y');
        }

        $nextScheduledAmount = (float)($lot['payment_amount'] ?? 0);
        $paidTotalDisplay = (float)($paidTotalsByLot[(int)($lot['id'] ?? 0)] ?? 0);
        $nextDueAmount = computeNextInstallmentDueAmount($lot, $paidTotalDisplay);
        if ($nextDueAmount > 0) {
            $nextScheduledAmount = $nextDueAmount;
        }
        $downPaymentDeadlines[] = [
            'lot_id' => (int)($lot['id'] ?? 0),
            'sort_date' => $candidate->format('Y-m-d'),
            'lot_label' => formatLotLocationLabel($lot),
            'date' => $candidate->format('M d, Y'),
            'amount' => $nextScheduledAmount,
            'status_text' => $deadlineText,
            'due_day' => (int)($lot['payment_due_day'] ?? 0),
            'days_left' => $daysLeftEach
        ];

        if ($nextDeadline === null || $candidate < $nextDeadline) {
            $nextDeadline = $candidate;
        }
    }

    if (!empty($downPaymentDeadlines)) {
        usort($downPaymentDeadlines, static function ($a, $b) {
            return strcmp($a['sort_date'], $b['sort_date']);
        });

        $nearestPayment = $downPaymentDeadlines[0];
        $nextPaymentCardDate = $nearestPayment['date'] ?? 'No due date set';
        if (!empty($nearestPayment['amount']) && (float)$nearestPayment['amount'] > 0) {
            $nextPaymentCardAmount = '₱' . number_format((float)$nearestPayment['amount'], 2);
        }
    }

    if ($nextDeadline !== null) {
        $today = new DateTime('today');
        $daysLeft = (int)$today->diff($nextDeadline)->format('%r%a');

        $paymentReminder['date'] = $nextDeadline->format('M d, Y');
        $paymentReminder['days_left'] = $daysLeft;
        $paymentReminder['overdue'] = $daysLeft < 0;

        if ($daysLeft < 0) {
            $paymentReminder['text'] = 'Overdue by ' . abs($daysLeft) . ' day(s)';
        } elseif ($daysLeft === 0) {
            $paymentReminder['text'] = 'Next payment: ' . $paymentReminder['date'] . ' (Due today)';
        } else {
            $paymentReminder['text'] = 'Next payment: ' . $paymentReminder['date'];
        }
    }
}

/* ---------------- 6. FETCH ASSIGNED AGENT ---------------- */
$agent = null;
if (!empty($userAgents)) {
    $agentRow = reset($userAgents);
    $agent = [
        'id' => (int)($agentRow['id'] ?? 0),
        'first_name' => $agentRow['first_name'] ?? '',
        'last_name' => $agentRow['last_name'] ?? '',
        'email' => $agentRow['email'] ?? 'N/A',
        'photo_path' => 'assets/Default_photo.jpg'
    ];
}

$agentReviewSummary = [
    'average_rating' => 0,
    'review_count' => 0
];
$myAgentReview = null;

if ($agent && (int)($agent['id'] ?? 0) > 0 && hasTable($conn, 'agent_reviews')) {
    $agentIdForReview = (int)$agent['id'];

    $stmtReviewSummary = prepOrDie($conn, "SELECT IFNULL(AVG(rating), 0) AS average_rating, COUNT(*) AS review_count FROM agent_reviews WHERE agent_id = ?");
    $stmtReviewSummary->bind_param('i', $agentIdForReview);
    $stmtReviewSummary->execute();
    $summaryRow = $stmtReviewSummary->get_result()->fetch_assoc();
    $stmtReviewSummary->close();

    if ($summaryRow) {
        $agentReviewSummary['average_rating'] = (float)($summaryRow['average_rating'] ?? 0);
        $agentReviewSummary['review_count'] = (int)($summaryRow['review_count'] ?? 0);
    }

    $stmtMyReview = prepOrDie($conn, "SELECT rating, review_text, updated_at FROM agent_reviews WHERE agent_id = ? AND user_id = ? LIMIT 1");
    $stmtMyReview->bind_param('ii', $agentIdForReview, $uid);
    $stmtMyReview->execute();
    $myAgentReview = $stmtMyReview->get_result()->fetch_assoc();
    $stmtMyReview->close();
}

/* ---------------- 7. FETCH TURNOVERS FOR OWNED LOTS ---------------- */
$lotTurnovers = [];
if (hasTable($conn, 'lot_turnovers')) {
    $visibleLotIds = [];
    foreach ($listings as $visibleLot) {
        $visibleLotId = (int)($visibleLot['id'] ?? 0);
        if ($visibleLotId > 0) {
            $visibleLotIds[] = $visibleLotId;
        }
    }

    if (!empty($visibleLotIds)) {
        $visibleLotIds = array_values(array_unique($visibleLotIds));
        $hasTurnoverConfirmed = hasColumn($conn, 'lot_turnovers', 'is_confirmed');
        $confirmedWhere = $hasTurnoverConfirmed ? ' AND lt.is_confirmed = 1' : '';
        $idCsv = implode(',', $visibleLotIds);

        $turnoverSql = "SELECT lt.lot_id, lt.turnover_date, lt.title_released, lt.remarks
                        FROM lot_turnovers lt
                        WHERE lt.lot_id IN ($idCsv)" . $confirmedWhere;
        $resTv = $conn->query($turnoverSql);
        if ($resTv) {
            while ($rowTv = $resTv->fetch_assoc()) {
                $lotTurnovers[(int)$rowTv['lot_id']] = $rowTv;
            }
        }
    }
}

$conn->close();

// Avatar handling
$avatarSrc = !empty($user['photo']) ? $user['photo'] : 'assets/Default_photo.jpg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Dashboard | Nuevo Puerta</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
:root { --green:#14532d; --light-green:#e6f4ea; --white:#ffffff; --bg:#f8f9fa; --text:#333; --muted:#666; --red:#dc3545; }
body { font-family:'Poppins', sans-serif; background:var(--bg); margin:0; color:var(--text); overflow-x: hidden; overflow-y: scroll; }
.dashboard-wrapper { display:flex; min-height:100vh; }

/* --- Sidebar --- */
.sidebar { width:280px; background:var(--green); color:var(--white); padding:30px 0; position:fixed; top:0; bottom:0; overflow-y:auto; transition:0.3s; z-index:1000; display:flex; flex-direction:column; align-items:center; }

/* Hide native scrollbar in the sidebar (remove scroll line) */
.sidebar { scrollbar-width: none; -ms-overflow-style: none; }
.sidebar::-webkit-scrollbar { width: 0; height: 0; }
.sidebar-logo { display:flex; align-items:center; gap:12px; margin-bottom:24px; padding:0 20px; width:100%; justify-content:center; }
.sidebar-logo img { width:56px; height:56px; border-radius:50%; background:rgba(255,255,255,0.1); padding:5px; object-fit:contain; }
.sidebar-brand { display:flex; flex-direction:column; line-height:1.1; }
.sidebar-logo h2 { margin:0; font-size:18px; font-weight:700; letter-spacing:0.5px; color: var(--white); }
.sidebar-logo span { font-size:12px; opacity:0.85; letter-spacing:1.5px; text-transform:uppercase; }

.sidebar-user { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.1); margin:0 auto 24px; padding:12px; border-radius:12px; width:220px; }
.sidebar-user img { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#fff; }
.sidebar-user div { line-height:1.2; }
.sidebar-user h3 { margin:0; font-size:14px; font-weight:600; }
.sidebar-user span { font-size:11px; opacity:0.85; }

nav {
    width: 100%;
}

.nav-link {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    padding: 12px 22px;
    color: rgba(255,255,255,0.9);
    text-decoration: none;
    transition: background 0.18s, color 0.18s, transform 0.12s;
    border-left: 4px solid transparent;
    font-size: 15px;
    margin: 6px 14px;
    border-radius: 8px;
    width: calc(100% - 28px);
}

.nav-link:hover,
.nav-link.active {
    background: rgba(255,255,255,0.06); /* softer hover so it reads as subtle */
    color: #fff;
    transform: translateY(-1px);
}
/* --- Main Content --- */
.main-content { margin-left:280px; flex:1; padding:35px 45px; }
.section { display:none; animation: fadeIn 0.4s ease; }
.section.active { display:block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

h2 { font-size:32px; font-weight:700; color:var(--green); margin-bottom:8px; letter-spacing:-0.5px; }
.subtitle { color:var(--muted); margin-bottom:30px; display:block; font-size:15px; }

/* --- Cards --- */
.card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:30px; }
.stat-card { background:var(--white); padding:16px 18px; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); display:flex; align-items:center; gap:12px; border:1px solid #f0f0f0; transition:all 0.3s ease; overflow:hidden; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(0,0,0,0.1); }
.stat-icon { width:48px; height:48px; background:var(--light-green); border-radius:11px; display:flex; align-items:center; justify-content:center; color:var(--green); font-size:22px; flex-shrink:0; }
.stat-info { min-width:0; flex:1; overflow:hidden; }
.stat-info h3 { margin:0; font-size:16px; font-weight:700; color:var(--text); line-height:1.3; letter-spacing:-0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stat-info span { font-size:12px; color:var(--muted); margin-top:3px; display:block; white-space:nowrap; }
.stat-card.stat-card-next-payment {
    align-items: center;
    min-height: 112px;
}
.stat-card.stat-card-next-payment .stat-info {
    overflow: visible;
}
.stat-card.stat-card-next-payment .stat-icon {
    margin-top: 0;
}
.stat-card.stat-card-next-payment .stat-info h3 {
    font-size: 20px;
    line-height: 1.15;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    word-break: break-word;
}
.stat-card.stat-card-next-payment .stat-info span {
    margin-top: 6px;
    white-space: normal;
    line-height: 1.4;
}
.stat-card.stat-card-reminder { align-items:flex-start; }
.stat-card.stat-card-reminder .stat-info { overflow:visible; }
.stat-card.stat-card-reminder .stat-icon { margin-top:2px; }
.stat-card.stat-card-reminder .stat-info h3 {
    font-size:18px;
    line-height:1.15;
    white-space:normal;
    overflow:visible;
    text-overflow:clip;
}
.stat-card.stat-card-reminder .stat-info span {
    white-space:normal;
    line-height:1.35;
}
.deadline-list { margin:8px 0 0; padding:0; list-style:none; display:flex; flex-direction:column; gap:6px; }
.deadline-item { font-size:12px; color:var(--muted); line-height:1.35; }
.deadline-item strong { color:var(--text); font-weight:600; }

/* --- Tables & Lists --- */
.content-box { background:var(--white); border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); padding:28px; border:1px solid #f0f0f0; margin-bottom:30px; }
.box-header { font-size:18px; font-weight:700; margin-bottom:22px; color:var(--green); border-bottom:2px solid #f0f0f0; padding-bottom:16px; letter-spacing:0.3px; }

table { width:100%; border-collapse:collapse; min-width:600px; }
th, td { padding:16px 15px; text-align:left; border-bottom:1px solid #f0f0f0; font-size:14px; }
th { font-weight:700; color:var(--text); background:#f9fbfd; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; }
tr:last-child td { border-bottom:none; }
tbody tr:hover { background:#f9fbfd; }

.notification-list { list-style:none; padding:0; margin:0; }
.notif-item { padding:18px 0; border-bottom:1px solid #f0f0f0; display:flex; align-items:flex-start; gap:16px; }
.notif-item:last-child { border-bottom:none; }
.notif-icon { width:42px; height:42px; background:#f0f4f8; color:var(--green); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:18px; }
.notif-content strong { display:block; font-size:15px; margin-bottom:4px; color:var(--text); }
.notif-content span { font-size:13px; color:var(--muted); line-height:1.4; }

/* --- Forms --- */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; margin-bottom:8px; font-weight:600; font-size:13px; color:var(--text); text-transform:capitalize; letter-spacing:0.3px; }
.form-control { width:100%; padding:12px 14px; border:1.5px solid #e0e0e0; border-radius:9px; font-size:14px; box-sizing:border-box; transition:all 0.25s ease; background:#fafbfc; font-family:'Poppins', sans-serif; }
.form-control:hover { border-color:#d0d0d0; background:#fff; }
.form-control:focus { border-color:var(--green); outline:none; background:#fff; box-shadow:0 0 0 3px rgba(20, 83, 45, 0.1); }
.form-control[readonly] { background:#f5f5f5; color:#999; cursor:not-allowed; }

.flash-message {
    opacity: 1;
    transition: opacity 0.6s ease;
}

.flash-message.fade-out {
    opacity: 0;
}

.btn { padding:11px 28px; border:none; border-radius:9px; font-weight:600; cursor:pointer; font-size:14px; transition:all 0.3s ease; display:inline-block; letter-spacing:0.3px; }
.btn-primary { background:var(--green); color:var(--white); box-shadow:0 2px 8px rgba(20, 83, 45, 0.2); }
.btn-primary:hover { background:#0f4223; transform:translateY(-2px); box-shadow:0 4px 12px rgba(20, 83, 45, 0.3); }
.btn-primary:active { transform:translateY(0); }
.btn-secondary { background:#f0f0f0; color:#333; border:1.5px solid #e0e0e0; }
.btn-secondary:hover { background:#e8e8e8; border-color:#d0d0d0; }

.badge { padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700; text-transform:capitalize; letter-spacing:0.3px; display:inline-block; }
.badge.scheduled { background:#e0f2fe; color:#0284c7; }
.badge.pending { background:#fef3c7; color:#d97706; }
.badge.cancelled { background:#fee2e2; color:#dc2626; }
.badge.completed, .badge.complete { background:#dcfce7; color:#166534; }
.badge.paid { background:#dcfce7; color:#166534; }

/* --- Agent Card --- */
.agent-card { display:flex; align-items:center; gap:22px; background:linear-gradient(135deg, #f9fbfd 0%, #fff 100%); padding:24px; border-radius:12px; border:1px solid #f0f0f0; }
.agent-img { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--white); box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.agent-details h3 { margin:0 0 6px; font-size:18px; font-weight:700; color:var(--text); }
.agent-details p { margin:6px 0; font-size:14px; color:var(--muted); }

/* Mobile */
@media (max-width: 900px) {
    .dashboard-wrapper { flex-direction: column; }
    .sidebar {
        width: 100%;
        position: static;
        padding: 14px 10px;
        max-height: none;
        border-bottom: 1px solid rgba(255,255,255,0.15);
    }

    .sidebar-logo,
    .sidebar-user {
        width: 100%;
        max-width: 100%;
    }

    nav {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 4px 2px;
    }

    .nav-link {
        width: auto;
        min-width: max-content;
        margin: 0;
        padding: 10px 12px;
    }

    .main-content { margin-left:0; padding:20px 24px; }
    .form-grid { grid-template-columns:1fr !important; }
    .card-grid { grid-template-columns:1fr; }
    .properties-metrics { grid-template-columns:1fr; min-width:0; width:100%; }
    .properties-hero { padding:18px; }
    .properties-hero h2 { font-size:24px; }
    .properties-grid { grid-template-columns:1fr; }
    .property-details { grid-template-columns:1fr; }
    .stat-card.stat-card-next-payment {
        min-height: 0;
    }
    .content-box { padding: 16px; overflow-x: auto; }
    table { min-width: 520px; }
    h2 { font-size:24px; }
    .subtitle { margin-bottom:20px; }
}

@media (max-width: 560px) {
    .main-content { padding: 14px 12px; }
    .agent-card { flex-direction: column; align-items: flex-start; }
    .properties-card,
    .properties-hero,
    .property-card { border-radius:14px; }
    .property-card-top { padding:18px 16px 0; }
    .property-details,
    .property-card-actions,
    .property-footer-note { padding-left:16px; padding-right:16px; }
    .modal-content { width:calc(100% - 20px); max-height:calc(100vh - 20px); }
    .modal-body { padding:18px; }
}

/* Logout icon flip and white text for logout link */
.logout-icon { display:inline-block; transform: scaleX(-1); -webkit-transform: scaleX(-1); }
.logout-link { color: #ffffff !important; }

/* --- Message & Document Sections --- */
.message-section { margin-bottom:30px; }
.message-section .form-grid { grid-template-columns:1fr 2fr; }

#msg_agent_text { resize:vertical; min-height:90px; }

.progress-container { margin:20px 0; }
.progress-bar { background:#f1f5f9; border-radius:12px; height:12px; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg, var(--green) 0%, #0f4223 100%); border-radius:12px; transition:width 0.4s ease; }
.progress-text { margin-top:12px; font-size:14px; font-weight:600; color:var(--green); }

/* --- Empty States --- */
.empty-state { text-align:center; padding:40px 20px; color:var(--muted); }
.empty-state p { font-size:15px; line-height:1.6; }

/* --- Modal Styles --- */
.modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:2000; display:none; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-content { background:var(--white); border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,0.2); width:90%; max-width:500px; max-height:calc(100vh - 40px); overflow:hidden; animation:modalSlideIn 0.3s ease; display:flex; flex-direction:column; }
@keyframes modalSlideIn { from { transform:translateY(-30px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-header { padding:24px; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; }
.modal-header h3 { margin:0; font-size:20px; font-weight:700; color:var(--green); }
.modal-close-btn { background:none; border:none; font-size:24px; color:var(--muted); cursor:pointer; width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:6px; transition:0.2s; }
.modal-close-btn:hover { background:#f5f5f5; color:var(--text); }
.modal-body { padding:24px; overflow-y:auto; }
.modal-body .form-group { margin-bottom:18px; }
.modal-footer { padding:20px 24px; border-top:1px solid #f0f0f0; display:flex; gap:12px; justify-content:flex-end; }
.modal-footer .btn { margin:0; }

/* --- Lot Contract Modal (fullscreen viewer) --- */
#lotContractModal.lot-contract-modal { background:rgba(0, 0, 0, 0.72); }
#lotContractModal.lot-contract-modal .modal-content {
    width:calc(100vw - 24px);
    max-width:1500px;
    height:calc(100vh - 24px);
    max-height:calc(100vh - 24px);
    border-radius:12px;
}
#lotContractModal.lot-contract-modal .modal-header,
#lotContractModal.lot-contract-modal .modal-footer {
    padding:14px 18px;
}
#lotContractModal.lot-contract-modal .modal-body {
    padding:12px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    gap:10px;
    min-height:0;
}
.lot-contract-toolbar {
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.lot-contract-selector {
    flex:1 1 320px;
    min-width:220px;
    padding:10px 12px;
    border:1px solid #d1d5db;
    border-radius:8px;
    background:#fff;
    font-size:14px;
}
.lot-contract-meta {
    font-size:12px;
    color:#6b7280;
    min-height:18px;
}
.lot-contract-frame-wrap {
    flex:1;
    min-height:0;
    border:1px solid #d1d5db;
    border-radius:10px;
    overflow:hidden;
    background:#fff;
}
#lot-contract-frame {
    width:100%;
    height:100%;
    border:none;
    display:block;
    background:#fff;
}

@media (max-width: 700px) {
    #lotContractModal.lot-contract-modal .modal-content {
        width:100vw;
        height:100vh;
        max-height:100vh;
        border-radius:0;
    }

    .lot-contract-toolbar {
        align-items:stretch;
    }

    .lot-contract-selector {
        flex:1 1 100%;
    }
}

/* --- Payment Page Styles --- */
.payment-info-card { background:linear-gradient(135deg, #f0fdf4 0%, #f1f5f9 100%); border:1.5px solid #bbf7d0; border-radius:12px; padding:20px; margin-bottom:30px; }
.payment-info-card h4 { margin:0 0 8px 0; color:var(--green); font-weight:700; }
.payment-hint { background:#f9fbfd; padding:18px; border-radius:10px; margin-bottom:20px; border-left:4px solid var(--green); }
.payment-hint p { margin:0; color:var(--muted); font-size:13px; }
.payment-warning { background:#fef3c7; border:1px solid #fcd34d; border-radius:9px; padding:14px; margin-bottom:20px; }
.payment-warning p { margin:0; color:#92400e; font-size:13px; }
.payment-status-guide { background:#f9fbfd; padding:16px; border-radius:9px; margin-bottom:18px; border-left:3px solid #0284c7; }
.payment-status-guide p { margin:0; color:var(--muted); font-size:13px; }

/* --- Properties Section --- */
.properties-section { display:flex; flex-direction:column; gap:18px; }
.properties-hero {
    background:linear-gradient(135deg, #0f4223 0%, #14532d 55%, #1f6b3a 100%);
    border-radius:18px;
    padding:22px 24px;
    border:1px solid rgba(20,83,45,0.14);
    box-shadow:0 10px 30px rgba(20, 83, 45, 0.08);
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
    position:relative;
    overflow:hidden;
}
.properties-hero::after {
    content:'';
    position:absolute;
    inset:auto -80px -90px auto;
    width:280px;
    height:280px;
    border-radius:50%;
    background:rgba(255,255,255,0.08);
    pointer-events:none;
}
.properties-hero-copy { max-width:680px; }
.properties-kicker {
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:rgba(255,255,255,0.16);
    color:#fff;
    padding:6px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    letter-spacing:0.4px;
    text-transform:uppercase;
    margin-bottom:12px;
}
.properties-hero h2 {
    margin:0 0 8px;
    color:#fff;
    font-size:30px;
    line-height:1.15;
}
.properties-hero p {
    margin:0;
    color:rgba(255,255,255,0.96);
    font-size:14px;
    line-height:1.6;
    max-width:720px;
}
.properties-metrics {
    display:grid;
    grid-template-columns:repeat(2, minmax(120px, 1fr));
    gap:12px;
    min-width:280px;
}
.property-metric {
    background:rgba(255,255,255,0.92);
    border:1px solid rgba(20,83,45,0.08);
    border-radius:14px;
    padding:14px 16px;
    box-shadow:0 8px 18px rgba(15, 42, 24, 0.06);
    position:relative;
    z-index:1;
}
.property-metric strong {
    display:block;
    font-size:22px;
    color:var(--green);
    line-height:1.1;
}
.property-metric span {
    display:block;
    margin-top:4px;
    font-size:12px;
    color:var(--muted);
}
.properties-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px; }
.property-card {
    display:flex;
    flex-direction:column;
    gap:14px;
    background:linear-gradient(180deg, #ffffff 0%, #fbfdfb 100%);
    border:1px solid #e8efe9;
    border-radius:18px;
    box-shadow:0 10px 24px rgba(15, 42, 24, 0.07);
    overflow:hidden;
    position:relative;
    transition:transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}
.property-card:hover {
    transform:translateY(-4px);
    box-shadow:0 16px 32px rgba(15, 42, 24, 0.12);
    border-color:#cfe2d4;
}
.property-card::before {
    content:'';
    position:absolute;
    inset:0 auto auto 0;
    width:100%;
    height:6px;
    background:linear-gradient(90deg, #14532d 0%, #22c55e 100%);
}
.property-card-top {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    padding:20px 20px 0;
}
.property-status {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    letter-spacing:0.5px;
    text-transform:uppercase;
    white-space:nowrap;
}
.property-title {
    margin:0;
    color:var(--green);
    font-size:20px;
    font-weight:700;
    line-height:1.25;
}
.property-location {
    display:flex;
    align-items:flex-start;
    gap:10px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
.property-location i {
    color:#16a34a;
    margin-top:2px;
}
.property-details {
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:12px;
    padding:0 20px;
}
.property-detail {
    background:#f8fbf8;
    border:1px solid #e7eee8;
    border-radius:14px;
    padding:12px 14px;
}
.property-detail label {
    display:block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.6px;
    color:#6b7280;
    margin-bottom:6px;
}
.property-detail strong {
    display:block;
    font-size:15px;
    color:var(--text);
    line-height:1.35;
}
.property-detail .value-green { color:var(--green); }
.property-card-actions {
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:0 20px 20px;
}
.property-card-actions .btn {
    width:100%;
    justify-content:center;
    padding:12px 18px;
    border-radius:12px;
}
.property-footer-note {
    padding:0 20px 20px;
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
}
</style>
</head>
<body>

<div class="dashboard-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="assets/f.png" alt="Logo" onerror="this.src='assets/Default_photo.jpg'">
            <div class="sidebar-brand">
                <h2>NUEVO PUERTA</h2>
                <span>Real Estate</span>
            </div>
        </div>
        
        <div class="sidebar-user">
            <img src="<?php echo h($avatarSrc); ?>" alt="User">
            <div>
                <h3><?php echo h($sidebarName); ?></h3>
                <span><?php echo h($roleLabel); ?></span>
            </div>
        </div>

        <nav>
            <a href="#dashboard" class="nav-link active" onclick="switchTab('dashboard', this)"><i class="fa fa-th-large"></i> Dashboard</a>
            <a href="#profile" class="nav-link" onclick="switchTab('profile', this)"><i class="fa fa-user"></i> My Profile</a>
            <a href="#properties" class="nav-link" onclick="switchTab('properties', this)"><i class="fa fa-building"></i> Properties</a>
            <a href="#payments" class="nav-link" onclick="switchTab('payments', this); loadLotPayments();"><i class="fa fa-credit-card"></i> Payments</a>
            <a href="#viewings" class="nav-link" onclick="switchTab('viewings', this)"><i class="fa fa-calendar-check"></i> Viewings</a>
            <a href="#documents" class="nav-link" onclick="switchTab('documents', this)"><i class="fa fa-folder-open"></i> Documents</a>
            <a href="#notifications" class="nav-link" onclick="switchTab('notifications', this)"><i class="fa fa-bell"></i> Notifications<?php if ($unreadNotificationCount > 0): ?> <span id="user-notifications-badge" style="margin-left:8px; min-width:20px; height:20px; padding:0 6px; border-radius:999px; background:#ef4444; color:#fff; font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; line-height:1; vertical-align:middle;"><?php echo $unreadNotificationCount > 99 ? '99+' : (int)$unreadNotificationCount; ?></span><?php endif; ?></a>
            <a href="#agent" class="nav-link" onclick="switchTab('agent', this)"><i class="fa fa-user-tie"></i> My Agent</a>
            <a href="#" class="nav-link logout-link" style="margin-top:20px;" onclick="confirmLogout(event)"><i class="fa fa-sign-out-alt logout-icon"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-content">
        

        <div id="dashboard" class="section active">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <div>
                    <h2>Dashboard</h2>
                    <span class="subtitle">Welcome back, <?php echo h($user['first_name']); ?></span>
                </div>
                <a href="index.php" class="btn btn-primary" style="height:40px; display:flex; align-items:center; gap:8px; font-size:15px; padding:0 22px; text-decoration:none;">
                    <i class="fa fa-home"></i> Go to Homepage
                </a>
            </div>

            <div class="card-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa fa-home"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $lotsOwned; ?></h3>
                        <span>Properties Owned</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa fa-file-signature"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $reservedLots; ?></h3>
                        <span>Reserved Lots</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fee2e2; color:#dc2626;"><i class="fa fa-coins"></i></div>
                    <div class="stat-info">
                        <h3>₱<?php echo number_format($outstandingBalance, 2); ?></h3>
                        <span>Balance Due</span>
                    </div>
                </div>
                <div class="stat-card stat-card-next-payment" style="cursor:pointer; position:relative;" onclick="switchTab('payments',document.querySelector('.nav-link[href=\'#payments\']')); loadLotPayments();">
                    <div class="stat-icon" style="background:#dcfce7; color:#166534;"><i class="fa fa-wallet"></i></div>
                    <div class="stat-info" style="width:100%">
                        <h3><?php echo h($nextPaymentCardAmount); ?></h3>
                        <span>Next Payment <?php echo !empty($nextPaymentCardDate) ? '(Due: ' . h($nextPaymentCardDate) . ')' : ''; ?></span>
                        <?php if (!empty($downPaymentDeadlines) && count($downPaymentDeadlines) > 1): ?>
                        <div style="margin-top:10px; position:relative;">
                            <button type="button" id="showAllLotsBtn" class="show-all-lots-btn">Show all lots <span class="dropdown-arrow">▼</span></button>
                            <div class="next-payments-dropdown" id="nextPaymentsDropdown">
                                <?php foreach($downPaymentDeadlines as $d): ?>
                                    <div class="next-payment-lot-row">
                                        <strong><?php echo h($d['lot_label']); ?></strong><br>
                                        <span>Due: <?php echo h($d['date']); ?></span><br>
                                        <span>Amount: ₱<?php echo number_format((float)$d['amount'], 2); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <script>
                        (function(){
                            var btn = document.getElementById('showAllLotsBtn');
                            var dropdown = document.getElementById('nextPaymentsDropdown');
                            if(btn && dropdown) {
                                btn.addEventListener('click', function(e){
                                    e.stopPropagation();
                                    var isOpen = dropdown.style.display === 'block';
                                    dropdown.style.display = isOpen ? 'none' : 'block';
                                    btn.querySelector('.dropdown-arrow').textContent = isOpen ? '▼' : '▲';
                                });
                                document.addEventListener('click', function(e){
                                    if(dropdown.style.display === 'block' && !btn.contains(e.target) && !dropdown.contains(e.target)) {
                                        dropdown.style.display = 'none';
                                        btn.querySelector('.dropdown-arrow').textContent = '▼';
                                    }
                                });
                            }
                        })();
                        </script>
                        <style>
                        .show-all-lots-btn {
                            background: #f1f5f9;
                            border: 1px solid #cbd5e1;
                            color: #166534;
                            font-size: 13.5px;
                            font-weight: 600;
                            border-radius: 7px;
                            padding: 6px 18px 6px 14px;
                            cursor: pointer;
                            transition: background 0.18s, border 0.18s;
                            outline: none;
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            box-shadow: 0 1px 4px rgba(22,101,52,0.04);
                        }
                        .show-all-lots-btn:hover, .show-all-lots-btn:focus {
                            background: #e0f2fe;
                            border-color: #38bdf8;
                        }
                        .show-all-lots-btn .dropdown-arrow {
                            font-size: 15px;
                            transition: transform 0.2s;
                        }
                        .next-payments-dropdown {
                            display: none;
                            position: absolute;
                            z-index: 10;
                            left: 0;
                            top: 110%;
                            background: #fff;
                            border: 1px solid #e5e7eb;
                            border-radius: 10px;
                            box-shadow: 0 2px 12px rgba(0,0,0,0.10);
                            min-width: 230px;
                            max-width: 340px;
                            max-height: 260px;
                            overflow-y: auto;
                            padding: 0;
                        }
                        .next-payment-lot-row {
                            padding: 10px 16px 8px 16px;
                            border-bottom: 1px solid #f3f4f6;
                            font-size: 13.5px;
                            white-space: normal;
                        }
                        .next-payment-lot-row:last-child {
                            border-bottom: none;
                        }
                        </style>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="content-box">
                <div class="box-header">Document Progress</div>
                <div class="card-grid" style="margin-bottom: 18px;">
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['total']; ?></h3><span>Total Documents</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['approved']; ?></h3><span>Approved</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['pending']; ?></h3><span>Pending</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['rejected']; ?></h3><span>Rejected</span></div></div>
                </div>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo (int)$documentStats['progress_percent']; ?>%;"></div>
                    </div>
                    <p class="progress-text">Approval Progress: <strong><?php echo (int)$documentStats['progress_percent']; ?>%</strong></p>
                </div>
            </div>

            <div class="content-box">
                <div class="box-header">Recent Viewings & Activity</div>
                <?php if(empty($recentActivities)): ?>
                    <p style="color:var(--muted); text-align:center;">No recent activity.</p>
                <?php else: ?>
                    <ul class="notification-list">
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-calendar-day"></i></div>
                            <div class="notif-content">
                                <strong>Viewing for Lot <?php echo h($act['lot_no']); ?></strong>
                                <span><?php echo date('F d, Y h:i A', strtotime($act['preferred_at'])); ?> • Status: <?php echo h($act['status']); ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div id="profile" class="section">
            <h2>My Profile</h2>
            <span class="subtitle">Manage your personal information</span>

            <div class="content-box">
                <?php if (!empty($profile_update_success)): ?>
                    <div id="profile-update-success" class="flash-message" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:10px 12px; border-radius:8px; margin-bottom:16px;">
                        <?php echo h($profile_update_success); ?>
                    </div>
                <?php elseif (!empty($profile_update_error)): ?>
                    <div style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:10px 12px; border-radius:8px; margin-bottom:16px;">
                        <?php echo h($profile_update_error); ?>
                    </div>
                <?php endif; ?>

                <form id="profile-form" method="post" class="form-grid">
                    <input type="hidden" name="profile_action" value="update_profile">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo h($user['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo h($user['last_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo h($user['username']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo h($user['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="mobile" class="form-control" value="<?php echo h($user['mobile']); ?>">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" value="<?php echo h($user['address']); ?>">
                    </div>
                    <div class="form-group" style="grid-column: span 2; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="properties" class="section">
            <div class="properties-section">
                <div class="properties-hero">
                    <div class="properties-hero-copy">
                        <div class="properties-kicker"><i class="fa fa-map-marker-alt"></i> Property Portfolio</div>
                        <h2>My Properties</h2>
                        <p>Review every lot linked to your account with its barangay, block, lot number, size, price, and payment status in one place.</p>
                    </div>
                    <div class="properties-metrics">
                        <div class="property-metric">
                            <strong><?php echo (int)$lotsOwned; ?></strong>
                            <span>Total Properties</span>
                        </div>
                        <div class="property-metric">
                            <strong><?php echo (int)$reservedLots; ?></strong>
                            <span>Reserved Lots</span>
                        </div>
                    </div>
                </div>

                <div class="properties-grid">
                    <?php if(empty($listings)): ?>
                        <div class="content-box" style="grid-column: 1 / -1; text-align:center;">
                            <p>No properties found linked to this account.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($listings as $lot): ?>
                        <?php
                            $lotLabel = formatLotLocationLabel($lot);
                            $effectiveStatus = strtolower(trim((string)($lot['status'] ?? '')));
                            $paymentType = strtolower(trim((string)($lot['payment_type'] ?? '')));
                            $lotPriceValue = (float)($lot['lot_price'] ?? 0);
                            $lotId = (int)($lot['id'] ?? 0);
                            $lotPaidSoFar = (float)($paymentsByLot[$lotId] ?? 0);
                            $downPaymentAmount = (float)($lot['down_payment_amount'] ?? 0);
                            $totalPaidDisplay = (float)($paidTotalsByLot[$lotId] ?? ($lotPaidSoFar + $downPaymentAmount));
                            $remainingBalance = max(0, $lotPriceValue - $totalPaidDisplay);
                            $nextDueAmount = computeNextInstallmentDueAmount($lot, $totalPaidDisplay);
                            $isFullyPaidByAmount = ($lotPriceValue > 0 && $remainingBalance <= 0.009);

                            if ($isFullyPaidByAmount || $paymentType === 'fully paid' || $effectiveStatus === 'paid' || $effectiveStatus === 'sold') {
                                $effectiveStatus = 'paid';
                                $paymentType = 'fully paid';
                            } elseif ($paymentType === 'down payment' && in_array($effectiveStatus, ['reserved', 'reservation', 'installment', 'installments'], true)) {
                                $effectiveStatus = 'installment';
                            }

                            $statusLabel = match($effectiveStatus) {
                                'available'   => ['Available',   '#22c55e', '#dcfce7'],
                                'reserved'    => ['Under Reservation', '#f59e0b', '#fef3c7'],
                                'reservation' => ['Under Reservation', '#f59e0b', '#fef3c7'],
                                'installment' => ['Installment', '#3b82f6', '#dbeafe'],
                                'installments' => ['Installment', '#3b82f6', '#dbeafe'],
                                'paid'        => ['Fully Paid',  '#8b5cf6', '#ede9fe'],
                                'sold'        => ['Sold',        '#64748b', '#f1f5f9'],
                                default       => [ucfirst($lot['status'] ?? 'N/A'), '#64748b', '#f1f5f9'],
                            };
                            $nextDueDateObj = ($effectiveStatus === 'installment') ? computeNextLotDueDate($lot, $totalPaidDisplay) : null;
                            $rawNextAmount = (float)($lot['payment_amount'] ?? 0);
                            $nextPayAmt  = ($effectiveStatus === 'installment' && $remainingBalance > 0) ? '₱' . number_format(($nextDueAmount > 0 ? $nextDueAmount : $rawNextAmount), 2) : null;
                            $nextPayDate = ($effectiveStatus === 'installment' && $nextDueDateObj instanceof DateTime) ? $nextDueDateObj->format('M d, Y') : null;
                            $nextDueDataValue = ($nextDueDateObj instanceof DateTime) ? $nextDueDateObj->format('Y-m-d') : '';
                            $isInstallmentCard = ($paymentType === 'down payment' && in_array($effectiveStatus, ['installment', 'installments'], true));
                        ?>
                        <article class="property-card" style="border-top-color: <?php echo $statusLabel[1]; ?>;">
                            <div class="property-card-top">
                                <div style="min-width:0;">
                                    <span class="property-status" style="background:<?php echo $statusLabel[2]; ?>; color:<?php echo $statusLabel[1]; ?>;"><?php echo $statusLabel[0]; ?></span>
                                    <h4 class="property-title"><?php echo h($lotLabel); ?></h4>
                                </div>
                                <div style="width:44px; height:44px; border-radius:14px; background:rgba(20,83,45,0.08); display:flex; align-items:center; justify-content:center; color:var(--green); flex-shrink:0;">
                                    <i class="fa fa-house"></i>
                                </div>
                            </div>

                            <div style="padding:0 20px;">
                                <?php if (!empty($lot['location_name'])): ?>
                                <div class="property-location" style="margin-bottom:12px;">
                                    <i class="fa fa-location-dot"></i>
                                    <span><strong>Barangay:</strong> <?php echo h($lot['location_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="property-location">
                                    <i class="fa fa-road"></i>
                                    <span>Block <?php echo h($lot['block_number']); ?>, Lot <?php echo h($lot['lot_number']); ?></span>
                                </div>
                            </div>

                            <div class="property-details">
                                <div class="property-detail">
                                    <label>Size</label>
                                    <strong><?php echo h($lot['lot_size']); ?> sqm</strong>
                                </div>
                                <div class="property-detail">
                                    <label>Price</label>
                                    <strong class="value-green">₱<?php echo number_format($lot['lot_price'], 2); ?></strong>
                                </div>
                                <div class="property-detail">
                                    <label>Payment Type</label>
                                    <strong><?php echo h($lot['payment_type'] ?? 'N/A'); ?></strong>
                                </div>
                                <div class="property-detail">
                                    <label>Remaining</label>
                                    <strong class="value-green"><?php echo $remainingBalance > 0 ? '₱' . number_format($remainingBalance, 2) : 'Fully Paid'; ?></strong>
                                </div>
                            </div>

                            <?php if ($nextPayAmt): ?>
                            <div style="padding:0 20px;">
                                <div style="background:linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); padding:14px 16px; border-radius:14px; border:1px solid #bbf7d0; display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                                    <div>
                                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:#16a34a; font-weight:800; margin-bottom:6px;">Next Payment</div>
                                        <div style="font-size:15px; font-weight:700; color:var(--text);"><?php echo $nextPayAmt; ?></div>
                                    </div>
                                    <?php if ($nextPayDate): ?>
                                    <div style="font-size:12px; color:var(--muted); text-align:right;">Due<br><strong style="color:var(--text);"><?php echo $nextPayDate; ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php
                                $lotId = (int)($lot['id'] ?? 0);
                                $hasLotContract =
                                    !empty($approvedContractsByLot[$lotId])
                                    || !empty($approvedAgreementsByLot[$lotId]);
                            ?>

                            <div class="property-card-actions">
                                <?php if ($isInstallmentCard): ?>
                                <button
                                    class="btn btn-secondary"
                                    data-lot-id="<?php echo (int)($lot['id'] ?? 0); ?>"
                                    data-lot-label="<?php echo h($lotLabel); ?>"
                                    data-lot-price="<?php echo h((string)(float)($lot['lot_price'] ?? 0)); ?>"
                                    data-down-payment="<?php echo h((string)(float)($lot['down_payment_amount'] ?? 0)); ?>"
                                    data-installment-amount="<?php echo h((string)(float)($lot['payment_amount'] ?? 0)); ?>"
                                    data-term-years="<?php echo h((string)(int)($lot['payment_term_years'] ?? 0)); ?>"
                                    data-due-day="<?php echo h((string)(int)($lot['payment_due_day'] ?? 0)); ?>"
                                    data-next-due="<?php echo h((string)$nextDueDataValue); ?>"
                                    data-paid-so-far="<?php echo h((string)$totalPaidDisplay); ?>"
                                    data-remaining-balance="<?php echo h((string)$remainingBalance); ?>"
                                    data-has-contract="<?php echo $hasLotContract ? '1' : '0'; ?>"
                                    onclick="openInstallmentDetailsModal(this)">View Installment Plan</button>
                                <?php else: ?>
                                <button class="btn btn-secondary" onclick="switchTab('payments',document.querySelector('.nav-link[href=\'#payments\']')); loadLotPayments();">View Payments</button>
                                <?php endif; ?>

                                <?php if ($hasLotContract): ?>
                                <button class="btn btn-primary" onclick='openLotContractModal(<?php echo (int)$lotId; ?>, <?php echo json_encode((string)$lotLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>View Contract</button>
                                <?php endif; ?>
                                <button class="btn btn-secondary" onclick='openLotHistoryModal(<?php echo (int)$lotId; ?>, <?php echo json_encode((string)$lotLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>View History</button>
                                <?php if ($remainingBalance > 0 && !in_array($effectiveStatus, ['available', 'paid', 'sold'], true)): ?>
                                <button class="btn btn-danger" onclick='openSurrenderModal(<?php echo (int)$lotId; ?>, <?php echo json_encode((string)$lotLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Surrender Lot</button>
                                <?php endif; ?>

                                <?php
                                    $tvLotId = (int)($lot['id'] ?? 0);
                                    if ($effectiveStatus === 'paid' && isset($lotTurnovers[$tvLotId])):
                                        $tv = $lotTurnovers[$tvLotId];
                                ?>
                                <div style="background:#f5f3ff; border:1px solid #e9d5ff; border-radius:14px; padding:14px 16px;">
                                    <strong style="color:#6d28d9; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Title &amp; Turnover</strong>
                                    <div style="font-size:13px; margin-top:8px; color:var(--text); line-height:1.45;">
                                        Turnover Date: <strong><?php echo h(!empty($tv['turnover_date']) ? date('M d, Y', strtotime($tv['turnover_date'])) : 'Pending'); ?></strong>
                                    </div>
                                    <div style="font-size:13px; margin-top:4px; color:var(--text); line-height:1.45;">
                                        Title Released:
                                        <strong style="color:<?php echo ($tv['title_released'] ? '#16a34a' : '#d97706'); ?>">
                                            <?php echo $tv['title_released'] ? 'Yes' : 'Pending'; ?>
                                        </strong>
                                    </div>
                                    <?php if (!empty($tv['remarks'])): ?>
                                    <div style="font-size:12px; margin-top:6px; color:var(--muted);"><?php echo h($tv['remarks']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="payments" class="section">
            <h2>My Payments</h2>
            <span class="subtitle">Payment history for your lots</span>

            <div id="payments-loading" style="text-align:center; padding:40px; color:var(--muted);">
                <i class="fa fa-spinner fa-spin" style="font-size:24px;"></i><br><br>Loading payment history…
            </div>

            <div id="payments-empty" style="display:none; text-align:center; padding:40px; color:var(--muted);">
                <i class="fa fa-receipt" style="font-size:40px; opacity:.3; margin-bottom:12px; display:block;"></i>
                No payment records found.
            </div>

            <div id="payments-content" style="display:none;"></div>
        </div>

        <div id="viewings" class="section">
            <h2>My Viewings</h2>
            <span class="subtitle">Scheduled visits to properties</span>

            <div style="margin-bottom:14px; text-align:right;">
                <button class="btn btn-primary" onclick="openNewViewingModal()">
                    <i class="fa fa-plus"></i> Request New Viewing
                </button>
            </div>

            <div class="content-box">
                <table>
                    <thead>
                        <tr><th>Lot</th><th>Date & Time</th><th>Agent</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($upcomingViewings)): ?>
                            <tr><td colspan="4" style="text-align:center;">No viewings found.</td></tr>
                        <?php else: ?>
                            <?php foreach($upcomingViewings as $v): ?>
                            <tr>
                                <td><?php echo h($v['lot_no']); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($v['preferred_at'])); ?></td>
                                <td>
                                    <?php echo h(($v['agent_first']??'') . ' ' . ($v['agent_last']??'')); ?>
                                </td>
                                                                <td>
                                                                    <?php
                                                                        $status = strtolower($v['status']);
                                                                        $badgeClass = 'badge ';
                                                                        switch ($status) {
                                                                            case 'cancelled':
                                                                                $badgeClass .= 'cancelled';
                                                                                break;
                                                                            case 'pending':
                                                                                $badgeClass .= 'pending';
                                                                                break;
                                                                            case 'scheduled':
                                                                                $badgeClass .= 'scheduled';
                                                                                break;
                                                                            case 'completed':
                                                                            case 'complete':
                                                                                $badgeClass .= 'completed';
                                                                                break;
                                                                            default:
                                                                                $badgeClass .= 'scheduled';
                                                                        }
                                                                    ?>
                                                                    <span class="<?php echo $badgeClass; ?>"><?php echo h($v['status']); ?></span>
                                                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="documents" class="section">
            <h2>Documents</h2>
            <span class="subtitle">Contracts, agreements, and payment records</span>
            <div class="content-box">
                <div class="box-header">Document Progress</div>
                <table>
                    <thead>
                        <tr><th>Total</th><th>Approved</th><th>Pending</th><th>Rejected</th><th>Progress</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo (int)$documentStats['total']; ?></td>
                            <td><?php echo (int)$documentStats['approved']; ?></td>
                            <td><?php echo (int)$documentStats['pending']; ?></td>
                            <td><?php echo (int)$documentStats['rejected']; ?></td>
                            <td><?php echo (int)$documentStats['progress_percent']; ?>%</td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top:10px; color:var(--muted); font-size:13px;">Tip: Upload documents via your existing document upload flow. This section reflects current review statuses.</p>
            </div>

            <div class="content-box">
                <div class="box-header">Copy of Contracts</div>
                <table>
                    <thead>
                        <tr><th>File Name</th><th>Type</th><th>Status</th><th>Uploaded</th><th>File</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contractDocs)): ?>
                            <tr><td colspan="5" style="text-align:center;">No contract copies uploaded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($contractDocs as $doc): ?>
                            <tr>
                                <td><?php echo h($doc['file_name'] ?? 'Untitled'); ?></td>
                                <td><?php echo h($doc['doc_type'] ?? 'Contract'); ?></td>
                                <td><?php echo h($doc['status'] ?? 'Pending'); ?></td>
                                <td><?php echo !empty($doc['uploaded_at']) ? h(date('M d, Y h:i A', strtotime($doc['uploaded_at']))) : 'N/A'; ?></td>
                                <td>
                                    <?php if (!empty($doc['file_path'])): ?>
                                        <a href="<?php echo h($doc['file_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 10px; font-size:12px;">Open</a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="content-box">
                <div class="box-header">Copy of Agreements</div>
                <table>
                    <thead>
                        <tr><th>File Name</th><th>Type</th><th>Status</th><th>Uploaded</th><th>File</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agreementDocs)): ?>
                            <tr><td colspan="5" style="text-align:center;">No agreement copies uploaded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($agreementDocs as $doc): ?>
                            <tr>
                                <td><?php echo h($doc['file_name'] ?? 'Untitled'); ?></td>
                                <td><?php echo h($doc['doc_type'] ?? 'Agreement'); ?></td>
                                <td><?php echo h($doc['status'] ?? 'Pending'); ?></td>
                                <td><?php echo !empty($doc['uploaded_at']) ? h(date('M d, Y h:i A', strtotime($doc['uploaded_at']))) : 'N/A'; ?></td>
                                <td>
                                    <?php if (!empty($doc['file_path'])): ?>
                                        <a href="<?php echo h($doc['file_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 10px; font-size:12px;">Open</a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="content-box">
                <div class="box-header">Previous Payments</div>
                <table>
                    <thead>
                        <tr><th>Lot</th><th>Date</th><th>Amount</th><th>Method</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($previousPayments)): ?>
                            <tr><td colspan="5" style="text-align:center;">No previous payment records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($previousPayments as $p): ?>
                            <?php $paymentLabel = formatLotLocationLabel($p); ?>
                            <tr>
                                <td><?php echo h($paymentLabel); ?></td>
                                <td><?php echo !empty($p['payment_date']) ? h(date('M d, Y', strtotime($p['payment_date']))) : 'N/A'; ?></td>
                                <td>₱<?php echo number_format((float)($p['amount'] ?? 0), 2); ?></td>
                                <td><?php echo h($p['payment_method'] ?? 'N/A'); ?></td>
                                <td><?php echo h($p['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="notifications" class="section">
            <h2>Notifications</h2>
            <span class="subtitle">System alerts</span>
            <div class="content-box">
                <ul class="notification-list">
                    <?php if(!empty($systemNotifications)): ?>
                        <?php foreach($systemNotifications as $n): ?>
                        <?php
                            $isRead = array_key_exists('is_read', $n) ? ((int)($n['is_read'] ?? 0) === 1) : false;
                            $notifId = (int)($n['id'] ?? 0);
                            $notifSource = (string)($n['notif_source'] ?? '');
                        ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-bell"></i></div>
                            <div class="notif-content">
                                <strong><?php echo h($n['title'] ?? 'System Notification'); ?></strong>
                                <span><?php echo h($n['message'] ?? ''); ?></span>
                                <?php if (!empty($n['created_at'])): ?>
                                    <small style="display:block; color:var(--muted); margin-top:4px;"><?php echo h(date('F d, Y h:i A', strtotime($n['created_at']))); ?></small>
                                <?php endif; ?>
                                <?php if ($notifId > 0 && $notifSource !== ''): ?>
                                    <button
                                        type="button"
                                        class="btn btn-secondary notif-view-btn"
                                        style="margin-top:8px; padding:6px 10px; font-size:12px;"
                                        onclick="viewNotificationMessage(this, <?php echo $notifId; ?>, '<?php echo h($notifSource); ?>')"
                                        <?php echo $isRead ? 'disabled' : ''; ?>
                                    >
                                        <?php echo $isRead ? 'Viewed' : 'Mark as Read'; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php elseif(!empty($recentActivities)): ?>
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-bell"></i></div>
                            <div class="notif-content">
                                <strong>System Notification</strong>
                                <span>Your viewing for Lot <?php echo h($act['lot_no']); ?> is <?php echo h($act['status']); ?>.</span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li style="padding:15px; text-align:center;">No new notifications.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div id="agent" class="section">
            <h2>My Agent</h2>
            <span class="subtitle">Your assigned sales agent</span>
            <div class="content-box">
                <?php if($agent): ?>
                <div class="agent-card">
                    <img src="assets/Default_photo.jpg" class="agent-img" alt="Agent">
                    <div class="agent-details">
                        <h3><?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?></h3>
                        <p><i class="fa fa-envelope"></i> <?php echo h($agent['email']); ?></p>
                        <div style="margin-top:10px;">
                            <button class="btn btn-primary btn-sm" onclick="openMessageModal(<?php echo (int)$agent['id']; ?>)">Message Agent</button>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px; border:1px solid #e5e7eb; border-radius:12px; padding:16px; background:#fafafa;">
                    <h3 style="margin:0 0 8px 0; font-size:18px;">Rate Your Agent</h3>
                    <p style="margin:0 0 12px 0; color:var(--muted); font-size:13px;">
                        Average rating: <strong><?php echo number_format((float)$agentReviewSummary['average_rating'], 1); ?>/5</strong>
                        (<?php echo (int)$agentReviewSummary['review_count']; ?> review<?php echo ((int)$agentReviewSummary['review_count'] === 1 ? '' : 's'); ?>)
                    </p>

                    <div class="form-group" style="max-width:220px;">
                        <label for="agent_rating">Your Rating</label>
                        <select id="agent_rating" class="form-control">
                            <option value="">Select rating</option>
                            <option value="5" <?php echo ((int)($myAgentReview['rating'] ?? 0) === 5 ? 'selected' : ''); ?>>5 - Excellent</option>
                            <option value="4" <?php echo ((int)($myAgentReview['rating'] ?? 0) === 4 ? 'selected' : ''); ?>>4 - Very Good</option>
                            <option value="3" <?php echo ((int)($myAgentReview['rating'] ?? 0) === 3 ? 'selected' : ''); ?>>3 - Good</option>
                            <option value="2" <?php echo ((int)($myAgentReview['rating'] ?? 0) === 2 ? 'selected' : ''); ?>>2 - Fair</option>
                            <option value="1" <?php echo ((int)($myAgentReview['rating'] ?? 0) === 1 ? 'selected' : ''); ?>>1 - Poor</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-top:8px;">
                        <label for="agent_review_text">Your Review (optional)</label>
                        <textarea id="agent_review_text" class="form-control" rows="4" maxlength="2000" placeholder="Share your experience with your agent..."><?php echo h($myAgentReview['review_text'] ?? ''); ?></textarea>
                    </div>

                    <?php if (!empty($myAgentReview['updated_at'])): ?>
                    <p style="margin:8px 0 0 0; color:var(--muted); font-size:12px;">
                        Last updated: <?php echo h(date('F d, Y h:i A', strtotime((string)$myAgentReview['updated_at']))); ?>
                    </p>
                    <?php endif; ?>

                    <div style="display:flex; align-items:center; gap:12px; margin-top:12px;">
                        <button class="btn btn-primary" onclick="submitAgentReview(<?php echo (int)$agent['id']; ?>)">Submit Review</button>
                        <div id="agentReviewStatus" style="font-size:13px; font-weight:600;"></div>
                    </div>
                </div>
                <?php else: ?>
                <p style="text-align:center;">No agent assigned to you yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <!-- Message Modal -->
    <div id="installmentDetailsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Installment Plan</h3>
                <button class="modal-close-btn" onclick="closeInstallmentDetailsModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="display:grid; gap:12px;">
                    <div style="padding:14px 16px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Property</div>
                        <div id="installment-modal-lot-label" style="font-size:18px; font-weight:700; color:var(--green);"></div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;">
                        <div style="padding:14px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                            <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Lot Price</div>
                            <div id="installment-modal-lot-price" style="font-size:18px; font-weight:700; color:#0f172a;"></div>
                        </div>
                        <div style="padding:14px; background:#eff6ff; border-radius:10px; border:1px solid #bfdbfe;">
                            <div style="font-size:12px; color:#2563eb; margin-bottom:4px;">Monthly Installment</div>
                            <div id="installment-modal-monthly" style="font-size:18px; font-weight:700; color:#1d4ed8;"></div>
                        </div>
                        <div style="padding:14px; background:#f0fdf4; border-radius:10px; border:1px solid #bbf7d0;">
                            <div style="font-size:12px; color:#15803d; margin-bottom:4px;">Down Payment</div>
                            <div id="installment-modal-down-payment" style="font-size:18px; font-weight:700; color:#166534;"></div>
                        </div>
                        <div style="padding:14px; background:#fff7ed; border-radius:10px; border:1px solid #fed7aa;">
                            <div style="font-size:12px; color:#c2410c; margin-bottom:4px;">Remaining Balance</div>
                            <div id="installment-modal-balance" style="font-size:18px; font-weight:700; color:#c2410c;"></div>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;">
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Payment Term</div>
                            <div id="installment-modal-term" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Monthly Due Day</div>
                            <div id="installment-modal-due-day" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Next Due Date</div>
                            <div id="installment-modal-next-due" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Total Paid</div>
                            <div id="installment-modal-paid" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                    </div>
                    <div style="margin-top:4px; border-top:1px solid #e5e7eb; padding-top:14px; min-height:0;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;">
                            <div style="font-size:15px; font-weight:700; color:#111827;">Monthly Schedule</div>
                            <div id="installment-schedule-summary" style="font-size:12px; color:#64748b;"></div>
                        </div>
                        <div id="installment-schedule-loading" style="display:none; color:#64748b; font-size:13px; padding:10px 0;">Loading installment schedule...</div>
                        <div id="installment-schedule-empty" style="display:none; color:#64748b; font-size:13px; padding:10px 0;">No installment schedule available.</div>
                        <div id="installment-schedule-list" style="display:grid; gap:10px; max-height:320px; overflow-y:auto; padding-right:4px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="installment-view-contract-btn" class="btn btn-primary" style="display:none;">View Contract</button>
                <button class="btn btn-secondary" onclick="closeInstallmentDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="lotContractModal" class="modal-overlay lot-contract-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="lot-contract-title">View Contract</h3>
                <button class="modal-close-btn" onclick="closeLotContractModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="lot-contract-toolbar">
                    <select id="lot-contract-select" class="lot-contract-selector" onchange="previewLotContractDoc(this.value)">
                        <option value="">Select contract/agreement</option>
                    </select>
                    <a id="lot-contract-open-link" href="#" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:8px 12px; font-size:12px;">Open in New Tab</a>
                </div>
                <div id="lot-contract-file-meta" class="lot-contract-meta"></div>
                <div id="lot-contract-empty" style="display:none; padding:12px; font-size:13px; color:#6b7280; border:1px solid #e5e7eb; border-radius:10px; background:#f9fafb;">No approved contract/agreement found for this lot.</div>
                <div class="lot-contract-frame-wrap">
                    <iframe id="lot-contract-frame" title="Contract Viewer"></iframe>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLotContractModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="messageModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Message to Agent</h3>
                <button class="modal-close-btn" onclick="closeMessageModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Agent</label>
                    <input type="text" id="modal_agent_name" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Your Message</label>
                    <textarea id="modal_message_text" class="form-control" placeholder="Type your message..."></textarea>
                </div>
                <div id="modal_msg_status" style="font-weight:600; margin-bottom:12px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeMessageModal()">Cancel</button>
                <button class="btn btn-primary" onclick="sendModalMessage()">Send Message</button>
            </div>
        </div>
    </div>

    <div id="lotHistoryModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Lot History</h3>
                <button class="modal-close-btn" onclick="closeLotHistoryModal()">×</button>
            </div>
            <div class="modal-body">
                <div id="lot-history-header" style="font-size:15px; font-weight:700; color:var(--green); margin-bottom:12px;"></div>
                <div id="lot-history-loading" style="padding:14px 0; color:#64748b;">Loading history...</div>
                <div id="lot-history-empty" style="display:none; padding:14px 0; color:#64748b;">No history records found.</div>
                <div id="lot-history-content" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLotHistoryModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="surrenderModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Surrender Lot</h3>
                <button class="modal-close-btn" onclick="closeSurrenderModal()">×</button>
            </div>
            <div class="modal-body">
                <div id="surrender-modal-lot-label" style="font-size:16px; font-weight:700; color:var(--green); margin-bottom:14px;"></div>
                <p style="margin-bottom:16px; color:var(--muted);">You are about to surrender this lot. Ownership will be returned to inventory, 20% of the amount paid will be refunded to you, and the lot will become available for a new buyer.</p>
                <div style="background:#f8fafc; border:1px solid #c7d2fe; border-radius:12px; padding:16px; margin-bottom:16px;">
                    <div style="font-size:12px; color:#475569; margin-bottom:8px;">Estimated Refund</div>
                    <div id="surrender-modal-refund-amount" style="font-size:22px; font-weight:700; color:#0f172a;">₱0.00</div>
                    <div style="font-size:12px; color:#64748b; margin-top:8px;">Company retains 80% of the amount paid. This action is final.</div>
                </div>
                <div style="margin-bottom:16px;">
                    <label for="surrender-reason" style="display:block; font-size:13px; font-weight:600; color:#111827; margin-bottom:8px;">Reason for surrender</label>
                    <textarea id="surrender-reason" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:12px; font-size:14px; color:#111827; resize:vertical;" placeholder="Explain why you need to surrender this lot."></textarea>
                </div>
                <div id="surrender-modal-warning" style="color:#b91c1c; font-size:13px; font-weight:600;">This action cannot be undone. Proceed only if you want to return the lot to inventory.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeSurrenderModal()">Cancel</button>
                <button id="confirm-surrender-btn" class="btn btn-danger" onclick="confirmSurrender()">Confirm Surrender</button>
            </div>
        </div>
    </div>

    <!-- New Viewing Request Modal -->
    <div id="newViewingModal" class="modal-overlay" style="display:none;">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3><i class="fa fa-calendar-plus"></i> Request New Viewing</h3>
                <button class="modal-close-btn" onclick="closeNewViewingModal()">×</button>
            </div>
            <div class="modal-body">
                <div id="newViewingStatus" style="display:none; padding:10px 12px; border-radius:6px; margin-bottom:12px; font-weight:600;"></div>
                <div class="form-grid" style="gap:12px;">
                    <div class="form-group">
                        <label>First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="nv_first_name" class="form-control" value="<?php echo h($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="nv_last_name" class="form-control" value="<?php echo h($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email <span style="color:#dc2626;">*</span></label>
                        <input type="email" id="nv_email" class="form-control" value="<?php echo h($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone <span style="color:#dc2626;">*</span></label>
                        <input type="tel" id="nv_phone" class="form-control" value="<?php echo h($user['mobile'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Preferred Date &amp; Time <span style="color:#dc2626;">*</span></label>
                        <input type="datetime-local" id="nv_datetime" class="form-control" min="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Notes (optional)</label>
                        <textarea id="nv_notes" class="form-control" rows="3" placeholder="Any specific lot or area you want to view?"></textarea>
                    </div>
                    <?php if ($agent): ?>
                    <input type="hidden" id="nv_agent_id" value="<?php echo (int)$agent['id']; ?>">
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Assigned Agent</label>
                        <input type="text" class="form-control" value="<?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?>" readonly style="background:#f0fdf4;">
                    </div>
                    <?php else: ?>
                    <input type="hidden" id="nv_agent_id" value="">
                    <div class="form-group" style="grid-column:span 2;">
                        <p style="color:#d97706; font-size:13px; background:#fffbeb; padding:10px; border-radius:6px; border-left:3px solid #f59e0b;">⚠️ No agent is currently assigned to you. Your request will be sent to the office for agent assignment.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNewViewingModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitNewViewingRequest()"><i class="fa fa-paper-plane"></i> Submit Request</button>
            </div>
        </div>
    </div>
</div>

<script>
const lotContractDocsByLot = <?php echo json_encode($approvedLegalDocsByLot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
let activeLotContractDocs = [];

function switchTab(id, link) {
    // Prevent default anchor link behavior (which causes scrolling)
    event.preventDefault();
    
    // Hide all sections
    document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
    // Deactivate nav links
    document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
    
    // Show target section
    document.getElementById(id).classList.add('active');
    // Activate clicked link
    link.classList.add('active');
}

function viewNotificationMessage(button, notifId, source) {
    const id = Number(notifId || 0);
    const src = String(source || '').trim();
    if (!button || !id || !src) return;

    fetch('?action=mark_notification_read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, source: src })
    })
    .then(r => r.json())
    .then(data => {
        if (!data || !data.success) {
            return;
        }

        button.textContent = 'Viewed';
        button.disabled = true;

        const badge = document.getElementById('user-notifications-badge');
        if (badge) {
            const current = String(badge.textContent || '').trim();
            let value = current.endsWith('+') ? 99 : parseInt(current, 10);
            if (!Number.isFinite(value)) value = 0;
            value = Math.max(0, value - 1);

            if (value <= 0) {
                badge.style.display = 'none';
            } else {
                badge.textContent = value > 99 ? '99+' : String(value);
                badge.style.display = 'inline-flex';
            }
        }
    })
    .catch(() => {
        // Keep existing UI state if request fails.
    });
}

function sendAgentMessage() {
    const agentIdEl = document.getElementById('msg_agent_id');
    const msgEl = document.getElementById('msg_agent_text');
    const statusEl = document.getElementById('agentMsgStatus');

    if (!agentIdEl || !msgEl || !statusEl) return;

    const agent_id = parseInt(agentIdEl.value || '0', 10);
    const message = msgEl.value.trim();

    if (!agent_id || !message) {
        statusEl.innerHTML = '<span style="color:#dc2626;">Please select an agent and type your message.</span>';
        return;
    }

    statusEl.innerHTML = '<span style="color:#0284c7;">Sending...</span>';

    fetch('?action=send_agent_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agent_id, message })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<span style="color:#166534;">' + (data.message || 'Message sent.') + '</span>';
            msgEl.value = '';
        } else {
            statusEl.innerHTML = '<span style="color:#dc2626;">' + (data.message || 'Failed to send message.') + '</span>';
        }
    })
    .catch(() => {
        statusEl.innerHTML = '<span style="color:#dc2626;">Request failed. Please try again.</span>';
    });
}

// Modal functions
let currentAgentId = null;

function formatPeso(value) {
    const amount = Number(value || 0);
    return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDueDate(value) {
    if (!value) return 'Not set';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatMonthLabel(value) {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) return 'Unknown month';
    return value.toLocaleDateString('en-PH', { year: 'numeric', month: 'long' });
}

function parseDateOnly(value) {
    if (!value) return null;
    const normalizedValue = String(value).trim().slice(0, 10);
    const parts = normalizedValue.split('-').map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
    return new Date(parts[0], parts[1] - 1, parts[2], 12, 0, 0, 0);
}

function resolveDateInput(value) {
    const exactDate = parseDateOnly(value);
    if (exactDate) return exactDate;

    if (!value) return null;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return null;
    return new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate(), 12, 0, 0, 0);
}

function resolveInstallmentAnchorDate(plan, transactions, fallbackDeadline = '') {
    const fromPlan = resolveDateInput(plan?.payment_deadline || fallbackDeadline || '');
    if (fromPlan) {
        return fromPlan;
    }

    if (Array.isArray(transactions) && transactions.length) {
        const dates = transactions
            .map(tx => resolveDateInput(tx?.payment_date || ''))
            .filter(date => date instanceof Date && !Number.isNaN(date.getTime()));

        if (dates.length) {
            dates.sort((a, b) => a - b);
            return dates[0];
        }
    }

    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1, 12, 0, 0, 0);
}

function buildDueDate(baseDate, monthOffset, dueDay) {
    const year = baseDate.getFullYear();
    const month = baseDate.getMonth() + monthOffset;
    const firstOfMonth = new Date(year, month, 1, 12, 0, 0, 0);
    const lastDay = new Date(firstOfMonth.getFullYear(), firstOfMonth.getMonth() + 1, 0).getDate();
    return new Date(firstOfMonth.getFullYear(), firstOfMonth.getMonth(), Math.min(dueDay, lastDay), 12, 0, 0, 0);
}

function resolveDueDay(plan, startDate) {
    const declaredDueDay = Number(plan?.payment_due_day || 0);
    if (declaredDueDay > 0) {
        return declaredDueDay;
    }

    if (startDate instanceof Date && !Number.isNaN(startDate.getTime())) {
        const derivedDueDay = startDate.getDate();
        if (derivedDueDay > 0) {
            return derivedDueDay;
        }
    }

    return 1;
}

function resolveInstallmentMeta(plan, installmentTransactions) {
    const termYears = Number(plan?.payment_term_years || 0);
    const lotPrice = Number(plan?.lot_price || 0);
    const downPayment = Number(plan?.down_payment_amount || 0);
    const installmentBalance = Math.max(lotPrice - downPayment, 0);
    const txCount = Array.isArray(installmentTransactions) ? installmentTransactions.length : 0;

    let totalMonths = termYears > 0 ? termYears * 12 : 0;
    let monthlyAmount = Number(plan?.payment_amount || 0);

    if (totalMonths <= 0 && monthlyAmount > 0) {
        if (installmentBalance > 0) {
            totalMonths = Math.ceil(installmentBalance / monthlyAmount);
        } else if (txCount > 0) {
            totalMonths = txCount;
        }
    }

    if (totalMonths <= 0 && installmentBalance > 0) {
        totalMonths = txCount > 0 ? txCount : 1;
    }

    if (monthlyAmount <= 0 && totalMonths > 0 && installmentBalance > 0) {
        monthlyAmount = installmentBalance / totalMonths;
    }

    return {
        totalMonths,
        monthlyAmount,
        installmentBalance,
        lotPrice,
        downPayment
    };
}

function buildEstimatedInstallmentTransactions(plan, paidSoFar) {
    const downPayment = Number(plan?.down_payment_amount || 0);
    const totalPaid = Math.max(0, Number(paidSoFar || 0));
    const installmentPaid = Math.max(0, totalPaid - downPayment);
    if (installmentPaid <= 0) {
        return [];
    }

    const txDate = resolveInstallmentAnchorDate(plan, [], plan?.payment_deadline || '') || new Date();
    const yyyy = txDate.getFullYear();
    const mm = String(txDate.getMonth() + 1).padStart(2, '0');
    const dd = String(txDate.getDate()).padStart(2, '0');

    return [{
        id: 0,
        amount: installmentPaid,
        payment_date: `${yyyy}-${mm}-${dd}`,
        payment_method: 'Estimated',
        remarks: 'Estimated from total paid'
    }];
}

function parseJsonFromResponseText(text) {
    const raw = String(text || '').trim();
    if (!raw) return null;

    try {
        return JSON.parse(raw);
    } catch (_) {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            const candidate = raw.slice(start, end + 1);
            try {
                return JSON.parse(candidate);
            } catch (_) {
                return null;
            }
        }
    }

    return null;
}

function buildInstallmentScheduleRows(plan, installmentTransactions, fallbackDeadline = '') {
    const startDate = resolveInstallmentAnchorDate(plan, installmentTransactions, fallbackDeadline);
    const dueDay = resolveDueDay(plan, startDate);
    const meta = resolveInstallmentMeta(plan, installmentTransactions);
    const totalMonths = meta.totalMonths;
    const monthlyAmount = meta.monthlyAmount;
    const lotPrice = meta.lotPrice;
    const downPayment = meta.downPayment;

    const rows = [];
    if (!startDate || totalMonths <= 0) {
        return { rows, paidCount: 0, totalMonths, startDate };
    }

    const sortedTransactions = Array.isArray(installmentTransactions)
        ? [...installmentTransactions]
            .map(tx => ({
                ...tx,
                amount: Number(tx.amount || 0),
                parsedDate: parseDateOnly(String(tx.payment_date || '').slice(0, 10))
            }))
            .filter(tx => tx.parsedDate && tx.amount > 0)
            .sort((a, b) => a.parsedDate - b.parsedDate || (Number(a.id || 0) - Number(b.id || 0)))
        : [];

    const installmentBalance = lotPrice > 0 ? Math.max(lotPrice - downPayment, 0) : (monthlyAmount * totalMonths);
    if (installmentBalance <= 0) {
        for (let index = 0; index < totalMonths; index += 1) {
            rows.push({
                index,
                dueDate: buildDueDate(startDate, index, dueDay),
                requiredAmount: 0,
                paidAmount: 0,
                isPaid: true,
                paidOn: null
            });
        }

        return { rows, paidCount: totalMonths, totalMonths, startDate };
    }

    const requiredAmounts = [];
    let remainingPlanBalance = installmentBalance;
    for (let index = 0; index < totalMonths; index += 1) {
        const requiredAmount = index === totalMonths - 1
            ? remainingPlanBalance
            : Math.min(monthlyAmount, remainingPlanBalance);
        const normalizedRequiredAmount = Math.max(requiredAmount, 0);
        requiredAmounts.push(normalizedRequiredAmount);
        remainingPlanBalance = Math.max(0, remainingPlanBalance - normalizedRequiredAmount);
    }

    const paidAmounts = requiredAmounts.map(() => 0);
    const completionDates = requiredAmounts.map(() => null);

    const monthIndexByKey = {};
    for (let index = 0; index < totalMonths; index += 1) {
        const dueDate = buildDueDate(startDate, index, dueDay);
        const monthKey = `${dueDate.getFullYear()}-${String(dueDate.getMonth() + 1).padStart(2, '0')}`;
        monthIndexByKey[monthKey] = index;
    }

    sortedTransactions.forEach(tx => {
        let remainingAmount = tx.amount;

        // First, allocate to the actual month of payment to keep monthly status accurate.
        const paymentMonthKey = tx.parsedDate
            ? `${tx.parsedDate.getFullYear()}-${String(tx.parsedDate.getMonth() + 1).padStart(2, '0')}`
            : '';
        const directMonthIndex = Object.prototype.hasOwnProperty.call(monthIndexByKey, paymentMonthKey)
            ? monthIndexByKey[paymentMonthKey]
            : -1;

        if (directMonthIndex >= 0 && remainingAmount > 0) {
            const directNeeded = Math.max(requiredAmounts[directMonthIndex] - paidAmounts[directMonthIndex], 0);
            if (directNeeded > 0) {
                const directUsed = Math.min(directNeeded, remainingAmount);
                paidAmounts[directMonthIndex] += directUsed;
                remainingAmount -= directUsed;

                if (paidAmounts[directMonthIndex] + 0.005 >= requiredAmounts[directMonthIndex] && completionDates[directMonthIndex] === null) {
                    completionDates[directMonthIndex] = tx.parsedDate;
                }
            }
        }

        // Any excess amount is applied to oldest remaining unpaid months.
        for (let index = 0; index < totalMonths && remainingAmount > 0; index += 1) {
            const needed = Math.max(requiredAmounts[index] - paidAmounts[index], 0);
            if (needed <= 0) continue;

            const used = Math.min(needed, remainingAmount);
            paidAmounts[index] += used;
            remainingAmount -= used;

            if (paidAmounts[index] + 0.005 >= requiredAmounts[index] && completionDates[index] === null) {
                completionDates[index] = tx.parsedDate;
            }
        }
    });

    for (let index = 0; index < totalMonths; index += 1) {
        const dueDate = buildDueDate(startDate, index, dueDay);
        const requiredAmount = requiredAmounts[index];
        const paidAmount = paidAmounts[index];
        const remainingAmount = Math.max(0, requiredAmount - paidAmount);
        const isPaid = requiredAmount <= 0 ? true : paidAmount + 0.005 >= requiredAmount;

        rows.push({
            index,
            dueDate,
            requiredAmount,
            paidAmount,
            remainingAmount,
            isPaid,
            paidOn: completionDates[index]
        });
    }

    return {
        rows,
        paidCount: rows.filter(row => row.isPaid).length,
        totalMonths,
        startDate
    };
}

function splitInstallmentTransactions(plan, transactions, fallbackDeadline = '') {
    const startDate = resolveInstallmentAnchorDate(plan, transactions, fallbackDeadline);
    const downPayment = Number(plan?.down_payment_amount || 0);

    const sortedTransactions = Array.isArray(transactions)
        ? [...transactions]
            .map(tx => ({
                ...tx,
                amount: Number(tx.amount || 0),
                parsedDate: parseDateOnly(String(tx.payment_date || '').slice(0, 10))
            }))
            .filter(tx => tx.parsedDate)
            .sort((a, b) => a.parsedDate - b.parsedDate)
        : [];

    let downPaymentRecorded = downPayment > 0;
    let installmentTransactions = sortedTransactions;

    if (downPayment > 0 && installmentTransactions.length) {
        // Only treat a transaction as down payment when there is strong evidence.
        // This avoids incorrectly removing a normal monthly payment that happens to
        // have the same amount as the down payment.
        const downPaymentIndex = installmentTransactions.findIndex(tx => {
            if (Math.abs(tx.amount - downPayment) >= 0.01) {
                return false;
            }

            const remarks = String(tx.remarks || '').toLowerCase();
            if (remarks.includes('down payment') || remarks.includes('reservation')) {
                return true;
            }

            if (startDate) {
                return tx.parsedDate.getTime() < startDate.getTime();
            }

            return false;
        });

        if (downPaymentIndex >= 0) {
            downPaymentRecorded = true;
            installmentTransactions = installmentTransactions.filter((_, index) => index !== downPaymentIndex);
        } else {
            // Fall back to the first matching amount so the balance stays aligned with the contract.
            const fallbackIndex = installmentTransactions.findIndex(tx => Math.abs(tx.amount - downPayment) < 0.01);
            if (fallbackIndex >= 0) {
                downPaymentRecorded = true;
                installmentTransactions = installmentTransactions.filter((_, index) => index !== fallbackIndex);
            } else {
                // No verifiable down payment transaction found: still apply the contractual down payment.
                downPaymentRecorded = true;
            }
        }
    }

    return {
        installmentTransactions,
        downPaymentApplied: downPaymentRecorded ? downPayment : 0
    };
}

function renderInstallmentSchedule(plan, transactions, fallbackDeadline = '') {
    const list = document.getElementById('installment-schedule-list');
    const empty = document.getElementById('installment-schedule-empty');
    const summary = document.getElementById('installment-schedule-summary');
    if (!list || !empty || !summary) return;

    list.innerHTML = '';
    list.style.display = 'grid';
    empty.style.display = 'none';

    const { installmentTransactions } = splitInstallmentTransactions(plan, transactions, fallbackDeadline);
    const meta = resolveInstallmentMeta(plan, installmentTransactions);
    const monthlyAmount = meta.monthlyAmount;
    const { rows: monthRows, paidCount, totalMonths, startDate } = buildInstallmentScheduleRows(plan, installmentTransactions, fallbackDeadline);
    const dueDay = resolveDueDay(plan, startDate);

    if (totalMonths <= 0 || monthRows.length === 0) {
        summary.textContent = 'Schedule unavailable';
        empty.style.display = 'block';
        return;
    }

    const unpaidCount = Math.max(totalMonths - paidCount, 0);
    summary.textContent = `${paidCount} paid | ${unpaidCount} unpaid`;

    const scheduleItems = [];
    monthRows.forEach(row => {
        const dueDate = row.dueDate;
        const isPaid = !!row.isPaid;
        const dueLabel = formatDueDate(dueDate);
        const displayedAmount = isPaid ? (row.requiredAmount || monthlyAmount || 0) : (row.remainingAmount || row.requiredAmount || monthlyAmount || 0);
        scheduleItems.push(`
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1px solid ${isPaid ? '#bbf7d0' : '#e5e7eb'}; background:${isPaid ? '#f0fdf4' : '#ffffff'}; border-radius:10px;">
                <div>
                    <div style="font-size:14px; font-weight:700; color:#111827;">Month ${row.index + 1} · ${escHtml(formatMonthLabel(dueDate))}</div>
                    <div style="font-size:12px; color:#6b7280; margin-top:3px;">Due ${escHtml(dueLabel)}</div>
                    <div style="font-size:12px; color:${isPaid ? '#166534' : '#b45309'}; margin-top:3px;">${isPaid ? (row.paidOn ? `Paid on ${escHtml(formatDueDate(row.paidOn))}` : 'Paid') : 'Unpaid'}</div>
                    ${!isPaid && row.paidAmount > 0 ? `<div style="font-size:12px; color:#2563eb; margin-top:3px;">After credit: ${escHtml(formatPeso(row.remainingAmount || 0))}</div>` : ''}
                </div>
                <div style="text-align:right; min-width:120px;">
                    <div style="font-size:14px; font-weight:700; color:#111827;">${escHtml(formatPeso(displayedAmount))}</div>
                    <div style="display:inline-flex; align-items:center; justify-content:center; margin-top:6px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.3px; background:${isPaid ? '#dcfce7' : '#fef3c7'}; color:${isPaid ? '#166534' : '#b45309'};">${isPaid ? 'Paid' : 'Unpaid'}</div>
                </div>
            </div>
        `);
    });

    list.innerHTML = scheduleItems.join('');
    list.style.display = scheduleItems.length ? 'grid' : 'none';
}

function openInstallmentDetailsModal(button) {
    const modal = document.getElementById('installmentDetailsModal');
    if (!modal || !button) return;

    const hasLotContract = String(button.dataset.hasContract || '0') === '1';
    const viewContractBtn = document.getElementById('installment-view-contract-btn');
    if (viewContractBtn) {
        viewContractBtn.style.display = hasLotContract ? '' : 'none';
        if (hasLotContract) {
            const lotId = Number(button.dataset.lotId || 0);
            const lotLabel = String(button.dataset.lotLabel || 'Property');
            viewContractBtn.onclick = function() {
                openLotContractModal(lotId, lotLabel);
            };
        } else {
            viewContractBtn.onclick = null;
        }
    }

    const termYears = Number(button.dataset.termYears || 0);
    const dueDay = Number(button.dataset.dueDay || 0);

    document.getElementById('installment-modal-lot-label').textContent = button.dataset.lotLabel || 'Property';
    document.getElementById('installment-modal-lot-price').textContent = formatPeso(button.dataset.lotPrice || 0);
    document.getElementById('installment-modal-monthly').textContent = formatPeso(button.dataset.installmentAmount || 0);
    document.getElementById('installment-modal-down-payment').textContent = formatPeso(button.dataset.downPayment || 0);
    document.getElementById('installment-modal-balance').textContent = formatPeso(button.dataset.remainingBalance || 0);
    document.getElementById('installment-modal-term').textContent = termYears > 0 ? `${termYears} year${termYears > 1 ? 's' : ''}` : 'Not set';
    document.getElementById('installment-modal-due-day').textContent = dueDay > 0 ? `Every day ${dueDay} of the month` : 'Not set';
    document.getElementById('installment-modal-next-due').textContent = formatDueDate(button.dataset.nextDue || '');
    document.getElementById('installment-modal-paid').textContent = formatPeso(button.dataset.paidSoFar || 0);

    const loading = document.getElementById('installment-schedule-loading');
    const empty = document.getElementById('installment-schedule-empty');
    const list = document.getElementById('installment-schedule-list');
    const summary = document.getElementById('installment-schedule-summary');
    if (loading) loading.style.display = 'block';
    if (empty) empty.style.display = 'none';
    if (list) list.innerHTML = '';
    if (summary) summary.textContent = 'Loading schedule...';

    modal.classList.add('active');

    // Always show a local fallback schedule immediately so the modal never looks empty.
    const localPlan = {
        lot_price: Number(button.dataset.lotPrice || 0),
        payment_amount: Number(button.dataset.installmentAmount || 0),
        down_payment_amount: Number(button.dataset.downPayment || 0),
        payment_term_years: Number(button.dataset.termYears || 0),
        payment_due_day: Number(button.dataset.dueDay || 0),
        payment_deadline: String(button.dataset.nextDue || '')
    };
    const localPaidSoFar = Number(button.dataset.paidSoFar || 0);
    const localFallbackDeadline = String(button.dataset.nextDue || '');
    const estimatedTransactions = buildEstimatedInstallmentTransactions(localPlan, localPaidSoFar);
    const localContractBalance = Math.max(0, Number(localPlan.lot_price || 0) - Number(localPlan.down_payment_amount || 0));
    const localMonthlyPaidTotal = Math.max(0, localPaidSoFar - Number(localPlan.down_payment_amount || 0));
    const localRemainingBalance = Math.max(0, localContractBalance - localMonthlyPaidTotal);
    document.getElementById('installment-modal-balance').textContent = formatPeso(localRemainingBalance);
    renderInstallmentSchedule(localPlan, estimatedTransactions, localFallbackDeadline);
    if (summary && summary.textContent === 'Schedule unavailable') {
        summary.textContent = 'Estimated schedule';
    }

    const lotId = Number(button.dataset.lotId || 0);
    if (!lotId) {
        if (loading) loading.style.display = 'none';
        if (summary && !summary.textContent) summary.textContent = 'Estimated schedule';
        return;
    }

    fetch(`user_dashboard.php?action=installment_plan&lot_id=${lotId}&_ts=${Date.now()}`, { cache: 'no-store' })
        .then(r => r.text())
        .then(text => parseJsonFromResponseText(text))
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (!data || !data.success || !data.plan) {
                if (summary && !summary.textContent) summary.textContent = 'Estimated schedule';
                return;
            }

            const plan = data.plan || {};
            const fallbackDeadline = String(button.dataset.nextDue || '');
            const { installmentTransactions, downPaymentApplied } = splitInstallmentTransactions(plan, data.transactions || [], fallbackDeadline);
            const monthlyPaidTotal = installmentTransactions.reduce((sum, tx) => sum + Number(tx.amount || 0), 0);
            const downPaymentAmount = Number(plan.down_payment_amount || 0);
            const contractBalance = Math.max(0, Number(plan.lot_price || 0) - downPaymentAmount);
            const totalPaid = downPaymentApplied + monthlyPaidTotal;
            const remainingBalance = Math.max(0, contractBalance - monthlyPaidTotal);

            document.getElementById('installment-modal-lot-price').textContent = formatPeso(plan.lot_price || 0);
            document.getElementById('installment-modal-monthly').textContent = formatPeso(plan.payment_amount || 0);
            document.getElementById('installment-modal-down-payment').textContent = formatPeso(plan.down_payment_amount || 0);
            document.getElementById('installment-modal-balance').textContent = formatPeso(remainingBalance);
            document.getElementById('installment-modal-term').textContent = Number(plan.payment_term_years || 0) > 0 ? `${Number(plan.payment_term_years || 0)} year${Number(plan.payment_term_years || 0) > 1 ? 's' : ''}` : 'Not set';
            document.getElementById('installment-modal-due-day').textContent = Number(plan.payment_due_day || 0) > 0 ? `Every day ${Number(plan.payment_due_day || 0)} of the month` : 'Not set';
            // Compute next due as first unpaid month slot (accurate to selected payment month).
            const _scheduleInfo = buildInstallmentScheduleRows(plan, installmentTransactions, fallbackDeadline);
            if (!_scheduleInfo.rows || _scheduleInfo.rows.length === 0) {
                if (summary) summary.textContent = 'Estimated schedule';
                return;
            }
            if (_scheduleInfo.totalMonths > 0) {
                const nextUnpaidMonth = _scheduleInfo.rows.find(row => !row.isPaid);
                if (!nextUnpaidMonth) {
                    document.getElementById('installment-modal-next-due').textContent = 'Fully paid';
                } else {
                    document.getElementById('installment-modal-next-due').textContent = formatDueDate(nextUnpaidMonth.dueDate);
                }
            } else {
                document.getElementById('installment-modal-next-due').textContent = formatDueDate(plan.payment_deadline || '');
            }
            document.getElementById('installment-modal-paid').textContent = formatPeso(totalPaid);

            renderInstallmentSchedule(data.plan, data.transactions || [], fallbackDeadline);
        })
        .catch(() => {
            if (loading) loading.style.display = 'none';
            if (summary && !summary.textContent) summary.textContent = 'Estimated schedule';
        });
}

function closeInstallmentDetailsModal() {
    const modal = document.getElementById('installmentDetailsModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

let surrenderLotContext = { lotId: 0 };

function openLotHistoryModal(lotId, lotLabel = 'Property') {
    const modal = document.getElementById('lotHistoryModal');
    const header = document.getElementById('lot-history-header');
    const loading = document.getElementById('lot-history-loading');
    const empty = document.getElementById('lot-history-empty');
    const content = document.getElementById('lot-history-content');

    if (!modal || !header || !loading || !empty || !content) {
        return;
    }

    header.textContent = lotLabel;
    loading.style.display = 'block';
    empty.style.display = 'none';
    content.style.display = 'none';
    content.innerHTML = '';
    modal.classList.add('active');

    fetch(`user_dashboard.php?action=lot_history&lot_id=${Number(lotId)}&_ts=${Date.now()}`, { cache: 'no-store' })
        .then(r => r.text())
        .then(text => {
            const data = parseJsonFromResponseText(text);
            loading.style.display = 'none';
            if (!data || !data.success || !Array.isArray(data.history)) {
                empty.textContent = 'Unable to load lot history.';
                empty.style.display = 'block';
                return;
            }

            if (data.history.length === 0) {
                empty.textContent = 'No history records found for this lot.';
                empty.style.display = 'block';
                return;
            }

            content.style.display = 'block';
            content.innerHTML = data.history.map(entry => {
                const paidAmount = formatPeso(entry.paid_amount || 0);
                const refundAmount = formatPeso(entry.refund_amount || 0);
                const companyAmount = formatPeso(entry.company_amount || 0);
                const eventDate = entry.event_date ? entry.event_date : entry.created_at;
                return `
                    <div style="border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:12px; background:#ffffff;">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:10px;">
                            <div>
                                <div style="font-size:14px; font-weight:700; color:#111827; text-transform:capitalize;">${escHtml(entry.event_type || 'Event')}</div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">${escHtml(String(eventDate || 'Unknown date'))}</div>
                            </div>
                            <div style="font-size:12px; color:#475569; text-align:right;">
                                <div>Paid: ${escHtml(paidAmount)}</div>
                                <div>Refund: ${escHtml(refundAmount)}</div>
                                <div>Company: ${escHtml(companyAmount)}</div>
                            </div>
                        </div>
                        <div style="font-size:13px; color:#334155; margin-bottom:8px;">${escHtml(entry.remarks || '')}</div>
                        <div style="font-size:12px; color:#475569;">Previous owner: ${escHtml(entry.previous_owner_name || 'N/A')}</div>
                    </div>
                `;
            }).join('');
        })
        .catch(() => {
            loading.style.display = 'none';
            empty.textContent = 'Unable to load lot history.';
            empty.style.display = 'block';
        });
}

function closeLotHistoryModal() {
    const modal = document.getElementById('lotHistoryModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function openSurrenderModal(lotId, lotLabel = 'Property') {
    const modal = document.getElementById('surrenderModal');
    const labelEl = document.getElementById('surrender-modal-lot-label');
    const refundEl = document.getElementById('surrender-modal-refund-amount');
    const reasonEl = document.getElementById('surrender-reason');
    if (!modal || !labelEl || !refundEl || !reasonEl) {
        return;
    }
    surrenderLotContext.lotId = Number(lotId) || 0;
    labelEl.textContent = lotLabel;
    refundEl.textContent = '₱0.00';
    reasonEl.value = '';

    if (surrenderLotContext.lotId > 0) {
        fetch(`user_dashboard.php?action=surrender_preview&lot_id=${surrenderLotContext.lotId}&_ts=${Date.now()}`, { cache: 'no-store' })
            .then(r => r.text())
            .then(text => {
                const data = parseJsonFromResponseText(text);
                if (!data || !data.success) {
                    refundEl.textContent = '₱0.00';
                    return;
                }
                refundEl.textContent = formatPeso(Number(data.refund_amount || 0));
            })
            .catch(() => {
                refundEl.textContent = '₱0.00';
            });
    }

    modal.classList.add('active');
}

function closeSurrenderModal() {
    const modal = document.getElementById('surrenderModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function confirmSurrender() {
    const button = document.getElementById('confirm-surrender-btn');
    const reasonEl = document.getElementById('surrender-reason');
    if (!button || !reasonEl || !surrenderLotContext.lotId) {
        return;
    }
    button.disabled = true;
    button.textContent = 'Processing...';

    const surrenderReason = reasonEl.value.trim();

    fetch('user_dashboard.php?action=surrender_lot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lot_id: surrenderLotContext.lotId, reason: surrenderReason })
    })
        .then(r => r.text())
        .then(text => {
            const data = parseJsonFromResponseText(text);
            button.disabled = false;
            button.textContent = 'Confirm Surrender';
            if (!data || !data.success) {
                alert(data?.message || 'Unable to surrender the lot.');
                return;
            }
            closeSurrenderModal();
            window.location.reload();
        })
        .catch(() => {
            button.disabled = false;
            button.textContent = 'Confirm Surrender';
            alert('Unable to surrender the lot.');
        });
}

function openLotContractModal(lotId, lotLabel = 'Property') {
    const modal = document.getElementById('lotContractModal');
    const titleEl = document.getElementById('lot-contract-title');
    if (!modal || !titleEl) return;

    const normalizedLotId = Number(lotId || 0);
    const lotKey = String(normalizedLotId);
    const docs = (lotContractDocsByLot && Array.isArray(lotContractDocsByLot[lotKey]))
        ? lotContractDocsByLot[lotKey]
        : [];

    activeLotContractDocs = docs.slice();
    titleEl.textContent = `View Contract - ${lotLabel || 'Property'}`;

    renderLotContractList();
    if (activeLotContractDocs.length > 0) {
        previewLotContractDoc(0);
    } else {
        const frame = document.getElementById('lot-contract-frame');
        const meta = document.getElementById('lot-contract-file-meta');
        const openLink = document.getElementById('lot-contract-open-link');
        if (frame) frame.src = 'about:blank';
        if (meta) meta.textContent = '';
        if (openLink) {
            openLink.href = '#';
            openLink.style.pointerEvents = 'none';
            openLink.style.opacity = '0.6';
        }
    }

    modal.classList.add('active');
}

function closeLotContractModal() {
    const modal = document.getElementById('lotContractModal');
    if (modal) modal.classList.remove('active');
}

function renderLotContractList() {
    const listEl = document.getElementById('lot-contract-select');
    const emptyEl = document.getElementById('lot-contract-empty');
    if (!listEl || !emptyEl) return;

    listEl.innerHTML = '<option value="">Select contract/agreement</option>';

    if (!Array.isArray(activeLotContractDocs) || activeLotContractDocs.length === 0) {
        emptyEl.style.display = 'block';
        listEl.disabled = true;
        return;
    }

    listEl.disabled = false;
    emptyEl.style.display = 'none';
    listEl.innerHTML += activeLotContractDocs.map((doc, index) => {
        const uploaded = String(doc.uploaded_at || '').trim();
        const uploadedText = uploaded ? new Date(uploaded).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' }) : 'N/A';
        const label = `${String(doc.file_name || 'Document')} - ${String(doc.doc_type || 'Legal Document')} (${uploadedText})`;
        return `<option value="${index}">${escHtml(label)}</option>`;
    }).join('');
}

function previewLotContractDoc(index) {
    const i = Number(index);
    if (!Number.isInteger(i) || i < 0 || i >= activeLotContractDocs.length) return;

    const doc = activeLotContractDocs[i] || {};
    const frame = document.getElementById('lot-contract-frame');
    const meta = document.getElementById('lot-contract-file-meta');
    const openLink = document.getElementById('lot-contract-open-link');
    const selectEl = document.getElementById('lot-contract-select');
    if (!frame || !meta || !openLink) return;

    const filePath = String(doc.file_path || '').trim();
    const uploaded = String(doc.uploaded_at || '').trim();
    const uploadedText = uploaded ? new Date(uploaded).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'N/A';

    meta.textContent = `${String(doc.doc_type || 'Legal Document')} • Uploaded ${uploadedText}`;

    if (!filePath) {
        frame.src = 'about:blank';
        openLink.href = '#';
        openLink.style.pointerEvents = 'none';
        openLink.style.opacity = '0.6';
        return;
    }

    // Ask browser PDF viewers to fit document width to reduce horizontal scrolling.
    frame.src = `${filePath}#view=FitH`;
    openLink.href = filePath;
    openLink.style.pointerEvents = '';
    openLink.style.opacity = '1';

    if (selectEl) {
        selectEl.value = String(i);
    }
}

function openMessageModal(agentId) {
    currentAgentId = agentId;
    const modal = document.getElementById('messageModal');
    const agentNameEl = document.getElementById('modal_agent_name');
    
    // Get agent name from button's context
    const agentCard = event.target.closest('.agent-card');
    if (agentCard) {
        const agentName = agentCard.querySelector('.agent-details h3');
        if (agentName) {
            agentNameEl.value = agentName.innerText;
        }
    }
    
    // Clear previous message and status
    document.getElementById('modal_message_text').value = '';
    document.getElementById('modal_msg_status').innerHTML = '';
    
    modal.classList.add('active');
}

function closeMessageModal() {
    const modal = document.getElementById('messageModal');
    modal.classList.remove('active');
    currentAgentId = null;
}

function sendModalMessage() {
    const msgEl = document.getElementById('modal_message_text');
    const statusEl = document.getElementById('modal_msg_status');

    if (!msgEl || !statusEl) return;

    const message = msgEl.value.trim();

    if (!currentAgentId || !message) {
        statusEl.innerHTML = '<span style="color:#dc2626;">Please type a message.</span>';
        return;
    }

    statusEl.innerHTML = '<span style="color:#0284c7;">Sending...</span>';

    fetch('?action=send_agent_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agent_id: currentAgentId, message })
    })
    .then(r => {
        if (!r.ok) throw new Error('Server responded with status ' + r.status);
        return r.text();
    })
    .then(text => {
        try { return JSON.parse(text); } catch(e) { throw new Error('Invalid server response'); }
    })
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<span style="color:#166534;">' + (data.message || 'Message sent successfully!') + '</span>';
            msgEl.value = '';
            setTimeout(() => closeMessageModal(), 1500);
        } else {
            statusEl.innerHTML = '<span style="color:#dc2626;">' + (data.message || 'Failed to send message.') + '</span>';
        }
    })
    .catch((err) => {
        statusEl.innerHTML = '<span style="color:#dc2626;">Request failed: ' + err.message + '</span>';
    });
}

function submitAgentReview(agentId) {
    const ratingEl = document.getElementById('agent_rating');
    const reviewEl = document.getElementById('agent_review_text');
    const statusEl = document.getElementById('agentReviewStatus');

    if (!ratingEl || !reviewEl || !statusEl) return;

    const rating = parseInt(ratingEl.value || '0', 10);
    const review = reviewEl.value.trim();

    if (rating < 1 || rating > 5) {
        statusEl.innerHTML = '<span style="color:#dc2626;">Please select a rating from 1 to 5.</span>';
        return;
    }

    statusEl.innerHTML = '<span style="color:#0284c7;">Submitting review...</span>';

    fetch('?action=submit_agent_review', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            agent_id: Number(agentId || 0),
            rating,
            review
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            statusEl.innerHTML = '<span style="color:#166534;">' + (data.message || 'Review submitted successfully.') + '</span>';
            setTimeout(() => window.location.reload(), 900);
        } else {
            statusEl.innerHTML = '<span style="color:#dc2626;">' + ((data && data.message) ? data.message : 'Failed to submit review.') + '</span>';
        }
    })
    .catch(() => {
        statusEl.innerHTML = '<span style="color:#dc2626;">Request failed. Please try again.</span>';
    });
}

// ---- New Viewing Request ----
function openNewViewingModal() {
    const modal = document.getElementById('newViewingModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('newViewingStatus').style.display = 'none';
    }
}

function closeNewViewingModal() {
    const modal = document.getElementById('newViewingModal');
    if (modal) modal.style.display = 'none';
}

function submitNewViewingRequest() {
    const firstName = document.getElementById('nv_first_name')?.value.trim();
    const lastName  = document.getElementById('nv_last_name')?.value.trim();
    const email     = document.getElementById('nv_email')?.value.trim();
    const phone     = document.getElementById('nv_phone')?.value.trim();
    const datetime  = document.getElementById('nv_datetime')?.value.trim();
    const notes     = document.getElementById('nv_notes')?.value.trim();
    const agentId   = document.getElementById('nv_agent_id')?.value || '';
    const statusEl  = document.getElementById('newViewingStatus');

    if (!firstName || !lastName || !email || !phone || !datetime) {
        statusEl.textContent = '⚠️ Please fill in all required fields.';
        statusEl.style.display = 'block';
        statusEl.style.background = '#fffbeb';
        statusEl.style.color = '#92400e';
        return;
    }

    statusEl.textContent = 'Submitting...';
    statusEl.style.display = 'block';
    statusEl.style.background = '#eff6ff';
    statusEl.style.color = '#1e40af';

    const formData = new FormData();
    formData.append('agent_id', agentId);
    formData.append('client_first_name', firstName);
    formData.append('client_middle_name', '');
    formData.append('client_last_name', lastName);
    formData.append('client_email', email);
    formData.append('client_phone', phone);
    formData.append('location', '');
    formData.append('lot_no', '');
    formData.append('preferredDateTime', datetime);
    formData.append('notes', notes || '');
    formData.append('client_lat', '');
    formData.append('client_lng', '');
    formData.append('location_id', '');
    formData.append('lot_id', '');

    fetch('submit_viewing.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusEl.textContent = '✅ Viewing request submitted! Your agent will confirm your appointment.';
                statusEl.style.background = '#f0fdf4';
                statusEl.style.color = '#166534';
                document.getElementById('nv_datetime').value = '';
                document.getElementById('nv_notes').value = '';
                setTimeout(() => closeNewViewingModal(), 2500);
            } else {
                statusEl.textContent = '❌ Error: ' + (data.error || 'Failed to submit request.');
                statusEl.style.background = '#fef2f2';
                statusEl.style.color = '#991b1b';
            }
        })
        .catch(err => {
            statusEl.textContent = '❌ Network error: ' + err.message;
            statusEl.style.background = '#fef2f2';
            statusEl.style.color = '#991b1b';
        });
}

// ---- Lot Payment History ----
let lotPaymentsLoaded = false;

function loadLotPayments(forceReload) {
    if (lotPaymentsLoaded && !forceReload) return;
    const loading = document.getElementById('payments-loading');
    const empty   = document.getElementById('payments-empty');
    const content = document.getElementById('payments-content');
    if (!loading || !content) return;

    loading.style.display = 'block';
    empty.style.display = 'none';
    content.style.display = 'none';
    content.innerHTML = '';

    fetch('user_dashboard.php?action=lot_payments')
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            if (!data.success || !data.transactions || data.transactions.length === 0) {
                empty.style.display = 'block';
                lotPaymentsLoaded = true;
                return;
            }

            // Group transactions by lot_id
            const lots = {};
            data.transactions.forEach(tx => {
                if (!lots[tx.lot_id]) {
                    lots[tx.lot_id] = {
                        lot_id: tx.lot_id,
                        block_number: tx.block_number,
                        lot_number: tx.lot_number,
                        location_name: tx.location_name || '',
                        lot_price: parseFloat(tx.lot_price) || 0,
                        amount_paid_so_far: parseFloat(tx.amount_paid_so_far) || 0,
                        payment_deadline: tx.payment_deadline,
                        payment_type: tx.payment_type,
                        status: tx.status,
                        transactions: []
                    };
                }
                lots[tx.lot_id].transactions.push(tx);
            });

            let html = '';
            Object.values(lots).forEach(lot => {
                lot.transactions.sort((a, b) => {
                    const da = String(a.payment_date || '');
                    const db = String(b.payment_date || '');
                    if (da < db) return -1;
                    if (da > db) return 1;
                    return Number(a.id || 0) - Number(b.id || 0);
                });
                const totalPaid = lot.transactions.reduce((s, t) => s + (parseFloat(t.amount) || 0), 0);
                const balance   = Math.max(0, lot.lot_price - totalPaid);
                const pct       = lot.lot_price > 0 ? Math.min(100, Math.round((totalPaid / lot.lot_price) * 100)) : 0;
                const barColor  = pct >= 100 ? '#8b5cf6' : pct >= 50 ? '#3b82f6' : '#22c55e';

                const dueDate  = lot.payment_deadline ? new Date(lot.payment_deadline).toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'}) : null;
                const lotHeader = lot.location_name
                    ? `Barangay ${lot.location_name}, Block ${lot.block_number}, Lot ${lot.lot_number}`
                    : `Block ${lot.block_number}, Lot ${lot.lot_number}`;

                html += `
                <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:28px; overflow:hidden;">
                    <div style="background:linear-gradient(135deg,#14532d 0%,#166534 100%); padding:20px 24px; color:#fff;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div>
                                <div style="font-size:18px; font-weight:700;">${escHtml(lotHeader)}</div>
                                <div style="font-size:13px; opacity:.8; margin-top:2px;">${escHtml(lot.payment_type || lot.status || '')}</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:13px; opacity:.8;">Total Lot Price</div>
                                <div style="font-size:20px; font-weight:700;">₱${fmt(lot.lot_price)}</div>
                            </div>
                        </div>
                    </div>
                    <div style="padding:20px 24px;">
                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:16px; margin-bottom:20px;">
                            <div style="text-align:center; background:#f0fdf4; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Total Paid</div>
                                <div style="font-size:18px; font-weight:700; color:#16a34a;">₱${fmt(totalPaid)}</div>
                            </div>
                            <div style="text-align:center; background:#fef9ee; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Balance</div>
                                <div style="font-size:18px; font-weight:700; color:${balance===0?'#8b5cf6':'#f59e0b'};">${balance===0?'Fully Paid':'₱'+fmt(balance)}</div>
                            </div>
                            ${dueDate ? `<div style="text-align:center; background:#eff6ff; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Next Due</div>
                                <div style="font-size:14px; font-weight:700; color:#2563eb;">${dueDate}</div>
                            </div>` : ''}
                        </div>

                        <div style="background:#f9fafb; border-radius:8px; height:10px; overflow:hidden; margin-bottom:6px;">
                            <div style="height:100%; width:${pct}%; background:${barColor}; border-radius:8px; transition:width .6s;"></div>
                        </div>
                        <div style="font-size:12px; color:#666; text-align:right; margin-bottom:20px;">${pct}% paid</div>

                        ${lot.transactions.length > 0 ? `
                        <h4 style="margin:0 0 10px; font-size:14px; color:#374151; font-weight:700;">Payment History</h4>
                        <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f3f4f6;">
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600; white-space:nowrap;">Date</th>
                                    <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:600;">Amount</th>
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600;">Method</th>
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600;">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${lot.transactions.map((tx, i) => `
                                <tr style="border-top:1px solid #e5e7eb; background:${i%2===1?'#fafafa':'#fff'};">
                                    <td style="padding:10px 12px; white-space:nowrap; color:#374151;">${tx.payment_date ? new Date(tx.payment_date).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—'}</td>
                                    <td style="padding:10px 12px; text-align:right; font-weight:700; color:#16a34a;">₱${fmt(parseFloat(tx.amount)||0)}</td>
                                    <td style="padding:10px 12px; color:#374151;">${escHtml(tx.payment_method||'—')}</td>
                                    <td style="padding:10px 12px; color:#6b7280;">${escHtml(tx.remarks||'—')}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                        </div>` : `<p style="color:#999; font-size:13px; text-align:center; padding:10px 0;">No individual transactions recorded yet.</p>`}
                    </div>
                </div>`;
            });

            content.innerHTML = html;
            content.style.display = 'block';
            lotPaymentsLoaded = true;
        })
        .catch(err => {
            loading.style.display = 'none';
            content.innerHTML = '<p style="color:#dc2626; text-align:center; padding:20px;">Failed to load payment history.</p>';
            content.style.display = 'block';
        });
}

function fmt(n) { return Number(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function escHtml(s) { const d=document.createElement('span'); d.textContent=String(s??''); return d.innerHTML; }

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInstallmentDetailsModal();
        closeMessageModal();
        closeLotContractModal();
        closeLotHistoryModal();
        closeSurrenderModal();
    }
});

// Close modal on overlay click
document.addEventListener('click', function(e) {
    const installmentModal = document.getElementById('installmentDetailsModal');
    const modal = document.getElementById('messageModal');
    const lotContractModal = document.getElementById('lotContractModal');
    const lotHistoryModal = document.getElementById('lotHistoryModal');
    const surrenderModal = document.getElementById('surrenderModal');
    if (e.target === installmentModal) {
        closeInstallmentDetailsModal();
    }
    if (e.target === modal) {
        closeMessageModal();
    }
    if (e.target === lotContractModal) {
        closeLotContractModal();
    }
    if (e.target === lotHistoryModal) {
        closeLotHistoryModal();
    }
    if (e.target === surrenderModal) {
        closeSurrenderModal();
    }
});

// Initial Load

// Auto-hide profile update success message
document.addEventListener('DOMContentLoaded', function() {
    const msg = document.getElementById('profile-update-success');
    if (msg) {
        setTimeout(function() {
            msg.style.display = 'none';
        }, 3000);
    }
});

// Admin-style dynamic logout confirm modal
function confirmLogout(e) {
        if (e && e.preventDefault) e.preventDefault();
        // prevent multiple modals
        if (document.getElementById('admin-logout-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'admin-logout-modal';
        modal.innerHTML = `
            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif;">
                <div style="background: white; padding: 0; border-radius: 8px; width: 400px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                    <div style="padding: 24px 24px 16px 24px;">
                        <div style="display: flex; align-items: flex-start; gap: 16px;">
                            <div style="width: 32px; height: 32px; background: #fff3cd; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 4px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                    <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z" stroke="#856404" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #212529;">Confirm Logout</h3>
                                <p style="margin: 0; font-size: 16px; color: #6c757d;">Are you sure you want to logout? You will need to login again to access your dashboard.</p>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 20px 24px 24px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e9ecef;">
                        <button id="cancel-logout" style="background: #f8f9fa; color: #6c757d; border: 1px solid #ced4da; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Cancel</button>
                        <button id="confirm-logout" style="background: #dc3545; color: white; border: 1px solid #dc3545; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Logout</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // handlers
        const cancelBtn = modal.querySelector('#cancel-logout');
        const okBtn = modal.querySelector('#confirm-logout');

        function removeModal() { if (modal && modal.parentNode) modal.parentNode.removeChild(modal); }

        cancelBtn && cancelBtn.addEventListener('click', function(){ removeModal(); });
        okBtn && okBtn.addEventListener('click', function(){ window.location.href = 'logout.php'; });

        // close on Escape
        function escHandler(ev){ if(ev.key === 'Escape') { removeModal(); document.removeEventListener('keydown', escHandler); } }
        document.addEventListener('keydown', escHandler);
}
</script>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="modal" aria-hidden="true">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="modal-row">
            <div class="modal-icon" aria-hidden="true"><i class="fa fa-exclamation-circle"></i></div>
            <div class="modal-body">
                <h3 id="logoutTitle">Confirm Logout</h3>
                <p>Are you sure you want to logout?</p>
            </div>
        </div>
        <div class="modal-divider" aria-hidden="true"></div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeLogoutConfirm()">Cancel</button>
            <button class="btn-danger" style="margin-left:8px;" onclick="confirmLogout()">Logout</button>
        </div>
    </div>
</div>

</body>
</html>