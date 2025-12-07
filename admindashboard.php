<?php
session_start();

// Database connection settings
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "nuevopuerta";

$conn = mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}


// =============================================
// FETCH SINGLE USER (AJAX: ?fetch=user&id=..)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['fetch']) && $_GET['fetch'] === 'user' &&
    isset($_GET['id'])) {

    $user_id = intval($_GET['id']);
    $userQuery = "SELECT * FROM user_accounts WHERE id = $user_id LIMIT 1";
    $userResult = mysqli_query($conn, $userQuery);
    $user = $userResult ? mysqli_fetch_assoc($userResult) : null;

    header('Content-Type: application/json');
    echo json_encode($user);
    exit;
}

// =============================================
// ADMIN INFO FROM SESSION
// =============================================
$admin_id   = $_SESSION['admin_id'] ?? null;
$admin_name = "Admin";
$admin_role = "Super Admin";

if ($admin_id) {
    $result = mysqli_query($conn, "SELECT first_name, last_name, role FROM admin_accounts WHERE id = $admin_id LIMIT 1");
    if ($row = mysqli_fetch_assoc($result)) {
        $admin_name = $row['first_name'] . ' ' . $row['last_name'];
        $admin_role = $row['role'];
    }
}

// =============================================
// DASHBOARD STATS (CLIENTS / LOTS / AGENTS)
// =============================================
$dashboard_stats = [
    'clients' => 0,
    'lots'    => 0,
    'agents'  => 0
];

// total clients
$clientQuery  = "SELECT COUNT(*) as total FROM user_accounts";
$clientResult = mysqli_query($conn, $clientQuery);
if ($clientResult) {
    $clientRow = mysqli_fetch_assoc($clientResult);
    $dashboard_stats['clients'] = (int)$clientRow['total'];
}

// total lots
$lotsQuery  = "SELECT COUNT(*) as total FROM lots";
$lotsResult = mysqli_query($conn, $lotsQuery);
if ($lotsResult) {
    $lotsRow = mysqli_fetch_assoc($lotsResult);
    $dashboard_stats['lots'] = (int)$lotsRow['total'];
}

// total active agents
$agentsQuery  = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";
$agentsResult = mysqli_query($conn, $agentsQuery);
if ($agentsResult) {
    $agentsRow = mysqli_fetch_assoc($agentsResult);
    $dashboard_stats['agents'] = (int)$agentsRow['total'];
}

// =============================================
// TOP AGENTS (AJAX: ?fetch=top_agents)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['fetch']) && $_GET['fetch'] === 'top_agents') {

    $date_from   = $_GET['date_from']   ?? null;
    $date_to     = $_GET['date_to']     ?? null;
    $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;

    $where = [];
    if ($date_from)   $where[] = "s.sale_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to)     $where[] = "s.sale_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
    if ($location_id) $where[] = "s.location_id = $location_id";

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
      SELECT 
        a.id,
        a.first_name,
        a.last_name,
        a.email,
        COUNT(s.id) AS sales_count,
        SUM(s.amount) AS total_amount,
        IFNULL(ROUND(SUM(s.amount)/COUNT(s.id)), 0) AS avg_deal_size
      FROM agent_accounts a
      LEFT JOIN sales s ON a.id = s.agent_id
      $whereSQL
      GROUP BY a.id
      ORDER BY total_amount DESC, sales_count DESC
      LIMIT 10
    ";

    $result = mysqli_query($conn, $sql);
    $agents = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $agents[] = [
                'id'           => (int)$row['id'],
                'name'         => $row['first_name'] . ' ' . $row['last_name'],
                'email'        => $row['email'],
                'sales_count'  => (int)$row['sales_count'],
                'total_amount' => (float)$row['total_amount'],
                'avg_deal_size'=> (float)$row['avg_deal_size'],
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($agents);
    exit;
}

// =============================================
// LOTS CRUD (AJAX: POST action=save/delete/bulk_delete)
// =============================================

// Save / update lot
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'save') {

    $block_number = mysqli_real_escape_string($conn, $_POST['block_number']);
    $lot_number   = mysqli_real_escape_string($conn, $_POST['lot_number']);
    $lot_size     = mysqli_real_escape_string($conn, $_POST['lot_size']);
    $lot_price    = mysqli_real_escape_string($conn, $_POST['lot_price']);
    $location_id  = mysqli_real_escape_string($conn, $_POST['location_id']);
    $status       = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'Available';

    if (!empty($_POST['lot_id'])) {
        $lot_id = intval($_POST['lot_id']);
        $updateQuery = "UPDATE lots SET
                        block_number = '$block_number',
                        lot_number   = '$lot_number',
                        lot_size     = '$lot_size',
                        lot_price    = '$lot_price',
                        location_id  = '$location_id',
                        status       = '$status'
                        WHERE id = $lot_id";

        $success = mysqli_query($conn, $updateQuery);
        $msg     = $success ? 'Lot updated successfully' : mysqli_error($conn);
    } else {
        $insertQuery = "INSERT INTO lots (block_number, lot_number, lot_size, lot_price, location_id, status)
                        VALUES ('$block_number', '$lot_number', '$lot_size', '$lot_price', '$location_id', '$status')";
        $success = mysqli_query($conn, $insertQuery);
        $msg     = $success ? 'Lot added successfully' : mysqli_error($conn);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => (bool)$success, 'message' => $msg]);
    exit;
}

// Delete single lot
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'delete') {

    $lot_id      = intval($_POST['lot_id']);
    $deleteQuery = "DELETE FROM lots WHERE id = $lot_id";
    $success     = mysqli_query($conn, $deleteQuery);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => (bool)$success,
        'message' => $success ? 'Lot deleted successfully' : mysqli_error($conn)
    ]);
    exit;
}

// Bulk delete lots
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {

    $ids = json_decode($_POST['lot_ids'], true);
    if (!is_array($ids) || empty($ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No lots selected']);
        exit;
    }

    $idList      = implode(',', array_map('intval', $ids));
    $deleteQuery = "DELETE FROM lots WHERE id IN ($idList)";
    $success     = mysqli_query($conn, $deleteQuery);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => (bool)$success,
        'message' => $success ? 'Lots deleted successfully' : mysqli_error($conn)
    ]);
    exit;
}


// =====================================================
// SINGLE ACCOUNT FETCH FOR EDIT MODAL (JSON, GET)
// =====================================================
if (isset($_GET['fetch'], $_GET['id']) && in_array($_GET['fetch'], ['admin', 'agent', 'user'], true)) {
    header('Content-Type: application/json');

    $id   = (int) $_GET['id'];
    $type = $_GET['fetch'];

    if ($type === 'admin') {
        $sql = "SELECT * FROM admin_accounts WHERE id = ?";
    } elseif ($type === 'agent') {
        $sql = "SELECT * FROM agent_accounts WHERE id = ?";
    } else { // user
        $sql = "SELECT * FROM user_accounts WHERE id = ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result  = $stmt->get_result();
    $account = $result->fetch_assoc();
    $stmt->close();

    if (!$account) {
        echo json_encode(['error' => 'Account not found', 'id' => $id]);
    } else {
        echo json_encode($account);
    }
    exit; // VERY IMPORTANT – stop executing before HTML
}

// =====================================================
// ADMIN ACCOUNT CRUD  (AJAX: account_action)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_action'])) {
    header('Content-Type: application/json');

    // ---------- ADD ADMIN ----------
    if ($_POST['account_action'] === 'add') {
        $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name']);
        $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email             = mysqli_real_escape_string($conn, $_POST['email']);
        $phone             = mysqli_real_escape_string($conn, $_POST['phone']);
        $address           = mysqli_real_escape_string($conn, $_POST['address']);
        $short_description = mysqli_real_escape_string($conn, $_POST['short_description']);
        $years_experience  = mysqli_real_escape_string($conn, $_POST['years_experience']);
        $availability      = isset($_POST['availability']) ? 1 : 0;
        $latitude          = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
        $longitude         = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role              = mysqli_real_escape_string($conn, $_POST['role']);

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        $sql = "INSERT INTO admin_accounts 
                (first_name, middle_name, last_name, email, phone, address, 
                 short_description, years_experience, photo_path, availability, 
                 latitude, longitude, password, role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        // strings x9, int, double, double, string, string
        $stmt->bind_param(
            "sssssssssiddss",
            $first_name, $middle_name, $last_name, $email, $phone, $address,
            $short_description, $years_experience, $photo_path, $availability,
            $latitude, $longitude, $password, $role
        );

        $ok = $stmt->execute();

        // Audit log for add
        if ($ok && isset($admin_id)) {
            $action  = 'add_admin';
            $details = 'Added admin: ' . $first_name . ' ' . $last_name . ' (' . $email . ')';
            $log_sql = "INSERT INTO audit_logs (admin_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            if ($log_stmt) {
                $log_stmt->bind_param("iss", $admin_id, $action, $details);
                $log_stmt->execute();
                $log_stmt->close();
            }
        }

        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Admin account created successfully!" : "Error creating admin account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ---------- UPDATE ADMIN ----------
    if ($_POST['account_action'] === 'update') {
    $account_id       = intval($_POST['account_id']);
    $first_name       = mysqli_real_escape_string($conn, $_POST['first_name']);
    $middle_name      = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
    $last_name        = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email            = mysqli_real_escape_string($conn, $_POST['email']);
    $phone            = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $address          = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    $short_description= mysqli_real_escape_string($conn, $_POST['short_description'] ?? '');
    $years_experience = mysqli_real_escape_string($conn, $_POST['years_experience'] ?? '');
    $availability     = isset($_POST['availability']) ? 1 : 0;
    $role             = mysqli_real_escape_string($conn, $_POST['role']);

    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo_path = handleFileUpload($_FILES['photo']);
    }

    // ---------- WITH password change ----------
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $sql = "UPDATE admin_accounts 
                SET first_name=?, middle_name=?, last_name=?, email=?, phone=?, address=?, 
                    short_description=?, years_experience=?, availability=?, password=?, 
                    role=?, photo_path=? 
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        // 8 strings + 1 int + 3 strings + 1 int  => "ssssssssissssi"
        $stmt->bind_param(
            "ssssssssissssi",
            $first_name, $middle_name, $last_name, $email, $phone, $address,
            $short_description, $years_experience, $availability, $password,
            $role, $photo_path, $account_id
        );

    // ---------- WITHOUT password change ----------
    } else {
        $sql = "UPDATE admin_accounts 
                SET first_name=?, middle_name=?, last_name=?, email=?, phone=?, address=?, 
                    short_description=?, years_experience=?, availability=?, role=?, photo_path=? 
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        // 8 strings + 1 int + 2 strings + 1 int => "ssssssssissi"
        $stmt->bind_param(
            "ssssssssissi",
            $first_name, $middle_name, $last_name, $email, $phone, $address,
            $short_description, $years_experience, $availability,
            $role, $photo_path, $account_id
        );
    }

    $ok = $stmt->execute();

    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "Account updated successfully!"
                         : "Error updating account: " . $stmt->error
    ]);
    $stmt->close();
    exit;
}


    // ---------- DELETE ADMIN ----------
    if ($_POST['account_action'] === 'delete') {
        $account_id = intval($_POST['account_id']);

        if ($account_id == $admin_id) {
            echo json_encode(['success' => false, 'error' => "You cannot delete your own account!"]);
            exit;
        }

        $sql  = "DELETE FROM admin_accounts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }
        $stmt->bind_param("i", $account_id);

        $ok = $stmt->execute();

        // Audit log for delete
        if ($ok && isset($admin_id)) {
            $action  = 'delete_admin';
            $details = 'Deleted admin account ID: ' . $account_id;
            $log_sql = "INSERT INTO audit_logs (admin_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            if ($log_stmt) {
                $log_stmt->bind_param("iss", $admin_id, $action, $details);
                $log_stmt->execute();
                $log_stmt->close();
            }
        }

        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Account deleted successfully!" : "Error deleting account: " . $conn->error
        ]);
        $stmt->close();
        exit;
    }
}

// =====================================================
// AGENT ACCOUNT CRUD (agent_action)
//   – add/update: JSON (AJAX)
//   – delete: normal form, no JSON
// =====================================================
// =============================================
// AGENT ACCOUNT CRUD (AJAX for add/update; normal form for delete)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agent_action'])) {

    $action = $_POST['agent_action'];

    // Only ADD & UPDATE should respond with JSON (AJAX)
    if ($action === 'add' || $action === 'update') {
        header('Content-Type: application/json');
    }

    // ----------------- ADD AGENT (AJAX) -----------------
    if ($action === 'add') {
        $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name']);
        $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']);
        $username          = mysqli_real_escape_string($conn, $_POST['username']);
        $email             = mysqli_real_escape_string($conn, $_POST['email']);
        $phone             = mysqli_real_escape_string($conn, $_POST['phone']);
        $address           = mysqli_real_escape_string($conn, $_POST['address']);
        $short_description = mysqli_real_escape_string($conn, $_POST['short_description']);
        // $years_experience  = mysqli_real_escape_string($conn, $_POST['years_experience']); // <-- removed
        $availability      = isset($_POST['availability']) ? 1 : 0;
        $latitude          = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
        $longitude         = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        // NOTE: years_experience removed from the columns and values
        $sql = "INSERT INTO agent_accounts 
                (first_name, middle_name, last_name, username, email, phone, address, 
                 short_description, photo_path, availability, latitude, 
                 longitude, password, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        // 8 strings (first..short_description) + photo_path(s) = 9 's'
        // availability (i), latitude(d), longitude(d), password(s)
        $stmt->bind_param(
            "sssssssssidds",
            $first_name, $middle_name, $last_name, $username, $email, $phone,
            $address, $short_description, $photo_path,
            $availability, $latitude, $longitude, $password
        );

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Agent account created successfully!"
                             : "Error creating agent account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ----------------- UPDATE AGENT (AJAX) -----------------
    if ($action === 'update') {
        $agent_id         = intval($_POST['account_id']);
        $first_name       = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name      = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
        $last_name        = mysqli_real_escape_string($conn, $_POST['last_name']);
        $username         = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $email            = mysqli_real_escape_string($conn, $_POST['email']);
        $phone            = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $address          = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $short_description= mysqli_real_escape_string($conn, $_POST['short_description'] ?? '');
        // $years_experience = mysqli_real_escape_string($conn, $_POST['years_experience'] ?? ''); // <-- removed
        $availability     = isset($_POST['availability']) ? 1 : 0;
        $latitude         = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
        $longitude        = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

            $sql = "UPDATE agent_accounts 
                    SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                        address=?, short_description=?, availability=?, 
                        latitude=?, longitude=?, password=?, photo_path=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                exit;
            }

            // 8 strings (first..short_description),
            // availability(i), latitude(d), longitude(d), password(s), photo_path(s), id(i)
            $stmt->bind_param(
                "ssssssssiddssi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $short_description, $availability,
                $latitude, $longitude, $password, $photo_path, $agent_id
            );
        } else {
            $sql = "UPDATE agent_accounts 
                    SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                        address=?, short_description=?, availability=?, 
                        latitude=?, longitude=?, photo_path=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                exit;
            }

            $stmt->bind_param(
                "ssssssssiddsi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $short_description, $availability,
                $latitude, $longitude, $photo_path, $agent_id
            );
        }

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Agent account updated successfully!"
                             : "Error updating agent account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ----------------- DELETE AGENT (NORMAL FORM) -----------------
    if ($action === 'delete') {
        $agent_id = intval($_POST['agent_id']);
        $sql      = "DELETE FROM agent_accounts WHERE id = ?";
        $stmt     = $conn->prepare($sql);

        if (!$stmt) {
            $error_message = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param("i", $agent_id);
            if ($stmt->execute()) {
                $success_message = "Agent account deleted successfully!";
            } else {
                $error_message = "Error deleting agent account: " . $conn->error;
            }
            $stmt->close();
        }
        // no JSON here, normal page reload
    }
}


