<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbUserDashboard = "userdashboard";
$dbLoginManager = "loginmanager";

// Connect to MySQL server
$conn = new mysqli($servername, $username, $password);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No user session found.']);
    exit;
}

$userId = intval($_SESSION['user_id']); // currently logged-in user's ID

// Handle profile update if AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $firstName = $conn->real_escape_string($_POST['first_name']);
    $lastName = $conn->real_escape_string($_POST['last_name']);
    $usernameVal = $conn->real_escape_string($_POST['username']);
    $emailVal = $conn->real_escape_string($_POST['email']);
    $mobileVal = $conn->real_escape_string($_POST['mobile']);
    $passwordRaw = $_POST['password'];
    $passwordHashed = password_hash($passwordRaw, PASSWORD_DEFAULT);
    $photoPath = '';

    // Handle profile picture if uploaded
    if (isset($_FILES['profilePic']) && $_FILES['profilePic']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filename = basename($_FILES['profilePic']['name']);
        $targetFile = $uploadDir . uniqid() . '_' . $filename;
        if (move_uploaded_file($_FILES['profilePic']['tmp_name'], $targetFile)) {
            $photoPath = $conn->real_escape_string($targetFile);
        }
    }

    $updateQuery = "UPDATE `$dbUserDashboard`.`users`
                    SET first_name = ?, last_name = ?, username = ?, password = ?, email = ?, mobile_number = ?, photo = ?
                    WHERE login_user_id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    if (!$updateStmt) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to prepare update query: ' . $conn->error]);
        exit;
    }

    $updateStmt->bind_param(
        "sssssssi",
        $firstName,
        $lastName,
        $usernameVal,
        $passwordHashed,
        $emailVal,
        $mobileVal,
        $photoPath,
        $userId
    );

    if ($updateStmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to update profile: ' . $updateStmt->error]);
    }
    $updateStmt->close();
    $conn->close();
    exit;
}

// Fetch user data from loginmanager.user_accounts
$query = "SELECT first_name, last_name, username, password, email, mobile, '' AS photo 
          FROM `$dbLoginManager`.`user_accounts` 
          WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to prepare fetch query: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No matching user found in user_accounts.']);
    exit;
}

$userData = $result->fetch_assoc();

// Check if user already exists in userdashboard.users
$queryCheck = "SELECT id FROM `$dbUserDashboard`.`users` WHERE login_user_id = ?";
$checkStmt = $conn->prepare($queryCheck);
if (!$checkStmt) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to prepare check query: ' . $conn->error]);
    exit;
}
$checkStmt->bind_param("i", $userId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    // Insert new record
    $queryInsert = "INSERT INTO `$dbUserDashboard`.`users` 
        (first_name, last_name, username, password, email, mobile_number, photo, login_user_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $conn->prepare($queryInsert);
    if (!$insertStmt) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to prepare insert query: ' . $conn->error]);
        exit;
    }
    // IMPORTANT: hash the password
    $passwordHashedInsert = password_hash($userData['password'], PASSWORD_DEFAULT);

    $insertStmt->bind_param(
        "sssssssi",
        $userData['first_name'],
        $userData['last_name'],
        $userData['username'],
        $passwordHashedInsert,
        $userData['email'],
        $userData['mobile'],
        $userData['photo'],
        $userId
    );

    if ($insertStmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'User inserted successfully.']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to insert user: ' . $insertStmt->error]);
    }

    $insertStmt->close();
} else {
    // User already exists
    // continue to show the HTML page
}

