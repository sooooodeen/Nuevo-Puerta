<?php
session_start();


// Database Connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "nuevopuerta";
$port = 3306;

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 1");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(64) NULL");
$conn->query("ALTER TABLE user_accounts ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL");

$error_message = "";
$resend_message = "";
$resend_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = trim((string)($_POST['login_action'] ?? 'login_submit'));

    if ($action !== 'login_submit') {
        $error_message = 'Invalid request.';
    } else {
        $input_user = trim((string)($_POST['username'] ?? ''));
        $input_pass = trim((string)($_POST['password'] ?? ''));
        
        $login_success = false;

        // 1. CHECK ADMIN ACCOUNTS
        if (!$login_success) {
            $stmt = $conn->prepare("SELECT * FROM admin_accounts WHERE username = ?");
            $stmt->bind_param("s", $input_user);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if (password_verify($input_pass, $row['password'])) {
                    $_SESSION['admin_id'] = $row['id'];
                    $_SESSION['user'] = $input_user;
                    $_SESSION['role'] = 'admin';
                    $_SESSION['first_name'] = $row['first_name'];
                    header("Location: ../admindashboard.php");
                    exit();
                }
            }
            $stmt->close();
        }

        // 2. CHECK AGENT ACCOUNTS
        if (!$login_success) {
            $stmt = $conn->prepare("SELECT * FROM agent_accounts WHERE username = ?");
            $stmt->bind_param("s", $input_user);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if (password_verify($input_pass, $row['password'])) {
                    $_SESSION['agent_id'] = $row['id'];
                    $_SESSION['user'] = $input_user;
                    $_SESSION['role'] = 'agent';
                    $_SESSION['first_name'] = $row['first_name'];
                    header("Location: ../agent_dashboard.php");
                    exit();
                }
            }
            $stmt->close();
        }

        // 3. CHECK USER ACCOUNTS
        if (!$login_success) {
            $stmt = $conn->prepare("SELECT id, username, password, first_name, last_name, email, IFNULL(email_verified, 1) AS email_verified FROM user_accounts WHERE username = ?");
            $stmt->bind_param("s", $input_user);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                // Verify the HASHED password
                if (password_verify($input_pass, $row['password'])) {
                    if ((int)($row['email_verified'] ?? 1) !== 1) {
                        $error_message = "Please verify your email first. Check your inbox for the verification link.";
                        $stmt->close();
                        $conn->close();
                        goto render_login_page;
                    }

                    $_SESSION['user_id']    = $row['id'];
                    $_SESSION['username']   = $row['username'];
                    $_SESSION['role']       = 'user';
                    $_SESSION['first_name'] = $row['first_name'];
                    $_SESSION['last_name']  = $row['last_name'];
                    $_SESSION['email']      = $row['email'];
                    
                    header("Location: ../user_dashboard.php");
                    exit();
                }
            }
            $stmt->close();
        }

        // If we reach here, no match was found
        $error_message = "Invalid username or password.";
    }
}

$conn->close();

render_login_page:
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | El Nuevo Puerta Real Estate</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Add a style for the error message so it is visible */
        .error-msg {
            background-color: #ffe6e6;
            color: #d93025;
            border: 1px solid #d93025;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
        }
        .success-msg {
            background-color: #e6f9ee;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
        }
        /* Register button style - match login button sizing and card alignment */
        .register-btn {
            display: inline-block;
            margin-top: 18px;
            width: 92%;
            padding: 12px 0;
            background: transparent;
            color: #ffffff;
            border: 2px solid rgba(255,255,255,0.85);
            border-radius: 30px;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            box-shadow: none;
            transition: all 0.2s ease-in-out;
        }
        .register-btn:hover {
            background: rgba(255,255,255,0.08);
            transform: translateY(-2px);
        }
        /* Make login page static (no page scroll) on desktop */
        html, body { height: 100%; overflow: hidden; }
        @media (max-width: 980px) { html, body { overflow: auto; } }
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
        <a href="../index.php" class="back-arrow" title="Back to Home">
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                <path d="M14 6L9 11L14 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

        <img src="img/Logo.png" alt="El Nuevo Puerta Real Estate Logo" class="logo">
        
        <form action="login.php" method="POST" class="login-form">
            <input type="hidden" name="login_action" value="login_submit">
            
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="success-msg"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="input-group">
                <input type="text" name="username" id="username" placeholder="Username" required>
            </div>
            <div class="input-group">
                <input type="password" name="password" id="password" placeholder="Password" required>
            </div>
            <!-- Terms and Conditions checkbox removed for login. Only required for registration. -->
            <button type="submit">LOGIN</button>
                </form>

            <a class="register-btn" href="register.php" id="registerBtn" style="opacity: 0.5;">Create an account</a>
                <!-- Terms and Conditions modal and checkbox are only required for registration, not login. -->
    </div>

</body>
</html>