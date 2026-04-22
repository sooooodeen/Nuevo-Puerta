<?php
session_start();

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'nuevopuerta';
$port = 3306;

$conn = new mysqli($servername, $username, $password, $dbname, $port);
if ($conn->connect_error) {
    http_response_code(500);
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 1");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(64) NULL");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL");

$email = trim((string)($_GET['email'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

$message = 'Invalid verification link.';
$isSuccess = false;

if ($email !== '' && $token !== '') {
    $stmt = $conn->prepare("SELECT id, email_verified, email_verification_token, email_verification_sent_at FROM user_accounts WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            if ((int)($row['email_verified'] ?? 0) === 1) {
                $isSuccess = true;
                $message = 'Your email is already verified. You can now log in.';
            } elseif (hash_equals((string)($row['email_verification_token'] ?? ''), $token)) {
                $isExpired = false;
                $sentAtRaw = trim((string)($row['email_verification_sent_at'] ?? ''));
                if ($sentAtRaw !== '') {
                    $sentAt = strtotime($sentAtRaw);
                    if ($sentAt !== false && (time() - $sentAt) > (48 * 3600)) {
                        $isExpired = true;
                    }
                }

                if ($isExpired) {
                    $message = 'Verification link expired. Please register again to get a new verification email.';
                } else {
                    $update = $conn->prepare("UPDATE user_accounts SET email_verified = 1, email_verified_at = NOW(), email_verification_token = NULL WHERE id = ? LIMIT 1");
                    if ($update) {
                        $uid = (int)$row['id'];
                        $update->bind_param('i', $uid);
                        if ($update->execute()) {
                            $isSuccess = true;
                            $message = 'Email verified successfully. You can now log in.';
                            $_SESSION['flash_success'] = 'Email verified successfully. Please log in.';
                            unset($_SESSION['pending_verification_email'], $_SESSION['pending_verification_name']);
                        } else {
                            $message = 'Failed to verify email. Please try again.';
                        }
                        $update->close();
                    } else {
                        $message = 'Failed to verify email. Please try again.';
                    }
                }
            } else {
                $message = 'Invalid verification token.';
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | Nuevo Puerta</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .verify-wrap {
            width: min(460px, 94vw);
            margin: 40px auto;
            background: rgba(255, 255, 255, 0.93);
            border-radius: 16px;
            padding: 26px 24px;
            box-shadow: 0 14px 26px rgba(0, 0, 0, 0.18);
            text-align: center;
        }
        .verify-title {
            margin: 0 0 12px;
            color: #14532d;
            font-size: 24px;
            font-weight: 700;
        }
        .verify-msg {
            margin: 0 0 18px;
            color: #1f2937;
            line-height: 1.55;
            font-size: 14px;
        }
        .verify-msg.ok { color: #166534; }
        .verify-msg.err { color: #991b1b; }
        .verify-btn {
            display: inline-block;
            text-decoration: none;
            background: #14532d;
            color: #fff;
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 700;
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
        <h1 class="verify-title">Email Verification</h1>
        <p class="verify-msg <?php echo $isSuccess ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($message); ?></p>
        <a class="verify-btn" href="login.php">Go to Login</a>
        <?php if ($isSuccess): ?>
            <p style="margin-top:10px; font-size:12px; color:#64748b;">Redirecting to login in 3 seconds...</p>
            <script>
                setTimeout(function () {
                    window.location.href = 'login.php';
                }, 3000);
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