// =====================================================
// USER ACCOUNT CRUD (AJAX: user_action)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
    header('Content-Type: application/json');

    // ---------- ADD USER ----------
    if ($_POST['user_action'] === 'add') {
        $first_name    = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name   = mysqli_real_escape_string($conn, $_POST['middle_name']);
        $username      = mysqli_real_escape_string($conn, $_POST['username']);
        $last_name     = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email         = mysqli_real_escape_string($conn, $_POST['email']);
        $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number']);
        $address       = mysqli_real_escape_string($conn, $_POST['address']);
        $password      = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        $sql  = "INSERT INTO user_accounts 
                 (first_name, middle_name, username, last_name, email, mobile_number, address, password, photo_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        $stmt->bind_param(
            "sssssssss",
            $first_name, $middle_name, $username, $last_name, $email,
            $mobile_number, $address, $password, $photo_path
        );

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "User account created successfully!" :
                               "Error creating user account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ---------- UPDATE USER ----------
    if ($_POST['user_action'] === 'update') {
        $user_id       = intval($_POST['account_id']);
        $first_name    = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name   = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
        $username      = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $last_name     = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email         = mysqli_real_escape_string($conn, $_POST['email']);
        $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number'] ?? '');
        $address       = mysqli_real_escape_string($conn, $_POST['address'] ?? '');

        $photo_path   = null;
        $passwordHash = null;

        $update_fields = [
            'first_name=?',
            'middle_name=?',
            'username=?',
            'last_name=?',
            'email=?',
            'mobile_number=?',
            'address=?'
        ];
        $bind_types  = "sssssss";
        $bind_values = [$first_name, $middle_name, $username, $last_name, $email, $mobile_number, $address];

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
            if ($photo_path !== null) {
                $update_fields[] = 'photo_path=?';
                $bind_types     .= 's';
                $bind_values[]   = $photo_path;
            }
        }

        if (!empty($_POST['password'])) {
            $passwordHash    = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $update_fields[] = 'password=?';
            $bind_types     .= 's';
            $bind_values[]   = $passwordHash;
        }

        $sql = "UPDATE user_accounts SET " . implode(', ', $update_fields) . " WHERE id=?";
        $bind_types  .= 'i';
        $bind_values[] = $user_id;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        $stmt->bind_param($bind_types, ...$bind_values);

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "User account updated successfully!" :
                               "Error updating user account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ---------- DELETE USER ----------
    if ($_POST['user_action'] === 'delete') {
        $user_id = intval($_POST['user_id']);
        $sql     = "DELETE FROM user_accounts WHERE id = ?";
        $stmt    = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }
        $stmt->bind_param("i", $user_id);

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "User account deleted successfully!" :
                               "Error deleting user account: " . $conn->error
        ]);
        $stmt->close();
        exit;
    }
}

// =============================================
// GENERIC GET FETCH: lots / locations (AJAX)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch'])) {
    if ($_GET['fetch'] === 'lots') {
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;

        if ($location_id > 0) {
            $lotsQuery = "SELECT lots.*, lot_locations.location_name
                          FROM lots
                          LEFT JOIN lot_locations ON lots.location_id = lot_locations.id
                          WHERE lots.location_id = $location_id
                          ORDER BY lots.id DESC";
        } else {
            $lotsQuery = "SELECT lots.*, lot_locations.location_name
                          FROM lots
                          LEFT JOIN lot_locations ON lots.location_id = lot_locations.id
                          ORDER BY lots.id DESC";
        }

        $lotsResult = mysqli_query($conn, $lotsQuery);
        $lots       = [];
        if ($lotsResult) {
            while ($lot = mysqli_fetch_assoc($lotsResult)) {
                $lots[] = $lot;
            }
        }

        header('Content-Type: application/json');
        echo json_encode($lots);
        exit;
    }

    if ($_GET['fetch'] === 'locations') {
        $locationsQuery  = "SELECT id, location_name FROM lot_locations";
        $locationsResult = mysqli_query($conn, $locationsQuery);
        $locations       = [];
        if ($locationsResult) {
            while ($row = mysqli_fetch_assoc($locationsResult)) {
                $locations[] = $row;
            }
        }

        header('Content-Type: application/json');
        echo json_encode($locations);
        exit;
    }
}



// =============================================
// VIEWINGS: REQUEST & ASSIGN
// =============================================

// Request a viewing (public form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['viewing_action']) && $_POST['viewing_action'] === 'request') {
    $user_id          = $_SESSION['user_id'] ?? null;
    $agent_id         = null;
    $client_first_name= mysqli_real_escape_string($conn, $_POST['client_first_name']);
    $client_last_name = mysqli_real_escape_string($conn, $_POST['client_last_name']);
    $client_email     = mysqli_real_escape_string($conn, $_POST['client_email']);
    $client_phone     = mysqli_real_escape_string($conn, $_POST['client_phone']);
    $lot_id           = mysqli_real_escape_string($conn, $_POST['lot_id']);
    $preferred_date   = mysqli_real_escape_string($conn, $_POST['preferred_date']);
    $status           = 'requested';
    $client_lat       = mysqli_real_escape_string($conn, $_POST['client_lat']);
    $client_lng       = mysqli_real_escape_string($conn, $_POST['client_lng']);
    $location_id      = mysqli_real_escape_string($conn, $_POST['location_id']);

    $insertQuery = "INSERT INTO viewings (
                        agent_id, user_id, client_first_name, client_last_name, client_email, client_phone,
                        lot_no, preferred_at, status, client_lat, client_lng, location_id, lot_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param(
        "iissssissssss",
        $agent_id, $user_id, $client_first_name, $client_last_name,
        $client_email, $client_phone, $lot_id, $preferred_date,
        $status, $client_lat, $client_lng, $location_id, $lot_id
    );

    if ($stmt->execute()) {
        $success_message = "Viewing request submitted successfully!";
    } else {
        $error_message = "Error submitting request: " . $stmt->error;
    }
    $stmt->close();
}

// Assign agent to viewing (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['viewing_action']) && $_POST['viewing_action'] === 'assign_agent') {
    $viewingId = intval($_POST['viewing_id']);
    $agentId   = intval($_POST['agent_id']);

    $sql  = "UPDATE viewings SET agent_id = ?, status = 'scheduled' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $agentId, $viewingId);
        if ($stmt->execute()) {
            $success_message = "Agent assigned successfully!";
        } else {
            $error_message = "Failed to assign agent.";
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "#viewings");
    exit;
}

// =============================================
// FETCH VIEWINGS & ACTIVE AGENTS FOR UI
// =============================================

// Viewing list
$all_viewings  = [];
$viewingsQuery = "SELECT v.*, ll.location_name, l.block_number, l.lot_number, l.lot_size, l.lot_price
                  FROM viewings v
                  LEFT JOIN lot_locations ll ON v.location_id = ll.id
                  LEFT JOIN lots l ON v.lot_id = l.id
                  ORDER BY v.created_at DESC
                  LIMIT 5";
$viewingsResult = mysqli_query($conn, $viewingsQuery);
if ($viewingsResult) {
    while ($viewing = mysqli_fetch_assoc($viewingsResult)) {
        $all_viewings[] = $viewing;
    }
}

// Active agents for assignment dropdown
$agents     = [];
$agentsQuery = "SELECT id, first_name, last_name FROM agent_accounts WHERE status = 'active'";
$agentsResult = mysqli_query($conn, $agentsQuery);
if ($agentsResult) {
    while ($agent = mysqli_fetch_assoc($agentsResult)) {
        $agents[] = $agent;
    }
}

// =============================================
// SINGLE FETCH BLOCK FOR ACCOUNTS (ADMIN UI)
// =============================================

// Admin accounts
$adminAccounts   = [];
$accountsQuery   = "SELECT id, first_name, middle_name, last_name, email, phone, address, short_description, years_experience, photo_path, availability, role, created_at FROM admin_accounts ORDER BY created_at DESC";
$accountsResult  = mysqli_query($conn, $accountsQuery);
if ($accountsResult) {
    while ($account = mysqli_fetch_assoc($accountsResult)) {
        $adminAccounts[] = $account;
    }
}

// Agent accounts (working, matches your DB)
$agentAccounts  = [];
$agentQuery     = "
    SELECT 
        id,
        first_name,
        middle_name,
        last_name,
        username,
        email,
        phone,
        address,
        availability,
        status,
        created_at
    FROM agent_accounts
    ORDER BY created_at DESC
";

$agentResult = mysqli_query($conn, $agentQuery);

if (!$agentResult) {
    die("Agent Query Error: " . mysqli_error($conn));
}

while ($agent = mysqli_fetch_assoc($agentResult)) {
    $agentAccounts[] = $agent;
}


// User accounts
$userAccounts = [];
$userQuery    = "SELECT id, first_name, middle_name, last_name, email, mobile_number, address, created_at FROM user_accounts ORDER BY created_at DESC LIMIT 5";
$userResult   = mysqli_query($conn, $userQuery);
if ($userResult) {
    while ($user = mysqli_fetch_assoc($userResult)) {
        $userAccounts[] = $user;
    }
}


// Handle file uploads
function handleFileUpload($file, $uploadDir = 'uploads/profiles/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    // Create upload directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) {
        return null;
    }
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('profile_') . '.' . $fileExtension;
    $uploadPath = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return $uploadPath;
    }
    return null;
}

// Handle form submissions for viewings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['viewing_action'])) {
    if ($_POST['viewing_action'] === 'request') {
        $user_id = $_SESSION['user_id'] ?? null;
        $agent_id = null;

        $client_first_name = mysqli_real_escape_string($conn, $_POST['client_first_name']);
        $client_last_name  = mysqli_real_escape_string($conn, $_POST['client_last_name']);
        $client_email      = mysqli_real_escape_string($conn, $_POST['client_email']);
        $client_phone      = mysqli_real_escape_string($conn, $_POST['client_phone']);

        // lot_id comes from the form (hidden input or similar)
        $lot_id        = (int) $_POST['lot_id'];
        $preferred_date = mysqli_real_escape_string($conn, $_POST['preferred_date']);
        $status         = 'requested';
        $client_lat     = mysqli_real_escape_string($conn, $_POST['client_lat']);
        $client_lng     = mysqli_real_escape_string($conn, $_POST['client_lng']);
        $location_id    = (int) $_POST['location_id'];

        // ✅ Fetch the lot_number of the selected lot_id
        $lot_no_query = $conn->prepare("SELECT lot_number FROM lots WHERE id = ?");
        $lot_no_query->bind_param("i", $lot_id);
        $lot_no_query->execute();
        $lot_no_result = $lot_no_query->get_result();
        $lot_row = $lot_no_result->fetch_assoc();
        $lot_no = $lot_row ? $lot_row['lot_number'] : null;  // Real lot number
        $lot_no_query->close();

        if ($lot_no === null) {
            // Safety check: lot_id not found
            $error_message = "Invalid lot selected.";
        } else {
            // ✅ Insert viewing request into the database
            $insertQuery = "INSERT INTO viewings (
                                agent_id, 
                                user_id, 
                                client_first_name, 
                                client_last_name, 
                                client_email, 
                                client_phone,
                                lot_no,          -- real lot number (e.g. 3, 6)
                                preferred_at, 
                                status, 
                                client_lat, 
                                client_lng, 
                                location_id, 
                                lot_id,          -- FK to lots.id
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param(
                "iissssissssss",
                $agent_id,
                $user_id,
                $client_first_name,
                $client_last_name,
                $client_email,
                $client_phone,
                $lot_no,          // ✅ use lot_no here (not lot_id)
                $preferred_date,
                $status,
                $client_lat,
                $client_lng,
                $location_id,
                $lot_id          // ✅ FK to lots.id
            );

            if ($stmt->execute()) {
                $success_message = "Viewing request submitted successfully!";
            } else {
                $error_message = "Error submitting request: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}


// Fetch all viewing requests except completed ones
$all_viewings = [];

$viewingsQuery = "
    SELECT 
        v.*,
        ll.location_name,
        l.block_number,
        l.lot_number,
        l.lot_size,
        l.lot_price
    FROM viewings v
    LEFT JOIN lots l 
        ON (
            v.lot_id IS NOT NULL AND l.id = v.lot_id
        ) OR (
            v.lot_id IS NULL AND l.lot_number = v.lot_no
        )
    LEFT JOIN lot_locations ll 
        ON ll.id = l.location_id
    WHERE v.status != 'completed'
    ORDER BY v.created_at DESC
";

$viewingsResult = mysqli_query($conn, $viewingsQuery);
if ($viewingsResult) {
    while ($viewing = mysqli_fetch_assoc($viewingsResult)) {
        $all_viewings[] = $viewing;
    }
}


// Handle viewing assignments and status updates
if (isset($_POST['viewing_action'])) {
    if ($_POST['viewing_action'] === 'assign_agent') {
        $viewingId = intval($_POST['viewing_id']);
        $agentId = intval($_POST['agent_id']);
        
        $sql = "UPDATE viewings SET agent_id = ?, status = 'scheduled' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $agentId, $viewingId);
            if ($stmt->execute()) {
                $success_message = "Agent assigned successfully!";
            } else {
                $error_message = "Failed to assign agent.";
            }
            $stmt->close();
        }
    }
    // Refresh the page to show updated data
    header("Location: " . $_SERVER['PHP_SELF'] . "#viewings");
    exit;
}

// Fetch all admin accounts
$adminAccounts = [];
$accountsQuery = "SELECT id, first_name, middle_name, last_name, email, phone, address, short_description, years_experience, photo_path, availability, role, created_at FROM admin_accounts ORDER BY created_at DESC";
$accountsResult = mysqli_query($conn, $accountsQuery);
if ($accountsResult) {
    while ($account = mysqli_fetch_assoc($accountsResult)) {
        $adminAccounts[] = $account;
    }
}

// Fetch all agent accounts
$agentAccounts = [];
$agentQuery = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, short_description, years_experience, photo_path, availability, status, created_at FROM agent_accounts ORDER BY created_at DESC";
$agentResult = mysqli_query($conn, $agentQuery);
if ($agentResult) {
    while ($agent = mysqli_fetch_assoc($agentResult)) {
        $agentAccounts[] = $agent;
    }
}

// Fetch all user accounts
$userAccounts = [];
$userQuery = "SELECT id, first_name, middle_name, last_name, email, mobile_number, address, created_at FROM user_accounts ORDER BY created_at DESC";
$userResult = mysqli_query($conn, $userQuery);
if ($userResult) {
    while ($user = mysqli_fetch_assoc($userResult)) {
        $userAccounts[] = $user;
    }
}

// Get total number of pending documents
$pendingDocumentsQuery = "SELECT COUNT(*) as total FROM documents WHERE status = 'pending'";
$pendingDocumentsResult = mysqli_query($conn, $pendingDocumentsQuery);
$dashboard_stats['pending_documents'] = 0;
if ($pendingDocumentsResult) {
    $pendingDocumentsRow = mysqli_fetch_assoc($pendingDocumentsResult);
    $dashboard_stats['pending_documents'] = $pendingDocumentsRow['total'];
}

$monthly_sales = [];
$salesQuery = "
  SELECT DATE_FORMAT(sale_date, '%b %Y') AS month, SUM(amount) AS total
  FROM sales
  WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
  GROUP BY month
  ORDER BY sale_date ASC
";
$salesResult = mysqli_query($conn, $salesQuery);
if ($salesResult) {
    while ($row = mysqli_fetch_assoc($salesResult)) {
        $monthly_sales[] = [
            'month' => $row['month'],
            'amount' => (float)$row['total']
        ];
    }
}

// Handle fetching analytics data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'analytics') {
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
    $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;

    // KPIs
    $salesQuery = "SELECT SUM(amount) as total FROM sales WHERE 1";
    $lotsQuery = "SELECT COUNT(*) as total FROM lots WHERE 1";
    $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";
    $pendingDocumentsQuery = "SELECT COUNT(*) as total FROM documents WHERE status = 'pending'";

    // Add filters
    $salesWhere = [];
    if ($date_from) $salesWhere[] = "sale_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to) $salesWhere[] = "sale_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
    if ($location_id) $salesWhere[] = "location_id = $location_id";
    if ($salesWhere) $salesQuery .= " AND " . implode(' AND ', $salesWhere);

    // Monthly sales trend
    $monthlySalesQuery = "
      SELECT DATE_FORMAT(sale_date, '%b %Y') AS month, SUM(amount) AS total
      FROM sales
      WHERE 1
    ";
    if ($salesWhere) $monthlySalesQuery .= " AND " . implode(' AND ', $salesWhere);
    $monthlySalesQuery .= " GROUP BY month ORDER BY sale_date ASC";

    // Fetch KPIs
    $salesResult = mysqli_query($conn, $salesQuery);
    $lotsResult = mysqli_query($conn, $lotsQuery);
    $agentsResult = mysqli_query($conn, $agentsQuery);
    $pendingDocumentsResult = mysqli_query($conn, $pendingDocumentsQuery);

    $kpis = [
        'total_sales' => $salesResult ? (float)mysqli_fetch_assoc($salesResult)['total'] : 0,
        'total_lots' => $lotsResult ? (int)mysqli_fetch_assoc($lotsResult)['total'] : 0,
        'available_agents' => $agentsResult ? (int)mysqli_fetch_assoc($agentsResult)['total'] : 0,
        'pending_documents' => $pendingDocumentsResult ? (int)mysqli_fetch_assoc($pendingDocumentsResult)['total'] : 0,
    ];

    // Fetch monthly sales
    $monthly_sales = [];
    $monthlySalesResult = mysqli_query($conn, $monthlySalesQuery);
    if ($monthlySalesResult) {
        while ($row = mysqli_fetch_assoc($monthlySalesResult)) {
            $monthly_sales[] = [
                'month' => $row['month'],
                'amount' => (float)$row['total']
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'kpis' => $kpis,
        'monthly_sales' => $monthly_sales
    ]);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export']) && $_GET['export'] === 'analytics') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analytics_export.csv"');

    $output = fopen('php://output', 'w');

    // Write the header row
    fputcsv($output, ['Metric', 'Value']);

    // Fetch KPIs
    $salesQuery = "SELECT SUM(amount) as total FROM sales";
    $lotsQuery = "SELECT COUNT(*) as total FROM lots";
    $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";
    $pendingDocumentsQuery = "SELECT COUNT(*) as total FROM documents WHERE status = 'pending'";

    $salesResult = mysqli_query($conn, $salesQuery);
    $lotsResult = mysqli_query($conn, $lotsQuery);
    $agentsResult = mysqli_query($conn, $agentsQuery);
    $pendingDocumentsResult = mysqli_query($conn, $pendingDocumentsQuery);

    $kpis = [
        'Total Sales' => $salesResult ? (float)mysqli_fetch_assoc($salesResult)['total'] : 0,
        'Total Lots' => $lotsResult ? (int)mysqli_fetch_assoc($lotsResult)['total'] : 0,
        'Available Agents' => $agentsResult ? (int)mysqli_fetch_assoc($agentsResult)['total'] : 0,
        'Pending Documents' => $pendingDocumentsResult ? (int)mysqli_fetch_assoc($pendingDocumentsResult)['total'] : 0,
    ];

    // Write KPIs to CSV
    foreach ($kpis as $metric => $value) {
        fputcsv($output, [$metric, $value]);
    }

    // Fetch monthly sales
    fputcsv($output, []); // Empty row for separation
    fputcsv($output, ['Month', 'Sales Amount']);
    $monthlySalesQuery = "
        SELECT DATE_FORMAT(sale_date, '%b %Y') AS month, SUM(amount) AS total
        FROM sales
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY sale_date ASC
    ";
    $monthlySalesResult = mysqli_query($conn, $monthlySalesQuery);
    if ($monthlySalesResult) {
        while ($row = mysqli_fetch_assoc($monthlySalesResult)) {
            fputcsv($output, [$row['month'], (float)$row['total']]);
        }
    }

    // Fetch top agents
    fputcsv($output, []); // Empty row for separation
    fputcsv($output, ['Agent Name', 'Email', 'Sales Count', 'Total Sales', 'Average Deal Size']);
    $topAgentsQuery = "
        SELECT 
            CONCAT(a.first_name, ' ', a.last_name) AS name,
            a.email,
            COUNT(s.id) AS sales_count,
            SUM(s.amount) AS total_amount,
            IFNULL(ROUND(SUM(s.amount)/COUNT(s.id)), 0) AS avg_deal_size
        FROM agent_accounts a
        LEFT JOIN sales s ON a.id = s.agent_id
        GROUP BY a.id
        ORDER BY total_amount DESC, sales_count DESC
        LIMIT 10
    ";
    $topAgentsResult = mysqli_query($conn, $topAgentsQuery);
    if ($topAgentsResult) {
        while ($row = mysqli_fetch_assoc($topAgentsResult)) {
            fputcsv($output, [
                $row['name'],
                $row['email'],
                (int)$row['sales_count'],
                (float)$row['total_amount'],
                (float)$row['avg_deal_size']
            ]);
        }
    }

    fclose($output);
    exit;
}


/* ============================================================
   CORE HELPERS
============================================================ */

// Respond with JSON
function respondJSON($data) {
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

// Audit Log Writer
function logAudit($conn, $admin_id, $action, $details) {
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (admin_id, action, details) 
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $admin_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}

// Send Notification
function sendNotification($conn, $title, $message, $type = 'info') {
    $stmt = $conn->prepare("
        INSERT INTO notifications (title, message, type) 
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("sss", $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

// File Upload Helper
function safeUploadFile($fileKey, $folder = "uploads/documents/") {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== 0) return null;

    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $newName = time() . "_" . rand(1000, 9999) . "." . $ext;
    $path = $folder . $newName;

    if (move_uploaded_file($_FILES[$fileKey]["tmp_name"], $path)) {
        return $path;
    }
    return null;
}

/* ============================================================
   FETCH HANDLERS — GET Requests
============================================================ */

// ------------------------------------------------------------
// Fetch Audit Logs
// ------------------------------------------------------------
if (isset($_GET['fetch']) && $_GET['fetch'] === 'audit_logs') {

    $q = "
        SELECT a.*, ad.first_name, ad.last_name
        FROM audit_logs a
        LEFT JOIN admin_accounts ad ON ad.id = a.admin_id
        ORDER BY a.created_at DESC
        LIMIT 100
    ";
    $res = mysqli_query($conn, $q);

    $logs = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $logs[] = $row;
    }

    respondJSON($logs);
}


// ------------------------------------------------------------
// Fetch Notifications
// ------------------------------------------------------------
if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications') {

    $q = "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 20";
    $res = mysqli_query($conn, $q);

    $data = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $data[] = $row;
    }

    respondJSON($data);
}


// ------------------------------------------------------------
// Fetch Notification Counter
// ------------------------------------------------------------
if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications_count') {

    $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM notifications");
    $row = mysqli_fetch_assoc($res);

    respondJSON(["count" => intval($row["total"])]);
}


