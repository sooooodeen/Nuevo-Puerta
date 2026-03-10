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

    function tableExists($conn, $table) {
      $tableEsc = mysqli_real_escape_string($conn, $table);
      $sql = "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableEsc'";
      $res = mysqli_query($conn, $sql);
      if (!$res) return false;
      $row = mysqli_fetch_assoc($res);
      return ((int)($row['c'] ?? 0)) > 0;
    }

    function columnExists($conn, $table, $column) {
      $tableEsc = mysqli_real_escape_string($conn, $table);
      $colEsc = mysqli_real_escape_string($conn, $column);
      $sql = "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableEsc' AND COLUMN_NAME = '$colEsc'";
      $res = mysqli_query($conn, $sql);
      if (!$res) return false;
      $row = mysqli_fetch_assoc($res);
      return ((int)($row['c'] ?? 0)) > 0;
    }

    function getPendingDocumentsCount($conn) {
      if (tableExists($conn, 'documents') && columnExists($conn, 'documents', 'status')) {
        $q = "SELECT COUNT(*) AS total FROM documents WHERE status = 'pending'";
        $res = mysqli_query($conn, $q);
        if ($res) {
          $row = mysqli_fetch_assoc($res);
          return (int)($row['total'] ?? 0);
        }
      }

      if (tableExists($conn, 'user_documents') && columnExists($conn, 'user_documents', 'status')) {
        $q = "SELECT COUNT(*) AS total FROM user_documents WHERE status IN ('pending_review', 'under_review')";
        $res = mysqli_query($conn, $q);
        if ($res) {
          $row = mysqli_fetch_assoc($res);
          return (int)($row['total'] ?? 0);
        }
      }

      return 0;
    }

    $salesAmountCol = columnExists($conn, 'sales', 'amount')
      ? 'amount'
      : (columnExists($conn, 'sales', 'sale_price') ? 'sale_price' : null);
    $salesDateCol = columnExists($conn, 'sales', 'sale_date')
      ? 'sale_date'
      : (columnExists($conn, 'sales', 'created_at') ? 'created_at' : null);
    $salesLocationCol = columnExists($conn, 'sales', 'location_id') ? 'location_id' : null;

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
  mysqli_query($conn, "ALTER TABLE lots ADD COLUMN IF NOT EXISTS payment_deadline DATE DEFAULT NULL");

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
      $result = mysqli_query($conn, "SELECT first_name, last_name FROM admin_accounts WHERE id = $admin_id LIMIT 1");
      if ($row = mysqli_fetch_assoc($result)) {
          $admin_name = $row['first_name'] . ' ' . $row['last_name'];
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
      if ($salesDateCol && $date_from)   $where[] = "s.$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
      if ($salesDateCol && $date_to)     $where[] = "s.$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
      if ($salesLocationCol && $location_id) $where[] = "s.$salesLocationCol = $location_id";

      $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

      $amountExpr = $salesAmountCol ? "IFNULL(SUM(s.$salesAmountCol), 0)" : "0";
      $avgExpr = $salesAmountCol
          ? "IFNULL(ROUND(SUM(s.$salesAmountCol)/NULLIF(COUNT(s.id),0), 2), 0)"
          : "0";

      $sql = "
        SELECT 
          a.id,
          a.first_name,
          a.last_name,
          a.email,
          COUNT(s.id) AS sales_count,
          $amountExpr AS total_amount,
          $avgExpr AS avg_deal_size
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
  // ALL PAYMENTS (AJAX: ?fetch=all_payments)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'all_payments') {
      
      $sql = "
        SELECT 
          l.id AS lot_id,
          l.owner_id,
          l.block_number,
          l.lot_number,
          l.lot_price,
          l.payment_type,
          l.payment_amount,
          l.payment_deadline,
          l.status,
          ll.location_name,
          CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
          u.email,
          u.mobile_number
        FROM lots l
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        LEFT JOIN user_accounts u ON l.owner_id = u.id
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
      ";
      
      $result = mysqli_query($conn, $sql);
      $payments = [];
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $payments[] = [
                  'lot_id'           => (int)$row['lot_id'],
                  'owner_id'         => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
                  'block_number'     => $row['block_number'],
                  'lot_number'       => $row['lot_number'],
                  'lot_price'        => $row['lot_price'],
                  'payment_type'     => $row['payment_type'],
                  'payment_amount'   => $row['payment_amount'],
                  'payment_deadline' => $row['payment_deadline'],
                  'status'           => $row['status'],
                  'location_name'    => $row['location_name'],
                  'owner_name'       => $row['owner_name'],
                  'email'            => $row['email'],
                  'mobile_number'    => $row['mobile_number']
              ];
          }
      }
      
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'payments' => $payments]);
      exit;
  }

  // =============================================
  // ALL LOT OWNERS (AJAX: ?fetch=all_lot_owners)
  // =============================================
  if ($_SERVER['REQUEST_METHOD'] === 'GET' &&
      isset($_GET['fetch']) && $_GET['fetch'] === 'all_lot_owners') {
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
      $locationWhere = $location_id > 0 ? " AND l.location_id = $location_id " : '';
      
      $sql = "
        SELECT 
          l.id AS lot_id,
          l.location_id,
          l.block_number,
          l.lot_number,
          l.status,
          ll.location_name,
          u.id AS user_id,
          CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
          u.email,
          u.mobile_number
        FROM lots l
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        LEFT JOIN user_accounts u ON l.owner_id = u.id
        WHERE l.owner_id IS NOT NULL
        $locationWhere
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
      ";
      
      $result = mysqli_query($conn, $sql);
      $owners = [];
      
      if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
              $owners[] = [
                  'lot_id'          => (int)$row['lot_id'],
                  'location_id'     => (int)$row['location_id'],
                  'user_id'         => (int)$row['user_id'],
                  'block_number'    => $row['block_number'],
                  'lot_number'      => $row['lot_number'],
                  'status'          => $row['status'],
                  'location_name'   => $row['location_name'],
                  'owner_name'      => $row['owner_name'],
                  'email'           => $row['email'],
                  'mobile_number'   => $row['mobile_number']
              ];
          }
      }
      
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'owners' => $owners]);
      exit;
  }

      // =====================================================
      // OWNER REGISTRATION HELPERS (AJAX GET)
      // =====================================================
      if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'owner_users') {
        $sql = "
        SELECT id, first_name, middle_name, last_name, email, mobile_number
        FROM user_accounts
        ORDER BY first_name ASC, last_name ASC
        ";
        $result = mysqli_query($conn, $sql);
        $users = [];

        if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $users[] = [
              'id' => (int)$row['id'],
              'name' => preg_replace('/\s+/', ' ', $fullName),
              'email' => $row['email'] ?? '',
              'mobile_number' => $row['mobile_number'] ?? ''
            ];
          }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
      }

      if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'owner_assignable_lots') {
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $whereLocation = $location_id > 0 ? " AND l.location_id = $location_id " : '';

        $sql = "
        SELECT l.id, l.block_number, l.lot_number, l.status, l.location_id, ll.location_name
        FROM lots l
        LEFT JOIN lot_locations ll ON l.location_id = ll.id
        WHERE l.owner_id IS NULL
        $whereLocation
        ORDER BY ll.location_name ASC, l.block_number ASC, l.lot_number ASC
        ";
        $result = mysqli_query($conn, $sql);
        $lots = [];

        if ($result) {
          while ($row = mysqli_fetch_assoc($result)) {
            $lots[] = [
              'id' => (int)$row['id'],
              'block_number' => $row['block_number'],
              'lot_number' => $row['lot_number'],
              'status' => $row['status'],
              'location_id' => (int)$row['location_id'],
              'location_name' => $row['location_name']
            ];
          }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'lots' => $lots]);
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
        $payment_deadline_raw = isset($_POST['payment_deadline']) ? trim($_POST['payment_deadline']) : '';
        $payment_amount = 'NULL';
        $payment_deadline = 'NULL';

          if (!in_array($payment_type, ['Down Payment', 'Fully Paid', 'Not Applicable'], true)) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Invalid payment type']);
          exit;
        }

          if ($payment_type === 'Down Payment' && $payment_deadline_raw !== '') {
            $deadlineObj = DateTime::createFromFormat('Y-m-d', $payment_deadline_raw);
            if (!$deadlineObj || $deadlineObj->format('Y-m-d') !== $payment_deadline_raw) {
              header('Content-Type: application/json');
              echo json_encode(['success' => false, 'error' => 'Please enter a valid payment deadline']);
              exit;
            }
            $payment_deadline = "'" . mysqli_real_escape_string($conn, $payment_deadline_raw) . "'";
          }

          if ($status === 'Available') {
            // Available lots should not have payment details yet.
            $payment_type = 'Not Applicable';
            $payment_amount = 'NULL';
            $payment_deadline = 'NULL';
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
        } else {
          $payment_deadline = 'NULL';
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
                          payment_amount = $payment_amount,
                          payment_deadline = $payment_deadline
                          WHERE id = $lot_id";

          $success = mysqli_query($conn, $updateQuery);
          if ($success) {
            // Keep blueprint pin color/status accurate when status is edited from Manage Lots.
            $syncPinStatusQuery = "UPDATE pin_locations SET pin_status = '$status' WHERE lot_id = $lot_id";
            mysqli_query($conn, $syncPinStatusQuery);
          }
          $msg     = $success ? 'Lot updated successfully' : mysqli_error($conn);
      } else {
          $insertQuery = "INSERT INTO lots (block_number, lot_number, lot_size, lot_price, location_id, status, payment_type, payment_amount, payment_deadline)
            VALUES ('$block_number', '$lot_number', '$lot_size', '$lot_price', '$location_id', '$status', '$payment_type', $payment_amount, $payment_deadline)";
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
    // UPDATE LOT STATUS (AJAX: POST action=update_lot_status)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['action']) && $_POST['action'] === 'update_lot_status') {
        header('Content-Type: application/json');
        
        $lot_id = intval($_POST['lot_id'] ?? 0);
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
        
        if (!$lot_id || !in_array($status, ['Available', 'Reserved', 'Sold', 'Paid'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid lot ID or status']);
            exit;
        }
        
        if ($status === 'Available') {
          $updateQuery = "UPDATE lots SET status = '$status', payment_type = 'Not Applicable', payment_amount = NULL, payment_deadline = NULL WHERE id = $lot_id";
        } else {
          $updateQuery = "UPDATE lots SET status = '$status' WHERE id = $lot_id";
        }
        $success = mysqli_query($conn, $updateQuery);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database update failed: ' . mysqli_error($conn)]);
        }
        exit;
    }

    // =====================================================
    // REMOVE LOT OWNER (AJAX: POST action=remove_lot_owner)
    // =====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['action']) && $_POST['action'] === 'remove_lot_owner') {
        header('Content-Type: application/json');
        
        $lot_id = intval($_POST['lot_id'] ?? 0);
        
        if (!$lot_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid lot ID']);
            exit;
        }
        
        $updateQuery = "UPDATE lots SET owner_id = NULL, status = 'Available' WHERE id = $lot_id";
        $success = mysqli_query($conn, $updateQuery);
        
        echo json_encode(['success' => (bool)$success]);
        exit;
    }

      // =====================================================
      // REGISTER LOT OWNER (AJAX: POST action=register_lot_owner)
      // =====================================================
      if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) && $_POST['action'] === 'register_lot_owner') {
        header('Content-Type: application/json');

        $lot_id = intval($_POST['lot_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);

        if (!$lot_id || !$user_id) {
          echo json_encode(['success' => false, 'error' => 'Please select both lot and owner.']);
          exit;
        }

        $lotCheck = mysqli_query($conn, "SELECT id, owner_id FROM lots WHERE id = $lot_id LIMIT 1");
        $lotRow = $lotCheck ? mysqli_fetch_assoc($lotCheck) : null;
        if (!$lotRow) {
          echo json_encode(['success' => false, 'error' => 'Selected lot does not exist.']);
          exit;
        }
        if (!empty($lotRow['owner_id'])) {
          echo json_encode(['success' => false, 'error' => 'This lot already has an owner.']);
          exit;
        }

        $userCheck = mysqli_query($conn, "SELECT id FROM user_accounts WHERE id = $user_id LIMIT 1");
        $userRow = $userCheck ? mysqli_fetch_assoc($userCheck) : null;
        if (!$userRow) {
          echo json_encode(['success' => false, 'error' => 'Selected owner account does not exist.']);
          exit;
        }

        $updateQuery = "UPDATE lots SET owner_id = $user_id WHERE id = $lot_id";
        $success = mysqli_query($conn, $updateQuery);

        echo json_encode([
          'success' => (bool)$success,
          'message' => $success ? 'Lot owner registered successfully.' : mysqli_error($conn)
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
        // FIXED: Removed extra columns not in DB
        $sql = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, created_at, photo_path FROM admin_accounts WHERE id = ?";
      } elseif ($type === 'agent') {
        // FIXED: Removed extra columns not in DB
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

          // password (required)
          $raw_password = $_POST['password'] ?? '';
          if ($raw_password === '') {
              echo json_encode(['success' => false, 'error' => 'Password is required.']);
              exit;
          }
          $password = password_hash($raw_password, PASSWORD_DEFAULT);

          // optional photo
          $photo_path = null;
          if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          // FIXED: Removed columns that don't exist in admin_accounts table
          $sql = "INSERT INTO admin_accounts 
                  (first_name, middle_name, last_name, email, username, password,
                  phone, address, photo_path, availability)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = $conn->prepare($sql);

          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
              exit;
          }

          // 9 strings + 1 int = "sssssssssi"
          $stmt->bind_param(
              "sssssssssi",
              $first_name, $middle_name, $last_name, $email, $username, $password,
              $phone, $address, $photo_path, $availability
          );

          $ok = $stmt->execute();

          echo json_encode([
              'success' => $ok,
              'message' => $ok ? 'Admin account created successfully!'
                              : 'Error creating admin account: ' . $stmt->error
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
              'message' => $ok ? 'Account updated successfully!'
                              : 'Error updating account: ' . $stmt->error
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
              'message' => $ok ? 'Account deleted successfully!'
                              : 'Error deleting account: ' . $stmt->error
          ]);
          $stmt->close();
          exit;
      }
  }



  // =====================================================
  // AGENT ACCOUNT CRUD (agent_action)
  //    – add/update: JSON (AJAX)
  //    – delete: normal form, no JSON
  // =====================================================
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
          
          // Removed short_description and years_experience to prevent crash
          $availability      = isset($_POST['availability']) ? 1 : 0;
          
          $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);

          $photo_path = null;
          if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
              $photo_path = handleFileUpload($_FILES['photo']);
          }

          // NOTE: years_experience and short_description removed
          $sql = "INSERT INTO agent_accounts 
            (first_name, middle_name, last_name, username, email, phone, address, 
            photo_path, availability, password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

          $stmt = $conn->prepare($sql);
          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }

          // 8 strings + 1 int + 1 string
          $stmt->bind_param(
            "ssssssssis",
            $first_name, $middle_name, $last_name, $username, $email, $phone,
            $address, $photo_path, $availability, $password
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
          
          // Removed short_description and years_experience to prevent crash
          $availability     = isset($_POST['availability']) ? 1 : 0;

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

              $stmt->bind_param(
                "ssssssssisi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $availability, $password, $photo_path, $agent_id
              );
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

              $stmt->bind_param(
                "sssssssisi",
                $first_name, $middle_name, $last_name, $username, $email, $phone,
                $address, $availability, $photo_path, $agent_id
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
              $_SESSION['success_message'] = "Agent account deleted successfully!";
              header('Location: admindashboard.php#admin-accounts');
              exit;
            } else {
              $error_message = "Error deleting agent account: " . $conn->error;
            }
            $stmt->close();
          }
          // PRG: redirect after POST to avoid resubmission
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
                  (first_name, middle_name, username, last_name, email, phone_number, address, password, photo_path)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = $conn->prepare($sql);

          if (!$stmt) {
              echo json_encode(['success' => false, 'error' => "Prepare failed: " . $conn->error]);
              exit;
          }

          $stmt->bind_param(
              "sssssssss",
              $first_name, $middle_name, $username, $last_name, $email,
              $phone_number, $address, $password, $photo_path
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
          $phone_number = mysqli_real_escape_string($conn, $_POST['phone_number'] ?? '');
          $address       = mysqli_real_escape_string($conn, $_POST['address'] ?? '');

          $photo_path   = null;
          $passwordHash = null;

          $update_fields = [
              'first_name=?',
              'middle_name=?',
              'username=?',
              'last_name=?',
              'email=?',
              'phone_number=?',
              'address=?'
          ];
          $bind_types  = "sssssss";
          $bind_values = [$first_name, $middle_name, $username, $last_name, $email, $phone_number, $address];

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
          $lots        = [];
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
  // Only fetch active viewings for the default list, or modify to fetch all
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

  // Active agents for assignment dropdown
  $agents      = [];
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
  // FIXED: Removed missing columns from SELECT
  $accountsQuery   = "SELECT id, first_name, middle_name, last_name, username, email, phone, address, photo_path, availability, created_at FROM admin_accounts ORDER BY created_at DESC";
  $accountsResult  = mysqli_query($conn, $accountsQuery);
  if (!$accountsResult) {
    die('Admin Accounts Query Error: ' . mysqli_error($conn));
  }
  while ($account = mysqli_fetch_assoc($accountsResult)) {
    $adminAccounts[] = $account;
  }

  // Agent accounts
  $agentAccounts  = [];
  // FIXED: Removed missing columns from SELECT
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

    $dashboard_stats['pending_documents'] = getPendingDocumentsCount($conn);

    $dashboard_stats['total_sales'] = 0;
    if ($salesAmountCol) {
      $salesTotalQuery = "SELECT IFNULL(SUM($salesAmountCol), 0) AS total FROM sales";
      $salesTotalResult = mysqli_query($conn, $salesTotalQuery);
      if ($salesTotalResult) {
        $salesTotalRow = mysqli_fetch_assoc($salesTotalResult);
        $dashboard_stats['total_sales'] = (float)($salesTotalRow['total'] ?? 0);
      }
    }

    $monthly_sales = [];
    if ($salesAmountCol && $salesDateCol) {
      $salesQuery = "
      SELECT DATE_FORMAT($salesDateCol, '%b %Y') AS month, IFNULL(SUM($salesAmountCol), 0) AS total
      FROM sales
      WHERE $salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      GROUP BY YEAR($salesDateCol), MONTH($salesDateCol), month
      ORDER BY YEAR($salesDateCol) ASC, MONTH($salesDateCol) ASC
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
    }

  // Handle fetching analytics data
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch']) && $_GET['fetch'] === 'analytics') {
      $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
      $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;

      // KPIs
      $salesQuery = $salesAmountCol
        ? "SELECT IFNULL(SUM($salesAmountCol), 0) as total FROM sales WHERE 1"
        : "SELECT 0 as total";
      $lotsQuery = "SELECT COUNT(*) as total FROM lots WHERE 1";
      $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";

      // Add filters
      $salesWhere = [];
      if ($salesDateCol && $date_from) $salesWhere[] = "$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
      if ($salesDateCol && $date_to) $salesWhere[] = "$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
      if ($salesLocationCol && $location_id) $salesWhere[] = "$salesLocationCol = $location_id";
      if ($salesWhere) $salesQuery .= " AND " . implode(' AND ', $salesWhere);

      if ($location_id) {
        $lotsQuery .= " AND location_id = $location_id";
      }

      // Monthly sales trend
      $monthlySalesQuery = null;
      if ($salesAmountCol && $salesDateCol) {
        $monthlyWhere = $salesWhere;
        if (!$date_from && !$date_to) {
          $monthlyWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        }

        $monthlySalesQuery = "
        SELECT DATE_FORMAT($salesDateCol, '%b %Y') AS month, IFNULL(SUM($salesAmountCol), 0) AS total
        FROM sales
        WHERE 1
        ";
        if ($monthlyWhere) $monthlySalesQuery .= " AND " . implode(' AND ', $monthlyWhere);
        $monthlySalesQuery .= " GROUP BY YEAR($salesDateCol), MONTH($salesDateCol), month ORDER BY YEAR($salesDateCol) ASC, MONTH($salesDateCol) ASC";
      }

      // Fetch KPIs
      $salesResult = mysqli_query($conn, $salesQuery);
      $lotsResult = mysqli_query($conn, $lotsQuery);
      $agentsResult = mysqli_query($conn, $agentsQuery);

      $kpis = [
        'total_sales' => $salesResult ? (float)(mysqli_fetch_assoc($salesResult)['total'] ?? 0) : 0,
        'total_lots' => $lotsResult ? (int)(mysqli_fetch_assoc($lotsResult)['total'] ?? 0) : 0,
        'available_agents' => $agentsResult ? (int)(mysqli_fetch_assoc($agentsResult)['total'] ?? 0) : 0,
        'pending_documents' => getPendingDocumentsCount($conn),
      ];

      // Fetch monthly sales
      $monthly_sales = [];
      if ($monthlySalesQuery) {
        $monthlySalesResult = mysqli_query($conn, $monthlySalesQuery);
        if ($monthlySalesResult) {
          while ($row = mysqli_fetch_assoc($monthlySalesResult)) {
            $monthly_sales[] = [
              'month' => $row['month'],
              'amount' => (float)$row['total']
            ];
          }
          }
      }

      header('Content-Type: application/json');
      echo json_encode([
          'kpis' => $kpis,
          'monthly_sales' => $monthly_sales,
          'monthly_scope' => (!$date_from && !$date_to) ? 'last_12_months' : 'filtered_range'
      ]);
      exit;
  }


  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export']) && $_GET['export'] === 'analytics') {
      $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
      $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
      $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : null;

      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="analytics_export.csv"');

      $output = fopen('php://output', 'w');

      // Write the header row
      fputcsv($output, ['Metric', 'Value']);

      // Fetch KPIs
        $salesQuery = $salesAmountCol
          ? "SELECT IFNULL(SUM($salesAmountCol), 0) as total FROM sales WHERE 1"
          : "SELECT 0 as total";
        $lotsQuery = "SELECT COUNT(*) as total FROM lots WHERE 1";
      $agentsQuery = "SELECT COUNT(*) as total FROM agent_accounts WHERE status = 'active' AND availability = 1";

        $salesWhere = [];
        if ($salesDateCol && $date_from) $salesWhere[] = "$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
        if ($salesDateCol && $date_to) $salesWhere[] = "$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
        if ($salesLocationCol && $location_id) $salesWhere[] = "$salesLocationCol = $location_id";
        if ($salesWhere) $salesQuery .= " AND " . implode(' AND ', $salesWhere);

        if ($location_id) {
          $lotsQuery .= " AND location_id = $location_id";
        }

      $salesResult = mysqli_query($conn, $salesQuery);
      $lotsResult = mysqli_query($conn, $lotsQuery);
      $agentsResult = mysqli_query($conn, $agentsQuery);

      $kpis = [
          'Total Sales' => $salesResult ? (float)(mysqli_fetch_assoc($salesResult)['total'] ?? 0) : 0,
          'Total Lots' => $lotsResult ? (int)(mysqli_fetch_assoc($lotsResult)['total'] ?? 0) : 0,
          'Available Agents' => $agentsResult ? (int)(mysqli_fetch_assoc($agentsResult)['total'] ?? 0) : 0,
          'Pending Documents' => getPendingDocumentsCount($conn),
      ];

      // Write KPIs to CSV
      foreach ($kpis as $metric => $value) {
          fputcsv($output, [$metric, $value]);
      }

      // Fetch monthly sales
      fputcsv($output, []); // Empty row for separation
      fputcsv($output, ['Month', 'Sales Amount']);
        if ($salesAmountCol && $salesDateCol) {
          $monthlyWhere = $salesWhere;
          if (!$date_from && !$date_to) {
            $monthlyWhere[] = "$salesDateCol >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
          }

          $monthlySalesQuery = "
            SELECT DATE_FORMAT($salesDateCol, '%b %Y') AS month, IFNULL(SUM($salesAmountCol), 0) AS total
            FROM sales
            WHERE 1
          ";
          if ($monthlyWhere) $monthlySalesQuery .= " AND " . implode(' AND ', $monthlyWhere);
          $monthlySalesQuery .= " GROUP BY YEAR($salesDateCol), MONTH($salesDateCol), month ORDER BY YEAR($salesDateCol) ASC, MONTH($salesDateCol) ASC";

          $monthlySalesResult = mysqli_query($conn, $monthlySalesQuery);
          if ($monthlySalesResult) {
            while ($row = mysqli_fetch_assoc($monthlySalesResult)) {
              fputcsv($output, [$row['month'], (float)$row['total']]);
            }
          }
      }

      // Fetch top agents
      fputcsv($output, []); // Empty row for separation
      fputcsv($output, ['Agent Name', 'Email', 'Sales Count', 'Total Sales', 'Average Deal Size']);
        $topAgentsWhere = [];
        if ($salesDateCol && $date_from) $topAgentsWhere[] = "s.$salesDateCol >= '" . mysqli_real_escape_string($conn, $date_from) . " 00:00:00'";
        if ($salesDateCol && $date_to) $topAgentsWhere[] = "s.$salesDateCol < DATE_ADD('" . mysqli_real_escape_string($conn, $date_to) . "', INTERVAL 1 DAY)";
        if ($salesLocationCol && $location_id) $topAgentsWhere[] = "s.$salesLocationCol = $location_id";
        $topAgentsWhereSql = $topAgentsWhere ? 'WHERE ' . implode(' AND ', $topAgentsWhere) : '';

        $topAgentsAmountExpr = $salesAmountCol ? "IFNULL(SUM(s.$salesAmountCol), 0)" : "0";
        $topAgentsAvgExpr = $salesAmountCol
          ? "IFNULL(ROUND(SUM(s.$salesAmountCol)/NULLIF(COUNT(s.id),0), 2), 0)"
          : "0";

        $topAgentsQuery = "
          SELECT 
              CONCAT(a.first_name, ' ', a.last_name) AS name,
              a.email,
              COUNT(s.id) AS sales_count,
            $topAgentsAmountExpr AS total_amount,
            $topAgentsAvgExpr AS avg_deal_size
          FROM agent_accounts a
          LEFT JOIN sales s ON a.id = s.agent_id
          $topAgentsWhereSql
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

      function getDocumentPendingFilterSql($alias = 'd') {
        return "$alias.status IN ('pending', 'pending_review', 'under_review')";
      }

        function updateDocumentReviewStatus($conn, $table, $doc_id, $status, $remarks = '', $admin_id = null) {
          if (!tableExists($conn, $table) || !columnExists($conn, $table, 'id') || !columnExists($conn, $table, 'status')) {
            return [false, "Table or required columns missing: $table"];
          }

          $statusEsc = mysqli_real_escape_string($conn, $status);
          $remarksEsc = mysqli_real_escape_string($conn, $remarks);

          $setParts = ["status='$statusEsc'"];
          if (columnExists($conn, $table, 'reviewed_at')) {
            $setParts[] = "reviewed_at=NOW()";
          }
          if ($admin_id && columnExists($conn, $table, 'reviewed_by')) {
            $setParts[] = "reviewed_by=" . intval($admin_id);
          }

          if ($status === 'rejected') {
            if (columnExists($conn, $table, 'progress_notes')) {
              $setParts[] = "progress_notes='$remarksEsc'";
            }
            if (columnExists($conn, $table, 'remarks')) {
              $setParts[] = "remarks='$remarksEsc'";
            }
          }

          $q = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE id = " . intval($doc_id);
          $ok = mysqli_query($conn, $q);
          if (!$ok) {
            return [false, mysqli_error($conn) ?: "Failed to update $table"];
          }

          if (mysqli_affected_rows($conn) > 0) {
            return [true, null];
          }

          // Treat as success if the document exists but update made no changes.
          $check = mysqli_query($conn, "SELECT id FROM $table WHERE id = " . intval($doc_id) . " LIMIT 1");
          if ($check && mysqli_fetch_assoc($check)) {
            return [true, null];
          }

          return [false, "Document not found in $table"];
        }

  /* ============================================================
    FETCH HANDLERS — GET Requests
  ============================================================ */

  // ------------------------------------------------------------
  // Fetch Audit Logs
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'audit_logs') {

      if (!tableExists($conn, 'audit_logs')) {
        respondJSON([]);
      }

        $actionFilter = trim($_GET['action'] ?? '');
        $adminFilter = trim($_GET['admin'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = [];
        if ($actionFilter !== '') {
          $where[] = "a.action LIKE '%" . mysqli_real_escape_string($conn, $actionFilter) . "%'";
        }
        if ($adminFilter !== '') {
          $adminEsc = mysqli_real_escape_string($conn, $adminFilter);
          $where[] = "(ad.first_name LIKE '%$adminEsc%' OR ad.last_name LIKE '%$adminEsc%')";
        }
        if ($dateFrom !== '') {
          $where[] = "a.created_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "a.created_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(a.action LIKE '%$searchEsc%' OR a.details LIKE '%$searchEsc%')";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "
          SELECT a.*, ad.first_name, ad.last_name
          FROM audit_logs a
          LEFT JOIN admin_accounts ad ON ad.id = a.admin_id
          $whereSql
          ORDER BY a.created_at DESC
          LIMIT 200
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

      if (!tableExists($conn, 'notifications')) {
        respondJSON([]);
      }

        $typeFilter = trim($_GET['type'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = [];
        $allowedTypes = ['info', 'success', 'warning', 'error'];
        if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
          $where[] = "type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
        }
        if ($dateFrom !== '') {
          $where[] = "created_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "created_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(title LIKE '%$searchEsc%' OR message LIKE '%$searchEsc%')";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $q = "SELECT * FROM notifications $whereSql ORDER BY created_at DESC LIMIT 100";
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

      if (!tableExists($conn, 'notifications')) {
        respondJSON(["count" => 0]);
      }

      $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM notifications");
      $row = mysqli_fetch_assoc($res);

      respondJSON(["count" => intval($row["total"])]);
  }


  // ------------------------------------------------------------
  // Fetch Pending Documents
  // ------------------------------------------------------------
  if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents') {

      $statusFilter = trim($_GET['status'] ?? '');
      $typeFilter = trim($_GET['doc_type'] ?? '');
      $dateFrom = trim($_GET['date_from'] ?? '');
      $dateTo = trim($_GET['date_to'] ?? '');
      $search = trim($_GET['search'] ?? '');

      $userDocAllowedStatuses = ['pending_review', 'under_review', 'approved', 'rejected', 'requires_revision'];
      $legacyDocAllowedStatuses = ['pending', 'approved', 'rejected'];

      if (tableExists($conn, 'user_documents') && columnExists($conn, 'user_documents', 'status')) {
        $where = [];
        if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, $userDocAllowedStatuses, true)) {
          $where[] = "d.status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
        } else {
          $where[] = getDocumentPendingFilterSql('d');
        }
        if ($typeFilter !== '' && columnExists($conn, 'user_documents', 'doc_type')) {
          $where[] = "d.doc_type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
        }
        if ($dateFrom !== '') {
          $where[] = "d.uploaded_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "d.uploaded_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $where[] = "(d.file_name LIKE '%$searchEsc%' OR d.doc_type LIKE '%$searchEsc%' OR u.first_name LIKE '%$searchEsc%' OR u.last_name LIKE '%$searchEsc%' OR u.email LIKE '%$searchEsc%')";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "
          SELECT 
            d.id,
            d.doc_type,
            d.file_name,
            d.file_path,
            d.status,
            d.uploaded_at,
            u.first_name,
            u.last_name,
            u.email,
            'user_documents' AS source
          FROM user_documents d
          LEFT JOIN user_accounts u ON d.user_id = u.id
          $whereSql
          ORDER BY d.uploaded_at DESC
        ";
        $res = mysqli_query($conn, $q);

        $docs = [];
        while ($row = mysqli_fetch_assoc($res)) {
          $docs[] = $row;
        }

        respondJSON($docs);
      }

      if (tableExists($conn, 'documents') && columnExists($conn, 'documents', 'status')) {
        $where = [];
        if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, $legacyDocAllowedStatuses, true)) {
          $where[] = "status = '" . mysqli_real_escape_string($conn, $statusFilter) . "'";
        } else {
          $where[] = "status = 'pending'";
        }
        if ($typeFilter !== '') {
          if (columnExists($conn, 'documents', 'doc_type')) {
            $where[] = "doc_type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
          } elseif (columnExists($conn, 'documents', 'type')) {
            $where[] = "type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'";
          }
        }
        if ($dateFrom !== '') {
          $where[] = "uploaded_at >= '" . mysqli_real_escape_string($conn, $dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== '') {
          $where[] = "uploaded_at < DATE_ADD('" . mysqli_real_escape_string($conn, $dateTo) . "', INTERVAL 1 DAY)";
        }
        if ($search !== '') {
          $searchEsc = mysqli_real_escape_string($conn, $search);
          $searchParts = [];
          if (columnExists($conn, 'documents', 'filename')) $searchParts[] = "filename LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'file_name')) $searchParts[] = "file_name LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'type')) $searchParts[] = "type LIKE '%$searchEsc%'";
          if (columnExists($conn, 'documents', 'doc_type')) $searchParts[] = "doc_type LIKE '%$searchEsc%'";
          if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
          }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $q = "SELECT *, 'documents' AS source FROM documents $whereSql ORDER BY uploaded_at DESC";
        $res = mysqli_query($conn, $q);

        $docs = [];
        while ($row = mysqli_fetch_assoc($res)) {
          $docs[] = $row;
        }

        respondJSON($docs);
      }

      respondJSON([]);
    }


    // ------------------------------------------------------------
    // Fetch Pending Documents Counter
    // ------------------------------------------------------------
    if (isset($_GET['fetch']) && $_GET['fetch'] === 'documents_count') {
      respondJSON(["count" => getPendingDocumentsCount($conn)]);
    }


  /* ============================================================
    POST HANDLERS — ACTIONS
  ============================================================ */

  // ------------------------------------------------------------
  // Approve Document
  // ------------------------------------------------------------
  if (isset($_POST["action"]) && $_POST["action"] === "approve_document") {

      $doc_id = intval($_POST["doc_id"]);
      $doc_source = $_POST['doc_source'] ?? 'user_documents';
      $updated = false;
      $lastError = null;

      if ($doc_source === 'user_documents') {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'user_documents', $doc_id, 'approved', '', $admin_id);
      }

      if (!$updated) {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'documents', $doc_id, 'approved', '', $admin_id);
      }

      if (!$updated) {
        respondJSON(["success" => false, "error" => $lastError ?: "Document not found or already updated."]);
      }

      logAudit($conn, $admin_id, "Document Approved", "Document ID: $doc_id approved");
      sendNotification($conn, "Document Approved", "Document #$doc_id was approved.", "success");

      respondJSON(["success" => true]);
  }


  // ------------------------------------------------------------
  // Reject Document
  // ------------------------------------------------------------
  if (isset($_POST["action"]) && $_POST["action"] === "reject_document") {

      $doc_id = intval($_POST["doc_id"]);
      $remarks = $_POST["remarks"] ?? '';
      $doc_source = $_POST['doc_source'] ?? 'user_documents';
      $updated = false;
      $lastError = null;

      if ($doc_source === 'user_documents') {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'user_documents', $doc_id, 'rejected', $remarks, $admin_id);
      }

      if (!$updated) {
        [$updated, $lastError] = updateDocumentReviewStatus($conn, 'documents', $doc_id, 'rejected', $remarks, $admin_id);
      }

      if (!$updated) {
        respondJSON(["success" => false, "error" => $lastError ?: "Document not found or already updated."]);
      }

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
      if (!tableExists($conn, 'user_documents')) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
      }

      $docs = [];
      $query = "SELECT d.*, u.first_name, u.last_name, u.email, 'user_documents' AS source
        FROM user_documents d
        LEFT JOIN user_accounts u ON d.user_id = u.id
        WHERE " . getDocumentPendingFilterSql('d') . "
        ORDER BY d.uploaded_at DESC";
      $stmt = $conn->prepare($query);
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@panzoom/panzoom/dist/panzoom.min.js"></script>
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

      .sidebar-wrapper {
        height: 100vh;
        display: flex;
        align-items: stretch;
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

      .user-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        background: rgba(255,255,255,0.08); /* translucent box like agent */
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
      input[type="number"], input[type="date"], input[type="datetime-local"],
      select, textarea {
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

  .sidebar {
      width: 290px;
      background-color: #14532d;
      border-radius: 0px;
      display: flex;
      flex-direction: column;
      padding: 40px 25px;
      height: 100%;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      position: sticky;
      top: 0;
      /* FIX: allow scrolling inside the sidebar so the logout button isn't cut off */
      overflow-y: auto; 
    }

    /* Scrollbar styling for a cleaner look */
    .sidebar::-webkit-scrollbar {
      width: 6px;
    }
    .sidebar::-webkit-scrollbar-track {
      background: transparent;
    }
    .sidebar::-webkit-scrollbar-thumb {
      background-color: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }
    .sidebar::-webkit-scrollbar-thumb:hover {
      background-color: rgba(255, 255, 255, 0.5);
    }

      .user-profile {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background-color: transparent; /* match agent transparent card */
        padding: 6px 0;
        border-radius: 8px;
        width: 100%;
        margin-bottom: 14px;
        box-shadow: none;
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
        color: #ffffff;
        line-height: 1.2;
      }

      .user-details div:first-child {
    font-size: 14px;
    font-weight: 500;
  }

  .user-details div:last-child {
    font-size: 12px;
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

      .table-section {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
      }

      .divider {
        width: 5px;
        background-color: #2D4D26;
        height: calc(100vh - 40px);
        margin-top: 20px;
        border-radius: 5px;
        display: none; /* hide the vertical divider/scroll-like line */
      }

      /* Flip logout icon horizontally */
      .logout-icon {
        display: inline-block;
        transform: scaleX(-1);
        -webkit-transform: scaleX(-1);
      }

      .header {
    display: flex;
    justify-content: flex-start;     /* ← changed to left */
    align-items: center;             /* better vertical alignment */
    gap: 16px;                       /* optional – space between logo/title if any */
    margin-bottom: 32px;
    padding-left: 8px;               /* tiny left breathing room – optional */
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
        display: none;
      }

      /* Show active section and animate like user dashboard */
      .section.active {
        display: block;
        animation: fadeIn 0.36s ease;
      }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
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
        padding: 10px 18px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
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

      .lot-action-btn {
        border: none;
        border-radius: 6px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.12s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      .lot-action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
      }

      .lot-action-btn-blueprint {
        background: #0d6efd;
        color: #fff;
      }

      .lot-action-btn-edit {
        background: #3e5f3e;
        color: #fff;
      }

      .lot-action-btn-delete {
        background: #6c757d;
        color: #fff;
      }

      .pin-modal-overlay {
        display: none;
        position: fixed;
        z-index: 3000;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        justify-content: center;
        align-items: center;
        overflow: auto;
      }

      .pin-modal-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        width: 95%;
        max-width: 1040px;
        max-height: 92vh;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
      }

      .pin-modal-header {
        padding: 18px 24px;
        background: linear-gradient(90deg, #223b22, #2d482d);
        color: #fff;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .pin-modal-title {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
      }

      .pin-modal-close {
        font-size: 28px;
        cursor: pointer;
        color: #fff;
        font-weight: 700;
        line-height: 1;
        opacity: 0.95;
      }

      .pin-modal-close:hover {
        opacity: 1;
      }

      .pin-modal-content {
        padding: 20px 24px;
        display: flex;
        flex-direction: column;
        gap: 14px;
      }

      .pin-status-row {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
      }

      .pin-status-btn {
        padding: 11px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        background: #fff;
        transition: all 0.2s ease;
      }

      .pin-status-btn:hover {
        transform: translateY(-1px);
      }

      .pin-status-btn-available {
        border: 2px solid #28a745;
        color: #28a745;
      }

      .pin-status-btn-reserved {
        border: 2px solid #ffc107;
        color: #9a7000;
      }

      .pin-status-btn-sold {
        border: 2px solid #dc3545;
        color: #dc3545;
      }

      .pin-modal-help {
        background: #2f3136;
        color: #fff;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
      }

      .blueprint-stage {
        position: relative;
        background: linear-gradient(135deg, #f5f7fa, #eef1f4);
        border: 1px solid #d9e0e6;
        border-radius: 10px;
        overflow: hidden;
        min-height: 420px;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .blueprint-wrapper {
        position: relative;
        display: inline-block;
      }

      #blueprintImage {
        max-width: 100%;
        max-height: 72vh;
        object-fit: contain;
        display: block;
      }

      #blueprintCanvas {
        position: absolute;
        top: 0;
        left: 0;
        cursor: crosshair;
        display: none;
        pointer-events: auto;
      }

      .pin-modal-footer {
        padding: 18px 24px;
        background: #f9fafb;
        border-top: 1px solid #e9ecef;
        border-radius: 0 0 12px 12px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
      }

      .pin-modal-footer-btn {
        border: none;
        border-radius: 8px;
        padding: 10px 18px;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
      }

      .pin-modal-footer-btn-draw { background: #28a745; }
      .pin-modal-footer-btn-cancel { background: #6c757d; }
      .pin-modal-footer-btn-save { background: #2d482d; }

      @media (max-width: 768px) {
        .pin-modal-card {
          width: 98%;
          max-height: 96vh;
        }

        .pin-modal-content,
        .pin-modal-header,
        .pin-modal-footer {
          padding: 14px;
        }

        .blueprint-stage {
          min-height: 320px;
        }
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

        <div style="background: rgba(255,255,255,0.08); border-radius:12px; padding:10px 12px; margin:0 auto 16px; width:220px; display:flex; align-items:center;">
          <div style="margin-right:12px; flex-shrink:0;">
            <img src="assets/s.png" alt="User Image" style="width:40px; height:40px; border-radius:50%; object-fit:cover; display:block;" />
          </div>
          <div style="line-height:1.1;">
            <div style="font-weight:600; font-size:15px; color:#ffffff;">
              <?php echo htmlspecialchars($admin_name); ?>
            </div>
            <div style="font-size:13px; color:rgba(255,255,255,0.85);">
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
            <div class="form-group">
              <label for="admin_first_name">First Name</label>
              <input type="text" id="admin_first_name" name="first_name" required>
            </div>
            <div class="form-group">
              <label for="admin_middle_name">Middle Name (Optional)</label>
              <input type="text" id="admin_middle_name" name="middle_name">
            </div>
            <div class="form-group">
              <label for="admin_last_name">Last Name</label>
              <input type="text" id="admin_last_name" name="last_name" required>
            </div>
          </div>

          <div class="form-group">
            <label for="admin_username">Username</label>
            <input type="text" id="admin_username" name="username" required>
          </div>
        </div>

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

        <div class="form-section">
          <div class="form-section-title">Profile Photo (Optional)</div>
          <div class="photo-upload-section">
            <div class="photo-placeholder" id="admin-photo-preview">
              No Photo
            </div>
            <div class="file-input-wrapper">
              <input type="file" id="admin_photo" name="photo" accept="image/*"
                    onchange="previewPhoto(this, 'admin-photo-preview')">
              <label for="admin_photo" class="file-input-label">Choose Photo</label>
            </div>
            <div style="font-size: 12px; color: #999; margin-top: 8px;">
              JPG, PNG, or GIF (Max 5MB) — Optional
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title">Account Security</div>

          <div class="form-row">
            <div class="form-group">
              <label for="admin_password">Password</label>
              <input type="password" id="admin_password" name="password" required>
            </div>

            <div class="form-group">
              <label for="admin_confirm_password">Confirm Password</label>
              <input type="password" id="admin_confirm_password" name="confirm_password" required>
              <small id="admin-password-error"
                    style="color:#dc3545;display:none;font-size:13px;">
                Passwords do not match.
              </small>
            </div>
          </div>
        </div>

        <button type="submit" class="btn-primary">Create Admin Account</button>
        <button type="button" class="btn btn-danger"
                onclick="resetForm('admin-account-form')">Cancel</button>
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

    <div class="form-section">
    <div class="form-section-title">Account Security</div>

    <div class="form-row">
      <div class="form-group">
        <label for="agent_password">Password</label>
        <input type="password" id="agent_password" name="password" required>
      </div>

      <div class="form-group">
        <label for="agent_confirm_password">Confirm Password</label>
        <input type="password" id="agent_confirm_password" name="confirm_password" required>
        <small id="agent-password-error" style="color:#dc3545;display:none;">
          Passwords do not match.
        </small>
      </div>
    </div>
  </div> <div class="form-section">
    <div class="form-section-title">Availability Status</div>
    <div class="availability-toggle">
      <label class="toggle-switch">
        <input type="checkbox" name="availability" id="agent_availability" checked>
        <span class="slider"></span>
      </label>
      <span>Available for client assignments</span>
    </div>
  </div>


        <button type="submit" class="btn-primary">Create Agent Account</button>
        <button type="button" class="btn btn-danger" onclick="resetForm('agent-account-form')">Cancel</button>
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

            <div class="form-section">
              <div class="form-section-title">Contact Information</div>
              <div class="form-row">
                <div class="form-group">
                  <label for="user_email">Email</label>
                  <input type="email" id="user_email" name="email" required>
                </div>
                <div class="form-group">
                  <label for="user_mobile">Phone</label>
                  <input type="tel" id="user_mobile" name="Phone_number" required>
                </div>
              </div>
              <div class="form-group">
                <label for="user_address">Address</label>
                <textarea id="user_address" name="address" required></textarea>
              </div>
            </div>

          <div class="form-section">
    <div class="form-section-title">Account Security</div>

    <div class="form-row">
      <div class="form-group">
        <label for="user_password">Password</label>
        <input type="password" id="user_password" name="password" required>
      </div>

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
        <div id="payment_amount_wrap" style="display:none; margin-top:6px;">
          <div style="font-size:13px; margin-bottom:4px; color:#2d482d;">Down Payment Amount</div>
          <input type="number" id="payment_amount" step="0.01" min="0" placeholder="Down payment amount" style="width:100%;">
        </div>
        <div id="payment_deadline_wrap" style="display:none; margin-top:6px;">
          <div style="font-size:13px; margin-bottom:4px; color:#2d482d;">Payment Deadline</div>
          <input type="date" id="payment_deadline" style="width:100%;">
        </div>
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
      <div id="pinModal" class="pin-modal-overlay">
        <div class="pin-modal-card">
          <!-- Header -->
          <div class="pin-modal-header">
            <h3 class="pin-modal-title">
              Mapping: <span id="pinModalLotInfo"></span>
            </h3>
            <span onclick="closePinModal()" class="pin-modal-close">&times;</span>
          </div>

          <!-- Content -->
          <div class="pin-modal-content">
            <!-- Status Selection Buttons -->
            <div class="pin-status-row">
              <button id="statusBtn_Available" type="button" onclick="selectLotStatus('Available')" class="pin-status-btn pin-status-btn-available" style="background:#28a745;color:#fff;">
                Available
              </button>
              <button id="statusBtn_Reserved" type="button" onclick="selectLotStatus('Reserved')" class="pin-status-btn pin-status-btn-reserved">
                Reserved
              </button>
              <button id="statusBtn_Sold" type="button" onclick="selectLotStatus('Sold')" class="pin-status-btn pin-status-btn-sold">
                Sold
              </button>
            </div>

            <div class="pin-modal-help">
              Click each corner/edge point of the lot to trace its real shape. Click near the first point (or double-click) to close the polygon.
            </div>

            <!-- Blueprint Container -->
            <div class="blueprint-stage" id="blueprint-stage">
              <div class="blueprint-wrapper" id="blueprint-wrapper" style="position: relative; display: inline-block;">
                <img id="blueprintImage" src="" alt="Blueprint" style="display: block; max-width: 100%; height: auto; pointer-events: none;">
                <div id="draw-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 10;"></div>
              </div>
            </div>

          <!-- Footer with Buttons -->
          <div class="pin-modal-footer">
            <button onclick="toggleDrawingMode()" id="toggleDrawBtn" class="pin-modal-footer-btn pin-modal-footer-btn-draw">Start Drawing Polygon</button>
            <button onclick="closePinModal()" class="pin-modal-footer-btn pin-modal-footer-btn-cancel">Cancel</button>
            <button onclick="savePinLocation()" class="pin-modal-footer-btn pin-modal-footer-btn-save">Save Pin Location</button>
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
                        </div>
                      </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

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
                <div id="kpi-total-sales-range-label" style="font-size: 12px; color: #666; margin-top: 4px;">Based on selected filters</div>
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
                </tbody>
            </table>
          </div>
        </div>

        <div style="background: white; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
          <div style="background: #f8f9fa; padding: 20px; border-bottom: 1px solid #e0e0e0;">
            <h3 id="monthly-sales-title" style="margin: 0; color: #2d482d; font-size: 18px; font-weight: 600;">Monthly Sales Trend (Last 12 Months)</h3>
          </div>
          <div id="monthly-sales-chart-wrap" style="position: relative; padding: 24px 24px 18px; background: linear-gradient(180deg, #fcfdfc 0%, #f6f9f6 100%);">
            <canvas id="monthly-sales-chart" style="display:block; width:100%; height:320px;"></canvas>
            <div id="monthly-sales-tooltip" style="display:none; position:absolute; z-index:5; pointer-events:none; background:rgba(18,24,22,0.94); color:#fff; padding:8px 10px; border-radius:8px; font-size:12px; line-height:1.35; white-space:nowrap; box-shadow:0 8px 18px rgba(0,0,0,0.22);"></div>
            <div style="margin-top: 10px; font-size: 12px; color: #6b7280;">Values are shown in PHP currency (PHP).</div>
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
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="notif_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="notif_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <select id="notif_type" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">All Types</option>
              <option value="info">Info</option>
              <option value="success">Success</option>
              <option value="warning">Warning</option>
              <option value="error">Error</option>
            </select>
            <input type="text" id="notif_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search title/message">
            <button type="button" class="btn-primary" onclick="applyNotificationFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetNotificationFilters()">Reset</button>
          </div>
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
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="audit_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="audit_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <input type="text" id="audit_action" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Action">
            <input type="text" id="audit_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search details">
            <button type="button" class="btn-primary" onclick="applyAuditFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetAuditFilters()">Reset</button>
          </div>
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
          <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; margin-bottom: 12px; align-items:end;">
            <input type="date" id="docs_date_from" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="From">
            <input type="date" id="docs_date_to" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="To">
            <select id="docs_status" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;">
              <option value="">Pending Only</option>
              <option value="all">All Statuses</option>
              <option value="pending_review">Pending Review</option>
              <option value="under_review">Under Review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="requires_revision">Requires Revision</option>
              <option value="pending">Pending (Legacy)</option>
            </select>
            <input type="text" id="docs_type" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Doc type">
            <input type="text" id="docs_search" style="padding:8px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Search file/user/email">
            <button type="button" class="btn-primary" onclick="applyDocumentFilters()">Apply</button>
            <button type="button" class="btn" onclick="resetDocumentFilters()">Reset</button>
          </div>
          <div id="documents-container" style="background: #f8f9fa; border-radius: 8px; padding: 20px; max-height: 400px; overflow-y: auto;">
            <p style="text-align: center; color: #666;">Loading documents...</p>
          </div>
        </div>
      </div>

      <div id="section-payments" class="section hidden">
        <div class="header">
          <div>
            <h2>Payment Overview</h2>
            <small>View all lot payments by type (Down Payment, Cash)</small>
          </div>
        </div>

        <div class="table-section">
          <h3>Payment Summary</h3>
          <table class="accounts-table" style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
            <thead>
              <tr style="background: #f8f9fa;">
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Lot Block</th>
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Lot Number</th>
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Location</th>
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Owner</th>
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Payment Type</th>
                <th style="padding: 15px; text-align: right; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Payment Amount</th>
                <th style="padding: 15px; text-align: left; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Deadline</th>
                <th style="padding: 15px; text-align: right; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Lot Price</th>
                <th style="padding: 15px; text-align: center; font-weight: 600; color: #333; font-size: 13px; border-bottom: 2px solid #dee2e6;">Status</th>
              </tr>
            </thead>
            <tbody id="payments-tbody">
              <tr>
                <td colspan="9" style="text-align: center; padding: 30px; color: #6c757d;">Loading payments...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="section-lot-owners" class="section hidden">
        <div class="header">
          <div>
            <h2>Lot Owners</h2>
            <small>Manage lot owners and their payment status</small>
          </div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label for="lot-owner-location-filter" style="font-size:13px; color:#495057; font-weight:600;">Filter by Location</label>
            <select id="lot-owner-location-filter" style="padding:8px 10px; border:1px solid #ced4da; border-radius:6px; min-width:190px;">
              <option value="">All Locations</option>
            </select>
            <button type="button" id="refresh-lot-owners-btn" class="btn-small" style="padding:8px 12px;">Apply</button>
            <button type="button" id="register-lot-owner-btn" class="btn-small" style="padding:8px 12px; background:#3e5f3e; color:#fff; border:none; border-radius:6px; cursor:pointer;">Register Lot Owner</button>
          </div>
        </div>

        <div class="table-section">
          <h3>Lot Owners List</h3>
          <table class="accounts-table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
              <tr>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Owner Name</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Email</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Mobile</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Lot Details</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Payment Status</th>
                <th style="padding: 12px 15px; text-align: left; font-weight: 500; color: #666; font-size: 13px; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">Actions</th>
              </tr>
            </thead>
            <tbody id="lot-owners-tbody">
              <tr>
                <td colspan="6" style="text-align: center; padding: 20px; color: #666;">Loading lot owners...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div id="registerLotOwnerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff; width:95%; max-width:560px; border-radius:10px; padding:18px; box-shadow:0 12px 30px rgba(0,0,0,0.2);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3 style="margin:0; color:#2d4e1e;">Register Lot Owner</h3>
            <button type="button" id="close-register-lot-owner-modal" style="border:none;background:transparent;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
          </div>

          <div style="display:grid; gap:10px;">
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Location Bought</label>
              <select id="register-owner-location" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
                <option value="">All Locations</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Select Lot</label>
              <select id="register-owner-lot" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
                <option value="">Select lot</option>
              </select>
            </div>
            <div>
              <label style="display:block; font-size:13px; color:#495057; margin-bottom:6px;">Select Owner Account</label>
              <select id="register-owner-user" style="width:100%; padding:9px 10px; border:1px solid #ced4da; border-radius:6px;">
                <option value="">Select owner account</option>
              </select>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
            <button type="button" id="cancel-register-lot-owner" class="btn-small" style="padding:8px 12px;">Cancel</button>
            <button type="button" id="submit-register-lot-owner" class="btn-small" style="padding:8px 12px; background:#3e5f3e; color:#fff; border:none; border-radius:6px; cursor:pointer;">Register</button>
          </div>
        </div>
      </div>

    </div> </body>




    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <script>
  // Admin Dashboard JavaScript v1.3 - CRITICAL FIX - March 8, 2026
  // Fixed: Canvas positioning issue - wrapped image and canvas together for proper overlay
  // Fixed: Lots auto-load, button text "Set Pin", added drawing debug logs
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
      'section-payments',
      'section-lot-owners',
      'section-notifications',
      'section-audit-logs'
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
        loadLots(''); // Load all lots when section is shown
      } else if (targetId === 'section-analytics') {
        loadAnalyticsData();
      } else if (targetId === 'section-documents') {
        loadDocuments();
      } else if (targetId === 'section-notifications') {
        loadNotifications();
      } else if (targetId === 'section-audit-logs') {
        loadAuditLogs();
      } else if (targetId === 'section-payments') {
        loadPayments();
      } else if (targetId === 'section-lot-owners') {
        loadLotOwnerLocationOptions();
        loadLotOwners();
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
      fetch(window.location.pathname + '?fetch=documents_count')
        .then(r => r.json())
        .then(data => updateBadge(docsBadge, data.count || 0))
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
    console.log('Loading lots for location:', locationId); // Debug log
    fetch(`${window.location.pathname}?fetch=lots&location_id=${locationId}`)
      .then(response => response.json())
      .then(data => {
        console.log('Lots data received:', data); // Debug log
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
                <button onclick='openPinModal(${lot.id}, ${JSON.stringify(lot)})' class="lot-action-btn lot-action-btn-blueprint">Set Pin</button>
                <button onclick='openEditLotModal(${JSON.stringify(lot)})' class="lot-action-btn lot-action-btn-edit">Edit</button>
                <button onclick="deleteLot(${lot.id})" class="lot-action-btn lot-action-btn-delete">Delete</button>
              </td>
            `;
          });
        }

        if (newRow) tbody.appendChild(newRow);
      })
      .catch(error => {
        console.error('Error loading lots:', error);
        alert('Error loading lots. Check console for details.');
      });
  }

  function saveLot() {
    const fields = ['block_number', 'lot_number', 'lot_size', 'lot_price', 'status', 'payment_type'];
    const locationId = document.getElementById('location_id').value;
    const paymentType = document.getElementById('payment_type').value;
    const paymentAmountInput = document.getElementById('payment_amount');
    const paymentDeadlineInput = document.getElementById('payment_deadline');
    const paymentAmount = paymentAmountInput ? paymentAmountInput.value : '';
    const paymentDeadline = paymentDeadlineInput ? paymentDeadlineInput.value : '';
    
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
    formData.append('payment_deadline', data.status === 'Available' ? '' : paymentDeadline);

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
    const paymentDeadline = document.getElementById('payment_deadline');
    if (paymentDeadline) paymentDeadline.value = '';
    togglePaymentFieldsByStatus('Available');
  }

  function togglePaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('payment_type');
    const paymentDeadlineInput = document.getElementById('payment_deadline');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      if (paymentDeadlineInput) {
        paymentDeadlineInput.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
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
    const paymentDeadlineInput = document.getElementById('payment_deadline');
    const paymentAmountWrap = document.getElementById('payment_amount_wrap');
    const paymentDeadlineWrap = document.getElementById('payment_deadline_wrap');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      if (paymentAmountWrap) paymentAmountWrap.style.display = 'block';
      paymentAmountInput.required = true;
      if (paymentDeadlineInput) {
        if (paymentDeadlineWrap) paymentDeadlineWrap.style.display = 'block';
      }
    } else {
      if (paymentAmountWrap) paymentAmountWrap.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
      if (paymentDeadlineInput) {
        if (paymentDeadlineWrap) paymentDeadlineWrap.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
    }
  }

  function toggleEditDownPaymentField(paymentType) {
    const paymentAmountInput = document.getElementById('edit_payment_amount');
    const paymentDeadlineInput = document.getElementById('edit_payment_deadline');
    const paymentAmountGroup = document.getElementById('edit_payment_amount_group');
    const paymentDeadlineGroup = document.getElementById('edit_payment_deadline_group');
    if (!paymentAmountInput) return;

    if (paymentType === 'Down Payment') {
      if (paymentAmountGroup) paymentAmountGroup.style.display = 'block';
      paymentAmountInput.required = true;
      if (paymentDeadlineInput) {
        if (paymentDeadlineGroup) paymentDeadlineGroup.style.display = 'block';
      }
    } else {
      if (paymentAmountGroup) paymentAmountGroup.style.display = 'none';
      paymentAmountInput.required = false;
      paymentAmountInput.value = '';
      if (paymentDeadlineInput) {
        if (paymentDeadlineGroup) paymentDeadlineGroup.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
    }
  }

  function toggleEditPaymentFieldsByStatus(status) {
    const paymentTypeSelect = document.getElementById('edit_payment_type');
    const paymentDeadlineInput = document.getElementById('edit_payment_deadline');
    if (!paymentTypeSelect) return;

    if (status === 'Available') {
      paymentTypeSelect.value = 'Not Applicable';
      paymentTypeSelect.disabled = true;
      if (paymentDeadlineInput) {
        paymentDeadlineInput.style.display = 'none';
        paymentDeadlineInput.value = '';
      }
      toggleEditDownPaymentField('Not Applicable');
      return;
    }

    paymentTypeSelect.disabled = false;
    if (paymentDeadlineInput) {
      paymentDeadlineInput.style.display = 'block';
    }
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

  // Backward-compatible notifier used by multiple sections.
  function showMessage(msg, success = true) {
    const msgDiv = document.getElementById('lot-message');
    if (msgDiv) {
      showLotMessage(msg, success);
      return;
    }
    if (!success) {
      alert(msg);
    } else {
      console.log(msg);
    }
  }

  // ===========================
  // DOCUMENT REVIEW FUNCTIONS
  // ===========================
  function loadDocuments() {
    const container = document.getElementById('documents-container');
    if (!container) return;

    container.innerHTML = '<p style="text-align: center; color: #666;">Loading documents...</p>';

    const params = new URLSearchParams();
    params.append('fetch', 'documents');

    const status = document.getElementById('docs_status')?.value || '';
    const docType = document.getElementById('docs_type')?.value?.trim() || '';
    const dateFrom = document.getElementById('docs_date_from')?.value || '';
    const dateTo = document.getElementById('docs_date_to')?.value || '';
    const search = document.getElementById('docs_search')?.value?.trim() || '';

    if (status) params.append('status', status);
    if (docType) params.append('doc_type', docType);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(documents => {
        if (!documents.length) {
          container.innerHTML = '<p style="text-align: center; color: #666;">No pending documents found.</p>';
          return;
        }

        container.innerHTML = documents.map(doc => {
          const docId = doc.id ?? doc.doc_id ?? doc.document_id;
          const source = doc.source || 'user_documents';
          const fileName = doc.file_name || doc.filename || 'Untitled Document';
          const docTypeLabel = doc.doc_type || doc.type || 'N/A';
          const actionButtons = docId
            ? `<button class="btn btn-sm btn-primary" onclick="approveDocument(${docId}, '${source}')">Approve</button>
               <button class="btn btn-sm btn-danger"  onclick="rejectDocument(${docId}, '${source}')">Reject</button>`
            : '';

          return `
            <div data-doc-id="${docId ?? ''}" style="padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff; border: 1px solid #e0e0e0;">
              <strong>${fileName}</strong>
              <div style="font-size: 13px; color: #333;">Type: ${docTypeLabel}</div>
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

  function applyDocumentFilters() {
    loadDocuments();
  }

  function resetDocumentFilters() {
    const ids = ['docs_status', 'docs_type', 'docs_date_from', 'docs_date_to', 'docs_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadDocuments();
  }

  function approveDocument(id, source = 'user_documents') {
    if (!confirm('Approve this document?')) return;

    const formData = new FormData();
    formData.append('action', 'approve_document');
    formData.append('doc_id', id);
    formData.append('doc_source', source);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Document approved.', true);
          loadDocuments();
          refreshBadges();
        } else {
          alert('Failed to approve document: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(() => alert('Failed to approve document.'));
  }

  function rejectDocument(id, source = 'user_documents') {
    const remarks = prompt('Enter remarks for rejection (optional):', '');
    if (remarks === null) return;

    const formData = new FormData();
    formData.append('action', 'reject_document');
    formData.append('doc_id', id);
    formData.append('remarks', remarks);
    formData.append('doc_source', source);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Document rejected.', true);
          loadDocuments();
          refreshBadges();
        } else {
          alert('Failed to reject document: ' + (res.error || 'Unknown error'));
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

    const params = new URLSearchParams();
    params.append('fetch', 'notifications');

    const type = document.getElementById('notif_type')?.value || '';
    const dateFrom = document.getElementById('notif_date_from')?.value || '';
    const dateTo = document.getElementById('notif_date_to')?.value || '';
    const search = document.getElementById('notif_search')?.value?.trim() || '';

    if (type) params.append('type', type);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
      .then(response => response.json())
      .then(notifications => {
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

  function applyNotificationFilters() {
    loadNotifications();
  }

  function resetNotificationFilters() {
    const ids = ['notif_type', 'notif_date_from', 'notif_date_to', 'notif_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadNotifications();
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

    const params = new URLSearchParams();
    params.append('fetch', 'audit_logs');

    const action = document.getElementById('audit_action')?.value?.trim() || '';
    const dateFrom = document.getElementById('audit_date_from')?.value || '';
    const dateTo = document.getElementById('audit_date_to')?.value || '';
    const search = document.getElementById('audit_search')?.value?.trim() || '';

    if (action) params.append('action', action);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (search) params.append('search', search);

    fetch(window.location.pathname + '?' + params.toString())
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

  function applyAuditFilters() {
    loadAuditLogs();
  }

  function resetAuditFilters() {
    const ids = ['audit_action', 'audit_date_from', 'audit_date_to', 'audit_search'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    loadAuditLogs();
  }

  // ===========================
  // PAYMENTS FUNCTIONS
  // ===========================
  function loadPayments() {
    const tbody = document.getElementById('payments-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 30px; color: #6c757d;">Loading payments...</td></tr>';

    fetch(window.location.pathname + '?fetch=all_payments', { method: 'GET' })
      .then(response => response.json())
      .then(data => {
        if (!data || !data.payments || data.payments.length === 0) {
          tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #6c757d;">No payments found.</td></tr>';
          return;
        }

        tbody.innerHTML = data.payments.map(payment => `
          <tr style="border-bottom: 1px solid #f0f0f0; transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
            <td style="padding: 15px; font-size: 14px; color: #333;">${payment.block_number || 'N/A'}</td>
            <td style="padding: 15px; font-size: 14px; color: #333;">${payment.lot_number || 'N/A'}</td>
            <td style="padding: 15px; font-size: 14px; color: #333;">${payment.location_name || 'N/A'}</td>
            <td style="padding: 15px; font-size: 14px; color: #333;">${payment.owner_name || '<span style="color: #6c757d; font-style: italic;">Unassigned</span>'}</td>
            <td style="padding: 15px; font-size: 14px; color: #333;">
              <span style="background: ${getPaymentTypeColor(payment.payment_type)}; padding: 5px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; color: white; display: inline-block;">
                ${payment.payment_type || 'N/A'}
              </span>
            </td>
            <td style="padding: 15px; font-size: 14px; color: #333; text-align: right; white-space: nowrap; font-weight: 600;">₱${payment.payment_amount ? parseFloat(payment.payment_amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '<span style="color: #6c757d;">N/A</span>'}</td>
            <td style="padding: 15px; font-size: 14px; color: #333; white-space: nowrap;">${payment.payment_type === 'Down Payment' && payment.payment_deadline ? new Date(payment.payment_deadline + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '<span style="color: #6c757d;">-</span>'}</td>
            <td style="padding: 15px; font-size: 14px; color: #495057; text-align: right; white-space: nowrap; font-weight: 600;">₱${payment.lot_price ? parseFloat(payment.lot_price).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '<span style="color: #6c757d;">N/A</span>'}</td>
            <td style="padding: 15px; font-size: 14px; text-align: center; min-width: 220px; white-space: nowrap;">
              <select 
                id="payment-status-${payment.lot_id}" 
                class="payment-status-select" 
                style="padding: 6px 34px 6px 12px; border: 1px solid #dee2e6; border-radius: 4px; cursor: pointer; background: white; font-size: 13px; font-weight: 500; width: 200px; min-width: 200px; max-width: 200px; box-sizing: border-box;" 
                onchange="updatePaymentStatus(${payment.lot_id}, this.value)">
                <option value="Available" ${payment.status === 'Available' ? 'selected' : ''}>Available</option>
                <option value="Reserved" ${payment.status === 'Reserved' ? 'selected' : ''}>Reserved</option>
                <option value="Sold" ${payment.status === 'Sold' ? 'selected' : ''}>Sold</option>
                <option value="Paid" ${payment.status === 'Paid' ? 'selected' : ''}>Paid</option>
              </select>
            </td>
          </tr>
        `).join('');
      })
      .catch(error => {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 30px; color: #dc3545;">Failed to load payments.</td></tr>';
        console.error('Error loading payments:', error);
      });
  }

  function getPaymentTypeColor(type) {
    switch(type) {
      case 'Down Payment': return '#007bff';
      case 'Cash': return '#28a745';
      case 'Fully Paid': return '#20c997';
      default: return '#6c757d';
    }
  }

  function getStatusColor(status) {
    switch(status) {
      case 'Available': return '#6c757d';
      case 'Sold': return '#dc3545';
      case 'Reserved': return '#ffc107';
      case 'Paid': return '#28a745';
      default: return '#6c757d';
    }
  }

  // Update Payment Status - robust parser for mixed responses
  function updatePaymentStatus(lotId, newStatus) {
    const selectElement = document.getElementById(`payment-status-${lotId}`);
    const previousValue = selectElement ? selectElement.value : newStatus;

    const formData = new FormData();
    formData.append('action', 'update_lot_status');
    formData.append('lot_id', lotId);
    formData.append('status', newStatus);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.text())
      .then(text => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
            res = JSON.parse(text.slice(firstBrace, lastBrace + 1));
          } else {
            throw new Error('Non-JSON response: ' + text.slice(0, 180));
          }
        }

        if (res.success) {
          showMessage('Payment status updated successfully', true);
          return;
        }

        if (selectElement) {
          selectElement.value = previousValue;
        }
        alert('Failed to update payment status: ' + (res.error || 'Unknown error'));
      })
      .catch(error => {
        if (selectElement) {
          selectElement.value = previousValue;
        }
        console.error('Status update response error:', error);
        alert('Failed to update payment status. ' + (error.message || 'Please check console.'));
      });
  }

  // ===========================
  // LOT OWNERS FUNCTIONS
  // ===========================
  function loadLotOwnerLocationOptions() {
    fetch(window.location.pathname + '?fetch=locations', { method: 'GET' })
      .then(r => r.json())
      .then(locations => {
        const filterSelect = document.getElementById('lot-owner-location-filter');
        const modalSelect = document.getElementById('register-owner-location');
        if (!filterSelect || !modalSelect) return;

        const currentFilter = filterSelect.value || '';
        const currentModal = modalSelect.value || '';

        const optionsHtml = ['<option value="">All Locations</option>']
          .concat((locations || []).map(loc => `<option value="${loc.id}">${loc.location_name}</option>`))
          .join('');

        filterSelect.innerHTML = optionsHtml;
        modalSelect.innerHTML = optionsHtml;

        filterSelect.value = currentFilter;
        modalSelect.value = currentModal;
      })
      .catch(err => console.error('Failed to load location options:', err));
  }

  function loadLotOwners() {
    const tbody = document.getElementById('lot-owners-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #666;">Loading lot owners...</td></tr>';

    const selectedLocation = document.getElementById('lot-owner-location-filter')?.value || '';
    const params = new URLSearchParams();
    params.append('fetch', 'all_lot_owners');
    if (selectedLocation) params.append('location_id', selectedLocation);

    fetch(window.location.pathname + '?' + params.toString(), { method: 'GET' })
      .then(response => response.json())
      .then(data => {
        if (!data || !data.owners || data.owners.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #666;">No lot owners found.</td></tr>';
          return;
        }

        tbody.innerHTML = data.owners.map(owner => `
          <tr>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.owner_name || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.email || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.mobile_number || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">${owner.location_name || 'N/A'} - Block ${owner.block_number || 'N/A'}, Lot ${owner.lot_number || 'N/A'}</td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">
              <select id="status-${owner.lot_id}" class="lot-status-select" style="padding: 6px 30px 6px 10px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; width: 170px; min-width: 170px; max-width: 170px; box-sizing: border-box;" onchange="updateLotPaymentStatus(${owner.lot_id}, this.value)">
                <option value="Available" ${owner.status === 'Available' ? 'selected' : ''}>Available</option>
                <option value="Reserved" ${owner.status === 'Reserved' ? 'selected' : ''}>Reserved</option>
                <option value="Sold" ${owner.status === 'Sold' ? 'selected' : ''}>Sold</option>
                <option value="Paid" ${owner.status === 'Paid' ? 'selected' : ''}>Paid</option>
              </select>
            </td>
            <td style="padding: 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; color: #333;">
              <button class="btn-small" onclick="viewOwnerDetails(${owner.user_id})">View Details</button>
              <button class="btn-small btn-danger" onclick="removeLotOwner(${owner.lot_id})">Remove Owner</button>
            </td>
          </tr>
        `).join('');
      })
      .catch(error => {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #dc3545;">Failed to load lot owners.</td></tr>';
        console.error('Error loading lot owners:', error);
      });
  }

  function loadOwnerAccountsForRegistration() {
    const ownerSelect = document.getElementById('register-owner-user');
    if (!ownerSelect) return;
    ownerSelect.innerHTML = '<option value="">Loading owner accounts...</option>';

    fetch(window.location.pathname + '?fetch=owner_users', { method: 'GET' })
      .then(r => r.json())
      .then(data => {
        const users = data?.users || [];
        ownerSelect.innerHTML = '<option value="">Select owner account</option>';
        users.forEach(u => {
          ownerSelect.innerHTML += `<option value="${u.id}">${u.name} (${u.email || 'No email'})</option>`;
        });
      })
      .catch(() => {
        ownerSelect.innerHTML = '<option value="">Failed to load owner accounts</option>';
      });
  }

  function loadAssignableLotsForRegistration(locationId = '') {
    const lotSelect = document.getElementById('register-owner-lot');
    if (!lotSelect) return;

    lotSelect.innerHTML = '<option value="">Loading lots...</option>';
    const params = new URLSearchParams();
    params.append('fetch', 'owner_assignable_lots');
    if (locationId) params.append('location_id', locationId);

    fetch(window.location.pathname + '?' + params.toString(), { method: 'GET' })
      .then(r => r.json())
      .then(data => {
        const lots = data?.lots || [];
        lotSelect.innerHTML = '<option value="">Select lot</option>';
        lots.forEach(lot => {
          lotSelect.innerHTML += `<option value="${lot.id}">${lot.location_name || 'N/A'} - Block ${lot.block_number}, Lot ${lot.lot_number}</option>`;
        });
      })
      .catch(() => {
        lotSelect.innerHTML = '<option value="">Failed to load lots</option>';
      });
  }

  function openRegisterLotOwnerModal() {
    const modal = document.getElementById('registerLotOwnerModal');
    if (!modal) return;
    modal.style.display = 'flex';

    loadLotOwnerLocationOptions();
    loadOwnerAccountsForRegistration();
    loadAssignableLotsForRegistration('');
  }

  function closeRegisterLotOwnerModal() {
    const modal = document.getElementById('registerLotOwnerModal');
    if (modal) modal.style.display = 'none';
  }

  function submitRegisterLotOwner() {
    const lotId = document.getElementById('register-owner-lot')?.value || '';
    const userId = document.getElementById('register-owner-user')?.value || '';

    if (!lotId || !userId) {
      alert('Please select both lot and owner account.');
      return;
    }

    const fd = new FormData();
    fd.append('action', 'register_lot_owner');
    fd.append('lot_id', lotId);
    fd.append('user_id', userId);

    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Lot owner registered successfully.', true);
          closeRegisterLotOwnerModal();
          loadLotOwners();
          loadPayments();
        } else {
          alert('Failed to register lot owner: ' + (res.error || res.message || 'Unknown error'));
        }
      })
      .catch(err => {
        console.error('Register lot owner error:', err);
        alert('Failed to register lot owner.');
      });
  }

  function updateLotPaymentStatus(lotId, newStatus) {
    const formData = new FormData();
    formData.append('action', 'update_lot_status');
    formData.append('lot_id', lotId);
    formData.append('status', newStatus);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.text())
      .then(text => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          const firstBrace = text.indexOf('{');
          const lastBrace = text.lastIndexOf('}');
          if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
            res = JSON.parse(text.slice(firstBrace, lastBrace + 1));
          } else {
            throw new Error('Non-JSON response: ' + text.slice(0, 180));
          }
        }

        if (res.success) {
          showMessage('Payment status updated successfully', true);
        } else {
          alert('Failed to update payment status: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(error => {
        console.error('Lot owner status response error:', error);
        alert('Failed to update payment status. ' + (error.message || 'Please check console.'));
      });
  }

  function removeLotOwner(lotId) {
    if (!confirm('Remove the owner from this lot?')) return;

    const formData = new FormData();
    formData.append('action', 'remove_lot_owner');
    formData.append('lot_id', lotId);

    fetch(window.location.pathname, { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showMessage('Owner removed successfully', true);
          loadPayments();
          loadLotOwners(); // Reload the list
        } else {
          alert('Failed to remove owner: ' + (res.error || 'Unknown error'));
        }
      })
      .catch(error => {
        alert('Failed to remove owner.');
        console.error('Error:', error);
      });
  }

  function viewOwnerDetails(userId) {
    // Fetch and display owner details
    fetch(window.location.pathname + '?fetch=user&id=' + userId)
      .then(r => r.json())
      .then(user => {
        alert('Owner Name: ' + user.first_name + ' ' + user.last_name + '\n' + 
              'Email: ' + user.email + '\n' + 
              'Mobile: ' + user.mobile_number + '\n' + 
              'Address: ' + user.address);
      })
      .catch(error => alert('Failed to fetch owner details.'));
  }

  // Lot owner page controls
  document.getElementById('refresh-lot-owners-btn')?.addEventListener('click', loadLotOwners);
  document.getElementById('lot-owner-location-filter')?.addEventListener('change', loadLotOwners);
  document.getElementById('register-lot-owner-btn')?.addEventListener('click', openRegisterLotOwnerModal);
  document.getElementById('close-register-lot-owner-modal')?.addEventListener('click', closeRegisterLotOwnerModal);
  document.getElementById('cancel-register-lot-owner')?.addEventListener('click', closeRegisterLotOwnerModal);
  document.getElementById('submit-register-lot-owner')?.addEventListener('click', submitRegisterLotOwner);
  document.getElementById('register-owner-location')?.addEventListener('change', function() {
    loadAssignableLotsForRegistration(this.value || '');
  });

  window.addEventListener('click', function(e) {
    const modal = document.getElementById('registerLotOwnerModal');
    if (modal && e.target === modal) closeRegisterLotOwnerModal();
  });

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
    applyAnalyticsFilters();
  }

  function loadAnalyticsKPIs() {
    applyAnalyticsFilters();
  }

  function updateSalesRangeLabel(dateFrom, dateTo) {
    const rangeLabelEl = document.getElementById('kpi-total-sales-range-label');
    if (!rangeLabelEl) return;

    if (dateFrom && dateTo) {
      rangeLabelEl.textContent = `Filtered: ${dateFrom} to ${dateTo}`;
      return;
    }
    if (dateFrom) {
      rangeLabelEl.textContent = `Filtered: from ${dateFrom}`;
      return;
    }
    if (dateTo) {
      rangeLabelEl.textContent = `Filtered: up to ${dateTo}`;
      return;
    }
    rangeLabelEl.textContent = 'All recorded sales';
  }

  function renderAnalyticsKpis(data) {
    const totalSalesEl = document.getElementById('kpi-total-sales');
    const totalLotsEl  = document.getElementById('kpi-total-lots');
    const agentsEl     = document.getElementById('kpi-available-agents');
    const pendingEl    = document.getElementById('kpi-pending-documents');

    if (totalSalesEl) totalSalesEl.textContent = '₱' + (data.kpis.total_sales || 0).toLocaleString();
    if (totalLotsEl)  totalLotsEl.textContent  = data.kpis.total_lots || 0;
    if (agentsEl)     agentsEl.textContent     = data.kpis.available_agents || 0;
    if (pendingEl)    pendingEl.textContent    = data.kpis.pending_documents || 0;
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

  let lastMonthlySalesData = [];

  function loadMonthlySalesChart() {
    updateMonthlySalesChart([]);
  }

  function formatPesoCompact(value) {
    const n = Number(value) || 0;
    return 'PHP ' + new Intl.NumberFormat('en-PH', {
      notation: 'compact',
      maximumFractionDigits: 1
    }).format(n);
  }

  function formatPesoFull(value) {
    const n = Number(value) || 0;
    return 'PHP ' + new Intl.NumberFormat('en-PH', {
      maximumFractionDigits: 0
    }).format(n);
  }

  function setupResponsiveCanvas(canvas) {
    const wrap = document.getElementById('monthly-sales-chart-wrap') || canvas.parentElement;
    const cssWidth = Math.max(360, Math.floor((wrap?.clientWidth || 800) - 8));
    const cssHeight = 320;
    const dpr = window.devicePixelRatio || 1;

    canvas.style.width = cssWidth + 'px';
    canvas.style.height = cssHeight + 'px';
    canvas.width = Math.floor(cssWidth * dpr);
    canvas.height = Math.floor(cssHeight * dpr);

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width: cssWidth, height: cssHeight };
  }

  function updateMonthlySalesTitle(data, dateFrom, dateTo) {
    const titleEl = document.getElementById('monthly-sales-title');
    if (!titleEl) return;

    if (data && data.monthly_scope === 'last_12_months' && !dateFrom && !dateTo) {
      titleEl.textContent = 'Monthly Sales Trend (Last 12 Months)';
      return;
    }

    if (dateFrom && dateTo) {
      titleEl.textContent = `Monthly Sales Trend (${dateFrom} to ${dateTo})`;
      return;
    }
    if (dateFrom) {
      titleEl.textContent = `Monthly Sales Trend (From ${dateFrom})`;
      return;
    }
    if (dateTo) {
      titleEl.textContent = `Monthly Sales Trend (Up to ${dateTo})`;
      return;
    }

    titleEl.textContent = 'Monthly Sales Trend';
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
        renderAnalyticsKpis(data);
        updateSalesRangeLabel(dateFrom, dateTo);
        updateMonthlySalesTitle(data, dateFrom, dateTo);

        const monthly = Array.isArray(data.monthly_sales) ? data.monthly_sales : [];
        lastMonthlySalesData = monthly;
        updateMonthlySalesChart(monthly);
        loadTopAgents(1);
      })
      .catch(err => {
        alert('Failed to load analytics data.');
        console.error(err);
      });
  }

  function updateMonthlySalesChart(monthlySalesData) {
    const canvas = document.getElementById('monthly-sales-chart');
    if (!canvas) return;
    const tooltip = document.getElementById('monthly-sales-tooltip');
    const wrap = document.getElementById('monthly-sales-chart-wrap');

    const { ctx, width, height } = setupResponsiveCanvas(canvas);
    const data = monthlySalesData.length ? monthlySalesData : [];
    if (canvas._animFrame) {
      cancelAnimationFrame(canvas._animFrame);
      canvas._animFrame = null;
    }

    const chart = {
      left: 70,
      right: width - 24,
      top: 20,
      bottom: height - 52
    };
    const plotWidth = Math.max(1, chart.right - chart.left);
    const plotHeight = Math.max(1, chart.bottom - chart.top);

    if (!data.length) {
      canvas._hitPoints = [];
      canvas._drawStatic = null;
      canvas._hoverIndex = -1;
      if (tooltip) tooltip.style.display = 'none';

      ctx.clearRect(0, 0, width, height);
      ctx.fillStyle = '#f7faf7';
      ctx.fillRect(0, 0, width, height);
      ctx.fillStyle = '#777';
      ctx.font = '600 14px Segoe UI, Arial, sans-serif';
      ctx.fillText('No sales data for selected filters.', chart.left + 8, height / 2);
      return;
    }

    const amounts = data.map(item => Number(item.amount) || 0);
    const maxAmount = Math.max(...amounts, 1);
    const minAmount = Math.min(...amounts, 0);
    const range = Math.max(1, maxAmount - minAmount);

    const xFor = (i) => {
      if (data.length === 1) return chart.left + (plotWidth / 2);
      return chart.left + (i / (data.length - 1)) * plotWidth;
    };
    const yFor = (amount, progress) => {
      const animatedAmount = minAmount + (amount - minAmount) * progress;
      const ratio = (animatedAmount - minAmount) / range;
      return chart.bottom - (ratio * plotHeight);
    };

    const drawFrame = (progress, hoverIndex = -1) => {
      ctx.clearRect(0, 0, width, height);

      const bgGrad = ctx.createLinearGradient(0, 0, 0, height);
      bgGrad.addColorStop(0, '#fcfdfc');
      bgGrad.addColorStop(1, '#f3f8f3');
      ctx.fillStyle = bgGrad;
      ctx.fillRect(0, 0, width, height);

      const ticks = 4;
      ctx.strokeStyle = '#e5e7eb';
      ctx.lineWidth = 1;
      ctx.fillStyle = '#6b7280';
      ctx.font = '11px Segoe UI, Arial, sans-serif';

      for (let t = 0; t <= ticks; t++) {
        const ratio = t / ticks;
        const y = chart.bottom - (ratio * plotHeight);
        const value = minAmount + (range * ratio);

        ctx.beginPath();
        ctx.moveTo(chart.left, y);
        ctx.lineTo(chart.right, y);
        ctx.stroke();

        ctx.textAlign = 'right';
        ctx.fillText(formatPesoCompact(value), chart.left - 10, y + 4);
      }

      const labelStep = Math.max(1, Math.ceil(data.length / 8));
      ctx.textAlign = 'center';
      data.forEach((item, i) => {
        if (i % labelStep !== 0 && i !== data.length - 1) return;
        const x = xFor(i);
        ctx.fillStyle = '#6b7280';
        ctx.fillText(item.month, x, chart.bottom + 22);
      });

      const points = data.map((item, i) => ({
        x: xFor(i),
        y: yFor(Number(item.amount) || 0, progress),
        amount: Number(item.amount) || 0,
        month: item.month
      }));

      const areaGrad = ctx.createLinearGradient(0, chart.top, 0, chart.bottom);
      areaGrad.addColorStop(0, 'rgba(34, 139, 78, 0.28)');
      areaGrad.addColorStop(1, 'rgba(34, 139, 78, 0.04)');

      ctx.beginPath();
      ctx.moveTo(points[0].x, chart.bottom);
      points.forEach((p) => ctx.lineTo(p.x, p.y));
      ctx.lineTo(points[points.length - 1].x, chart.bottom);
      ctx.closePath();
      ctx.fillStyle = areaGrad;
      ctx.fill();

      ctx.beginPath();
      points.forEach((p, idx) => {
        if (idx === 0) ctx.moveTo(p.x, p.y);
        else ctx.lineTo(p.x, p.y);
      });
      ctx.strokeStyle = '#1e7a4a';
      ctx.lineWidth = 3;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.stroke();

      points.forEach((p, i) => {
        if (i % labelStep !== 0 && i !== points.length - 1) return;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#1e7a4a';
        ctx.lineWidth = 2;
        ctx.stroke();
      });

      if (hoverIndex >= 0 && points[hoverIndex]) {
        const hp = points[hoverIndex];

        ctx.setLineDash([5, 4]);
        ctx.strokeStyle = 'rgba(30,122,74,0.35)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(hp.x, chart.top);
        ctx.lineTo(hp.x, chart.bottom);
        ctx.stroke();
        ctx.setLineDash([]);

        ctx.beginPath();
        ctx.arc(hp.x, hp.y, 7, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.strokeStyle = '#14532d';
        ctx.lineWidth = 3;
        ctx.stroke();
      }

      const last = points[points.length - 1];
      if (last) {
        const text = formatPesoFull(last.amount);
        ctx.font = 'bold 12px Segoe UI, Arial, sans-serif';
        const tw = ctx.measureText(text).width;
        const bx = Math.min(chart.right - tw - 18, last.x + 8);
        const by = Math.max(chart.top + 8, last.y - 28);
        ctx.fillStyle = 'rgba(30,122,74,0.95)';
        ctx.fillRect(bx, by, tw + 12, 20);
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.fillText(text, bx + (tw + 12) / 2, by + 14);
      }

      if (progress >= 1) {
        canvas._hitPoints = points;
        canvas._drawStatic = (hoverIdx) => drawFrame(1, hoverIdx);
      }
    };

    const duration = 650;
    const start = performance.now();
    const animate = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const p = 1 - Math.pow(1 - t, 3);
      drawFrame(p, canvas._hoverIndex ?? -1);
      if (t < 1) {
        canvas._animFrame = requestAnimationFrame(animate);
      }
    };

    if (!canvas._hoverBound) {
      canvas.addEventListener('mousemove', (ev) => {
        if (!canvas._hitPoints || !canvas._hitPoints.length || !canvas._drawStatic) return;

        const rect = canvas.getBoundingClientRect();
        const x = ev.clientX - rect.left;

        let nearest = -1;
        let minDx = Number.POSITIVE_INFINITY;
        for (let i = 0; i < canvas._hitPoints.length; i++) {
          const dx = Math.abs(canvas._hitPoints[i].x - x);
          if (dx < minDx) {
            minDx = dx;
            nearest = i;
          }
        }

        if (nearest < 0 || minDx > 28) {
          if (tooltip) tooltip.style.display = 'none';
          if ((canvas._hoverIndex ?? -1) !== -1) {
            canvas._hoverIndex = -1;
            canvas._drawStatic(-1);
          }
          return;
        }

        const point = canvas._hitPoints[nearest];
        if (tooltip && wrap && point) {
          tooltip.innerHTML = `<div style="font-weight:600; margin-bottom:2px;">${point.month}</div><div>${formatPesoFull(point.amount)}</div>`;
          tooltip.style.display = 'block';

          const tipWidth = tooltip.offsetWidth || 110;
          const left = Math.max(8, Math.min(point.x - (tipWidth / 2), (wrap.clientWidth - tipWidth - 8)));
          const top = Math.max(8, point.y - 56);
          tooltip.style.left = `${left}px`;
          tooltip.style.top = `${top}px`;
        }

        if ((canvas._hoverIndex ?? -1) !== nearest) {
          canvas._hoverIndex = nearest;
          canvas._drawStatic(nearest);
        }
      });

      canvas.addEventListener('mouseleave', () => {
        if (tooltip) tooltip.style.display = 'none';
        if ((canvas._hoverIndex ?? -1) !== -1 && canvas._drawStatic) {
          canvas._hoverIndex = -1;
          canvas._drawStatic(-1);
        }
      });

      canvas._hoverBound = true;
    }

    canvas._animFrame = requestAnimationFrame(animate);
  }

  window.addEventListener('resize', () => {
    if (!lastMonthlySalesData.length) return;
    updateMonthlySalesChart(lastMonthlySalesData);
  });

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
      <div class="form-group" id="edit_payment_amount_group">
        <label>Down Payment Amount</label>
        <input type="number" step="0.01" min="0" name="payment_amount" id="edit_payment_amount" value="${lot.payment_amount || ''}" placeholder="Enter down payment amount">
      </div>
      <div class="form-group" id="edit_payment_deadline_group">
        <label>Payment Deadline</label>
        <input type="date" name="payment_deadline" id="edit_payment_deadline" value="${lot.payment_deadline || ''}">
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

// --- Mapper Globals ---
let pzInstance = null;
let svgMain = null, staticGroup = null, liveGroup = null;
let pinModalData = {
    lot_id: null,
    isDrawingMode: false,
    polygonPoints: [],
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
            buttonEl.style.background = { 'Available': '#28a745', 'Reserved': '#ffc107', 'Sold': '#dc3545' }[btn];
            buttonEl.style.color = btn === 'Reserved' ? '#333' : 'white';
        } else {
            buttonEl.style.background = 'white';
            buttonEl.style.color = { 'Available': '#28a745', 'Reserved': '#ffc107', 'Sold': '#dc3545' }[btn];
        }
    });

    if (pinModalData.polygonPoints.length > 0) {
        drawLivePolygon(pinModalData.polygonPoints.length > 2 && !pinModalData.isDrawingMode);
    }
}

// --- Open Mapper Modal ---
function openPinModal(lotId, lotData) {
    pinModalData.lot_id = lotId;
    document.getElementById('pinModal').style.display = 'flex';
    
    fetch(`?fetch=blueprint&lot_id=${lotId}`)
    .then(r => r.json())
    .then(data => {
        const img = document.getElementById('blueprintImage');
        const wrapper = document.getElementById('blueprint-wrapper');
        const layer = document.getElementById('draw-layer');
        const stage = document.getElementById('blueprint-stage');
        
        if (!data.blueprint) {
            alert("No blueprint found for this location. Please upload a blueprint first.");
            closePinModal();
            return;
        }

        img.src = data.blueprint; 
        layer.innerHTML = ''; 

        // Reset and init Panzoom
        if(pzInstance) {
            pzInstance.destroy();
            pzInstance = null;
        }
        wrapper.style.transform = 'scale(1) translate(0px, 0px)'; 
        
        // Wait for image to load to apply correct dimensions
        img.onload = () => {
            pzInstance = Panzoom(wrapper, { maxScale: 10, minScale: 0.5, step: 0.2, cursor: 'grab' });
            stage.onwheel = e => { e.preventDefault(); pzInstance.zoomWithWheel(e); };
            
            // Setup Native SVG Canvas overlay
            svgMain = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svgMain.setAttribute('width', '100%');
            svgMain.setAttribute('height', '100%');
            svgMain.setAttribute('viewBox', '0 0 100 100'); // Calculates by % 0-100
            svgMain.setAttribute('preserveAspectRatio', 'none');
            svgMain.style.position = 'absolute';
            svgMain.style.top = '0';
            svgMain.style.left = '0';
            
            staticGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            liveGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            svgMain.appendChild(staticGroup);
            svgMain.appendChild(liveGroup);
            layer.appendChild(svgMain);

            // Draw existing background pins
            if(data.all_pins) {
                data.all_pins.forEach(pin => {
                    if(pin.coordinates && Array.isArray(pin.coordinates) && pin.lot_id != lotId) {
                        let poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        poly.setAttribute('points', pin.coordinates.map(pt => `${pt.x},${pt.y}`).join(' '));
                        
                        let stat = (pin.pin_status || 'Available').toLowerCase();
                        poly.setAttribute('fill', stat==='sold' ? 'rgba(220,53,69,0.4)' : (stat==='reserved' ? 'rgba(255,193,7,0.4)' : 'rgba(40,167,69,0.4)'));
                        poly.setAttribute('stroke', stat==='sold' ? 'red' : (stat==='reserved' ? 'gold' : 'green'));
                        poly.setAttribute('stroke-width', '0.2');
                        poly.setAttribute('vector-effect', 'non-scaling-stroke');
                        staticGroup.appendChild(poly);
                    }
                });
            }

            // Load active lot data
            pinModalData.polygonPoints = (data.pin && Array.isArray(data.pin)) ? data.pin : [];
            pinModalData.selectedStatus = data.pin_status || 'Available';
            
            const lotInfo = document.getElementById('pinModalLotInfo');
            if (lotInfo && data.lot) lotInfo.textContent = `Block ${data.lot.block_number} - Lot ${data.lot.lot_number}`;

            selectLotStatus(pinModalData.selectedStatus); 
            drawLivePolygon(pinModalData.polygonPoints.length > 2);
            
            pinModalData.isDrawingMode = false;
            const btn = document.getElementById('toggleDrawBtn');
            if (btn) {
                btn.textContent = 'Start Drawing Polygon';
                btn.style.background = '#28a745';
            }
            layer.style.pointerEvents = 'none'; // Allow panning map on default
        };
    })
    .catch(err => {
        console.error(err);
        alert('Error loading blueprint data.');
    });
}

// --- Toggle Drawing ---
function toggleDrawingMode() {
    const btn = document.getElementById('toggleDrawBtn');
    const layer = document.getElementById('draw-layer');
    const wrapper = document.getElementById('blueprint-wrapper');
    
    pinModalData.isDrawingMode = !pinModalData.isDrawingMode;

    if(pinModalData.isDrawingMode) {
        if (btn) {
            btn.textContent = 'Stop / Double Click to Finish';
            btn.style.background = '#dc3545';
        }
        layer.style.pointerEvents = 'auto'; // Block map panning, enable capturing clicks
        layer.style.cursor = 'crosshair';
        
        // Stop Panzoom from capturing drag events
        if (pzInstance) pzInstance.setOptions({ disablePan: true });

        pinModalData.polygonPoints = [];
        liveGroup.innerHTML = '';
        
        let startX, startY;
        layer.onpointerdown = e => { 
            e.stopPropagation();
            startX = e.clientX; 
            startY = e.clientY; 
        };
        
        layer.onpointerup = e => {
            e.stopPropagation();
            if(!pinModalData.isDrawingMode) return;
            // Prevent click triggers when dragging slightly
            if(Math.abs(e.clientX - startX) > 5 || Math.abs(e.clientY - startY) > 5) return; 
            
            const rect = layer.getBoundingClientRect();
            // Store percentages 0-100 for proper scaling
            pinModalData.polygonPoints.push({
                x: ((e.clientX - rect.left) / rect.width) * 100,
                y: ((e.clientY - rect.top) / rect.height) * 100
            });
            drawLivePolygon(false);
        };
        
        layer.ondblclick = e => {
            e.stopPropagation();
            if(pinModalData.polygonPoints.length > 2) {
                finishDrawing();
            }
        };
    } else {
        finishDrawing();
    }
}

function finishDrawing() {
    const btn = document.getElementById('toggleDrawBtn');
    const layer = document.getElementById('draw-layer');
    pinModalData.isDrawingMode = false;

    if (btn) {
        btn.textContent = 'Start Drawing Polygon';
        btn.style.background = '#28a745';
    }
    
    layer.style.pointerEvents = 'none'; // Re-enable map panning
    layer.style.cursor = 'default';
    if (pzInstance) pzInstance.setOptions({ disablePan: false });

    layer.onpointerdown = null; 
    layer.onpointerup = null; 
    layer.ondblclick = null;

    if(pinModalData.polygonPoints.length > 2) {
        drawLivePolygon(true); // Close the polygon
    } else {
        liveGroup.innerHTML = ''; // Abort if they didn't draw a valid polygon
        pinModalData.polygonPoints = [];
    }
}

// --- Render the Active Drawing ---
function drawLivePolygon(closed) {
    liveGroup.innerHTML = '';
    if(pinModalData.polygonPoints.length === 0) return;

    const ptsStr = pinModalData.polygonPoints.map(pt => `${pt.x},${pt.y}`).join(' ');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', closed ? 'polygon' : 'polyline');
    
    const status = pinModalData.selectedStatus.toLowerCase();
    let color = status === 'sold' ? 'rgba(220,53,69,0.6)' : (status === 'reserved' ? 'rgba(255,193,7,0.6)' : 'rgba(40,167,69,0.6)');
    let stroke = status === 'sold' ? 'red' : (status === 'reserved' ? 'gold' : 'green');

    shape.setAttribute('points', ptsStr);
    shape.setAttribute('stroke', stroke);
    shape.setAttribute('stroke-width', closed ? '0.4' : '0.2');
    shape.setAttribute('vector-effect', 'non-scaling-stroke');
    shape.setAttribute('fill', closed ? color : 'none');
    if(!closed) shape.setAttribute('stroke-dasharray', '1,1');
    liveGroup.appendChild(shape);

    // Render little dots on corners while drawing
    if(!closed) {
        pinModalData.polygonPoints.forEach(pt => {
            let c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            c.setAttribute('cx', pt.x);
            c.setAttribute('cy', pt.y);
            c.setAttribute('r', '0.5');
            c.setAttribute('fill', 'white');
            c.setAttribute('stroke', stroke);
            c.setAttribute('stroke-width', '0.2');
            c.setAttribute('vector-effect', 'non-scaling-stroke');
            liveGroup.appendChild(c);
        });
    }
}

// --- Save Function ---
function savePinLocation() {
    if(pinModalData.polygonPoints.length < 3) { 
        alert("Please draw a valid shape (at least 3 points) first!"); 
        return; 
    }
    
    const fd = new FormData();
    fd.append('action', 'save_pin');
    fd.append('lot_id', pinModalData.lot_id);
    fd.append('polygon_coordinates', JSON.stringify(pinModalData.polygonPoints)); 
    fd.append('pin_status', pinModalData.selectedStatus); 
    
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
        if(res.success) { 
            alert("Pin and status saved successfully!"); 
            closePinModal();
            // Automatically refresh the lots table data behind the scenes
            const locId = document.getElementById('location_id').value;
            if (locId) loadLots(locId);
        } else { 
            alert("Database Error: " + res.error); 
        }
    }).catch(err => {
        console.error(err);
        alert("Request failed. Check console for details.");
    });
}

function closePinModal() {
    document.getElementById('pinModal').style.display = 'none';
    if(pzInstance) { 
        pzInstance.reset(); 
        pzInstance.setOptions({ disablePan: false });
        pzInstance.destroy(); 
        pzInstance = null; 
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