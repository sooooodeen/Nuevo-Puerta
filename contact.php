<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/user_helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Contact</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    body {
        font-family: 'Poppins', sans-serif;
        font-size: 16px;
        margin: 0;
        padding: 0;
        background-color: #e3efe2;
        color: #333;
    }
    .main-nav {
        background: #2d4e1e;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 40px;
        height: 80px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        z-index: 1000;
        position: sticky;
        top: 0;
    }
    .nav-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .nav-logo {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        object-fit: contain;
        background: transparent;
        padding: 4px;
        margin-right: 0;
    }
    .company-name {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .nav-links {
        display: flex;
        gap: 30px;
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .nav-links a {
        color: #fff;
        text-decoration: none;
        font-size: 1rem;
        font-weight: 500;
        padding: 8px 0;
        transition: color 0.18s;
        position: relative;
    }
    .nav-links a:hover {
        color: #b8c9a7;
    }
    .nav-links a::after {
        content: '';
        position: absolute;
        width: 0;
        height: 2px;
        bottom: -5px;
        left: 0;
        background-color: #b8c9a7;
        transition: width 0.3s ease-out;
    }
    .nav-links a:hover::after {
        width: 100%;
    }
    .login-btn {
        background: #2d4e1e;
        color: #ffffff;
        font-weight: 600;
        border-radius: 20px;
        padding: 10px 25px;
        text-decoration: none;
        font-size: 1rem;
        transition: all 0.2s ease;
        border: none;
        box-shadow: 0 4px 12px rgba(44,62,80,0.1);
    }
    .login-btn:hover {
        background: #3a6c28;
        color: #ffffff;
        box-shadow: 0 6px 15px rgba(58, 108, 40, 0.35);
    }
    .nav-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .nav-links li.active a {
        color: #b8c9a7;
        font-weight: 600;
    }
    .nav-links li.active a::after {
        content: '';
        position: absolute;
        bottom: -5px;
        left: 0;
        width: 100%;
        height: 3px;
        background: #b8c9a7;
        border-radius: 2px;
    }
    @media (max-width: 900px) {
        .main-nav { padding: 0 15px; }
        .company-name { font-size: 1.2rem; }
        .nav-logo { width: 40px; height: 40px; }
    }
  </style>
</head>
<body>
    <nav class="main-nav">
        <div class="nav-left">
            <img src="assets/f.png" alt="Logo" class="nav-logo">
            <span class="company-name">El Nuevo Puerta Real Estate</span>
        </div>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="userlot.php">View Lots</a></li>
            <li><a href="findagent.php">Find Agent</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="faqs.php">FAQs</a></li>
            <li class="active"><a href="contact.php">Contact</a></li>
        </ul>
        <div class="nav-right">
            <?php if (function_exists('userHasAccount') ? userHasAccount() : (isset($_SESSION['user_id']))): ?>
                <a href="user_dashboard.php" class="login-btn">Go to Dashboard</a>
            <?php else: ?>
                <a href="Login/login.php" class="login-btn">Login</a>
            <?php endif; ?>
        </div>
    </nav>
    <main style="max-width: 900px; margin: 40px auto; padding: 24px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07);">
        <h1>Contact Us</h1>
        <p>For inquiries, assistance, or to schedule a site visit, please use the following contact details:</p>
        <ul>
            <li>Email: <a href="mailto:nuevopuertarealestate@gmail.com">nuevopuertarealestate@gmail.com</a></li>
            <li>Facebook: <a href="https://www.facebook.com/nuevopuertarealestate" target="_blank">facebook.com/nuevopuertarealestate</a></li>
            <li>Phone: 0912-345-6789</li>
            <li>Office: Brgy. Example, City, Province</li>
        </ul>
        <p>We look forward to helping you with your real estate needs!</p>
    </main>
</body>
</html>
