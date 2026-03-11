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

    // Fetch payment transactions for user's own lots
    if ($action === 'lot_payments') {
        $rows = [];
        if (hasTable($conn, 'lot_payment_transactions')) {
            $sql = "SELECT t.id, t.lot_id, t.amount, t.payment_date, t.payment_method, t.remarks,
                           l.block_number, l.lot_number, l.lot_price,
                           IFNULL(l.payment_amount, 0) AS amount_paid_so_far,
                           l.payment_deadline, l.payment_type, l.status
                    FROM lot_payment_transactions t
                    INNER JOIN lots l ON l.id = t.lot_id
                    WHERE l.owner_id = ?
                    ORDER BY t.payment_date ASC, t.id ASC
                    LIMIT 200";
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        echo json_encode(['success' => true, 'transactions' => $rows]);
        exit;
    }

    if ($action === 'installment_plan') {
        $lotId = (int)($_GET['lot_id'] ?? 0);
        if ($lotId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid lot.']);
            exit;
        }

        $lotSql = "SELECT id, block_number, lot_number, lot_price,
                          IFNULL(payment_amount, 0) AS payment_amount,
                          IFNULL(down_payment_amount, 0) AS down_payment_amount,
                          payment_deadline, payment_term_years, payment_due_day,
                          payment_type, status
                   FROM lots
                   WHERE id = ? AND owner_id = ?
                   LIMIT 1";
        $stmt = prepOrDie($conn, $lotSql);
        $stmt->bind_param('ii', $lotId, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $plan = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$plan) {
            echo json_encode(['success' => false, 'message' => 'Installment plan not found.']);
            exit;
        }

        $transactions = [];
        if (hasTable($conn, 'lot_payment_transactions')) {
            $txSql = "SELECT id, amount, payment_date, payment_method, remarks
                      FROM lot_payment_transactions
                      WHERE lot_id = ? AND (user_id = ? OR user_id IS NULL)
                      ORDER BY payment_date ASC, id ASC";
            $stmt = prepOrDie($conn, $txSql);
            $stmt->bind_param('ii', $lotId, $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $transactions = $res->fetch_all(MYSQLI_ASSOC);
            }
            $stmt->close();
        }

        echo json_encode([
            'success' => true,
            'plan' => $plan,
            'transactions' => $transactions
        ]);
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
    if (hasColumn($conn,'lots','payment_type'))    $cols .= ", payment_type";
    if (hasColumn($conn,'lots','status'))         $cols .= ", status";
    if (hasColumn($conn,'lots','agent_id'))      $cols .= ", agent_id";
    if (hasColumn($conn,'lots','payment_amount')) $cols .= ", payment_amount";
    if (hasColumn($conn,'lots','down_payment_amount')) $cols .= ", down_payment_amount";
    if (hasColumn($conn,'lots','payment_deadline')) $cols .= ", payment_deadline";
    if (hasColumn($conn,'lots','payment_term_years')) $cols .= ", payment_term_years";
    if (hasColumn($conn,'lots','payment_due_day')) $cols .= ", payment_due_day";
    
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
$systemNotifications = [];

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

if (hasTable($conn, 'notifications')) {
    $selectCols = [];
    if (hasColumn($conn, 'notifications', 'title')) $selectCols[] = 'title';
    if (hasColumn($conn, 'notifications', 'message')) $selectCols[] = 'message';
    if (hasColumn($conn, 'notifications', 'type')) $selectCols[] = 'type';
    if (hasColumn($conn, 'notifications', 'created_at')) $selectCols[] = 'created_at';

    if (!empty($selectCols)) {
        $whereSql = '';
        $types = '';
        $params = [];

        if (hasColumn($conn, 'notifications', 'recipient_type') && hasColumn($conn, 'notifications', 'recipient_id')) {
            $whereSql = ' WHERE recipient_type = ? AND recipient_id = ? ';
            $types = 'si';
            $recipientType = 'user';
            $params = [$recipientType, $user_id];
        } elseif (hasColumn($conn, 'notifications', 'recipient_id')) {
            $whereSql = ' WHERE recipient_id = ? ';
            $types = 'i';
            $params = [$user_id];
        } elseif (hasColumn($conn, 'notifications', 'user_id')) {
            $whereSql = ' WHERE user_id = ? ';
            $types = 'i';
            $params = [$user_id];
        }

        if ($whereSql !== '') {
            $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM notifications ' . $whereSql . ' ORDER BY created_at DESC LIMIT 20';
            $stmt = prepOrDie($conn, $sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $systemNotifications = $res->fetch_all(MYSQLI_ASSOC);
            }
            $stmt->close();
        }
    }
}

if (empty($systemNotifications) && hasTable($conn, 'user_notifications')) {
    $selectCols = [];
    if (hasColumn($conn, 'user_notifications', 'title')) $selectCols[] = 'title';
    if (hasColumn($conn, 'user_notifications', 'message')) $selectCols[] = 'message';
    if (hasColumn($conn, 'user_notifications', 'type')) $selectCols[] = 'type';
    if (hasColumn($conn, 'user_notifications', 'created_at')) $selectCols[] = 'created_at';

    if (!empty($selectCols) && hasColumn($conn, 'user_notifications', 'user_id')) {
        $orderCol = hasColumn($conn, 'user_notifications', 'created_at') ? 'created_at' : 'id';
        $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM user_notifications WHERE user_id = ? ORDER BY ' . $orderCol . ' DESC LIMIT 20';
        $stmt = prepOrDie($conn, $sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $systemNotifications = $res->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
}

/* ---------------- 4. CALCULATE OUTSTANDING BALANCE ---------------- */
$outstandingBalance = 0.0;
foreach ($listings as $lot) {
    $price = (float)($lot['lot_price'] ?? 0);
    $paid  = (float)($lot['payment_amount'] ?? 0);
    $outstandingBalance += max(0, $price - $paid);
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

$documentItems = [];
$contractDocs = [];
$agreementDocs = [];

$previousPayments = [];

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

    // Full document list for Contracts and Agreements copies
    $docSql = "SELECT id, file_name, file_path, uploaded_at, doc_type, status
               FROM user_documents
               WHERE user_id = ?
               ORDER BY uploaded_at DESC
               LIMIT 120";
    $stmt = prepOrDie($conn, $docSql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $documentItems = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();

    foreach ($documentItems as $doc) {
        $docType = strtolower(trim((string)($doc['doc_type'] ?? '')));
        if (str_contains($docType, 'contract')) {
            $contractDocs[] = $doc;
        }
        if (str_contains($docType, 'agreement') || str_contains($docType, 'waiver') || str_contains($docType, 'terms')) {
            $agreementDocs[] = $doc;
        }
    }
}

// Previous payments history for user-owned lots
if (hasTable($conn, 'lot_payment_transactions') && hasTable($conn, 'lots')) {
    $paySql = "SELECT t.payment_date, t.amount, t.payment_method, t.remarks,
                      t.lot_id,
                      l.block_number, l.lot_number
               FROM lot_payment_transactions t
               INNER JOIN lots l ON l.id = t.lot_id
               WHERE (t.user_id = ? OR l.owner_id = ?)
               ORDER BY t.payment_date ASC, t.id ASC
               LIMIT 120";
    $stmt = prepOrDie($conn, $paySql);
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $previousPayments = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$paymentsByLot = [];
$paidMonthsByLot = [];
foreach ($previousPayments as $paymentRow) {
    $paymentLotId = (int)($paymentRow['lot_id'] ?? 0);
    if ($paymentLotId <= 0) {
        continue;
    }
    $paymentsByLot[$paymentLotId] = ($paymentsByLot[$paymentLotId] ?? 0.0) + (float)($paymentRow['amount'] ?? 0);

    $paymentDateRaw = trim((string)($paymentRow['payment_date'] ?? ''));
    if ($paymentDateRaw !== '') {
        $monthKey = date('Y-m', strtotime($paymentDateRaw));
        if ($monthKey) {
            if (!isset($paidMonthsByLot[$paymentLotId])) {
                $paidMonthsByLot[$paymentLotId] = [];
            }
            $paidMonthsByLot[$paymentLotId][$monthKey] = true;
        }
    }
}

/**
 * Build a due date using a reference month and due-day value.
 */
function buildDueDateForMonth(DateTime $referenceMonth, int $dueDay): DateTime {
    $year = (int)$referenceMonth->format('Y');
    $month = (int)$referenceMonth->format('m');
    $daysInMonth = (int)$referenceMonth->format('t');
    $day = max(1, min($dueDay, $daysInMonth));

    $due = clone $referenceMonth;
    $due->setDate($year, $month, $day);
    $due->setTime(0, 0, 0);
    return $due;
}

/**
 * Compute the next unpaid installment due date for a lot.
 */
function computeNextLotDueDate(array $lot, array $paidMonthsByLot): ?DateTime {
    $paymentType = trim((string)($lot['payment_type'] ?? ''));
    if ($paymentType !== 'Down Payment') {
        return null;
    }

    $deadlineRaw = trim((string)($lot['payment_deadline'] ?? ''));
    if ($deadlineRaw === '') {
        return null;
    }

    try {
        $anchor = new DateTime($deadlineRaw);
    } catch (Exception $e) {
        return null;
    }

    $dueDay = (int)($lot['payment_due_day'] ?? 0);
    if ($dueDay < 1 || $dueDay > 31) {
        $dueDay = (int)$anchor->format('j');
    }

    $termYears = (int)($lot['payment_term_years'] ?? 0);
    $maxMonths = $termYears > 0 ? ($termYears * 12) : 120;

    $lotId = (int)($lot['id'] ?? 0);
    $paidMonths = $paidMonthsByLot[$lotId] ?? [];

    for ($i = 0; $i < $maxMonths; $i++) {
        $targetMonth = clone $anchor;
        if ($i > 0) {
            $targetMonth->modify("+{$i} month");
        }

        $candidate = buildDueDateForMonth($targetMonth, $dueDay);
        $monthKey = $candidate->format('Y-m');

        if (!isset($paidMonths[$monthKey])) {
            return $candidate;
        }
    }

    return null;
}

$nextPaymentCardAmount = 'N/A';
$nextPaymentCardDate = 'No due date set';

$paymentReminder = [
    'text' => 'No payment deadline set.',
    'date' => null,
    'days_left' => null,
    'overdue' => false
];
$downPaymentDeadlines = [];

if (hasTable($conn, 'lots') && hasColumn($conn, 'lots', 'payment_deadline')) {
    $nextDeadline = null;

    foreach ($listings as $lot) {
        $candidate = computeNextLotDueDate($lot, $paidMonthsByLot);
        if (!$candidate) {
            continue;
        }

        $today = new DateTime('today');
        $daysLeftEach = (int)$today->diff($candidate)->format('%r%a');
        if ($daysLeftEach < 0) {
            $deadlineText = 'Overdue by ' . abs($daysLeftEach) . ' day(s)';
        } elseif ($daysLeftEach === 0) {
            $deadlineText = 'Due today';
        } else {
            $deadlineText = 'Due in ' . $daysLeftEach . ' day(s)';
        }

        $nextScheduledAmount = (float)($lot['payment_amount'] ?? 0);
        $downPaymentDeadlines[] = [
            'lot_id' => (int)($lot['id'] ?? 0),
            'sort_date' => $candidate->format('Y-m-d'),
            'lot_label' => 'Block ' . ($lot['block_number'] ?? 'N/A') . ', Lot ' . ($lot['lot_number'] ?? 'N/A'),
            'date' => $candidate->format('M d, Y'),
            'amount' => $nextScheduledAmount,
            'status_text' => $deadlineText,
            'due_day' => (int)($lot['payment_due_day'] ?? 0),
            'days_left' => $daysLeftEach
        ];

        if ($nextDeadline === null || $candidate < $nextDeadline) {
            $nextDeadline = $candidate;
        }
    }

    if (!empty($downPaymentDeadlines)) {
        usort($downPaymentDeadlines, static function ($a, $b) {
            return strcmp($a['sort_date'], $b['sort_date']);
        });

        $nearestPayment = $downPaymentDeadlines[0];
        $nextPaymentCardDate = $nearestPayment['date'] ?? 'No due date set';
        if (!empty($nearestPayment['amount']) && (float)$nearestPayment['amount'] > 0) {
            $nextPaymentCardAmount = '₱' . number_format((float)$nearestPayment['amount'], 2);
        }
    }

    if ($nextDeadline !== null) {
        $today = new DateTime('today');
        $daysLeft = (int)$today->diff($nextDeadline)->format('%r%a');

        $paymentReminder['date'] = $nextDeadline->format('M d, Y');
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

/* ---------------- 7. FETCH TURNOVERS FOR OWNED LOTS ---------------- */
$lotTurnovers = [];
if (hasTable($conn, 'lot_turnovers')) {
    $turnoverSql = "SELECT lt.lot_id, lt.turnover_date, lt.title_released, lt.remarks
                    FROM lot_turnovers lt
                    INNER JOIN lots l ON l.id = lt.lot_id
                    WHERE l.owner_id = ?";
    $stmtTv = prepOrDie($conn, $turnoverSql);
    $stmtTv->bind_param('i', $user_id);
    $stmtTv->execute();
    $resTv = $stmtTv->get_result();
    if ($resTv) {
        while ($rowTv = $resTv->fetch_assoc()) {
            $lotTurnovers[(int)$rowTv['lot_id']] = $rowTv;
        }
    }
    $stmtTv->close();
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
.card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:30px; }
.stat-card { background:var(--white); padding:16px 18px; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); display:flex; align-items:center; gap:12px; border:1px solid #f0f0f0; transition:all 0.3s ease; overflow:hidden; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 16px rgba(0,0,0,0.1); }
.stat-icon { width:48px; height:48px; background:var(--light-green); border-radius:11px; display:flex; align-items:center; justify-content:center; color:var(--green); font-size:22px; flex-shrink:0; }
.stat-info { min-width:0; flex:1; overflow:hidden; }
.stat-info h3 { margin:0; font-size:16px; font-weight:700; color:var(--text); line-height:1.3; letter-spacing:-0.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stat-info span { font-size:12px; color:var(--muted); margin-top:3px; display:block; white-space:nowrap; }
.stat-card.stat-card-next-payment {
    align-items: center;
    min-height: 112px;
}
.stat-card.stat-card-next-payment .stat-info {
    overflow: visible;
}
.stat-card.stat-card-next-payment .stat-icon {
    margin-top: 0;
}
.stat-card.stat-card-next-payment .stat-info h3 {
    font-size: 20px;
    line-height: 1.15;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    word-break: break-word;
}
.stat-card.stat-card-next-payment .stat-info span {
    margin-top: 6px;
    white-space: normal;
    line-height: 1.4;
}
.stat-card.stat-card-reminder { align-items:flex-start; }
.stat-card.stat-card-reminder .stat-info { overflow:visible; }
.stat-card.stat-card-reminder .stat-icon { margin-top:2px; }
.stat-card.stat-card-reminder .stat-info h3 {
    font-size:18px;
    line-height:1.15;
    white-space:normal;
    overflow:visible;
    text-overflow:clip;
}
.stat-card.stat-card-reminder .stat-info span {
    white-space:normal;
    line-height:1.35;
}
.deadline-list { margin:8px 0 0; padding:0; list-style:none; display:flex; flex-direction:column; gap:6px; }
.deadline-item { font-size:12px; color:var(--muted); line-height:1.35; }
.deadline-item strong { color:var(--text); font-weight:600; }

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
    .dashboard-wrapper { flex-direction: column; }
    .sidebar {
        width: 100%;
        position: static;
        padding: 14px 10px;
        max-height: none;
        border-bottom: 1px solid rgba(255,255,255,0.15);
    }

    .sidebar-logo,
    .sidebar-user {
        width: 100%;
        max-width: 100%;
    }

    nav {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        overflow-y: hidden;
        padding: 4px 2px;
    }

    .nav-link {
        width: auto;
        min-width: max-content;
        margin: 0;
        padding: 10px 12px;
    }

    .main-content { margin-left:0; padding:20px 24px; }
    .form-grid { grid-template-columns:1fr !important; }
    .card-grid { grid-template-columns:1fr; }
    .stat-card.stat-card-next-payment {
        min-height: 0;
    }
    .content-box { padding: 16px; overflow-x: auto; }
    table { min-width: 520px; }
    h2 { font-size:24px; }
    .subtitle { margin-bottom:20px; }
}

@media (max-width: 560px) {
    .main-content { padding: 14px 12px; }
    .agent-card { flex-direction: column; align-items: flex-start; }
    .modal-content { width:calc(100% - 20px); max-height:calc(100vh - 20px); }
    .modal-body { padding:18px; }
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
.modal-content { background:var(--white); border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,0.2); width:90%; max-width:500px; max-height:calc(100vh - 40px); overflow:hidden; animation:modalSlideIn 0.3s ease; display:flex; flex-direction:column; }
@keyframes modalSlideIn { from { transform:translateY(-30px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-header { padding:24px; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; }
.modal-header h3 { margin:0; font-size:20px; font-weight:700; color:var(--green); }
.modal-close-btn { background:none; border:none; font-size:24px; color:var(--muted); cursor:pointer; width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:6px; transition:0.2s; }
.modal-close-btn:hover { background:#f5f5f5; color:var(--text); }
.modal-body { padding:24px; overflow-y:auto; }
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
            <a href="#payments" class="nav-link" onclick="switchTab('payments', this); loadLotPayments();"><i class="fa fa-credit-card"></i> Payments</a>
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
                <div class="stat-card stat-card-next-payment">
                    <div class="stat-icon" style="background:#dcfce7; color:#166534;"><i class="fa fa-wallet"></i></div>
                    <div class="stat-info">
                        <h3><?php echo h($nextPaymentCardAmount); ?></h3>
                        <span>Next Payment <?php echo !empty($nextPaymentCardDate) ? '(Due: ' . h($nextPaymentCardDate) . ')' : ''; ?></span>
                    </div>
                </div>
                <div class="stat-card stat-card-reminder">
                    <div class="stat-icon" style="background:#fff7ed; color:#c2410c;"><i class="fa fa-hourglass-half"></i></div>
                    <div class="stat-info">
                        <h3><?php echo h($paymentReminder['text']); ?></h3>
                        <span>Payment Deadline Reminder</span>
                        <?php if (!empty($downPaymentDeadlines)): ?>
                        <ul class="deadline-list">
                            <?php foreach ($downPaymentDeadlines as $deadline): ?>
                            <li class="deadline-item">
                                <strong><?php echo h($deadline['lot_label']); ?></strong>
                                : <?php echo h($deadline['date']); ?>
                                <?php if (!empty($deadline['due_day'])): ?>
                                (Due day: <?php echo (int)$deadline['due_day']; ?>)
                                <?php endif; ?>
                                (<?php echo h($deadline['status_text']); ?>)
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
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
                    <?php
                        $effectiveStatus = strtolower(trim((string)($lot['status'] ?? '')));
                        $paymentType = strtolower(trim((string)($lot['payment_type'] ?? '')));
                        if ($paymentType === 'down payment' && in_array($effectiveStatus, ['reserved', 'reservation', 'installment', 'installments'], true)) {
                            $effectiveStatus = 'installment';
                        }

                        $statusLabel = match($effectiveStatus) {
                            'available'   => ['Available',   '#22c55e', '#dcfce7'],
                            'reserved'    => ['Under Reservation', '#f59e0b', '#fef3c7'],
                            'reservation' => ['Under Reservation', '#f59e0b', '#fef3c7'],
                            'installment' => ['Installment', '#3b82f6', '#dbeafe'],
                            'installments' => ['Installment', '#3b82f6', '#dbeafe'],
                            'paid'        => ['Fully Paid',  '#8b5cf6', '#ede9fe'],
                            'sold'        => ['Sold',        '#64748b', '#f1f5f9'],
                            default       => [ucfirst($lot['status'] ?? 'N/A'), '#64748b', '#f1f5f9'],
                        };
                        $rawNextAmount = (float)($lot['payment_amount'] ?? 0);
                        $nextPayAmt  = $rawNextAmount > 0 ? '₱' . number_format($rawNextAmount, 2) : null;
                        $nextPayDate = !empty($lot['payment_deadline'])  ? date('M d, Y', strtotime($lot['payment_deadline']))     : null;
                        $lotPaidSoFar = (float)($paymentsByLot[(int)($lot['id'] ?? 0)] ?? 0);
                        $downPaymentAmount = (float)($lot['down_payment_amount'] ?? 0);
                        $totalPaidDisplay = $lotPaidSoFar + $downPaymentAmount;
                        $remainingBalance = max(0, (float)($lot['lot_price'] ?? 0) - $totalPaidDisplay);
                        $isInstallmentCard = ($paymentType === 'down payment' && in_array($effectiveStatus, ['installment', 'installments'], true));
                    ?>
                    <div class="stat-card" style="display:block; border-left:4px solid <?php echo $statusLabel[1]; ?>; padding:24px; position:relative; overflow:hidden;">
                        <div style="position:absolute; top:0; right:0; width:60px; height:60px; background:rgba(20, 83, 45, 0.05); border-radius:0 12px 0 999px;"></div>
                        <span style="display:inline-block; background:<?php echo $statusLabel[2]; ?>; color:<?php echo $statusLabel[1]; ?>; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; margin-bottom:10px; letter-spacing:.5px;"><?php echo $statusLabel[0]; ?></span>
                        <h4 style="margin:0 0 16px; color:var(--green); font-size:18px; font-weight:700;">
                            Block <?php echo h($lot['block_number']); ?>, Lot <?php echo h($lot['lot_number']); ?>
                        </h4>
                        <div style="font-size:14px; margin-bottom:8px;">
                            <strong style="color:var(--text);">Size:</strong> <span style="color:var(--muted);"><?php echo h($lot['lot_size']); ?> sqm</span>
                        </div>
                        <div style="font-size:14px; margin-bottom:8px;">
                            <strong style="color:var(--text);">Price:</strong> <span style="color:var(--green); font-weight:700;">₱<?php echo number_format($lot['lot_price'], 2); ?></span>
                        </div>
                        <?php if ($nextPayAmt): ?>
                        <div style="font-size:14px; margin-bottom:8px; background:#f0fdf4; padding:8px 12px; border-radius:8px; border-left:3px solid #22c55e;">
                            <strong style="color:var(--text);">Next Payment:</strong> <span style="color:#16a34a; font-weight:700;"><?php echo $nextPayAmt; ?></span>
                            <?php if ($nextPayDate): ?><br><small style="color:var(--muted);">Due: <?php echo $nextPayDate; ?></small><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($isInstallmentCard): ?>
                        <button
                            class="btn btn-secondary"
                            style="width:100%; font-size:13px; margin-top:8px;"
                            data-lot-id="<?php echo (int)($lot['id'] ?? 0); ?>"
                            data-lot-label="<?php echo h('Block ' . ($lot['block_number'] ?? 'N/A') . ', Lot ' . ($lot['lot_number'] ?? 'N/A')); ?>"
                            data-lot-price="<?php echo h((string)(float)($lot['lot_price'] ?? 0)); ?>"
                            data-down-payment="<?php echo h((string)(float)($lot['down_payment_amount'] ?? 0)); ?>"
                            data-installment-amount="<?php echo h((string)(float)($lot['payment_amount'] ?? 0)); ?>"
                            data-term-years="<?php echo h((string)(int)($lot['payment_term_years'] ?? 0)); ?>"
                            data-due-day="<?php echo h((string)(int)($lot['payment_due_day'] ?? 0)); ?>"
                            data-next-due="<?php echo h((string)($lot['payment_deadline'] ?? '')); ?>"
                            data-paid-so-far="<?php echo h((string)$totalPaidDisplay); ?>"
                            data-remaining-balance="<?php echo h((string)$remainingBalance); ?>"
                            onclick="openInstallmentDetailsModal(this)">View Installment Plan</button>
                        <?php else: ?>
                        <button class="btn btn-secondary" style="width:100%; font-size:13px; margin-top:8px;" onclick="switchTab('payments',document.querySelector('.nav-link[href=\'#payments\']')); loadLotPayments();">View Payments</button>
                        <?php endif; ?>
                        <?php
                            $tvLotId = (int)($lot['id'] ?? 0);
                            if ($effectiveStatus === 'paid' && isset($lotTurnovers[$tvLotId])):
                                $tv = $lotTurnovers[$tvLotId];
                        ?>
                        <div style="margin-top:12px; background:#f5f3ff; border-left:3px solid #8b5cf6; border-radius:8px; padding:10px 12px;">
                            <strong style="color:#6d28d9; font-size:13px;">🏡 Title &amp; Turnover</strong>
                            <div style="font-size:13px; margin-top:5px; color:var(--text);">
                                Turnover Date: <strong><?php echo h(!empty($tv['turnover_date']) ? date('M d, Y', strtotime($tv['turnover_date'])) : 'Pending'); ?></strong>
                            </div>
                            <div style="font-size:13px; margin-top:2px; color:var(--text);">
                                Title Released:
                                <strong style="color:<?php echo ($tv['title_released'] ? '#16a34a' : '#d97706'); ?>">
                                    <?php echo $tv['title_released'] ? 'Yes ✅' : 'Pending ⏳'; ?>
                                </strong>
                            </div>
                            <?php if (!empty($tv['remarks'])): ?>
                            <div style="font-size:12px; margin-top:4px; color:var(--muted);"><?php echo h($tv['remarks']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="payments" class="section">
            <h2>My Payments</h2>
            <span class="subtitle">Payment history for your lots</span>

            <div id="payments-loading" style="text-align:center; padding:40px; color:var(--muted);">
                <i class="fa fa-spinner fa-spin" style="font-size:24px;"></i><br><br>Loading payment history…
            </div>

            <div id="payments-empty" style="display:none; text-align:center; padding:40px; color:var(--muted);">
                <i class="fa fa-receipt" style="font-size:40px; opacity:.3; margin-bottom:12px; display:block;"></i>
                No payment records found.
            </div>

            <div id="payments-content" style="display:none;"></div>
        </div>

        <div id="viewings" class="section">
            <h2>My Viewings</h2>
            <span class="subtitle">Scheduled visits to properties</span>

            <div style="margin-bottom:14px; text-align:right;">
                <button class="btn btn-primary" onclick="openNewViewingModal()">
                    <i class="fa fa-plus"></i> Request New Viewing
                </button>
            </div>

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
            <span class="subtitle">Contracts, agreements, and payment records</span>
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

            <div class="content-box">
                <div class="box-header">Copy of Contracts</div>
                <table>
                    <thead>
                        <tr><th>File Name</th><th>Type</th><th>Status</th><th>Uploaded</th><th>File</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contractDocs)): ?>
                            <tr><td colspan="5" style="text-align:center;">No contract copies uploaded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($contractDocs as $doc): ?>
                            <tr>
                                <td><?php echo h($doc['file_name'] ?? 'Untitled'); ?></td>
                                <td><?php echo h($doc['doc_type'] ?? 'Contract'); ?></td>
                                <td><?php echo h($doc['status'] ?? 'Pending'); ?></td>
                                <td><?php echo !empty($doc['uploaded_at']) ? h(date('M d, Y h:i A', strtotime($doc['uploaded_at']))) : 'N/A'; ?></td>
                                <td>
                                    <?php if (!empty($doc['file_path'])): ?>
                                        <a href="<?php echo h($doc['file_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 10px; font-size:12px;">Open</a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="content-box">
                <div class="box-header">Copy of Agreements</div>
                <table>
                    <thead>
                        <tr><th>File Name</th><th>Type</th><th>Status</th><th>Uploaded</th><th>File</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agreementDocs)): ?>
                            <tr><td colspan="5" style="text-align:center;">No agreement copies uploaded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($agreementDocs as $doc): ?>
                            <tr>
                                <td><?php echo h($doc['file_name'] ?? 'Untitled'); ?></td>
                                <td><?php echo h($doc['doc_type'] ?? 'Agreement'); ?></td>
                                <td><?php echo h($doc['status'] ?? 'Pending'); ?></td>
                                <td><?php echo !empty($doc['uploaded_at']) ? h(date('M d, Y h:i A', strtotime($doc['uploaded_at']))) : 'N/A'; ?></td>
                                <td>
                                    <?php if (!empty($doc['file_path'])): ?>
                                        <a href="<?php echo h($doc['file_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 10px; font-size:12px;">Open</a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="content-box">
                <div class="box-header">Previous Payments</div>
                <table>
                    <thead>
                        <tr><th>Lot</th><th>Date</th><th>Amount</th><th>Method</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($previousPayments)): ?>
                            <tr><td colspan="5" style="text-align:center;">No previous payment records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($previousPayments as $p): ?>
                            <tr>
                                <td>Block <?php echo h($p['block_number'] ?? '-'); ?>, Lot <?php echo h($p['lot_number'] ?? '-'); ?></td>
                                <td><?php echo !empty($p['payment_date']) ? h(date('M d, Y', strtotime($p['payment_date']))) : 'N/A'; ?></td>
                                <td>₱<?php echo number_format((float)($p['amount'] ?? 0), 2); ?></td>
                                <td><?php echo h($p['payment_method'] ?? 'N/A'); ?></td>
                                <td><?php echo h($p['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="notifications" class="section">
            <h2>Notifications</h2>
            <span class="subtitle">System alerts</span>
            <div class="content-box">
                <ul class="notification-list">
                    <?php if(!empty($systemNotifications)): ?>
                        <?php foreach($systemNotifications as $n): ?>
                        <li class="notif-item">
                            <div class="notif-icon"><i class="fa fa-bell"></i></div>
                            <div class="notif-content">
                                <strong><?php echo h($n['title'] ?? 'System Notification'); ?></strong>
                                <span><?php echo h($n['message'] ?? ''); ?></span>
                                <?php if (!empty($n['created_at'])): ?>
                                    <small style="display:block; color:var(--muted); margin-top:4px;"><?php echo h(date('F d, Y h:i A', strtotime($n['created_at']))); ?></small>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php elseif(!empty($recentActivities)): ?>
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
    <div id="installmentDetailsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Installment Plan</h3>
                <button class="modal-close-btn" onclick="closeInstallmentDetailsModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="display:grid; gap:12px;">
                    <div style="padding:14px 16px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                        <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Property</div>
                        <div id="installment-modal-lot-label" style="font-size:18px; font-weight:700; color:var(--green);"></div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;">
                        <div style="padding:14px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                            <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Lot Price</div>
                            <div id="installment-modal-lot-price" style="font-size:18px; font-weight:700; color:#0f172a;"></div>
                        </div>
                        <div style="padding:14px; background:#eff6ff; border-radius:10px; border:1px solid #bfdbfe;">
                            <div style="font-size:12px; color:#2563eb; margin-bottom:4px;">Monthly Installment</div>
                            <div id="installment-modal-monthly" style="font-size:18px; font-weight:700; color:#1d4ed8;"></div>
                        </div>
                        <div style="padding:14px; background:#f0fdf4; border-radius:10px; border:1px solid #bbf7d0;">
                            <div style="font-size:12px; color:#15803d; margin-bottom:4px;">Down Payment</div>
                            <div id="installment-modal-down-payment" style="font-size:18px; font-weight:700; color:#166534;"></div>
                        </div>
                        <div style="padding:14px; background:#fff7ed; border-radius:10px; border:1px solid #fed7aa;">
                            <div style="font-size:12px; color:#c2410c; margin-bottom:4px;">Remaining Balance</div>
                            <div id="installment-modal-balance" style="font-size:18px; font-weight:700; color:#c2410c;"></div>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;">
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Payment Term</div>
                            <div id="installment-modal-term" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Monthly Due Day</div>
                            <div id="installment-modal-due-day" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Next Due Date</div>
                            <div id="installment-modal-next-due" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                        <div style="padding:14px; background:#ffffff; border-radius:10px; border:1px solid #e5e7eb;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Total Paid</div>
                            <div id="installment-modal-paid" style="font-size:16px; font-weight:700; color:#111827;"></div>
                        </div>
                    </div>
                    <div style="margin-top:4px; border-top:1px solid #e5e7eb; padding-top:14px; min-height:0;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;">
                            <div style="font-size:15px; font-weight:700; color:#111827;">Monthly Schedule</div>
                            <div id="installment-schedule-summary" style="font-size:12px; color:#64748b;"></div>
                        </div>
                        <div id="installment-schedule-loading" style="display:none; color:#64748b; font-size:13px; padding:10px 0;">Loading installment schedule...</div>
                        <div id="installment-schedule-empty" style="display:none; color:#64748b; font-size:13px; padding:10px 0;">No installment schedule available.</div>
                        <div id="installment-schedule-list" style="display:grid; gap:10px; max-height:320px; overflow-y:auto; padding-right:4px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeInstallmentDetailsModal()">Close</button>
            </div>
        </div>
    </div>

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

    <!-- New Viewing Request Modal -->
    <div id="newViewingModal" class="modal-overlay" style="display:none;">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header">
                <h3><i class="fa fa-calendar-plus"></i> Request New Viewing</h3>
                <button class="modal-close-btn" onclick="closeNewViewingModal()">×</button>
            </div>
            <div class="modal-body">
                <div id="newViewingStatus" style="display:none; padding:10px 12px; border-radius:6px; margin-bottom:12px; font-weight:600;"></div>
                <div class="form-grid" style="gap:12px;">
                    <div class="form-group">
                        <label>First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="nv_first_name" class="form-control" value="<?php echo h($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="nv_last_name" class="form-control" value="<?php echo h($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email <span style="color:#dc2626;">*</span></label>
                        <input type="email" id="nv_email" class="form-control" value="<?php echo h($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone <span style="color:#dc2626;">*</span></label>
                        <input type="tel" id="nv_phone" class="form-control" value="<?php echo h($user['mobile'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Preferred Date &amp; Time <span style="color:#dc2626;">*</span></label>
                        <input type="datetime-local" id="nv_datetime" class="form-control" min="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Notes (optional)</label>
                        <textarea id="nv_notes" class="form-control" rows="3" placeholder="Any specific lot or area you want to view?"></textarea>
                    </div>
                    <?php if ($agent): ?>
                    <input type="hidden" id="nv_agent_id" value="<?php echo (int)$agent['id']; ?>">
                    <div class="form-group" style="grid-column:span 2;">
                        <label>Assigned Agent</label>
                        <input type="text" class="form-control" value="<?php echo h($agent['first_name'] . ' ' . $agent['last_name']); ?>" readonly style="background:#f0fdf4;">
                    </div>
                    <?php else: ?>
                    <input type="hidden" id="nv_agent_id" value="">
                    <div class="form-group" style="grid-column:span 2;">
                        <p style="color:#d97706; font-size:13px; background:#fffbeb; padding:10px; border-radius:6px; border-left:3px solid #f59e0b;">⚠️ No agent is currently assigned to you. Your request will be sent to the office for agent assignment.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNewViewingModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitNewViewingRequest()"><i class="fa fa-paper-plane"></i> Submit Request</button>
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

function formatPeso(value) {
    const amount = Number(value || 0);
    return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDueDate(value) {
    if (!value) return 'Not set';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatMonthLabel(value) {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) return 'Unknown month';
    return value.toLocaleDateString('en-PH', { year: 'numeric', month: 'long' });
}

function parseDateOnly(value) {
    if (!value) return null;
    const parts = String(value).split('-').map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
    return new Date(parts[0], parts[1] - 1, parts[2], 12, 0, 0, 0);
}

function buildDueDate(baseDate, monthOffset, dueDay) {
    const year = baseDate.getFullYear();
    const month = baseDate.getMonth() + monthOffset;
    const firstOfMonth = new Date(year, month, 1, 12, 0, 0, 0);
    const lastDay = new Date(firstOfMonth.getFullYear(), firstOfMonth.getMonth() + 1, 0).getDate();
    return new Date(firstOfMonth.getFullYear(), firstOfMonth.getMonth(), Math.min(dueDay, lastDay), 12, 0, 0, 0);
}

function monthKeyFromDate(value) {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) return null;
    return (value.getFullYear() * 12) + value.getMonth();
}

function normalizeScheduleStartDate(startDate, dueDay, installmentTransactions) {
    if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime()) || !Array.isArray(installmentTransactions) || !installmentTransactions.length || dueDay <= 0) {
        return startDate;
    }

    const paidMonthKeys = installmentTransactions
        .map(tx => monthKeyFromDate(tx.parsedDate))
        .filter(v => typeof v === 'number')
        .sort((a, b) => a - b);
    if (!paidMonthKeys.length) {
        return startDate;
    }
    const paidMonths = new Set(paidMonthKeys);

    let normalized = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate(), 12, 0, 0, 0);
    const currentKey = monthKeyFromDate(normalized);
    const earliestPaidKey = paidMonthKeys[0];

    // If stored anchor is ahead of real paid history, shift anchor back to the earliest paid month.
    if (typeof currentKey === 'number' && earliestPaidKey < currentKey) {
        const monthsBack = currentKey - earliestPaidKey;
        if (monthsBack > 0 && monthsBack <= 36) {
            normalized = buildDueDate(normalized, -monthsBack, dueDay);
        }
    }

    // Correct historical drift by shifting back when previous month has payment but anchor month has none.
    for (let i = 0; i < 12; i += 1) {
        const currentKey = monthKeyFromDate(normalized);
        const previous = buildDueDate(normalized, -1, dueDay);
        const previousKey = monthKeyFromDate(previous);
        if (previousKey === null || currentKey === null) break;
        if (paidMonths.has(previousKey) && !paidMonths.has(currentKey)) {
            normalized = previous;
            continue;
        }
        break;
    }

    return normalized;
}

function getInstallmentMonthIndex(startDate, parsedDate) {
    if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime()) || !(parsedDate instanceof Date) || Number.isNaN(parsedDate.getTime())) {
        return null;
    }
    const startMonth = (startDate.getFullYear() * 12) + startDate.getMonth();
    const paidMonth = (parsedDate.getFullYear() * 12) + parsedDate.getMonth();
    const monthDiff = paidMonth - startMonth;
    // Allow one-month-early payment to count as Month 1; older historical months should not be forced into Month 1.
    if (monthDiff === -1) return 0;
    return monthDiff;
}

function buildInstallmentPaymentMap(plan, installmentTransactions) {
    const termYears = Number(plan?.payment_term_years || 0);
    const dueDay = Number(plan?.payment_due_day || 0);
    const totalMonths = termYears > 0 ? termYears * 12 : 0;
    const rawStartDate = parseDateOnly(plan?.payment_deadline || '');
    const startDate = normalizeScheduleStartDate(rawStartDate, dueDay, installmentTransactions);
    const map = new Map();
    if (!startDate || totalMonths <= 0) {
        return { paymentMap: map, paidCount: 0, totalMonths, startDate };
    }

    installmentTransactions.forEach(tx => {
        const index = getInstallmentMonthIndex(startDate, tx.parsedDate);
        if (index === null || index < 0 || index >= totalMonths) return;
        if (!map.has(index)) {
            map.set(index, []);
        }
        // Keep all payment records for this month for audit/history visibility.
        map.get(index).push(tx);
    });

    return { paymentMap: map, paidCount: map.size, totalMonths, startDate };
}

function splitInstallmentTransactions(plan, transactions) {
    const startDate = parseDateOnly(plan?.payment_deadline || '');
    const downPayment = Number(plan?.down_payment_amount || 0);

    const sortedTransactions = Array.isArray(transactions)
        ? [...transactions]
            .map(tx => ({
                ...tx,
                amount: Number(tx.amount || 0),
                parsedDate: parseDateOnly(String(tx.payment_date || '').slice(0, 10))
            }))
            .filter(tx => tx.parsedDate)
            .sort((a, b) => a.parsedDate - b.parsedDate)
        : [];

    let downPaymentRecorded = downPayment > 0;
    let installmentTransactions = sortedTransactions;

    if (downPayment > 0 && installmentTransactions.length) {
        // Try strict match: amount + date on-or-before firstDue
        let downPaymentIndex = startDate
            ? installmentTransactions.findIndex(tx =>
                Math.abs(tx.amount - downPayment) < 0.01 && tx.parsedDate.getTime() <= startDate.getTime())
            : -1;
        // Fallback: match by amount only (first occurrence), in case payment was recorded after firstDue
        if (downPaymentIndex < 0) {
            downPaymentIndex = installmentTransactions.findIndex(tx =>
                Math.abs(tx.amount - downPayment) < 0.01);
        }
        if (downPaymentIndex >= 0) {
            downPaymentRecorded = true;
            installmentTransactions = installmentTransactions.filter((_, index) => index !== downPaymentIndex);
        }
    }

    return {
        installmentTransactions,
        downPaymentApplied: downPaymentRecorded ? downPayment : 0
    };
}

function renderInstallmentSchedule(plan, transactions) {
    const list = document.getElementById('installment-schedule-list');
    const empty = document.getElementById('installment-schedule-empty');
    const summary = document.getElementById('installment-schedule-summary');
    if (!list || !empty || !summary) return;

    list.innerHTML = '';
    empty.style.display = 'none';

    const dueDay = Number(plan?.payment_due_day || 0);
    const monthlyAmount = Number(plan?.payment_amount || 0);

    const { installmentTransactions } = splitInstallmentTransactions(plan, transactions);
    const { paymentMap, paidCount, totalMonths, startDate } = buildInstallmentPaymentMap(plan, installmentTransactions);

    if (!startDate || dueDay <= 0 || totalMonths <= 0 || monthlyAmount <= 0) {
        summary.textContent = 'Schedule unavailable';
        empty.style.display = 'block';
        return;
    }

    summary.textContent = `${paidCount} of ${totalMonths} month${totalMonths !== 1 ? 's' : ''} paid`;

    const scheduleItems = [];
    for (let index = 0; index < totalMonths; index += 1) {
        const dueDate = buildDueDate(startDate, index, dueDay);
        const paymentRecords = paymentMap.get(index) || [];
        const payment = paymentRecords.length ? paymentRecords[0] : null;
        const isPaid = !!payment;
        scheduleItems.push(`
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1px solid ${isPaid ? '#bbf7d0' : '#e5e7eb'}; background:${isPaid ? '#f0fdf4' : '#ffffff'}; border-radius:10px;">
                <div>
                    <div style="font-size:14px; font-weight:700; color:#111827;">Month ${index + 1} · ${escHtml(formatMonthLabel(dueDate))}</div>
                    <div style="font-size:12px; color:#6b7280; margin-top:3px;">Due ${escHtml(formatDueDate(dueDate.toISOString().slice(0, 10)))}</div>
                    ${isPaid ? `<div style="font-size:12px; color:#166534; margin-top:3px;">Paid on ${escHtml(formatDueDate(String(payment.payment_date || '').slice(0, 10)))}</div>` : ''}
                </div>
                <div style="text-align:right; min-width:120px;">
                    <div style="font-size:14px; font-weight:700; color:#111827;">${escHtml(formatPeso(monthlyAmount))}</div>
                    <div style="display:inline-flex; align-items:center; justify-content:center; margin-top:6px; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.3px; background:${isPaid ? '#dcfce7' : '#f3f4f6'}; color:${isPaid ? '#166534' : '#6b7280'};">${isPaid ? 'Paid' : 'Unpaid'}</div>
                </div>
            </div>
        `);
    }

    list.innerHTML = scheduleItems.join('');
}

function openInstallmentDetailsModal(button) {
    const modal = document.getElementById('installmentDetailsModal');
    if (!modal || !button) return;

    const termYears = Number(button.dataset.termYears || 0);
    const dueDay = Number(button.dataset.dueDay || 0);

    document.getElementById('installment-modal-lot-label').textContent = button.dataset.lotLabel || 'Property';
    document.getElementById('installment-modal-lot-price').textContent = formatPeso(button.dataset.lotPrice || 0);
    document.getElementById('installment-modal-monthly').textContent = formatPeso(button.dataset.installmentAmount || 0);
    document.getElementById('installment-modal-down-payment').textContent = formatPeso(button.dataset.downPayment || 0);
    document.getElementById('installment-modal-balance').textContent = formatPeso(button.dataset.remainingBalance || 0);
    document.getElementById('installment-modal-term').textContent = termYears > 0 ? `${termYears} year${termYears > 1 ? 's' : ''}` : 'Not set';
    document.getElementById('installment-modal-due-day').textContent = dueDay > 0 ? `Every day ${dueDay} of the month` : 'Not set';
    document.getElementById('installment-modal-next-due').textContent = formatDueDate(button.dataset.nextDue || '');
    document.getElementById('installment-modal-paid').textContent = formatPeso(button.dataset.paidSoFar || 0);

    const loading = document.getElementById('installment-schedule-loading');
    const empty = document.getElementById('installment-schedule-empty');
    const list = document.getElementById('installment-schedule-list');
    const summary = document.getElementById('installment-schedule-summary');
    if (loading) loading.style.display = 'block';
    if (empty) empty.style.display = 'none';
    if (list) list.innerHTML = '';
    if (summary) summary.textContent = 'Loading schedule...';

    modal.classList.add('active');

    const lotId = Number(button.dataset.lotId || 0);
    if (!lotId) {
        if (loading) loading.style.display = 'none';
        if (empty) empty.style.display = 'block';
        if (summary) summary.textContent = 'Schedule unavailable';
        return;
    }

    fetch(`user_dashboard.php?action=installment_plan&lot_id=${lotId}`)
        .then(r => r.json())
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (!data.success || !data.plan) {
                if (empty) empty.style.display = 'block';
                if (summary) summary.textContent = 'Schedule unavailable';
                return;
            }

            const plan = data.plan || {};
            const { installmentTransactions, downPaymentApplied } = splitInstallmentTransactions(plan, data.transactions || []);
            const monthlyPaidTotal = installmentTransactions.reduce((sum, tx) => sum + Number(tx.amount || 0), 0);
            const totalPaid = downPaymentApplied + monthlyPaidTotal;
            const remainingBalance = Math.max(0, Number(plan.lot_price || 0) - totalPaid);

            document.getElementById('installment-modal-lot-price').textContent = formatPeso(plan.lot_price || 0);
            document.getElementById('installment-modal-monthly').textContent = formatPeso(plan.payment_amount || 0);
            document.getElementById('installment-modal-down-payment').textContent = formatPeso(plan.down_payment_amount || 0);
            document.getElementById('installment-modal-balance').textContent = formatPeso(remainingBalance);
            document.getElementById('installment-modal-term').textContent = Number(plan.payment_term_years || 0) > 0 ? `${Number(plan.payment_term_years || 0)} year${Number(plan.payment_term_years || 0) > 1 ? 's' : ''}` : 'Not set';
            document.getElementById('installment-modal-due-day').textContent = Number(plan.payment_due_day || 0) > 0 ? `Every day ${Number(plan.payment_due_day || 0)} of the month` : 'Not set';
            // Compute next due as first unpaid month slot (accurate to selected payment month).
            const _dueDay = Number(plan.payment_due_day || 0);
            const _mapInfo = buildInstallmentPaymentMap(plan, installmentTransactions);
            if (_mapInfo.startDate && _dueDay > 0 && _mapInfo.totalMonths > 0) {
                let nextIndex = 0;
                while (nextIndex < _mapInfo.totalMonths && _mapInfo.paymentMap.has(nextIndex)) {
                    nextIndex += 1;
                }
                if (nextIndex >= _mapInfo.totalMonths) {
                    document.getElementById('installment-modal-next-due').textContent = 'Fully paid';
                } else {
                    const _nextDue = buildDueDate(_mapInfo.startDate, nextIndex, _dueDay);
                    document.getElementById('installment-modal-next-due').textContent = formatDueDate(_nextDue.toISOString().slice(0, 10));
                }
            } else {
                document.getElementById('installment-modal-next-due').textContent = formatDueDate(plan.payment_deadline || '');
            }
            document.getElementById('installment-modal-paid').textContent = formatPeso(totalPaid);

            renderInstallmentSchedule(data.plan, data.transactions || []);
        })
        .catch(() => {
            if (loading) loading.style.display = 'none';
            if (empty) empty.style.display = 'block';
            if (summary) summary.textContent = 'Failed to load schedule';
        });
}

function closeInstallmentDetailsModal() {
    const modal = document.getElementById('installmentDetailsModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

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

// ---- New Viewing Request ----
function openNewViewingModal() {
    const modal = document.getElementById('newViewingModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('newViewingStatus').style.display = 'none';
    }
}

function closeNewViewingModal() {
    const modal = document.getElementById('newViewingModal');
    if (modal) modal.style.display = 'none';
}

function submitNewViewingRequest() {
    const firstName = document.getElementById('nv_first_name')?.value.trim();
    const lastName  = document.getElementById('nv_last_name')?.value.trim();
    const email     = document.getElementById('nv_email')?.value.trim();
    const phone     = document.getElementById('nv_phone')?.value.trim();
    const datetime  = document.getElementById('nv_datetime')?.value.trim();
    const notes     = document.getElementById('nv_notes')?.value.trim();
    const agentId   = document.getElementById('nv_agent_id')?.value || '';
    const statusEl  = document.getElementById('newViewingStatus');

    if (!firstName || !lastName || !email || !phone || !datetime) {
        statusEl.textContent = '⚠️ Please fill in all required fields.';
        statusEl.style.display = 'block';
        statusEl.style.background = '#fffbeb';
        statusEl.style.color = '#92400e';
        return;
    }

    statusEl.textContent = 'Submitting...';
    statusEl.style.display = 'block';
    statusEl.style.background = '#eff6ff';
    statusEl.style.color = '#1e40af';

    const formData = new FormData();
    formData.append('agent_id', agentId);
    formData.append('client_first_name', firstName);
    formData.append('client_middle_name', '');
    formData.append('client_last_name', lastName);
    formData.append('client_email', email);
    formData.append('client_phone', phone);
    formData.append('location', '');
    formData.append('lot_no', '');
    formData.append('preferredDateTime', datetime);
    formData.append('notes', notes || '');
    formData.append('client_lat', '');
    formData.append('client_lng', '');
    formData.append('location_id', '');
    formData.append('lot_id', '');

    fetch('submit_viewing.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusEl.textContent = '✅ Viewing request submitted! Your agent will confirm your appointment.';
                statusEl.style.background = '#f0fdf4';
                statusEl.style.color = '#166534';
                document.getElementById('nv_datetime').value = '';
                document.getElementById('nv_notes').value = '';
                setTimeout(() => closeNewViewingModal(), 2500);
            } else {
                statusEl.textContent = '❌ Error: ' + (data.error || 'Failed to submit request.');
                statusEl.style.background = '#fef2f2';
                statusEl.style.color = '#991b1b';
            }
        })
        .catch(err => {
            statusEl.textContent = '❌ Network error: ' + err.message;
            statusEl.style.background = '#fef2f2';
            statusEl.style.color = '#991b1b';
        });
}

// ---- Lot Payment History ----
let lotPaymentsLoaded = false;

function loadLotPayments(forceReload) {
    if (lotPaymentsLoaded && !forceReload) return;
    const loading = document.getElementById('payments-loading');
    const empty   = document.getElementById('payments-empty');
    const content = document.getElementById('payments-content');
    if (!loading || !content) return;

    loading.style.display = 'block';
    empty.style.display = 'none';
    content.style.display = 'none';
    content.innerHTML = '';

    fetch('user_dashboard.php?action=lot_payments')
        .then(r => r.json())
        .then(data => {
            loading.style.display = 'none';
            if (!data.success || !data.transactions || data.transactions.length === 0) {
                empty.style.display = 'block';
                lotPaymentsLoaded = true;
                return;
            }

            // Group transactions by lot_id
            const lots = {};
            data.transactions.forEach(tx => {
                if (!lots[tx.lot_id]) {
                    lots[tx.lot_id] = {
                        lot_id: tx.lot_id,
                        block_number: tx.block_number,
                        lot_number: tx.lot_number,
                        lot_price: parseFloat(tx.lot_price) || 0,
                        amount_paid_so_far: parseFloat(tx.amount_paid_so_far) || 0,
                        payment_deadline: tx.payment_deadline,
                        payment_type: tx.payment_type,
                        status: tx.status,
                        transactions: []
                    };
                }
                lots[tx.lot_id].transactions.push(tx);
            });

            let html = '';
            Object.values(lots).forEach(lot => {
                lot.transactions.sort((a, b) => {
                    const da = String(a.payment_date || '');
                    const db = String(b.payment_date || '');
                    if (da < db) return -1;
                    if (da > db) return 1;
                    return Number(a.id || 0) - Number(b.id || 0);
                });
                const totalPaid = lot.transactions.reduce((s, t) => s + (parseFloat(t.amount) || 0), 0);
                const balance   = Math.max(0, lot.lot_price - totalPaid);
                const pct       = lot.lot_price > 0 ? Math.min(100, Math.round((totalPaid / lot.lot_price) * 100)) : 0;
                const barColor  = pct >= 100 ? '#8b5cf6' : pct >= 50 ? '#3b82f6' : '#22c55e';

                const dueDate  = lot.payment_deadline ? new Date(lot.payment_deadline).toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'}) : null;

                html += `
                <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:28px; overflow:hidden;">
                    <div style="background:linear-gradient(135deg,#14532d 0%,#166534 100%); padding:20px 24px; color:#fff;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div>
                                <div style="font-size:18px; font-weight:700;">Block ${escHtml(lot.block_number)}, Lot ${escHtml(lot.lot_number)}</div>
                                <div style="font-size:13px; opacity:.8; margin-top:2px;">${escHtml(lot.payment_type || lot.status || '')}</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:13px; opacity:.8;">Total Lot Price</div>
                                <div style="font-size:20px; font-weight:700;">₱${fmt(lot.lot_price)}</div>
                            </div>
                        </div>
                    </div>
                    <div style="padding:20px 24px;">
                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:16px; margin-bottom:20px;">
                            <div style="text-align:center; background:#f0fdf4; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Total Paid</div>
                                <div style="font-size:18px; font-weight:700; color:#16a34a;">₱${fmt(totalPaid)}</div>
                            </div>
                            <div style="text-align:center; background:#fef9ee; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Balance</div>
                                <div style="font-size:18px; font-weight:700; color:${balance===0?'#8b5cf6':'#f59e0b'};">${balance===0?'Fully Paid':'₱'+fmt(balance)}</div>
                            </div>
                            ${dueDate ? `<div style="text-align:center; background:#eff6ff; border-radius:10px; padding:14px;">
                                <div style="font-size:12px; color:#666; margin-bottom:4px;">Next Due</div>
                                <div style="font-size:14px; font-weight:700; color:#2563eb;">${dueDate}</div>
                            </div>` : ''}
                        </div>

                        <div style="background:#f9fafb; border-radius:8px; height:10px; overflow:hidden; margin-bottom:6px;">
                            <div style="height:100%; width:${pct}%; background:${barColor}; border-radius:8px; transition:width .6s;"></div>
                        </div>
                        <div style="font-size:12px; color:#666; text-align:right; margin-bottom:20px;">${pct}% paid</div>

                        ${lot.transactions.length > 0 ? `
                        <h4 style="margin:0 0 10px; font-size:14px; color:#374151; font-weight:700;">Payment History</h4>
                        <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f3f4f6;">
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600; white-space:nowrap;">Date</th>
                                    <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:600;">Amount</th>
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600;">Method</th>
                                    <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:600;">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${lot.transactions.map((tx, i) => `
                                <tr style="border-top:1px solid #e5e7eb; background:${i%2===1?'#fafafa':'#fff'};">
                                    <td style="padding:10px 12px; white-space:nowrap; color:#374151;">${tx.payment_date ? new Date(tx.payment_date).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—'}</td>
                                    <td style="padding:10px 12px; text-align:right; font-weight:700; color:#16a34a;">₱${fmt(parseFloat(tx.amount)||0)}</td>
                                    <td style="padding:10px 12px; color:#374151;">${escHtml(tx.payment_method||'—')}</td>
                                    <td style="padding:10px 12px; color:#6b7280;">${escHtml(tx.remarks||'—')}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                        </div>` : `<p style="color:#999; font-size:13px; text-align:center; padding:10px 0;">No individual transactions recorded yet.</p>`}
                    </div>
                </div>`;
            });

            content.innerHTML = html;
            content.style.display = 'block';
            lotPaymentsLoaded = true;
        })
        .catch(err => {
            loading.style.display = 'none';
            content.innerHTML = '<p style="color:#dc2626; text-align:center; padding:20px;">Failed to load payment history.</p>';
            content.style.display = 'block';
        });
}

function fmt(n) { return Number(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function escHtml(s) { const d=document.createElement('span'); d.textContent=String(s??''); return d.innerHTML; }

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInstallmentDetailsModal();
        closeMessageModal();
    }
});

// Close modal on overlay click
document.addEventListener('click', function(e) {
    const installmentModal = document.getElementById('installmentDetailsModal');
    const modal = document.getElementById('messageModal');
    if (e.target === installmentModal) {
        closeInstallmentDetailsModal();
    }
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