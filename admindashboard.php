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

// =====================================================
// SINGLE LOT FETCH FOR EDIT MODAL (JSON, GET)
// =====================================================
if (isset($_GET['fetch']) && $_GET['fetch'] === 'single_lot' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $sql = "SELECT l.*, ll.location_name 
        FROM lots l 
        LEFT JOIN lot_locations ll ON l.location_id = ll.id 
        WHERE l.id = ? LIMIT 1";
    if (!$conn) {
        echo json_encode(['error' => 'DB connection error']);
        exit;
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }
    if (!$stmt->bind_param("i", $id)) {
        echo json_encode(['error' => 'Bind param failed: ' . $stmt->error]);
        exit;
    }
    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
        exit;
    }
    $result = $stmt->get_result();
    if (!$result) {
        echo json_encode(['error' => 'Get result failed: ' . $stmt->error]);
        $stmt->close();
        exit;
    }
    $lot = $result->fetch_assoc();
    $stmt->close();
    if (!$lot) {
        echo json_encode(['error' => 'Lot not found for id: ' . $id]);
    } else {
        echo json_encode($lot);
    }
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

    $date_from   = $_GET['date_from']  ?? null;
    $date_to     = $_GET['date_to']    ?? null;
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
                'id'            => (int)$row['id'],
                'name'          => $row['first_name'] . ' ' . $row['last_name'],
                'email'         => $row['email'],
                'sales_count'   => (int)$row['sales_count'],
                'total_amount'  => (float)$row['total_amount'],
                'avg_deal_size'=> (float)$row['avg_deal_size'],
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($agents);
    exit;
}

// =============================================
// LOTS & BLUEPRINT MANAGEMENT (AJAX HANDLERS)
// =============================================
// --- GET REQUESTS (Fetching Data) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch'])) {
    if ($_GET['fetch'] === 'locations') {
        header('Content-Type: application/json');
        $result = mysqli_query($conn, "SELECT id, location_name FROM lot_locations ORDER BY location_name ASC");
        $locations = [];
        while ($row = mysqli_fetch_assoc($result)) { $locations[] = $row; }
        echo json_encode($locations);
        exit;
    }

    if ($_GET['fetch'] === 'lots') {
        header('Content-Type: application/json');
        $loc_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $query = "SELECT * FROM lots WHERE location_id = $loc_id ORDER BY block_number, lot_number ASC";
        $result = mysqli_query($conn, $query);
        $lots = [];
        while ($row = mysqli_fetch_assoc($result)) { $lots[] = $row; }
        echo json_encode($lots);
        exit;
    }

    if ($_GET['fetch'] === 'blueprint_data') {
        header('Content-Type: application/json');
        $loc_id = intval($_GET['location_id']);
        $bp_query = mysqli_query($conn, "SELECT filename FROM blueprints WHERE location_id = $loc_id LIMIT 1");
        $bp = mysqli_fetch_assoc($bp_query);
        
        $lots_res = mysqli_query($conn, "SELECT * FROM lots WHERE location_id = $loc_id");
        $lots = [];
        while($row = mysqli_fetch_assoc($lots_res)) { $lots[] = $row; }
        
        echo json_encode([
            'image' => $bp ? 'blueprints/' . $bp['filename'] : null, 
            'lots' => $lots
        ]);
        exit;
    }
}

// --- POST REQUESTS (Saving/Deleting Data) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ADD NEW LOCATION PIN
    if ($action === 'add_location') {
        $location_name = mysqli_real_escape_string($conn, $_POST['location_name']);
        $lat = mysqli_real_escape_string($conn, $_POST['latitude']);
        $lng = mysqli_real_escape_string($conn, $_POST['longitude']);

        $sql = "INSERT INTO lot_locations (location_name, latitude, longitude) VALUES ('$location_name', '$lat', '$lng')";
        $res = mysqli_query($conn, $sql);
        echo json_encode(['success' => $res, 'error' => mysqli_error($conn)]);
        exit;
    }

    // 4. SAVE / UPDATE LOT
    if ($action === 'save') {
        $block_number = mysqli_real_escape_string($conn, $_POST['block_number']);
        $lot_number   = mysqli_real_escape_string($conn, $_POST['lot_number']);
        $lot_size     = mysqli_real_escape_string($conn, $_POST['lot_size']);
        $lot_price    = mysqli_real_escape_string($conn, $_POST['lot_price']);
        $location_id  = intval($_POST['location_id']);
        $status       = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Available');

        if (!empty($_POST['lot_id'])) {
            $lot_id = intval($_POST['lot_id']);
            $sql = "UPDATE lots SET block_number='$block_number', lot_number='$lot_number', lot_size='$lot_size', lot_price='$lot_price', location_id='$location_id', status='$status' WHERE id=$lot_id";
        } else {
            $sql = "INSERT INTO lots (block_number, lot_number, lot_size, lot_price, location_id, status) VALUES ('$block_number', '$lot_number', '$lot_size', '$lot_price', '$location_id', '$status')";
        }
        $res = mysqli_query($conn, $sql);
        echo json_encode(['success' => $res, 'error' => mysqli_error($conn)]);
        exit;
    }

    // 5. SAVE MAP COORDINATES & STATUS COLOR
    if ($action === 'save_map') {
        $lot_id = intval($_POST['lot_id']);
        $coords = $_POST['coords']; // JSON string
        $status = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : null;

        if ($status) {
            $stmt = $conn->prepare("UPDATE lots SET coordinates = ?, status = ? WHERE id = ?");
            $stmt->bind_param("ssi", $coords, $status, $lot_id);
        } else {
            $stmt = $conn->prepare("UPDATE lots SET coordinates = ? WHERE id = ?");
            $stmt->bind_param("si", $coords, $lot_id);
        }

        if ($stmt->execute()) {
          echo json_encode(['success' => true]);
        } else {
          echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt->close();
        exit;
    }

    // 6. DELETE SINGLE
    if ($action === 'delete') {
        $lot_id = intval($_POST['lot_id']);
        $ok = mysqli_query($conn, "DELETE FROM lots WHERE id = $lot_id");
        echo json_encode(['success' => $ok]);
        exit;
    }

    // 7. BULK DELETE
    if ($action === 'bulk_delete') {
        $ids = json_decode($_POST['lot_ids'], true);
        if (!$ids) { echo json_encode(['success' => false, 'error' => 'No IDs provided']); exit; }
        $idList = implode(',', array_map('intval', $ids));
        $ok = mysqli_query($conn, "DELETE FROM lots WHERE id IN ($idList)");
        echo json_encode(['success' => $ok]);
        exit;
    }
}

// =====================================================
// SINGLE ACCOUNT FETCH FOR EDIT MODAL (JSON, GET)
// =====================================================
if (isset($_GET['fetch'], $_GET['id']) && in_array($_GET['fetch'], ['admin', 'agent', 'user'], true)) {
    header('Content-Type: application/json');

    $id   = (int) $_GET['id'];
    $type = $_GET['fetch'];

    if ($type === 'admin') {
      $sql = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, created_at, photo_path FROM admin_accounts WHERE id = ?";
    } elseif ($type === 'agent') {
      $sql = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, created_at, photo_path FROM agent_accounts WHERE id = ?";
    } else { // user
      $sql = "SELECT id, first_name, middle_name, last_name, email, mobile_number, address, created_at, photo_path FROM user_accounts WHERE id = ?";
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
    exit; 
}

// =====================================================
// ADMIN ACCOUNT CRUD  (AJAX: account_action)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_action'])) {
    header('Content-Type: application/json');

    // ---------- ADD ADMIN ----------
    if ($_POST['account_action'] === 'add') {
        $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']         ?? '');
        $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name']        ?? '');
        $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']          ?? '');
        $email             = mysqli_real_escape_string($conn, $_POST['email']              ?? '');
        $username          = mysqli_real_escape_string($conn, $_POST['username']           ?? '');
        $phone             = mysqli_real_escape_string($conn, $_POST['phone']              ?? '');
        $address           = mysqli_real_escape_string($conn, $_POST['address']            ?? '');
        $availability      = isset($_POST['availability']) ? 1 : 0;

        $raw_password = $_POST['password'] ?? '';
        if ($raw_password === '') {
            echo json_encode(['success' => false, 'error' => 'Password is required.']);
            exit;
        }
        $password = password_hash($raw_password, PASSWORD_DEFAULT);

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        $sql = "INSERT INTO admin_accounts 
                (first_name, middle_name, last_name, email, username, password,
                phone, address, photo_path, availability)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit;
        }

        $stmt->bind_param(
            "sssssssssi",
            $first_name, $middle_name, $last_name, $email, $username, $password,
            $phone, $address, $photo_path, $availability
        );

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Admin account created successfully!' : 'Error creating admin account: ' . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ---------- UPDATE ADMIN ----------
    if ($_POST['account_action'] === 'update') {
        $account_id       = intval($_POST['account_id'] ?? 0);
        $first_name       = mysqli_real_escape_string($conn, $_POST['first_name']         ?? '');
        $middle_name      = mysqli_real_escape_string($conn, $_POST['middle_name']        ?? '');
        $last_name        = mysqli_real_escape_string($conn, $_POST['last_name']          ?? '');
        $email            = mysqli_real_escape_string($conn, $_POST['email']              ?? '');
        $username         = mysqli_real_escape_string($conn, $_POST['username']           ?? '');
        $phone            = mysqli_real_escape_string($conn, $_POST['phone']              ?? '');
        $address          = mysqli_real_escape_string($conn, $_POST['address']            ?? '');
        $availability     = isset($_POST['availability']) ? 1 : 0;

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE admin_accounts 
                    SET first_name=?, middle_name=?, last_name=?, email=?, username=?, 
                        password=?, phone=?, address=?, photo_path=?, availability=?
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param("sssssssssii", $first_name, $middle_name, $last_name, $email, $username, $password, $phone, $address, $photo_path, $availability, $account_id);
        } else {
            $sql = "UPDATE admin_accounts 
                    SET first_name=?, middle_name=?, last_name=?, email=?, username=?,
                        phone=?, address=?, photo_path=?, availability=?
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param("sssssssii", $first_name, $middle_name, $last_name, $email, $username, $phone, $address, $photo_path, $availability, $account_id);
        }

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Account updated successfully!' : 'Error updating account: ' . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ---------- DELETE ADMIN ----------
    if ($_POST['account_action'] === 'delete') {
        $account_id = intval($_POST['account_id'] ?? 0);
        $sql  = "DELETE FROM admin_accounts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param("i", $account_id);
        $ok = $stmt->execute();

        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Account deleted successfully!' : 'Error deleting account: ' . $stmt->error
        ]);
        $stmt->close();
        exit;
    }
}