// ------------------------------------------------------------
// Fetch Pending Documents
// ------------------------------------------------------------
if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents') {

    $q = "SELECT * FROM documents WHERE status='pending' ORDER BY uploaded_at DESC";
    $res = mysqli_query($conn, $q);

    $docs = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $docs[] = $row;
    }

    respondJSON($docs);
}


/* ============================================================
   POST HANDLERS — ACTIONS
============================================================ */

// ------------------------------------------------------------
// Approve Document
// ------------------------------------------------------------
if (isset($_POST["action"]) && $_POST["action"] === "approve_document") {

    $doc_id = intval($_POST["doc_id"]);

    $q = "
        UPDATE documents 
        SET status='approved', reviewed_at=NOW() 
        WHERE id = $doc_id
    ";
    mysqli_query($conn, $q);

    logAudit($conn, $admin_id, "Document Approved", "Document ID: $doc_id approved");
    sendNotification($conn, "Document Approved", "Document #$doc_id was approved.", "success");

    respondJSON(["success" => true]);
}


// ------------------------------------------------------------
// Reject Document
// ------------------------------------------------------------
if (isset($_POST["action"]) && $_POST["action"] === "reject_document") {

    $doc_id = intval($_POST["doc_id"]);
    $remarks = mysqli_real_escape_string($conn, $_POST["remarks"]);

    $q = "
        UPDATE documents 
        SET status='rejected', remarks='$remarks', reviewed_at=NOW() 
        WHERE id = $doc_id
    ";
    mysqli_query($conn, $q);

    logAudit($conn, $admin_id, "Document Rejected", "Document ID: $doc_id rejected");
    sendNotification($conn, "Document Rejected", "A document was rejected.", "warning");

    respondJSON(["success" => true]);
}


// ------------------------------------------------------------
// Handle document upload from agents/users (if used)
// ------------------------------------------------------------
if (isset($_POST["action"]) && $_POST["action"] === "upload_document") {

    $user_id  = intval($_POST["user_id"] ?? 0);
    $agent_id = intval($_POST["agent_id"] ?? 0);
    $type     = mysqli_real_escape_string($conn, $_POST["type"]);

    $file = safeUploadFile("document_file");
    if (!$file) respondJSON(["success" => false, "error" => "Upload failed"]);

    $stmt = $conn->prepare("
        INSERT INTO documents (user_id, agent_id, filename, type, status) 
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("iiss", $user_id, $agent_id, $file, $type);
    $stmt->execute();
    $stmt->close();

    sendNotification($conn, "New Document Uploaded", "A new $type document was uploaded.", "info");

    respondJSON(["success" => true]);
}

// Fetch all user documents for admin
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'all_user_documents') {
    $docs = [];
    $stmt = $conn->prepare("
        SELECT d.*, u.first_name, u.last_name, u.email
        FROM user_documents d
        LEFT JOIN user_accounts u ON d.user_id = u.id
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard</title>
  <style>
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
    }

    .sidebar-wrapper {
  background-color: transparent;
  padding: 25px;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  height: 100vh;
  position: sticky;
  top: 0;
  left: 0;
  z-index: 100;
}
.sidebar {
  width: 275px;
  background-color: #3e5f3e;
  border-radius: 5px;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px 15px;
  height: 95vh; /* Fill full viewport height */
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
  position: sticky;
  top: 0;
  
}
    .logo-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 20px;
      margin-top: -10px;
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
      width: 70px;
      height: 70px;
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
      padding: 10px;
      border-radius: 8px;
      width: 100%;
      margin-bottom: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
      text-align: center;

    }

    .user-profile img {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      object-fit: cover;
      margin-right: 8px;
      background-color: #d9d9d9;
      margin-bottom: 6px;
    }

    .user-details {
      font-size: 11px;
      color: black;
      line-height: 1.2;
    }

    .user-details div:first-child {
  font-size: 14px;
  font-weight: 500;
}

.user-details div:last-child {
  font-size: 12px;
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
      display: flex;
      align-items: center;
      justify-content: flex-start;
      transition: background-color 0.2s;
      cursor: pointer;
    }

    .nav a:hover {
      background-color: #3D4D26;
    }

    .nav a.active {
      background-color: #2d4e1e;
    }

    .nav-icon {
      width: 24px;
      height: 24px;
      margin-right: 8px;
      vertical-align: middle;
      filter: brightness(0) invert(1);
    }

    /* Eye icon using CSS for Manage Viewings */
    .nav-icon-eye {
      background-color: white;
      mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
      mask-size: contain;
      -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
      -webkit-mask-size: contain;
      filter: none;
    }

    .container {
  flex: 1;
  padding: 40px;
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow-y: auto;
}
    .divider {
      width: 5px;
      background-color: #2D4D26;
      height: calc(100vh - 40px);
      margin-top: 20px;
      border-radius: 5px;
    }

    .header {
      display: flex;
      justify-content: flex-end;
      align-items: flex-start;
      margin-bottom: 30px;
      text-align: right;
    }

    .header h2 {
      color: #2d482d;
      font-size: 30px;
    }

    .header small {
      font-size: 14px;
      color: #555;
    }

    .dashboard-cards {
      display: flex;
      gap: 20px;
      margin-bottom: 30px;
    }

    .card {
      background-color: #fff;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-width: 200px;
      position: relative;
    }

    .card-content {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .card-text {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      padding-left: 10px;
      padding-right: 10px;
    }

    .card-title {
      font-size: 14px;
      font-weight: bold;
      color: #2d482d;
      margin-bottom: 5px;
    }

    .card-subtitle {
      font-size: 12px;
      color: #555;
      margin-bottom: 10px;
    }

    .card-icon {
      font-size: 26px;
      color: #2d482d;
      width: 60px;
      height: 60px;
      object-fit: contain;
      position: absolute;
      top: 50%;
      right: 20px;
      transform: translateY(-50%);
    }

    .card-number {
      font-size: 24px;
      font-weight: bold;
      color: #2d482d;
    }

    .section {
      display: block;
    }

    .section.hidden {
      display: none;
    }

    .table-section {
      background-color: #fff;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      width: 100%;
      flex: 1;
    }

    .table-section h2 {
      font-size: 20px;
      font-weight: 600;
      color: #2d482d;
      margin-bottom: 10px;
    }

    .table-section table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      font-size: 14px;
      color: #2d482d;
    }

    .table-section thead {
      background-color: #3e5f3e;
      color: white;
    }

    .table-section th, .table-section td {
      padding: 12px 10px;
      text-align: left;
      border-bottom: 1px solid #ddd;
    }

    .table-section tbody tr:hover {
      background-color: #f1f1f1;
    }

    .table-section thead tr:hover {
      background-color: transparent;
    }

    .table-section button, .btn {
      background-color: #2d482d;
      color: white;
      border: none;
      padding: 6px 12px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 13px;
      transition: background-color 0.2s ease-in-out;
      margin: 0 2px;
    }

    .table-section button:hover, .btn:hover {
      background-color: #3e5f3e;
    }

    .btn-danger {
      background-color: #dc3545;
    }

    .btn-danger:hover {
      background-color: #c82333;
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
      color: #2d482d;
    }

    .form-group input, .form-group select {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }

    .form-row {
      display: flex;
      gap: 15px;
    }

    .alert {
      padding: 12px;
      border-radius: 6px;
      margin-bottom: 20px;
      font-size: 14px;
    }

    .alert.success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }

    .alert.error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }

    .location-dropdown {
      margin-bottom: 20px;
    }

    .status-badge {
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: bold;
      color: white;
    }

    .status-scheduled {
      background-color: #28a745;
    }

    .status-pending, .status-requested {
      background-color: #ffc107;
      color: #212529;
    }

    .status-completed {
      background-color: #17a2b8;
    }

    .status-cancelled {
      background-color: #dc3545;
    }

    .btn-assign {
      background-color: #28a745;
      color: white;
      border: none;
      padding: 6px 12px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
    }

    .btn-assign:hover {
      background-color: #218838;
    }

    .account-type-nav {
      display: flex;
      gap: 0;
      margin-bottom: 30px;
      border-bottom: 1px solid #e0e0e0;
    }
    
    .account-type-nav a {
      padding: 12px 24px;
      background-color: #f8f9fa;
      color: #666;
      text-decoration: none;
      border: 1px solid #e0e0e0;
      border-bottom: none;
      font-size: 14px;
      font-weight: 500;
      transition: all 0.3s ease;
      position: relative;
    }
    
    .account-type-nav a:first-child {
      border-radius: 8px 0 0 0;
    }
    
    .account-type-nav a:last-child {
      border-radius: 0 8px 0 0;
    }
    
    .account-type-nav a:hover {
      background-color: #e8f5e8;
      color: #2d482d;
      border-color: #c3e6cb;
    }
    
    .account-type-nav a.active {
      background-color: #2d482d;
      color: white;
      border-color: #2d482d;
      z-index: 1;
    }
    
    .account-type-nav a.active:hover {
      background-color: #3e5f3e;
      border-color: #3e5f3e;
    }

    .account-section {
      display: none;
      background: white;
      border: 1px solid #e0e0e0;
      border-top: none;
      border-radius: 0 0 8px 8px;
      padding: 30px;
    }
    
    .account-section.active {
      display: block;
    }

    .form-container {
      background: #fafafa;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 30px;
      margin-bottom: 40px;
    }

    .form-title {
      font-size: 20px;
      font-weight: 600;
      color: #333;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 1px solid #e0e0e0;
    }

    .form-section {
      margin-bottom: 25px;
    }

    .form-section-title {
      font-size: 14px;
      font-weight: 600;
      color: #555;
      margin-bottom: 15px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: #333;
      font-size: 14px;
    }

    .form-group input, 
    .form-group select, 
    .form-group textarea {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      background-color: white;
      transition: border-color 0.2s;
    }

    .form-group input:focus, 
    .form-group select:focus, 
    .form-group textarea:focus {
      outline: none;
      border-color: #666;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    .form-row-three {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 20px;
    }

    .photo-upload-section {
      background: white;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: 20px;
      text-align: center;
    }

    .photo-preview {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #e0e0e0;
      margin: 0 auto 15px;
      display: block;
    }

    .photo-placeholder {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      background-color: #f5f5f5;
      border: 2px dashed #ccc;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      color: #999;
      font-size: 12px;
    }

    .file-input-wrapper {
      position: relative;
      display: inline-block;
    }

    .file-input-wrapper input[type=file] {
      position: absolute;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
    }

    .file-input-label {
      background: #f8f9fa;
      border: 1px solid #ddd;
      padding: 8px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      color: #666;
      transition: background-color 0.2s;
    }

    .file-input-label:hover {
      background: #e9ecef;
    }

    .availability-toggle {
      display: flex;
      align-items: center;
      gap: 12px;
      background: white;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: 15px;
    }

    .toggle-switch {
      position: relative;
      width: 50px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #ccc;
      transition: .4s;
      border-radius: 24px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }

    input:checked + .slider {
      background-color: #333;
    }

    input:checked + .slider:before {
      transform: translateX(26px);
    }

    .location-section {
      background: white;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: 20px;
    }

    .location-controls {
      display: flex;
      gap: 10px;
      margin-top: 15px;
    }

    .btn-location {
      background: #f8f9fa;
      border: 1px solid #ddd;
      padding: 8px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      color: #666;
      transition: all 0.2s;
    }

    .btn-location:hover {
      background: #e9ecef;
      border-color: #999;
    }

    .btn-primary {
      background-color: #333;
      color: white;
      border: none;
      padding: 12px 30px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: background-color 0.2s;
    }

    .btn-primary:hover {
      background-color: #555;
    }

    .location-status {
      margin-top: 10px;
      padding: 8px 12px;
      border-radius: 4px;
      font-size: 13px;
      display: none;
    }

    .location-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }

    .location-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }

    .accounts-table {
      background: white;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      overflow: hidden;
    }

    .accounts-table h3 {
      background: #f8f9fa;
      margin: 0;
      padding: 20px;
      border-bottom: 1px solid #e0e0e0;
      font-size: 16px;
      font-weight: 600;
      color: #333;
    }

    .accounts-table table {
      width: 100%;
      border-collapse: collapse;
    }

    .accounts-table th {
      background: #f8f9fa;
      padding: 12px 15px;
      text-align: left;
      font-weight: 500;
      color: #666;
      font-size: 13px;
      border-bottom: 1px solid #e0e0e0;
    }

    .accounts-table td {
      padding: 15px;
      border-bottom: 1px solid #f0f0f0;
      font-size: 14px;
      color: #333;
    }

    .accounts-table tbody tr:hover {
      background-color: #f9f9f9;
    }

    .profile-photo {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      border: 1px solid #e0e0e0;
    }

    .profile-placeholder {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background-color: #f5f5f5;
      border: 1px solid #e0e0e0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      color: #999;
    }

    .status-active {
      background-color: #d4edda;
      color: #155724;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
    }

    .status-inactive {
      background-color: #f8d7da;
      color: #721c24;
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
    }

    .btn-small {
      background: #f8f9fa;
      border: 1px solid #ddd;
      padding: 6px 12px;
      border-radius: 4px;
      font-size: 12px;
      color: #666;
      cursor: pointer;
      margin-right: 5px;
      transition: all 0.2s;
    }

    .btn-small:hover {
      background: #e9ecef;
      border-color: #999;
    }

    .btn-danger {
      background: #dc3545;
      color: white;
      border: 1px solid #dc3545;
    }

    .btn-danger:hover {
      background: #c82333;
      border-color: #c82333;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #666;
      background: #f9f9f9;
      border-radius: 8px;
      margin: 20px 0;
    }

    textarea {
      min-height: 80px;
      resize: vertical;
    }

    .status-reschedule_requested {
  background-color: #f4d03f;
  color: #234;
}
  </style>
