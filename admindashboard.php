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
  // CREATE PIN LOCATIONS TABLE IF NOT EXISTS
  // =============================================
  $createTableSQL = "CREATE TABLE IF NOT EXISTS pin_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lot_id INT NOT NULL UNIQUE,
    polygon_coordinates LONGTEXT,
    pin_status VARCHAR(50) DEFAULT 'Available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
  )";
  mysqli_query($conn, $createTableSQL);
  
  // Add pin_status column to existing tables
  mysqli_query($conn, "ALTER TABLE pin_locations ADD COLUMN IF NOT EXISTS pin_status VARCHAR(50) DEFAULT 'Available'");
  // Add payment columns to lots table for Manage Lots payment tracking
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_type VARCHAR(30) DEFAULT 'Fully Paid'");
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(12,2) DEFAULT NULL");

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
        $payment_type = isset($_POST['payment_type']) ? mysqli_real_escape_string($conn, $_POST['payment_type']) : 'Fully Paid';
        $payment_amount_raw = isset($_POST['payment_amount']) ? trim($_POST['payment_amount']) : '';
        $payment_amount = 'NULL';

          if (!in_array($payment_type, ['Down Payment', 'Fully Paid', 'Not Applicable'], true)) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid payment type']);
          exit;
        }

          if ($status === 'Available') {
            // Available lots should not have payment details yet.
            $payment_type = 'Not Applicable';
            $payment_amount = 'NULL';
          } elseif ($payment_type === 'Not Applicable') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Select Down Payment or Fully Paid for non-available lots']);
            exit;
          } elseif ($payment_type === 'Down Payment') {
          if ($payment_amount_raw === '' || !is_numeric($payment_amount_raw) || (float)$payment_amount_raw <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please enter a valid down payment amount']);
            exit;
          }
          $payment_amount = (float)$payment_amount_raw;
        }

      if (!empty($_POST['lot_id'])) {
          $lot_id = intval($_POST['lot_id']);
          $updateQuery = "UPDATE lots SET
                          block_number = '$block_number',
                          lot_number   = '$lot_number',
                          lot_size     = '$lot_size',
                          lot_price    = '$lot_price',
                          location_id  = '$location_id',
                  status       = '$status',
                  payment_type = '$payment_type',
                  payment_amount = $payment_amount
                          WHERE id = $lot_id";

          $success = mysqli_query($conn, $updateQuery);
          if ($success) {
            // Keep blueprint pin color/status accurate when status is edited from Manage Lots.
            $syncPinStatusQuery = "UPDATE pin_locations SET pin_status = '$status' WHERE lot_id = $lot_id";
            mysqli_query($conn, $syncPinStatusQuery);
          }
          $msg     = $success ? 'Lot updated successfully' : mysqli_error($conn);
      } else {
          $insertQuery = "INSERT INTO lots (block_number, lot_number, lot_size, lot_price, location_id, status, payment_type, payment_amount)
                  VALUES ('$block_number', '$lot_number', '$lot_size', '$lot_price', '$location_id', '$status', '$payment_type', $payment_amount)";
          $success = mysqli_query($conn, $insertQuery);
          $msg     = $success ? 'Lot added successfully' : mysqli_error($conn);
      }

      header('Content-Type: application/json');
      echo json_encode(['success' => (bool)$success, 'message' => $msg]);
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


  // =============================================
  // PIN LOCATION ENDPOINTS (AJAX)
  // =============================================
  
  // GET: Fetch blueprint for a location
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
      isset($_GET['fetch']) && $_GET['fetch'] === 'blueprint') {
      $lot_id = intval($_GET['lot_id'] ?? 0);
      
      // Get lot and its location
      $lotQuery = "SELECT l.*, loc.location_name FROM lots l 
                   LEFT JOIN lot_locations loc ON l.location_id = loc.id 
                   WHERE l.id = $lot_id";
      $lotResult = mysqli_query($conn, $lotQuery);
      $lot = $lotResult ? mysqli_fetch_assoc($lotResult) : null;
      
      if (!$lot) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Lot not found']);
          exit;
      }
      
      // Get blueprint for this location
      $blueprintQuery = "SELECT filename FROM blueprints WHERE location_id = " . $lot['location_id'] . " LIMIT 1";
      $blueprintResult = mysqli_query($conn, $blueprintQuery);
      $blueprint = $blueprintResult ? mysqli_fetch_assoc($blueprintResult) : null;
      
        // Get existing pin location for current lot
        $pinQuery = "SELECT polygon_coordinates, pin_status FROM pin_locations WHERE lot_id = $lot_id";
        $pinResult = mysqli_query($conn, $pinQuery);
        $pin = $pinResult ? mysqli_fetch_assoc($pinResult) : null;

        // Get all saved pins in this location (for multi-lot rendering on same blueprint)
        $allPins = [];
        $allPinsQuery = "
          SELECT p.lot_id, p.polygon_coordinates, p.pin_status
          FROM pin_locations p
          INNER JOIN lots l ON l.id = p.lot_id
          WHERE l.location_id = " . (int)$lot['location_id'];
        $allPinsResult = mysqli_query($conn, $allPinsQuery);
        if ($allPinsResult) {
          while ($row = mysqli_fetch_assoc($allPinsResult)) {
            $allPins[] = [
              'lot_id' => (int)$row['lot_id'],
              'coordinates' => json_decode($row['polygon_coordinates'], true),
              'pin_status' => $row['pin_status'] ?: 'Available'
            ];
          }
        }
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => true,
          'lot' => $lot,
          'blueprint' => $blueprint ? 'blueprints/' . $blueprint['filename'] : null,
          'pin' => $pin ? json_decode($pin['polygon_coordinates'], true) : null,
          'pin_status' => $pin ? $pin['pin_status'] : null,
          'all_pins' => $allPins
      ]);
      exit;
  }
  
  // POST: Save pin location
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
      isset($_POST['action']) && $_POST['action'] === 'save_pin') {
      $lot_id = intval($_POST['lot_id'] ?? 0);
      $coordinates = mysqli_real_escape_string($conn, $_POST['polygon_coordinates'] ?? '');
      $pin_status = mysqli_real_escape_string($conn, $_POST['pin_status'] ?? 'Available');
      
      if (!$lot_id || !$coordinates) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Missing lot_id or coordinates']);
          exit;
      }
      
      // Check if pin exists
      $checkQuery = "SELECT id FROM pin_locations WHERE lot_id = $lot_id";
      $checkResult = mysqli_query($conn, $checkQuery);
      $exists = mysqli_fetch_assoc($checkResult);
      
      if ($exists) {
          // Update
          $updateQuery = "UPDATE pin_locations SET polygon_coordinates = '$coordinates', pin_status = '$pin_status' WHERE lot_id = $lot_id";
          $success = mysqli_query($conn, $updateQuery);
      } else {
          // Insert
          $insertQuery = "INSERT INTO pin_locations (lot_id, polygon_coordinates, pin_status) VALUES ($lot_id, '$coordinates', '$pin_status')";
          $success = mysqli_query($conn, $insertQuery);
      }
      
      // Automatically update the lot's status to match the pin_status
      if ($success) {
          $updateLotStatusQuery = "UPDATE lots SET status = '$pin_status' WHERE id = $lot_id";
          mysqli_query($conn, $updateLotStatusQuery);
      }
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Pin location and lot status saved successfully' : mysqli_error($conn)
      ]);
      exit;
  }
  
  // GET: Fetch pin location
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
      isset($_GET['fetch']) && $_GET['fetch'] === 'pin_location') {
      $lot_id = intval($_GET['lot_id'] ?? 0);
      
      $pinQuery = "SELECT polygon_coordinates FROM pin_locations WHERE lot_id = $lot_id";
      $pinResult = mysqli_query($conn, $pinQuery);
      $pin = $pinResult ? mysqli_fetch_assoc($pinResult) : null;
      
      header('Content-Type: application/json');
      echo json_encode([
          'success' => true,
          'pin' => $pin ? json_decode($pin['polygon_coordinates'], true) : null
      ]);
      exit;
  }


    // POST: Save new location
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
      isset($_POST['action']) && $_POST['action'] === 'save_location') {
      $location_name = mysqli_real_escape_string($conn, $_POST['location_name'] ?? '');
      $latitude = floatval($_POST['latitude'] ?? 0);
      $longitude = floatval($_POST['longitude'] ?? 0);
      
      if (!$location_name || $latitude == 0 || $longitude == 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing location name or coordinates']);
        exit;
      }
      
      // Insert into lot_locations table
      $insertQuery = "INSERT INTO lot_locations (location_name, latitude, longitude) VALUES ('$location_name', $latitude, $longitude)";
      $success = mysqli_query($conn, $insertQuery);
      
      header('Content-Type: application/json');
      if ($success) {
        $location_id = mysqli_insert_id($conn);

        // Handle optional blueprint upload
        if (isset($_FILES['blueprint']) && $_FILES['blueprint']['error'] === UPLOAD_ERR_OK) {
          $allowed = ['jpg','jpeg','png','gif'];
          $ext = strtolower(pathinfo($_FILES['blueprint']['name'], PATHINFO_EXTENSION));
          if (in_array($ext, $allowed)) {
            $uploadDir = 'blueprints/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $newName = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['blueprint']['tmp_name'], $uploadDir . $newName)) {
              $stmt = $conn->prepare("INSERT INTO blueprints (location_id, filename, uploaded_at) VALUES (?, ?, NOW())");
              $stmt->bind_param('is', $location_id, $newName);
              $stmt->execute();
              $stmt->close();
            }
          }
        }

        echo json_encode([
          'success' => true,
          'message' => 'Location saved successfully',
          'location_id' => $location_id
        ]);
      } else {
        echo json_encode([
          'success' => false,
          'error' => mysqli_error($conn)
        ]);
      }
      exit;
    }

    // =====================================================
  // AJAX: Update payment status
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'update_payment_status') {
      header('Content-Type: application/json');
      $pay_id = intval($_POST['payment_id'] ?? 0);
      $new_status = trim($_POST['new_status'] ?? '');
      if (!$pay_id || !in_array($new_status, ['Pending','Verified','Rejected'], true)) {
          echo json_encode(['success' => false, 'error' => 'Invalid payment ID or status']); exit;
      }
      $stmt = $conn->prepare("UPDATE payments SET status=? WHERE id=?");
      $stmt->bind_param('si', $new_status, $pay_id);
      $ok = $stmt->execute();
      $stmt->close();

      // If verified, credit the lot balance
      if ($ok && $new_status === 'Verified') {
          $pRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT lot_id, amount_paid FROM payments WHERE id=$pay_id"));
          if ($pRow && $pRow['lot_id']) {
              $conn->query("UPDATE lots SET balance = balance + {$pRow['amount_paid']} WHERE id={$pRow['lot_id']}");
          }
      }
      echo json_encode(['success' => $ok]); exit;
  }

  // =====================================================
  // AJAX: Assign owner to lot
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'assign_lot_owner') {
      header('Content-Type: application/json');
      $lot_id = intval($_POST['lot_id'] ?? 0);
      $owner_id = intval($_POST['owner_id'] ?? 0);
      if (!$lot_id) { echo json_encode(['success'=>false,'error'=>'Invalid lot']); exit; }
      $stmt = $conn->prepare("UPDATE lots SET owner_id=? WHERE id=?");
      $oid = $owner_id ?: null;
      $stmt->bind_param('ii', $oid, $lot_id);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]); exit;
  }

  // =====================================================
  // AJAX: Update lot payment info (type, balance)
  // =====================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
      isset($_POST['action']) && $_POST['action'] === 'update_lot_payment') {
      header('Content-Type: application/json');
      $lot_id = intval($_POST['lot_id'] ?? 0);
      $pay_type = trim($_POST['payment_type'] ?? '');
      $pay_amt = floatval($_POST['payment_amount'] ?? 0);
      if (!$lot_id || !in_array($pay_type, ['Down Payment','Fully Paid','Not Applicable'], true)) {
          echo json_encode(['success'=>false,'error'=>'Invalid data']); exit;
      }
      $stmt = $conn->prepare("UPDATE lots SET payment_type=?, payment_amount=? WHERE id=?");
      $stmt->bind_param('sdi', $pay_type, $pay_amt, $lot_id);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]); exit;
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

      // POST: Delete location
      if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'delete_location') {
        $location_id = intval($_POST['location_id'] ?? 0);

        if (!$location_id) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid location selected']);
          exit;
        }

        $lotsCountQuery = "SELECT COUNT(*) AS total FROM lots WHERE location_id = $location_id";
        $lotsCountRes = mysqli_query($conn, $lotsCountQuery);
        $lotsCountRow = $lotsCountRes ? mysqli_fetch_assoc($lotsCountRes) : ['total' => 0];
        $lotsCount = (int)($lotsCountRow['total'] ?? 0);

        if ($lotsCount > 0) {
          header('Content-Type: application/json');
          echo json_encode([
            'success' => false,
            'error' => 'Cannot delete location with existing lots. Delete or move the lots first.'
          ]);
          exit;
        }

        $blueprintCountQuery = "SELECT COUNT(*) AS total FROM blueprints WHERE location_id = $location_id";
        $blueprintCountRes = mysqli_query($conn, $blueprintCountQuery);
        $blueprintCountRow = $blueprintCountRes ? mysqli_fetch_assoc($blueprintCountRes) : ['total' => 0];
        $blueprintCount = (int)($blueprintCountRow['total'] ?? 0);

        if ($blueprintCount > 0) {
          header('Content-Type: application/json');
          echo json_encode([
            'success' => false,
            'error' => 'Cannot delete location with existing blueprints. Remove the blueprint first.'
          ]);
          exit;
        }

        $deleteLocationQuery = "DELETE FROM lot_locations WHERE id = $location_id";
        $success = mysqli_query($conn, $deleteLocationQuery);

        header('Content-Type: application/json');
        echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Location deleted successfully' : mysqli_error($conn)
        ]);
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

          // FIXED: Removed non-existent columns from UPDATE logic
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

              // "sssssssssii"
              $stmt->bind_param(
                  "sssssssssii",
                  $first_name, $middle_name, $last_name, $email, $username,
                  $password, $phone, $address, $photo_path, $availability, $account_id
              );

          } else {
              // no password change
              $sql = "UPDATE admin_accounts 
                      SET first_name=?, middle_name=?, last_name=?, email=?, username=?,
                          phone=?, address=?, photo_path=?, availability=?
                      WHERE id=?";
              $stmt = $conn->prepare($sql);
              if (!$stmt) {
                  echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                  exit;
              }

                // "ssssssssii"
              $stmt->bind_param(
                  "ssssssssii",
                  $first_name, $middle_name, $last_name, $email, $username,
                  $phone, $address, $photo_path, $availability, $account_id
              );
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


  // =============================================
  // FETCH PAYMENTS & LOT OWNERS DATA
  // =============================================
  // All payments
  $allPayments = [];
  $pq = "SELECT p.id, p.user_id, p.lot_id, p.amount_paid, p.payment_date, p.payment_method,
                p.reference_no, p.status, p.remarks,
                u.first_name AS u_first, u.last_name AS u_last,
                l.block_number, l.lot_number, ll.location_name
         FROM payments p
         LEFT JOIN user_accounts u ON p.user_id = u.id
         LEFT JOIN lots l ON p.lot_id = l.id
         LEFT JOIN lot_locations ll ON l.location_id = ll.id
         ORDER BY p.payment_date DESC";
  $pqr = mysqli_query($conn, $pq);
  if ($pqr) { while ($pr = mysqli_fetch_assoc($pqr)) $allPayments[] = $pr; }

  // Lot owners: lots that are Sold or Reserved
  $lotOwners = [];
  $loq = "SELECT l.id, l.block_number, l.lot_number, l.lot_size, l.lot_price,
                 l.status, l.payment_type, l.payment_amount, l.balance, l.owner_id,
                 u.first_name AS o_first, u.last_name AS o_last, u.email AS o_email,
                 ll.location_name
          FROM lots l
          LEFT JOIN user_accounts u ON l.owner_id = u.id
          LEFT JOIN lot_locations ll ON l.location_id = ll.id
          WHERE l.status IN ('Sold','Reserved')
          ORDER BY l.id DESC";
  $loqr = mysqli_query($conn, $loq);
  if ($loqr) { while ($lo = mysqli_fetch_assoc($loqr)) $lotOwners[] = $lo; }

  // All users for owner assignment dropdown
  $allUsers = [];
  $auq = mysqli_query($conn, "SELECT id, first_name, last_name, email FROM user_accounts ORDER BY first_name");
  if ($auq) { while ($au = mysqli_fetch_assoc($auq)) $allUsers[] = $au; }

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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
          document.querySelectorAll('.alert.success, .alert.error').forEach(function(el) {
            el.style.display = 'none';
          });
        }, 3000);
      });
    </script>
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

      /* Unified UI controls: consistent buttons and form fields */
      button, input[type="button"], input[type="submit"], .btn {
        font-family: inherit;
        font-size: 15px;
        padding: 12px 20px;
        border-radius: 6px;
        min-width: 100px;
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
        align-items: stretch;
      }

      /* Make admin nav links match the user dashboard `.nav-link` appearance */
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
        margin: 6px 0;
        border-radius: 8px;
        justify-content: flex-start;
        width: 100%;
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

      .table-section thead tr:hover {
        background-color: transparent;
      }

      .table-section button, .btn {
        background-color: #2d482d;
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        transition: background-color 0.2s ease-in-out;
        margin: 0 2px;
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
        padding: 10px 18px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
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
        padding: 10px 18px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
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
        padding: 14px 36px;
        border-radius: 6px;
        font-size: 15px;
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

      .accounts-table td .btn-small {
        margin: 0;
      }

      .accounts-table td form {
        display: inline-flex;
        margin: 0;
      }

      .accounts-table tbody td:last-child {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
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

          <a data-target="section-notifications">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6V11a6 6 0 0 0-5-5.91V4a1 1 0 1 0-2 0v1.09A6 6 0 0 0 6 11v5l-2 2v1h16v-1l-2-2z"/>
            </svg>
            <span>Notifications</span>
          </a>
          <a data-target="section-audit-logs">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M9 2h6a2 2 0 0 1 2 2v2h2a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a1 1 0 0 1 1-1h2V4a2 2 0 0 1 2-2zm0 4h6V4H9v2zm-1 5h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2zm0 4h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2z"/>
            </svg>
            <span>Audit Logs</span>
          </a>
          <a data-target="section-documents">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V7h3.5L13 3.5zM8 11h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2zm0 4h8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2z"/>
            </svg>
            <span>Document Review</span>
          </a>
          <a data-target="section-payments">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 14H4V10h16v8zm0-10H4V6h16v2z"/>
            </svg>
            <span>Payments</span>
          </a>
          <a data-target="section-lot-owners">
            <svg width="24" height="24" viewBox="0 0 24 24" class="nav-icon" style="fill:white;">
              <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/>
            </svg>
            <span>Lot Owners</span>
          </a>

          <a href="#" onclick="confirmLogout()">
            <img src="assets/ic_baseline-logout.png" alt="Logout Icon" class="nav-icon logout-icon">
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>

    <div class="divider"></div>

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
        <div class="empty-state" style="
          background:#fafafa;
          padding:40px;
          text-align:center;
          border-radius:8px;
          font-size:18px;
          color:#666;
        ">
          <p>No admin accounts found in the database.</p>
        </div>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#14532d; color:#fff;">
              <th style="padding:12px 10px;">Name</th>
              <th style="padding:12px 10px;">Username</th>
              <th style="padding:12px 10px;">Email</th>
              <th style="padding:12px 10px;">Mobile</th>
              <th style="padding:12px 10px;">Address</th>
              <th style="padding:12px 10px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminAccounts as $account): ?>
              <tr style="border-bottom:1px solid #eee; background:#fff;">
                <td style="padding:10px 8px;"><strong><?php echo htmlspecialchars(trim(($account['first_name'] ?? '') . ' ' . ($account['middle_name'] ?? '') . ' ' . ($account['last_name'] ?? '')) ?: 'N/A'); ?></strong></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['username'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['email'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['phone'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($account['address'] ?? ''); ?></td>
                <td style="padding:10px 8px;">
                  <button onclick="viewProfile(<?php echo (int)$account['id']; ?>, 'admin')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                  <button onclick="editAccount(<?php echo (int)$account['id']; ?>, 'admin')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">Edit</button>
                  <button onclick="deleteAdmin(<?php echo (int)$account['id']; ?>)" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px;">Delete</button>
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
        <div class="empty-state" style="
          background:#fafafa;
          padding:40px;
          text-align:center;
          border-radius:8px;
          font-size:18px;
          color:#666;
        ">
          <p>No agent accounts found in the database.</p>
        </div>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#14532d; color:#fff;">
              <th style="padding:12px 10px;">Name</th>
              <th style="padding:12px 10px;">Username</th>
              <th style="padding:12px 10px;">Email</th>
              <th style="padding:12px 10px;">Mobile</th>
              <th style="padding:12px 10px;">Address</th>
              <th style="padding:12px 10px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agentAccounts as $agent): ?>
              <tr style="border-bottom:1px solid #eee; background:#fff;">
                <td style="padding:10px 8px;"><strong><?php echo htmlspecialchars(trim(($agent['first_name'] ?? '') . ' ' . ($agent['middle_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')) ?: 'N/A'); ?></strong></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['username'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['email'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['phone'] ?? ''); ?></td>
                <td style="padding:10px 8px;"><?php echo htmlspecialchars($agent['address'] ?? ''); ?></td>
                <td style="padding:10px 8px;">
                  <button onclick="viewProfile(<?php echo (int)$agent['id']; ?>, 'agent')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                  <button onclick="editAccount(<?php echo (int)$agent['id']; ?>, 'agent')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">Edit</button>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this agent account?');">
                    <input type="hidden" name="agent_action" value="delete">
                    <input type="hidden" name="agent_id" value="<?php echo (int)$agent['id']; ?>">
                    <button type="submit" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px;">Delete</button>
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
                    <button onclick="viewProfile(<?php echo $user['id']; ?>, 'user')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">View</button>
                    <button onclick="editAccount(<?php echo $user['id']; ?>, 'user')" class="btn-small" style="padding:10px 16px; font-size:13px; margin-right:3px;">Edit</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user account?')">
                      <input type="hidden" name="user_action" value="delete">
                      <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                      <button type="submit" class="btn-small btn-danger" style="padding:10px 16px; font-size:13px;">Delete</button>
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
    <select id="location_id" name="location_id" style="flex:1; min-width:250px;">
      <option value="" disabled selected>Please select a location first</option>
            </select>
            <button class="add-location-btn" onclick="openAddLocationModal()" style="background-color: #3e5f3e; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
              </svg>
              Add Location
            </button>
            <button class="delete-location-btn" onclick="deleteSelectedLocation()" style="background-color: #8a2d2d; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
              Delete Location
            </button>
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
                <th>Payment</th>
                <th>Action</th>
              </tr>
            </thead>

          <tbody id="lots-table-body">
    <tr id="new-row" class="hidden" style="display:none;">
      
      <td></td>

      <td><input type="text" id="block_number"></td>
      <td><input type="text" id="lot_number"></td>
      <td><input type="text" id="lot_size"></td>
      <td><input type="text" id="lot_price"></td>

      <td>
        <select id="status" onchange="togglePaymentFieldsByStatus(this.value)">
          <option value="Available">Available</option>
          <option value="Sold">Sold</option>
          <option value="Reserved">Reserved</option>
        </select>
      </td>

      <td>
        <select id="payment_type" onchange="toggleDownPaymentField(this.value)">
          <option value="Not Applicable" selected>Not Applicable</option>
          <option value="Fully Paid">Fully Paid</option>
          <option value="Down Payment">Down Payment</option>
        </select>
        <input type="number" id="payment_amount" step="0.01" min="0" placeholder="Down payment amount" style="display:none; margin-top:6px; width:100%;">
      </td>

      <td>
        <button onclick="saveLot()">Save</button>
        <button onclick="cancelAdd()">Cancel</button>
      </td>
    </tr>
  </tbody>
          </table>

        <div style="margin-top: 20px; display: flex; gap: 10px;">
          <button onclick="addNewLot()" class="btn-primary">+ Add New Lot</button>
          <button onclick="bulkDeleteLots()" class="btn-danger btn-primary">Delete Selected</button>
        </div>
      </div>

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

      <!-- Blueprint Pin Modal -->
      <div id="pinModal" style="
        display: none;
        position: fixed;
        z-index: 3000;
        left: 0; top: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.7);
        justify-content: center; align-items: center;
        overflow: auto;
        font-family: 'Segoe UI', sans-serif;
      ">
        <div style="
          background: #fff;
          border-radius: 8px;
          box-shadow: 0 5px 25px rgba(0,0,0,0.3);
          width: 95%; max-width: 1000px; position: relative;
          max-height: 90vh;
          overflow-y: auto;
          display: flex;
          flex-direction: column;
        ">
          <!-- Header -->
          <div style="
            padding: 20px 24px;
            background: #2d482d;
            color: white;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
          ">
            <h3 style="margin: 0; font-size: 18px;">
              Mapping: <span id="pinModalLotInfo"></span>
            </h3>
            <span onclick="closePinModal()" style="
              font-size: 28px;
              cursor: pointer;
              color: white;
              font-weight: bold;
            ">&times;</span>
          </div>

          <!-- Content -->
          <div style="
            padding: 20px 24px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
          ">
            <!-- Status Selection Buttons -->
            <div style="
              display: flex;
              gap: 12px;
              justify-content: center;
            ">
              <button id="statusBtn_Available" type="button" onclick="selectLotStatus('Available')" style="
                padding: 12px 24px;
                border: 2px solid #28a745;
                background: #28a745;
                color: white;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.3s;
              ">
                Available
              </button>
              <button id="statusBtn_Reserved" type="button" onclick="selectLotStatus('Reserved')" style="
                padding: 12px 24px;
                border: 2px solid #ffc107;
                background: white;
                color: #ffc107;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.3s;
              ">
                Reserved
              </button>
              <button id="statusBtn_Sold" type="button" onclick="selectLotStatus('Sold')" style="
                padding: 12px 24px;
                border: 2px solid #dc3545;
                background: white;
                color: #dc3545;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.3s;
              ">
                Sold
              </button>
            </div>

            <div style="
              background: #333;
              color: white;
              padding: 10px 14px;
              border-radius: 4px;
              font-size: 13px;
            ">
              Click each corner/edge point of the lot to trace its real shape. Click near the first point (or double-click) to close the polygon.
            </div>

            <!-- Blueprint Container -->
            <div style="
              position: relative;
              background: #f5f5f5;
              border: 1px solid #ddd;
              border-radius: 4px;
              overflow: hidden;
              flex: 1;
              display: flex;
              justify-content: center;
              align-items: center;
              min-height: 400px;
            ">
              <img id="blueprintImage" src="" alt="Blueprint" style="
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                cursor: crosshair;
              ">
              <canvas id="blueprintCanvas" style="
                position: absolute;
                top: 0;
                left: 0;
                cursor: crosshair;
                display: none;
              "></canvas>
            </div>
          </div>

          <!-- Footer with Buttons -->
          <div style="
            padding: 20px 24px;
            background: #f9f9f9;
            border-top: 1px solid #eee;
            border-radius: 0 0 8px 8px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
          ">
            <button onclick="toggleDrawingMode()" id="toggleDrawBtn" style="
              background: #28a745;
              color: white;
              padding: 10px 20px;
              border: none;
              border-radius: 4px;
              cursor: pointer;
              font-size: 14px;
              font-weight: 500;
            ">Start Drawing Polygon</button>
            <button onclick="closePinModal()" style="
              background: #6c757d;
              color: white;
              padding: 10px 20px;
              border: none;
              border-radius: 4px;
              cursor: pointer;
              font-size: 14px;
              font-weight: 500;
            ">Cancel</button>
            <button onclick="savePinLocation()" style="
              background: #2d482d;
              color: white;
              padding: 10px 20px;
              border: none;
              border-radius: 4px;
              cursor: pointer;
              font-size: 14px;
              font-weight: 500;
            ">Save Pin Location</button>
          </div>
        </div>
      </div>

      <!-- Add Location Modal -->
      <div id="addLocationModal" style="
        display: none;
        position: fixed;
        z-index: 2200;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        justify-content: center;
        align-items: center;
        overflow: auto;
      " class="location-modal">
        <div style="
          background-color: white;
          padding: 25px;
          border-radius: 10px;
          box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
          max-width: 800px;
          width: 90%;
          max-height: 90vh;
          overflow-y: auto;
        ">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #2d482d; font-size: 24px;">Add New Location</h3>
            <button onclick="closeAddLocationModal()" style="background: none; border: none; font-size: 32px; color: #aaa; cursor: pointer; padding: 0; width: 32px; height: 32px; line-height: 1; min-width: unset;">&times;</button>
          </div>

          <div style="background-color: #e8f5e9; color: #2e7d32; padding: 12px 15px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            Click anywhere on the map to pin the location
          </div>

          <div id="location-map" style="height: 400px; width: 100%; margin: 20px 0; border-radius: 8px; border: 1px solid #ddd;"></div>

          <form id="locationForm" onsubmit="saveNewLocation(event)">
            <div style="margin-bottom: 15px;">
              <label for="new_location_name" style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Location Name:</label>
              <input type="text" id="new_location_name" name="location_name" required style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Latitude:</label>
              <input type="text" id="new_latitude_display" readonly style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; background-color: #f5f5f5; color: #666;">
              <input type="hidden" id="new_latitude" name="latitude">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Longitude:</label>
              <input type="text" id="new_longitude_display" readonly style="width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; background-color: #f5f5f5; color: #666;">
              <input type="hidden" id="new_longitude" name="longitude">
            </div>
            <div style="margin-bottom: 15px;">
              <label style="display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px;">Blueprint Image (Optional):</label>
              <div id="blueprint-upload-area" style="border: 2px dashed #b0c4a8; border-radius: 8px; padding: 20px; text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s;" onclick="document.getElementById('new_blueprint_file').click()">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6b8f5e" stroke-width="1.5" style="margin-bottom: 8px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div id="blueprint-upload-text" style="color: #666; font-size: 13px;">Click to upload a blueprint image<br><span style="font-size: 12px; color: #999;">JPG, PNG, or GIF</span></div>
                <input type="file" id="new_blueprint_file" name="blueprint" accept="image/jpeg,image/png,image/gif" style="display:none;" onchange="previewBlueprintFile(this)">
              </div>
              <div id="blueprint-preview-container" style="display:none; margin-top: 10px; position: relative;">
                <img id="blueprint-preview-img" style="max-width: 100%; max-height: 200px; border-radius: 6px; border: 1px solid #ddd;" />
                <button type="button" onclick="removeBlueprintPreview()" style="position: absolute; top: 4px; right: 4px; width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.6); color: #fff; border: none; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center;">&times;</button>
              </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
              <button type="button" onclick="closeAddLocationModal()" style="background-color: #999; color: white; padding: 10px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;">Cancel</button>
              <button type="submit" style="background-color: #3e5f3e; color: white; padding: 10px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;">Save Location</button>
            </div>
          </form>
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
                  <tr style="border-bottom:1px solid #eee; background:#fff;">
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
                              <span style="font-size:15px;color:#195c36;font-weight:700;margin-top:2px;\"><?php echo htmlspecialchars($assignedAgent['first_name'] . ' ' . $assignedAgent['last_name']); ?></span>
                              <button type="button"
                                class="btn-small"
                                style="padding:10px 16px; font-size:13px; margin-top:8px;"
                                onclick="viewProfile(<?= $viewing['user_id'] ?: 0 ?>, 'user', event)">
                                View Client
                              </button>
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
                                <button type="submit" class="btn-small" style="padding:10px 16px; font-size:13px;">Assign</button>
                                <button type="button"
                                  class="btn-small"
                                  style="padding:10px 16px; font-size:13px;"
                                  onclick="viewProfile(<?= $viewing['user_id'] ?: 0 ?>, 'user', event)">
                                  View Client
                                </button>
                              </div>
                            <?php endif; ?>
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

      <!-- ============ PAYMENTS SECTION ============ -->
      <div id="section-payments" class="section hidden">
        <div class="header">
          <div>
            <h2>Payments</h2>
            <small>View all payment transactions and update their status</small>
          </div>
        </div>

        <div class="table-section">
          <?php if (empty($allPayments)): ?>
            <div style="background:#fafafa; padding:40px; text-align:center; border-radius:8px; font-size:18px; color:#666;">
              <p>No payment records found.</p>
            </div>
          <?php else: ?>
            <div style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="background:#14532d; color:#fff;">
                    <th style="padding:12px 10px;">Date</th>
                    <th style="padding:12px 10px;">Payer</th>
                    <th style="padding:12px 10px;">Lot</th>
                    <th style="padding:12px 10px;">Location</th>
                    <th style="padding:12px 10px;">Amount</th>
                    <th style="padding:12px 10px;">Method</th>
                    <th style="padding:12px 10px;">Reference</th>
                    <th style="padding:12px 10px;">Remarks</th>
                    <th style="padding:12px 10px;">Status</th>
                    <th style="padding:12px 10px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allPayments as $pay): ?>
                    <tr style="border-bottom:1px solid #eee; background:#fff;" id="pay-row-<?php echo (int)$pay['id']; ?>">
                      <td style="padding:10px 8px; white-space:nowrap;"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></td>
                      <td style="padding:10px 8px;"><strong><?php echo htmlspecialchars(trim(($pay['u_first'] ?? '') . ' ' . ($pay['u_last'] ?? '')) ?: 'Unknown'); ?></strong></td>
                      <td style="padding:10px 8px;">Blk <?php echo htmlspecialchars($pay['block_number'] ?? '-'); ?> Lot <?php echo htmlspecialchars($pay['lot_number'] ?? '-'); ?></td>
                      <td style="padding:10px 8px;"><?php echo htmlspecialchars($pay['location_name'] ?? '-'); ?></td>
                      <td style="padding:10px 8px; font-weight:600;">&#8369;<?php echo number_format((float)$pay['amount_paid'], 2); ?></td>
                      <td style="padding:10px 8px;"><?php echo htmlspecialchars($pay['payment_method'] ?? '-'); ?></td>
                      <td style="padding:10px 8px; font-size:12px;"><?php echo htmlspecialchars($pay['reference_no'] ?? '-'); ?></td>
                      <td style="padding:10px 8px; font-size:12px; max-width:150px; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($pay['remarks'] ?? '-'); ?></td>
                      <td style="padding:10px 8px;" id="pay-status-<?php echo (int)$pay['id']; ?>">
                        <?php
                          $pst = $pay['status'] ?? 'Pending';
                          $pstColor = $pst === 'Verified' ? 'background:#dcfce7;color:#166534;' : ($pst === 'Rejected' ? 'background:#fee2e2;color:#991b1b;' : 'background:#fef3c7;color:#92400e;');
                        ?>
                        <span style="padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600; <?php echo $pstColor; ?>"><?php echo htmlspecialchars($pst); ?></span>
                      </td>
                      <td style="padding:10px 8px; white-space:nowrap;">
                        <?php if (($pay['status'] ?? 'Pending') === 'Pending'): ?>
                          <button onclick="updatePaymentStatus(<?php echo (int)$pay['id']; ?>, 'Verified')" class="btn-small" style="padding:6px 12px; font-size:12px; margin-right:3px; background:#16a34a; color:#fff; border:none; border-radius:4px; cursor:pointer;">Verify</button>
                          <button onclick="updatePaymentStatus(<?php echo (int)$pay['id']; ?>, 'Rejected')" class="btn-small btn-danger" style="padding:6px 12px; font-size:12px; border:none; border-radius:4px; cursor:pointer;">Reject</button>
                        <?php else: ?>
                          <span style="color:#999; font-size:12px;">Done</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ============ LOT OWNERS SECTION ============ -->
      <div id="section-lot-owners" class="section hidden">
        <div class="header">
          <div>
            <h2>Lot Owners</h2>
            <small>View all sold/reserved lots, assign owners, and manage payment details</small>
          </div>
        </div>

        <div class="table-section">
          <?php if (empty($lotOwners)): ?>
            <div style="background:#fafafa; padding:40px; text-align:center; border-radius:8px; font-size:18px; color:#666;">
              <p>No sold or reserved lots found.</p>
            </div>
          <?php else: ?>
            <div style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="background:#14532d; color:#fff;">
                    <th style="padding:12px 10px;">Location</th>
                    <th style="padding:12px 10px;">Lot</th>
                    <th style="padding:12px 10px;">Price</th>
                    <th style="padding:12px 10px;">Status</th>
                    <th style="padding:12px 10px;">Payment Type</th>
                    <th style="padding:12px 10px;">Down Payment</th>
                    <th style="padding:12px 10px;">Balance Paid</th>
                    <th style="padding:12px 10px;">Remaining</th>
                    <th style="padding:12px 10px;">Owner</th>
                    <th style="padding:12px 10px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lotOwners as $lo): ?>
                    <?php
                      $lotPrice = (float)($lo['lot_price'] ?? 0);
                      $balPaid  = (float)($lo['balance'] ?? 0);
                      $remaining = max(0, $lotPrice - $balPaid);
                      $ownerName = trim(($lo['o_first'] ?? '') . ' ' . ($lo['o_last'] ?? ''));
                      $loStatus = $lo['status'] ?? '';
                      $statusColor = $loStatus === 'Sold' ? 'background:#fee2e2;color:#991b1b;' : 'background:#fef3c7;color:#92400e;';
                    ?>
                    <tr style="border-bottom:1px solid #eee; background:#fff;" id="lot-owner-row-<?php echo (int)$lo['id']; ?>">
                      <td style="padding:10px 8px;"><?php echo htmlspecialchars($lo['location_name'] ?? '-'); ?></td>
                      <td style="padding:10px 8px;"><strong>Blk <?php echo htmlspecialchars($lo['block_number']); ?> - Lot <?php echo htmlspecialchars($lo['lot_number']); ?></strong></td>
                      <td style="padding:10px 8px; font-weight:600;">&#8369;<?php echo number_format($lotPrice, 2); ?></td>
                      <td style="padding:10px 8px;"><span style="padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600; <?php echo $statusColor; ?>"><?php echo htmlspecialchars($loStatus); ?></span></td>
                      <td style="padding:10px 8px;">
                        <select id="paytype-<?php echo (int)$lo['id']; ?>" style="padding:4px 8px; border:1px solid #ddd; border-radius:4px; font-size:13px;">
                          <option value="Down Payment" <?php echo ($lo['payment_type'] ?? '') === 'Down Payment' ? 'selected' : ''; ?>>Down Payment</option>
                          <option value="Fully Paid" <?php echo ($lo['payment_type'] ?? '') === 'Fully Paid' ? 'selected' : ''; ?>>Fully Paid</option>
                          <option value="Not Applicable" <?php echo ($lo['payment_type'] ?? '') === 'Not Applicable' ? 'selected' : ''; ?>>Not Applicable</option>
                        </select>
                      </td>
                      <td style="padding:10px 8px;">&#8369;<?php echo number_format((float)($lo['payment_amount'] ?? 0), 2); ?></td>
                      <td style="padding:10px 8px; color:#16a34a; font-weight:600;">&#8369;<?php echo number_format($balPaid, 2); ?></td>
                      <td style="padding:10px 8px; color:<?php echo $remaining > 0 ? '#dc2626' : '#16a34a'; ?>; font-weight:600;">&#8369;<?php echo number_format($remaining, 2); ?></td>
                      <td style="padding:10px 8px;">
                        <select id="owner-<?php echo (int)$lo['id']; ?>" style="padding:4px 8px; border:1px solid #ddd; border-radius:4px; font-size:13px; max-width:160px;">
                          <option value="0">-- No owner --</option>
                          <?php foreach ($allUsers as $au): ?>
                            <option value="<?php echo (int)$au['id']; ?>" <?php echo ((int)($lo['owner_id'] ?? 0) === (int)$au['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($au['first_name'] . ' ' . $au['last_name']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td style="padding:10px 8px; white-space:nowrap;">
                        <button onclick="saveLotOwner(<?php echo (int)$lo['id']; ?>)" class="btn-small" style="padding:6px 12px; font-size:12px; margin-right:3px; background:#16a34a; color:#fff; border:none; border-radius:4px; cursor:pointer;">Save</button>
                        <button onclick="saveLotPaymentType(<?php echo (int)$lo['id']; ?>)" class="btn-small" style="padding:6px 12px; font-size:12px; background:#0284c7; color:#fff; border:none; border-radius:4px; cursor:pointer;">Update Payment</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div> </body>




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
      'section-audit-logs',
      'section-payments',
      'section-lot-owners'
    ];
    
    function showSection(targetId) {
      sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            const willShow = sectionId === targetId;
            // use `active` class like user dashboard for smooth transitions
            section.classList.toggle('active', willShow);
            // remove any legacy `hidden` class if present
            if (section.classList.contains('hidden')) section.classList.remove('hidden');
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
      
      analyticsNav.addEventListener('click', function(e) {
        e.preventDefault();
        showSection('section-analytics');
      });
    }

    // Show dashboard by default
    showSection('section-dashboard');

    // Lots location dropdown
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
      loadNotifications();
      refreshBadges();

      const docsSection  = document.getElementById('section-documents');
      const auditSection = document.getElementById('section-audit-logs');

      if (docsSection && docsSection.classList.contains('active')) {
        loadDocuments();
      }
      if (auditSection && auditSection.classList.contains('active')) {
        loadAuditLogs();
      }
    }, 20000);

    // ========= Edit Account modal submit =========
    const editAccountForm = document.getElementById('editAccountForm');
    if (editAccountForm) {
      editAccountForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(editAccountForm);
        const type = formData.get('account_type');

        if (type === 'admin') {
          formData.append('account_action', 'update');
        } else if (type === 'agent') {
          formData.append('agent_action', 'update');
        } else if (type === 'user') {
          formData.append('user_action', 'update');
        }

        fetch(window.location.pathname, {
          method: 'POST',
          body: formData
        })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert(res.message || 'Account updated!');
            closeEditAccountModal();
            location.reload();
          } else {
            alert('Failed to update account: ' + (res.error || res.message || 'Unknown error'));
          }
        })
        .catch(err => {
          console.error('Update fetch error:', err);
          alert('Failed to update account: ' + err.message);
        });
      });
    }

    // ========= Edit Lot modal submit =========
    const editLotForm = document.getElementById('editLotForm');
    if (editLotForm) {
      editLotForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(editLotForm);
        formData.append('action', 'save');
        fetch(window.location.pathname, { method: 'POST', body: formData })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              alert('Lot updated!');
              closeEditLotModal();
              const locSel = document.getElementById('location_id');
              loadLots(locSel ? locSel.value : '');
            } else {
              alert('Failed to update lot: ' + (res.error || 'Unknown error'));
            }
          })
          .catch(() => alert('Failed to update lot.'));
      });
    }

    // ========= Agent account create (AJAX) =========
    const agentForm = document.getElementById('agent-account-form');
    if (agentForm) {
      agentForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const pass   = document.getElementById('agent_password');
        const confirm = document.getElementById('agent_confirm_password');
        const error  = document.getElementById('agent-password-error');

        if (pass && confirm && error && pass.value !== confirm.value) {
          error.style.display = 'block';
          return;
        } else if (error) {
          error.style.display = 'none';
        }

        const formData = new FormData(agentForm);

        try {
          const response = await fetch(window.location.pathname, {
            method: "POST",
            body: formData
          });
          const result = await response.json();

          if (result.success) {
            alert(result.message || "✅ Agent account created successfully!");
            agentForm.reset();
            if (error) error.style.display = 'none';
          } else {
            alert("❌ Error: " + (result.message || "Failed to create account"));
          }
        } catch (err) {
          alert("❌ Request failed.");
          console.error(err);
        }
      });
    }

    // ========= Admin account create (AJAX) =========
    const adminForm = document.getElementById('admin-account-form');
    if (adminForm) {
      const adminPass    = document.getElementById('admin_password');
      const adminConfirm = document.getElementById('admin_confirm_password');
      const adminError   = document.getElementById('admin-password-error');

      if (adminPass && adminConfirm && adminError) {
        // live feedback
        function validateAdminPasswords() {
          if (adminPass.value && adminConfirm.value && adminPass.value !== adminConfirm.value) {
            adminError.style.display = 'block';
            adminConfirm.setCustomValidity('Passwords do not match');
          } else {
            adminError.style.display = 'none';
            adminConfirm.setCustomValidity('');
          }
        }

        adminPass.addEventListener('input', validateAdminPasswords);
        adminConfirm.addEventListener('input', validateAdminPasswords);

        adminForm.addEventListener('submit', function(e) {
          e.preventDefault();

          validateAdminPasswords();
          if (adminConfirm.validationMessage) {
            adminConfirm.reportValidity();
            return;
          }

          const formData = new FormData(adminForm);
          if (!formData.get('account_action')) {
            formData.append('account_action', 'add');
          }

          fetch(window.location.pathname, {
            method: 'POST',
            body: formData
          })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              alert(res.message || 'Admin account created successfully!');
              adminForm.reset();
              adminError.style.display = 'none';
            } else {
              alert(res.error || res.message || 'Failed to create admin account.');
            }
          })
          .catch(err => {
            alert('Request failed: ' + err.message);
          });
        });
      }
    }

    // ========= User password live check =========
    (function () {
      const form    = document.getElementById('user-account-form');
      const pass    = document.getElementById('user_password');
      const confirm = document.getElementById('user_confirm_password');
      const error   = document.getElementById('user-password-error');

      if (!form || !pass || !confirm || !error) return;

      function validateUserPasswords() {
        if (pass.value && confirm.value && pass.value !== confirm.value) {
          error.style.display = 'block';
          confirm.setCustomValidity('Passwords do not match');
        } else {
          error.style.display = 'none';
          confirm.setCustomValidity('');
        }
      }

      pass.addEventListener('input',    validateUserPasswords);
      confirm.addEventListener('input', validateUserPasswords);

      form.addEventListener('submit', function (e) {
        validateUserPasswords();
        if (confirm.validationMessage) {
          e.preventDefault();
          confirm.reportValidity();
        }
      });
    })();

    // ========= View Client modal close handlers =========
    const viewModal = document.getElementById('viewClientModal');
    if (viewModal) {
      viewModal.addEventListener('click', (e) => {
        if (e.target === viewModal) closeViewClientModal();
      });
    }
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeViewClientModal();
    });

    // expose globals
    window.loadLocations           = loadLocations;
    window.loadLots                = loadLots;
    window.saveLot                 = saveLot;
    window.editLot                 = editLot;
    window.saveEdit                = saveEdit;
    window.deleteLot               = deleteLot;
    window.addNewLot               = addNewLot;
    window.cancelAdd               = cancelAdd;
    window.cancelEdit              = cancelEdit;
    window.showAccountType         = showAccountType;
    window.confirmLogout           = confirmLogout;
    window.applyAnalyticsFilters   = applyAnalyticsFilters;
    window.loadTopAgents           = loadTopAgents;
    window.exportAnalytics         = exportAnalytics;
    window.loadDocuments           = loadDocuments;
    window.loadNotifications       = loadNotifications;
    window.loadAuditLogs           = loadAuditLogs;
    window.viewProfile             = viewProfile;
    window.closeViewClientModal    = closeViewClientModal;
    window.editAccount             = editAccount;
    window.closeEditAccountModal   = closeEditAccountModal;
    window.openEditLotModal        = openEditLotModal;
    window.closeEditLotModal       = closeEditLotModal;
    window.bulkDeleteLots          = bulkDeleteLots;
    window.openPinModal            = openPinModal;
    window.closePinModal           = closePinModal;
    window.toggleDrawingMode       = toggleDrawingMode;
    window.savePinLocation         = savePinLocation;
    window.selectLotStatus         = selectLotStatus;
    window.openAddLocationModal    = openAddLocationModal;
    window.closeAddLocationModal   = closeAddLocationModal;
    window.saveNewLocation         = saveNewLocation;
    window.deleteSelectedLocation  = deleteSelectedLocation;
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
        if (!tbody) return;

        if (newRow) newRow.remove();
        
        tbody.innerHTML = '';
        
        if (!data.length) {
          tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No lots available.</td></tr>';
        } else {
          data.forEach(lot => {
            const paymentType = lot.payment_type || (lot.status === 'Available' ? 'Not Applicable' : 'Fully Paid');
            const paymentText = paymentType === 'Down Payment'
              ? `Down Payment (PHP ${Number(lot.payment_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })})`
              : paymentType;
            const row = tbody.insertRow();
            row.setAttribute('data-id', lot.id);
            row.innerHTML = `
              <td><input type="checkbox" class="lot-checkbox" value="${lot.id}"></td>
              <td>${lot.block_number}</td>
              <td>${lot.lot_number}</td>
              <td>${lot.lot_size}</td>
              <td>${lot.lot_price}</td>
              <td>${lot.status}</td>
              <td>${paymentText}</td>
              <td style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button onclick='openPinModal(${lot.id}, ${JSON.stringify(lot)})' style="background: #dc3545; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Set Pin</button>
                <button onclick='openEditLotModal(${JSON.stringify(lot)})' style="background: #3e5f3e; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Edit</button>
                <button onclick="deleteLot(${lot.id})" style="background: #666; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Delete</button>
              </td>
            `;
          });
        }

        if (newRow) tbody.appendChild(newRow);
      })
      .catch(error => console.error('Error loading lots:', error));
  }

  function saveLot() {
    const fields = ['block_number', 'lot_number', 'lot_size', 'lot_price', 'status', 'payment_type'];
    const locationId = document.getElementById('location_id').value;
    const paymentType = document.getElementById('payment_type').value;
    const paymentAmountInput = document.getElementById('payment_amount');
    const paymentAmount = paymentAmountInput ? paymentAmountInput.value : '';
    
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

    if (paymentType === 'Down Payment') {
      if (!paymentAmount || isNaN(paymentAmount) || Number(paymentAmount) <= 0) {
        alert('Please enter a valid down payment amount.');
        return;
      }
    }

    const formData = new FormData();
    formData.append('action', 'save');
    Object.keys(data).forEach(key => formData.append(key, data[key]));
    formData.append('location_id', locationId);
    if (paymentType === 'Down Payment') {
      formData.append('payment_amount', paymentAmount);
    } else {
      formData.append('payment_amount', '');
    }

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          showLotMessage('Lot added successfully!', true);
          loadLots(locationId);
          cancelAdd();
        } else {
          alert('Error: ' + (result.error || result.message || 'Unknown error'));
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
          const locSel = document.getElementById('location_id');
          loadLots(locSel ? locSel.value : '');
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
    if (newRow) {
      newRow.style.display = 'table-row';
      const status = document.getElementById('status');
      togglePaymentFieldsByStatus(status ? status.value : 'Available');
    }
  }

  function cancelAdd() {
    const newRow = document.getElementById('new-row');
    if (!newRow) return;
    newRow.style.display = 'none';
    newRow.querySelectorAll('input').forEach(input => input.value = '');
    const status = document.getElementById('status');
    if (status) status.value = 'Available';
    const paymentType = document.getElementById('payment_type');
    if (paymentType) paymentType.value = 'Not Applicable';
    togglePaymentFieldsByStatus('Available');
  }

  function togglePaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('payment_type');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      toggleDownPaymentField('Not Applicable');
      return;
    }

    paymentTypeSelect.disabled = false;
    if (paymentTypeSelect.value === 'Not Applicable') {
      paymentTypeSelect.value = 'Fully Paid';
    }
    toggleDownPaymentField(paymentTypeSelect.value);
  }

  function toggleDownPaymentField(paymentType) {
    const paymentAmountInput = document.getElementById('payment_amount');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      paymentAmountInput.style.display = 'block';
      paymentAmountInput.required = true;
    } else {
      paymentAmountInput.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
    }
  }

  function toggleEditDownPaymentField(paymentType) {
    const paymentAmountInput = document.getElementById('edit_payment_amount');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      paymentAmountInput.style.display = 'block';
      paymentAmountInput.required = true;
    } else {
      paymentAmountInput.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
    }
  }

  function toggleEditPaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('edit_payment_type');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      toggleEditDownPaymentField('Not Applicable');
      return;
    }

    paymentTypeSelect.disabled = false;
    if (paymentTypeSelect.value === 'Not Applicable') {
      paymentTypeSelect.value = 'Fully Paid';
    }
    toggleEditDownPaymentField(paymentTypeSelect.value);
  }

  function cancelEdit() {
    const locSel = document.getElementById('location_id');
    loadLots(locSel ? locSel.value : '');
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
  // ===========================
  function loadDocuments() {
    const container = document.getElementById('documents-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading documents...</p>';

    fetch(window.location.pathname + '?fetch=all_user_documents')
      .then(response => response.json())
      .then(documents => {
        const docsBadge = document.getElementById('documents-badge');
        updateBadge(docsBadge, documents.length || 0);

        if (!documents.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No user documents found.</p>';
          return;
        }

        container.innerHTML = documents.map(doc => {
          const docId = doc.id ?? doc.doc_id ?? doc.document_id;
          const actionButtons = docId
            ? `<button class="btn btn-sm btn-primary" onclick="approveDocument(${docId})">Approve</button>
               <button class="btn btn-sm btn-danger"  onclick="rejectDocument(${docId})">Reject</button>`
            : '';

          return `
            <div data-doc-id="${docId ?? ''}" style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
              <strong>${doc.file_name || 'Untitled Document'}</strong>
              <div style="font-size: 13px; color: #333;">Type: ${doc.doc_type || 'N/A'}</div>
              <div style="font-size: 13px; color: #333;">User: ${doc.first_name || ''} ${doc.last_name || ''} (${doc.email || ''})</div>
              <div style="font-size: 12px; color: #999;">
                Uploaded: ${doc.uploaded_at ? new Date(doc.uploaded_at).toLocaleString() : 'N/A'}
              </div>
              <div style="font-size: 12px; color: #999;">
                Status: <span data-doc-status style="font-weight:600;">${doc.status || 'Pending'}</span>
              </div>
              <div data-doc-actions style="margin-top: 8px;">
                ${doc.file_path ? `<a href="${doc.file_path}" target="_blank" class="btn btn-sm btn-secondary">View</a>` : ''}
                ${actionButtons}
              </div>
            </div>
          `;
        }).join('');
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
          updateDocumentCardStatus(id, 'Approved');
          refreshBadges();
        } else {
          alert('Failed to approve document.');
        }
      })
      .catch(() => alert('Failed to approve document.'));
  }

  function rejectDocument(id) {
    const remarks = prompt('Enter remarks for rejection (optional):', '');
    if (remarks === null) return;

    const formData = new FormData();
    formData.append('action', 'reject_document');
    formData.append('doc_id', id);
    formData.append('remarks', remarks);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          alert('Document rejected.');
          updateDocumentCardStatus(id, 'Rejected');
          refreshBadges();
        } else {
          alert('Failed to reject document.');
        }
      })
      .catch(() => alert('Failed to reject document.'));
  }

  function updateDocumentCardStatus(id, statusText) {
    const row = document.querySelector(`[data-doc-id="${id}"]`);
    if (!row) return;

    const statusEl = row.querySelector('[data-doc-status]');
    if (statusEl) {
      statusEl.textContent = statusText;
    }

    const actions = row.querySelector('[data-doc-actions]');
    if (actions) {
      actions.innerHTML = '<span style="color:#2d482d;font-weight:600;">✓ ' + statusText + '</span>';
    }
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
              By: ${log.first_name || ''} ${log.last_name || ''} • ${log.created_at ? new Date(log.created_at).toLocaleDateString() + ' ' + new Date(log.created_at).toLocaleTimeString() : ''}
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
    const sec = document.getElementById(type + '-accounts');
    const tab = document.getElementById(type + '-tab');
    if (sec) sec.classList.add('active');
    if (tab) tab.classList.add('active');
  }

  // Photo and location functions
  function previewPhoto(input, previewId) {
    const preview = document.getElementById(previewId);
    const file = input.files[0];
    
    if (file && preview) {
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="photo-preview">`;
      };
      reader.readAsDataURL(file);
    } else if (preview) {
      preview.innerHTML = 'No Photo';
      preview.className = 'photo-placeholder';
    }
  }

  function getCurrentLocation() {
    const statusDiv = document.getElementById('location-status');
    if (!statusDiv) return;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          const lat = document.getElementById('latitude');
          const lng = document.getElementById('longitude');
          if (lat && lng) {
            lat.value = position.coords.latitude;
            lng.value = position.coords.longitude;
          }
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
    const lat = document.getElementById('latitude');
    const lng = document.getElementById('longitude');
    const status = document.getElementById('location-status');
    if (lat) lat.value = '';
    if (lng) lng.value = '';
    if (status) status.style.display = 'none';
  }

  function getCurrentLocationAgent() {
    const statusDiv = document.getElementById('agent-location-status');
    if (!statusDiv) return;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = 'Getting location...';
    
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        position => {
          const lat = document.getElementById('agent_latitude');
          const lng = document.getElementById('agent_longitude');
          if (lat && lng) {
            lat.value = position.coords.latitude;
            lng.value = position.coords.longitude;
          }
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
    const lat = document.getElementById('agent_latitude');
    const lng = document.getElementById('agent_longitude');
    const status = document.getElementById('agent-location-status');
    if (lat) lat.value = '';
    if (lng) lng.value = '';
    if (status) status.style.display = 'none';
  }

  // ===========================
  // VIEW CLIENT MODAL
  // ===========================
  function viewProfile(id, type, evt) {
      // Hide modal after 3 seconds
      setTimeout(() => {
        modal.style.display = 'none';
      }, 3000);
    if (evt && typeof evt.preventDefault === 'function') evt.preventDefault();
    const modal   = document.getElementById('viewClientModal');
    const content = document.getElementById('viewClientContent');
    const title   = modal?.querySelector('h3');
    if (!modal || !content || !title) return;

    // Set modal title based on type
    let profileTitle = 'Profile';
    if (type === 'admin') profileTitle = 'Admin Profile';
    else if (type === 'agent') profileTitle = 'Agent Profile';
    else if (type === 'user') profileTitle = 'User Profile';
    else profileTitle = 'Client Profile';
    title.textContent = profileTitle;

    modal.style.display = 'flex';
    content.innerHTML = '<div style="color:#666;">Loading details…</div>';

    // guest (no account) from table row
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

    // Registered user/admin/agent via endpoint
    if ((type === 'user' || type === 'admin' || type === 'agent') && id) {
      fetch(window.location.pathname + `?fetch=${type}&id=` + encodeURIComponent(id))
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(account => {
          if (account && account.id) {
            let html = `<strong>Name:</strong> ${account.first_name || ''} ${account.middle_name ? account.middle_name + ' ' : ''}${account.last_name || ''}<br>`;
            if (account.username) html += `<strong>Username:</strong> ${account.username}<br>`;
            html += `<strong>Email:</strong> ${account.email || 'N/A'}<br>`;
            if (account.phone) html += `<strong>Mobile:</strong> ${account.phone}<br>`;
            if (account.mobile_number) html += `<strong>Mobile:</strong> ${account.mobile_number}<br>`;
            html += `<strong>Address:</strong> ${account.address || 'N/A'}<br>`;
            if (account.created_at) {
              const created = new Date(account.created_at).toLocaleDateString();
              html += `<strong>Registered:</strong> ${created}<br>`;
            }
            content.innerHTML = html;
          } else {
            content.innerHTML = `<div style=\"color:#dc3545;\">Client not found.</div>`;
          }
        })
        .catch(err => {
          content.innerHTML = `<div style=\"color:#dc3545;\">Error loading client: ${err.message}</div>`;
        });
    }
  }

  function closeViewClientModal() {
    const modal = document.getElementById('viewClientModal');
    if (modal) modal.style.display = 'none';
  }

  // ===========================
  // PAYMENTS & LOT OWNERS
  // ===========================
  function updatePaymentStatus(payId, newStatus) {
    if (!confirm('Set this payment to "' + newStatus + '"?')) return;
    const formData = new FormData();
    formData.append('action', 'update_payment_status');
    formData.append('payment_id', payId);
    formData.append('new_status', newStatus);
    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          const colors = { Verified: 'background:#dcfce7;color:#166534;', Rejected: 'background:#fee2e2;color:#991b1b;' };
          const cell = document.getElementById('pay-status-' + payId);
          if (cell) cell.innerHTML = '<span style="padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;' + (colors[newStatus]||'') + '">' + newStatus + '</span>';
          // Replace action buttons with "Done"
          const row = document.getElementById('pay-row-' + payId);
          if (row) { const lastTd = row.querySelector('td:last-child'); if (lastTd) lastTd.innerHTML = '<span style="color:#999;font-size:12px;">Done</span>'; }
        } else {
          alert('Failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Request failed'));
  }

  function saveLotOwner(lotId) {
    const sel = document.getElementById('owner-' + lotId);
    if (!sel) return;
    const formData = new FormData();
    formData.append('action', 'assign_lot_owner');
    formData.append('lot_id', lotId);
    formData.append('owner_id', sel.value);
    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          alert('Owner updated successfully!');
        } else {
          alert('Failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Request failed'));
  }

  function saveLotPaymentType(lotId) {
    const sel = document.getElementById('paytype-' + lotId);
    if (!sel) return;
    const formData = new FormData();
    formData.append('action', 'update_lot_payment');
    formData.append('lot_id', lotId);
    formData.append('payment_type', sel.value);
    formData.append('payment_amount', 0);
    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          alert('Payment type updated!');
        } else {
          alert('Failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Request failed'));
  }

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
    const totalSalesEl = document.getElementById('kpi-total-sales');
    const totalLotsEl  = document.getElementById('kpi-total-lots');
    const agentsEl     = document.getElementById('kpi-available-agents');
    const pendingEl    = document.getElementById('kpi-pending-documents');

    if (totalSalesEl) {
      totalSalesEl.textContent =
        '₱' + (<?php echo isset($dashboard_stats["total_sales"]) ? $dashboard_stats["total_sales"] : 0; ?>).toLocaleString();
    }
    if (totalLotsEl)  totalLotsEl.textContent  = '<?php echo $dashboard_stats["lots"]; ?>';
    if (agentsEl)     agentsEl.textContent     = '<?php echo $dashboard_stats["agents"]; ?>';
    if (pendingEl)    pendingEl.textContent    = '<?php echo $dashboard_stats["pending_documents"]; ?>';
  }

  function loadTopAgents(page = 1) {
    const loading  = document.getElementById('top-agents-loading');
    const content  = document.getElementById('top-agents-content');
    const tbody    = document.getElementById('top-agents-tbody');

    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';

    const dateFrom   = document.getElementById('analytics_date_from')?.value;
    const dateTo     = document.getElementById('analytics_date_to')?.value;
    const locationId = document.getElementById('analytics_location')?.value;

    const params = new URLSearchParams();
    params.append('fetch', 'top_agents');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(agents => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (!tbody) return;

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
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#dc3545;">Failed to load agent data.</td></tr>`;
        }
        console.error(err);
      });
  }

  const monthlySalesData = <?php echo json_encode($monthly_sales); ?>; 

  function loadMonthlySalesChart() {
    const canvas = document.getElementById('monthly-sales-chart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

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

    ctx.strokeStyle = '#ddd';
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, canvas.height - padding);
    ctx.lineTo(canvas.width - padding, canvas.height - padding);
    ctx.stroke();

    const maxAmount = Math.max(...data.map(item => item.amount), 1);
    const barWidth = width / data.length / 2;
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

  function applyAnalyticsFilters() {
    const dateFrom   = document.getElementById('analytics_date_from')?.value;
    const dateTo     = document.getElementById('analytics_date_to')?.value;
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

  function updateMonthlySalesChart(monthlySalesData) {
    const canvas = document.getElementById('monthly-sales-chart');
    if (!canvas) return;
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
      form.reset();
      const previews = form.querySelectorAll('.photo-placeholder, .photo-preview');
      previews.forEach(preview => {
        preview.innerHTML = 'No Photo';
        preview.className = 'photo-placeholder';
      });
    }
  }

  // ===========================
  // EDIT ACCOUNT MODAL
  // ===========================
  function editAccount(id, type = 'admin') {
    const modal     = document.getElementById('editAccountModal');
    const fieldsDiv = document.getElementById('editAccountFields');
    const photoDiv  = document.getElementById('editAccountPhotoSection');

    if (!modal || !fieldsDiv || !photoDiv) return;

    document.getElementById('edit_account_id').value   = id;
    document.getElementById('edit_account_type').value = type;

    modal.style.display = 'flex';
    fieldsDiv.innerHTML = '<div style="color:#666;">Loading account details…</div>';
    photoDiv.innerHTML  = '';

    const url = `${window.location.pathname}?fetch=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;

    fetch(url)
      .then(r => r.text())
      .then(text => {
        let account;
        try {
          account = JSON.parse(text);
        } catch (e) {
          console.error('Raw response from server:', text);
          fieldsDiv.innerHTML =
            '<div style="color:#dc3545;">Error loading account: Unexpected non-JSON response from server.</div>';
          return;
        }

        if (!account || !account.id) {
          fieldsDiv.innerHTML = '<div style="color:#dc3545;">Account not found.</div>';
          return;
        }

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

        // Username (always show for admin, agent, user)
        html += `
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="${account.username || ''}" required>
          </div>
        `;

        html += `
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="${account.email || ''}" required>
          </div>
        `;

        if (type === 'agent') {
          html += `
            <div class="form-group">
              <label>Role</label>
              <input type="text" name="role" value="${account.role || ''}">
            </div>
          `;
        }

        if (type === 'agent') {
          html += `
            <div class="form-group">
              <label>Phone Number</label>
              <input type="text" name="phone" value="${account.phone || ''}">
            </div>
          `;
        }

        if (type === 'user') {
          html += `
            <div class="form-group">
              <label>Mobile Number</label>
              <input type="text" name="mobile_number" value="${account.mobile_number || ''}">
            </div>
          `;
        }

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
      })
      .catch(err => {
        fieldsDiv.innerHTML =
          `<div style="color:#dc3545;">Error loading account: ${err.message}</div>`;
      });
  }

  function closeEditAccountModal() {
    const modal = document.getElementById('editAccountModal');
    if (modal) modal.style.display = 'none';
  }

  // ===========================
  // EDIT LOT MODAL
  // ===========================
  function openEditLotModal(lot) {
    const modal     = document.getElementById('editLotModal');
    const fieldsDiv = document.getElementById('editLotFields');
    const idInput   = document.getElementById('edit_lot_id');
    if (!modal || !fieldsDiv || !idInput) return;

    idInput.value = lot.id;

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
        <select name="status" id="edit_status" onchange="toggleEditPaymentFieldsByStatus(this.value)" required>
          <option value="Available" ${lot.status === 'Available' ? 'selected' : ''}>Available</option>
          <option value="Sold" ${lot.status === 'Sold' ? 'selected' : ''}>Sold</option>
          <option value="Reserved" ${lot.status === 'Reserved' ? 'selected' : ''}>Reserved</option>
        </select>
      </div>
      <div class="form-group">
        <label>Payment</label>
        <select name="payment_type" id="edit_payment_type" onchange="toggleEditDownPaymentField(this.value)" required>
          <option value="Not Applicable" ${(lot.payment_type || 'Not Applicable') === 'Not Applicable' ? 'selected' : ''}>Not Applicable</option>
          <option value="Fully Paid" ${(lot.payment_type || 'Fully Paid') === 'Fully Paid' ? 'selected' : ''}>Fully Paid</option>
          <option value="Down Payment" ${(lot.payment_type || 'Fully Paid') === 'Down Payment' ? 'selected' : ''}>Down Payment</option>
        </select>
      </div>
      <div class="form-group">
        <label>Down Payment Amount</label>
        <input type="number" step="0.01" min="0" name="payment_amount" id="edit_payment_amount" value="${lot.payment_amount || ''}" placeholder="Enter down payment amount">
      </div>
      <div class="form-group">
        <label>Location ID</label>
        <input type="text" name="location_id" value="${lot.location_id || ''}" required>
      </div>
    `;
    toggleEditPaymentFieldsByStatus(lot.status || 'Available');
    modal.style.display = 'flex';
  }

  function closeEditLotModal() {
    const modal = document.getElementById('editLotModal');
    if (modal) modal.style.display = 'none';
  }

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
          const locSel = document.getElementById('location_id');
          loadLots(locSel ? locSel.value : '');
        } else {
          alert('Failed to delete lots: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to delete lots.'));
  }

  // ===========================
  // PIN LOCATION MANAGEMENT
  // ===========================
  let pinModalData = {
    lot_id: null,
    lot: null,
    canvas: null,
    ctx: null,
    isDrawingMode: false,
    polygonPoints: [],
    otherPins: [],
    hoverPoint: null,
    isPolygonClosed: false,
    canvasOffsetX: 0,
    canvasOffsetY: 0,
    selectedStatus: 'Available'
  };

  function selectLotStatus(status) {
    pinModalData.selectedStatus = status;
    
    // Update button styles
    const buttons = ['Available', 'Reserved', 'Sold'];
    buttons.forEach(btn => {
      const buttonEl = document.getElementById(`statusBtn_${btn}`);
      if (!buttonEl) return;
      
      if (btn === status) {
        // Active state
        buttonEl.style.background = {
          'Available': '#28a745',
          'Reserved': '#ffc107',
          'Sold': '#dc3545'
        }[btn];
        buttonEl.style.color = btn === 'Reserved' ? '#333' : 'white';
      } else {
        // Inactive state
        buttonEl.style.background = 'white';
        buttonEl.style.color = {
          'Available': '#28a745',
          'Reserved': '#ffc107',
          'Sold': '#dc3545'
        }[btn];
      }
    });

    if (pinModalData.canvas && pinModalData.polygonPoints.length > 0) {
      redrawPolygon();
    }
  }

  function openPinModal(lotId, lot) {
    const modal = document.getElementById('pinModal');
    if (!modal) return;

    pinModalData.lot_id = lotId;
    pinModalData.lot = lot;
    pinModalData.polygonPoints = [];
    pinModalData.otherPins = [];
    pinModalData.hoverPoint = null;
    pinModalData.isPolygonClosed = false;
    pinModalData.isDrawingMode = false;
    pinModalData.selectedStatus = lot.status || 'Available';

    // Update header
    const lotInfo = document.getElementById('pinModalLotInfo');
    if (lotInfo) {
      lotInfo.textContent = `Block ${lot.block_number} - Lot ${lot.lot_number}`;
    }

    // Set the status button to match the lot's current status
    selectLotStatus(pinModalData.selectedStatus);

    // Fetch blueprint
    fetch(`${window.location.pathname}?fetch=blueprint&lot_id=${lotId}`)
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          alert('Failed to load blueprint');
          return;
        }

        const imgElement = document.getElementById('blueprintImage');
        const canvas = document.getElementById('blueprintCanvas');

        if (!imgElement || !canvas) return;

        // Load image
        if (data.blueprint) {
          imgElement.src = data.blueprint;
          imgElement.onload = function() {
            setupCanvas();

            const allPins = Array.isArray(data.all_pins) ? data.all_pins : [];
            const currentPin = allPins.find(p => Number(p.lot_id) === Number(lotId));

            pinModalData.otherPins = allPins
              .filter(p => Number(p.lot_id) !== Number(lotId))
              .map(p => ({
                lot_id: Number(p.lot_id),
                points: Array.isArray(p.coordinates)
                  ? p.coordinates.map(pt => ({ x: pt.x, y: pt.y }))
                  : [],
                status: p.pin_status || 'Available'
              }))
              .filter(p => p.points.length > 0);

            if (currentPin && Array.isArray(currentPin.coordinates) && currentPin.coordinates.length > 0) {
              pinModalData.polygonPoints = currentPin.coordinates.map(p => ({ x: p.x, y: p.y }));
              pinModalData.isPolygonClosed = true;
              pinModalData.selectedStatus = currentPin.pin_status || pinModalData.selectedStatus;
              selectLotStatus(pinModalData.selectedStatus);
            } else if (data.pin && data.pin.length > 0) {
              pinModalData.polygonPoints = data.pin.map(p => ({ x: p.x, y: p.y }));
              pinModalData.isPolygonClosed = true;
              if (data.pin_status) {
                pinModalData.selectedStatus = data.pin_status;
                selectLotStatus(data.pin_status);
              }
            }

            if (pinModalData.otherPins.length > 0 || pinModalData.polygonPoints.length > 0) {
              canvas.style.display = 'block';
              redrawPolygon();
            }
          };
        } else {
          alert('No blueprint found for this location');
        }
      })
      .catch(err => {
        console.error('Error loading blueprint:', err);
        alert('Error loading blueprint');
      });

    modal.style.display = 'flex';
  }

  function setupCanvas() {
    const img = document.getElementById('blueprintImage');
    const canvas = document.getElementById('blueprintCanvas');
    const container = img.parentElement;

    if (!img || !canvas || !container) return;

    canvas.width = img.offsetWidth;
    canvas.height = img.offsetHeight;

    pinModalData.canvas = canvas;
    pinModalData.ctx = canvas.getContext('2d');
    pinModalData.canvasOffsetX = container.offsetLeft;
    pinModalData.canvasOffsetY = container.offsetTop;

    // Add event listeners (reset first to avoid duplicate bindings)
    canvas.removeEventListener('mousedown', onCanvasMouseDown);
    canvas.removeEventListener('mousemove', onCanvasMouseMove);
    canvas.removeEventListener('mouseup', onCanvasMouseUp);
    canvas.removeEventListener('dblclick', onCanvasDoubleClick);
    canvas.addEventListener('mousedown', onCanvasMouseDown);
    canvas.addEventListener('mousemove', onCanvasMouseMove);
    canvas.addEventListener('mouseup', onCanvasMouseUp);
    canvas.addEventListener('dblclick', onCanvasDoubleClick);
  }

  function toggleDrawingMode() {
    const btn = document.getElementById('toggleDrawBtn');
    const canvas = document.getElementById('blueprintCanvas');

    if (!btn || !canvas) return;

    pinModalData.isDrawingMode = !pinModalData.isDrawingMode;

    if (pinModalData.isDrawingMode) {
      btn.textContent = 'Stop Drawing';
      btn.style.background = '#dc3545';
      canvas.style.display = 'block';
      pinModalData.polygonPoints = [];
      pinModalData.hoverPoint = null;
      pinModalData.isPolygonClosed = false;
      redrawPolygon();
    } else {
      btn.textContent = 'Start Drawing Polygon';
      btn.style.background = '#28a745';
      pinModalData.hoverPoint = null;
      redrawPolygon();
    }
  }

  function onCanvasMouseDown(e) {
    if (!pinModalData.isDrawingMode || !pinModalData.canvas) return;

    const rect = pinModalData.canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    if (pinModalData.isPolygonClosed) {
      pinModalData.polygonPoints = [];
      pinModalData.isPolygonClosed = false;
    }

    if (pinModalData.polygonPoints.length >= 3) {
      const first = pinModalData.polygonPoints[0];
      const dist = Math.hypot(first.x - x, first.y - y);
      if (dist <= 10) {
        pinModalData.isPolygonClosed = true;
        redrawPolygon();
        return;
      }
    }

    pinModalData.polygonPoints.push({ x, y });
    redrawPolygon();
  }

  function getStatusColor(status) {
    const colors = {
      'Available': { stroke: '#28a745', fill: 'rgba(40, 167, 69, 0.2)' },
      'Reserved': { stroke: '#ffc107', fill: 'rgba(255, 193, 7, 0.2)' },
      'Sold': { stroke: '#dc3545', fill: 'rgba(220, 53, 69, 0.2)' }
    };
    return colors[status] || colors['Available'];
  }

  function onCanvasMouseMove(e) {
    if (!pinModalData.isDrawingMode || !pinModalData.ctx || !pinModalData.canvas) return;

    const rect = pinModalData.canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    pinModalData.hoverPoint = { x, y };
    redrawPolygon();
  }

  function onCanvasMouseUp() {}

  function onCanvasDoubleClick(e) {
    if (!pinModalData.isDrawingMode || pinModalData.polygonPoints.length < 3) return;
    e.preventDefault();
    pinModalData.isPolygonClosed = true;
    redrawPolygon();
  }

  function redrawPolygon() {
    if (!pinModalData.canvas || !pinModalData.ctx) return;

    pinModalData.ctx.clearRect(0, 0, pinModalData.canvas.width, pinModalData.canvas.height);

    // Draw all other saved pins on this blueprint
    pinModalData.otherPins.forEach(pin => {
      if (!Array.isArray(pin.points) || pin.points.length === 0) return;
      const colors = getStatusColor(pin.status);

      pinModalData.ctx.fillStyle = colors.fill;
      pinModalData.ctx.strokeStyle = colors.stroke;
      pinModalData.ctx.lineWidth = 2;

      pinModalData.ctx.beginPath();
      pinModalData.ctx.moveTo(pin.points[0].x, pin.points[0].y);
      for (let i = 1; i < pin.points.length; i++) {
        pinModalData.ctx.lineTo(pin.points[i].x, pin.points[i].y);
      }
      pinModalData.ctx.closePath();
      pinModalData.ctx.fill();
      pinModalData.ctx.stroke();
    });

    if (pinModalData.polygonPoints.length > 0) {
      const colors = getStatusColor(pinModalData.selectedStatus);
      pinModalData.ctx.fillStyle = colors.fill;
      pinModalData.ctx.strokeStyle = colors.stroke;
      pinModalData.ctx.lineWidth = 2;

      pinModalData.ctx.beginPath();
      pinModalData.ctx.moveTo(pinModalData.polygonPoints[0].x, pinModalData.polygonPoints[0].y);

      for (let i = 1; i < pinModalData.polygonPoints.length; i++) {
        pinModalData.ctx.lineTo(pinModalData.polygonPoints[i].x, pinModalData.polygonPoints[i].y);
      }

      if (pinModalData.isPolygonClosed) {
        pinModalData.ctx.closePath();
        pinModalData.ctx.fill();
      }
      pinModalData.ctx.stroke();

      // Vertex markers (for precise corner placement)
      pinModalData.polygonPoints.forEach(point => {
        pinModalData.ctx.beginPath();
        pinModalData.ctx.arc(point.x, point.y, 4, 0, Math.PI * 2);
        pinModalData.ctx.fillStyle = '#ffffff';
        pinModalData.ctx.fill();
        pinModalData.ctx.strokeStyle = colors.stroke;
        pinModalData.ctx.lineWidth = 2;
        pinModalData.ctx.stroke();
      });

      // Preview segment from last point to cursor while drawing
      if (pinModalData.isDrawingMode && !pinModalData.isPolygonClosed && pinModalData.hoverPoint) {
        const lastPoint = pinModalData.polygonPoints[pinModalData.polygonPoints.length - 1];
        pinModalData.ctx.beginPath();
        pinModalData.ctx.moveTo(lastPoint.x, lastPoint.y);
        pinModalData.ctx.lineTo(pinModalData.hoverPoint.x, pinModalData.hoverPoint.y);
        pinModalData.ctx.strokeStyle = colors.stroke;
        pinModalData.ctx.lineWidth = 1.5;
        pinModalData.ctx.setLineDash([4, 4]);
        pinModalData.ctx.stroke();
        pinModalData.ctx.setLineDash([]);
      }
    }
  }

  function savePinLocation() {
    if (!pinModalData.lot_id) {
      alert('No lot selected');
      return;
    }

    if (pinModalData.polygonPoints.length < 3) {
      alert('Please place at least 3 points to form a lot shape.');
      return;
    }

    if (!pinModalData.isPolygonClosed) {
      pinModalData.isPolygonClosed = true;
      redrawPolygon();
    }

    const formData = new FormData();
    formData.append('action', 'save_pin');
    formData.append('lot_id', pinModalData.lot_id);
    formData.append('polygon_coordinates', JSON.stringify(pinModalData.polygonPoints));
    formData.append('pin_status', pinModalData.selectedStatus);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          alert('Pin location saved successfully!');
          closePinModal();
          // Reload the lots to update the view
          const locSel = document.getElementById('location_id');
          loadLots(locSel ? locSel.value : '');
        } else {
          alert('Failed to save pin location: ' + (res.message || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Error saving pin:', err);
        alert('Error saving pin location');
      });
  }

  function closePinModal() {
    const modal = document.getElementById('pinModal');
    if (modal) {
      modal.style.display = 'none';
      // Reset drawing mode
      const btn = document.getElementById('toggleDrawBtn');
      if (btn) {
        btn.textContent = 'Start Drawing Polygon';
        btn.style.background = '#28a745';
      }
      const canvas = document.getElementById('blueprintCanvas');
      if (canvas) canvas.style.display = 'none';
      pinModalData.isDrawingMode = false;
      pinModalData.polygonPoints = [];
      pinModalData.otherPins = [];
      pinModalData.hoverPoint = null;
      pinModalData.isPolygonClosed = false;
    }
  }

  // ===========================
  // ADD LOCATION FUNCTIONS
  // ===========================
  let locationMap = null;
  let locationMarker = null;

  function openAddLocationModal() {
    const modal = document.getElementById('addLocationModal');
    modal.style.display = 'flex';
    
    // Initialize map after modal is visible
    setTimeout(() => {
      if (!locationMap) {
        locationMap = L.map('location-map').setView([6.9214, 122.0790], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '© OpenStreetMap contributors'
        }).addTo(locationMap);

        locationMap.on('click', function(e) {
          const { lat, lng } = e.latlng;
          
          // Update form fields
          document.getElementById('new_latitude').value = lat;
          document.getElementById('new_longitude').value = lng;
          document.getElementById('new_latitude_display').value = lat.toFixed(6);
          document.getElementById('new_longitude_display').value = lng.toFixed(6);

          // Remove previous marker if exists
          if (locationMarker) {
            locationMap.removeLayer(locationMarker);
          }

          // Add new marker
          locationMarker = L.marker([lat, lng], {
            icon: L.icon({
              iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
              shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
              iconSize: [25, 41],
              iconAnchor: [12, 41],
              popupAnchor: [1, -34],
              shadowSize: [41, 41]
            })
          }).addTo(locationMap);

          // Reverse geocoding
          fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
            .then(response => response.json())
            .then(data => {
              const name = data.display_name || 'Unknown location';
              document.getElementById('new_location_name').value = name;
            })
            .catch(() => {
              document.getElementById('new_location_name').value = 'Unknown';
            });
        });
      } else {
        locationMap.invalidateSize();
      }
    }, 100);
  }

  function closeAddLocationModal() {
    const modal = document.getElementById('addLocationModal');
    modal.style.display = 'none';
    
    // Clear form
    document.getElementById('locationForm').reset();
    document.getElementById('new_latitude_display').value = '';
    document.getElementById('new_longitude_display').value = '';
    removeBlueprintPreview();
    
    // Remove marker
    if (locationMarker && locationMap) {
      locationMap.removeLayer(locationMarker);
      locationMarker = null;
    }
  }

  function previewBlueprintFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('blueprint-preview-img').src = e.target.result;
      document.getElementById('blueprint-preview-container').style.display = 'block';
      document.getElementById('blueprint-upload-area').style.borderColor = '#3e5f3e';
      document.getElementById('blueprint-upload-text').innerHTML = '<strong style="color:#3e5f3e;">' + file.name + '</strong>';
    };
    reader.readAsDataURL(file);
  }

  function removeBlueprintPreview() {
    document.getElementById('new_blueprint_file').value = '';
    document.getElementById('blueprint-preview-container').style.display = 'none';
    document.getElementById('blueprint-upload-area').style.borderColor = '#b0c4a8';
    document.getElementById('blueprint-upload-text').innerHTML = 'Click to upload a blueprint image<br><span style="font-size:12px;color:#999;">JPG, PNG, or GIF</span>';
  }

  function saveNewLocation(event) {
    event.preventDefault();
    
    const locationName = document.getElementById('new_location_name').value;
    const latitude = document.getElementById('new_latitude').value;
    const longitude = document.getElementById('new_longitude').value;

    if (!latitude || !longitude) {
      alert('Please click on the map to select a location.');
      return;
    }

    const formData = new FormData();
    formData.append('action', 'save_location');
    formData.append('location_name', locationName);
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);

    const blueprintFile = document.getElementById('new_blueprint_file').files[0];
    if (blueprintFile) {
      formData.append('blueprint', blueprintFile);
    }

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('Location added successfully!');
        closeAddLocationModal();
        loadLocations(); // Reload the locations dropdown
      } else {
        alert('Failed to save location: ' + (data.error || data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('An error occurred while saving the location.');
    });
  }

  function deleteSelectedLocation() {
    const locationSelect = document.getElementById('location_id');
    if (!locationSelect || !locationSelect.value) {
      alert('Please select a location to delete.');
      return;
    }

    const selectedText = locationSelect.options[locationSelect.selectedIndex]?.text || 'this location';
    const confirmDelete = confirm(`Delete location "${selectedText}"? This cannot be undone.`);
    if (!confirmDelete) return;

    const formData = new FormData();
    formData.append('action', 'delete_location');
    formData.append('location_id', locationSelect.value);

    fetch(window.location.pathname, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert('Location deleted successfully!');
        loadLocations();
        loadLots('');
      } else {
        alert('Failed to delete location: ' + (data.error || data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('An error occurred while deleting the location.');
    });
  }

  function exportAnalytics() {
    const dateFrom    = document.getElementById('analytics_date_from')?.value;
    const dateTo      = document.getElementById('analytics_date_to')?.value;
    const locationId = document.getElementById('analytics_location')?.value;

    const params = new URLSearchParams();
    params.append('export', 'analytics');
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (locationId) params.append('location_id', locationId);

    window.location.href = window.location.pathname + '?' + params.toString();
  }
  </script>
</body>
</html>