// =====================================================
// AGENT ACCOUNT CRUD (agent_action)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agent_action'])) {

    $action = $_POST['agent_action'];

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
        $availability      = isset($_POST['availability']) ? 1 : 0;
        $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        $sql = "INSERT INTO agent_accounts 
            (first_name, middle_name, last_name, username, email, phone, address, 
            photo_path, availability, password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("ssssssssis", $first_name, $middle_name, $last_name, $username, $email, $phone, $address, $photo_path, $availability, $password);
        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Agent account created successfully!" : "Error creating agent account: " . $stmt->error
        ]);
        $stmt->close();
        exit;
    }

    // ----------------- UPDATE AGENT (AJAX) -----------------
    if ($action === 'update') {
        $agent_id          = intval($_POST['account_id']);
        $first_name        = mysqli_real_escape_string($conn, $_POST['first_name']);
        $middle_name       = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');
        $last_name         = mysqli_real_escape_string($conn, $_POST['last_name']);
        $username          = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $email             = mysqli_real_escape_string($conn, $_POST['email']);
        $phone             = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $address           = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $availability      = isset($_POST['availability']) ? 1 : 0;

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handleFileUpload($_FILES['photo']);
        }

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql = "UPDATE agent_accounts 
                SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                  address=?, availability=?, password=?, photo_path=? 
                WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                exit;
            }
            $stmt->bind_param("ssssssssisi", $first_name, $middle_name, $last_name, $username, $email, $phone, $address, $availability, $password, $photo_path, $agent_id);
        } else {
            $sql = "UPDATE agent_accounts 
                SET first_name=?, middle_name=?, last_name=?, username=?, email=?, phone=?, 
                  address=?, availability=?, photo_path=? 
                WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
                exit;
            }
            $stmt->bind_param("sssssssisi", $first_name, $middle_name, $last_name, $username, $email, $phone, $address, $availability, $photo_path, $agent_id);
        }

        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "Agent account updated successfully!" : "Error updating agent account: " . $stmt->error
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
            $_SESSION['success_message'] = "Agent account deleted successfully!";
            header('Location: admindashboard.php#admin-accounts');
            exit;
          } else {
            $error_message = "Error deleting agent account: " . $conn->error;
          }
          $stmt->close();
        }
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
        $mobile_number = mysqli_real_escape_string($conn, $_POST['phone_number']);
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

        $stmt->bind_param("sssssssss", $first_name, $middle_name, $username, $last_name, $email, $mobile_number, $address, $password, $photo_path);
        $ok = $stmt->execute();
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "User account created successfully!" : "Error creating user account: " . $stmt->error
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
            'first_name=?', 'middle_name=?', 'username=?', 'last_name=?', 'email=?', 'mobile_number=?', 'address=?'
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
            'message' => $ok ? "User account updated successfully!" : "Error updating user account: " . $stmt->error
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
            'message' => $ok ? "User account deleted successfully!" : "Error deleting user account: " . $conn->error
        ]);
        $stmt->close();
        exit;
    }
}

// =============================================
// VIEWINGS: REQUEST & ASSIGN
// =============================================
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

// Viewing list
$all_viewings  = [];
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
        ON (v.lot_id IS NOT NULL AND l.id = v.lot_id) 
        OR (v.lot_id IS NULL AND l.lot_number = v.lot_no)
    LEFT JOIN lot_locations ll 
        ON ll.id = l.location_id
    ORDER BY v.created_at DESC
    LIMIT 10
";
$viewingsResult = mysqli_query($conn, $viewingsQuery);
if ($viewingsResult) {
    while ($viewing = mysqli_fetch_assoc($viewingsResult)) {
        $all_viewings[] = $viewing;
    }
}

// Active agents
$agents      = [];
$agentsQuery = "SELECT id, first_name, last_name FROM agent_accounts WHERE status = 'active'";
$agentsResult = mysqli_query($conn, $agentsQuery);
if ($agentsResult) {
    while ($agent = mysqli_fetch_assoc($agentsResult)) {
        $agents[] = $agent;
    }
}

// =============================================
// FETCH ACCOUNTS FOR ADMIN UI
// =============================================
$adminAccounts   = [];
$accountsQuery   = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, photo_path, availability, created_at FROM admin_accounts ORDER BY created_at DESC";
$accountsResult  = mysqli_query($conn, $accountsQuery);
if ($accountsResult) {
  while ($account = mysqli_fetch_assoc($accountsResult)) {
    $adminAccounts[] = $account;
  }
}

$agentAccounts  = [];
$agentQuery     = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, availability, status, created_at FROM agent_accounts ORDER BY created_at DESC";
$agentResult = mysqli_query($conn, $agentQuery);
if ($agentResult) {
  while ($agent = mysqli_fetch_assoc($agentResult)) {
      $agentAccounts[] = $agent;
  }
}

$userAccounts = [];
$userQuery    = "SELECT id, first_name, middle_name, last_name, email, mobile_number, address, created_at FROM user_accounts ORDER BY created_at DESC LIMIT 5";
$userResult   = mysqli_query($conn, $userQuery);
if ($userResult) {
    while ($user = mysqli_fetch_assoc($userResult)) {
        $userAccounts[] = $user;
    }
}

// Handle file uploads helper
function handleFileUpload($file, $uploadDir = 'uploads/profiles/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) return null;
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('profile_') . '.' . $fileExtension;
    $uploadPath = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) return $uploadPath;
    return null;
}

// Pending documents stats
$pendingDocumentsQuery = "SELECT COUNT(*) as total FROM documents WHERE status = 'pending'";
$pendingDocumentsResult = mysqli_query($conn, $pendingDocumentsQuery);
$dashboard_stats['pending_documents'] = $pendingDocumentsResult ? mysqli_fetch_assoc($pendingDocumentsResult)['total'] : 0;

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

    $salesQuery = "SELECT SUM(amount) as total FROM sales WHERE 1";
    $lotsQuery = "SELECT COUNT(*) as total FROM lots WHERE 1";
    $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";
    $pendingDocumentsQuery = "SELECT COUNT(*) as total FROM documents WHERE status = 'pending'";

    $salesWhere = [];
    if ($date_from) $salesWhere[] = "sale_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
    if ($date_to) $salesWhere[] = "sale_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
    if ($location_id) $salesWhere[] = "location_id = $location_id";
    if ($salesWhere) $salesQuery .= " AND " . implode(' AND ', $salesWhere);

    $monthlySalesQuery = "SELECT DATE_FORMAT(sale_date, '%b %Y') AS month, SUM(amount) AS total FROM sales WHERE 1";
    if ($salesWhere) $monthlySalesQuery .= " AND " . implode(' AND ', $salesWhere);
    $monthlySalesQuery .= " GROUP BY month ORDER BY sale_date ASC";

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

    $monthly_sales = [];
    $monthlySalesResult = mysqli_query($conn, $monthlySalesQuery);
    if ($monthlySalesResult) {
        while ($row = mysqli_fetch_assoc($monthlySalesResult)) {
            $monthly_sales[] = ['month' => $row['month'], 'amount' => (float)$row['total']];
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['kpis' => $kpis, 'monthly_sales' => $monthly_sales]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export']) && $_GET['export'] === 'analytics') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analytics_export.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Metric', 'Value']);
    fclose($output);
    exit;
}