</head>
<body onload="loadLocations()">
  <div class="sidebar-wrapper">
    <div class="sidebar">
    <div class="logo-title" style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
  <img src="assets/a.png" alt="Logo" class="profile-pic" style="width:60px;height:60px;border-radius:50%;object-fit:cover;background-color:transparent;">
  <div style="display:flex;flex-direction:column;justify-content:center;line-height:1;">
    <h2 style="font-weight:700;font-size:1.18rem;letter-spacing:1px;line-height:1;color:white;margin:0;">NUEVO PUERTA</h2>
    <span style="font-size:0.95rem;letter-spacing:0.5px;color:white;opacity:0.9;line-height:1;">REAL ESTATE</span>
  </div>
</div>

      <div class="user-profile">
        <img src="assets/s.png" alt="User Image">
        <div class="user-details">
          <div style="font-size:16px;font-weight:500;">
            <?php echo htmlspecialchars($admin_name); ?>
          </div>
          <div style="font-size:15px;color:#23613b;">
            <?php echo htmlspecialchars($admin_role); ?>
          </div>
        </div>
      </div>

      <div class="nav">
        <a data-target="section-dashboard" class="active">
          <img src="assets/mdi_home.png" alt="Home Icon" class="nav-icon">
          <span>Home</span>
        </a>
        <a data-target="section-accounts">
          <img src="assets/mdi_user.png" alt="Accounts Icon" class="nav-icon">
          <span>Manage Accounts</span>
        </a>
        <a data-target="section-lots">
          <img src="assets/lotpinicon.png" alt="Lot Icon" class="nav-icon">
          <span>Manage Lots</span>
        </a>
        <a data-target="section-viewings">
          <div class="nav-icon nav-icon-eye"></div>
          <span>Manage Viewings</span>
        </a>

        <!-- NEW: Notifications, Audit Logs, Documents (UI only) -->
        <a data-target="section-notifications">
          <!-- bell icon via simple svg so no missing asset -->
          <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
            <path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6V11a6 6 0 0 0-5-5.91V4a1 1 0 1 0-2 0v1.09A6 6 0 0 0 6 11v5l-2 2v1h16v-1l-2-2z"/>
          </svg>
          <span>Notifications</span>
        </a>
        <a data-target="section-audit-logs">
          <img src="assets/audit_icon.png" alt="Audit Logs Icon" class="nav-icon">
          <span>Audit Logs</span>
        </a>
        <a data-target="section-documents">
          <img src="assets/document_icon.png" alt="Documents Icon" class="nav-icon">
          <span>Document Review</span>
        </a>

        <a href="#" onclick="confirmLogout()">
          <img src="assets/ic_baseline-logout.png" alt="Logout Icon" class="nav-icon">
          <span>Logout</span>
        </a>
      </div>
    </div>
  </div>

  <div class="divider"></div>

  <div class="container">
    <!-- DASHBOARD SECTION -->
    <div id="section-dashboard" class="section">
      <div class="header">
        <div>
          <h2>Welcome, <?php echo htmlspecialchars($admin_name); ?></h2>
          <small>Admin Dashboard. Monitor and manage system activities.</small>
        </div>
      </div>

      <div class="dashboard-cards">
        <div class="card">
          <div class="card-content">
            <div class="card-text">
              <div class="card-title">CLIENTS</div>
              <div class="card-subtitle">Number of Clients</div>
              <div class="card-number"><?php echo number_format($dashboard_stats['clients']); ?></div>
            </div>
            <img src="assets/mdi_people.png" alt="Clients Icon" class="card-icon">
          </div>
        </div>

        <div class="card">
          <div class="card-content">
            <div class="card-text">
              <div class="card-title">LOTS</div>
              <div class="card-subtitle">Total Number of Lots</div>
              <div class="card-number"><?php echo number_format($dashboard_stats['lots']); ?></div>
            </div>
            <img src="assets/ooui_map-pin.png" alt="Lots Icon" class="card-icon">
          </div>
        </div>

        <div class="card">
          <div class="card-content">
            <div class="card-text">
              <div class="card-title">AGENTS</div>
              <div class="card-subtitle">Available Agents</div>
              <div class="card-number"><?php echo number_format($dashboard_stats['agents']); ?></div>
            </div>
            <img src="assets/mdi_face-agent.png" alt="Agents Icon" class="card-icon">
          </div>
        </div>
      </div>

      <div class="table-section">
        <h2>Recent Activity</h2>
        
        <!-- Enhanced Recent Activity Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
          <!-- Recent User Registrations -->
          <div>
            <h3 style="color: #2d482d; margin-bottom: 15px; font-size: 16px;">Recent User Registrations</h3>
            <?php
            $recentUsersQuery = "SELECT first_name, middle_name, last_name, email, created_at 
                                FROM user_accounts 
                                ORDER BY created_at DESC 
                                LIMIT 5";
            $recentUsersResult = mysqli_query($conn, $recentUsersQuery);
            
            if ($recentUsersResult && mysqli_num_rows($recentUsersResult) > 0):
            ?>
              <div style="background: #f8f9fa; border-radius: 8px; padding: 15px;">
                <?php while ($user = mysqli_fetch_assoc($recentUsersResult)): ?>
                  <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; last-child:border-bottom: none;">
                    <div style="font-weight: 500; color: #333;">
                      <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                      <?php echo htmlspecialchars($user['email']); ?> • 
                      <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div style="text-align: center; color: #666; padding: 20px;">
                No recent user registrations
              </div>
            <?php endif; ?>
          </div>

          <!-- Recent Viewing Requests -->
          <div>
            <h3 style="color: #2d482d; margin-bottom: 15px; font-size: 16px;">Recent Viewing Requests</h3>
            <?php
            $recentViewingsQuery = "SELECT v.client_first_name, v.client_last_name, v.status, v.created_at, 
                                          ll.location_name, l.block_number, l.lot_number
                                   FROM viewings v
                                   LEFT JOIN lot_locations ll ON v.location_id = ll.id
                                   LEFT JOIN lots l ON v.lot_id = l.id
                                   ORDER BY v.created_at DESC 
                                   LIMIT 5";
            $recentViewingsResult = mysqli_query($conn, $recentViewingsQuery);
            
            if ($recentViewingsResult && mysqli_num_rows($recentViewingsResult) > 0):
            ?>
              <div style="background: #f8f9fa; border-radius: 8px; padding: 15px;">
                <?php while ($viewing = mysqli_fetch_assoc($recentViewingsResult)): ?>
                  <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0; last-child:border-bottom: none;">
                    <div style="font-weight: 500; color: #333;">
                      <?php echo htmlspecialchars($viewing['client_first_name'] . ' ' . $viewing['client_last_name']); ?>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                      <?php echo htmlspecialchars($viewing['location_name']); ?> - Block <?php echo htmlspecialchars($viewing['block_number']); ?>, Lot <?php echo htmlspecialchars($viewing['lot_number']); ?>
                    </div>
                    <div style="font-size: 11px; color: #999;">
                      <span class="status-badge status-<?php echo strtolower($viewing['status']); ?>" style="font-size: 10px; padding: 2px 6px;">
                        <?php echo htmlspecialchars(ucfirst($viewing['status'])); ?>
                      </span>
                      • <?php echo date('M d, Y', strtotime($viewing['created_at'])); ?>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div style="text-align: center; color: #666; padding: 20px;">
                No recent viewing requests
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Quick Stats Summary -->
        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
          <h3 style="color: #2d482d; margin-bottom: 15px; font-size: 16px;">System Overview</h3>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <?php
            // Get additional stats
            $pendingViewingsQuery = "SELECT COUNT(*) as total FROM viewings WHERE status = 'pending'";
            $pendingViewingsResult = mysqli_query($conn, $pendingViewingsQuery);
            $pendingViewings = $pendingViewingsResult ? mysqli_fetch_assoc($pendingViewingsResult)['total'] : 0;

            $availableLotsQuery = "SELECT COUNT(*) as total FROM lots WHERE status = 'Available'";
            $availableLotsResult = mysqli_query($conn, $availableLotsQuery);
            $availableLots = $availableLotsResult ? mysqli_fetch_assoc($availableLotsResult)['total'] : 0;

            $soldLotsQuery = "SELECT COUNT(*) as total FROM lots WHERE status = 'Sold'";
            $soldLotsResult = mysqli_query($conn, $soldLotsQuery);
            $soldLots = $soldLotsResult ? mysqli_fetch_assoc($soldLotsResult)['total'] : 0;
            ?>
            
            <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e0e0e0;">
              <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo $pendingViewings; ?></div>
              <div style="font-size: 12px; color: #666;">Pending Viewings</div>
            </div>
            
            <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e0e0e0;">
              <div style="font-size: 24px; font-weight: bold; color: #17a2b8;"><?php echo $availableLots; ?></div>
              <div style="font-size: 12px; color: #666;">Available Lots</div>
            </div>
            
            <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e0e0e0;">
              <div style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo $soldLots; ?></div>
              <div style="font-size: 12px; color: #666;">Sold Lots</div>
            </div>
            
            <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e0e0e0;">
              <div style="font-size: 24px; font-weight: bold; color: #6f42c1;">
                <?php echo ($dashboard_stats['lots'] > 0) ? round(($soldLots / $dashboard_stats['lots']) * 100, 1) : 0; ?>%
              </div>
              <div style="font-size: 12px; color: #666;">Sales Rate</div>
            </div>
          </div>
        </div>
      </div>
    </div>

   <!-- MANAGE ACCOUNTS SECTION -->
<div id="section-accounts" class="section hidden">
  <div class="header">
    <div>
      <h2>Account Management</h2>
      <small>Create, edit, and manage different types of accounts</small>
    </div>
  </div>

  <div class="table-section">
    <?php if (isset($success_message)): ?>
      <div class="alert success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
      <div class="alert error"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <!-- Account Type Navigation -->
    <div class="account-type-nav">
      <a href="#" onclick="showAccountType('admin')" id="admin-tab" class="active">Admin Accounts</a>
      <a href="#" onclick="showAccountType('agent')" id="agent-tab">Agent Accounts</a>
      <a href="#" onclick="showAccountType('user')" id="user-tab">User Accounts</a>
    </div>

  <!-- ADMIN ACCOUNTS SECTION -->
<div id="admin-accounts" class="account-section active">
  <div class="form-container">
    <div class="form-title">Add New Admin Account</div>
    
    <!-- Admin Account Form -->
    <form method="POST" enctype="multipart/form-data" id="admin-account-form">
      <input type="hidden" name="account_action" value="add">
      
      <!-- Personal Information -->
      <div class="form-section">
        <div class="form-section-title">Personal Information</div>
        <div class="form-row-three">
          <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" required>
          </div>
          <div class="form-group">
            <label for="middle_name">Middle Name (Optional)</label>
            <input type="text" id="middle_name" name="middle_name">
          </div>
          <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" required>
          </div>
        </div>
      </div>

      <!-- Contact Information -->
      <div class="form-section">
        <div class="form-section-title">Contact Information</div>
        <div class="form-row">
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required>
          </div>
          <div class="form-group">
            <label for="phone">Phone</label>
            <input type="tel" id="phone" name="phone" required>
          </div>
        </div>
        <div class="form-group">
          <label for="address">Address</label>
          <textarea id="address" name="address" required></textarea>
        </div>
      </div>

      <!-- PROFILE PHOTO (Optional) -->
      <div class="form-section">
        <div class="form-section-title">Profile Photo (Optional)</div>
        <div class="photo-upload-section">
          <div class="photo-placeholder" id="admin-photo-preview">
            No Photo
          </div>
          <div class="file-input-wrapper">
            <input type="file" id="admin_photo" name="photo" accept="image/*" onchange="previewPhoto(this, 'admin-photo-preview')">
            <label for="admin_photo" class="file-input-label">Choose Photo</label>
          </div>
          <div style="font-size: 12px; color: #999; margin-top: 8px;">
            JPG, PNG, or GIF (Max 5MB) — Optional
          </div>
        </div>
      </div>

     <!-- Login Details -->
