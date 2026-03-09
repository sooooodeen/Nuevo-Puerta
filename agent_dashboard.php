<?php
/* agent_dashboard.php — static sidebar SPA with white icons and section switching */

session_start();

/* ---- Allow both session styles ---- */
if (!isset($_SESSION['agent_id'])) {
  if (!isset($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header("Location: agentdb/Login/login.php");
    exit();
  }
}

/* ---- DB hookup ---- */
if (file_exists(__DIR__ . '/dbconn.php')) {
  require_once __DIR__ . '/dbconn.php';
}
if (!isset($conn) || !($conn instanceof mysqli)) {
  $conn = new mysqli("localhost", "root", "", "nuevopuerta");
}
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

date_default_timezone_set('Asia/Manila');

/* ---- Resolve agent id if only username exists ---- */
$agentId = (int)($_SESSION['agent_id'] ?? 0);
if ($agentId === 0) {
  $username = (string)($_SESSION['user'] ?? '');
  if ($username !== '') {
    if ($stmt = $conn->prepare("SELECT id FROM agent_accounts WHERE username=? LIMIT 1")) {
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        $agentId = (int)$row['id'];
        $_SESSION['agent_id'] = $agentId;
      }
      $stmt->close();
    }
  }
}
if ($agentId === 0) {
  header("Location: agentdb/Login/login.php");
  exit();
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasAgentColumn(mysqli $conn, string $column): bool {
  $col = $conn->real_escape_string($column);
  $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='agent_accounts' AND COLUMN_NAME='$col'");
  if (!$res) return false;
  $row = $res->fetch_assoc();
  return ((int)($row['c'] ?? 0)) > 0;
}

$hasAvailability = hasAgentColumn($conn, 'availability');
$hasIsAvailable  = hasAgentColumn($conn, 'is_available');

// If neither column exists, create 'is_available' so the toggle always works
if (!$hasAvailability && !$hasIsAvailable) {
  $conn->query("ALTER TABLE agent_accounts ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1");
  $hasIsAvailable = true;
}

$availabilityCol = $hasAvailability ? 'availability' : ($hasIsAvailable ? 'is_available' : null);

/* ---- CSRF ---- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check() {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
}

/* ---- Safe bootstrap tables ---- */
$conn->query("
  CREATE TABLE IF NOT EXISTS viewings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    client_first_name VARCHAR(100),
    client_last_name  VARCHAR(100),
    client_email      VARCHAR(150),
    client_phone      VARCHAR(20),
    lot_no            VARCHAR(50),
    preferred_at      DATETIME,
    status ENUM('scheduled','rescheduled','completed','no_show_agent','no_show_client','cancelled') DEFAULT 'scheduled',
    client_lat DECIMAL(10,8),
    client_lng DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_viewings_agent (agent_id, preferred_at, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Create user_documents table if it doesn't exist
$conn->query("
  CREATE TABLE IF NOT EXISTS user_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    doc_type VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    status ENUM('pending_review','under_review','approved','rejected','requires_revision') DEFAULT 'pending_review',
    progress_notes TEXT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    INDEX idx_user_docs (user_id, status, uploaded_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Create client_progress table for tracking client progress
$conn->query("
  CREATE TABLE IF NOT EXISTS client_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    client_email VARCHAR(150) NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    progress_status ENUM('initial_contact','document_collection','property_viewing','offer_preparation','negotiation','closing','completed') DEFAULT 'initial_contact',
    progress_percentage INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT,
    next_followup DATE NULL,
    INDEX idx_client_progress (agent_id, client_email, progress_status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Removed table creation for messages to avoid conflicts with existing structure

/* ---- POST actions (toggle availability / quick viewing status) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  /* Agent toggles availability */
  if (isset($_POST['toggle_avail'])) {
    $isAvail = 1;
    if ($availabilityCol && $stmt = $conn->prepare("SELECT IFNULL($availabilityCol, 1) AS a FROM agent_accounts WHERE id=?")) {
      $stmt->bind_param('i', $agentId);
      $stmt->execute();
      $r = $stmt->get_result();
      if ($row = $r->fetch_assoc()) $isAvail = (int)$row['a'];
      $stmt->close();
    }
    $newVal = $isAvail ? 0 : 1;

    if ($hasAvailability && $stmt = $conn->prepare("UPDATE agent_accounts SET availability=? WHERE id=?")) {
      $stmt->bind_param('ii', $newVal, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    if ($hasIsAvailable && $stmt = $conn->prepare("UPDATE agent_accounts SET is_available=? WHERE id=?")) {
      $stmt->bind_param('ii', $newVal, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#dashboard');
    exit;
  }

  /* Agent approves pending viewing (from Upcoming Viewings table) */
  if (isset($_POST['approve_viewing_id'])) {
    $vid = (int)$_POST['approve_viewing_id'];
    if ($stmt = $conn->prepare("UPDATE viewings SET status='scheduled' WHERE id=? AND agent_id=? AND status='pending'")) {
      $stmt->bind_param('ii', $vid, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'dashboard'));
    exit;
  }

  /* Generic viewing status updates (complete / cancelled / etc.) */
  if (isset($_POST['viewing_action'], $_POST['viewing_id'])) {
    $vid = (int)$_POST['viewing_id'];
    $action = $_POST['viewing_action'];
    $allowed = ['completed','no_show_agent','no_show_client','cancelled','scheduled'];
    if (in_array($action, $allowed, true)) {
      if ($stmt = $conn->prepare("UPDATE viewings SET status=? WHERE id=? AND agent_id=?")) {
        $stmt->bind_param('sii', $action, $vid, $agentId);
        $stmt->execute();
        $stmt->close();
      }
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'dashboard'));
    exit;
  }

  /* Delete a viewing record */
  if (isset($_POST['delete_viewing_id'])) {
    $vid = (int)$_POST['delete_viewing_id'];
    if ($stmt = $conn->prepare("DELETE FROM viewings WHERE id=? AND agent_id=?")) {
      $stmt->bind_param('ii', $vid, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'viewings'));
    exit;
  }

  // Mark notification as read
  if (isset($_POST['mark_read'])) {
    $notif_id = (int)$_POST['mark_read'];
    // Ensure table exists or handle error silently
    $stmt = $conn->prepare("UPDATE agent_notifications SET is_read=1 WHERE id=? AND agent_id=?");
    if ($stmt) {
        $stmt->bind_param('ii', $notif_id, $agentId);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
  }

  // Delete notification
  if (isset($_POST['delete_notif'])) {
    $notif_id = (int)$_POST['delete_notif'];
    $stmt = $conn->prepare("DELETE FROM agent_notifications WHERE id=? AND agent_id=?");
    if ($stmt) {
        $stmt->bind_param('ii', $notif_id, $agentId);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
  }

  // Mark message as read
  if (isset($_POST['mark_message_read'])) {
    $msg_id = (int)$_POST['mark_message_read'];
    // FIX: Using try-catch or simple check to avoid crash if 'is_read' missing
    $checkCol = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE id=? AND agent_id=?");
        if ($stmt) {
            $stmt->bind_param('ii', $msg_id, $agentId);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success' => true]);
    exit;
  }

  // Delete message
  if (isset($_POST['delete_message'])) {
    $msg_id = (int)$_POST['delete_message'];
    $stmt = $conn->prepare("DELETE FROM messages WHERE id=? AND agent_id=?");
    if ($stmt) {
        $stmt->bind_param('ii', $msg_id, $agentId);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit;
  }

  /* Update document status */
  if (isset($_POST['update_document_status'])) {
    $doc_id = (int)$_POST['document_id'];
    $status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    $allowed_statuses = ['pending_review', 'under_review', 'approved', 'rejected', 'requires_revision'];

    if (in_array($status, $allowed_statuses, true)) {
      $stmt = $conn->prepare("
        UPDATE user_documents
        SET status = ?, progress_notes = ?, reviewed_at = NOW(), reviewed_by = ?
        WHERE id = ? AND user_id IN (SELECT id FROM user_accounts WHERE agent_id = ?)
      ");
      if ($stmt) {
        $stmt->bind_param('ssiii', $status, $notes, $agentId, $doc_id, $agentId);
        $stmt->execute();
        $stmt->close();
      }
    }
    header('Location: agent_dashboard.php#section-documents');
    exit;
  }

  /* Update client progress */
  if (isset($_POST['update_client_progress'])) {
    $client_email = trim($_POST['client_email']);
    $progress_status = $_POST['progress_status'];
    $progress_percentage = (int)$_POST['progress_percentage'];
    $notes = trim($_POST['notes'] ?? '');
    $next_followup = !empty($_POST['next_followup']) ? $_POST['next_followup'] : null;

    $allowed_statuses = ['initial_contact', 'document_collection', 'property_viewing', 'offer_preparation', 'negotiation', 'closing', 'completed'];

    if (in_array($progress_status, $allowed_statuses, true) && $progress_percentage >= 0 && $progress_percentage <= 100) {
      // Check if client progress record exists
      $stmt = $conn->prepare("SELECT id FROM client_progress WHERE agent_id = ? AND client_email = ?");
      $stmt->bind_param('is', $agentId, $client_email);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows > 0) {
        // Update existing record
        $stmt = $conn->prepare("
          UPDATE client_progress
          SET progress_status = ?, progress_percentage = ?, notes = ?, next_followup = ?
          WHERE agent_id = ? AND client_email = ?
        ");
        $stmt->bind_param('sissss', $progress_status, $progress_percentage, $notes, $next_followup, $agentId, $client_email);
      } else {
        // Insert new record - need client name from viewings or messages
        $client_name = 'Unknown Client';
        $name_stmt = $conn->prepare("
          SELECT CONCAT(client_first_name, ' ', client_last_name) as name
          FROM viewings
          WHERE agent_id = ? AND client_email = ?
          ORDER BY created_at DESC LIMIT 1
        ");
        $name_stmt->bind_param('is', $agentId, $client_email);
        $name_stmt->execute();
        $name_result = $name_stmt->get_result();
        if ($name_row = $name_result->fetch_assoc()) {
          $client_name = $name_row['name'];
        }
        $name_stmt->close();

        $stmt = $conn->prepare("
          INSERT INTO client_progress (agent_id, client_email, client_name, progress_status, progress_percentage, notes, next_followup)
          VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('isssiss', $agentId, $client_email, $client_name, $progress_status, $progress_percentage, $notes, $next_followup);
      }
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#section-clients');
    exit;
  }

  /* Send message to client */
  if (isset($_POST['send_client_message'])) {
    $client_email = trim($_POST['client_email']);
    $subject = trim($_POST['subject'] ?? 'Message from your Agent');
    $message = trim($_POST['message']);

    if (!empty($client_email) && !empty($message)) {
      // Get agent info for the message
      $agent_info = [];
      $agent_stmt = $conn->prepare("SELECT first_name, last_name, email FROM agent_accounts WHERE id = ?");
      $agent_stmt->bind_param('i', $agentId);
      $agent_stmt->execute();
      $agent_result = $agent_stmt->get_result();
      if ($agent_row = $agent_result->fetch_assoc()) {
        $agent_info = $agent_row;
      }
      $agent_stmt->close();

      // For now, we'll store the message in the messages table as if from agent to client
      // In a real implementation, you'd want a separate client_messages table or use email
      $stmt = $conn->prepare("
        INSERT INTO messages (agent_id, name, email, phone, message, created_at)
        VALUES (?, ?, ?, '', ?, NOW())
      ");
      $sender_name = ($agent_info['first_name'] ?? 'Agent') . ' ' . ($agent_info['last_name'] ?? '');
      $stmt->bind_param('isss', $agentId, $sender_name, $client_email, $message);
      $stmt->execute();
      $stmt->close();
    }
    header('Location: agent_dashboard.php#section-clients');
    exit;
  }

  // Handle agent profile update (POST)
  if (isset($_POST['first_name'], $_POST['last_name'], $_POST['email'])) {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    // FIX: Use 'mobile' to match your database column
    $mobile= trim($_POST['mobile'] ?? ''); 
    $addr  = trim($_POST['address'] ?? '');
    $exp   = (int)($_POST['experience'] ?? 0);
    $sales = (int)($_POST['total_sales'] ?? 0);
    $desc  = trim($_POST['description'] ?? '');
    $lat   = trim($_POST['latitude'] ?? '');
    $lng   = trim($_POST['longitude'] ?? '');
    
    // Check if we have profile_picture in array, otherwise empty
    $profile_picture = $agent['profile_picture'] ?? '';

    // Handle profile picture upload
    if (!empty($_FILES['profile_picture']['name']) && is_uploaded_file($_FILES['profile_picture']['tmp_name'])) {
      $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
      if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        $dir = __DIR__.'/assets/profile_photos';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $fname = 'agent_'.$agentId.'_'.time().'.'.$ext;
        $dest  = $dir.'/'.$fname;
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
          $profile_picture = 'assets/profile_photos/'.$fname;
        }
      }
    }

    // FIX: Update agent_accounts using 'mobile' and 'profile_picture' columns
    $stmt = $conn->prepare("UPDATE agent_accounts SET first_name=?, last_name=?, email=?, mobile=?, address=?, experience=?, total_sales=?, description=?, latitude=?, longitude=?, profile_picture=? WHERE id=?");
    if ($stmt) {
      $stmt->bind_param('sssssiissssi', $first, $last, $email, $mobile, $addr, $exp, $sales, $desc, $lat, $lng, $profile_picture, $agentId);
      $stmt->execute();
      $stmt->close();
      
      // Update local variable to reflect changes immediately
      $agent['first_name'] = $first;
      $agent['last_name'] = $last;
      $agent['email'] = $email;
      $agent['mobile'] = $mobile;
      $agent['address'] = $addr;
      $agent['experience'] = $exp;
      $agent['total_sales'] = $sales;
      $agent['description'] = $desc;
      $agent['latitude'] = $lat;
      $agent['longitude'] = $lng;
      $agent['profile_picture'] = $profile_picture;
      
      $profile_update_success = true;
    } else {
      $profile_update_error = 'Error updating profile: ' . $conn->error;
    }
  }
}

/* ---- GET fetch handlers ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch'])) {
  if ($_GET['fetch'] === 'notifications') {
    $notifications = [];
    // Ensure table exists
    $check = $conn->query("SHOW TABLES LIKE 'agent_notifications'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM agent_notifications WHERE agent_id=? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $notifications[] = $row;
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit;
  }

  if ($_GET['fetch'] === 'messages') {
    $messages = [];
    $stmt = $conn->prepare("SELECT * FROM messages WHERE agent_id=? ORDER BY created_at DESC LIMIT 50");
    if ($stmt) {
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $messages[] = $row;
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($messages);
    exit;
  }

  if ($_GET['fetch'] === 'audit_logs') {
    $logs = [];
    // Ensure table exists
    $check = $conn->query("SHOW TABLES LIKE 'agent_audit_logs'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM agent_audit_logs WHERE agent_id=? ORDER BY created_at DESC LIMIT 100");
        if ($stmt) {
            $stmt->bind_param('i', $agentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $logs[] = $row;
            }
            $stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($logs);
    exit;
  }

  if ($_GET['fetch'] === 'documents') {
    $docs = [];
    // Ensure table exists
    $check = $conn->query("SHOW TABLES LIKE 'agent_documents'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM agent_documents WHERE agent_id=? ORDER BY created_at DESC");
        if ($stmt) {
            $stmt->bind_param('i', $agentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $docs[] = $row;
            }
            $stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
  }

  if ($_GET['fetch'] === 'user_documents') {
    $docs = [];
    // Basic check if user_documents table exists
    $check = $conn->query("SHOW TABLES LIKE 'user_documents'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT d.*, u.first_name, u.last_name
            FROM user_documents d
            LEFT JOIN user_accounts u ON d.user_id = u.id
            WHERE u.agent_id = ?
            ORDER BY d.uploaded_at DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $agentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
              $docs[] = $row;
            }
            $stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($docs);
    exit;
  }

  if ($_GET['fetch'] === 'clients') {
    $clients = [];
    // Get unique clients from viewings table
    $stmt = $conn->prepare("
        SELECT DISTINCT
            v.client_email,
            CONCAT(v.client_first_name, ' ', v.client_last_name) as client_name,
            v.client_phone,
            MAX(v.created_at) as last_contact,
            COUNT(v.id) as total_viewings,
            COALESCE(cp.progress_status, 'initial_contact') as progress_status,
            COALESCE(cp.progress_percentage, 0) as progress_percentage,
            cp.notes as progress_notes,
            cp.next_followup
        FROM viewings v
        LEFT JOIN client_progress cp ON cp.agent_id = v.agent_id AND cp.client_email = v.client_email
        WHERE v.agent_id = ?
        GROUP BY v.client_email, v.client_first_name, v.client_last_name, v.client_phone
        ORDER BY last_contact DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $clients[] = $row;
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($clients);
    exit;
  }
}

/* ---- KPIs ---- */
$kpis = [
  'total_sales' => 0,
  'month_sales' => 0,
  'upcoming_viewings' => 0,
  'unread_messages' => 0,
  'is_available' => 1,
  'full_name' => 'Agent',
];

// FIX: Check 'availability' column instead of 'is_available' if that is what your DB has.
// Based on typical setups, let's look for 'availability' or 'is_available'.
// Your DB image usually has 'availability' for admin/user, but 'is_available' was in your older code.
// I will query both or default to 1.
$availSelect = $availabilityCol ? ", $availabilityCol AS availability_val" : "";
if ($stmt = $conn->prepare("SELECT first_name, last_name$availSelect FROM agent_accounts WHERE id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) {
    $kpis['full_name']    = trim(($row['first_name'] ?? 'Agent').' '.($row['last_name'] ?? ''));
    if ($availabilityCol) {
      $kpis['is_available'] = (int)($row['availability_val'] ?? 1);
    }
  }
  $stmt->close();
}

if ($stmt = $conn->prepare("SELECT COUNT(*) c FROM sales WHERE agent_id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['total_sales'] = (int)$row['c'];
  $stmt->close();
}

if ($stmt = $conn->prepare("
  SELECT COUNT(*) c FROM sales
  WHERE agent_id=? AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['month_sales'] = (int)$row['c'];
  $stmt->close();
}

if ($stmt = $conn->prepare("
  SELECT COUNT(*) c FROM viewings
  WHERE agent_id=? AND status IN ('pending','scheduled','rescheduled','requested')
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['upcoming_viewings'] = (int)$row['c'];
  $stmt->close();
}

// FIX: REMOVED CHECK for 'is_read' to prevent Fatal Error if column is missing
if ($stmt = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE agent_id=?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) $kpis['unread_messages'] = (int)$row['c'];
  $stmt->close();
}

/* ---- Upcoming viewings list (DASHBOARD) ---- */
$viewings = [];
if ($stmt = $conn->prepare("
  SELECT v.id,
         v.client_first_name, v.client_last_name,
         v.client_email, v.client_phone,
         v.preferred_at, v.status,
         v.lot_no,
         l.block_number, l.lot_number,
         ll.location_name
  FROM viewings v
  LEFT JOIN lots l            ON v.lot_id = l.id
  LEFT JOIN lot_locations ll ON v.location_id = ll.id
  WHERE v.agent_id=? AND v.status IN ('pending','scheduled','rescheduled','requested')
  ORDER BY v.created_at DESC
  LIMIT 12
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) $viewings = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ---- (Optional) Load profile data for the Profile section ---- */
$agent = [
  'first_name' => '', 'last_name' => '', 'email' => '',
  'mobile' => '', 'address' => '', 'experience' => 0,
  'total_sales' => 0, 'description' => '', 'profile_picture' => '',
  'latitude' => '', 'longitude' => ''
];
if ($stmt = $conn->prepare("SELECT * FROM agent_accounts WHERE id=? LIMIT 1")) {
  $stmt->bind_param("i", $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) $agent = $row;
  $stmt->close();
}

/* ---- Fetch ALL viewings for this agent (for the viewings section) ---- */
$all_viewings = [];
if ($stmt = $conn->prepare("
  SELECT v.*, ll.location_name, l.block_number, l.lot_number, l.lot_size, l.lot_price
  FROM viewings v
  LEFT JOIN lot_locations ll ON v.location_id = ll.id
  LEFT JOIN lots l ON v.lot_id = l.id
  WHERE v.agent_id = ?
  ORDER BY v.created_at DESC
")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res) $all_viewings = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ---- Fetch assigned leads for this agent ---- */
$leads = [];
// Safe check if leads table exists
$checkLeads = $conn->query("SHOW TABLES LIKE 'leads'");
if ($checkLeads && $checkLeads->num_rows > 0) {
    if ($stmt = $conn->prepare("
      SELECT l.*, CONCAT(a.first_name, ' ', a.last_name) AS agent_name
      FROM leads l
      LEFT JOIN agent_accounts a ON l.agent_id = a.id
      WHERE l.agent_id = ?
      ORDER BY l.created_at DESC
    ")) {
      $stmt->bind_param('i', $agentId);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) $leads = $res->fetch_all(MYSQLI_ASSOC);
      $stmt->close();
    }
}

// Handle agent time slot form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_time_slot'])) {
  csrf_check();
  $avail_date = $_POST['avail_date'] ?? '';
  $time_slot = $_POST['time_slot'] ?? '';
  $max_clients = (int)($_POST['max_clients'] ?? 1);
  $errors = [];
  if (!$avail_date || !$time_slot || $max_clients < 1) {
    $errors[] = 'All fields are required and max clients must be at least 1.';
  }
  
  // Ensure table exists
  $conn->query("CREATE TABLE IF NOT EXISTS agent_time_slots (
      id INT AUTO_INCREMENT PRIMARY KEY,
      agent_id INT,
      available_date DATE,
      time_slot TIME,
      max_clients INT DEFAULT 1
  )");

  if (empty($errors)) {
    $stmt = $conn->prepare("INSERT INTO agent_time_slots (agent_id, available_date, time_slot, max_clients) VALUES (?, ?, ?, ?)");
    if ($stmt === false) {
      $slot_error = 'Prepare failed: ' . $conn->error;
    } else {
      $stmt->bind_param('issi', $agentId, $avail_date, $time_slot, $max_clients);
      if (!$stmt->execute()) {
        $slot_error = 'Execute failed: ' . $stmt->error;
      } else {
        $slot_success = true;
      }
      $stmt->close();
    }
  } else {
    $slot_error = implode(' ', $errors);
  }
}

// Fetch agent's time slots
$agent_time_slots = [];
$checkSlots = $conn->query("SHOW TABLES LIKE 'agent_time_slots'");
if ($checkSlots && $checkSlots->num_rows > 0) {
    $stmt = $conn->prepare("SELECT * FROM agent_time_slots WHERE agent_id=? ORDER BY available_date DESC, time_slot ASC");
    if ($stmt) {
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $agent_time_slots[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Agent Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
/* Sidebar appearance: match user/admin nav sizing and hide native scrollbar */
aside, .sidebar { scrollbar-width: none; -ms-overflow-style: none; }
aside::-webkit-scrollbar, .sidebar::-webkit-scrollbar { width:0; height:0; }

#spa-nav a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 22px;
  color: rgba(255,255,255,0.95);
  text-decoration: none;
  transition: background 0.18s, color 0.18s, transform 0.12s;
  border-left: 4px solid transparent;
  font-size: 15px;
  margin: 6px 14px;
  border-radius: 8px;
}

#spa-nav a:hover,
#spa-nav a.nav-active {
  background: rgba(255,255,255,0.06);
  color: #fff;
  transform: translateY(-1px);
}

.logout-icon { display:inline-block; transform: scaleX(1); -webkit-transform: scaleX(1); }
.logout-link { color: #ffffff !important; }

button,
input[type="button"],
input[type="submit"],
.btn,
.btn-small {
  background-color: #3e5f3e !important;
  border-color: #3e5f3e !important;
  color: #ffffff !important;
}

button:hover,
input[type="button"]:hover,
input[type="submit"]:hover,
.btn:hover,
.btn-small:hover {
  background-color: #3e5f3e !important;
  border-color: #3e5f3e !important;
  color: #ffffff !important;
}

/* Logout modal buttons should match admin styling */
#admin-logout-modal #cancel-logout {
  background: #f8f9fa !important;
  color: #6c757d !important;
  border: 1px solid #ced4da !important;
}

#admin-logout-modal #confirm-logout {
  background: #dc3545 !important;
  color: #ffffff !important;
  border: 1px solid #dc3545 !important;
}

/* Profile tabs: override global button styles */
.profile-tabs {
  background: #f5f7f4;
  border: 1px solid #e2e8e0;
  border-radius: 12px;
  padding: 6px;
  gap: 6px;
}

.profile-tabs .profile-tab {
  background: transparent !important;
  color: #475569 !important;
  border: 0 !important;
  border-radius: 8px;
  padding: 10px 18px;
  font-weight: 600;
  transition: background 0.15s ease, color 0.15s ease;
}

.profile-tabs .profile-tab.active {
  background: #1f5b37 !important;
  color: #ffffff !important;
}

.profile-pane {
  opacity: 0;
  transform: translateY(6px);
  max-height: 0;
  overflow: hidden;
  pointer-events: none;
  transition: opacity 0.2s ease, transform 0.2s ease, max-height 0.25s ease;
}

.profile-pane.active {
  opacity: 1;
  transform: translateY(0);
  max-height: 2000px;
  pointer-events: auto;
}

/* Sections: animate out then in for smooth transitions.
   Place sections absolutely inside the main container to avoid layout jumps. */
      .section {
        display: none;
      }

      /* Show active section and animate like user/admin dashboards */
      .section.active {
        display: block;
        animation: fadeIn 0.36s ease;
      }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
      }
</style>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="flex min-h-screen">
  <aside class="bg-green-900 text-white flex flex-col items-center py-8 sidebar" style="width:280px; height: 100vh; position: fixed; left: 0; top: 0; bottom: 0; z-index: 10; overflow: hidden;">
    <div class="flex items-center gap-3 mb-8">
      <img src="logo.png" alt="Logo" class="w-16 h-16 rounded-full bg-white/10 object-contain" />
      <div>
        <h2 class="font-bold text-lg tracking-wide whitespace-nowrap leading-tight">NUEVO PUERTA</h2>
        <span class="text-xs font-normal text-white/90 leading-none block mt-0.5">REAL ESTATE</span>
      </div>
    </div>

    <div class="bg-white/10 rounded-xl px-4 py-3 mb-8 w-56 mx-auto flex items-center">
      <div class="mr-3">
        <?php if (!empty($agent['profile_picture'])): ?>
          <img src="<?php echo h($agent['profile_picture']); ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover bg-white" onerror="this.style.display='none'">
        <?php else: ?>
          <div class="w-10 h-10 rounded-full bg-white text-green-900 grid place-items-center font-bold">
            <?php echo strtoupper(substr($kpis['full_name'],0,1)); ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="leading-tight">
        <div class="font-semibold text-sm text-white"><?php echo h($kpis['full_name']); ?></div>
        <div class="text-xs text-white/80">Agent</div>
      </div>
    </div>

    <nav class="w-full">
      <ul class="space-y-1 w-full" id="spa-nav">
        <li>
          <a href="#dashboard" data-target="section-dashboard"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7A1 1 0 003 11h1v6a1 1 0 001 1h3a1 1 0 001-1v-3h2v3a1 1 0 001 1h3a1 1 0 001-1v-6h1a1 1 0 00.707-1.707l-7-7z"/>
            </svg>
            Dashboard
          </a>
        </li>
        <li>
          <a href="#profile" data-target="section-profile"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10 10a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 1114 0H3z"/>
            </svg>
            Profile
          </a>
        </li>
        <li>
          <a href="#viewings" data-target="section-viewings"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 20 20">
              <path d="M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z"/>
            </svg>
            Viewings
          </a>
        </li>
        <li>
          <a href="#sales" data-target="section-sales"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M3 13h6v8H3zM9 3h6v18H9zM15 9h6v12h-6z"/>
            </svg>
            Sales
          </a>
        </li>
        <li>
          <a href="#notifications" data-target="section-notifications"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V9a6 6 0 10-12 0v7L4 18v1h16v-1l-2-2z"/>
            </svg>
            Notifications
          </a>
        </li>
        <li>
          <a href="#messages" data-target="section-messages"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/>
            </svg>
            Messages
          </a>
        </li>
        <li>
          <a href="#audit-logs" data-target="section-audit-logs"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm7 7h2v2h-2v-2z"/>
            </svg>
            Audit Logs
          </a>
        </li>
        <li>
          <a href="#documents" data-target="section-documents"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M6 2v6h12V2H6zm0 8v12h12V10H6zm2 2h8v8H8v-8z"/>
            </svg>
            Document Review
          </a>
        </li>
        <li>
          <a href="#leads" data-target="section-leads"
             class="flex items-center px-8 py-3 rounded transition hover:bg-green-800">
            <svg class="w-5 h-5 mr-3 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm7 7h2v2h-2v-2z"/>
            </svg>
            Leads
          </a>
        </li>
        <li>
          <a href="#" onclick="confirmLogout()" class="flex items-center px-8 py-3 rounded transition hover:bg-green-800 logout-link">
            <svg class="w-5 h-5 mr-3 text-white logout-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M16 13v-2H7V8l-5 4 5 4v-3zM20 3h-8v2h8v14h-8v2h8a2 2 0 002-2V5a2 2 0 00-2-2z"/>
            </svg>
            Logout
          </a>
        </li>
      </ul>
    </nav>
  </aside>

  <main class="flex-1 p-8 mt-8" style="margin-left:280px;">
    <section id="section-dashboard">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-4xl font-bold text-green-900">Welcome, <?php echo h(explode(' ',$kpis['full_name'])[0] ?? 'Agent'); ?></h1>
          <p class="text-gray-700">Monitor client requests for lot viewing.</p>
        </div>
        <form method="post" class="flex items-center gap-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
          <button name="toggle_avail" value="1"
                  class="px-4 py-2 rounded-lg font-semibold text-white shadow
                    <?php echo $kpis['is_available'] ? 'bg-red-600 hover:bg-red-700' : 'bg-green-700 hover:bg-green-800'; ?>">
            <?php echo $kpis['is_available'] ? 'Set Unavailable' : 'Set Available'; ?>
          </button>
          <span class="px-3 py-1 rounded-full text-sm border font-medium
            <?php echo $kpis['is_available'] ? 'bg-green-50 text-green-800 border-green-300' : 'bg-red-50 text-red-700 border-red-300'; ?>">
            ● <?php echo $kpis['is_available'] ? 'Available' : 'Unavailable'; ?>
          </span>
        </form>
      </div>

      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mt-6">
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M3 13h6v8H3zM9 3h6v18H9zM15 9h6v12h-6z"/></svg>
            Total Sales
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['total_sales']); ?></div>
          <div class="text-xs text-gray-500 mt-1">All time</div>
        </div>
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#fff">M</text></svg>
            Sales (This Month)
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['month_sales']); ?></div>
          <div class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></div>
        </div>
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M17 8a5 5 0 00-10 0c0 3.87 5 9 5 9s5-5.13 5-9z"/><circle cx="12" cy="8" r="2"/></svg>
            Upcoming Viewings
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['upcoming_viewings']); ?></div>
          <div class="text-xs text-gray-500 mt-1">Scheduled / Rescheduled</div>
        </div>
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/></svg>
            Unread Messages
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['unread_messages']); ?></div>
          <div class="text-xs text-gray-500 mt-1">Needs attention</div>
        </div>
      </section>

      <section class="mt-8">
        <div class="bg-white rounded-2xl border shadow p-6">
          <div class="font-semibold text-gray-800 mb-4">Recent Activities</div>
          <ul class="text-sm text-gray-700 space-y-2">
            <li>
              <span style="display:inline-flex;align-items:center;margin-right:6px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect width="18" height="18" rx="4" fill="#2ecc71"/><path d="M5 10l4 4 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              No recent activities yet.
            </li>
          </ul>
        </div>
      </section>

      <section class="mt-8">
        <div class="bg-white rounded-2xl border shadow p-6 mb-8">
          <div class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
            <span style="display:inline-flex;align-items:center;margin-right:6px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="12" rx="9" ry="6" fill="#2e7d32"/><circle cx="12" cy="12" r="2.5" fill="#fff"/></svg>
            </span> Set Your Availability
          </div>
          <?php if (!empty($availability_success)): ?>
            <div class="mb-3 p-2 rounded bg-green-100 text-green-800 text-sm">Availability saved!</div>
          <?php elseif (!empty($availability_error)): ?>
            <div class="mb-3 p-2 rounded bg-red-100 text-red-800 text-sm"><?php echo h($availability_error); ?></div>
          <?php endif; ?>
          <?php if (!empty($slot_success)): ?>
            <div id="slot-success-msg" class="mb-3 p-2 rounded bg-green-100 text-green-800 text-sm">Time slot added successfully!</div>
          <?php elseif (!empty($slot_error)): ?>
            <div id="slot-error-msg" class="mb-3 p-2 rounded bg-red-100 text-red-800 text-sm"><?php echo h($slot_error); ?></div>
          <?php endif; ?>
          <script>
            setTimeout(function() {
              var msg = document.getElementById('slot-success-msg');
              if (msg) msg.style.display = 'none';
              var err = document.getElementById('slot-error-msg');
              if (err) err.style.display = 'none';
            }, 3000);
          </script>
          <form method="post" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
              <input type="date" name="avail_date" class="border rounded px-3 py-2" required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Time Slot</label>
              <input type="time" name="time_slot" class="border rounded px-3 py-2" required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Max Clients (for this slot)</label>
              <input type="number" name="max_clients" class="border rounded px-3 py-2" min="1" value="1" required>
            </div>
            <button type="submit" name="save_time_slot" class="bg-green-700 text-white px-5 py-2 rounded font-semibold hover:bg-green-800">Add Slot</button>
          </form>

          <?php if (!empty($agent_time_slots)): ?>
            <div class="mt-6">
              <div class="font-semibold text-gray-700 mb-2">Your Time Slots</div>
              <div class="overflow-x-auto">
                <table class="min-w-full border rounded text-sm">
                  <thead>
                    <tr class="bg-gray-50 text-gray-700">
                      <th class="py-2 px-4 text-left border">Date</th>
                      <th class="py-2 px-4 text-left border">Time Slot</th>
                      <th class="py-2 px-4 text-left border">Max Clients</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($agent_time_slots as $slot): ?>
                      <tr class="border-t">
                        <td class="py-2 px-4 border"><?php echo h(date('M d, Y', strtotime($slot['available_date']))); ?></td>
                        <td class="py-2 px-4 border"><?php echo h(date('h:i A', strtotime($slot['time_slot']))); ?></td>
                        <td class="py-2 px-4 border"><?php echo h($slot['max_clients']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
        
        <div class="bg-white rounded-2xl border shadow p-6">
          <div class="flex items-center gap-2 font-semibold text-gray-800 mb-4">
            <span style="display:inline-flex;align-items:center;margin-right:6px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="12" rx="9" ry="6" fill="#2e7d32"/><circle cx="12" cy="12" r="2.5" fill="#fff"/></svg>
            </span> Upcoming Viewings
          </div>

          <?php if (empty($viewings)): ?>
            <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm text-center">
              No upcoming viewings.
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($viewings as $v):
                $st = $v['status'];
                $statusCfg = [
                  'pending'     => ['bg' => 'bg-amber-50',  'text' => 'text-amber-700',  'border' => 'border-amber-200', 'dot' => 'bg-amber-400',  'label' => 'Pending'],
                  'scheduled'   => ['bg' => 'bg-green-50',  'text' => 'text-green-700',  'border' => 'border-green-200', 'dot' => 'bg-green-500',  'label' => 'Scheduled'],
                  'rescheduled' => ['bg' => 'bg-blue-50',   'text' => 'text-blue-700',   'border' => 'border-blue-200',  'dot' => 'bg-blue-400',   'label' => 'Rescheduled'],
                  'requested'   => ['bg' => 'bg-amber-50',  'text' => 'text-amber-700',  'border' => 'border-amber-200', 'dot' => 'bg-amber-400',  'label' => 'Requested'],
                  'completed'   => ['bg' => 'bg-emerald-50','text' => 'text-emerald-700','border' => 'border-emerald-200','dot' => 'bg-emerald-500','label' => 'Completed'],
                  'cancelled'   => ['bg' => 'bg-red-50',    'text' => 'text-red-700',    'border' => 'border-red-200',   'dot' => 'bg-red-400',    'label' => 'Cancelled'],
                ];
                $cfg = $statusCfg[$st] ?? $statusCfg['pending'];

                // Build location text
                $locParts = [];
                if (!empty($v['location_name'])) $locParts[] = $v['location_name'];
                if (!empty($v['block_number']) && !empty($v['lot_number'])) {
                  $locParts[] = 'Block ' . $v['block_number'] . ', Lot ' . $v['lot_number'];
                } elseif (!empty($v['lot_no'])) {
                  $locParts[] = 'Lot ' . $v['lot_no'];
                }
                $locText = $locParts ? implode(' — ', $locParts) : 'N/A';
              ?>
              <div class="flex items-start gap-4 p-4 rounded-xl border <?php echo $cfg['border']; ?> <?php echo $cfg['bg']; ?> transition hover:shadow-sm">
                <!-- Left: Date badge -->
                <div class="flex-shrink-0 text-center bg-white rounded-lg border shadow-sm px-3 py-2 min-w-[56px]">
                  <div class="text-xs font-semibold text-gray-500 uppercase"><?php echo date('M', strtotime($v['preferred_at'])); ?></div>
                  <div class="text-xl font-bold text-green-900 leading-tight"><?php echo date('d', strtotime($v['preferred_at'])); ?></div>
                  <div class="text-[10px] text-gray-400"><?php echo date('h:i A', strtotime($v['preferred_at'])); ?></div>
                </div>
                <!-- Center: Info -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="font-semibold text-gray-900 text-sm"><?php echo h($v['client_first_name'].' '.$v['client_last_name']); ?></span>
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full <?php echo $cfg['bg']; ?> <?php echo $cfg['text']; ?> border <?php echo $cfg['border']; ?>">
                      <span class="w-1.5 h-1.5 rounded-full <?php echo $cfg['dot']; ?>"></span>
                      <?php echo $cfg['label']; ?>
                    </span>
                  </div>
                  <div class="text-xs text-gray-500 mb-1">
                    <svg class="inline w-3 h-3 mr-0.5 -mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <?php echo h($locText); ?>
                  </div>
                  <div class="text-xs text-gray-500">
                    <a href="mailto:<?php echo h($v['client_email']); ?>" class="text-green-700 hover:underline"><?php echo h($v['client_email']); ?></a>
                    <span class="mx-1">·</span>
                    <a href="tel:<?php echo h($v['client_phone']); ?>" class="text-green-700 hover:underline"><?php echo h($v['client_phone']); ?></a>
                  </div>
                </div>
                <!-- Right: Action -->
                <div class="flex-shrink-0 flex items-center gap-2">
                  <?php if ($st === 'pending'): ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                      <input type="hidden" name="approve_viewing_id" value="<?php echo (int)$v['id']; ?>">
                      <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 shadow-sm transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                        Approve
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </section>

    <section id="section-profile" class="hidden">
      <?php if (empty($agent['longitude']) || empty($agent['latitude'])): ?>
        <div id="location-reminder" class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-900 p-4 mb-4 rounded">
          <strong>Reminder:</strong> Please set your current location (Longitude and Latitude). This is required for your agent account and helps clients find you more easily.
        </div>
        <script>
          document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('edit-profile-form');
            if (form) {
              form.addEventListener('submit', function() {
                setTimeout(function() {
                  var reminder = document.getElementById('location-reminder');
                  if (reminder) reminder.style.display = 'none';
                }, 500);
              });
            }
          });
        </script>
      <?php endif; ?>
      <h2 class="text-3xl font-bold text-green-900 mb-2">Profile</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-bottom: 1.5em;">View and update your personal and contact information.</p>
      <div class="bg-white rounded-2xl border shadow p-6" style="width:100%;max-width:none;">
        <div class="profile-tabs flex mb-6">
          <button id="tab-profile-info" type="button" class="profile-tab active" aria-pressed="true">Profile Info</button>
          <button id="tab-change-password" type="button" class="profile-tab" aria-pressed="false">Change Password</button>
        </div>

        <div id="profile-info-pane" class="profile-pane active">
          <form id="edit-profile-form" enctype="multipart/form-data" method="post" action="agent_dashboard.php">
            <?php if (!empty($profile_update_success)): ?>
              <div class="bg-green-100 border border-green-300 text-green-900 px-4 py-2 rounded mb-4">Profile updated successfully.</div>
            <?php elseif (!empty($profile_update_error)): ?>
              <div class="bg-red-100 border border-red-300 text-red-900 px-4 py-2 rounded mb-4"><?php echo h($profile_update_error); ?></div>
            <?php endif; ?>
            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-semibold mb-1">First Name</label>
                <input type="text" name="first_name" class="w-full border rounded px-3 py-2"
                       value="<?php echo h($agent['first_name'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="block text-sm font-semibold mb-1">Last Name</label>
                <input type="text" name="last_name" class="w-full border rounded px-3 py-2"
                       value="<?php echo h($agent['last_name'] ?? ''); ?>">
              </div>
              <div>
                <label class="block text-sm font-semibold mb-1">Email</label>
                <input type="email" name="email" class="w-full border rounded px-3 py-2"
                       value="<?php echo h($agent['email'] ?? ''); ?>">
              </div>
              <div>
                <label class="block text-sm font-semibold mb-1">Mobile</label>
                <input type="text" name="mobile" class="w-full border rounded px-3 py-2"
                       value="<?php echo h($agent['mobile'] ?? ''); ?>"> </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-semibold mb-1">Address</label>
                <input type="text" name="address" class="w-full border rounded px-3 py-2"
                       value="<?php echo h($agent['address'] ?? ''); ?>">
              </div>
              <div>
                <label class="block text-sm font-semibold mb-1">Experience (Years)</label>
                <input type="number" name="experience" class="w-full border rounded px-3 py-2"
                       value="<?php echo h((string)($agent['experience'] ?? 0)); ?>">
              </div>
              <div>
                <label class="block text-sm font-semibold mb-1">Total Sales</label>
                <input type="number" name="total_sales" class="w-full border rounded px-3 py-2"
                       value="<?php echo h((string)($agent['total_sales'] ?? 0)); ?>">
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-semibold mb-1">Description</label>
                <textarea name="description" rows="3" class="w-full border rounded px-3 py-2"><?php
                  echo h($agent['description'] ?? '');
                ?></textarea>
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-semibold mb-1">Profile Picture</label>
                <?php if (!empty($agent['profile_picture'])): ?>
                  <img src="<?php echo h($agent['profile_picture']); ?>" class="w-16 h-16 rounded-full object-cover mb-2"
                       onerror="this.style.display='none'">
                <?php endif; ?>
                <input type="file" name="profile_picture" class="w-full border rounded px-3 py-2">
              </div>
              <div class="md:col-span-2 grid grid-cols-2 gap-4 mt-2">
                <div>
                  <label class="block text-sm font-semibold mb-1">Latitude</label>
                  <input type="text" name="latitude" id="agent-latitude" class="w-full border rounded px-3 py-2" value="<?php echo h($agent['latitude'] ?? ''); ?>">
                </div>
                <div>
                  <label class="block text-sm font-semibold mb-1">Longitude</label>
                  <input type="text" name="longitude" id="agent-longitude" class="w-full border rounded px-3 py-2" value="<?php echo h($agent['longitude'] ?? ''); ?>">
                </div>
              </div>
              <div class="md:col-span-2 flex gap-4 mt-2">
                <button id="get-location-btn" type="button" class="bg-green-900 text-white px-4 py-2 rounded" onclick="getAgentLocation()">Get Current Location</button>
                <button type="button" class="bg-green-900 text-white px-4 py-2 rounded" onclick="clearAgentLocation()">Clear Location</button>
              </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <div class="flex justify-end mt-4">
              <button class="bg-green-900 text-white px-6 py-2 rounded hover:bg-green-800">Save</button>
            </div>
          </form>
        </div>

        <div id="change-password-pane" class="profile-pane">
          <form id="change-password-form" method="post" action="agentprofile_update.php">
            <div class="mb-3">
              <label class="block text-sm font-semibold mb-1">New Password</label>
              <input type="password" name="new_password" class="w-full border rounded px-3 py-2" placeholder="New Password">
            </div>
            <div class="mb-3">
              <label class="block text-sm font-semibold mb-1">Confirm New Password</label>
              <input type="password" name="confirm_password" class="w-full border rounded px-3 py-2" placeholder="Confirm Password">
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <button class="bg-green-900 text-white px-6 py-2 rounded hover:bg-green-800">Change Password</button>
          </form>
        </div>
      </div>
    </section>

    <section id="section-sales" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Manage Sales</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review your property sales and transaction history</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div class="mb-4 flex items-center gap-3">
          <label class="text-gray-700">Filter by:</label>
          <select id="filter-select" class="border rounded px-2 py-1">
            <option value="all">All</option>
            <option value="sale_id">Sale ID</option>
            <option value="buyer_name">Buyer Name</option>
            <option value="property_name">Property Name</option>
            <option value="sale_date">Sale Date</option>
          </select>
          <input id="filter-input" type="text" class="border rounded px-2 py-1 ml-2" placeholder="Type to filter..." style="display:none;">
        </div>

        <div class="overflow-x-auto">
          <div id="sale-message" style="display:none;" class="mb-4 p-3 rounded bg-red-100 text-red-800 font-semibold"></div>
          <div id="sale-success-message" style="display:none;" class="bg-green-100 text-green-900 px-4 py-2 rounded mb-4"></div>
          <table class="min-w-full border rounded text-sm">
            <thead>
              <tr class="bg-gray-50 text-gray-700">
                <th class="py-2 px-4 text-left">Property</th>
                <th class="py-2 px-4 text-left">Buyer</th>
                <th class="py-2 px-4 text-left">Sale Price</th>
                <th class="py-2 px-4 text-left">Sale Date</th>
                <th class="py-2 px-4 text-left">Actions</th>
              </tr>
            </thead>
            <tbody id="sales-table-body"></tbody>
          </table>
        </div>

        <div class="mt-6 flex gap-4">
          <button id="add-sale-btn" class="bg-green-800 text-white px-5 py-2 rounded hover:bg-green-900">Add New Sale</button>
          <button id="save-all-sales-btn" class="bg-green-800 text-white px-5 py-2 rounded hover:bg-green-900">Save All Changes</button>
        </div>
      </div>
    </section>

    <section id="section-notifications" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Notifications</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Stay updated with important alerts and system messages</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="notifications-container">
          <p class="text-gray-600">Loading notifications...</p>
        </div>
      </div>
    </section>

    <section id="section-messages" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Messages</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">View and respond to your messages</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="messages-container">
          <p class="text-gray-600">Loading messages...</p>
        </div>
      </div>
    </section>

    <!-- View Message Modal -->
    <div id="viewMessageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);">
      <div style="background:#fff; border-radius:16px; box-shadow:0 25px 60px rgba(0,0,0,0.25), 0 0 0 1px rgba(0,0,0,0.05); width:92%; max-width:580px; overflow:hidden; animation:modalSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);">

        <!-- Header -->
        <div style="padding:22px 28px; background:linear-gradient(135deg, #14532d 0%, #166534 100%); display:flex; align-items:center; justify-content:space-between;">
          <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:38px; height:38px; background:rgba(255,255,255,0.15); border-radius:10px; display:flex; align-items:center; justify-content:center;">
              <svg width="20" height="20" fill="#fff" viewBox="0 0 24 24"><path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/></svg>
            </div>
            <h3 style="margin:0; font-size:18px; font-weight:700; color:#fff; letter-spacing:0.3px;">Message Details</h3>
          </div>
          <button onclick="closeViewMessageModal()" style="background:rgba(255,255,255,0.1); border:none; font-size:20px; color:#fff; cursor:pointer; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">&times;</button>
        </div>

        <!-- Sender Info Bar -->
        <div style="padding:18px 28px; background:#f0fdf4; border-bottom:1px solid #dcfce7; display:flex; align-items:center; gap:14px;">
          <div style="width:48px; height:48px; background:#14532d; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; font-weight:700; flex-shrink:0;">
            <span id="modal-msg-avatar"></span>
          </div>
          <div style="flex:1; min-width:0;">
            <div id="modal-msg-sender" style="font-size:16px; font-weight:700; color:#111827; margin-bottom:2px;"></div>
            <div id="modal-msg-date" style="font-size:13px; color:#6b7280;"></div>
          </div>
        </div>

        <!-- Contact Details -->
        <div style="padding:20px 28px; display:grid; grid-template-columns:1fr 1fr; gap:0; border-bottom:1px solid #f3f4f6;">
          <div style="padding:10px 0; border-right:1px solid #f3f4f6; padding-right:20px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <svg width="14" height="14" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              <label style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.8px;">Email</label>
            </div>
            <div id="modal-msg-email" style="font-size:14px; color:#374151; font-weight:500; word-break:break-all;"></div>
          </div>
          <div style="padding:10px 0; padding-left:20px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
              <svg width="14" height="14" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
              <label style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.8px;">Phone</label>
            </div>
            <div id="modal-msg-phone" style="font-size:14px; color:#374151; font-weight:500;"></div>
          </div>
        </div>

        <!-- Message Body -->
        <div style="padding:24px 28px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <svg width="14" height="14" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <label style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.8px;">Message</label>
          </div>
          <div id="modal-msg-body" style="font-size:14px; color:#1f2937; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding:20px; border-radius:12px; border:1px solid #e2e8f0; line-height:1.7; white-space:pre-wrap; max-height:280px; overflow-y:auto; font-family:'Segoe UI', system-ui, sans-serif;"></div>
        </div>

        <!-- Footer -->
        <div style="padding:18px 28px; border-top:1px solid #f3f4f6; display:flex; gap:10px; justify-content:flex-end; background:#fafafa;">
          <button onclick="closeViewMessageModal()" style="padding:10px 24px; background:#14532d; color:#fff; border:none; border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.2s; box-shadow:0 2px 6px rgba(20,83,45,0.2);" onmouseover="this.style.background='#0f4223'; this.style.transform='translateY(-1px)'" onmouseout="this.style.background='#14532d'; this.style.transform='translateY(0)'">Close</button>
        </div>

      </div>
    </div>
    <style>
      @keyframes modalSlideIn { from { transform:translateY(-30px) scale(0.97); opacity:0; } to { transform:translateY(0) scale(1); opacity:1; } }
      #viewMessageModal > div { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
      #modal-msg-body::-webkit-scrollbar { width: 6px; }
      #modal-msg-body::-webkit-scrollbar-track { background: transparent; }
      #modal-msg-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
      #modal-msg-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>

    <section id="section-audit-logs" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Audit Logs</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review system audit logs for security and compliance</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="audit-logs-container">
          <p class="text-gray-600">Loading audit logs...</p>
        </div>
      </div>
    </section>

    <section id="section-documents" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Document Review</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Review and manage your documents</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="documents-container">
          <p class="text-gray-600">Loading documents...</p>
        </div>
      </div>
    </section>

    <section id="section-viewings" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-1">Viewing Requests</h2>
      <p class="text-gray-500 text-sm mb-5">Manage and track your assigned viewing requests</p>
      <div class="bg-white rounded-2xl border shadow">
        <?php if (empty($all_viewings)): ?>
          <div class="border border-dashed rounded-xl p-8 text-gray-500 text-sm text-center m-6">
            No viewing requests assigned to you yet.
          </div>
        <?php else: ?>
          <!-- Status filter tabs -->
          <div class="flex flex-wrap gap-2 px-6 pt-5 pb-3 border-b">
            <button onclick="filterViewings('all')" class="vw-filter-tab active" data-filter="all">All <span class="vw-count"><?php echo count($all_viewings); ?></span></button>
            <?php
              $statusCounts = [];
              foreach ($all_viewings as $vv) {
                $s = $vv['status'];
                $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
              }
              $tabOrder = ['pending','scheduled','rescheduled','completed','cancelled'];
              foreach ($tabOrder as $tab):
                if (($statusCounts[$tab] ?? 0) > 0):
            ?>
              <button onclick="filterViewings('<?php echo $tab; ?>')" class="vw-filter-tab" data-filter="<?php echo $tab; ?>"><?php echo ucfirst($tab); ?> <span class="vw-count"><?php echo $statusCounts[$tab]; ?></span></button>
            <?php endif; endforeach; ?>
          </div>

          <div class="divide-y">
            <?php foreach ($all_viewings as $v):
              $st = $v['status'];
              $statusCfg = [
                'pending'     => ['bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'border' => 'border-amber-200', 'dot' => 'bg-amber-400',   'label' => 'Pending'],
                'requested'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'border' => 'border-amber-200', 'dot' => 'bg-amber-400',   'label' => 'Requested'],
                'scheduled'   => ['bg' => 'bg-green-50',   'text' => 'text-green-700',   'border' => 'border-green-200', 'dot' => 'bg-green-500',   'label' => 'Scheduled'],
                'rescheduled' => ['bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'border' => 'border-blue-200',  'dot' => 'bg-blue-400',    'label' => 'Rescheduled'],
                'completed'   => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200','dot' => 'bg-emerald-500','label' => 'Completed'],
                'cancelled'   => ['bg' => 'bg-red-50',     'text' => 'text-red-700',     'border' => 'border-red-200',   'dot' => 'bg-red-400',     'label' => 'Cancelled'],
                'no_show_agent'=>['bg' => 'bg-red-50',     'text' => 'text-red-700',     'border' => 'border-red-200',   'dot' => 'bg-red-400',     'label' => 'No Show (Agent)'],
                'no_show_client'=>['bg'=>'bg-red-50',      'text' => 'text-red-700',     'border' => 'border-red-200',   'dot' => 'bg-red-400',     'label' => 'No Show (Client)'],
              ];
              $cfg = $statusCfg[$st] ?? $statusCfg['pending'];

              // Location text
              $locParts = [];
              if (!empty($v['location_name'])) $locParts[] = $v['location_name'];
              if (!empty($v['block_number']) && !empty($v['lot_number'])) {
                $lotLabel = 'Block ' . $v['block_number'] . ', Lot ' . $v['lot_number'];
                if (!empty($v['lot_size'])) $lotLabel .= ' (' . number_format($v['lot_size'], 2) . ' sqm)';
                $locParts[] = $lotLabel;
              } elseif (!empty($v['lot_no'])) {
                $locParts[] = 'Lot ' . $v['lot_no'];
              }
              $locText = $locParts ? implode(' — ', $locParts) : 'N/A';
            ?>
            <div class="vw-row p-5 hover:bg-gray-50/50 transition" data-status="<?php echo h($st); ?>">
              <div class="flex items-start gap-4">
                <!-- Date badge -->
                <div class="flex-shrink-0 text-center bg-white rounded-xl border shadow-sm px-3 py-2 min-w-[58px]">
                  <?php if ($v['preferred_at']): ?>
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"><?php echo date('M', strtotime($v['preferred_at'])); ?></div>
                    <div class="text-xl font-extrabold text-green-900 leading-tight"><?php echo date('d', strtotime($v['preferred_at'])); ?></div>
                    <div class="text-[10px] text-gray-400 font-medium"><?php echo date('h:i A', strtotime($v['preferred_at'])); ?></div>
                  <?php else: ?>
                    <div class="text-xs text-gray-400">TBD</div>
                  <?php endif; ?>
                </div>

                <!-- Main content -->
                <div class="flex-1 min-w-0">
                  <!-- Top row: Name + Status -->
                  <div class="flex flex-wrap items-center gap-2 mb-1.5">
                    <span class="font-semibold text-gray-900"><?php echo h($v['client_first_name'] . ' ' . $v['client_last_name']); ?></span>
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-0.5 rounded-full <?php echo $cfg['bg']; ?> <?php echo $cfg['text']; ?> border <?php echo $cfg['border']; ?>">
                      <span class="w-1.5 h-1.5 rounded-full <?php echo $cfg['dot']; ?>"></span>
                      <?php echo $cfg['label']; ?>
                    </span>
                    <?php if (!empty($v['lot_price'])): ?>
                      <span class="text-xs text-gray-500 font-medium">₱<?php echo number_format($v['lot_price'], 2); ?></span>
                    <?php endif; ?>
                  </div>

                  <!-- Info row -->
                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1">
                      <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                      <?php echo h($locText); ?>
                    </span>
                    <span class="inline-flex items-center gap-1">
                      <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                      <a href="mailto:<?php echo h($v['client_email']); ?>" class="text-green-700 hover:underline"><?php echo h($v['client_email']); ?></a>
                    </span>
                    <span class="inline-flex items-center gap-1">
                      <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                      <a href="tel:<?php echo h($v['client_phone']); ?>" class="text-green-700 hover:underline"><?php echo h($v['client_phone']); ?></a>
                    </span>
                  </div>

                  <?php if (!empty($v['notes'])): ?>
                    <div class="text-xs text-gray-500 mt-1.5 italic">
                      <svg class="inline w-3 h-3 mr-0.5 -mt-0.5 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                      <?php echo h($v['notes']); ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($st === 'cancelled' && !empty($v['cancellation_reason'])): ?>
                    <div class="text-xs text-red-600 mt-1.5">
                      <strong>Cancellation reason:</strong> <?php echo h($v['cancellation_reason']); ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Right: Action buttons -->
                <div class="flex-shrink-0 flex flex-col items-end gap-1.5">
                  <?php if (in_array($st, ['pending', 'requested', 'scheduled'])): ?>
                    <?php if ($st === 'pending'): ?>
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="approve_viewing_id" value="<?php echo (int)$v['id']; ?>">
                        <input type="hidden" name="redirect_to" value="viewings">
                        <button type="submit" class="vw-btn vw-btn-approve">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                          Approve
                        </button>
                      </form>
                    <?php endif; ?>
                    <button type="button" class="vw-btn vw-btn-cancel cancel-init-btn" id="cancel-init-btn-<?php echo (int)$v['id']; ?>" onclick="showCancelReason(this, <?php echo (int)$v['id']; ?>)">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                      Cancel
                    </button>
                    <form method="post" class="cancel-reason-form" id="cancel-reason-form-<?php echo (int)$v['id']; ?>" style="display:none;width:100%;max-width:280px;">
                      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                      <input type="hidden" name="viewing_id" value="<?php echo (int)$v['id']; ?>">
                      <input type="hidden" name="redirect_to" value="viewings">
                      <textarea name="cancellation_reason" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs mt-1 focus:ring-2 focus:ring-red-200 focus:border-red-400 outline-none resize-none" placeholder="Reason for cancellation (required)" required></textarea>
                      <div class="flex gap-1.5 mt-1.5">
                        <button name="viewing_action" value="cancelled" class="vw-btn vw-btn-cancel" style="font-size:11px;">Submit</button>
                        <button type="button" class="vw-btn vw-btn-secondary" style="font-size:11px;" onclick="hideCancelReason(<?php echo (int)$v['id']; ?>)">Back</button>
                      </div>
                    </form>
                  <?php endif; ?>

                  <!-- Delete button (always visible) -->
                  <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete this viewing record?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="delete_viewing_id" value="<?php echo (int)$v['id']; ?>">
                    <input type="hidden" name="redirect_to" value="viewings">
                    <button type="submit" class="vw-btn vw-btn-delete">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      Delete
                    </button>
                  </form>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <style>
        /* Viewing Button System */
        .vw-btn {
          display: inline-flex; align-items: center; gap: 5px;
          padding: 5px 14px; border-radius: 8px;
          font-size: 12px; font-weight: 600;
          border: none; cursor: pointer;
          transition: all 0.15s ease;
          white-space: nowrap;
          line-height: 1.5;
        }
        .vw-btn:hover { transform: translateY(-1px); }
        .vw-btn-approve { background: #2563eb; color: #fff; }
        .vw-btn-approve:hover { background: #1d4ed8; box-shadow: 0 2px 8px rgba(37,99,235,0.25); }
        .vw-btn-complete { background: #16a34a; color: #fff; }
        .vw-btn-complete:hover { background: #15803d; box-shadow: 0 2px 8px rgba(22,163,74,0.25); }
        .vw-btn-cancel { background: #ef4444; color: #fff; }
        .vw-btn-cancel:hover { background: #dc2626; box-shadow: 0 2px 8px rgba(239,68,68,0.25); }
        .vw-btn-delete { background: #fff; color: #9ca3af; border: 1.5px solid #e5e7eb; }
        .vw-btn-delete:hover { color: #ef4444; border-color: #fca5a5; background: #fef2f2; box-shadow: 0 2px 8px rgba(239,68,68,0.1); }
        .vw-btn-secondary { background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; }
        .vw-btn-secondary:hover { background: #e2e8f0; }

        /* Filter Tabs */
        .vw-filter-tab {
          display: inline-flex; align-items: center; gap: 5px;
          padding: 6px 14px; border-radius: 8px;
          font-size: 13px; font-weight: 500;
          border: 1.5px solid #e2e8f0;
          background: #fff; color: #64748b;
          cursor: pointer; transition: all 0.15s;
        }
        .vw-filter-tab:hover { background: #f1f5f9; color: #334155; }
        .vw-filter-tab.active { background: #14532d; color: #fff; border-color: #14532d; }
        .vw-filter-tab.active .vw-count { background: rgba(255,255,255,0.2); color: #fff; }
        .vw-count {
          display: inline-flex; align-items: center; justify-content: center;
          min-width: 20px; height: 20px;
          padding: 0 6px; border-radius: 10px;
          font-size: 11px; font-weight: 700;
          background: #f1f5f9; color: #64748b;
        }
      </style>

      <script>
        function filterViewings(status) {
          document.querySelectorAll('.vw-filter-tab').forEach(t => t.classList.remove('active'));
          document.querySelector('.vw-filter-tab[data-filter="' + status + '"]').classList.add('active');
          document.querySelectorAll('.vw-row').forEach(row => {
            row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
          });
        }
        function showCancelReason(btn, id) {
          document.querySelectorAll('.cancel-reason-form').forEach(f => f.style.display = 'none');
          document.querySelectorAll('.cancel-init-btn').forEach(b => b.style.display = '');
          var form = document.getElementById('cancel-reason-form-' + id);
          if (form) { form.style.display = 'block'; form.scrollIntoView({behavior: 'smooth', block: 'center'}); }
          var cancelBtn = document.getElementById('cancel-init-btn-' + id);
          if (cancelBtn) cancelBtn.style.display = 'none';
        }
        function hideCancelReason(id) {
          var form = document.getElementById('cancel-reason-form-' + id);
          if (form) form.style.display = 'none';
          var cancelBtn = document.getElementById('cancel-init-btn-' + id);
          if (cancelBtn) cancelBtn.style.display = '';
        }
      </script>
    </section>

    <section id="section-leads" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">My Assigned Leads</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Manage and track your assigned leads</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <?php if (empty($leads)): ?>
          <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm">
            No leads assigned to you yet.
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full border rounded text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="py-2 px-4 text-left border">Name</th>
                  <th class="py-2 px-4 text-left border">Email</th>
                  <th class="py-2 px-4 text-left border">Phone</th>
                  <th class="py-2 px-4 text-left border">Status</th>
                  <th class="py-2 px-4 text-left border">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($leads as $lead): ?>
                <tr class="border-t">
                  <td class="py-2 px-4 border"><?php echo h($lead['first_name'] . ' ' . $lead['last_name']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['email']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['phone']); ?></td>
                  <td class="py-2 px-4 border"><?php echo h($lead['status']); ?></td>
                  <td class="py-2 px-4 border">
                    <a href="lead_timeline.php?id=<?php echo (int)$lead['id']; ?>" class="text-blue-600 hover:underline">
                      Timeline
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>

<script>
const links = document.querySelectorAll('#spa-nav a[data-target]');
let sections = [
  'section-dashboard',
  'section-profile',
  'section-viewings',
  'section-sales',
  'section-notifications',
  'section-messages',
  'section-leads',
  'section-audit-logs',
  'section-documents'
].map(id => document.getElementById(id));

// Ensure every section element has the `.section` class and remove Tailwind's `hidden`
sections.forEach(s => {
  if (!s) return;
  if (!s.classList.contains('section')) s.classList.add('section');
  if (s.classList.contains('hidden')) s.classList.remove('hidden');
});

// Rebuild sections array to only include existing elements
sections = sections.filter(Boolean);

// If none are active, set dashboard active by default to avoid stuck UI
if (!sections.some(s => s.classList.contains('active'))) {
  const dash = document.getElementById('section-dashboard');
  if (dash) dash.classList.add('active');
}

function showSection(id) {
  const current = sections.find(s => s && s.classList.contains('active'));
  const target = sections.find(s => s && s.id === id);

  links.forEach(a => a.classList.toggle('nav-active', a.dataset.target === id));

  function triggerLoads(sectionId) {
    if (sectionId === 'section-notifications') loadAgentNotifications();
    else if (sectionId === 'section-messages') loadAgentMessages();
    else if (sectionId === 'section-audit-logs') loadAgentAuditLogs();
    else if (sectionId === 'section-documents') loadAgentDocuments();
  }

  // Immediate swap of `active` class — transitions handle the fade.
  if (current && current !== target) {
    current.classList.remove('active');
  }
  if (target) {
    target.classList.add('active');
    requestAnimationFrame(() => triggerLoads(id));
  }
}

// handle clicks
links.forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const id = a.dataset.target;
    history.replaceState(null, '', a.getAttribute('href'));
    showSection(id);
  });
});

// initial route based on hash
const hash = location.hash.replace('#','');
const map = {
  'dashboard': 'section-dashboard',
  'profile': 'section-profile',
  'viewings': 'section-viewings',
  'sales': 'section-sales',
  'notifications': 'section-notifications',
  'messages': 'section-messages',
  'leads': 'section-leads',
  'audit-logs': 'section-audit-logs',
  'documents': 'section-documents'
};
showSection(map[hash] || 'section-dashboard');

// Admin-style dynamic logout confirm modal
function confirmLogout(e) {
  if (e && e.preventDefault) e.preventDefault();
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

  const cancelBtn = modal.querySelector('#cancel-logout');
  const okBtn = modal.querySelector('#confirm-logout');

  function removeModal() { if (modal && modal.parentNode) modal.parentNode.removeChild(modal); }

  cancelBtn && cancelBtn.addEventListener('click', function(){ removeModal(); });
  okBtn && okBtn.addEventListener('click', function(){ window.location.href = 'logout.php'; });

  function escHandler(ev){ if(ev.key === 'Escape') { removeModal(); document.removeEventListener('keydown', escHandler); } }
  document.addEventListener('keydown', escHandler);
}

// Notifications
function loadAgentNotifications() {
  const container = document.getElementById('notifications-container');
  if (!container) return;
  container.innerHTML = '<p class="text-gray-600">Loading notifications...</p>';
  fetch(window.location.pathname + '?fetch=notifications')
    .then(response => response.json())
    .then(notifications => {
      if (!notifications.length) {
        container.innerHTML = '<p class="text-gray-600">No notifications yet.</p>';
        return;
      }
      container.innerHTML = notifications.map(n => `
        <div class="mb-4 p-4 rounded border ${n.is_read ? 'bg-gray-50' : 'bg-green-50'}">
          <div class="font-semibold text-green-900">${n.title}</div>
          <div class="text-gray-700 mb-2">${n.message}</div>
          <div class="text-xs text-gray-500">${new Date(n.created_at).toLocaleString()}</div>
          <div class="mt-2 flex gap-2">
            ${!n.is_read ? `<button class="px-3 py-1 text-xs bg-green-700 text-white rounded" onclick="markNotificationRead(${n.id})">Mark as Read</button>` : ''}
            <button class="px-3 py-1 text-xs bg-red-600 text-white rounded" onclick="deleteNotification(${n.id})">Delete</button>
          </div>
        </div>
      `).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load notifications.</p>';
    });
}

function markNotificationRead(id) {
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `mark_read=${id}&csrf_token=<?php echo h($_SESSION['csrf_token']); ?>`
  })
  .then(res => res.json())
  .then(() => loadAgentNotifications());
}

function deleteNotification(id) {
  if (!confirm('Delete this notification?')) return;
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `delete_notif=${id}&csrf_token=<?php echo h($_SESSION['csrf_token']); ?>`
  })
  .then(res => res.json())
  .then(() => loadAgentNotifications());
}

// Messages
function loadAgentMessages() {
  const container = document.getElementById('messages-container');
  if (!container) return;
  container.innerHTML = '<p class="text-gray-600">Loading messages...</p>';
  fetch(window.location.pathname + '?fetch=messages')
    .then(response => response.json())
    .then(messages => {
      if (!messages.length) {
        container.innerHTML = '<div style="text-align:center; padding:30px;"><p class="text-gray-500" style="font-size:15px;">No messages yet.</p></div>';
        return;
      }
      container.innerHTML = messages.map(m => {
        const isUnread = !m.is_read;
        const borderColor = isUnread ? '#3b82f6' : '#e5e7eb';
        const bgColor = isUnread ? '#eff6ff' : '#ffffff';
        const preview = m.message && m.message.length > 80 ? m.message.substring(0, 80) + '...' : (m.message || '');
        const dateStr = m.created_at ? new Date(m.created_at).toLocaleString() : '';
        const escapedName = (m.name || 'Unknown').replace(/'/g, "\\'");
        const escapedEmail = (m.email || 'N/A').replace(/'/g, "\\'");
        const escapedPhone = (m.phone || 'N/A').replace(/'/g, "\\'");
        const escapedMsg = (m.message || '').replace(/'/g, "\\'").replace(/\n/g, '\\n');
        const escapedDate = dateStr.replace(/'/g, "\\'");

        return `
        <div style="display:flex; align-items:flex-start; gap:16px; padding:18px; margin-bottom:12px; border-radius:12px; border:1.5px solid ${borderColor}; background:${bgColor}; transition:all 0.2s;">
          <div style="width:44px; height:44px; background:${isUnread ? '#dbeafe' : '#f3f4f6'}; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:${isUnread ? '#1d4ed8' : '#6b7280'}; font-size:20px;">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M21 6.5a2.5 2.5 0 00-2.5-2.5h-13A2.5 2.5 0 003 6.5v11A2.5 2.5 0 005.5 20h13a2.5 2.5 0 002.5-2.5v-11zm-2.5 0l-6.5 5.5-6.5-5.5"/></svg>
          </div>
          <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
              <span style="font-weight:700; color:#111827; font-size:15px;">${m.name || 'Unknown Sender'}</span>
              ${isUnread ? '<span style="background:#3b82f6; color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;">New</span>' : ''}
            </div>
            <div style="font-size:13px; color:#6b7280; margin-bottom:6px;">${m.email || ''}</div>
            <div style="font-size:14px; color:#374151; margin-bottom:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${preview}</div>
            <div style="display:flex; align-items:center; justify-content:space-between;">
              <span style="font-size:12px; color:#9ca3af;">${dateStr}</span>
              <div style="display:flex; gap:8px;">
                <button onclick="viewMessage('${escapedName}','${escapedEmail}','${escapedPhone}','${escapedDate}','${escapedMsg}', ${m.id}, ${isUnread})" style="padding:6px 14px; font-size:13px; font-weight:600; background:#14532d; color:#fff; border:none; border-radius:7px; cursor:pointer; transition:0.2s;" onmouseover="this.style.background='#0f4223'" onmouseout="this.style.background='#14532d'">View Message</button>
                ${isUnread ? `<button onclick="markMessageRead(${m.id})" style="padding:6px 14px; font-size:13px; font-weight:600; background:#2563eb; color:#fff; border:none; border-radius:7px; cursor:pointer;">Mark Read</button>` : ''}
                <button onclick="deleteMessage(${m.id})" style="padding:6px 14px; font-size:13px; font-weight:600; background:#dc2626; color:#fff; border:none; border-radius:7px; cursor:pointer;">Delete</button>
              </div>
            </div>
          </div>
        </div>`;
      }).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load messages.</p>';
    });
}

function viewMessage(name, email, phone, date, message, msgId, isUnread) {
  document.getElementById('modal-msg-sender').textContent = name;
  document.getElementById('modal-msg-email').textContent = email || 'N/A';
  document.getElementById('modal-msg-phone').textContent = phone || 'N/A';
  document.getElementById('modal-msg-date').textContent = date;
  document.getElementById('modal-msg-body').textContent = message;
  
  // Set avatar initial
  const initials = name ? name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase() : '?';
  document.getElementById('modal-msg-avatar').textContent = initials;
  
  const modal = document.getElementById('viewMessageModal');
  modal.style.display = 'flex';
  
  // Auto-mark as read when viewed
  if (isUnread && msgId) {
    markMessageRead(msgId);
  }
}

function closeViewMessageModal() {
  document.getElementById('viewMessageModal').style.display = 'none';
}

// Close modal on Escape or overlay click
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeViewMessageModal();
});
document.addEventListener('click', function(e) {
  if (e.target && e.target.id === 'viewMessageModal') closeViewMessageModal();
});

function markMessageRead(id) {
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `mark_message_read=${id}&csrf_token=<?php echo h($_SESSION['csrf_token']); ?>`
  })
  .then(res => res.json())
  .then(() => loadAgentMessages());
}

function deleteMessage(id) {
  if (!confirm('Delete this message?')) return;
  fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `delete_message=${id}&csrf_token=<?php echo h($_SESSION['csrf_token']); ?>`
  })
  .then(res => res.json())
  .then(() => loadAgentMessages());
}

// Audit Logs
function loadAgentAuditLogs() {
  const container = document.getElementById('audit-logs-container');
  if (!container) return;
  container.innerHTML = '<p class="text-gray-600">Loading audit logs...</p>';
  fetch(window.location.pathname + '?fetch=audit_logs')
    .then(response => response.json())
    .then(logs => {
      if (!logs.length) {
        container.innerHTML = '<p class="text-gray-600">No audit logs found.</p>';
        return;
      }
      container.innerHTML = logs.map(log => `
        <div class="mb-4 p-4 rounded border bg-gray-50">
          <div class="font-semibold text-green-900">${log.action}</div>
          <div class="text-gray-700 mb-2">${log.details}</div>
          <div class="text-xs text-gray-500">${new Date(log.created_at).toLocaleString()}</div>
        </div>
      `).join('');
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load audit logs.</p>';
    });
}

// Documents
function loadAgentDocuments() {
  const container = document.getElementById('documents-container');
  if (!container) return;
  container.innerHTML = '<p class="text-gray-600">Loading documents...</p>';
  fetch(window.location.pathname + '?fetch=user_documents')
    .then(response => response.json())
    .then(docs => {
      if (!docs.length) {
        container.innerHTML = '<p class="text-gray-600">No user documents found.</p>';
        return;
      }
      container.innerHTML = `
        <div class="overflow-x-auto">
          <table class="min-w-full border rounded text-sm">
            <thead>
              <tr class="bg-gray-50 text-gray-700">
                <th class="py-2 px-4 text-left">Client</th>
                <th class="py-2 px-4 text-left">Document Type</th>
                <th class="py-2 px-4 text-left">File</th>
                <th class="py-2 px-4 text-left">Status</th>
                <th class="py-2 px-4 text-left">Uploaded</th>
                <th class="py-2 px-4 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${docs.map(doc => {
                const statusColors = {
                  'pending_review': 'bg-yellow-100 text-yellow-800',
                  'under_review': 'bg-blue-100 text-blue-800',
                  'approved': 'bg-green-100 text-green-800',
                  'rejected': 'bg-red-100 text-red-800',
                  'requires_revision': 'bg-orange-100 text-orange-800'
                };
                const statusColor = statusColors[doc.status] || 'bg-gray-100 text-gray-800';
                return `
                  <tr>
                    <td class="py-2 px-4 border">${(doc.first_name || '') + ' ' + (doc.last_name || '')}</td>
                    <td class="py-2 px-4 border">${doc.doc_type}</td>
                    <td class="py-2 px-4 border">
                      <a href="${doc.file_path}" target="_blank" class="text-blue-600 hover:text-blue-800 underline">${doc.file_name}</a>
                    </td>
                    <td class="py-2 px-4 border">
                      <span class="px-2 py-1 rounded-full text-xs font-medium ${statusColor}">${doc.status.replace('_', ' ')}</span>
                    </td>
                    <td class="py-2 px-4 border">${new Date(doc.uploaded_at).toLocaleDateString()}</td>
                    <td class="py-2 px-4 border">
                      <button onclick="updateDocumentStatus(${doc.id}, '${doc.status}')" class="px-3 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700">Update Status</button>
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    })
    .catch(() => {
      container.innerHTML = '<p class="text-red-600">Failed to load user documents.</p>';
    });
}

// Profile tabs
document.addEventListener('DOMContentLoaded', function() {
  const tabProfileInfo = document.getElementById('tab-profile-info');
  const tabChangePassword = document.getElementById('tab-change-password');
  const profilePane = document.getElementById('profile-info-pane');
  const passwordPane = document.getElementById('change-password-pane');

  if (tabProfileInfo && tabChangePassword && profilePane && passwordPane) {
    tabProfileInfo.onclick = function() {
      this.classList.add('active');
      this.setAttribute('aria-pressed', 'true');
      tabChangePassword.classList.remove('active');
      tabChangePassword.setAttribute('aria-pressed', 'false');
      profilePane.classList.add('active');
      passwordPane.classList.remove('active');
    };
    tabChangePassword.onclick = function() {
      this.classList.add('active');
      this.setAttribute('aria-pressed', 'true');
      tabProfileInfo.classList.remove('active');
      tabProfileInfo.setAttribute('aria-pressed', 'false');
      profilePane.classList.remove('active');
      passwordPane.classList.add('active');
    };
  }
});

// Clear location button
function clearAgentLocation() {
  const lat = document.getElementById('agent-latitude');
  const lng = document.getElementById('agent-longitude');
  if (lat) lat.value = '';
  if (lng) lng.value = '';
}
</script>

<script src="agentdb/js/main.js"></script>

<script>
function getAgentLocation() {
  var btn = document.getElementById('get-location-btn');
  if (!btn) return;
  var origText = btn.textContent;
  btn.textContent = 'Loading...';
  btn.disabled = true;
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(position) {
      var latInput = document.getElementById('agent-latitude');
      var lngInput = document.getElementById('agent-longitude');
      if (latInput) latInput.value = position.coords.latitude;
      if (lngInput) lngInput.value = position.coords.longitude;
      btn.textContent = origText;
      btn.disabled = false;
    }, function(error) {
      alert('Unable to retrieve location: ' + error.message);
      btn.textContent = origText;
      btn.disabled = false;
    });
  } else {
    alert('Geolocation is not supported by your browser.');
    btn.textContent = origText;
    btn.disabled = false;
  }
}
</script>
</body>
</html>
