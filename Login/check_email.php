<?php
session_start();

require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../includes/email_branding.php';

function sendVerificationEmail(string $recipientEmail, string $recipientName, string $verificationLink, ?string &$errorInfo = null): bool {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'carlomallari01471@gmail.com';
        $mail->Password = 'rsmv pipf ijxf phha';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('carlomallari01471@gmail.com', 'Nuevo Puerta Real Estate');
        $mail->addAddress($recipientEmail, $recipientName !== '' ? $recipientName : $recipientEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Verify your email address';

        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Customer', ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');
        $mail->Body = buildNovoPuertaEmailHtml(
            'Verify your email address',
            '<p>Hello ' . $safeName . ',</p>'
                . '<p>Please verify your Nuevo Puerta account using the link below:</p>'
                . '<p><a href="' . $safeLink . '" style="display:inline-block;background:#14532d;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:700;">Verify my email</a></p>'
                . '<p>If you did not request this, you can ignore this email.</p>',
            ['intro' => 'A verification email was requested for your Nuevo Puerta account.', 'footer_note' => 'If you did not request this, you can ignore this email.']
        );
        $mail->AltBody = buildNovoPuertaEmailAltBody(
            "Hello {$recipientName},\n\nPlease verify your account: {$verificationLink}",
            'If you did not request this, you can ignore this email.'
        );

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $errorInfo = $mail->ErrorInfo;
        error_log('Check-email resend failed: ' . $mail->ErrorInfo);
        return false;
    }
}

$pendingEmail = trim((string)($_SESSION['pending_verification_email'] ?? ''));
$pendingName = trim((string)($_SESSION['pending_verification_name'] ?? ''));
$resendMessage = '';
$resendError = '';

if ($pendingEmail === '') {
    header('Location: login.php');
    exit();
}

// Keep a short-lived notice for the login page after successful verification.
$_SESSION['flash_success'] = 'Verification email sent. Please verify your account, then log in.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['resend_action'] ?? '') === 'resend_verification') {
    $conn = new mysqli('localhost', 'root', '', 'nuevopuerta', 3306);
    if ($conn->connect_error) {
        $resendError = 'Unable to connect to database. Please try again.';
    } else {
        $conn->set_charset('utf8mb4');

        $stmt = $conn->prepare("SELECT id, first_name, last_name, IFNULL(email_verified, 1) AS email_verified, email_verification_sent_at FROM user_accounts WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $pendingEmail);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                $resendError = 'Account not found for this email.';
            } elseif ((int)($row['email_verified'] ?? 1) === 1) {
                $resendMessage = 'This email is already verified. You can log in now.';
            } else {
                $sentAtRaw = trim((string)($row['email_verification_sent_at'] ?? ''));
                $rateLimited = false;
                if ($sentAtRaw !== '') {
                    $sentAt = strtotime($sentAtRaw);
                    if ($sentAt !== false && (time() - $sentAt) < 60) {
                        $rateLimited = true;
                    }
                }

                if ($rateLimited) {
                    $resendError = 'Please wait at least 1 minute before requesting another email.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $update = $conn->prepare("UPDATE user_accounts SET email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ? LIMIT 1");
                    if ($update) {
                        $uid = (int)$row['id'];
                        $update->bind_param('si', $token, $uid);
                        $ok = $update->execute();
                        $update->close();

                        if ($ok) {
                            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/Login/check_email.php')), '/');
                            $verificationLink = $scheme . '://' . $host . $basePath . '/verify_email.php?email=' . urlencode($pendingEmail) . '&token=' . urlencode($token);
                            $displayName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                            $mailError = null;

                            if (sendVerificationEmail($pendingEmail, $displayName, $verificationLink, $mailError)) {
                                $resendMessage = 'Verification email sent. Please check your inbox and spam folder.';
                            } else {
                                $resendError = 'Unable to send verification email right now. ' . (!empty($mailError) ? ('Mail error: ' . $mailError) : 'Please try again later.');
                            }
                        } else {
                            $resendError = 'Could not refresh verification token. Please try again.';
                        }
                    } else {
                        $resendError = 'Could not prepare verification request. Please try again.';
                    }
                }
            }
        } else {
            $resendError = 'Could not prepare database query. Please try again.';
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email | Nuevo Puerta</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .verify-wrap {
            width: min(520px, 94vw);
            margin: 40px auto;
            background: rgba(255, 255, 255, 0.94);
            border-radius: 16px;
            padding: 26px 24px;
            box-shadow: 0 14px 26px rgba(0, 0, 0, 0.18);
            text-align: center;
        }
        .verify-title {
            margin: 0 0 8px;
            color: #14532d;
            font-size: 25px;
            font-weight: 700;
        }
        .verify-sub {
            margin: 0 0 16px;
            color: #1f2937;
            line-height: 1.55;
            font-size: 14px;
        }
        .verify-email {
            display: inline-block;
            margin: 0 0 18px;
            padding: 8px 12px;
            border-radius: 10px;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            font-weight: 700;
            word-break: break-all;
        }
        .btn-row {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .verify-btn {
            display: inline-block;
            text-decoration: none;
            background: #14532d;
            color: #fff;
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 700;
        }
        .verify-btn.secondary {
            background: #f3f4f6;
            color: #111827;
            border: 1px solid #d1d5db;
        }
        .verify-tip {
            margin-top: 14px;
            font-size: 12px;
            color: #6b7280;
        }
        .msg-ok {
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #e6f9ee;
            color: #166534;
            border: 1px solid #bbf7d0;
            font-size: 13px;
        }
        .msg-err {
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #ffe6e6;
            color: #991b1b;
            border: 1px solid #fecaca;
            font-size: 13px;
        }
        .resend-form {
            margin-top: 12px;
        }
        .resend-form button {
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php
        $welcomeMessagePath = __DIR__ . '/../includes/welcome_message.php';
        if (is_file($welcomeMessagePath)) {
            include $welcomeMessagePath;
        }
    ?>

    <div class="verify-wrap">
        <h1 class="verify-title">One More Step</h1>
        <?php if ($resendMessage !== ''): ?>
            <div class="msg-ok"><?php echo htmlspecialchars($resendMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($resendError !== ''): ?>
            <div class="msg-err"><?php echo htmlspecialchars($resendError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <p class="verify-sub">
            <?php if ($pendingName !== ''): ?>
                Hi <?php echo htmlspecialchars($pendingName, ENT_QUOTES, 'UTF-8'); ?>, your account was created successfully.
            <?php else: ?>
                Your account was created successfully.
            <?php endif; ?>
            <br>
            We sent a verification link to this email:
        </p>
        <div class="verify-email"><?php echo htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8'); ?></div>

        <div class="btn-row">
            <a class="verify-btn" href="https://mail.google.com" target="_blank" rel="noopener">Open Gmail</a>
            <a class="verify-btn secondary" href="login.php">Back to Login</a>
        </div>

        <form method="post" class="resend-form">
            <input type="hidden" name="resend_action" value="resend_verification">
            <button type="submit" class="verify-btn secondary">Resend Verification Email</button>
        </form>

        <p class="verify-tip">After you click the verification link in your email, you will be redirected to the login page automatically.</p>
    </div>
</body>
</html>