<div class="form-section">
  <div class="form-section-title">Login Details</div>

  <div class="form-group">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
  </div>

  <div class="form-group">
    <label for="confirm_password">Confirm Password</label>
    <input type="password" id="confirm_password" name="confirm_password" required>
    <small id="password-error" style="color: red; display: none;">
      Passwords do not match.
    </small>
  </div>
</div>


      <!-- REMOVE: Role -->
      <!-- REMOVE: Availability -->
      <!-- REMOVE: Geolocation -->

      <button type="submit" class="btn-primary">Create Admin Account</button>
      <button type="button" class="btn btn-danger" onclick="resetForm('admin-account-form')">Cancel</button>
    </form>
  </div>

  <!-- Admin Accounts List -->
  <div class="accounts-table">
    <h3>Existing Admin Accounts</h3>
    
    <?php if (empty($adminAccounts)): ?>
      <div class="empty-state"><p>No admin accounts found.</p></div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Photo</th>
            <th>Name</th>
            <th>Email</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($adminAccounts as $account): ?>
          <tr>
            <td>
              <?php if ($account['photo_path']): ?>
                <img src="<?= htmlspecialchars($account['photo_path']) ?>" class="profile-photo">
              <?php else: ?>
                <div class="profile-placeholder">No Photo</div>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($account['first_name'].' '.$account['last_name']) ?></strong></td>
            <td><?= htmlspecialchars($account['email']) ?></td>
            <td>
              <button onclick="viewProfile(<?= $account['id'] ?>, 'admin')" class="btn-small">View</button>
              <button onclick="editAccount(<?= $account['id'] ?>, 'admin')" class="btn-small">Edit</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>


  <!-- AGENT ACCOUNTS SECTION -->
<div id="agent-accounts" class="account-section">
  <div class="form-container">
    <div class="form-title">Create Agent Account</div>
    
    <form method="POST" enctype="multipart/form-data" id="agent-account-form">
      <input type="hidden" name="agent_action" value="add">
      
      <!-- Personal Information -->
      <div class="form-section">
        <div class="form-section-title">Personal Information</div>
        <div class="form-row-three">
          <div class="form-group">
            <label for="agent_first_name">First Name</label>
            <input type="text" id="agent_first_name" name="first_name" required>
          </div>
          <div class="form-group">
            <label for="agent_middle_name">Middle Name (Optional)</label>
            <input type="text" id="agent_middle_name" name="middle_name">
          </div>
          <div class="form-group">
            <label for="agent_last_name">Last Name</label>
            <input type="text" id="agent_last_name" name="last_name" required>
          </div>
        </div>
        <div class="form-group">
          <label for="agent_username">Username</label>
          <input type="text" id="agent_username" name="username" required>
        </div>
      </div>

      <!-- Contact Information -->
      <div class="form-section">
        <div class="form-section-title">Contact Information</div>
        <div class="form-row">
          <div class="form-group">
            <label for="agent_email">Email</label>
            <input type="email" id="agent_email" name="email" required>
          </div>
          <div class="form-group">
            <label for="agent_phone">Phone</label>
            <input type="tel" id="agent_phone" name="phone" required>
          </div>
        </div>
        <div class="form-group">
          <label for="agent_address">Address</label>
          <textarea id="agent_address" name="address" required></textarea>
        </div>
      </div>

      <!-- Photo Upload (optional) -->
      <div class="form-section">
        <div class="form-section-title">Profile Photo (Optional)</div>
        <div class="photo-upload-section">
          <div class="photo-placeholder" id="agent-photo-preview">
            No Photo
          </div>
          <div class="file-input-wrapper">
            <input type="file" id="agent_photo" name="photo" accept="image/*"
                   onchange="previewPhoto(this, 'agent-photo-preview')">
            <label for="agent_photo" class="file-input-label">Choose Photo</label>
          </div>
          <div style="font-size: 12px; color: #999; margin-top: 8px;">
            JPG, PNG, or GIF (Max 5MB) — Optional
          </div>
        </div>
      </div>

   <!-- Professional Information -->
<div class="form-section">
  <div class="form-section-title">Professional Information</div>

  <div class="form-group">
    <label for="agent_years_experience">Years of Experience</label>
    <select id="agent_years_experience" name="years_experience" required>
      <option value="">Select Experience</option>
      <option value="0-1">0-1 Years (Entry Level)</option>
      <option value="2-3">2-3 Years (Junior Agent)</option>
      <option value="4-5">4-5 Years (Mid-level Agent)</option>
      <option value="6-10">6-10 Years (Senior Agent)</option>
      <option value="10+">10+ Years (Expert Agent)</option>
    </select>
  </div>

  <div class="form-group">
    <label for="agent_short_description">Professional Description</label>
    <textarea id="agent_short_description" name="short_description" required
      placeholder="Describe the agent's expertise, specializations, and professional background..."></textarea>
  </div>
</div> <!-- END Professional Information -->


<!-- ACCOUNT SECURITY (NEW, SEPARATE SECTION) -->
<div class="form-section">
  <div class="form-section-title">Account Security</div>

  <div class="form-row">
    <!-- Password -->
    <div class="form-group">
      <label for="agent_password">Password</label>
      <input type="password" id="agent_password" name="password" required>
    </div>

    <!-- Confirm Password -->
    <div class="form-group">
      <label for="agent_confirm_password">Confirm Password</label>
      <input type="password" id="agent_confirm_password" name="confirm_password" required>
      <small id="agent-password-error" style="color:#dc3545;display:none;">
        Passwords do not match.
      </small>
    </div>
  </div>
</div> <!-- END Account Security -->


<!-- Availability -->
<div class="form-section">
  <div class="form-section-title">Availability Status</div>
  <div class="availability-toggle">
    <label class="toggle-switch">
      <input type="checkbox" name="availability" id="agent_availability" checked>
      <span class="slider"></span>
    </label>
    <span>Available for client assignments</span>
  </div>
</div>


      <!-- Geolocation (optional, can be set by admin OR agent later) -->
      <div class="form-section">
        <div class="form-section-title">Geolocation (Optional)</div>
        <div class="location-section">
          <p style="margin-bottom: 15px; color: #666; font-size: 14px;">
            Location is crucial for matching this agent to nearby clients for optimal service delivery.
            If not available now, the agent can update this later.
          </p>
          
          <div class="form-row">
            <div class="form-group">
              <label for="agent_latitude">Latitude</label>
              <input type="number" step="any" id="agent_latitude" name="latitude" readonly>
            </div>
            <div class="form-group">
              <label for="agent_longitude">Longitude</label>
              <input type="number" step="any" id="agent_longitude" name="longitude" readonly>
            </div>
          </div>
          
          <div class="location-controls">
            <button type="button" onclick="getCurrentLocationAgent()" class="btn-location">
              Get Current Location
            </button>
            <button type="button" onclick="clearLocationAgent()" class="btn-location">
              Clear Location
            </button>
          </div>
          
          <div id="agent-location-status" class="location-status"></div>
        </div>
      </div>

      <button type="submit" class="btn-primary">Create Agent Account</button>
      <button type="button" class="btn btn-danger" onclick="resetForm('agent-account-form')">Cancel</button>
    </form>
  </div>


     <div class="accounts-table">
  <h3>Existing Agent Accounts</h3>

  <?php
    // Run a fresh query just for this table
    $agentQuery = "
      SELECT 
        id,
        first_name,
        middle_name,
        last_name,
        username,
        email,
        phone,
        address,
        availability,
        status,
        created_at
      FROM agent_accounts
      ORDER BY created_at DESC
    ";

    $agentResult = mysqli_query($conn, $agentQuery);
  ?>

  <?php if (!$agentResult): ?>
      <div class="empty-state">
        <p>Database error: <?php echo htmlspecialchars(mysqli_error($conn)); ?></p>
      </div>

  <?php elseif (mysqli_num_rows($agentResult) === 0): ?>
     <div class="empty-state" style="
      background: #fafafa;
      padding: 40px;
      text-align: center;
      border-radius: 8px;
      font-size: 18px;
      color: #666;
  ">
      <p>No agent accounts found in the database.</p>
  </div>

  <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Email</th>
            <th>Mobile</th>
            <th>Address</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($agent = mysqli_fetch_assoc($agentResult)): ?>
            <tr>
              <td>
                <strong>
                  <?php
                    echo htmlspecialchars(
                      $agent['first_name'] . ' ' .
                      (!empty($agent['middle_name']) ? $agent['middle_name'] . ' ' : '') .
                      $agent['last_name']
                    );
                  ?>
                </strong>
              </td>

              <td><?php echo htmlspecialchars($agent['username']); ?></td>
              <td><?php echo htmlspecialchars($agent['email']); ?></td>
              <td><?php echo htmlspecialchars($agent['phone']); ?></td>

              <td>
                <?php
                  $addr = $agent['address'] ?? '';
                  echo htmlspecialchars(mb_strlen($addr) > 50 ? mb_substr($addr, 0, 50) . '...' : $addr);
                ?>
              </td>

              <td>
                <?php if ($agent['status'] === 'active'): ?>
                  <span class="status-active">Active</span>
                <?php else: ?>
                  <span class="status-inactive">Inactive</span>
                <?php endif; ?>
              </td>

              <td><?php echo date('M d, Y', strtotime($agent['created_at'])); ?></td>

             <td>
  <div class="action-buttons">
    <button class="btn-small"
            onclick="editAccount(<?php echo (int)$agent['id']; ?>, 'agent')">
      Edit
    </button>

    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this agent account?');">
      <input type="hidden" name="agent_action" value="delete">
      <input type="hidden" name="agent_id" value="<?php echo (int)$agent['id']; ?>">
      <button type="submit" class="btn-small btn-danger">Delete</button>
    </form>
  </div>
</td>

            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
  <?php endif; ?>
</div>
</div>


    <!-- USER ACCOUNTS SECTION -->
    <div id="user-accounts" class="account-section">
      <div class="form-container">
        <div class="form-title">Create User Account</div>
        
        <form method="POST" id="user-account-form">
          <input type="hidden" name="user_action" value="add">
          
          <!-- Personal Information -->
          <div class="form-section">
            <div class="form-section-title">PERSONAL INFORMATION</div>
            <div class="form-row-three">
              <div class="form-group">
                <label for="user_first_name">First Name</label>
                <input type="text" id="user_first_name" name="first_name" required>
              </div>
              <div class="form-group">
                <label for="user_middle_name">Middle Name (Optional)</label>
                <input type="text" id="user_middle_name" name="middle_name">
              </div>
              <div class="form-group">
                <label for="user_last_name">Last Name</label>
                <input type="text" id="user_last_name" name="last_name" required>
              </div>
            </div>
            <div class="form-group">
              <label for="user_username">Username</label>
              <input type="text" id="user_username" name="username" required>
            </div>
          </div>

          <!-- Contact Information -->
          <div class="form-section">
            <div class="form-section-title">Contact Information</div>
            <div class="form-row">
              <div class="form-group">
                <label for="user_email">Email</label>
                <input type="email" id="user_email" name="email" required>
              </div>
              <div class="form-group">
                <label for="user_mobile">Mobile Number</label>
                <input type="tel" id="user_mobile" name="mobile_number" required>
              </div>
            </div>
            <div class="form-group">
              <label for="user_address">Address</label>
              <textarea id="user_address" name="address" required></textarea>
            </div>
          </div>

        <!-- ACCOUNT SECURITY -->
<div class="form-section">
  <div class="form-section-title">Account Security</div>

  <div class="form-row">
    <!-- Password -->
    <div class="form-group">
      <label for="user_password">Password</label>
      <input type="password" id="user_password" name="password" required>
    </div>

    <!-- Confirm Password -->
    <div class="form-group">
      <label for="user_confirm_password">Confirm Password</label>
      <input type="password" id="user_confirm_password" required>
      <small id="user-password-error"
            style="color:#dc3545;display:none;font-size:13px;">
        Passwords do not match.
      </small>
    </div>
  </div>
</div>

<button type="submit" class="btn-primary">Create User Account</button>
<button type="button" class="btn btn-danger" onclick="resetForm('user-account-form')">
  Cancel
</button>
        </form>
      </div>
      <div class="accounts-table">
        <h3>Existing User Accounts</h3>
        <?php if (empty($userAccounts)): ?>
          <div class="empty-state">
            <p>No user accounts found in the database.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Address</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($userAccounts as $user): ?>
              <tr>
                <td>
                  <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?></strong>
                </td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><?php echo htmlspecialchars($user['mobile_number']); ?></td>
                <td><?php echo htmlspecialchars(substr($user['address'], 0, 50)); ?>...</td>
                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                <td>
                  <button onclick="viewProfile(<?php echo $user['id']; ?>, 'user')" class="btn-small">View</button>
                  <button onclick="editAccount(<?php echo $user['id']; ?>, 'user')" class="btn-small">Edit</button>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user account?')">
                    <input type="hidden" name="user_action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                    <button type="submit" class="btn-small btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

    <!-- LOTS SECTION -->
    <div id="section-lots" class="section hidden">
      <div class="header">
        <div>
          <h2>Manage Lots</h2>
          <small>Create, edit, and manage property lots</small>
        </div>
      </div>

      <div class="table-section">
        <div class="location-dropdown" style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
  <label for="location_id" style="font-weight:500; min-width:90px;">Location:</label>
  <select id="location_id" name="location_id" style="flex:1; min-width:250px;">
    <option value="" disabled selected>Please select a location first</option>
          </select>
        </div>

        <div id="lot-message" style="margin-bottom:15px;display:none;padding:10px 18px;border-radius:6px;font-size:15px;"></div>

        <table id="lots-table">
          <thead>
            <tr>
              <th></th>
              <th>Block Number</th>
              <th>Lot Number</th>
              <th>Lot Size</th>
              <th>Lot Price</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>

         <tbody id="lots-table-body">
  <tr id="new-row" class="hidden" style="display:none;">
    
    <!-- FIX: Add missing left column -->
    <td></td>

    <td><input type="text" id="block_number"></td>
    <td><input type="text" id="lot_number"></td>
    <td><input type="text" id="lot_size"></td>
    <td><input type="text" id="lot_price"></td>

    <td>
      <select id="status">
        <option value="Available">Available</option>
        <option value="Sold">Sold</option>
        <option value="Reserved">Reserved</option>
      </select>
    </td>

    <td>
      <button onclick="saveLot()">Save</button>
      <button onclick="cancelAdd()">Cancel</button>
    </td>
  </tr>