// Helpers
function respondJSON($data) { header("Content-Type: application/json"); echo json_encode($data); exit; }
function logAudit($conn, $admin_id, $action, $details) {
    $stmt = $conn->prepare("INSERT INTO audit_logs (admin_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $admin_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}
function sendNotification($conn, $title, $message, $type = 'info') {
    $stmt = $conn->prepare("INSERT INTO notifications (title, message, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

// GET FETCH HANDLERS
if (isset($_GET['fetch']) && $_GET['fetch'] === 'audit_logs') {
    $res = mysqli_query($conn, "SELECT a.*, ad.first_name, ad.last_name FROM audit_logs a LEFT JOIN admin_accounts ad ON ad.id = a.admin_id ORDER BY a.created_at DESC LIMIT 100");
    $logs = []; while ($row = mysqli_fetch_assoc($res)) { $logs[] = $row; } respondJSON($logs);
}
if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications') {
    $res = mysqli_query($conn, "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 20");
    $data = []; while ($row = mysqli_fetch_assoc($res)) { $data[] = $row; } respondJSON($data);
}
if (isset($_GET['fetch']) && $_GET['fetch'] === 'notifications_count') {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM notifications");
    $row = mysqli_fetch_assoc($res); respondJSON(["count" => intval($row["total"])]);
}
if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents') {
    $res = mysqli_query($conn, "SELECT * FROM documents WHERE status='pending' ORDER BY uploaded_at DESC");
    $docs = []; while ($row = mysqli_fetch_assoc($res)) { $docs[] = $row; } respondJSON($docs);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'all_user_documents') {
    $docs = [];
    $query = "SELECT d.*, u.first_name, u.last_name, u.email FROM user_documents d LEFT JOIN user_accounts u ON d.user_id = u.id WHERE d.status = 'pending' ORDER BY d.uploaded_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $docs[] = $row; }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
}

// POST HANDLERS - Approve/Reject
if (isset($_POST["action"]) && $_POST["action"] === "approve_document") {
    $doc_id = intval($_POST["doc_id"]);
    mysqli_query($conn, "UPDATE documents SET status='approved', reviewed_at=NOW() WHERE id = $doc_id");
    @mysqli_query($conn, "UPDATE user_documents SET status='approved', reviewed_at=NOW() WHERE id = $doc_id");
    logAudit($conn, $admin_id, "Document Approved", "Document ID: $doc_id approved");
    sendNotification($conn, "Document Approved", "Document #$doc_id was approved.", "success");
    respondJSON(["success" => true]);
}
if (isset($_POST["action"]) && $_POST["action"] === "reject_document") {
    $doc_id = intval($_POST["doc_id"]);
    $remarks = mysqli_real_escape_string($conn, $_POST["remarks"]);
    mysqli_query($conn, "UPDATE documents SET status='rejected', remarks='$remarks', reviewed_at=NOW() WHERE id = $doc_id");
    @mysqli_query($conn, "UPDATE user_documents SET status='rejected', remarks='$remarks', reviewed_at=NOW() WHERE id = $doc_id");
    logAudit($conn, $admin_id, "Document Rejected", "Document ID: $doc_id rejected");
    sendNotification($conn, "Document Rejected", "A document was rejected.", "warning");
    respondJSON(["success" => true]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard</title>
  
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
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

    /* Unified UI controls */
    button, input[type="button"], input[type="submit"], .btn {
      font-family: inherit;
      font-size: 14px;
      padding: 8px 14px;
      border-radius: 6px;
      min-width: 88px;
      box-shadow: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }

    input[type="number"], input[type="date"], input[type="datetime-local"],
    select, textarea, input[type="text"], input[type="email"], input[type="password"], input[type="tel"] {
      width: 100%;
      padding: 10px 12px;
      font-size: 14px;
      border: 1px solid #ced4da;
      border-radius: 6px;
      background: #ffffff;
      color: #222222;
      outline: none;
      transition: box-shadow .15s ease, border-color .15s ease;
      box-sizing: border-box;
    }

    input:focus, textarea:focus, select:focus {
      border-color: #7aa97a;
      box-shadow: 0 0 0 3px rgba(42, 139, 73, 0.12);
    }

    .form-group label {
      font-size: 13px;
      margin-bottom: 6px;
      display: block;
      color: #2d482d;
    }

    /* Sidebar */
    .sidebar {
      width: 290px;
      background-color: #14532d;
      border-radius: 0px;
      display: flex;
      flex-direction: column;
      padding: 40px 25px;
      height: 100vh;
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
    }

    .profile-pic {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      object-fit: cover;
      background-color: transparent;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255,255,255,0.08);
      padding: 10px 12px;
      border-radius: 12px;
      width: 220px;
      margin: 0 auto 16px;
      box-shadow: none;
      text-align: left;
    }

    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      background-color: transparent;
      flex-shrink: 0;
    }

    .user-details {
      font-size: 14px;
      color: #ffffff;
      line-height: 1.1;
    }

    .user-details div:first-child {
      font-size: 15px;
      font-weight: 600;
      color: #ffffff;
    }

    .user-details div:last-child {
      font-size: 13px;
      color: rgba(255,255,255,0.85);
    }

    .nav {
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    .nav a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 22px;
      color: rgba(255,255,255,0.9);
      text-decoration: none;
      transition: background 0.18s, color 0.18s, transform 0.12s;
      border-left: 4px solid transparent;
      font-size: 15px;
      margin: 6px 14px;
      border-radius: 8px;
      justify-content: flex-start;
      cursor: pointer;
    }

    .nav a:hover,
    .nav a.active {
      background: rgba(255,255,255,0.06);
      color: #fff;
      transform: translateY(-1px);
    }

    .nav-icon {
      width: 24px;
      height: 24px;
      margin-right: 8px;
      vertical-align: middle;
      filter: brightness(0) invert(1);
    }

    .nav-icon-eye {
      background-color: white;
      mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
      mask-size: contain;
      -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z'/%3E%3C/svg%3E") no-repeat center;
      -webkit-mask-size: contain;
      filter: none;
    }

    .logout-icon {
      display: inline-block;
      transform: scaleX(-1);
      -webkit-transform: scaleX(-1);
    }

    /* Main Container */
    .container {
      flex: 1;
      padding: 40px;
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow-y: auto;
    }

    .header {
      display: flex;
      justify-content: flex-start;
      align-items: center;
      gap: 16px;
      margin-bottom: 32px;
      padding-left: 8px;
    }

    .header h2 {
      color: #2d482d;
      font-size: 30px;
    }

    .header small {
      font-size: 14px;
      color: #555;
    }

    /* Cards */
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

    /* Sections */
    .section {
      display: none;
    }

    .section.active {
      display: block;
      animation: fadeIn 0.36s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Tables */
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

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      font-size: 14px;
      color: #2d482d;
    }

    thead {
      background-color: #3e5f3e;
      color: white;
    }

    th, td {
      padding: 12px 10px;
      text-align: left;
      border-bottom: 1px solid #ddd;
    }

    tbody tr:hover {
      background-color: #f1f1f1;
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

    .btn-danger {
      background-color: #dc3545;
      color: white;
      border: 1px solid #dc3545;
    }

    .btn-danger:hover {
      background-color: #c82333;
      border-color: #c82333;
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

    .form-group {
      margin-bottom: 15px;
    }

    .form-row {
      display: flex;
      gap: 15px;
    }
    .form-row-three {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 20px;
    }

    .alert {
      padding: 12px;
      border-radius: 6px;
      margin-bottom: 20px;
      font-size: 14px;
    }
    .alert.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

    .status-badge {
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: bold;
      color: #fff !important;
      background-color: #888;
    }
    .status-scheduled, .status-available { background-color: #28a745 !important; }
    .status-pending, .status-requested, .status-reserved { background-color: #ffc107 !important; color: #212529 !important; }
    .status-completed { background-color: #17a2b8 !important; }
    .status-cancelled, .status-sold { background-color: #dc3545 !important; }

    /* Account Tabs */
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
    .account-type-nav a:first-child { border-radius: 8px 0 0 0; }
    .account-type-nav a:last-child { border-radius: 0 8px 0 0; }
    .account-type-nav a:hover { background-color: #e8f5e8; color: #2d482d; border-color: #c3e6cb; }
    .account-type-nav a.active { background-color: #2d482d; color: white; border-color: #2d482d; z-index: 1; }

    .account-section {
      display: none;
      background: white;
      border: 1px solid #e0e0e0;
      border-top: none;
      border-radius: 0 0 8px 8px;
      padding: 30px;
    }
    .account-section.active { display: block; }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #666;
      background: #f9f9f9;
      border-radius: 8px;
      margin: 20px 0;
    }

    /* Mapper Tool CSS */
    #draw-layer {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      cursor: crosshair;
      user-select: none;
      z-index: 10;
    }
    #draw-layer.drawing-cursor { cursor: crosshair !important; }
    .start-polygon-btn:hover {
      background: #c8e6c9 !important;
      color: #1b2e1b !important;
      border-color: #1b2e1b !important;
      box-shadow: 0 2px 8px rgba(44, 167, 44, 0.08) !important;
      cursor: pointer !important;
    }
  </style>
</head>

<body onload="loadLocations()">
  <div class="sidebar-wrapper">
    <div class="sidebar">
      <div class="logo-title">
        <img src="assets/a.png" alt="Logo" class="profile-pic">
        <div style="display:flex;flex-direction:column;justify-content:center;line-height:1;">
          <h2 style="font-weight:700;font-size:1.18rem;letter-spacing:1px;line-height:1;color:white;margin:0;">NUEVO PUERTA</h2>
          <span style="font-size:0.95rem;letter-spacing:0.5px;color:white;opacity:0.9;line-height:1;">REAL ESTATE</span>
        </div>
      </div>

      <div class="user-profile">
        <div style="margin-right:12px; flex-shrink:0;">
          <img src="assets/s.png" alt="User Image" />
        </div>
        <div style="line-height:1.1;">
          <div><?php echo htmlspecialchars($admin_name); ?></div>
          <div><?php echo htmlspecialchars($admin_role); ?></div>
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
        <a data-target="section-analytics">
          <svg width="24" height="24" fill="white" viewBox="0 0 24 24" class="nav-icon">
            <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
          </svg>
          <span>Analytics</span>
        </a>
        <a data-target="section-notifications">
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
          <img src="assets/ic_baseline-logout.png" alt="Logout Icon" class="nav-icon logout-icon">
          <span>Logout</span>
        </a>
      </div>
    </div>
  </div>

  <div class="container">
    
    <div id="section-dashboard" class="section active">
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
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
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
                  <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
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
                  <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
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

    <div id="section-accounts" class="section hidden">
      <div class="header">
        <div>
          <h2>Account Management</h2>
          <small>Create, edit, and manage different types of accounts</small>
        </div>
      </div>

      <div class="table-section">
        <?php if (isset($_SESSION['success_message'])): ?>
          <div class="alert success"><?php echo $_SESSION['success_message']; ?></div>
          <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
          <div class="alert error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="account-type-nav">
          <a href="#" onclick="showAccountType('admin')" id="admin-tab" class="active">Admin Accounts</a>
          <a href="#" onclick="showAccountType('agent')" id="agent-tab">Agent Accounts</a>
          <a href="#" onclick="showAccountType('user')" id="user-tab">User Accounts</a>
        </div>

        <div id="admin-accounts" class="account-section active">
            <div class="form-container">
              <div class="form-title">Add New Admin Account</div>
              <form method="POST" enctype="multipart/form-data" id="admin-account-form">
                <input type="hidden" name="account_action" value="add">
                
                <div class="form-section">
                  <div class="form-section-title">Personal Information</div>
                  <div class="form-row-three">
                    <div class="form-group"><label>First Name</label><input type="text" name="first_name" required></div>
                    <div class="form-group"><label>Middle Name (Optional)</label><input type="text" name="middle_name"></div>
                    <div class="form-group"><label>Last Name</label><input type="text" name="last_name" required></div>
                  </div>
                  <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Contact Information</div>
                  <div class="form-row">
                    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone" required></div>
                  </div>
                  <div class="form-group"><label>Address</label><textarea name="address" required></textarea></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Account Security</div>
                  <div class="form-row">
                    <div class="form-group"><label>Password</label><input type="password" id="admin_password" name="password" required></div>
                    <div class="form-group">
                      <label>Confirm Password</label><input type="password" id="admin_confirm_password" name="confirm_password" required>
                      <small id="admin-password-error" style="color:#dc3545;display:none;font-size:13px;">Passwords do not match.</small>
                    </div>
                  </div>
                </div>

                <button type="submit" class="btn-primary">Create Admin Account</button>
              </form>
            </div>

            <div class="accounts-table">
              <h3>Existing Admin Accounts</h3>
              <?php if (empty($adminAccounts)): ?>
                  <div class="empty-state"><p>No admin accounts found in the database.</p></div>
              <?php else: ?>
                  <table style="width:100%; border-collapse:collapse;">
                      <thead>
                          <tr style="background:#14532d; color:#fff;">
                              <th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Address</th><th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($adminAccounts as $account): ?>
                              <tr style="border-bottom:1px solid #eee; background:#fff;">
                                  <td><strong><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></strong></td>
                                  <td><?php echo htmlspecialchars($account['username']); ?></td>
                                  <td><?php echo htmlspecialchars($account['email']); ?></td>
                                  <td><?php echo htmlspecialchars($account['phone']); ?></td>
                                  <td><?php echo htmlspecialchars($account['address']); ?></td>
                                  <td>
                                      <button onclick="editAccount(<?php echo $account['id']; ?>, 'admin')" class="btn-small">Edit</button>
                                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                          <input type="hidden" name="account_action" value="delete">
                                          <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
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

        <div id="agent-accounts" class="account-section">
            <div class="form-container">
              <div class="form-title">Create Agent Account</div>
              <form method="POST" enctype="multipart/form-data" id="agent-account-form">
                <input type="hidden" name="agent_action" value="add">
                
                <div class="form-section">
                  <div class="form-section-title">Personal Information</div>
                  <div class="form-row-three">
                    <div class="form-group"><label>First Name</label><input type="text" name="first_name" required></div>
                    <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name"></div>
                    <div class="form-group"><label>Last Name</label><input type="text" name="last_name" required></div>
                  </div>
                  <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Contact Information</div>
                  <div class="form-row">
                    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone" required></div>
                  </div>
                  <div class="form-group"><label>Address</label><textarea name="address" required></textarea></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Account Security</div>
                  <div class="form-row">
                    <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                    <div class="form-group"><label>Confirm Password</label><input type="password" required></div>
                  </div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Availability Status</div>
                  <div class="availability-toggle">
                    <label class="toggle-switch">
                      <input type="checkbox" name="availability" checked>
                      <span class="slider"></span>
                    </label>
                    <span>Available for client assignments</span>
                  </div>
                </div>

                <button type="submit" class="btn-primary">Create Agent Account</button>
              </form>
            </div>

            <div class="accounts-table">
              <h3>Existing Agent Accounts</h3>
              <?php if (empty($agentAccounts)): ?>
                  <div class="empty-state"><p>No agent accounts found in the database.</p></div>
              <?php else: ?>
                  <table>
                      <thead>
                          <tr>
                              <th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Status</th><th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($agentAccounts as $account): ?>
                              <tr>
                                  <td><strong><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></strong></td>
                                  <td><?php echo htmlspecialchars($account['username']); ?></td>
                                  <td><?php echo htmlspecialchars($account['email']); ?></td>
                                  <td><?php echo htmlspecialchars($account['phone']); ?></td>
                                  <td>
                                      <?php if ($account['status'] === 'active'): ?>
                                          <span class="status-active">Active</span>
                                      <?php else: ?>
                                          <span class="status-inactive">Inactive</span>
                                      <?php endif; ?>
                                  </td>
                                  <td>
                                      <button onclick="editAccount(<?php echo $account['id']; ?>, 'agent')" class="btn-small">Edit</button>
                                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                          <input type="hidden" name="agent_action" value="delete">
                                          <input type="hidden" name="agent_id" value="<?php echo $account['id']; ?>">
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

        <div id="user-accounts" class="account-section">
            <div class="form-container">
              <div class="form-title">Create User Account</div>
              <form method="POST" id="user-account-form">
                <input type="hidden" name="user_action" value="add">
                
                <div class="form-section">
                  <div class="form-section-title">Personal Information</div>
                  <div class="form-row-three">
                    <div class="form-group"><label>First Name</label><input type="text" name="first_name" required></div>
                    <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name"></div>
                    <div class="form-group"><label>Last Name</label><input type="text" name="last_name" required></div>
                  </div>
                  <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Contact Information</div>
                  <div class="form-row">
                    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Phone</label><input type="tel" name="phone_number" required></div>
                  </div>
                  <div class="form-group"><label>Address</label><textarea name="address" required></textarea></div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">Account Security</div>
                  <div class="form-row">
                    <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                    <div class="form-group"><label>Confirm Password</label><input type="password" required></div>
                  </div>
                </div>

                <button type="submit" class="btn-primary">Create User Account</button>
              </form>
            </div>

            <div class="accounts-table">
              <h3>Existing User Accounts</h3>
              <?php if (empty($userAccounts)): ?>
                  <div class="empty-state"><p>No user accounts found in the database.</p></div>
              <?php else: ?>
                  <table>
                      <thead>
                          <tr>
                              <th>Name</th><th>Email</th><th>Mobile</th><th>Created</th><th>Actions</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($userAccounts as $account): ?>
                              <tr>
                                  <td><strong><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></strong></td>
                                  <td><?php echo htmlspecialchars($account['email']); ?></td>
                                  <td><?php echo htmlspecialchars($account['mobile_number']); ?></td>
                                  <td><?php echo date('M d, Y', strtotime($account['created_at'])); ?></td>
                                  <td>
                                      <button onclick="viewProfile(<?php echo $account['id']; ?>, 'user')" class="btn-small">View</button>
                                      <button onclick="editAccount(<?php echo $account['id']; ?>, 'user')" class="btn-small">Edit</button>
                                      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                          <input type="hidden" name="user_action" value="delete">
                                          <input type="hidden" name="user_id" value="<?php echo $account['id']; ?>">
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
          <select id="location_id" name="location_id" style="flex:1; min-width:250px;" onchange="loadLots(this.value)">
            <option value="" disabled selected>Please select a location first</option>
          </select>
          <button onclick="openAddLocationModal()" class="btn-primary" style="padding: 10px 15px; white-space: nowrap;">
             Add New Map Pin
          </button>
        </div>

        <div id="lot-message" style="margin-bottom:15px;display:none;padding:10px 18px;border-radius:6px;font-size:15px;"></div>
        
        <table id="lots-table">
          <thead>
            <tr>
              <th id="select-all-header"></th>
              <th>Block Number</th>
              <th>Lot Number</th>
              <th>Lot Size</th>
              <th>Lot Price</th>
              <th>Status</th>
              <th>Map Position</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="lots-table-body">
            </tbody>
        </table>

        <div style="margin-top: 20px; display: flex; gap: 10px;">
          <button onclick="addNewLot()" class="btn-primary">+ Add New Lot</button>
          <button onclick="bulkDeleteLots()" class="btn-danger btn-primary">Delete Selected</button>
        </div>
      </div>
    </div>

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
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Contact</th>
                <th>Location</th>
                <th>Lot Details</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($all_viewings as $viewing): ?>
                <tr style="border-bottom:1px solid #eee; background:#fff;">
                  <td>
                    <strong><?php echo htmlspecialchars($viewing['client_first_name'] . ' ' . $viewing['client_last_name']); ?></strong>
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
                  </td>
                  <td>
                    <span class="status-badge status-<?php echo strtolower($viewing['status']); ?>">
                      <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $viewing['status']))); ?>
                    </span>
                  </td>
                  <td>
                      <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 6px;">
                        <?php
                          $assignedAgent = null;
                          if (!empty($viewing['agent_id'])) {
                            foreach ($agents as $agent) {
                              if ($agent['id'] == $viewing['agent_id']) {
                                $assignedAgent = $agent;
                                break;
                              }
                            }
                          }
                        ?>
                        <?php if ($assignedAgent): ?>
                          <div style="background:#e6f4ea;padding:8px 12px;border-radius:7px;display:flex;flex-direction:column;align-items:flex-start;min-width:140px;">
                            <span style="font-size:13px;color:#14532d;font-weight:600;">Assigned Agent:</span>
                            <span style="font-size:15px;color:#195c36;font-weight:700;margin-top:2px;"><?php echo htmlspecialchars($assignedAgent['first_name'] . ' ' . $assignedAgent['last_name']); ?></span>
                          </div>
                        <?php else: ?>
                          <form method="POST" style="width: 100%;">
                            <input type="hidden" name="viewing_action" value="assign_agent">
                            <input type="hidden" name="viewing_id" value="<?php echo $viewing['id']; ?>">
                            <select name="agent_id" required style="width: 120px; font-size:12px; padding:4px 8px; border-radius:4px;">
                              <option value="">Select Agent</option>
                              <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <div style="display: flex; gap: 4px; margin-top: 4px;">
                              <button type="submit" class="btn-small" style="padding:4px 10px; font-size:11px; background:#28a745; color:white;">Assign</button>
                            </div>
                          </form>
                        <?php endif; ?>
                      </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div id="section-analytics" class="section hidden">
      <div class="header">
        <div>
          <h2>Analytics Dashboard</h2>
          <small>Track sales performance and agent statistics</small>
        </div>
      </div>

      <div class="table-section">
        <div class="analytics-filters" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #e0e0e0; position: relative;">
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

      <div class="analytics-kpis" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Sales</div>
              <div id="kpi-total-sales" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
            </div>
          </div>
        </div>
        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Total Lots</div>
              <div id="kpi-total-lots" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
            </div>
          </div>
        </div>
        <div class="kpi-card" style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);margin-top: 30px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
              <div style="font-size: 12px; font-weight: 600; color: #2d482d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Available Agents</div>
              <div id="kpi-available-agents" style="font-size: 28px; font-weight: bold; color: #2d482d;">Loading...</div>
            </div>
          </div>
        </div>
      </div>

      <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 30px; overflow: hidden;">
        <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
          <h3 style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Top Agents by Sales</h3>
        </div>
        <div id="top-agents-loading" style="text-align: center; padding: 40px; color: #666;">Loading agents data...</div>
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
            <tbody id="top-agents-tbody"></tbody>
          </table>
        </div>
      </div>

      <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
          <h3 style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Monthly Sales Trend (Last 12 Months)</h3>
        </div>
        <div style="padding: 30px;">
          <canvas id="monthly-sales-chart" width="400" height="200"></canvas>
        </div>
      </div>
    </div>

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
  </div>


  <div id="addLocationModal" class="modal" style="display:none; position:fixed; z-index:4000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center;">
    <div style="background:#fff; padding:24px; border-radius:8px; width:95%; max-width:650px;">
      <span onclick="closeAddLocationModal()" style="color:#aaa; float:right; font-size:32px; font-weight:normal; cursor:pointer;">&times;</span>
      <h3 style="color:#3e5f3e; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">Add New Subdivision / Map Pin</h3>
      <form id="addLocationForm">
        <div class="form-group">
          <label>Location Name (e.g., Carmen Valley Subdivision)</label>
          <input type="text" id="new_location_name" name="location_name" placeholder="Enter full location name" required>
        </div>
        <div class="form-group" style="margin-top: 15px;">
          <label>Click on the map below to drop a pin:</label>
          <div id="new-location-map" style="height: 320px; width: 100%; border-radius: 6px; border: 2px solid #ccc; z-index: 1;"></div>
        </div>
        <div style="display:flex; gap:10px; margin-top: 10px;">
            <input type="text" id="new_loc_lat" name="latitude" placeholder="Latitude" readonly style="background:#e9ecef;">
            <input type="text" id="new_loc_lng" name="longitude" placeholder="Longitude" readonly style="background:#e9ecef;">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <button type="button" onclick="closeAddLocationModal()" class="btn-primary" style="background:#ccc; color:#333;">Cancel</button>
            <button type="submit" class="btn-primary" style="background:#14532d;">Save Location Pin</button>
        </div>
      </form>
    </div>
  </div>

  <div id="mapperModal" class="modal" style="display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8);">
    <div class="modal-content" style="background:#fff; margin:2% auto; width:90%; height:90%; border-radius:10px; display:flex; flex-direction:column; overflow:hidden;">
        
        <div class="modal-header" style="padding:15px; background:#2d482d; color:#fff; display:flex; justify-content:space-between; align-items:center;">
          <h3 id="mapperTitle" style="margin:0;">Lot Mapper</h3>
          <span id="mapperCloseBtn" style="cursor:pointer; font-size:24px;">&times;</span>
        </div>
        
        <div class="modal-body" style="flex:1; display:flex; background:#444; overflow:hidden; position:relative; justify-content:center; align-items:center;">
            
            <div id="map-container" style="position:relative; display:flex; align-items:center; justify-content:center; background:#fff; box-shadow:0 0 20px rgba(0,0,0,0.5); width:80vw; height:75vh; overflow:hidden;">
              <div id="blueprint-wrapper" style="position:relative; transform-origin:center center; cursor:grab; line-height:0; display:inline-block;">
                  <img id="blueprint-img" src="" style="display:block; max-width:80vw; max-height:75vh; object-fit:contain; pointer-events:none;">
                  <div id="draw-layer" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></div>
              </div>
            </div>

            <div style="position:absolute; bottom:20px; left:20px; background:rgba(0,0,0,0.7); color:#fff; padding:10px 15px; border-radius:5px; pointer-events:none; z-index:100; font-size: 13px;">
                <b style="color: #f4d03f;">Mapping Instructions:</b><br>
                1. Scroll wheel to zoom. Click & drag to pan.<br>
                2. Click "Start Drawing Polygon".<br>
                3. Click corners to draw. Double-click to close.<br>
                <i>(Select "Target Lot" below to map multiple lots quickly)</i>
            </div>
        </div>
        
        <div class="modal-footer" style="padding:15px; background:#f4f4f4; border-top:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">
          
          <div style="display:flex; align-items:center; gap:15px;">
            <div style="display:flex; align-items:center; gap:5px;">
              <label style="font-weight:bold; color:#14532d; font-size:14px;">Target Lot:</label>
              <select id="mapper_target_lot" onchange="switchMapLot(this.value)" style="padding:6px; border-radius:5px; border:1px solid #ccc; font-weight:bold; cursor:pointer;">
              </select>
            </div>

            <div style="display:flex; align-items:center; gap:5px;">
              <label style="font-weight:bold; color:#14532d; font-size:14px;">Status Color:</label>
              <select id="mapper_status" onchange="updatePolygonColor()" style="padding:6px; border-radius:5px; border:1px solid #ccc; font-weight:bold; cursor:pointer;">
                <option value="Available" style="color:green;">🟩 Available (Green)</option>
                <option value="Reserved" style="color:#d39e00;">🟨 Reserved (Yellow)</option>
                <option value="Sold" style="color:red;">🟥 Sold (Red)</option>
              </select>
            </div>
          </div>

          <div style="display:flex; gap:10px;">
            <button type="button" id="startPolygonBtn" class="start-polygon-btn" style="padding:8px 15px; border:1px solid #2d482d; border-radius:5px; background:#e8f5e9; color:#2d482d; font-weight:bold; cursor:pointer;" onclick="startPolygonDrawing()">📐 Start Drawing</button>
            <button type="button" id="mapperCancelBtn" style="padding:8px 15px; border:1px solid #ccc; border-radius:5px; background:white; cursor:pointer;">Close Mapper</button>
            <button type="button" onclick="saveMapping()" style="padding:8px 20px; border:none; border-radius:5px; background:#2d482d; color:white; font-weight:bold; cursor:pointer;">💾 Save & Keep Mapping</button>
          </div>
        </div>
    </div>
  </div>

  <div id="editLotModal" style="display:none; position:fixed; z-index:2100; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); justify-content:center; align-items:center;">
    <div style="background:#fff; padding:24px; border-radius:8px; box-shadow:0 5px 15px rgba(0,0,0,0.3); width:95%; max-width:400px;">
      <span onclick="closeEditLotModal()" style="color:#aaa; float:right; font-size:32px; font-weight:normal; cursor:pointer;">&times;</span>
      <h3 style="color:#3e5f3e; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">Edit Lot</h3>
      <form id="editLotForm">
        <input type="hidden" id="edit_lot_id" name="lot_id">
        <div class="form-group"><label>Block Number</label><input type="text" id="edit_block_number" name="block_number" required></div>
        <div class="form-group"><label>Lot Number</label><input type="text" id="edit_lot_number" name="lot_number" required></div>
        <div class="form-group"><label>Lot Size</label><input type="text" id="edit_lot_size" name="lot_size" required></div>
        <div class="form-group"><label>Lot Price</label><input type="text" id="edit_lot_price" name="lot_price" required></div>
        <div class="form-group">
          <label>Status</label>
          <select id="edit_status" name="status" required>
            <option value="Available">Available</option>
            <option value="Sold">Sold</option>
            <option value="Reserved">Reserved</option>
          </select>
        </div>
        <div class="form-group"><label>Location ID</label><input type="text" id="edit_location_id" name="location_id" required></div>
        <button type="submit" class="btn-primary" style="margin-top:18px;">Save Changes</button>
      </form>
    </div>
  </div>

  <div id="editAccountModal" style="display:none; position:fixed; z-index:2100; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); justify-content:center; align-items:center;">
    <div style="background:#fff; padding:24px; border-radius:8px; width:95%; max-width:500px; max-height:90vh; overflow-y:auto;">
      <span onclick="closeEditAccountModal()" style="color:#aaa; float:right; font-size:32px; cursor:pointer;">&times;</span>
      <h3 style="color:#3e5f3e; margin-bottom:15px;">Edit Account</h3>
      <form id="editAccountForm" enctype="multipart/form-data">
        <input type="hidden" id="edit_account_id" name="account_id">
        <input type="hidden" id="edit_account_type" name="account_type">
        <div id="editAccountPhotoSection"></div>
        <div id="editAccountFields"></div>
        <button type="submit" class="btn-primary" style="margin-top:18px;">Save Changes</button>
      </form>
    </div>
  </div>

  <div id="viewClientModal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center;">
    <div style="background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); width: 90%; max-width: 450px; position: relative;">
      <span onclick="closeViewClientModal()" style="color: #aaa; float: right; font-size: 32px; font-weight: normal; line-height: 1; cursor: pointer; margin-left: 15px;">&times;</span>
      <h3 style="color: #3e5f3e; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Client Profile</h3>
      <div id="viewClientContent">Loading client details...</div>
    </div>
  </div>

  <script src="https://unpkg.com/@panzoom/panzoom/dist/panzoom.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(function() {
        document.querySelectorAll('.alert.success, .alert.error').forEach(function(el) {
          el.style.display = 'none';
        });
      }, 3000);
      
      const sa = document.getElementById('select-all-lots');
      if(sa) {
          sa.addEventListener('change', function() {
              document.querySelectorAll('.lot-checkbox').forEach(cb => cb.checked = this.checked);
          });
      }
    });

    let currentLotsData = [];
    
    // Mapper Globals
    let isPolygonDrawing = false;
    let polygonPoints = [];
    let currentMappingLotId = null;
    let currentMappingLocId = null;
    
    // SVG Globals
    let svgMain = null;
    let staticGroup = null;
    let liveGroup = null;

    const sections = ['section-dashboard', 'section-accounts', 'section-lots', 'section-viewings', 'section-analytics', 'section-notifications', 'section-audit-logs', 'section-documents'];
    function showSection(targetId) {
      sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            section.classList.toggle('active', sectionId === targetId);
            if(section.classList.contains('hidden')) section.classList.remove('hidden');
        }
      });
      document.querySelectorAll('.nav a').forEach(link => link.classList.toggle('active', link.dataset.target === targetId));
      
      if (targetId === 'section-lots') loadLocations();
      if (targetId === 'section-analytics') applyAnalyticsFilters();
      if (targetId === 'section-documents') loadDocuments();
      if (targetId === 'section-notifications') loadNotifications();
      if (targetId === 'section-audit-logs') loadAuditLogs();
    }

    document.querySelectorAll('.nav a').forEach(link => {
      link.addEventListener('click', function(e) {
        if(this.dataset.target) {
            e.preventDefault();
            showSection(this.dataset.target);
        }
      });
    });

    showSection('section-dashboard');

    function showAccountType(type) {
      document.querySelectorAll('.account-section').forEach(section => section.classList.remove('active'));
      document.querySelectorAll('.account-type-nav a').forEach(tab => tab.classList.remove('active'));
      const sec = document.getElementById(type + '-accounts');
      const tab = document.getElementById(type + '-tab');
      if (sec) sec.classList.add('active');
      if (tab) tab.classList.add('active');
    }

    function loadLocations() {
      fetch('?fetch=locations').then(r => r.json()).then(data => {
        const sel = document.getElementById('location_id');
        const anSel = document.getElementById('analytics_location');
        if(sel) {
            const previousVal = sel.value;
            sel.innerHTML = '<option value="" disabled selected>Please select a location</option>';
            data.forEach(l => sel.innerHTML += `<option value="${l.id}">${l.location_name}</option>`);
            if(previousVal) { sel.value = previousVal; loadLots(previousVal); }
        }
        if(anSel) {
            anSel.innerHTML = '<option value="">All Locations</option>';
            data.forEach(l => anSel.innerHTML += `<option value="${l.id}">${l.location_name}</option>`);
        }
      });
    }

    function loadLots(locationId) {
      if(!locationId) return;
      fetch(`?fetch=lots&location_id=${locationId}`).then(r => r.json()).then(data => {
        currentLotsData = data;
        const tbody = document.getElementById('lots-table-body');
        const selectAllHeader = document.getElementById('select-all-header');
        if(!tbody || !selectAllHeader) return;
        
        if (data && data.length > 0) {
            selectAllHeader.innerHTML = '<input type="checkbox" id="select-all-lots">';
            setTimeout(() => {
                const selectAll = document.getElementById('select-all-lots');
                if (selectAll) {
                    selectAll.onclick = function() {
                        const checkboxes = document.querySelectorAll('.lot-checkbox');
                        checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
                    };
                }
            }, 0);
        } else {
            selectAllHeader.innerHTML = '';
        }

        const newRowHtml = `
        <tr id="new-row" style="display:none; background:#e8f5e9;">
          <td></td>
          <td><input type="text" id="add_block_number" placeholder="Blk"></td>
          <td><input type="text" id="add_lot_number" placeholder="Lot"></td>
          <td><input type="text" id="add_lot_size" placeholder="Sqm"></td>
          <td><input type="text" id="add_lot_price" placeholder="Price"></td>
          <td>
            <select id="add_status">
              <option value="Available">Available</option>
              <option value="Sold">Sold</option>
              <option value="Reserved">Reserved</option>
            </select>
          </td>
          <td></td>
          <td>
            <button class="btn-primary" onclick="saveLot()" style="padding:6px 12px; font-size:12px;">Save</button>
            <button class="btn-danger" onclick="cancelAdd()" style="padding:6px 12px; font-size:12px;">Cancel</button>
          </td>
        </tr>`;

        tbody.innerHTML = newRowHtml;

        if(!data || data.length === 0) {
            tbody.innerHTML += '<tr><td colspan="8" style="text-align: center; padding: 20px;">No lots found for this location.</td></tr>';
        } else {
            data.forEach(lot => {
                const isMapped = (lot.coordinates && lot.coordinates.length > 5);
                const row = `
                <tr data-id="${lot.id}">
                  <td><input type="checkbox" class="lot-checkbox" value="${lot.id}"></td>
                  <td>${lot.block_number}</td>
                  <td>${lot.lot_number}</td>
                  <td>${lot.lot_size} sqm</td>
                  <td>₱${parseFloat(lot.lot_price).toLocaleString()}</td>
                  <td><span class="status-badge status-${lot.status.toLowerCase()}">${lot.status}</span></td>
                  <td>
                    <button class="btn-small" style="background:${isMapped?'#d4edda':'#f8f9fa'};" onclick="openMapper(${lot.id}, ${locationId})">
                      ${isMapped ? '✅ Edit Pin' : 'Set Pin'}
                    </button>
                  </td>
                  <td>
                    <button class="btn-small" onclick="openEditLotModal(${lot.id})">Edit</button>
                    <button class="btn-small btn-danger" style="color:white;" onclick="deleteLot(${lot.id})">Delete</button>
                  </td>
                </tr>`;
                tbody.insertAdjacentHTML('beforeend', row);
            });
        }
      });
    }

    function addNewLot() { 
        const loc = document.getElementById('location_id').value;
        if (!loc) { alert("Please select a location from the dropdown first."); return; }
        document.getElementById('new-row').style.display = 'table-row'; 
    }
    
    function cancelAdd() { document.getElementById('new-row').style.display = 'none'; }
    
    function saveLot() {
      const locId = document.getElementById('location_id').value;
      if(!locId) return alert('Select location first');
      const formData = new FormData();
      formData.append('action', 'save');
      formData.append('location_id', locId);
      formData.append('block_number', document.getElementById('add_block_number').value);
      formData.append('lot_number', document.getElementById('add_lot_number').value);
      formData.append('lot_size', document.getElementById('add_lot_size').value);
      formData.append('lot_price', document.getElementById('add_lot_price').value);
      formData.append('status', document.getElementById('add_status').value);

      fetch('?action=save', { method:'POST', body: formData }).then(r=>r.json()).then(res=>{
          if(res.success) { loadLots(locId); } else { alert(res.error); }
      });
    }

    function deleteLot(id) {
        if(!confirm('Delete this lot?')) return;
        const formData = new FormData(); formData.append('action', 'delete'); formData.append('lot_id', id);
        fetch('?action=delete', {method:'POST', body:formData}).then(r=>r.json()).then(()=>{
            loadLots(document.getElementById('location_id').value);
        });
    }

    function bulkDeleteLots() {
        const checks = Array.from(document.querySelectorAll('.lot-checkbox:checked')).map(cb=>cb.value);
        if(!checks.length) return alert("Select lots first.");
        if(!confirm("Delete selected?")) return;
        const formData = new FormData(); formData.append('action', 'bulk_delete'); formData.append('lot_ids', JSON.stringify(checks));
        fetch('?action=bulk_delete', {method:'POST', body:formData}).then(r=>r.json()).then(()=>{
            loadLots(document.getElementById('location_id').value);
        });
    }

    function openEditLotModal(id) {
        fetch(`?fetch=single_lot&id=${id}`).then(r=>r.json()).then(lot=>{
            document.getElementById('edit_lot_id').value = lot.id;
            document.getElementById('edit_block_number').value = lot.block_number;
            document.getElementById('edit_lot_number').value = lot.lot_number;
            document.getElementById('edit_lot_size').value = lot.lot_size;
            document.getElementById('edit_lot_price').value = lot.lot_price;
            document.getElementById('edit_status').value = lot.status;
            document.getElementById('edit_location_id').value = lot.location_id;
            document.getElementById('editLotModal').style.display = 'flex';
        });
    }
    
    function closeEditLotModal() { document.getElementById('editLotModal').style.display='none'; }
    
    document.getElementById('editLotForm').onsubmit = function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'save');
        fetch('', {method:'POST', body:formData}).then(r=>r.json()).then(res=>{
            if(res.success){ closeEditLotModal(); loadLots(document.getElementById('location_id').value); }
        });
    }

    function editAccount(id, type) {
        document.getElementById('edit_account_id').value = id;
        document.getElementById('edit_account_type').value = type;
        
        fetch(`?fetch=${type}&id=${id}`).then(r=>r.json()).then(acc=>{
            let fields = `<div class="form-group"><label>First Name</label><input type="text" name="first_name" value="${acc.first_name}"></div>`;
            fields += `<div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="${acc.last_name}"></div>`;
            fields += `<div class="form-group"><label>Email</label><input type="email" name="email" value="${acc.email}"></div>`;
            document.getElementById('editAccountFields').innerHTML = fields;
            document.getElementById('editAccountModal').style.display = 'flex';
        });
    }
    
    function closeEditAccountModal() { document.getElementById('editAccountModal').style.display = 'none'; }
    
    document.getElementById('editAccountForm').onsubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const type = fd.get('account_type');
        fd.append(type === 'admin' ? 'account_action' : (type==='agent'?'agent_action':'user_action'), 'update');
        fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if(res.success){ location.reload(); } else alert(res.error);
        });
    }

    function viewProfile(id, type, evt) {
        if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
        const modal   = document.getElementById('viewClientModal');
        const content = document.getElementById('viewClientContent');
        if (!modal || !content) return;
        modal.style.display = 'flex';
        content.innerHTML = '<div style="color:#666;">Loading details…</div>';
        
        fetch(`?fetch=${type}&id=${id}`).then(r=>r.json()).then(account=>{
            if (account && account.id) {
                let html = `<strong>Name:</strong> ${account.first_name || ''} ${account.middle_name ? account.middle_name + ' ' : ''}${account.last_name || ''}<br>`;
                if (account.username) html += `<strong>Username:</strong> ${account.username}<br>`;
                html += `<strong>Email:</strong> ${account.email || 'N/A'}<br>`;
                if (account.phone) html += `<strong>Mobile:</strong> ${account.phone}<br>`;
                if (account.mobile_number) html += `<strong>Mobile:</strong> ${account.mobile_number}<br>`;
                html += `<strong>Address:</strong> ${account.address || 'N/A'}<br>`;
                content.innerHTML = html;
            } else {
                content.innerHTML = `<div style="color:#dc3545;">Client not found.</div>`;
            }
        });
    }
    function closeViewClientModal() { document.getElementById('viewClientModal').style.display = 'none'; }

    function confirmLogout() {
        if(confirm("Are you sure you want to logout?")) window.location.href = 'logout.php';
    }

    // =============================================
    // LEAFLET LOCATION MAPPER (Add Pin)
    // =============================================
    let locationMap = null;
    let locationMarker = null;

    function openAddLocationModal() {
        document.getElementById('addLocationModal').style.display = 'flex';
        document.getElementById('addLocationForm').reset();
        document.getElementById('new_loc_lat').value = '';
        document.getElementById('new_loc_lng').value = '';
        
        setTimeout(() => {
            if (!locationMap) {
                locationMap = L.map('new-location-map').setView([6.9214, 122.0790], 12);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(locationMap);

                locationMap.on('click', function(e) {
                    const lat = e.latlng.lat.toFixed(6);
                    const lng = e.latlng.lng.toFixed(6);
                    
                    document.getElementById('new_loc_lat').value = lat;
                    document.getElementById('new_loc_lng').value = lng;

                    if (locationMarker) {
                        locationMarker.setLatLng(e.latlng);
                    } else {
                        locationMarker = L.marker(e.latlng).addTo(locationMap);
                    }
                });
            } else {
                locationMap.invalidateSize();
                if(locationMarker) {
                    locationMap.removeLayer(locationMarker);
                    locationMarker = null;
                }
            }
        }, 200);
    }

    function closeAddLocationModal() {
        document.getElementById('addLocationModal').style.display = 'none';
    }

    document.getElementById('addLocationForm').onsubmit = function(e) {
        e.preventDefault();
        const lat = document.getElementById('new_loc_lat').value;
        const lng = document.getElementById('new_loc_lng').value;
        if(!lat || !lng) {
            alert("Please click on the map to set the exact pin location.");
            return;
        }

        const formData = new FormData(this);
        formData.append('action', 'add_location');

        fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert("New subdivision location pinned successfully!");
                closeAddLocationModal();
                loadLocations(); 
            } else {
                alert("Error saving location: " + res.error);
            }
        });
    };

    // =============================================
    // BLUEPRINT POLYGON MAPPER (NATIVE SVG VERSION)
    // =============================================
    function closeMapperModal() {
      var modal = document.getElementById('mapperModal');
      if (modal) modal.style.display = 'none';
      var layer = document.getElementById('draw-layer');
      if (layer) {
        layer.onmousedown = null; layer.onclick = null; layer.ondblclick = null;
        layer.style.pointerEvents = 'none'; layer.innerHTML = '';
      }
      var img = document.getElementById('blueprint-img');
      if (img) img.src = '';
      
      if(window.pzInstance) { 
          window.pzInstance.reset();
          window.pzInstance.destroy(); 
          window.pzInstance = null; 
      }
      
      const wrapper = document.getElementById('blueprint-wrapper');
      if(wrapper) {
          wrapper.style.transform = 'none';
          wrapper.style.cursor = 'grab';
      }
    }

    function switchMapLot(newLotId) {
        let lot = currentLotsData.find(x => x.id == newLotId);
        if (lot) {
            openMapper(lot.id, currentMappingLocId);
        }
    }

    function openMapper(lotId, locId) {
        currentMappingLotId = lotId;
        currentMappingLocId = locId;
        
        let targetLot = currentLotsData.find(l => l.id == lotId);
        if (targetLot) {
            document.getElementById('mapperTitle').innerText = `Mapping: Block ${targetLot.block_number} - Lot ${targetLot.lot_number}`;
        }
        
        fetch(`admindashboard.php?fetch=blueprint_data&location_id=${locId}`).then(r => r.json()).then(data => {
            if(!data.image) { alert("Please upload a blueprint for this location first!"); return; }
            
            const img = document.getElementById('blueprint-img');
            const layer = document.getElementById('draw-layer');
            const wrapper = document.getElementById('blueprint-wrapper');
            const container = document.getElementById('map-container');
            
            img.src = data.image; 
            layer.innerHTML = '';

            // Clean up old panzoom completely before starting a new one
            if (window.pzInstance) {
                window.pzInstance.destroy();
            }
            wrapper.style.transform = 'scale(1) translate(0px, 0px)'; 

            // Initialize Panzoom
            window.pzInstance = Panzoom(wrapper, {
                maxScale: 10,
                minScale: 0.5,
                step: 0.2,
                cursor: 'grab'
            });

            container.onwheel = function(e) {
                e.preventDefault(); 
                window.pzInstance.zoomWithWheel(e);
            };

            // Populate Target Lot Dropdown (so admin can rapidly map all lots)
            const targetSelect = document.getElementById('mapper_target_lot');
            if (targetSelect) {
                targetSelect.innerHTML = '';
                data.lots.forEach(l => {
                    let opt = document.createElement('option');
                    opt.value = l.id;
                    opt.text = `Blk ${l.block_number} - Lot ${l.lot_number}`;
                    if (l.id == lotId) opt.selected = true;
                    targetSelect.appendChild(opt);
                });
            }

            let selectedLot = data.lots.find(l => l.id == lotId);
            window.currentStatus = selectedLot && selectedLot.status ? selectedLot.status : 'Available';
            document.getElementById('mapper_status').value = window.currentStatus;

            // Setup primary SVG canvas using native 0-100 coordinate system
            svgMain = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svgMain.setAttribute('width', '100%');
            svgMain.setAttribute('height', '100%');
            svgMain.setAttribute('viewBox', '0 0 100 100');
            svgMain.setAttribute('preserveAspectRatio', 'none');
            svgMain.style.position = 'absolute';
            svgMain.style.top = '0';
            svgMain.style.left = '0';
            svgMain.style.pointerEvents = 'none';

            staticGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            liveGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            svgMain.appendChild(staticGroup);
            svgMain.appendChild(liveGroup);
            layer.appendChild(svgMain);

            function drawAllPolygons() {
              staticGroup.innerHTML = '';
              if (data.lots) {
                data.lots.forEach(l => {
                  if (l.coordinates && l.id != lotId) { 
                    try {
                      const c = JSON.parse(l.coordinates);
                      if (c.type === 'polygon' && Array.isArray(c.points)) {
                        // FIXED: Raw pt.x and pt.y without offsetWidth
                        let pointsStr = c.points.map(pt => `${pt.x},${pt.y}`).join(' ');
                        
                        let status = (l.status || 'Available').toLowerCase();
                        let fillColor = status === 'reserved' ? 'rgba(255,255,0,0.4)' : (status === 'sold' ? 'rgba(255,0,0,0.4)' : 'rgba(0,255,0,0.4)');
                        let strokeColor = status === 'reserved' ? 'gold' : (status === 'sold' ? 'red' : 'green');
                        
                        let polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        polygon.setAttribute('points', pointsStr); 
                        polygon.setAttribute('stroke', strokeColor); 
                        polygon.setAttribute('stroke-width', '0.2'); 
                        polygon.setAttribute('vector-effect', 'non-scaling-stroke');
                        polygon.setAttribute('fill', fillColor);
                        staticGroup.appendChild(polygon);
                      }
                    } catch(e) {}
                  }
                });
              }
            }

            drawAllPolygons();

            if (selectedLot && selectedLot.coordinates) {
              try {
                let coordsObj = JSON.parse(selectedLot.coordinates);
                if (coordsObj.type === 'polygon' && Array.isArray(coordsObj.points)) {
                  polygonPoints = coordsObj.points;
                  setTimeout(() => { if (typeof window.drawPolygon === 'function') drawPolygon(true); }, 100);
                }
              } catch(e) {}
            } else { polygonPoints = []; }

            isPolygonDrawing = false;
            layer.style.pointerEvents = 'none'; 
            wrapper.style.cursor = 'grab';

            document.getElementById('mapperModal').style.display = 'flex';
        });
    }

    function updatePolygonColor() {
        if(polygonPoints.length > 0) {
            drawLivePolygon(polygonPoints.length > 2 && !isPolygonDrawing);
        }
    }

    function startPolygonDrawing() {
        isPolygonDrawing = true;
        polygonPoints = [];
        const layer = document.getElementById('draw-layer');
        const wrapper = document.getElementById('blueprint-wrapper');
        
        layer.style.pointerEvents = 'auto';
        wrapper.style.cursor = 'crosshair';
        
        if (liveGroup) liveGroup.innerHTML = '';

        let startX, startY;
        layer.onmousedown = function(e) {
            startX = e.clientX;
            startY = e.clientY;
        };

        layer.onclick = function(e) {
            if(!isPolygonDrawing) return;
            if(Math.abs(e.clientX - startX) > 5 || Math.abs(e.clientY - startY) > 5) return;

            const rect = layer.getBoundingClientRect();
            // Store raw percentages mapping natively to the 0-100 viewBox!
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            polygonPoints.push({x, y});
            drawLivePolygon(false);
        };

        layer.ondblclick = function(e) {
            if(polygonPoints.length > 2) {
                isPolygonDrawing = false;
                layer.style.pointerEvents = 'none';
                wrapper.style.cursor = 'grab';
                drawLivePolygon(true);
            }
        };
    }

    function drawLivePolygon(closed) {
        if(!liveGroup) return;
        liveGroup.innerHTML = '';
        
        const ptsStr = polygonPoints.map(pt => `${pt.x},${pt.y}`).join(' ');
        const shape = document.createElementNS('http://www.w3.org/2000/svg', closed ? 'polygon' : 'polyline');
        
        const status = document.getElementById('mapper_status').value.toLowerCase();
        let color = status === 'sold' ? 'rgba(255,0,0,0.6)' : (status === 'reserved' ? 'rgba(255,255,0,0.6)' : 'rgba(0,255,0,0.6)');
        let stroke = status === 'sold' ? 'red' : (status === 'reserved' ? 'gold' : 'green');

        shape.setAttribute('points', ptsStr);
        shape.setAttribute('stroke', stroke);
        shape.setAttribute('stroke-width', closed ? '0.4' : '0.2');
        shape.setAttribute('vector-effect', 'non-scaling-stroke');
        shape.setAttribute('fill', closed ? color : 'none');
        
        if(!closed) shape.setAttribute('stroke-dasharray', '1,1');
        liveGroup.appendChild(shape);

        if(!closed) {
            polygonPoints.forEach(pt => {
                let c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                c.setAttribute('cx', pt.x);
                c.setAttribute('cy', pt.y);
                c.setAttribute('r', '0.4');
                c.setAttribute('fill', 'white');
                c.setAttribute('stroke', stroke);
                c.setAttribute('stroke-width', '0.2');
                c.setAttribute('vector-effect', 'non-scaling-stroke');
                liveGroup.appendChild(c);
            });
        }
    }

    window.drawPolygon = drawLivePolygon;

    function saveMapping() {
        if(polygonPoints.length < 3) return alert("Please draw a shape with at least 3 points first!");
        
        const coordsStr = JSON.stringify({ type: 'polygon', points: polygonPoints });
        const lotStatus = document.getElementById('mapper_status').value; 

        const fd = new FormData();
        fd.append('action', 'save_map');
        fd.append('lot_id', currentMappingLotId);
        fd.append('coords', coordsStr);
        fd.append('status', lotStatus); 
        
        fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if(res.success) { 
                alert("Map bounds & status saved successfully!"); 
                // Automatically refresh map to bake the lot into staticGroup and continue
                openMapper(currentMappingLotId, currentMappingLocId); 
                loadLots(document.getElementById('location_id').value);
            } else { alert("Database Error: "+res.error); }
        });
    }

    // Analytics Data Fetching
    const monthlySalesData = <?php echo json_encode($monthly_sales); ?>;
    function applyAnalyticsFilters() {
      const dateFrom = document.getElementById('analytics_date_from')?.value;
      const dateTo   = document.getElementById('analytics_date_to')?.value;
      const locationId = document.getElementById('analytics_location')?.value;
      const params = new URLSearchParams();
      params.append('fetch', 'analytics');
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (locationId) params.append('location_id', locationId);

      fetch(window.location.pathname + '?' + params.toString())
        .then(response => response.json())
        .then(data => {
          const totalSalesEl = document.getElementById('kpi-total-sales');
          const totalLotsEl  = document.getElementById('kpi-total-lots');
          const agentsEl     = document.getElementById('kpi-available-agents');
          if (totalSalesEl) totalSalesEl.textContent = '₱' + (data.kpis.total_sales || 0).toLocaleString();
          if (totalLotsEl)  totalLotsEl.textContent  = data.kpis.total_lots || 0;
          if (agentsEl)     agentsEl.textContent     = data.kpis.available_agents || 0;
          updateMonthlySalesChart(data.monthly_sales);
          loadTopAgents();
        });
    }

    function updateMonthlySalesChart(data) {
      const canvas = document.getElementById('monthly-sales-chart');
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      const padding = 40; const width = canvas.width - padding * 2; const height = canvas.height - padding * 2;
      ctx.strokeStyle = '#ddd'; ctx.beginPath(); ctx.moveTo(padding, padding); ctx.lineTo(padding, canvas.height - padding); ctx.lineTo(canvas.width - padding, canvas.height - padding); ctx.stroke();
      if(!data || data.length === 0) return;
      const maxAmount = Math.max(...data.map(item => item.amount), 1);
      const barWidth = width / data.length / 2;
      data.forEach((item, index) => {
        const x = padding + index * 2 * barWidth; const barHeight = (item.amount / maxAmount) * (height - 20); const y = canvas.height - padding - barHeight;
        ctx.fillStyle = index % 2 === 0 ? '#28a745' : '#007bff'; ctx.fillRect(x, y, barWidth, barHeight);
        ctx.fillStyle = '#333'; ctx.font = 'bold 12px Arial'; ctx.fillText(item.month, x, canvas.height - padding + 15); ctx.fillText(item.amount.toLocaleString(), x, y - 5);
      });
    }
    
    function loadTopAgents() {
        const tbody = document.getElementById('top-agents-tbody');
        fetch(window.location.pathname + '?fetch=top_agents').then(r=>r.json()).then(agents => {
            if(!tbody) return;
            if(!agents.length) { tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:15px;">No data found.</td></tr>`; return; }
            tbody.innerHTML = agents.map((a, i) => `<tr><td style="padding:15px;">${i+1}</td><td style="padding:15px;">${a.name}<br><small>${a.email}</small></td><td style="padding:15px;">${a.sales_count}</td><td style="padding:15px;">₱${a.total_amount.toLocaleString()}</td><td style="padding:15px;">₱${a.avg_deal_size.toLocaleString()}</td></tr>`).join('');
            document.getElementById('top-agents-content').style.display = 'block';
            document.getElementById('top-agents-loading').style.display = 'none';
        });
    }

    function exportAnalytics() { window.location.href = window.location.pathname + '?export=analytics'; }
    
    function loadDocuments() {
      fetch('?fetch=all_user_documents').then(r=>r.json()).then(docs=>{
          const c = document.getElementById('documents-container');
          if(!c) return;
          if(!docs.length) c.innerHTML = '<p style="text-align:center; color:#666;">No pending documents.</p>';
          else c.innerHTML = docs.map(d => `<div style="padding:12px; background:#fff; border-radius:6px; border:1px solid #ddd; margin-bottom:10px;"><strong>${d.file_name||'Doc'}</strong><br>User: ${d.email}<br><button class="btn-small" style="margin-top:10px;" onclick="approveDocument(${d.id})">Approve</button> <button class="btn-small btn-danger" style="margin-top:10px;" onclick="rejectDocument(${d.id})">Reject</button></div>`).join('');
      });
    }
    
    function loadNotifications() {
        fetch('?fetch=notifications').then(r=>r.json()).then(data=>{
            const c = document.getElementById('notifications-container');
            if(!c) return;
            if(!data.length) c.innerHTML = '<p style="text-align:center; color:#666;">No notifications.</p>';
            else c.innerHTML = data.map(n => `<div style="padding:15px; margin-bottom:10px; border-radius:6px; background:#fff; border:1px solid #ddd;"><strong>${n.title}</strong><p style="margin:5px 0;">${n.message}</p></div>`).join('');
        });
    }
    
    function loadAuditLogs() {
        fetch('?fetch=audit_logs').then(r=>r.json()).then(data=>{
            const c = document.getElementById('audit-logs-container');
            if(!c) return;
            if(!data.length) c.innerHTML = '<p style="text-align:center; color:#666;">No audit logs.</p>';
            else c.innerHTML = data.map(l => `<div style="padding:12px; margin-bottom:10px; border-radius:6px; background:#fff; border:1px solid #ddd;"><strong>${l.action}</strong><div style="font-size:13px;">${l.details}</div></div>`).join('');
        });
    }
    
    setTimeout(() => { updateMonthlySalesChart(monthlySalesData); loadTopAgents(); }, 500);

  </script>
</body>
</html>