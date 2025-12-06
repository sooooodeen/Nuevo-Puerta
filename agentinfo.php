<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";

// Connect to agentmanager database first
$agent_dbname = "agentmanager";
$agent_conn = new mysqli($servername, $username, $password, $agent_dbname);
if ($agent_conn->connect_error) {
    die("Connection to agentmanager database failed: " . $agent_conn->connect_error);
}

// Create a second connection for loginmanager database (agent_accounts)
$account_dbname = "loginmanager";
$account_conn = new mysqli($servername, $username, $password, $account_dbname);
if ($account_conn->connect_error) {
    die("Connection to loginmanager database failed: " . $account_conn->connect_error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $message = trim($_POST['message']);

    if ($name && $phone && $email) {
        $insert_sql = "INSERT INTO messages (agent_id, name, phone, email, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $agent_conn->prepare($insert_sql);
        $insert_stmt->bind_param("issss", $agent_id, $name, $phone, $email, $message);

        if ($insert_stmt->execute()) {
            echo "<script>alert('Message sent successfully!');</script>";
            echo "<script>window.location.href = '" . $_SERVER['PHP_SELF'] . "?id=" . $agent_id . "';</script>";
            exit;
        } else {
            echo "<script>alert('Failed to send message. Please try again.');</script>";
        }
    } else {
        echo "<script>alert('Please fill in all required fields.');</script>";
    }
}

// Fetch agent info
$agent_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// From loginmanager -> agent_accounts
$agent_sql = "SELECT * FROM agent_accounts WHERE id = ?";
$agent_stmt = $account_conn->prepare($agent_sql);
$agent_stmt->bind_param("i", $agent_id);
$agent_stmt->execute();
$agent_result = $agent_stmt->get_result();

if ($agent_result->num_rows > 0) {
    $agent = $agent_result->fetch_assoc();
} else {
    echo "<script>alert('Agent not found.');</script>";
    exit;
}

// Debug: Output the agent ID
echo "<script>console.log('Agent ID: " . htmlspecialchars($agent_id) . "');</script>";

// Fetch agent's photo from agentmanager -> agents using login_agent_id
$photo_sql = "SELECT photo FROM agents WHERE login_agent_id = ?";
$photo_stmt = $agent_conn->prepare($photo_sql);

if ($photo_stmt === false) {
    echo "<script>console.log('Error preparing statement: " . htmlspecialchars($agent_conn->error) . "');</script>";
} else {
    $photo_stmt->bind_param("i", $agent_id);

    if (!$photo_stmt->execute()) {
        echo "<script>console.log('Error executing query: " . htmlspecialchars($photo_stmt->error) . "');</script>";
    } else {
        $photo_result = $photo_stmt->get_result();

        if ($photo_result === false) {
            echo "<script>console.log('Error fetching result: " . htmlspecialchars($photo_stmt->error) . "');</script>";
        } else {
            if ($photo_result->num_rows > 0) {
                $photo_data = $photo_result->fetch_assoc();
                $agent['photo'] = $photo_data['photo'];

                // Debug: Check if the photo path is correct
                if (!file_exists($agent['photo'])) {
                    echo "<script>console.log('Photo path is incorrect or file does not exist: " . htmlspecialchars($agent['photo']) . "');</script>";
                    $agent['photo'] = 'assets/s.png'; // fallback image
                } else {
                    echo "<script>console.log('Photo path is correct: " . htmlspecialchars($agent['photo']) . "');</script>";
                }
            } else {
                echo "<script>console.log('No photo found for login_agent_id: " . htmlspecialchars($agent_id) . "');</script>";
                $agent['photo'] = 'assets/s.png'; // fallback image
            }
        }
    }
}

// Fetch sales from agentmanager -> sales
$sales_sql = "SELECT property, sale_price, sale_date FROM sales WHERE agent_id = ? ORDER BY sale_date DESC";
$sales_stmt = $agent_conn->prepare($sales_sql);
$sales_stmt->bind_param("i", $agent_id);
$sales_stmt->execute();
$sales_result = $sales_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?> - Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Full CSS you wrote */
        body {
            font-family: Arial, sans-serif;
            font-size: 18px;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background-color: #f8f8f8;
            text-align: center;
        }
        nav {
            background: #2d4e1e;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 70px;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }
        .nav-left, .nav-right {
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
        }
        .nav-left li, .nav-right li {
            margin-right: 40px;
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
            transform: translateX(-50%) translateY(-50%);
            top: 50%;
        }
        .nav-logo img {
            width: 80px;
            height: auto;
        }
        .container {
            width: 90%;
            max-width: 1200px;
            margin: 20px auto;
            padding-top: 90px;
        }
        .card {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .profile-header {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
        }
        .agent-info {
            flex: 1;
            max-width: 300px;
            text-align: center;
            border-right: 1px solid #ddd;
            padding-right: 20px;
        }
        .agent-info img {
            width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 50%;
            margin-bottom: 15px;
            border: 2px solid #ccc;
        }
        .sales-table {
            flex: 2;
            overflow-x: auto;
        }
        table {
            width: 100%;
            min-width: 500px;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background: #f2f2f2;
        }
        .bottom-section {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .about-section, .contact-card {
            flex: 1;
        }
        .contact-card form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .contact-card input, .contact-card textarea {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .contact-card button {
            padding: 12px;
            background-color: #2d482d;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .contact-card button:hover {
            background-color: #2d4e1e;
        }
        @media (max-width: 768px) {
            .profile-header, .bottom-section {
                flex-direction: column;
            }
            .agent-info {
                border: none;
                padding: 0;
            }
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
        <a href="landingpage.html">
            <img src="assets/f.png" alt="Logo">
        </a>
    </div>
    <ul class="nav-right">
        <li><a href="faqs.html">FAQs</a></li>
        <li><a href="contact.html">Contact</a></li>
        <li><a href="../login.html">Login</a></li>
    </ul>
</nav>

<div class="container">

    <div class="card profile-header">
        <div class="agent-info">
        <img src="<?php echo htmlspecialchars($agent['photo']); ?>" alt="Agent Photo" style="max-width: 100px; height: auto;">
            <div class="agent-details">
                <h1><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></h1>
                <p><?php echo htmlspecialchars($agent['address']); ?></p>
                <p>Experience: <?php echo htmlspecialchars($agent['experience']); ?> years</p>
                <p>Total Sales: <?php echo htmlspecialchars($agent['total_sales']); ?></p>
            </div>
        </div>

        <div class="sales-table">
            <h2>Recent Sales</h2>
            <?php if ($sales_result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Sale Price</th>
                            <th>Sale Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sale = $sales_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sale['property']); ?></td>
                            <td><?php echo number_format($sale['sale_price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No sales found for this agent.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bottom-section">
        <div class="card about-section">
            <h2>About</h2>
            <p><?php echo htmlspecialchars($agent['description']); ?></p>
        </div>

        <div class="card contact-card">
            <h2>Contact Us</h2>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $agent_id; ?>" method="POST">
                <input type="text" name="name" placeholder="Name" required>
                <input type="text" name="phone" placeholder="Phone" required>
                <input type="email" name="email" placeholder="Email" required>
                <textarea name="message" placeholder="Message (optional)" rows="4"></textarea>
                <button type="submit">Send Message</button>
            </form>
        </div>
    </div>

</div>

</body>
</html>