</tbody>
        </table>


        <button onclick="addNewLot()">Add New Lot</button>
        <button onclick="bulkDeleteLots()" class="btn btn-danger" style="margin-top:10px;">Delete Selected Lots</button>
      </div>
    </div>

    <!-- Edit Lot Modal -->
    <div id="editLotModal" style="
      display: none;
      position: fixed;
      z-index: 2100;
      left: 0; top: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.6);
      justify-content: center; align-items: center;
      overflow: auto;
    ">
      <div style="
        background: #fff;
        padding: 24px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        width: 95%; max-width: 400px; position: relative;
        max-height: 90vh;
        overflow-y: auto;
      ">
        <span onclick="closeEditLotModal()" style="
          color: #aaa; float: right; font-size: 32px; font-weight: normal; line-height: 1; cursor: pointer; margin-left: 15px;
        ">&times;</span>
        <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
          Edit Lot
        </h3>
        <form id="editLotForm">
          <input type="hidden" name="lot_id" id="edit_lot_id">
          <div id="editLotFields"></div>
          <button type="submit" class="btn-primary" style="margin-top: 18px;">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- MANAGE VIEWINGS SECTION -->
    <div id="section-viewings" class="section hidden">
      <div class="header">
        <div>
          <h2>Manage Viewing Requests</h2>
          <small>Review and assign viewing requests to agents</small>
        </div>
      </div>

      <div class="table-section">
        <?php if (isset($success_message)): ?>
          <div class="alert success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
          <div class="alert error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if (empty($all_viewings)): ?>
          <div style="text-align: center; padding: 40px; color: #666;">
            <div style="font-size: 48px; margin-bottom: 20px;">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #999;">
                <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <p style="font-size: 18px; margin-bottom: 10px;">No viewing requests yet</p>
            <p>When users submit viewing requests, they will appear here.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Contact</th>
                <th>Location</th>
                <th>Lot Details</th>
                <th>Preferred Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_viewings as $viewing): ?>
                <tr>
                  <td>
                    <strong><?php echo htmlspecialchars($viewing['client_first_name'] . ' ' . $viewing['client_last_name']); ?></strong>
                    <?php if (!empty($viewing['note'])): ?>
                        <br><small style="color: #666;">Note: <?php echo htmlspecialchars($viewing['note']); ?></small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div>
                      <a href="mailto:<?php echo htmlspecialchars($viewing['client_email'] ?? 'N/A'); ?>" style="color: #2d482d; text-decoration: none;">
                        <?php echo htmlspecialchars($viewing['client_email'] ?? 'N/A'); ?>
                      </a>
                    </div>
                    <div style="font-size: 12px; color: #666;">
                      <a href="tel:<?php echo htmlspecialchars($viewing['client_phone'] ?? 'N/A'); ?>" style="color: #2d482d; text-decoration: none;">
                        <?php echo htmlspecialchars($viewing['client_phone'] ?? 'N/A'); ?>
                      </a>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($viewing['location_name']); ?></td>
                  <td>
                    <strong>Block: <?php echo htmlspecialchars($viewing['block_number']); ?></strong><br>
                    Lot: <?php echo htmlspecialchars($viewing['lot_number']); ?><br>
                    Size: <?php echo htmlspecialchars($viewing['lot_size']); ?> sqm<br>
                    Price: ₱<?php echo number_format($viewing['lot_price'], 2); ?>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($viewing['preferred_at'])); ?></td>
                  <td>
                    <span class="status-badge status-<?php echo strtolower($viewing['status']); ?>">
                      <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $viewing['status']))); ?>
                    </span>
                  </td>
                  <td>
                    <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 6px;">
                      <form method="POST" style="width: 100%;">
                        <input type="hidden" name="viewing_action" value="assign_agent">
                        <input type="hidden" name="viewing_id" value="<?php echo $viewing['id']; ?>">
                        <select name="agent_id" required style="width: 140px;">
                          <option value="">Select Agent</option>
                          <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <div style="display: flex; gap: 6px; margin-top: 6px;">
                          <button type="submit" class="btn-small">Assign</button>
                          <button type="button"
                            class="btn-small"
                            onclick="viewProfile(<?= $viewing['user_id'] ?: 0 ?>, 'user', event)">
                            View Client
                          </button>
                        </div>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- View Client Modal -->
    <div id="viewClientModal" style="
      display: none; 
      position: fixed; 
      z-index: 2000;
      left: 0; 
      top: 0; 
      width: 100%; 
      height: 100%; 
      overflow: auto; 
      background-color: rgba(0,0,0,0.6);
      justify-content: center; 
      align-items: center;
    ">
      <div style="
        background-color: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        width: 90%; 
        max-width: 450px; 
        position: relative;
      ">
        <span onclick="closeViewClientModal()" style="
          color: #aaa;
          float: right;
          font-size: 32px;
          font-weight: normal;
          line-height: 1;
          cursor: pointer;
          margin-left: 15px;
        ">&times;</span>
        
        <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
          Client Profile
        </h3>
        
        <div id="viewClientContent">
          Loading client details...
        </div>
      </div>
    </div>

    <!-- Edit Account Modal -->
    <div id="editAccountModal" style="
      display: none;
      position: fixed;
      z-index: 2100;
      left: 0; top: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.6);
      justify-content: center; align-items: center;
      overflow: auto;
    ">
      <div style="
        background: #fff;
        padding: 24px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        width: 95%; max-width: 500px; position: relative;
        max-height: 90vh;
        overflow-y: auto;
      ">
        <span onclick="closeEditAccountModal()" style="
          color: #aaa; float: right; font-size: 32px; font-weight: normal; line-height: 1; cursor: pointer; margin-left: 15px;
        ">&times;</span>
        <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
          Edit Account
        </h3>
        <form id="editAccountForm" enctype="multipart/form-data">
          <input type="hidden" id="edit_account_id" name="account_id">
          <input type="hidden" id="edit_account_type" name="account_type">
          <div id="editAccountPhotoSection"></div>
          <div id="editAccountFields"></div>
          <button type="submit" class="btn-primary" style="margin-top: 18px;">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- ANALYTICS SECTION -->
    <div id="section-analytics" class="section hidden">
      <div class="header">
        <div>
          <h2>Analytics Dashboard</h2>
          <small>Track sales performance and agent statistics</small>
        </div>
      </div>

      <div class="table-section">
        <!-- Filter Toolbar -->
        <div class="analytics-filters" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #e0e0e0; position: relative;">
          <!-- Export Analytics Button -->
          <button onclick="exportAnalytics()" class="btn-primary" style="position: absolute; top: 20px; right: 20px; padding: 9px 20px; white-space: nowrap;">Export Analytics</button>

          <h3 style="margin: 0 0 15px 0; color: #2d482d; font-size: 16px; font-weight: 600;">Filter Options</h3>
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 15px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
              <label for="analytics_date_from">Date From</label>
              <input type="date" id="analytics_date_from" name="date_from" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label for="analytics_date_to">Date To</label>
              <input type="date" id="analytics_date_to" name="date_to" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label for="analytics_location">Location (Optional)</label>
              <select id="analytics_location" name="location" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                <option value="">All Locations</option>
              </select>
            </div>
            <div>
              <button onclick="applyAnalyticsFilters()" class="btn-primary" style="padding: 9px 20px; white-space: nowrap;">Apply Filters</button>
            </div>
          </div>
        </div>
      </div>

      <!-- KPI Cards -->
      <div class="analytics-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Sales</div>
              <div id="kpi-total-sales" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
              <div style="font-size: 12px; color: #666; margin-top: 4px;">Last 30 days</div>
            </div>
            <div style="width: 50px; height: 50px; background: #2d482d; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12z"/>
              </svg>
            </div>
          </div>
        </div>

        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Lots</div>
              <div id="kpi-total-lots" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
              <div style="font-size: 12px; color: #666; margin-top: 4px;">Available properties</div>
            </div>
            <div style="width: 50px; height: 50px; background: #17a2b8; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
              </svg>
            </div>
          </div>
        </div>

        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Available Agents</div>
              <div id="kpi-available-agents" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
              <div style="font-size: 12px; color: #666; margin-top: 4px;">Active and ready</div>
            </div>
            <div style="width: 50px; height: 50px; background: #28a745; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                <path d="M16 4c0-1.11.89-2 2-2s2 .89 2 2-.89 2-2 2-2-.89-2-2zm4 18v-6h2.5l-2.54-7.63A2 2 0 0 0 17.87 7H14.8c-.8 0-1.54.35-2.05.96L11 10.5V7H9v8h2.5l1.5-3.5 2 6z"/>
              </svg>
            </div>
          </div>
        </div>

        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Pending Documents</div>
              <div id="kpi-pending-documents" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
              <div style="font-size: 12px; color: #666; margin-top: 4px;">Awaiting review</div>
            </div>
            <div style="width: 50px; height: 50px; background: #ffc107; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" fill="white" viewBox="0 0 24 24">
                <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- Top Agents Table -->
      <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 30px; overflow: hidden;">
        <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
          <h3 style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Top Agents by Sales</h3>
        </div>
        <div id="top-agents-loading" style="text-align: center; padding: 40px; color: #666;">
          Loading agents data...
        </div>
        <div id="top-agents-content" style="display: none;">
          <table id="top-agents-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: #f8f9fa;">
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Rank</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Agent</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Sales Count</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Total Amount</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0;">Avg Deal Size</th>
              </tr>
            </thead>
            <tbody id="top-agents-tbody">
              <!-- filled by JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Monthly Sales Trend Chart -->
      <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
          <h3 style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Monthly Sales Trend (Last 12 Months)</h3>
        </div>
        <div style="padding: 30px;">
          <canvas id="monthly-sales-chart" width="400" height="200"></canvas>
        </div>
      </div>
    </div>

    <!-- NEW: NOTIFICATIONS SECTION -->
    <div id="section-notifications" class="section hidden">
      <div class="header">
        <div>
          <h2>Notifications</h2>
          <small>System alerts and updates</small>
        </div>
      </div>
      <div class="table-section">
        <div id="notifications-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 350px; overflow-y: auto;">
          <p style="text-align: center; color: #666;">Loading notifications...</p>
        </div>
      </div>
    </div>

    <!-- NEW: AUDIT LOGS SECTION -->
    <div id="section-audit-logs" class="section hidden">
      <div class="header">
        <div>
          <h2>Audit Logs</h2>
          <small>Track admin actions and system changes</small>
        </div>
      </div>
      <div class="table-section">
        <div id="audit-logs-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 400px; overflow-y: auto;">
          <p style="text-align: center; color: #666;">Loading audit logs...</p>
        </div>
      </div>
    </div>

    <!-- NEW: DOCUMENT REVIEW SECTION -->
    <div id="section-documents" class="section hidden">
      <div class="header">
        <div>
          <h2>Document Review</h2>
          <small>Review and manage pending documents</small>
        </div>
      </div>

      <div class="table-section">
        <div id="documents-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 400px; overflow-y: auto;">
          <p style="text-align: center; color: #666;">Loading documents...</p>
        </div>
      </div>
    </div>

  </div> <!-- end .container -->
</body>




  <!-- Add Chart.js library -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

 <script>
  // ================================
  // MAIN NAVIGATION & INITIAL SETUP
  // ================================
  document.addEventListener('DOMContentLoaded', function() {
    // Section navigation
    const sections = [
      'section-dashboard',
      'section-accounts',
      'section-lots',
      'section-viewings',
      'section-analytics',
      'section-documents',
      'section-notifications',
      'section-audit-logs'
    ];
    
    function showSection(targetId) {
      sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
          section.classList.toggle('hidden', sectionId !== targetId);
        }
      });
      
      // Update active nav link
      document.querySelectorAll('[data-target]').forEach(link => {
        link.classList.toggle('active', link.dataset.target === targetId);
      });

      // Load data based on section
      if (targetId === 'section-lots') {
        loadLocations();
      } else if (targetId === 'section-analytics') {
        loadAnalyticsData();
      } else if (targetId === 'section-documents') {
        loadDocuments();
      } else if (targetId === 'section-notifications') {
        loadNotifications();
      } else if (targetId === 'section-audit-logs') {
        loadAuditLogs();
      }
    }

    // Handle navigation clicks
    document.querySelectorAll('[data-target]').forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        showSection(this.dataset.target);
      });
    });

    // Add Analytics navigation dynamically (if not present)
    const viewingsNav = document.querySelector('[data-target="section-viewings"]');
    if (viewingsNav && !document.querySelector('[data-target="section-analytics"]')) {
      const analyticsNav = document.createElement('a');
      analyticsNav.setAttribute('data-target', 'section-analytics');
      analyticsNav.innerHTML = `
        <svg width="24" height="24" fill="white" viewBox="0 0 24 24" class="nav-icon">
          <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
        </svg>
        <span>Analytics</span>
      `;
      viewingsNav.parentNode.insertBefore(analyticsNav, viewingsNav.nextSibling);
      
      // Add click handler for new analytics nav
      analyticsNav.addEventListener('click', function(e) {
        e.preventDefault();
        showSection('section-analytics');
      });
    }

    // Show dashboard by default
    showSection('section-dashboard');

    // Set up lots location dropdown
    const locationSelect = document.getElementById('location_id');
    if (locationSelect) {
      locationSelect.addEventListener('change', function() {
        loadLots(this.value);
      });
    }

    // Initial loads
    loadNotifications();
    refreshBadges();

    // Auto-refresh every 20 seconds
    setInterval(() => {
      loadNotifications();      // always keep notifications fresh
      refreshBadges();          // update counts

      // Only refresh audit logs/documents if their section is open
      const docsSection  = document.getElementById('section-documents');
      const auditSection = document.getElementById('section-audit-logs');

      if (docsSection && !docsSection.classList.contains('hidden')) {
        loadDocuments();
      }
      if (auditSection && !auditSection.classList.contains('hidden')) {
        loadAuditLogs();
      }
    }, 20000);

    // Global functions
    window.loadLocations         = loadLocations;
    window.loadLots              = loadLots;
    window.saveLot               = saveLot;
    window.editLot               = editLot;
    window.saveEdit              = saveEdit;
    window.deleteLot             = deleteLot;
    window.addNewLot             = addNewLot;
    window.cancelAdd             = cancelAdd;
    window.cancelEdit            = cancelEdit;
    window.showAccountType       = showAccountType;
    window.confirmLogout         = confirmLogout;
    window.applyAnalyticsFilters = applyAnalyticsFilters;
    window.loadTopAgents         = loadTopAgents;
    window.exportAnalytics       = exportAnalytics;
    window.loadDocuments         = loadDocuments;
    window.loadNotifications     = loadNotifications;
    window.loadAuditLogs         = loadAuditLogs;
  });

  // ===========================
  // BADGES (Notifications / Docs)
  // ===========================
  function updateBadge(el, count) {
    if (!el) return;
    if (count > 0) {
      el.style.display = 'inline-block';
      el.textContent = count > 99 ? '99+' : count;
    } else {
      el.style.display = 'none';
    }
  }

  function refreshBadges() {
    const notifBadge = document.getElementById('notifications-badge');
    const docsBadge  = document.getElementById('documents-badge');

    if (notifBadge) {
      fetch(window.location.pathname + '?fetch=notifications_count')
        .then(r => r.json())
        .then(data => updateBadge(notifBadge, data.count || 0))
        .catch(() => {});
    }

    if (docsBadge) {
      fetch(window.location.pathname + '?fetch=documents')
        .then(r => r.json())
        .then(docs => updateBadge(docsBadge, docs.length || 0))
        .catch(() => {});
    }
  }

  // ===========================
  // LOTS MANAGEMENT FUNCTIONS
  // ===========================
  function loadLocations() {
    fetch(window.location.pathname + '?fetch=locations')
      .then(response => response.json())
      .then(locations => {
        const selects = ['location_id', 'analytics_location'];
        selects.forEach(selectId => {
          const select = document.getElementById(selectId);
          if (select) {
            const isAnalytics = selectId === 'analytics_location';
            select.innerHTML = isAnalytics
              ? '<option value="">All Locations</option>'
              : '<option value="" disabled selected>Please select a location first</option>';
            locations.forEach(location => {
              const option = document.createElement('option');
              option.value = location.id;
              option.textContent = location.location_name;
              select.appendChild(option);
            });
          }
        });
      })
      .catch(error => console.error('Error loading locations:', error));
  }

  function loadLots(locationId = '') {
    fetch(`${window.location.pathname}?fetch=lots&location_id=${locationId}`)
      .then(response => response.json())
      .then(data => {
        const tbody = document.getElementById('lots-table-body');
        const newRow = document.getElementById('new-row');
        if (newRow) newRow.remove();
        
        tbody.innerHTML = '';
        
        if (data.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No lots available.</td></tr>';
        } else {
          data.forEach(lot => {
            const row = tbody.insertRow();
            row.setAttribute('data-id', lot.id);
            row.innerHTML = `
              <td><input type="checkbox" class="lot-checkbox" value="${lot.id}"></td>
              <td>${lot.block_number}</td>
              <td>${lot.lot_number}</td>
              <td>${lot.lot_size}</td>
              <td>${lot.lot_price}</td>
              <td>${lot.status}</td>
              <td>
                <button onclick='openEditLotModal(${JSON.stringify(lot)})'>Edit</button>
                <button onclick="deleteLot(${lot.id})">Delete</button>
              </td>
            `;
          });
        }

        if (newRow) tbody.appendChild(newRow);
      })
      .catch(error => console.error('Error loading lots:', error));
  }

  function saveLot() {
    const fields = ['block_number', 'lot_number', 'lot_size', 'lot_price', 'status'];
    const locationId = document.getElementById('location_id').value;
    
    const data = {};
    let isValid = true;
    
    fields.forEach(field => {
      const value = document.getElementById(field).value;
      if (!value || (field.includes('number') && isNaN(value))) {
        isValid = false;
      }
      data[field] = value;
    });

    if (!isValid || !locationId) {
      alert('Please fill out all fields correctly and select a location.');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save');
    Object.keys(data).forEach(key => formData.append(key, data[key]));
    formData.append('location_id', locationId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot added successfully!', true);
          loadLots(locationId);
          cancelAdd();
        } else {
          alert('Error: ' + result.error);
        }
      })
      .catch(error => console.error('Error:', error));
  }

  function deleteLot(id) {
    if (!confirm('Are you sure you want to delete this lot?')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('lot_id', id);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot deleted successfully!', true);
          loadLots(document.getElementById('location_id').value);
        } else {
          alert('Error: ' + result.error);
        }
      })
      .catch(error => console.error('Error:', error));
  }

  function editLot(button) {
    const row = button.closest('tr');
    const cells = row.querySelectorAll('td');

    for (let i = 0; i < 5; i++) {
      cells[i].setAttribute('data-original', cells[i].innerText);
    }

    cells[0].innerHTML = `<input type="text" value="${cells[0].getAttribute('data-original')}">`;
    cells[1].innerHTML = `<input type="text" value="${cells[1].getAttribute('data-original')}">`;
    cells[2].innerHTML = `<input type="text" value="${cells[2].getAttribute('data-original')}">`;
    cells[3].innerHTML = `<input type="text" value="${cells[3].getAttribute('data-original')}">`;
    cells[4].innerHTML = `
      <select>
        <option value="Available" ${cells[4].getAttribute('data-original') === 'Available' ? 'selected' : ''}>Available</option>
        <option value="Sold" ${cells[4].getAttribute('data-original') === 'Sold' ? 'selected' : ''}>Sold</option>
        <option value="Reserved" ${cells[4].getAttribute('data-original') === 'Reserved' ? 'selected' : ''}>Reserved</option>
      </select>
    `;
    cells[5].innerHTML = '<button onclick="saveEdit(this)">Save</button><button onclick="cancelEdit(this)">Cancel</button>';
  }

  function saveEdit(button) {
    const row = button.closest('tr');
    const id = row.getAttribute('data-id');
    const inputs = row.querySelectorAll('input, select');
    const locationId = document.getElementById('location_id').value;

    if (!locationId) {
      showLotMessage('Please select a location.', false);
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('lot_id', id);
    formData.append('block_number', inputs[0].value);
    formData.append('lot_number', inputs[1].value);
    formData.append('lot_size', inputs[2].value);
    formData.append('lot_price', inputs[3].value);
    formData.append('status', inputs[4].value);
    formData.append('location_id', locationId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot updated successfully!', true);
          setTimeout(() => loadLots(locationId), 1000);
        } else {
          showLotMessage('Error: ' + result.error, false);
        }
      })
      .catch(error => console.error('Error:', error));
  }

  function addNewLot() {
    const newRow = document.getElementById('new-row');
    if (newRow) newRow.style.display = 'table-row';
  }

  function cancelAdd() {
    const newRow = document.getElementById('new-row');
    newRow.style.display = 'none';
    newRow.querySelectorAll('input').forEach(input => input.value = '');
    document.getElementById('status').value = 'Available';
  }

  function cancelEdit(button) {
    loadLots(document.getElementById('location_id').value);
  }

  function showLotMessage(msg, success = true) {
    const msgDiv = document.getElementById('lot-message');
    if (!msgDiv) return;
    msgDiv.textContent = msg;
    msgDiv.style.display = 'block';
    msgDiv.style.background = success ? '#d4edda' : '#f8d7da';
    msgDiv.style.color = success ? '#155724' : '#721c24';
    msgDiv.style.border = success ? '1px solid #c3e6cb' : '1px solid #f5c6cb';
    setTimeout(() => msgDiv.style.display = 'none', 3000);
  }

  // ===========================
  // DOCUMENT REVIEW FUNCTIONS
 
