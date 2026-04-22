<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root"; // or your DB username
$password = ""; // or your DB password
$database = "loginmanager"; // your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Make sure the agent is logged in
if (!isset($_SESSION['agent_id'])) {
    header('Location: login.php');
    exit();
}

// Get agent ID from session
$agentId = $_SESSION['agent_id'];

$message = '';

// Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form inputs
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Basic validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = 'Please fill in all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'New passwords do not match.';
    } else {
        // Fetch the agent's current hashed password
        $query = "SELECT password FROM agent_accounts WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $message = 'Agent not found.';
        } else {
            $stmt->bind_result($hashedPassword);
            $stmt->fetch();

            if (!password_verify($currentPassword, $hashedPassword)) {
                $message = 'Current password is incorrect.';
            } else {
                // Hash the new password
                $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update password
                $updateQuery = "UPDATE agent_accounts SET password = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param('si', $newHashedPassword, $agentId);

                if ($updateStmt->execute()) {
                    $message = 'Password updated successfully!';
                } else {
                    $message = 'Error updating password. Please try again.';
                }

                $updateStmt->close();
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Agent Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* OVERALL DESIGN */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            background-color: #f6f6f6;
            display: flex;
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
        }

        /* SIDEBAR */
        .sidebar-wrapper {
            background-color: transparent;
            padding: 25px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .sidebar {
            width: 265px;
            background-color: #3e5f3e;
            border-radius: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 15px;
            height: calc(100vh - 120px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .logo-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .logo-title h2 {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            line-height: 1.2;
            font-family: 'Inter', sans-serif;
        }

        .sidebar img.profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            background-color: transparent;
        }

        .user-profile {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: white;
            padding: 16px;
            border-radius: 8px;
            width: 100%;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
            text-align: center;
        }

        .user-profile img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 8px;
            background-color: #d9d9d9;
            margin-bottom: 10px;
        }

        .user-details {
            font-size: 12px;
            color: black;
            line-height: 1.2;
        }

        .nav {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .nav a {
            color: white;
            text-decoration: none;
            padding: 10px;
            margin-bottom: 6px;
            text-align: center;
            border-radius: 5px;
            display: block;
        }

        .nav a:hover {
            background-color: #3D4D26;
        }

        .nav-icon {
            width: 24px;
            height: 24px;
            margin-right: 4px;
            vertical-align: middle;
        }

        .container {
            flex: 1;
            padding: 40px;
            display: flex;
            flex-direction: column;
        }

        .divider {
            width: 5px;
            background-color: #2D4D26;
            height: calc(100vh - 40px);
            margin-top: 20px;
            border-radius: 5px;
        }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            padding: 40px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            height: calc(100vh - 0px); /* Adjust height to fit the viewport */
        }

        .profile-card {
            display: flex;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            height: 100%;
        }

        /* SECONDARY SIDEBAR */
        .secondary-sidebar {
            flex: 1;
            background-color: #f8f9fa;
            padding: 20px;
            border-right: 1px solid #ddd;
            height: 100%;
            overflow-y: auto;
        }

        .sidebar-header {
            font-size: 28px;
            padding-bottom: 2.5rem;
            cursor: default;
        }

        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar-item {
            padding-top: 3px;
            padding-bottom: 3px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .sidebar-item:hover {
            background-color: #e0e0e0;
            color: #2d482d;
            border-radius: 5px;
        }

        .secondary-sidebar a {
            text-decoration: none; /* Remove underline */
            color: inherit; /* Keep the text color unchanged */
        }

        .secondary-sidebar a:hover {
            text-decoration: none; /* Ensure no underline on hover */
            color: inherit; /* Keep the text color unchanged on hover */
        }

        /* FILL-UP FORMS FOR PROFILE EDITING */
        .form-card {
            flex: 4;
            background-color: #fff;
            border-radius: 10px;
            padding: 20px;
            height: 100%;
            overflow-y: hidden; /* Add scrolling for overflowing content */
        }
    </style>
</head>
<body>
<div class="sidebar-wrapper">
      <div class="sidebar">
          <div class="logo-title">
              <img src="assets/a.png" alt="Logo" class="profile-pic">
              <h2>NUEVO PUERTA</h2>
          </div>

          <div class="user-profile">
              <img src="assets/s.png" alt="User Image">
              <div class="user-details">
                  <div>John Eithan Apolinario</div>
                  <div>XXXX-XXXX-XXX</div>
              </div>
          </div>

          <div class="nav">
              <a href="agentdashboard.php">
                  <img src="assets/mdi_home.png" alt="Home Icon" class="nav-icon">
                  <span>Home</span>
              </a>
              <a href="agentprofile.php">
                  <img src="assets/mdi_user.png" alt="Edit Profile Icon" class="nav-icon">
                  <span>Agent Profile</span>
              </a>
              <a href="logout.php">
                  <img src="assets/ic_baseline-logout.png" alt="Logout Icon" class="nav-icon">
                  <span>Logout</span>
              </a>
          </div>
      </div>
  </div>

  <div class="divider"></div>

  <div class="main-content">
      <div class="profile-card">
          <div class="secondary-sidebar">
              <div class="sidebar-section">
                  <div class="sidebar-header">
                      My Profile
                  </div>
                  <div class="sidebar-item">
                    <a href="agentprofile.php">
                      <span>Edit Profile</span>
                    </a>
                  </div>
                  <div class="sidebar-item">
                    <a href="agentnotification.php">
                      <span>Notifications</span>
                    </a>
                  </div>
                  <div class="sidebar-item">
                    <a href="agentsecurity.php">
                      <span>Password & Security</span>
                    </a>
                  </div>
              </div>
          </div>

        <div class="form-card">
            <h2 style="margin-bottom: 20px;">Change Password</h2>
            <?php if (!empty($message)): ?>
                <div style="padding: 10px; background-color: #f8d7da; color: #721c24; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" style="display: flex; flex-direction: column; gap: 20px;">
                <div>
                    <label for="current-password" style="display: block; font-weight: 500; margin-bottom: 5px;">Current Password</label>
                    <input type="password" id="current-password" name="current_password" required 
                        style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                </div>

                <div>
                    <label for="new-password" style="display: block; font-weight: 500; margin-bottom: 5px;">New Password</label>
                    <input type="password" id="new-password" name="new_password" required 
                        style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                </div>

                <div>
                    <label for="confirm-password" style="display: block; font-weight: 500; margin-bottom: 5px;">Confirm New Password</label>
                    <input type="password" id="confirm-password" name="confirm_password" required 
                        style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                </div>

                <button type="submit" 
                        style="padding: 12px; background-color: #3e5f3e; color: white; font-weight: 600; border: none; border-radius: 5px; cursor: pointer;">
                    Update Password
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>