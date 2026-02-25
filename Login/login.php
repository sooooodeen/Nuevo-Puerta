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
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_user = trim($_POST['username']);
    $input_pass = trim($_POST['password']);
    
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
        $stmt = $conn->prepare("SELECT id, username, password, first_name, last_name, email FROM user_accounts WHERE username = ?");
        $stmt->bind_param("s", $input_user);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            // Verify the HASHED password
            if (password_verify($input_pass, $row['password'])) {
                $_SESSION['user_id']    = $row['id'];
                $_SESSION['username']   = $row['username'];
                $_SESSION['role']       = 'user';
                $_SESSION['first_name'] = $row['first_name'];
                $_SESSION['last_name']  = $row['last_name'];
                $_SESSION['email']      = $row['email']; // Useful for dashboard
                
                // Redirect to User Dashboard
                header("Location: ../user_dashboard.php");
                exit();
            }
        }
        $stmt->close();
    }

    // If we reach here, no match was found
    $error_message = "Invalid username or password.";
}

$conn->close();
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
    </style>
</head>
<body>

    <div class="welcome-message">
        <h1>WELCOME TO</h1>
        <h2>EL NUEVO PUERTA</h2>
        <h2>REAL ESTATE</h2>
        <p>Your gateway to premium real estate opportunities. <br> Explore, invest, and find your dream property with us.</p>
    </div>

    <div class="form-container">
        <a href="../index.html" class="back-arrow" title="Back to Home">
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                <path d="M14 6L9 11L14 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

        <img src="img/Logo.png" alt="El Nuevo Puerta Real Estate Logo" class="logo">
        
        <form action="login.php" method="POST" class="login-form">
            
            <?php if (!empty($error_message)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="input-group">
                <input type="text" name="username" id="username" placeholder="Username" required>
            </div>
            <div class="input-group">
                <input type="password" name="password" id="password" placeholder="Password" required>
            </div>
            
            <button type="submit">LOGIN</button>
        </form>
    </div>
</body>
</html>