function loadDocuments() {
  const container = document.getElementById('documents-container');
  if (!container) return;

  container.innerHTML = '<p style="text-align: center; color: #666;">Loading documents...</p>';

  // Fetch ALL user-uploaded documents for admin review
  fetch(window.location.pathname + '?fetch=all_user_documents')
    .then(response => response.json())
    .then(documents => {
      // update badge count too
      const docsBadge = document.getElementById('documents-badge');
      updateBadge(docsBadge, documents.length || 0);

      if (!documents.length) {
        container.innerHTML = '<p style="text-align: center; color: #666;">No user documents found.</p>';
        return;
      }

      container.innerHTML = documents.map(doc => `
        <div style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
          <strong>${doc.file_name || 'Untitled Document'}</strong>
          <div style="font-size: 13px; color: #333;">Type: ${doc.doc_type || 'N/A'}</div>
          <div style="font-size: 13px; color: #333;">User: ${doc.first_name || ''} ${doc.last_name || ''} (${doc.email || ''})</div>
          <div style="font-size: 12px; color: #999;">
            Uploaded: ${doc.uploaded_at ? new Date(doc.uploaded_at).toLocaleString() : 'N/A'}
          </div>
          <div style="font-size: 12px; color: #999;">
            Status: <span style="font-weight:600;">${doc.status || 'Pending'}</span>
          </div>
          <div style="margin-top: 8px;">
            ${doc.file_path ? `<a href="${doc.file_path}" target="_blank" class="btn btn-sm btn-secondary">View</a>` : ''}
            <button class="btn btn-sm btn-primary" onclick="approveDocument(${doc.id})">Approve</button>
            <button class="btn btn-sm btn-danger"  onclick="rejectDocument(${doc.id})">Reject</button>
          </div>
        </div>
      `).join('');
    })
    .catch(error => {
      container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load user documents.</p>';
      console.error('Error loading documents:', error);
    });
}

function approveDocument(id) {
  if (!confirm('Approve this document?')) return;

  const formData = new FormData();
  formData.append('action', 'approve_document');
  formData.append('doc_id', id);

  fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert('Document approved.');
        loadDocuments();
        refreshBadges();
      } else {
        alert('Failed to approve document.');
      }
    })
    .catch(() => alert('Failed to approve document.'));
}

