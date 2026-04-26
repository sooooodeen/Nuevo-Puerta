<?php
session_start();

require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../includes/email_branding.php';

function columnExists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $res && $res->num_rows > 0;
}

function sendVerificationEmail(string $recipientEmail, string $recipientName, string $verificationLink): bool {
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
                . '<p>Thank you for creating your Nuevo Puerta account.</p>'
                . '<p>Please verify your email by clicking the link below:</p>'
                . '<p><a href="' . $safeLink . '" style="display:inline-block;background:#14532d;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:700;">Verify my email</a></p>'
                . '<p>If you did not create this account, you can ignore this email.</p>',
            ['intro' => 'Account verification is required before you can log in.', 'footer_note' => 'If you did not create this account, you can ignore this email.']
        );
        $mail->AltBody = buildNovoPuertaEmailAltBody(
            "Hello {$recipientName},\n\nPlease verify your account: {$verificationLink}",
            'If you did not create this account, you can ignore this email.'
        );

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Email verification send failed: ' . $mail->ErrorInfo);
        return false;
    }
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $mobile      = trim($_POST['mobile'] ?? '');
    $address     = trim($_POST['address'] ?? ''); // New field
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '')  $errors[] = 'Last name is required.';
    if ($username === '')   $errors[] = 'Username is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $servername = "localhost";
        $dbuser = "root";
        $dbpass = "";
        $dbname = "nuevopuerta";
        $port = 3306;

        $conn = new mysqli($servername, $dbuser, $dbpass, $dbname, $port);
        if ($conn->connect_error) {
            $errors[] = 'Database connection failed.';
        } else {
            $conn->set_charset('utf8mb4');

            // Ensure table exists and has an account_number column (unique)
            $conn->query("CREATE TABLE IF NOT EXISTS user_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_number VARCHAR(60) UNIQUE,
                first_name VARCHAR(100),
                middle_name VARCHAR(100),
                last_name VARCHAR(100),
                username VARCHAR(100) UNIQUE,
                email VARCHAR(255) UNIQUE,
                password VARCHAR(255),
                mobile_number VARCHAR(50),
                address TEXT,
                email_verified TINYINT(1) NOT NULL DEFAULT 1,
                email_verified_at DATETIME NULL,
                email_verification_token VARCHAR(64) NULL,
                email_verification_sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            if (!columnExists($conn, 'user_accounts', 'email_verified')) {
                $conn->query("ALTER TABLE user_accounts ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!columnExists($conn, 'user_accounts', 'email_verified_at')) {
                $conn->query("ALTER TABLE user_accounts ADD COLUMN email_verified_at DATETIME NULL");
            }
            if (!columnExists($conn, 'user_accounts', 'email_verification_token')) {
                $conn->query("ALTER TABLE user_accounts ADD COLUMN email_verification_token VARCHAR(64) NULL");
            }
            if (!columnExists($conn, 'user_accounts', 'email_verification_sent_at')) {
                $conn->query("ALTER TABLE user_accounts ADD COLUMN email_verification_sent_at DATETIME NULL");
            }

            // Generate a reasonably-unique account number to avoid duplicate-key errors.
            // Try a few times and verify it does not already exist in the DB.
            $account_number = '';
            $attempts = 0;
            while ($attempts < 8) {
                $candidate = 'USR-' . date('YmdHis') . '-' . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $checkStmt = $conn->prepare("SELECT id FROM user_accounts WHERE account_number = ? LIMIT 1");
                if ($checkStmt) {
                    $checkStmt->bind_param('s', $candidate);
                    $checkStmt->execute();
                    $r = $checkStmt->get_result();
                    $exists = ($r && $r->num_rows > 0);
                    $checkStmt->close();
                    if (! $exists) { $account_number = $candidate; break; }
                } else {
                    // If prepare failed, just accept the candidate to avoid infinite loop
                    $account_number = $candidate; break;
                }
                $attempts++;
                usleep(10000);
            }
            if ($account_number === '') {
                // Last resort: use uniqid
                $account_number = 'USR-' . uniqid();
            }

            // Check username
            $stmt = $conn->prepare("SELECT id FROM user_accounts WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $errors[] = 'Username already taken.';
            }
            $stmt->close();
            $stmt = null;

            // Check email
            $stmt = $conn->prepare("SELECT id FROM user_accounts WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $errors[] = 'Email already taken.';
            }
            $stmt->close();
            $stmt = null;

            if (empty($errors)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $verificationToken = bin2hex(random_bytes(32));
                $ins = $conn->prepare("INSERT INTO user_accounts (account_number, first_name, middle_name, last_name, username, email, password, mobile_number, address, email_verified, email_verified_at, email_verification_token, email_verification_sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, NOW())");
                $ins->bind_param('ssssssssss', $account_number, $first_name, $middle_name, $last_name, $username, $email, $hash, $mobile, $address, $verificationToken);

                if ($ins->execute()) {
                    $user_id = $conn->insert_id;

                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/Login/register.php')), '/');
                    $verificationLink = $scheme . '://' . $host . $basePath . '/verify_email.php?email=' . urlencode($email) . '&token=' . urlencode($verificationToken);

                    $displayName = trim($first_name . ' ' . $last_name);
                    $mailSent = sendVerificationEmail($email, $displayName, $verificationLink);

                    if (!$mailSent) {
                        $rollbackStmt = $conn->prepare("DELETE FROM user_accounts WHERE id = ? LIMIT 1");
                        if ($rollbackStmt) {
                            $rollbackStmt->bind_param('i', $user_id);
                            $rollbackStmt->execute();
                            $rollbackStmt->close();
                        }
                        $ins->close();
                        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                            $stmt->close();
                            $stmt = null;
                        }
                        $errors[] = 'Unable to send verification email right now. Please try registering again in a few minutes.';
                    } else {
                        $ins->close();
                        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                            $stmt->close();
                            $stmt = null;
                        }
                        $_SESSION['pending_verification_email'] = $email;
                        $_SESSION['pending_verification_name'] = trim($first_name . ' ' . $last_name);

                        // Notify admin: create notifications table if missing and insert a notification
                        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            recipient_type VARCHAR(50),
                            recipient_id INT,
                            title VARCHAR(180),
                            message TEXT,
                            type VARCHAR(30) DEFAULT 'info',
                            is_read TINYINT(1) DEFAULT 0,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                        $notifTitle = 'New user registered';
                        $notifMessage = sprintf("New account: %s %s (username: %s, email: %s)", $first_name, $last_name, $username, $email);
                        $recipientType = 'admin';
                        $recipientId = 0;

                        $notifCols = [];
                        $notifVals = [];
                        $notifTypes = '';
                        $notifParams = [];

                        if (columnExists($conn, 'notifications', 'recipient_type')) {
                            $notifCols[] = 'recipient_type';
                            $notifVals[] = '?';
                            $notifTypes .= 's';
                            $notifParams[] = $recipientType;
                        }
                        if (columnExists($conn, 'notifications', 'recipient_id')) {
                            $notifCols[] = 'recipient_id';
                            $notifVals[] = '?';
                            $notifTypes .= 'i';
                            $notifParams[] = $recipientId;
                        }
                        if (columnExists($conn, 'notifications', 'title')) {
                            $notifCols[] = 'title';
                            $notifVals[] = '?';
                            $notifTypes .= 's';
                            $notifParams[] = $notifTitle;
                        }
                        if (columnExists($conn, 'notifications', 'message')) {
                            $notifCols[] = 'message';
                            $notifVals[] = '?';
                            $notifTypes .= 's';
                            $notifParams[] = $notifMessage;
                        }
                        if (columnExists($conn, 'notifications', 'type')) {
                            $notifCols[] = 'type';
                            $notifVals[] = '?';
                            $notifTypes .= 's';
                            $notifParams[] = 'success';
                        }

                        if (!empty($notifCols)) {
                            $notifSql = 'INSERT INTO notifications (' . implode(', ', $notifCols) . ') VALUES (' . implode(', ', $notifVals) . ')';
                            $insNotif = $conn->prepare($notifSql);
                            if ($insNotif) {
                                if ($notifTypes !== '') {
                                    $insNotif->bind_param($notifTypes, ...$notifParams);
                                }
                                if (!$insNotif->execute()) {
                                    error_log('Notification insert failed: ' . $conn->error);
                                }
                                $insNotif->close();
                            } else {
                                error_log('Notification prepare failed: ' . $conn->error);
                            }
                        }

                        $conn->close();

                        // Redirect to verification notice page after registration.
                        header('Location: check_email.php');
                        exit();
                    }
                } else {
                    $errors[] = 'Failed to create account: ' . $conn->error;
                }
            }
            $conn->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | El Nuevo Puerta Real Estate</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Row Layout Fix */
        .form-row {
            display: flex;
            gap: 10px;
            width: 100%; /* Matches your input-group width */
            margin: 0 auto 12px auto;
        }

        /* Adjust input groups to sit side-by-side */
        .form-row .input-group {
            flex: 1;
            margin-bottom: 0 !important; /* Remove bottom margin inside rows */
            width: 100% !important;
        }

        /* Full width groups (Email line) */
        .full-width-row {
            width: 100%;
            margin: 0;
        }
        .full-width-row .input-group {
            width: 100% !important;
        }

        .register-heading { color: #fff; margin-bottom: 15px; font-weight:700; text-align: center; }
        .error { background:#ffe6e6;color:#b30000;padding:10px;border-radius:8px;margin: 0 auto 14px auto; width:80%; text-align:center; font-size: 13px; }
        
        button[type="submit"] {
            width: 100%;
            max-width: 420px;
            display: block;
            margin: 12px auto 0;
            background-color: #1b5e20;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 30px;
            font-weight: bold;
            cursor: pointer;
        }

        .login-link { color: #fff; text-decoration: underline; display: block; text-align: center; margin-top: 15px; font-size: 14px; }

        html, body { height: 100%; overflow: hidden; }
        @media (max-width: 980px) { 
            html, body { overflow: auto; }
            .form-row { flex-direction: column; width: 100%; }
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

    <div class="form-container">
        <a href="login.php" class="back-arrow"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M14 6L9 11L14 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        <img src="img/Logo.png" alt="Logo" class="logo">
        <h2 class="register-heading">Create an account</h2>

        <?php if (!empty($errors)): ?>
            <div class="error"><?php echo implode('<br>', $errors); ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST" class="login-form">
            
            <div class="form-row">
                <div class="input-group">
                    <input type="text" name="first_name" placeholder="First name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                </div>
                <div class="input-group">
                    <input type="text" name="middle_name" placeholder="Middle name (optional)" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <input type="text" name="last_name" placeholder="Last name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                </div>
                <div class="input-group">
                    <input type="text" name="username" placeholder="Username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
            </div>

            <div class="full-width-row">
                <div class="input-group">
                    <input type="email" name="email" placeholder="Email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <input type="text" name="mobile" placeholder="Mobile number" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
                </div>
                <div class="input-group">
                    <input type="text" name="address" placeholder="Address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <input type="password" name="password" placeholder="Password" required>
                </div>
                <div class="input-group">
                    <input type="password" name="confirm_password" placeholder="Confirm password" required>
                </div>
            </div>

            <div class="input-group" style="margin-bottom: 10px; display: flex; align-items: flex-start; gap: 5px;">
                <input type="checkbox" id="agree_terms" name="agree_terms" required style="width: 15px; height: 15px; margin-top: 3px;">
                <label for="agree_terms" style="font-size: 12.5px; margin: 0; color: #222; line-height: 1.4;">
                    I agree to the <a href="#" id="showTerms" style="color: #1a4d8f; text-decoration: underline;">Terms and Conditions & Privacy Policy</a>
                </label>
            </div>
            <button type="submit" id="registerBtn">Register</button>
            <a class="login-link" href="login.php">Already have an account? Login</a>
        </form>
        <!-- Use system modal style -->
        <script src="../assets/js/alert-modal.js"></script>
    </div>
<script>
// Use system modal for Terms and Conditions (render as HTML, add scroll)
const showTerms = document.getElementById('showTerms');
if (showTerms) {
    showTerms.addEventListener('click', function(e) {
        e.preventDefault();
        // Use the system modal, but render HTML and add scroll for long content
        window.showAlertModal('Loading...', 'Terms and Conditions & Privacy Policy');
        setTimeout(function() {
            var modalBody = document.getElementById('globalAlertBody');
            if (modalBody) {
                modalBody.innerHTML = `<div style='max-height:60vh; overflow-y:auto; padding-right:8px;'>
<h2 style='color:#1a4d8f;font-size:1.2em;'>TERMS AND CONDITIONS & PRIVACY POLICY<br>Nuevo Puerta Real Estate</h2>
<hr><h3>1. Acceptance of Terms</h3>
<p>By accessing and using Nuevo Puerta Real Estate, you agree to comply with and be bound by these Terms and Conditions. If you do not agree, please do not use this system.</p>
<hr><h3>2. Description of Service</h3>
<p>Nuevo Puerta Real Estate is an online platform designed to display available lot listings and provide information for interested buyers. The system serves as an informational and communication tool only.</p>
<hr><h3>3. Admin-Controlled Listings</h3>
<ul><li>All property (lot) listings are <b>exclusively posted and managed by the system administrator</b></li><li>Users (buyers) are <b>not allowed to post or upload listings</b></li><li>The admin ensures that listings are updated, but does not guarantee completeness at all times</li></ul>
<hr><h3>4. User Responsibilities</h3>
<ul><li>Provide accurate personal information when required</li><li>Use the system only for lawful purposes</li><li>Not attempt to hack, damage, or misuse the system</li></ul>
<hr><h3>5. Property Information Disclaimer</h3>
<ul><li>All lot details are provided for <b>informational purposes only</b></li><li>Availability, pricing, and details may change without prior notice</li><li>Nuevo Puerta Real Estate is not liable for any misunderstanding based on displayed information</li></ul>
<hr><h3>6. Payments and Transactions</h3>
<ul><li>Nuevo Puerta Real Estate <b>does NOT process any payments online</b></li><li>All payments must be made <b>personally at the official office</b></li><li>The system will <b>not ask for online payments, bank transfers, or e-wallet transactions</b></li><li>Users are advised to avoid scams and only transact through the official office</li></ul>
<hr><h3>7. Limitation of Liability</h3>
<ul><li>Any losses or damages from misuse of the system</li><li>Decisions made based on property information</li><li>Any unauthorized transactions outside the official office</li></ul>
<hr><h3>8. Account and Access Control</h3>
<ul><li>The admin has full control over the system</li><li>Access may be restricted, suspended, or terminated for violations</li></ul>
<hr><h3>9. System Availability</h3>
<p>We do not guarantee uninterrupted access. The system may undergo maintenance or updates at any time.</p>
<hr><h3>10. Changes to Terms</h3>
<p>We reserve the right to update these Terms at any time. Continued use means you accept the changes.</p>
<hr><h3>11. Governing Law</h3>
<p>These Terms are governed by the laws of the Republic of the Philippines.</p>
<hr><h2 class='section-title'>PRIVACY POLICY</h2>
<h3>12. Information We Collect</h3>
<ul><li>Name</li><li>Contact number</li><li>Email address</li><li>Any details submitted through inquiry forms</li></ul>
<hr><h3>13. How We Use Information</h3>
<ul><li>Respond to inquiries</li><li>Provide property details</li><li>Improve system functionality</li></ul>
<hr><h3>14. Data Protection</h3>
<ul><li>We are committed to protecting your personal data</li><li>Your information will not be sold or shared with third parties without consent</li><li>Data may only be disclosed if required by law</li></ul>
<hr><h3>15. Data Security</h3>
<p>We implement reasonable security measures to protect your information from unauthorized access, loss, or misuse.</p>
<hr><h3>16. User Rights</h3>
<ul><li>Request access to their personal data</li><li>Request correction of inaccurate information</li><li>Request deletion of their data (if applicable)</li></ul>
<hr><h3>17. Cookies (Optional if you use them)</h3>
<p>The system may use cookies to improve user experience. Users may disable cookies in their browser settings.</p>
<hr><h3>18. Contact Information</h3>
<p>For questions about these Terms or Privacy Policy, please contact the system administrator through the platform.</p>
</div>`;
            }
        }, 50);
    });
}
// Show alert if Register is clicked without agreeing to terms
const agreeTerms = document.getElementById('agree_terms');
const registerBtn = document.getElementById('registerBtn');
if (agreeTerms && registerBtn) {
    registerBtn.addEventListener('click', function(e) {
        if (!agreeTerms.checked) {
            e.preventDefault();
            alert('Please read and agree to the Terms and Conditions & Privacy Policy before registering.');
        }
    });
}
</script>
</body>
</html>