$stmt->close();
$checkStmt->close();
// don't close $conn here yet because it is needed for POST updates
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account Settings</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      background-color: #f9f9f9;
    }

    /* Navigation Bar */
    nav {
      background: #2d4e1e;
      padding: 10px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      height: 90px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      position: fixed;
      top: 0;
      left: 0;
      z-index: 1000;
    }

    .nav-left, .nav-right {
      list-style: none;
      display: flex;
      align-items: center;
      margin: 0;
      padding: 0;
    }

    .nav-left li, .nav-right li {
      margin-right: 40px;
      display: flex;
      align-items: center;
    }

    .nav-left li a, .nav-right li a {
      font-size: 18px;
      color: white;
      text-decoration: none;
      font-weight: bold;
      padding: 10px 15px;
      transition: transform 0.2s ease, color 0.2s ease;
    }

    .nav-left li a:hover, .nav-right li a:hover {
      transform: translateY(-3px);
      color: #f4d03f;
    }

    .nav-logo {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      z-index: 2;
      top: 50%;
      transform: translate(-50%, -50%);
    }

    .nav-logo img {
      width: 80px;
      height: auto;
    }

    /* Profile Dropdown */
    #profileMenu {
      display: none;
      position: absolute;
      top: 50px;
      right: 0;
      background: white;
      list-style: none;
      padding: 10px 0;
      margin: 0;
      border-radius: 8px;
      box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
      width: 300px;
    }

    #profileMenu li a {
      display: block;
      padding: 10px 20px;
      color: #2d4e1e;
      text-decoration: none;
    }

    #profileMenu li a:hover {
      color: #f4d03f;
      border-radius: 5px;
    }

    .container {
      flex: 1;
      padding: 40px;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      padding-top: 110px;
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

    .secondary-sidebar {
      width: 20%;
      background-color: #f8f9fa;
      padding: 20px;
      border-right: 1px solid #ddd;
      height: 100%;
      overflow-y: auto;
    }

    .secondary-sidebar a {
      text-decoration: none;
      color: inherit;
    }

    .secondary-sidebar a:hover {
      text-decoration: none;
      color: inherit;
    }

    .sidebar-header {
      font-size: 24px;
      padding-bottom: 1.5rem;
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

    .form-card {
      flex: 1;
      background-color: #fff;
      border-radius: 10px;
      padding: 30px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      max-height: calc(100vh - 100px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .scrollable-form {
      flex: 1;
      overflow-y: auto;
      padding-right: 10px;
    }

    .scrollable-form h2 {
      font-size: 28px;
      color: #2d482d;
      margin-bottom: 25px;
      cursor: default;
    }

    form {
      display: flex;
      flex-direction: column;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      font-size: 14px;
      margin-bottom: 5px;
      display: block;
      color: #333;
    }

    .form-group input,
    .form-group textarea {
      width: 100%;
      padding: 10px;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }

    .save-btn {
      align-self: flex-end;
      background-color: #2d482d;
      color: white;
      padding: 10px 30px;
      border: none;
      font-size: 14px;
      border-radius: 5px;
      cursor: pointer;
      margin-top: 10px;
    }

    .save-btn:hover {
      background-color: #1e331e;
    }
  </style>
</head>
<body>
  <nav>
    <ul class="nav-left">
      <li><a href="userlot.php">Buy</a></li>
      <li><a href="findagent.php">Find Agent</a></li>
      <li><a href="about.html">About</a></li>
    </ul>

    <div class="nav-logo">
      <a href="index.html">
        <img src="../assets/f.png" alt="Centered Logo">
      </a>
    </div>

    <ul class="nav-right">
      <li><a href="faqs.html">FAQs</a></li>
      <li><a href="contact.html">Contact</a></li>
      <li style="position: relative;">
        <img src="../assets/s.png" alt="Profile" id="profileBtn" style="width: 40px; height: 40px; border-radius: 50%; cursor: pointer;">
        <ul id="profileMenu">
          <li><a href="profile.php">Account Settings</a></li>
          <li><a href="logout.php">Logout</a></li>
        </ul>
      </li>
    </ul>
  </nav>

  <main class="container">
  <div class="secondary-sidebar">
    <div class="sidebar-section">
      <div class="sidebar-header">Account Settings</div>
      <div class="sidebar-item">
        <a href="adminaccounts.php">
          <span>User Profile</span>
        </a>
      </div>
    </div>
  </div>

    <div class="form-card">
      <div class="scrollable-form">
        <h2>Update User Profile</h2>
        <form id="updateProfileForm" enctype="multipart/form-data">
          <div class="form-row">
            <div class="form-group">
              <label for="userFirstName">First Name</label>
              <input type="text" id="userFirstName" name="first_name" required value="<?= htmlspecialchars($userData['first_name']) ?>">
            </div>
            <div class="form-group">
              <label for="userLastName">Last Name</label>
              <input type="text" id="userLastName" name="last_name" required value="<?= htmlspecialchars($userData['last_name']) ?>">
            </div>
          </div>
          <div class="form-group">
            <label for="userUsername">Username</label>
            <input type="text" id="userUsername" name="username" required value="<?= htmlspecialchars($userData['username']) ?>">
          </div>
          <div class="form-group">
            <label for="userPassword">Password</label>
            <input type="password" id="userPassword" name="password" required>
          </div>
          <div class="form-group">
            <label for="userEmail">Email</label>
            <input type="email" id="userEmail" name="email" required value="<?= htmlspecialchars($userData['email']) ?>">
          </div>
          <div class="form-group">
            <label for="userMobile">Mobile Number</label>
            <input type="text" id="userMobile" name="mobile" value="<?= htmlspecialchars($userData['mobile']) ?>">
          </div>
          <div class="form-group">
            <label for="profilePic">Profile Picture</label>
            <input type="file" id="profilePic" name="profilePic" accept="image/*">
          </div>
          <button type="submit" class="save-btn">SAVE</button>
        </form>
      </div>
    </div>
  </main>

  <script>
  const profileBtn = document.getElementById('profileBtn');
  const profileMenu = document.getElementById('profileMenu');

  profileBtn.addEventListener('click', () => {
    profileMenu.style.display = (profileMenu.style.display === 'block') ? 'none' : 'block';
  });

  document.addEventListener('click', function(event) {
    if (!profileBtn.contains(event.target) && !profileMenu.contains(event.target)) {
      profileMenu.style.display = 'none';
    }
  });

  document.getElementById('updateProfileForm').addEventListener('submit', function(e) {
      e.preventDefault();

      const formData = new FormData(this);
      formData.append('ajax', '1');

      fetch('', {
          method: 'POST',
          body: formData
      })
      .then(response => response.json())
      .then(data => {
          if (data.success) {
              alert('Profile updated successfully!');
              // location.reload();
          } else {
              alert('Error: ' + data.message);
          }
      })
      .catch(error => {
          console.error('Error:', error);
          alert('Unexpected error occurred.');
      });
  });
  </script>
</body>
</html>