function rejectDocument(id) {
  const remarks = prompt('Enter remarks for rejection (optional):', '');
  if (remarks === null) return; // cancelled

  const formData = new FormData();
  formData.append('action', 'reject_document');
  formData.append('doc_id', id);
  formData.append('remarks', remarks);

  fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        alert('Document rejected.');
        loadDocuments();
        refreshBadges();
      } else {
        alert('Failed to reject document.');
      }
    })
    .catch(() => alert('Failed to reject document.'));
}

  // ===========================
  // NOTIFICATIONS FUNCTIONS
  // ===========================
  function loadNotifications() {
    const container = document.getElementById('notifications-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading notifications...</p>';

    fetch(window.location.pathname + '?fetch=notifications')
      .then(response => response.json())
      .then(notifications => {
        // update badge
        const notifBadge = document.getElementById('notifications-badge');
        updateBadge(notifBadge, notifications.length || 0);

        if (!notifications.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No notifications available.</p>';
          return;
        }

        container.innerHTML = notifications.map(notification => `
          <div style="padding: 15px; margin-bottom: 10px; border-radius: 6px; background: ${getNotificationColor(notification.type)}; color: ${getNotificationTextColor(notification.type)};">
            <strong>${notification.title}</strong>
            <p style="margin: 5px 0;">${notification.message}</p>
            <small style="color: #999;">${notification.created_at ? new Date(notification.created_at).toLocaleString() : ''}</small>
          </div>
        `).join('');
      })
      .catch(error => {
        container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load notifications.</p>';
        console.error('Error loading notifications:', error);
      });
  }

  function getNotificationColor(type) {
    switch (type) {
      case 'success': return '#d4edda';
      case 'warning': return '#fff3cd';
      case 'error':   return '#f8d7da';
      default:        return '#e2e3e5';
    }
  }

  function getNotificationTextColor(type) {
    switch (type) {
      case 'success': return '#155724';
      case 'warning': return '#856404';
      case 'error':   return '#721c24';
      default:        return '#383d41';
    }
  }

  // ===========================
  // AUDIT LOGS FUNCTIONS
  // ===========================
  function loadAuditLogs() {
    const container = document.getElementById('audit-logs-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading audit logs...</p>';

    fetch(window.location.pathname + '?fetch=audit_logs')
      .then(response => response.json())
      .then(logs => {
        if (!logs.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No audit logs found.</p>';
          return;
        }

        container.innerHTML = logs.map(log => `
          <div style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
            <strong>${log.action}</strong>
            <div style="font-size: 13px; color: #333;">${log.details}</div>
            <div style="font-size: 12px; color: #999;">
              By: ${log.first_name || ''} ${log.last_name || ''} • ${log.created_at ? new Date(log.created_at).toLocaleString() : ''}
            </div>
          </div>
        `).join('');
      })
      .catch(error => {
        container.innerHTML = '<p style="text-align: center; color: #dc3545;">Failed to load audit logs.</p>';
        console.error('Error loading audit logs:', error);
      });
  }

  // ===========================
  // ACCOUNT MANAGEMENT
  // ===========================
  function showAccountType(type) {
    document.querySelectorAll('.account-section').forEach(section => section.classList.remove('active'));
    document.querySelectorAll('.account-type-nav a').forEach(tab => tab.classList.remove('active'));
    document.getElementById(type + '-accounts').classList.add('active');
    document.getElementById(type + '-tab').classList.add('active');
  }

  // Photo and location functions
  function previewPhoto(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="photo-preview">`;
      };
      reader.readAsDataURL(file);
    } else {
      preview.innerHTML = 'No Photo';
      preview.className = 'photo-placeholder';
    }
  }

  function getCurrentLocation() {
    const statusDiv = document.getElementById('location-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          document.getElementById('latitude').value = position.coords.latitude;
          document.getElementById('longitude').value = position.coords.longitude;
          statusDiv.className = 'location-status location-success';
          statusDiv.innerHTML = 'Location captured successfully!';
          setTimeout(() => statusDiv.style.display = 'none', 3000);
        },
        error => {
          statusDiv.className = 'location-status location-error';
          statusDiv.innerHTML = 'Error: ' + error.message;
        }
      );
    } else {
      statusDiv.className = 'location-status location-error';
      statusDiv.innerHTML = 'Geolocation is not supported by this browser.';
    }
  }

  function clearLocation() {
    document.getElementById('latitude').value = '';
    document.getElementById('longitude').value = '';
    document.getElementById('location-status').style.display = 'none';
  }

  function getCurrentLocationAgent() {
    const statusDiv = document.getElementById('agent-location-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          document.getElementById('agent_latitude').value = position.coords.latitude;
          document.getElementById('agent_longitude').value = position.coords.longitude;
          statusDiv.className = 'location-status location-success';
          statusDiv.innerHTML = 'Location captured successfully!';
          setTimeout(() => statusDiv.style.display = 'none', 3000);
        },
        error => {
          statusDiv.className = 'location-status location-error';
          statusDiv.innerHTML = 'Error: ' + error.message;
        }
      );
    } else {
      statusDiv.className = 'location-status location-error';
      statusDiv.innerHTML = 'Geolocation is not supported by this browser.';
    }
  }

  function clearLocationAgent() {
    document.getElementById('agent_latitude').value = '';
    document.getElementById('agent_longitude').value = '';
    document.getElementById('agent-location-status').style.display = 'none';
  }

  // ===========================
  // VIEW CLIENT MODAL
  // ===========================
  function viewProfile(id, type, evt) {
    if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
    const modal   = document.getElementById('viewClientModal');
    const content = document.getElementById('viewClientContent');
    if (!modal || !content) return;

    // show modal immediately with loading state
    modal.style.display = 'flex';
    content.innerHTML = '<div style="color:#666;">Loading client details…</div>';

    // Guest client (no account): read details from the table row and show
    if (type === 'user' && (!id || id === 0)) {
      try {
        const btn       = evt?.target;
        const row       = btn?.closest('tr');
        const name      = row?.querySelector('td strong')?.innerText?.trim() || 'N/A';
        const contact   = row?.querySelector('td:nth-child(2)')?.innerText?.trim() || 'N/A';
        const location  = row?.querySelector('td:nth-child(3)')?.innerText?.trim() || 'N/A';
        const lot       = row?.querySelector('td:nth-child(4)')?.innerText?.trim() || 'N/A';
        const prefDate  = row?.querySelector('td:nth-child(5)')?.innerText?.trim() || 'N/A';

        content.innerHTML = `
          <strong>Name:</strong> ${name}<br>
          <strong>Contact:</strong> ${contact}<br>
          <strong>Location:</strong> ${location}<br>
          <strong>Lot Details:</strong> ${lot}<br>
          <strong>Preferred Date:</strong> ${prefDate}<br>
          <div style="color:#dc3545;margin-top:8px;">No user account for this client.</div>
        `;
      } catch (e) {
        content.innerHTML = `<div style="color:#dc3545;">Couldn’t read row data.</div>`;
      }
      return;
    }

    // Registered user: fetch full profile via your PHP endpoint
    if (type === 'user' && id) {
      fetch(window.location.pathname + '?fetch=user&id=' + encodeURIComponent(id))
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(user => {
          if (user && user.id) {
            const created = user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A';
            content.innerHTML = `
              <strong>Name:</strong> ${user.first_name} ${user.middle_name ? user.middle_name + ' ' : ''}${user.last_name}<br>
              <strong>Email:</strong> ${user.email || 'N/A'}<br>
              <strong>Mobile:</strong> ${user.mobile_number || 'N/A'}<br>
              <strong>Address:</strong> ${user.address || 'N/A'}<br>
              <strong>Registered:</strong> ${created}
            `;
          } else {
            content.innerHTML = `<div style="color:#dc3545;">Client not found.</div>`;
          }
        })
        .catch(err => {
          content.innerHTML = `<div style="color:#dc3545;">Error loading client: ${err.message}</div>`;
        });
    }
  }

  function closeViewClientModal() {
    const modal = document.getElementById('viewClientModal');
    if (modal) modal.style.display = 'none';
  }

  // close on backdrop click + Esc
  (function attachModalCloseHandlers(){
    const modal = document.getElementById('viewClientModal');
    if (!modal) return;
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeViewClientModal();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeViewClientModal();
    });
  })();

  // Make global
  window.viewProfile = viewProfile;
  window.closeViewClientModal = closeViewClientModal;

  // ===========================
  // LOGOUT CONFIRMATION
  // ===========================
  function confirmLogout() {
    const modal = document.createElement('div');
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
    
    function closeModal() {
      if (document.body.contains(modal)) document.body.removeChild(modal);
    }
    
    document.getElementById('cancel-logout').addEventListener('click', closeModal);
    document.getElementById('confirm-logout').addEventListener('click', () => window.location.href = 'logout.php');
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  }

  // ===========================
  // ANALYTICS
  // ===========================
  function loadAnalyticsData() {
    loadAnalyticsKPIs();
    loadTopAgents(1);
    loadMonthlySalesChart();
  }

  function loadAnalyticsKPIs() {
    document.getElementById('kpi-total-sales').textContent =
      '₱' + (<?php echo isset($dashboard_stats["total_sales"]) ? $dashboard_stats["total_sales"] : 0; ?>).toLocaleString();
    document.getElementById('kpi-total-lots').textContent = '<?php echo $dashboard_stats["lots"]; ?>';
    document.getElementById('kpi-available-agents').textContent = '<?php echo $dashboard_stats["agents"]; ?>';
    document.getElementById('kpi-pending-documents').textContent = '<?php echo $dashboard_stats["pending_documents"]; ?>';
  }

  function loadTopAgents(page = 1) {
    document.getElementById('top-agents-loading').style.display = 'block';
    document.getElementById('top-agents-content').style.display = 'none';

    // Get filter values
    const dateFrom = document.getElementById('analytics_date_from').value;
    const dateTo = document.getElementById('analytics_date_to').value;
    const locationId = document.getElementById('analytics_location').value;

    const params = new URLSearchParams();
    params.append('fetch', 'top_agents');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(agents => {
        document.getElementById('top-agents-loading').style.display = 'none';
        document.getElementById('top-agents-content').style.display = 'block';

        const tbody = document.getElementById('top-agents-tbody');
        if (!agents.length) {
          tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#666;">No agent sales data found.</td></tr>`;
          return;
        }
        tbody.innerHTML = agents.map((agent, idx) => `
          <tr style="border-bottom: 1px solid #f0f0f0;">
            <td style="padding: 15px;">
              <div style="width: 30px; height: 30px; background: #2d482d; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                ${idx + 1}
              </div>
            </td>
            <td style="padding: 15px;">
              <div style="font-weight: 500;">${agent.name}</div>
              <div style="font-size: 12px; color: #666;">${agent.email}</div>
            </td>
            <td style="padding: 15px;">
              <span style="background: #e8f5e8; color: #2d482d; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                ${agent.sales_count}
              </span>
            </td>
            <td style="padding: 15px; font-weight: 500;">₱${agent.total_amount.toLocaleString()}</td>
            <td style="padding: 15px;">₱${agent.avg_deal_size.toLocaleString()}</td>
          </tr>
        `).join('');
      })
      .catch(err => {
        document.getElementById('top-agents-loading').style.display = 'none';
        document.getElementById('top-agents-content').style.display = 'block';
        const tbody = document.getElementById('top-agents-tbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#dc3545;">Failed to load agent data.</td></tr>`;
        console.error(err);
      });
  }

  const monthlySalesData = <?php echo json_encode($monthly_sales); ?>; 

  function loadMonthlySalesChart() {
    const canvas = document.getElementById('monthly-sales-chart');
    const ctx = canvas.getContext('2d');

    // Use real data from PHP
    const data = monthlySalesData.length ? monthlySalesData : [
      { month: 'Jan 2024', amount: 150000 },
      { month: 'Feb 2024', amount: 200000 },
      { month: 'Mar 2024', amount: 180000 },
      { month: 'Apr 2024', amount: 250000 },
      { month: 'May 2024', amount: 300000 },
      { month: 'Jun 2024', amount: 280000 }
    ];

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const padding = 40;
    const width = canvas.width - padding * 2;
    const height = canvas.height - padding * 2;

    // Draw axes
    ctx.strokeStyle = '#ddd';
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, canvas.height - padding);
    ctx.lineTo(canvas.width - padding, canvas.height - padding);
    ctx.stroke();

    // Find max amount for scaling
    const maxAmount = Math.max(...data.map(item => item.amount), 1);

    // Draw bars
    const barWidth = width / data.length / 2;
    data.forEach((item, index) => {
      const x = padding + index * 2 * barWidth;
      const barHeight = (item.amount / maxAmount) * (height - 20);
      const y = canvas.height - padding - barHeight;
      const color = index % 2 === 0 ? '#28a745' : '#007bff';

      ctx.fillStyle = color;
      ctx.fillRect(x, y, barWidth, barHeight);

      // Draw labels
      ctx.fillStyle = '#333';
      ctx.font = 'bold 12px Arial';
      ctx.fillText(item.month, x, canvas.height - padding + 15);
      ctx.fillText(item.amount.toLocaleString(), x, y - 5);
    });
  }

  function applyAnalyticsFilters() {
    const dateFrom = document.getElementById('analytics_date_from').value;
    const dateTo = document.getElementById('analytics_date_to').value;
    const locationId = document.getElementById('analytics_location').value;

    const params = new URLSearchParams();
    params.append('fetch', 'analytics');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(data => {
        // Update KPIs
        document.getElementById('kpi-total-sales').textContent = '₱' + (data.kpis.total_sales || 0).toLocaleString();
        document.getElementById('kpi-total-lots').textContent = data.kpis.total_lots || 0;
        document.getElementById('kpi-available-agents').textContent = data.kpis.available_agents || 0;
        document.getElementById('kpi-pending-documents').textContent = data.kpis.pending_documents || 0;

        // Update monthly sales chart
        updateMonthlySalesChart(data.monthly_sales);

        // Refresh top agents table:
        loadTopAgents(1);
      })
      .catch(err => {
        alert('Failed to load analytics data.');
        console.error(err);
      });
  }

  // Helper to update chart with new data
  function updateMonthlySalesChart(monthlySalesData) {
    const canvas = document.getElementById('monthly-sales-chart');
    const ctx = canvas.getContext('2d');
    const data = monthlySalesData.length ? monthlySalesData : [];

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const padding = 40;
    const width = canvas.width - padding * 2;
    const height = canvas.height - padding * 2;

    ctx.strokeStyle = '#ddd';
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, canvas.height - padding);
    ctx.lineTo(canvas.width - padding, canvas.height - padding);
    ctx.stroke();

    const maxAmount = Math.max(...data.map(item => item.amount), 1);
    const barWidth = data.length ? width / data.length / 2 : 0;

    data.forEach((item, index) => {
      const x = padding + index * 2 * barWidth;
      const barHeight = (item.amount / maxAmount) * (height - 20);
      const y = canvas.height - padding - barHeight;
      const color = index % 2 === 0 ? '#28a745' : '#007bff';

      ctx.fillStyle = color;
      ctx.fillRect(x, y, barWidth, barHeight);

      ctx.fillStyle = '#333';
      ctx.font = 'bold 12px Arial';
      ctx.fillText(item.month, x, canvas.height - padding + 15);
      ctx.fillText(item.amount.toLocaleString(), x, y - 5);
    });
  }

  // ===========================
  // MISC HELPERS
  // ===========================
  function resetForm(formId) {
    const form = document.getElementById(formId);
    if (form) {
      form.reset(); // Reset all form fields
      const previews = form.querySelectorAll('.photo-placeholder, .photo-preview');
      previews.forEach(preview => {
        preview.innerHTML = 'No Photo'; // Reset photo previews
        preview.className = 'photo-placeholder';
      });
    }
  }

  // ===========================
  // EDIT ACCOUNT MODAL
  // ===========================
 function editAccount(id, type = 'admin') {
  const modal    = document.getElementById('editAccountModal');
  const fieldsDiv = document.getElementById('editAccountFields');
  const photoDiv  = document.getElementById('editAccountPhotoSection');

  // Store id + type in hidden inputs
  document.getElementById('edit_account_id').value   = id;
  document.getElementById('edit_account_type').value = type;

  // Show modal + initial loading state
  modal.style.display = 'flex';
  fieldsDiv.innerHTML = '<div style="color:#666;">Loading account details…</div>';
  photoDiv.innerHTML  = '';

  // Fetch account data (admin / agent / user)
  const url = `${window.location.pathname}?fetch=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;

  fetch(url)
    .then(r => r.text())          // get raw text first
    .then(text => {
      let account;

      // Try to parse JSON
      try {
        account = JSON.parse(text);
      } catch (e) {
        // If PHP returned HTML or an error, we see it here
        console.error('Raw response from server:', text);
        fieldsDiv.innerHTML =
          '<div style="color:#dc3545;">Error loading account: Unexpected non-JSON response from server.</div>';
        return;
      }

      // Safety check
      if (!account || !account.id) {
        fieldsDiv.innerHTML = '<div style="color:#dc3545;">Account not found.</div>';
        return;
      }

      // --------------------
      // Photo preview
      // --------------------
      const photoHtml = `
        <div class="form-group">
          <label>Profile Photo</label>
          <div style="text-align:center;">
            <div id="edit-photo-preview" class="photo-placeholder" style="margin:0 auto 10px;">
              ${
                account.photo_path
                  ? `<img src="${account.photo_path}" alt="Profile" class="photo-preview">`
                  : 'No Photo'
              }
            </div>
            <input type="file" name="photo" accept="image/*"
                   onchange="previewPhoto(this, 'edit-photo-preview')">
          </div>
        </div>
      `;
      photoDiv.innerHTML = photoHtml;

      // --------------------
      // Dynamic form fields
      // --------------------
      let html = `
        <div class="form-row-three">
          <div class="form-group">
            <label>First Name</label>
            <input type="text" name="first_name" value="${account.first_name || ''}" required>
          </div>
          <div class="form-group">
            <label>Middle Name</label>
            <input type="text" name="middle_name" value="${account.middle_name || ''}" placeholder="Middle Name">
          </div>
          <div class="form-group">
            <label>Last Name</label>
            <input type="text" name="last_name" value="${account.last_name || ''}" required>
          </div>
        </div>
      `;

      // Username (agent + user only)
      if (type === 'agent' || type === 'user') {
        html += `
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="${account.username || ''}" required>
          </div>
        `;
      }

      // Email
      html += `
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" value="${account.email || ''}" required>
        </div>
      `;

      // Role (admin + agent)
      if (type === 'admin' || type === 'agent') {
        html += `
          <div class="form-group">
            <label>Role</label>
            <input type="text" name="role" value="${account.role || ''}">
          </div>
        `;
      }

      // Phone (agent)
      if (type === 'agent') {
        html += `
          <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="${account.phone || ''}">
          </div>
        `;
      }

      // Mobile number (user)
      if (type === 'user') {
        html += `
          <div class="form-group">
            <label>Mobile Number</label>
            <input type="text" name="mobile_number" value="${account.mobile_number || ''}">
          </div>
        `;
      }

      // Address + change password
      html += `
        <div class="form-group">
          <label>Address</label>
          <input type="text" name="address" value="${account.address || ''}">
        </div>
        <div class="form-group">
          <label>Change Password (leave blank to keep current)</label>
          <input type="password" name="password" placeholder="New Password">
        </div>
      `;

      fieldsDiv.innerHTML = html;

      // Save original values for "no changes" check
      setTimeout(() => {
        const form = document.getElementById('editAccountForm');
        form.dataset.original = JSON.stringify(
          Object.fromEntries(new FormData(form))
        );
      }, 0);
    })
    .catch(err => {
      fieldsDiv.innerHTML =
        `<div style="color:#dc3545;">Error loading account: ${err.message}</div>`;
    });
}

  function closeEditAccountModal() {
    document.getElementById('editAccountModal').style.display = 'none';
  }

 // ===========================
// EDIT ACCOUNT FORM SUBMIT
// ===========================
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('editAccountForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(form);

    // account_type is "admin" | "agent" | "user"
    const type = formData.get('account_type');

    // Tell PHP which handler to use
    if (type === 'admin') {
      formData.append('account_action', 'update');
    } else if (type === 'agent') {
      formData.append('agent_action', 'update');
    } else if (type === 'user') {
      formData.append('user_action', 'update');
    }

    // Send to same page
    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
      .then(r => r.json())
      .then(res => {
        console.log('Update response:', res);
        if (res.success) {
          alert(res.message || 'Account updated!');
          closeEditAccountModal();
          location.reload();
        } else {
          alert(
            'Failed to update account: ' +
            (res.error || res.message || 'Unknown error')
          );
        }
      })
      .catch(err => {
        console.error('Update fetch error:', err);
        alert('Failed to update account: ' + err.message);
      });
  });
});



  // Make global
  window.editAccount = editAccount;
  window.closeEditAccountModal = closeEditAccountModal;

  // ===========================
  // EDIT LOT MODAL
  // ===========================
  function openEditLotModal(lot) {
    const modal = document.getElementById('editLotModal');
    const fieldsDiv = document.getElementById('editLotFields');
    document.getElementById('edit_lot_id').value = lot.id;

    fieldsDiv.innerHTML = `
      <div class="form-group">
        <label>Block Number</label>
        <input type="text" name="block_number" value="${lot.block_number || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Number</label>
        <input type="text" name="lot_number" value="${lot.lot_number || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Size</label>
        <input type="text" name="lot_size" value="${lot.lot_size || ''}" required>
      </div>
      <div class="form-group">
        <label>Lot Price</label>
        <input type="text" name="lot_price" value="${lot.lot_price || ''}" required>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" required>
          <option value="Available" ${lot.status === 'Available' ? 'selected' : ''}>Available</option>
          <option value="Sold" ${lot.status === 'Sold' ? 'selected' : ''}>Sold</option>
          <option value="Reserved" ${lot.status === 'Reserved' ? 'selected' : ''}>Reserved</option>
        </select>
      </div>
      <div class="form-group">
        <label>Location ID</label>
        <input type="text" name="location_id" value="${lot.location_id || ''}" required>
      </div>
    `;
    modal.style.display = 'flex';
  }

  function closeEditLotModal() {
    document.getElementById('editLotModal').style.display = 'none';
  }

  // Make global
  window.openEditLotModal = openEditLotModal;
  window.closeEditLotModal = closeEditLotModal;

  document.addEventListener('DOMContentLoaded', function() {
    const editLotForm = document.getElementById('editLotForm');
    if (editLotForm) {
      editLotForm.onsubmit = function(e) {
        e.preventDefault();
        const formData = new FormData(editLotForm);
        formData.append('action', 'save');
        fetch(window.location.pathname, { method: 'POST', body: formData })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              alert('Lot updated!');
              closeEditLotModal();
              loadLots(document.getElementById('location_id').value);
            } else {
              alert('Failed to update lot: ' + (res.error || 'Unknown error'));
            }
          })
          .catch(() => alert('Failed to update lot.'));
      };
    }
  });

  // ===========================
  // BULK LOT DELETE + EXPORT
  // ===========================
  function bulkDeleteLots() {
    const checkboxes = document.querySelectorAll('.lot-checkbox:checked');
    if (checkboxes.length === 0) {
      alert('Please select at least one lot to delete.');
      return;
    }
    if (!confirm('Are you sure you want to delete the selected lots?')) return;

    const ids = Array.from(checkboxes).map(cb => cb.value);
    const formData = new FormData();
    formData.append('action', 'bulk_delete');
    formData.append('lot_ids', JSON.stringify(ids));

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          alert('Selected lots deleted!');
          loadLots(document.getElementById('location_id').value);
        } else {
          alert('Failed to delete lots: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to delete lots.'));
  }
  window.bulkDeleteLots = bulkDeleteLots;

  function exportAnalytics() {
    const dateFrom = document.getElementById('analytics_date_from').value;
    const dateTo = document.getElementById('analytics_date_to').value;
    const locationId = document.getElementById('analytics_location').value;

    const params = new URLSearchParams();
    params.append('export', 'analytics');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);

    window.location.href = window.location.pathname + '?' + params.toString();
  }


// ===========================
  document.getElementById('admin-account-form').addEventListener('submit', function(e) {
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    const error = document.getElementById('password-error');

    if (pass !== confirm) {
        e.preventDefault();
        error.style.display = 'block';
        return false;
    } else {
        error.style.display = 'none';
    }
});

// Live check while typing
document.getElementById('confirm_password').addEventListener('input', function() {
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    const error = document.getElementById('password-error');

    if (confirm !== pass) {
        error.style.display = 'block';
    } else {
        error.style.display = 'none';
    }
});


// Agent password confirm validation
document.getElementById('agent-account-form').addEventListener('submit', function(e) {
  const pass   = document.getElementById('agent_password').value;
  const confirm = document.getElementById('agent_confirm_password').value;
  const error  = document.getElementById('agent-password-error');

  if (pass !== confirm) {
    e.preventDefault();
    error.style.display = 'block';
    return false;
  } else {
    error.style.display = 'none';
  }
});

document.getElementById('agent_confirm_password').addEventListener('input', function() {
  const pass   = document.getElementById('agent_password').value;
  const confirm = document.getElementById('agent_confirm_password').value;
  const error  = document.getElementById('agent-password-error');

  error.style.display = (pass && confirm && pass !== confirm) ? 'block' : 'none';
});

</script>


</body>
</html>