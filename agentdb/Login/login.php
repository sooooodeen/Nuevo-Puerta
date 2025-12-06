<?php
session_start();
$conn = new mysqli("localhost", "root", "", "nuevopuerta");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Check admin_accounts
    $sql = "SELECT * FROM admin_accounts WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password'])) {
                $_SESSION['user'] = $username;
                $_SESSION['role'] = 'admin';
                $_SESSION['first_name'] = $row['first_name'];
                header("Location: ../admin_dashboard.php");
                exit();
            }
        }
        $stmt->close();
    }

    // Check agent_accounts
    $sql = "SELECT * FROM agent_accounts WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password'])) {
                $_SESSION['agent_id'] = $row['id']; // <-- THIS IS CORRECT
                $_SESSION['user'] = $username;
                $_SESSION['role'] = 'agent';
                $_SESSION['first_name'] = $row['first_name'];
                header("Location: ../agent_dashboard.php");
                exit();
            }
        }
        $stmt->close();
    }

    // Check user_accounts (if you have this)
    $sql = "SELECT * FROM user_accounts WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password'])) {
                $_SESSION['user'] = $username;
                $_SESSION['role'] = 'user';
                $_SESSION['first_name'] = $row['first_name'];
                header("Location: ../user_dashboard.php");
                exit();
            }
        }
        $stmt->close();
    }

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
</head>
<body>

    <!-- Welcome Message on Mid-Left -->
    <div class="welcome-message">
        <h1>WELCOME TO</h1>
        <h2>EL NUEVO PUERTA</h2>
        <h2>REAL ESTATE</h2>
        <p>Your gateway to premium real estate opportunities. <br> Explore, invest, and find your dream property with us.</p>
    </div>

    <!-- Login Form on the Right -->
    <div class="form-container">
        <img src="img/Logo.png" alt="El Nuevo Puerta Real Estate Logo" class="logo">
       <form action="login.php" method="POST" class="login-form">
    <div class="input-group">
        <input type="text" name="username" id="username" placeholder="Username" required>
    </div>
    <div class="input-group">
        <input type="password" name="password" id="password" placeholder="Password" required>
    </div>
    <div class="flex-row" style="display: flex; justify-content: flex-end; width: 100%;">
    </div>
    <button type="submit">LOGIN</button>
  
</form>
       

</body>
</html>
