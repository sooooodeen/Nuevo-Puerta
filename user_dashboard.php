<?php
// filepath: user_dashboard.php
session_start();

// 1. LOGIN CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: Login/login.php");
    exit;
}

/* ---------------- DB CONNECTION ---------------- */
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "nuevopuerta";

// Enable error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ---------------- HELPER FUNCTIONS ---------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Check if a column exists to prevent crashes
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeCol   = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}

function hasTable(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

// Wrapper to prepare SQL and catch errors
function prepOrDie(mysqli $conn, string $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Database Error: " . $conn->error . "<br>SQL: $sql");
    }
    return $stmt;
}

// >>> PAYMENTS AJAX HANDLER (Handles Pay Now & History) >>>
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

    // Fetch History
    if ($action === 'history') {
        if (!hasTable($conn,'payments')) {
            echo json_encode(['success'=>true,'payments'=>[]]); exit;
        }
        $stmt = prepOrDie($conn, "SELECT payment_date AS created_at, remarks AS description, lot_id, amount_paid, status FROM payments WHERE user_id=? ORDER BY payment_date DESC LIMIT 20");
        $stmt->bind_param('i',$uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        echo json_encode(['success'=>true,'payments'=>$rows]); exit;
    }

    // Process Payment
    if ($action === 'pay') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw,true);
        
        $lot_id  = (int)($data['lot_id'] ?? 0);
        $amount  = (float)($data['amount'] ?? 0);
        $remarks = trim((string)($data['description'] ?? 'Payment'));
        $method  = trim((string)($data['method'] ?? 'Manual'));
        
        if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid amount']); exit; }

        // Create payments table if missing (safety check)
        $conn->query("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT, lot_id INT, amount_paid DECIMAL(10,2), 
            payment_date DATETIME, payment_method VARCHAR(50), 
            reference_no VARCHAR(100), status VARCHAR(50), remarks TEXT
        )");

        $stmt = prepOrDie($conn,"INSERT INTO payments (user_id, lot_id, amount_paid, payment_date, payment_method, status, remarks) VALUES (?, ?, ?, NOW(), ?, 'Pending', ?)");
        $stmt->bind_param('iidss', $uid, $lot_id, $amount, $method, $remarks);
        
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Payment submitted successfully']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Database error']);
        }
        $stmt->close();
        exit;
    }

    // Send message to agent
    if ($action === 'send_agent_message') {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $agent_id = (int)($data['agent_id'] ?? 0);
            $message  = trim((string)($data['message'] ?? ''));

            if ($agent_id <= 0 || $message === '') {
                echo json_encode(['success' => false, 'message' => 'Agent and message are required.']);
                exit;
            }

            // Ensure messages table exists (compatible with agentnotification.php)
            $conn->query("CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                name VARCHAR(100) NULL,
                phone VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                message TEXT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_messages_agent (agent_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Add missing columns if they don't exist
            if (!hasColumn($conn, 'messages', 'is_read')) {
                $conn->query("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
            }
            if (!hasColumn($conn, 'messages', 'created_at')) {
                $conn->query("ALTER TABLE messages ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            }

            // Get sender details
            $senderName  = 'Client';
            $senderPhone = '';
            $senderEmail = '';

            if (hasTable($conn, 'user_accounts')) {
                $cols = ['first_name', 'last_name', 'email'];
                if (hasColumn($conn, 'user_accounts', 'mobile_number')) {
                    $cols[] = 'mobile_number';
                } elseif (hasColumn($conn, 'user_accounts', 'mobile')) {
                    $cols[] = 'mobile';
                }

                $colList = implode(', ', $cols);
                $stmtUser = $conn->prepare("SELECT $colList FROM user_accounts WHERE id = ? LIMIT 1");
                if ($stmtUser) {
                    $stmtUser->bind_param('i', $uid);
                    $stmtUser->execute();
                    $row = $stmtUser->get_result()->fetch_assoc();
                    $stmtUser->close();

                    if ($row) {
                        $senderName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        if ($senderName === '') $senderName = 'Client';
                        $senderEmail = (string)($row['email'] ?? '');
                        $senderPhone = (string)($row['mobile_number'] ?? ($row['mobile'] ?? ''));
                    }
                }
            }

            // Build INSERT based on actual columns
            $insertCols = ['agent_id', 'name', 'phone', 'email', 'message'];
            $insertVals = ['?', '?', '?', '?', '?'];
            $bindTypes  = 'issss';
            $bindParams = [&$agent_id, &$senderName, &$senderPhone, &$senderEmail, &$message];

            if (hasColumn($conn, 'messages', 'is_read')) {
                $insertCols[] = 'is_read';
                $insertVals[] = '0';
            }
            if (hasColumn($conn, 'messages', 'created_at')) {
                $insertCols[] = 'created_at';
                $insertVals[] = 'NOW()';
            }

            $sql = "INSERT INTO messages (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmtIns = $conn->prepare($sql);
            if (!$stmtIns) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit;
            }
            $stmtIns->bind_param($bindTypes, $agent_id, $senderName, $senderPhone, $senderEmail, $message);
            $ok = $stmtIns->execute();
            $stmtIns->close();

            if ($ok) {
                echo json_encode(['success' => true, 'message' => 'Message sent to agent.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send message.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    exit;
}

/* ---------------- PROFILE UPDATE ---------------- */
$profile_update_success = '';
$profile_update_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_action']) && $_POST['profile_action'] === 'update_profile') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) {
        $profile_update_error = 'Not authenticated.';
    } elseif (!hasTable($conn, 'user_accounts')) {
        $profile_update_error = 'User table not available.';
    } else {
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $middle_name = trim((string)($_POST['middle_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $username_in = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));

        if ($first_name === '' || $last_name === '' || $email === '') {
            $profile_update_error = 'First name, last name, and email are required.';
        } else {
            $fields = [];
            $types = '';
            $values = [];

            if (hasColumn($conn, 'user_accounts', 'first_name')) {
                $fields[] = 'first_name=?';
                $types .= 's';
                $values[] = $first_name;
            }
            if (hasColumn($conn, 'user_accounts', 'middle_name')) {
                $fields[] = 'middle_name=?';
                $types .= 's';
                $values[] = $middle_name;
            }
            if (hasColumn($conn, 'user_accounts', 'last_name')) {
                $fields[] = 'last_name=?';
                $types .= 's';
                $values[] = $last_name;
            }
            if (hasColumn($conn, 'user_accounts', 'username')) {
                $fields[] = 'username=?';
                $types .= 's';
                $values[] = $username_in;
            }
            if (hasColumn($conn, 'user_accounts', 'email')) {
                $fields[] = 'email=?';
                $types .= 's';
                $values[] = $email;
            }

            if (hasColumn($conn, 'user_accounts', 'mobile_number')) {
                $fields[] = 'mobile_number=?';
                $types .= 's';
                $values[] = $mobile;
            } elseif (hasColumn($conn, 'user_accounts', 'mobile')) {
                $fields[] = 'mobile=?';
                $types .= 's';
                $values[] = $mobile;
            }

            if (hasColumn($conn, 'user_accounts', 'address')) {
                $fields[] = 'address=?';
                $types .= 's';
                $values[] = $address;
            }

            if (empty($fields)) {
                $profile_update_error = 'No updatable fields found.';
            } else {
                $sql = 'UPDATE user_accounts SET ' . implode(', ', $fields) . ' WHERE id=?';
                $stmt = prepOrDie($conn, $sql);
                $types .= 'i';
                $values[] = $uid;
                $stmt->bind_param($types, ...$values);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $profile_update_success = 'Profile updated successfully.';
                } else {
                    $profile_update_error = 'Failed to update profile.';
                }
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        if ($profile_update_error !== '') {
            echo json_encode(['success' => false, 'message' => $profile_update_error]);
        } else {
            echo json_encode(['success' => true, 'message' => $profile_update_success ?: 'Profile updated successfully.']);
        }
        exit;
    }
}

/* ---------------- 1. FETCH USER DATA (FIXED LOGIN CRASH) ---------------- */
$user_id = (int)$_SESSION['user_id'];
$user = [
    'first_name' => '', 'middle_name' => '', 'last_name' => '', 
    'username' => '', 'email' => '', 'address' => '', 'mobile' => '', 'photo' => ''
];

if (hasTable($conn, 'user_accounts')) {
    // Determine which columns actually exist to prevent "Unknown column" error
    $cols = ['first_name','middle_name','last_name','username','email','address'];
    
    // CHECK BOTH MOBILE COLUMN NAMES
    $hasMobileNum = hasColumn($conn, 'user_accounts', 'mobile_number');
    $hasMobile    = hasColumn($conn, 'user_accounts', 'mobile');
    
    if ($hasMobileNum) $cols[] = 'mobile_number';
    if ($hasMobile)    $cols[] = 'mobile';
    if (hasColumn($conn,'user_accounts','photo')) $cols[] = 'photo';
    if (hasColumn($conn,'user_accounts','role'))  $cols[] = 'role';

    $colList = implode(', ', $cols);
    $stmt = prepOrDie($conn, "SELECT $colList FROM user_accounts WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows) { 
        $userData = $res->fetch_assoc();
        $user = array_merge($user, $userData);
        
        // SMART MOBILE DETECTION: Use whichever column has data
        $m1 = $userData['mobile_number'] ?? '';
        $m2 = $userData['mobile'] ?? '';
        $user['mobile'] = !empty($m1) ? $m1 : $m2;
    } else {
        // User ID in session but not in DB? Force logout.
        session_destroy();
        header("Location: Login/login.php");
        exit;
    }
    $stmt->close();
}

$user_email = $user['email'] ?? '';
$sidebarName = trim(($user['first_name']??'') . ' ' . ($user['last_name']??'')) ?: ($user['username']??'User');
$roleLabel = $user['role'] ?? 'Client';

/* ---------------- 2. FETCH PROPERTIES (LOTS) ---------------- */
$listings = [];
$lotsOwned = 0;
$reservedLots = 0;

if (hasTable($conn,'lots') && hasColumn($conn,'lots','owner_id')) {
    $cols = "id, block_number, lot_number, lot_size, lot_price";
    if (hasColumn($conn,'lots','agent_id')) $cols .= ", agent_id";
    
    $stmt = prepOrDie($conn, "SELECT $cols FROM lots WHERE owner_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { 
        $listings = $res->fetch_all(MYSQLI_ASSOC); 
        $lotsOwned = count($listings);
    }
    $stmt->close();

    if (hasColumn($conn,'lots','status')) {
        $stmt = prepOrDie($conn, "SELECT COUNT(*) as c FROM lots WHERE owner_id = ? AND status = 'Reserved'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $reservedLots = $row['c'] ?? 0;
        $stmt->close();
    }
}

/* ---------------- 3. FETCH VIEWINGS & NOTIFICATIONS ---------------- */
$upcomingViewings = [];
$recentActivities = [];

if (hasTable($conn,'viewings')) {
    // FIX: Add 'v.' prefix to explicitly select status from the viewings table
    $statusCol = hasColumn($conn,'viewings','status') ? 'v.status' : "'' as status";
    
    $agentJoin = hasTable($conn,'agent_accounts') ? "LEFT JOIN agent_accounts a ON v.agent_id = a.id" : "";
    $agentCols = hasTable($conn,'agent_accounts') ? ", a.first_name as agent_first, a.last_name as agent_last, a.email as agent_email" : "";

    // Fetch Viewings
    $sql = "SELECT v.id, v.lot_no, v.preferred_at, $statusCol $agentCols 
            FROM viewings v $agentJoin 
            WHERE v.client_email = ? 
            ORDER BY v.preferred_at DESC LIMIT 20";
            
    $stmt = prepOrDie($conn, $sql);
    $stmt->bind_param("s", $user_email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { 
        $data = $res->fetch_all(MYSQLI_ASSOC);
        // Split into upcoming and recent for notifications
        foreach($data as $row) {
            $upcomingViewings[] = $row; 
            $recentActivities[] = $row; 
        }
    }
    $stmt->close();
}

/* ---------------- 4. CALCULATE OUTSTANDING BALANCE ---------------- */
$outstandingBalance = 0.0;
if (!empty($listings) && hasTable($conn,'payments') && hasColumn($conn,'payments','amount_paid')) {
    foreach ($listings as $lot) {
        $l_id = (int)$lot['id'];
        $price = (float)($lot['lot_price'] ?? 0);
        
        $stmt = prepOrDie($conn, "SELECT SUM(amount_paid) as paid FROM payments WHERE user_id=? AND lot_id=?");
        $stmt->bind_param("ii", $user_id, $l_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $paid = (float)($row['paid'] ?? 0);
        $stmt->close();
        
        $outstandingBalance += max(0, $price - $paid);
    }
}

/* ---------------- 5. DASHBOARD ADD-ONS: AGENT MESSAGING + DOC PROGRESS + PAYMENT DEADLINE ---------------- */
$userAgents = [];

if (hasTable($conn, 'agent_accounts')) {
    // Agents linked to user's lots
    if (hasTable($conn, 'lots') && hasColumn($conn, 'lots', 'owner_id') && hasColumn($conn, 'lots', 'agent_id')) {
        $sql = "SELECT DISTINCT a.id, a.first_name, a.last_name, a.email
                FROM lots l
                INNER JOIN agent_accounts a ON a.id = l.agent_id
                WHERE l.owner_id = ?";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $userAgents[(int)$row['id']] = $row;
            }
        }
        $stmt->close();
    }

    // Agents linked to user's viewings
    if (hasTable($conn, 'viewings') && hasColumn($conn, 'viewings', 'agent_id') && hasColumn($conn, 'viewings', 'client_email')) {
        $sql = "SELECT DISTINCT a.id, a.first_name, a.last_name, a.email
                FROM viewings v
                INNER JOIN agent_accounts a ON a.id = v.agent_id
                WHERE v.client_email = ?";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('s', $user_email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $userAgents[(int)$row['id']] = $row;
            }
        }
        $stmt->close();
    }
}

$documentStats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'progress_percent' => 0
];

if (hasTable($conn, 'user_documents') && hasColumn($conn, 'user_documents', 'user_id')) {
    if (hasColumn($conn, 'user_documents', 'status')) {
        $stmt = prepOrDie($conn, "SELECT status, COUNT(*) AS total FROM user_documents WHERE user_id = ? GROUP BY status");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $status = strtolower(trim((string)($row['status'] ?? 'pending')));
                $count = (int)($row['total'] ?? 0);
                $documentStats['total'] += $count;

                if (in_array($status, ['approved', 'accepted', 'verified'], true)) {
                    $documentStats['approved'] += $count;
                } elseif (in_array($status, ['rejected', 'declined', 'denied'], true)) {
                    $documentStats['rejected'] += $count;
                } else {
                    $documentStats['pending'] += $count;
                }
            }
        }
        $stmt->close();
    } else {
        $stmt = prepOrDie($conn, "SELECT COUNT(*) AS total FROM user_documents WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $documentStats['total'] = (int)($row['total'] ?? 0);
        $documentStats['pending'] = $documentStats['total'];
        $stmt->close();
    }

    if ($documentStats['total'] > 0) {
        $documentStats['progress_percent'] = (int)round(($documentStats['approved'] / $documentStats['total']) * 100);
    }
}

$totalAmountPaid = 0.0;
if (hasTable($conn, 'payments') && hasColumn($conn, 'payments', 'user_id') && hasColumn($conn, 'payments', 'amount_paid')) {
    if (hasColumn($conn, 'payments', 'status')) {
        $stmt = prepOrDie($conn, "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
                                 FROM payments
                                 WHERE user_id = ? AND LOWER(status) IN ('paid', 'approved', 'completed')");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalAmountPaid = (float)($row['total_paid'] ?? 0);
        $stmt->close();

        // Fallback in case status values are not normalized yet
        if ($totalAmountPaid <= 0) {
            $stmt = prepOrDie($conn, "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE user_id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $totalAmountPaid = (float)($row['total_paid'] ?? 0);
            $stmt->close();
        }
    } else {
        $stmt = prepOrDie($conn, "SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalAmountPaid = (float)($row['total_paid'] ?? 0);
        $stmt->close();
    }
}

$paymentReminder = [
    'text' => 'No payment deadline set.',
    'date' => null,
    'days_left' => null,
    'overdue' => false
];

if (hasTable($conn, 'payments') && hasColumn($conn, 'payments', 'user_id')) {
    $deadlineColumn = null;
    foreach (['due_date', 'deadline', 'next_due_date'] as $col) {
        if (hasColumn($conn, 'payments', $col)) {
            $deadlineColumn = $col;
            break;
        }
    }

    if ($deadlineColumn !== null) {
        $statusFilter = '';
        if (hasColumn($conn, 'payments', 'status')) {
            $statusFilter = " AND LOWER(status) NOT IN ('paid', 'approved', 'completed')";
        }

        $sql = "SELECT $deadlineColumn AS due_at
                FROM payments
                WHERE user_id = ? AND $deadlineColumn IS NOT NULL $statusFilter
                ORDER BY $deadlineColumn ASC
                LIMIT 1";
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['due_at'])) {
            $dueDate = new DateTime($row['due_at']);
            $today = new DateTime('today');
            $daysLeft = (int)$today->diff($dueDate)->format('%r%a');

            $paymentReminder['date'] = $dueDate->format('M d, Y');
            $paymentReminder['days_left'] = $daysLeft;
            $paymentReminder['overdue'] = $daysLeft < 0;

            if ($daysLeft < 0) {
                $paymentReminder['text'] = 'Overdue by ' . abs($daysLeft) . ' day(s)';
            } elseif ($daysLeft === 0) {
                $paymentReminder['text'] = 'Due today';
            } else {
                $paymentReminder['text'] = 'Due in ' . $daysLeft . ' day(s)';
            }
        }
    }
}

/* ---------------- 6. FETCH ASSIGNED AGENT ---------------- */
$agent = null;
if (!empty($userAgents)) {
    $agentRow = reset($userAgents);
    $agent = [
        'id' => (int)($agentRow['id'] ?? 0),
        'first_name' => $agentRow['first_name'] ?? '',
        'last_name' => $agentRow['last_name'] ?? '',
        'email' => $agentRow['email'] ?? 'N/A',
        'photo_path' => 'assets/Default_photo.jpg'
    ];
}

$conn->close();

// Avatar handling
$avatarSrc = !empty($user['photo']) ? $user['photo'] : 'assets/Default_photo.jpg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Dashboard | Nuevo Puerta</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
:root { --green:#14532d; --light-green:#e6f4ea; --white:#ffffff; --bg:#f8f9fa; --text:#333; --muted:#666; --red:#dc3545; }
body { font-family:'Poppins', sans-serif; background:var(--bg); margin:0; color:var(--text); overflow-x: hidden; overflow-y: scroll; }
.dashboard-wrapper { display:flex; min-height:100vh; }

/* --- Sidebar --- */
.sidebar { width:280px; background:var(--green); color:var(--white); padding:30px 0; position:fixed; top:0; bottom:0; overflow-y:auto; transition:0.3s; z-index:1000; display:flex; flex-direction:column; align-items:center; }

/* Hide native scrollbar in the sidebar (remove scroll line) */
.sidebar { scrollbar-width: none; -ms-overflow-style: none; }
.sidebar::-webkit-scrollbar { width: 0; height: 0; }
.sidebar-logo { display:flex; align-items:center; gap:12px; margin-bottom:24px; padding:0 20px; width:100%; justify-content:center; }
.sidebar-logo img { width:56px; height:56px; border-radius:50%; background:rgba(255,255,255,0.1); padding:5px; object-fit:contain; }
.sidebar-brand { display:flex; flex-direction:column; line-height:1.1; }
.sidebar-logo h2 { margin:0; font-size:18px; font-weight:700; letter-spacing:0.5px; color: var(--white); }
.sidebar-logo span { font-size:12px; opacity:0.85; letter-spacing:1.5px; text-transform:uppercase; }

.sidebar-user { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.1); margin:0 auto 24px; padding:12px; border-radius:12px; width:220px; }
.sidebar-user img { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#fff; }
.sidebar-user div { line-height:1.2; }
.sidebar-user h3 { margin:0; font-size:14px; font-weight:600; }
.sidebar-user span { font-size:11px; opacity:0.85; }

nav {
    width: 100%;
}

.nav-link {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    padding: 12px 22px;
    color: rgba(255,255,255,0.9);
    text-decoration: none;
    transition: background 0.18s, color 0.18s, transform 0.12s;
    border-left: 4px solid transparent;
    font-size: 15px;
    margin: 6px 14px;
    border-radius: 8px;
    width: calc(100% - 28px);
}

.nav-link:hover,
.nav-link.active {
    background: rgba(255,255,255,0.06); /* softer hover so it reads as subtle */
    color: #fff;
    transform: translateY(-1px);
}
/* --- Main Content --- */
.main-content { margin-left:280px; flex:1; padding:35px 45px; }
.section { display:none; animation: fadeIn 0.4s ease; }
.section.active { display:block; }
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

h2 { font-size:32px; font-weight:700; color:var(--green); margin-bottom:8px; letter-spacing:-0.5px; }
.subtitle { color:var(--muted); margin-bottom:30px; display:block; font-size:15px; }

/* --- Cards --- */
.card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:30px; }
.stat-card { background:var(--white); padding:22px; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); display:flex; align-items:center; gap:16px; border:1px solid #f0f0f0; transition:all 0.3s ease; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(0,0,0,0.1); }
.stat-icon { width:56px; height:56px; background:var(--light-green); border-radius:11px; display:flex; align-items:center; justify-content:center; color:var(--green); font-size:24px; flex-shrink:0; }
.stat-info h3 { margin:0; font-size:28px; font-weight:700; color:var(--text); line-height:1; letter-spacing:-0.5px; }
.stat-info span { font-size:13px; color:var(--muted); margin-top:4px; display:block; }

/* --- Tables & Lists --- */
.content-box { background:var(--white); border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,0.06); padding:28px; border:1px solid #f0f0f0; margin-bottom:30px; }
.box-header { font-size:18px; font-weight:700; margin-bottom:22px; color:var(--green); border-bottom:2px solid #f0f0f0; padding-bottom:16px; letter-spacing:0.3px; }

table { width:100%; border-collapse:collapse; min-width:600px; }
th, td { padding:16px 15px; text-align:left; border-bottom:1px solid #f0f0f0; font-size:14px; }
th { font-weight:700; color:var(--text); background:#f9fbfd; text-transform:uppercase; font-size:12px; letter-spacing:0.5px; }
tr:last-child td { border-bottom:none; }
tbody tr:hover { background:#f9fbfd; }

.notification-list { list-style:none; padding:0; margin:0; }
.notif-item { padding:18px 0; border-bottom:1px solid #f0f0f0; display:flex; align-items:flex-start; gap:16px; }
.notif-item:last-child { border-bottom:none; }
.notif-icon { width:42px; height:42px; background:#f0f4f8; color:var(--green); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:18px; }
.notif-content strong { display:block; font-size:15px; margin-bottom:4px; color:var(--text); }
.notif-content span { font-size:13px; color:var(--muted); line-height:1.4; }

/* --- Forms --- */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; margin-bottom:8px; font-weight:600; font-size:13px; color:var(--text); text-transform:capitalize; letter-spacing:0.3px; }
.form-control { width:100%; padding:12px 14px; border:1.5px solid #e0e0e0; border-radius:9px; font-size:14px; box-sizing:border-box; transition:all 0.25s ease; background:#fafbfc; font-family:'Poppins', sans-serif; }
.form-control:hover { border-color:#d0d0d0; background:#fff; }
.form-control:focus { border-color:var(--green); outline:none; background:#fff; box-shadow:0 0 0 3px rgba(20, 83, 45, 0.1); }
.form-control[readonly] { background:#f5f5f5; color:#999; cursor:not-allowed; }

.flash-message {
    opacity: 1;
    transition: opacity 0.6s ease;
}

.flash-message.fade-out {
    opacity: 0;
}

.btn { padding:11px 28px; border:none; border-radius:9px; font-weight:600; cursor:pointer; font-size:14px; transition:all 0.3s ease; display:inline-block; letter-spacing:0.3px; }
.btn-primary { background:var(--green); color:var(--white); box-shadow:0 2px 8px rgba(20, 83, 45, 0.2); }
.btn-primary:hover { background:#0f4223; transform:translateY(-2px); box-shadow:0 4px 12px rgba(20, 83, 45, 0.3); }
.btn-primary:active { transform:translateY(0); }
.btn-secondary { background:#f0f0f0; color:#333; border:1.5px solid #e0e0e0; }
.btn-secondary:hover { background:#e8e8e8; border-color:#d0d0d0; }

.badge { padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700; text-transform:capitalize; letter-spacing:0.3px; display:inline-block; }
.badge.scheduled { background:#e0f2fe; color:#0284c7; }
.badge.pending { background:#fef3c7; color:#d97706; }
.badge.paid { background:#dcfce7; color:#166534; }

/* --- Agent Card --- */
.agent-card { display:flex; align-items:center; gap:22px; background:linear-gradient(135deg, #f9fbfd 0%, #fff 100%); padding:24px; border-radius:12px; border:1px solid #f0f0f0; }
.agent-img { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--white); box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.agent-details h3 { margin:0 0 6px; font-size:18px; font-weight:700; color:var(--text); }
.agent-details p { margin:6px 0; font-size:14px; color:var(--muted); }

/* Mobile */
@media (max-width: 900px) {
    .sidebar { width:0; padding:0; overflow:hidden; }
    .main-content { margin-left:0; padding:20px 24px; }
    .mobile-menu-btn { display:block; }
    .form-grid { grid-template-columns:1fr !important; }
    .card-grid { grid-template-columns:1fr; }
    h2 { font-size:24px; }
    .subtitle { margin-bottom:20px; }
}

/* Logout icon flip and white text for logout link */
.logout-icon { display:inline-block; transform: scaleX(-1); -webkit-transform: scaleX(-1); }
.logout-link { color: #ffffff !important; }

/* --- Message & Document Sections --- */
.message-section { margin-bottom:30px; }
.message-section .form-grid { grid-template-columns:1fr 2fr; }

#msg_agent_text { resize:vertical; min-height:90px; }

.progress-container { margin:20px 0; }
.progress-bar { background:#f1f5f9; border-radius:12px; height:12px; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg, var(--green) 0%, #0f4223 100%); border-radius:12px; transition:width 0.4s ease; }
.progress-text { margin-top:12px; font-size:14px; font-weight:600; color:var(--green); }

/* --- Empty States --- */
.empty-state { text-align:center; padding:40px 20px; color:var(--muted); }
.empty-state p { font-size:15px; line-height:1.6; }

/* --- Modal Styles --- */
.modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:2000; display:none; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-content { background:var(--white); border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,0.2); width:90%; max-width:500px; overflow:hidden; animation:modalSlideIn 0.3s ease; }
@keyframes modalSlideIn { from { transform:translateY(-30px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-header { padding:24px; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; }
.modal-header h3 { margin:0; font-size:20px; font-weight:700; color:var(--green); }
.modal-close-btn { background:none; border:none; font-size:24px; color:var(--muted); cursor:pointer; width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:6px; transition:0.2s; }
.modal-close-btn:hover { background:#f5f5f5; color:var(--text); }
.modal-body { padding:24px; }
.modal-body .form-group { margin-bottom:18px; }
.modal-footer { padding:20px 24px; border-top:1px solid #f0f0f0; display:flex; gap:12px; justify-content:flex-end; }
.modal-footer .btn { margin:0; }

/* --- Payment Page Styles --- */
.payment-info-card { background:linear-gradient(135deg, #f0fdf4 0%, #f1f5f9 100%); border:1.5px solid #bbf7d0; border-radius:12px; padding:20px; margin-bottom:30px; }
.payment-info-card h4 { margin:0 0 8px 0; color:var(--green); font-weight:700; }
.payment-hint { background:#f9fbfd; padding:18px; border-radius:10px; margin-bottom:20px; border-left:4px solid var(--green); }
.payment-hint p { margin:0; color:var(--muted); font-size:13px; }
.payment-warning { background:#fef3c7; border:1px solid #fcd34d; border-radius:9px; padding:14px; margin-bottom:20px; }
.payment-warning p { margin:0; color:#92400e; font-size:13px; }
.payment-status-guide { background:#f9fbfd; padding:16px; border-radius:9px; margin-bottom:18px; border-left:3px solid #0284c7; }
.payment-status-guide p { margin:0; color:var(--muted); font-size:13px; }
</style>
</head>
<body>

<div class="dashboard-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="assets/f.png" alt="Logo" onerror="this.src='assets/Default_photo.jpg'">
            <div class="sidebar-brand">
                <h2>NUEVO PUERTA</h2>
                <span>Real Estate</span>
            </div>
        </div>
        
        <div class="sidebar-user">
            <img src="<?php echo h($avatarSrc); ?>" alt="User">
            <div>
                <h3><?php echo h($sidebarName); ?></h3>
                <span><?php echo h($roleLabel); ?></span>
            </div>
        </div>

        <nav>
            <a href="#dashboard" class="nav-link active" onclick="switchTab('dashboard', this)"><i class="fa fa-th-large"></i> Dashboard</a>
            <a href="#profile" class="nav-link" onclick="switchTab('profile', this)"><i class="fa fa-user"></i> My Profile</a>
            <a href="#properties" class="nav-link" onclick="switchTab('properties', this)"><i class="fa fa-building"></i> Properties</a>
            <a href="#viewings" class="nav-link" onclick="switchTab('viewings', this)"><i class="fa fa-calendar-check"></i> Viewings</a>
            <a href="#documents" class="nav-link" onclick="switchTab('documents', this)"><i class="fa fa-folder-open"></i> Documents</a>
            <a href="#notifications" class="nav-link" onclick="switchTab('notifications', this)"><i class="fa fa-bell"></i> Notifications</a>
            <a href="#agent" class="nav-link" onclick="switchTab('agent', this)"><i class="fa fa-user-tie"></i> My Agent</a>
            <a href="#" class="nav-link logout-link" style="margin-top:20px;" onclick="confirmLogout(event)"><i class="fa fa-sign-out-alt logout-icon"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-content">
        
        <div id="dashboard" class="section active">
            <h2>Dashboard</h2>
            <span class="subtitle">Welcome back, <?php echo h($user['first_name']); ?></span>

            <div class="card-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa fa-home"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $lotsOwned; ?></h3>
                        <span>Properties Owned</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa fa-file-signature"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $reservedLots; ?></h3>
                        <span>Reserved Lots</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fee2e2; color:#dc2626;"><i class="fa fa-coins"></i></div>
                    <div class="stat-info">
                        <h3>₱<?php echo number_format($outstandingBalance, 2); ?></h3>
                        <span>Balance Due</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#dcfce7; color:#166534;"><i class="fa fa-wallet"></i></div>
                    <div class="stat-info">
                        <h3>₱<?php echo number_format($totalAmountPaid, 2); ?></h3>
                        <span>Total Amount Paid</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff7ed; color:#c2410c;"><i class="fa fa-hourglass-half"></i></div>
                    <div class="stat-info">
                        <h3 style="font-size:20px;"><?php echo h($paymentReminder['text']); ?></h3>
                        <span>Payment Deadline Reminder<?php echo !empty($paymentReminder['date']) ? ' - ' . h($paymentReminder['date']) : ''; ?></span>
                    </div>
                </div>
            </div>

            <div class="content-box">
                <div class="box-header">Document Progress</div>
                <div class="card-grid" style="margin-bottom: 18px;">
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['total']; ?></h3><span>Total Documents</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['approved']; ?></h3><span>Approved</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['pending']; ?></h3><span>Pending</span></div></div>
                    <div class="stat-card"><div class="stat-info"><h3><?php echo (int)$documentStats['rejected']; ?></h3><span>Rejected</span></div></div>
                </div>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo (int)$documentStats['progress_percent']; ?>%;"></div>
                    </div>
                    <p class="progress-text">Approval Progress: <strong><?php echo (int)$documentStats['progress_percent']; ?>%</strong></p>
                </div>
            </div>

            <div class="content-box">
                <div class="box-header">Recent Viewings & Activity</div>
                <?php if(empty($recentActivities)): ?>
                    <p style="color:var(--muted); text-align:center;">No recent activity.</p>
                <?php else: ?>
                    <ul class="notification-list">
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-calendar-day"></i></div>
                            <div class="notif-content">
                                <strong>Viewing for Lot <?php echo h($act['lot_no']); ?></strong>
                                <span><?php echo date('F d, Y h:i A', strtotime($act['preferred_at'])); ?> • Status: <?php echo h($act['status']); ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div id="profile" class="section">
            <h2>My Profile</h2>
            <span class="subtitle">Manage your personal information</span>

            <div class="content-box">
                <?php if (!empty($profile_update_success)): ?>
                    <div id="profile-update-success" class="flash-message" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:10px 12px; border-radius:8px; margin-bottom:16px;">
                        <?php echo h($profile_update_success); ?>
                    </div>
                <?php elseif (!empty($profile_update_error)): ?>
                    <div style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:10px 12px; border-radius:8px; margin-bottom:16px;">
                        <?php echo h($profile_update_error); ?>
                    </div>
                <?php endif; ?>

                <form id="profile-form" method="post" class="form-grid">
                    <input type="hidden" name="profile_action" value="update_profile">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo h($user['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo h($user['last_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo h($user['username']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo h($user['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="mobile" class="form-control" value="<?php echo h($user['mobile']); ?>">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" value="<?php echo h($user['address']); ?>">
                    </div>
                    <div class="form-group" style="grid-column: span 2; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="properties" class="section">
            <h2>My Properties</h2>
            <span class="subtitle">List of lots you own or have reserved</span>

            <div class="card-grid">
                <?php if(empty($listings)): ?>
                    <div class="content-box" style="grid-column: span 3; text-align:center;">
                        <p>No properties found linked to this account.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($listings as $lot): ?>
                    <div class="stat-card" style="display:block; border-left:4px solid var(--green); padding:24px; position:relative; overflow:hidden;">
                        <div style="position:absolute; top:0; right:0; width:60px; height:60px; background:rgba(20, 83, 45, 0.05); border-radius:0 12px 0 999px;"></div>
                        <h4 style="margin:0 0 16px; color:var(--green); font-size:18px; font-weight:700;">
                            Block <?php echo h($lot['block_number']); ?>, Lot <?php echo h($lot['lot_number']); ?>
                        </h4>
                        <div style="font-size:14px; margin-bottom:8px;">
                            <strong style="color:var(--text);">Size:</strong> <span style="color:var(--muted);"><?php echo h($lot['lot_size']); ?> sqm</span>
                        </div>
                        <div style="font-size:14px; margin-bottom:18px;">
                            <strong style="color:var(--text);">Price:</strong> <span style="color:var(--green); font-weight:700;">₱<?php echo number_format($lot['lot_price'], 2); ?></span>
                        </div>
                        <button class="btn btn-secondary" style="width:100%; font-size:13px;">View Details</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="viewings" class="section">
            <h2>My Viewings</h2>
            <span class="subtitle">Scheduled visits to properties</span>

            <div class="content-box">
                <table>
                    <thead>
                        <tr><th>Lot</th><th>Date & Time</th><th>Agent</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($upcomingViewings)): ?>
                            <tr><td colspan="4" style="text-align:center;">No viewings found.</td></tr>
                        <?php else: ?>
                            <?php foreach($upcomingViewings as $v): ?>
                            <tr>
                                <td><?php echo h($v['lot_no']); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($v['preferred_at'])); ?></td>
                                <td>
                                    <?php echo h(($v['agent_first']??'') . ' ' . ($v['agent_last']??'')); ?>
                                </td>
                                <td><span class="badge scheduled"><?php echo h($v['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="documents" class="section">
            <h2>Documents</h2>
            <span class="subtitle">Contracts and uploaded files</span>
            <div class="content-box">
                <div class="box-header">Document Progress</div>
                <table>
                    <thead>
                        <tr><th>Total</th><th>Approved</th><th>Pending</th><th>Rejected</th><th>Progress</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo (int)$documentStats['total']; ?></td>
                            <td><?php echo (int)$documentStats['approved']; ?></td>
                            <td><?php echo (int)$documentStats['pending']; ?></td>
                            <td><?php echo (int)$documentStats['rejected']; ?></td>
                            <td><?php echo (int)$documentStats['progress_percent']; ?>%</td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top:10px; color:var(--muted); font-size:13px;">Tip: Upload documents via your existing document upload flow. This section reflects current review statuses.</p>
            </div>
        </div>

        <div id="notifications" class="section">
            <h2>Notifications</h2>
            <span class="subtitle">System alerts</span>
            <div class="content-box">
                <ul class="notification-list">
                    <?php if(!empty($recentActivities)): ?>
                        <?php foreach($recentActivities as $act): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-bell"></i></div>
                            <div class="notif-content">
                                <strong>System Notification</strong>
                                <span>Your viewing for Lot <?php echo h($act['lot_no']); ?> is <?php echo h($act['status']); ?>.</span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li style="padding:15px; text-align:center;">No new notifications.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div id="agent" class="section">
            <h2>My Agent</h2>
            <span class="subtitle">Your assigned sales agent</span>
            <div class="content-box">
                <?php if($agent): ?>
                <div class="agent-card">
                    <img src="assets/Default_photo.jpg" class="agent-img" alt="Agent">
                    <div class="agent-details">
                        <h3><?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?></h3>
                        <p><i class="fa fa-envelope"></i> <?php echo h($agent['email']); ?></p>
                        <div style="margin-top:10px;">
                            <button class="btn btn-primary btn-sm" onclick="openMessageModal(<?php echo (int)$agent['id']; ?>)">Message Agent</button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <p style="text-align:center;">No agent assigned to you yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <!-- Message Modal -->
    <div id="messageModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Message to Agent</h3>
                <button class="modal-close-btn" onclick="closeMessageModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Agent</label>
                    <input type="text" id="modal_agent_name" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Your Message</label>
                    <textarea id="modal_message_text" class="form-control" placeholder="Type your message..."></textarea>
                </div>
                <div id="modal_msg_status" style="font-weight:600; margin-bottom:12px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeMessageModal()">Cancel</button>
                <button class="btn btn-primary" onclick="sendModalMessage()">Send Message</button>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(id, link) {
    // Prevent default anchor link behavior (which causes scrolling)
    event.preventDefault();
    
    // Hide all sections
    document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
    // Deactivate nav links
    document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
    
    // Show target section
    document.getElementById(id).classList.add('active');
    // Activate clicked link
    link.classList.add('active');
}

function sendAgentMessage() {
    const agentIdEl = document.getElementById('msg_agent_id');
    const msgEl = document.getElementById('msg_agent_text');
    const statusEl = document.getElementById('agentMsgStatus');

    if (!agentIdEl || !msgEl || !statusEl) return;

    const agent_id = parseInt(agentIdEl.value || '0', 10);
    const message = msgEl.value.trim();

    if (!agent_id || !message) {
        statusEl.innerHTML = '<span style="color:#dc2626;">Please select an agent and type your message.</span>';
        return;
    }

    statusEl.innerHTML = '<span style="color:#0284c7;">Sending...</span>';

    fetch('?action=send_agent_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agent_id, message })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<span style="color:#166534;">' + (data.message || 'Message sent.') + '</span>';
            msgEl.value = '';
        } else {
            statusEl.innerHTML = '<span style="color:#dc2626;">' + (data.message || 'Failed to send message.') + '</span>';
        }
    })
    .catch(() => {
        statusEl.innerHTML = '<span style="color:#dc2626;">Request failed. Please try again.</span>';
    });
}

// Modal functions
let currentAgentId = null;

function openMessageModal(agentId) {
    currentAgentId = agentId;
    const modal = document.getElementById('messageModal');
    const agentNameEl = document.getElementById('modal_agent_name');
    
    // Get agent name from button's context
    const agentCard = event.target.closest('.agent-card');
    if (agentCard) {
        const agentName = agentCard.querySelector('.agent-details h3');
        if (agentName) {
            agentNameEl.value = agentName.innerText;
        }
    }
    
    // Clear previous message and status
    document.getElementById('modal_message_text').value = '';
    document.getElementById('modal_msg_status').innerHTML = '';
    
    modal.classList.add('active');
}

function closeMessageModal() {
    const modal = document.getElementById('messageModal');
    modal.classList.remove('active');
    currentAgentId = null;
}

function sendModalMessage() {
    const msgEl = document.getElementById('modal_message_text');
    const statusEl = document.getElementById('modal_msg_status');

    if (!msgEl || !statusEl) return;

    const message = msgEl.value.trim();

    if (!currentAgentId || !message) {
        statusEl.innerHTML = '<span style="color:#dc2626;">Please type a message.</span>';
        return;
    }

    statusEl.innerHTML = '<span style="color:#0284c7;">Sending...</span>';

    fetch('?action=send_agent_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agent_id: currentAgentId, message })
    })
    .then(r => {
        if (!r.ok) throw new Error('Server responded with status ' + r.status);
        return r.text();
    })
    .then(text => {
        try { return JSON.parse(text); } catch(e) { throw new Error('Invalid server response'); }
    })
    .then(data => {
        if (data.success) {
            statusEl.innerHTML = '<span style="color:#166534;">' + (data.message || 'Message sent successfully!') + '</span>';
            msgEl.value = '';
            setTimeout(() => closeMessageModal(), 1500);
        } else {
            statusEl.innerHTML = '<span style="color:#dc2626;">' + (data.message || 'Failed to send message.') + '</span>';
        }
    })
    .catch((err) => {
        statusEl.innerHTML = '<span style="color:#dc2626;">Request failed: ' + err.message + '</span>';
    });
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMessageModal();
    }
});

// Close modal on overlay click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('messageModal');
    if (e.target === modal) {
        closeMessageModal();
    }
});

// Initial Load

// Auto-hide profile update success message
document.addEventListener('DOMContentLoaded', function() {
    const msg = document.getElementById('profile-update-success');
    if (msg) {
        setTimeout(function() {
            msg.style.display = 'none';
        }, 3000);
    }
});

// Admin-style dynamic logout confirm modal
function confirmLogout(e) {
        if (e && e.preventDefault) e.preventDefault();
        // prevent multiple modals
        if (document.getElementById('admin-logout-modal')) return;

        const modal = document.createElement('div');
        modal.id = 'admin-logout-modal';
        modal.innerHTML = `
            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif;">
                <div style="background: white; padding: 0; border-radius: 8px; width: 400px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                    <div style="padding: 24px 24px 16px 24px;">
                        <div style="display: flex; align-items: flex-start; gap: 16px;">
                            <div style="width: 32px; height: 32px; background: #fff3cd; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 4px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                    <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z" stroke="#856404" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 600; color: #212529;">Confirm Logout</h3>
                                <p style="margin: 0; font-size: 16px; color: #6c757d;">Are you sure you want to logout? You will need to login again to access your dashboard.</p>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 20px 24px 24px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e9ecef;">
                        <button id="cancel-logout" style="background: #f8f9fa; color: #6c757d; border: 1px solid #ced4da; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Cancel</button>
                        <button id="confirm-logout" style="background: #dc3545; color: white; border: 1px solid #dc3545; padding: 10px 20px; border-radius: 4px; font-size: 16px; cursor: pointer; min-width: 90px;">Logout</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // handlers
        const cancelBtn = modal.querySelector('#cancel-logout');
        const okBtn = modal.querySelector('#confirm-logout');

        function removeModal() { if (modal && modal.parentNode) modal.parentNode.removeChild(modal); }

        cancelBtn && cancelBtn.addEventListener('click', function(){ removeModal(); });
        okBtn && okBtn.addEventListener('click', function(){ window.location.href = 'logout.php'; });

        // close on Escape
        function escHandler(ev){ if(ev.key === 'Escape') { removeModal(); document.removeEventListener('keydown', escHandler); } }
        document.addEventListener('keydown', escHandler);
}
</script>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="modal" aria-hidden="true">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="modal-row">
            <div class="modal-icon" aria-hidden="true"><i class="fa fa-exclamation-circle"></i></div>
            <div class="modal-body">
                <h3 id="logoutTitle">Confirm Logout</h3>
                <p>Are you sure you want to logout?</p>
            </div>
        </div>
        <div class="modal-divider" aria-hidden="true"></div>
        <div class="modal-actions">
            <button class="btn-outline" onclick="closeLogoutConfirm()">Cancel</button>
            <button class="btn-danger" style="margin-left:8px;" onclick="confirmLogout()">Logout</button>
        </div>
    </div>
</div>

</body>
</html>