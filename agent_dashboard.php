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

$agentUnreadNotifications = 0;
$checkAgentNotifications = $conn->query("SHOW TABLES LIKE 'agent_notifications'");
if ($checkAgentNotifications && $checkAgentNotifications->num_rows > 0) {
  $hasIsReadColumn = false;
  $colCheck = $conn->query("SHOW COLUMNS FROM agent_notifications LIKE 'is_read'");
  if ($colCheck && $colCheck->num_rows > 0) {
    $hasIsReadColumn = true;
  }

  if ($hasIsReadColumn && ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM agent_notifications WHERE agent_id=? AND COALESCE(is_read, 0)=0"))) {
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $agentUnreadNotifications = (int)($row['c'] ?? 0);
    }
    $stmt->close();
  } elseif ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM agent_notifications WHERE agent_id=?")) {
    $stmt->bind_param('i', $agentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $agentUnreadNotifications = (int)($row['c'] ?? 0);
    }
    $stmt->close();
  }
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasAgentColumn(mysqli $conn, string $column): bool {
  $col = $conn->real_escape_string($column);
  $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='agent_accounts' AND COLUMN_NAME='$col'");
  if (!$res) return false;
  $row = $res->fetch_assoc();
  return ((int)($row['c'] ?? 0)) > 0;
}

function hasTableColumn(mysqli $conn, string $table, string $column): bool {
  $tbl = $conn->real_escape_string($table);
  $col = $conn->real_escape_string($column);
  $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='$col'");
  if (!$res) return false;
  $row = $res->fetch_assoc();
  return ((int)($row['c'] ?? 0)) > 0;
}

function normalizeEmailKey(string $email): string {
  return strtolower(trim($email));
}

function normalizePhoneKey(string $phone): string {
  return preg_replace('/\D+/', '', $phone);
}

function countDueInstallments(?string $startDate, int $dueDay, ?DateTime $today = null): int {
  $startDate = trim((string)$startDate);
  if ($startDate === '') {
    return 0;
  }

  try {
    $start = new DateTime($startDate);
  } catch (Throwable $e) {
    return 0;
  }

  $today = $today ?? new DateTime('today');
  if ($today < $start) {
    return 0;
  }

  if ($dueDay < 1 || $dueDay > 31) {
    $dueDay = (int)$start->format('j');
  }

  $cursor = new DateTime($start->format('Y-m-01'));
  $daysInStartMonth = (int)$cursor->format('t');
  $cursor->setDate((int)$cursor->format('Y'), (int)$cursor->format('m'), min($dueDay, $daysInStartMonth));
  if ($cursor < $start) {
    $cursor = clone $start;
  }

  $count = 0;
  $guard = 0;
  while ($cursor <= $today && $guard < 600) {
    $count++;
    $cursor->modify('first day of next month');
    $daysInMonth = (int)$cursor->format('t');
    $cursor->setDate((int)$cursor->format('Y'), (int)$cursor->format('m'), min($dueDay, $daysInMonth));
    $guard++;
  }

  return $count;
}

function computeCollectionFollowup(array $record): array {
  $lotStatus = strtolower(trim((string)($record['lot_status'] ?? '')));
  $paymentType = strtolower(trim((string)($record['payment_type'] ?? '')));
  $totalPaid = (float)($record['total_paid'] ?? 0);
  $monthlyAmount = (float)($record['monthly_amount'] ?? 0);
  $lotPrice = (float)($record['lot_price'] ?? 0);
  $paymentDeadline = trim((string)($record['payment_deadline'] ?? ''));
  $dueDay = (int)($record['payment_due_day'] ?? 0);

  if ($lotStatus === 'fully paid' || $paymentType === 'fully paid' || ($lotPrice > 0 && $totalPaid >= $lotPrice)) {
    return [
      'health_label' => 'Current / Fully Paid',
      'health_class' => 'bg-green-100 text-green-800',
      'outstanding_amount' => max(0, $lotPrice - $totalPaid),
      'needs_followup' => false,
    ];
  }

  if ($monthlyAmount > 0 && $paymentDeadline !== '') {
    $dueInstallments = countDueInstallments($paymentDeadline, $dueDay);
    $paidInstallments = (int)floor(($totalPaid + 0.0001) / $monthlyAmount);
    $overdueInstallments = max(0, $dueInstallments - $paidInstallments);

    if ($overdueInstallments > 0) {
      return [
        'health_label' => 'Overdue (' . $overdueInstallments . ' month' . ($overdueInstallments > 1 ? 's' : '') . ')',
        'health_class' => 'bg-red-100 text-red-800',
        'outstanding_amount' => $overdueInstallments * $monthlyAmount,
        'needs_followup' => true,
      ];
    }

    return [
      'health_label' => 'Current Installment',
      'health_class' => 'bg-emerald-100 text-emerald-800',
      'outstanding_amount' => max(0, $lotPrice - $totalPaid),
      'needs_followup' => false,
    ];
  }

  if ($totalPaid <= 0 && ($paymentType === 'down payment' || $lotStatus === 'down payment' || $lotStatus === 'reserved')) {
    return [
      'health_label' => 'No payment recorded yet',
      'health_class' => 'bg-amber-100 text-amber-800',
      'outstanding_amount' => max(0, $lotPrice),
      'needs_followup' => true,
    ];
  }

  return [
    'health_label' => 'For follow-up',
    'health_class' => 'bg-yellow-100 text-yellow-800',
    'outstanding_amount' => max(0, $lotPrice - $totalPaid),
    'needs_followup' => true,
  ];
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

$conn->query("
  CREATE TABLE IF NOT EXISTS agent_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_agent_user_review (agent_id, user_id),
    INDEX idx_agent_reviews_agent (agent_id, created_at),
    INDEX idx_agent_reviews_user (user_id, created_at)
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
    // Ensure appointment_type column exists
    $conn->query("ALTER TABLE viewings ADD COLUMN IF NOT EXISTS appointment_type VARCHAR(32) DEFAULT 'appointment'");
    // Check if this is a reservation or just an appointment
    $viewingType = 'appointment';
    $typeRow = $conn->query("SELECT appointment_type FROM viewings WHERE id=$vid AND agent_id=$agentId LIMIT 1");
    if ($typeRow && $typeData = $typeRow->fetch_assoc()) {
      $viewingType = $typeData['appointment_type'] ?? 'appointment';
    }
    if ($stmt = $conn->prepare("UPDATE viewings SET status='scheduled' WHERE id=? AND agent_id=? AND status='pending'")) {
      $stmt->bind_param('ii', $vid, $agentId);
      $stmt->execute();
      $stmt->close();
    }
    // Only reserve lot if this is a reservation, not an appointment
    if ($viewingType === 'reservation') {
      $lotRow = $conn->query("SELECT lot_id FROM viewings WHERE id=$vid AND agent_id=$agentId LIMIT 1");
      if ($lotRow) {
        $lr = $lotRow->fetch_assoc();
        $lotId = (int)($lr['lot_id'] ?? 0);
        if ($lotId > 0) {
          $conn->query("UPDATE lots SET status='Reserved' WHERE id=$lotId AND COALESCE(NULLIF(status,''),'Available')='Available'");
          $conn->query("UPDATE pin_locations SET pin_status='reserved' WHERE lot_id=$lotId AND LOWER(COALESCE(pin_status,'available'))='available'");
        }
      }
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'dashboard'));
    exit;
  }

  /* Generic viewing status updates (complete / cancelled / etc.) */
  if (isset($_POST['viewing_action'], $_POST['viewing_id'])) {
    $vid = (int)$_POST['viewing_id'];
    $action = $_POST['viewing_action'];
    $cancelReason = trim($_POST['cancellation_reason'] ?? '');
    $allowed = ['completed','no_show_agent','no_show_client','cancelled','scheduled'];
    if (in_array($action, $allowed, true)) {
      if ($action === 'cancelled' && $cancelReason !== '') {
        // Save status + cancellation_reason together
        // Ensure column exists first
        $conn->query("ALTER TABLE viewings ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL");
        if ($stmt = $conn->prepare("UPDATE viewings SET status=?, cancellation_reason=? WHERE id=? AND agent_id=?")) {
          $stmt->bind_param('ssii', $action, $cancelReason, $vid, $agentId);
          $stmt->execute();
          $stmt->close();
        }
      } else {
        if ($stmt = $conn->prepare("UPDATE viewings SET status=? WHERE id=? AND agent_id=?")) {
          $stmt->bind_param('sii', $action, $vid, $agentId);
          $stmt->execute();
          $stmt->close();
        }
      }

      // If the viewing is cancelled, revert lot + notify client
      if ($action === 'cancelled') {
        $viewRow = $conn->query("SELECT lot_id, client_email, client_phone, client_first_name, client_last_name, lot_no FROM viewings WHERE id=$vid LIMIT 1");
        if ($viewRow) {
          $vr = $viewRow->fetch_assoc();
          $lotId = (int)($vr['lot_id'] ?? 0);
          if ($lotId > 0) {
            $conn->query("UPDATE lots SET status='Available' WHERE id=$lotId AND status='Reserved'");
            $conn->query("UPDATE pin_locations SET pin_status='available' WHERE lot_id=$lotId AND LOWER(pin_status)='reserved'");
          }

          // Notify the client — find their user_id by email or phone
          $clientUserId = 0;
          $clientEmail = strtolower(trim((string)($vr['client_email'] ?? '')));
          $clientPhone = preg_replace('/\D+/', '', (string)($vr['client_phone'] ?? ''));
          if ($clientEmail !== '') {
            $uStmt = $conn->prepare("SELECT id FROM user_accounts WHERE LOWER(TRIM(email))=? LIMIT 1");
            if ($uStmt) {
              $uStmt->bind_param('s', $clientEmail);
              $uStmt->execute();
              $uRes = $uStmt->get_result();
              if ($uRow = $uRes->fetch_assoc()) $clientUserId = (int)$uRow['id'];
              $uStmt->close();
            }
          }
          if ($clientUserId === 0 && $clientPhone !== '') {
            $phoneColExists = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME IN ('phone_number','mobile_number') LIMIT 1");
            $pcRow = $phoneColExists ? $phoneColExists->fetch_assoc() : null;
            if ($pcRow && (int)$pcRow['c'] > 0) {
              $phoneCol = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME IN ('phone_number','mobile_number') LIMIT 1");
              $pcn = $phoneCol ? $phoneCol->fetch_assoc() : null;
              $colName = $pcn ? $pcn['COLUMN_NAME'] : null;
              if ($colName) {
                $uStmt2 = $conn->prepare("SELECT id FROM user_accounts WHERE REGEXP_REPLACE($colName,'[^0-9]','')=? LIMIT 1");
                if ($uStmt2) {
                  $uStmt2->bind_param('s', $clientPhone);
                  $uStmt2->execute();
                  $uRes2 = $uStmt2->get_result();
                  if ($uRow2 = $uRes2->fetch_assoc()) $clientUserId = (int)$uRow2['id'];
                  $uStmt2->close();
                }
              }
            }
          }

          if ($clientUserId > 0) {
            // Fetch agent name
            $agentNameRow = $conn->query("SELECT CONCAT(first_name,' ',last_name) AS name FROM agent_accounts WHERE id=$agentId LIMIT 1");
            $agentName = ($agentNameRow && $r = $agentNameRow->fetch_assoc()) ? $r['name'] : 'Your agent';

            $clientName = trim(($vr['client_first_name'] ?? '') . ' ' . ($vr['client_last_name'] ?? ''));
            $lotRef = !empty($vr['lot_no']) ? ' for Lot ' . $vr['lot_no'] : '';
            $notifTitle = 'Viewing Cancelled';
            $notifMsg  = "Hi $clientName, your lot viewing$lotRef has been cancelled by $agentName.";
            if ($cancelReason !== '') {
              $notifMsg .= "\n\nReason: $cancelReason";
            }

            $conn->query("CREATE TABLE IF NOT EXISTS user_notifications (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              title VARCHAR(180) NOT NULL,
              message TEXT NOT NULL,
              type VARCHAR(30) DEFAULT 'info',
              is_read TINYINT(1) DEFAULT 0,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_user_notifications_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $nStmt = $conn->prepare("INSERT INTO user_notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'warning', 0, NOW())");
            if ($nStmt) {
              $nStmt->bind_param('iss', $clientUserId, $notifTitle, $notifMsg);
              $nStmt->execute();
              $nStmt->close();
            }
          }
        }
      }
    }
    header('Location: agent_dashboard.php#' . ($_POST['redirect_to'] ?? 'dashboard'));
    exit;
  }

  /* Delete a viewing record */
  if (isset($_POST['delete_viewing_id'])) {
    $vid = (int)$_POST['delete_viewing_id'];
    // Before deleting, check if we should revert the lot to Available
    $lotRow = $conn->query("SELECT lot_id, status FROM viewings WHERE id=$vid AND agent_id=$agentId LIMIT 1");
    if ($lotRow) {
      $lr = $lotRow->fetch_assoc();
      $lotId = (int)($lr['lot_id'] ?? 0);
      if ($lotId > 0) {
        $conn->query("UPDATE lots SET status='Available' WHERE id=$lotId AND status='Reserved'");
        $conn->query("UPDATE pin_locations SET pin_status='available' WHERE lot_id=$lotId AND LOWER(pin_status)='reserved'");
      }
    }
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

  if ($_GET['fetch'] === 'agent_sales_auto') {
    $salesRows = [];
    $seenSales = [];

    $hasSalesTable = false;
    $checkSales = $conn->query("SHOW TABLES LIKE 'sales'");
    if ($checkSales && $checkSales->num_rows > 0) {
      $hasSalesTable = true;
    }

    $hasPaymentsTable = false;
    $checkPayments = $conn->query("SHOW TABLES LIKE 'lot_payment_transactions'");
    if ($checkPayments && $checkPayments->num_rows > 0) {
      $hasPaymentsTable = true;
    }

    $paymentJoin = $hasPaymentsTable
      ? "LEFT JOIN (\n          SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid, MAX(payment_date) AS last_payment_date\n          FROM lot_payment_transactions\n          GROUP BY lot_id\n        ) tx ON tx.lot_id = l.id"
      : "LEFT JOIN (\n          SELECT NULL AS lot_id, 0 AS total_paid, NULL AS last_payment_date\n        ) tx ON tx.lot_id = l.id";

    $lotSql = "
      SELECT
        CONCAT(
          COALESCE(NULLIF(ll.location_name, ''), 'N/A'),
          ' - Block ', COALESCE(CAST(l.block_number AS CHAR), 'N/A'),
          ', Lot ', COALESCE(CAST(l.lot_number AS CHAR), 'N/A')
        ) AS property,
        COALESCE(
          NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
          NULLIF(TRIM(CONCAT(COALESCE(rv.client_first_name, ''), ' ', COALESCE(rv.client_last_name, ''))), ''),
          NULLIF(TRIM(rv.client_email), ''),
          'N/A'
        ) AS buyer,
        CASE
          WHEN (CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END) = 'Paid' THEN IFNULL(l.lot_price, 0)
          WHEN IFNULL(tx.total_paid, 0) > 0 THEN IFNULL(tx.total_paid, 0)
          ELSE IFNULL(l.payment_amount, IFNULL(l.lot_price, 0))
        END AS sale_price,
        DATE_FORMAT(COALESCE(tx.last_payment_date, l.payment_deadline, rv.preferred_at, CURDATE()), '%Y-%m-%d') AS sale_date,
        CASE
          WHEN (CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END) = 'Paid' THEN 'Closed'
          WHEN (CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END) = 'Installment' THEN 'Ongoing'
          WHEN (CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END) = 'Reserved' THEN 'Reserved'
          ELSE 'Recorded'
        END AS source
      FROM lots l
      LEFT JOIN user_accounts u ON u.id = l.owner_id
      LEFT JOIN lot_locations ll ON ll.id = l.location_id
      LEFT JOIN (
        SELECT v1.lot_id, v1.agent_id, v1.client_first_name, v1.client_last_name, v1.client_email, v1.preferred_at
        FROM viewings v1
        INNER JOIN (
          SELECT lot_id, MAX(id) AS latest_id
          FROM viewings
          WHERE lot_id IS NOT NULL
          GROUP BY lot_id
        ) lv ON lv.latest_id = v1.id
      ) rv ON rv.lot_id = l.id
      $paymentJoin
      WHERE (u.agent_id = ? OR rv.agent_id = ?)
        AND (
          (CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END) IN ('Paid', 'Installment', 'Reserved')
          OR l.payment_type IN ('Fully Paid', 'Down Payment')
          OR IFNULL(tx.total_paid, 0) > 0
        )
      ORDER BY COALESCE(tx.last_payment_date, l.payment_deadline, rv.preferred_at, CURDATE()) DESC
    ";

    if ($stmt = $conn->prepare($lotSql)) {
      $stmt->bind_param('ii', $agentId, $agentId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $entry = [
          'id' => null,
          'property' => $row['property'] ?? 'N/A',
          'buyer' => trim((string)($row['buyer'] ?? '')) !== '' ? $row['buyer'] : 'N/A',
          'sale_price' => (float)($row['sale_price'] ?? 0),
          'sale_date' => $row['sale_date'] ?? date('Y-m-d'),
          'source' => $row['source'] ?? 'Recorded',
        ];

        $dedupeKey = strtolower(trim(($entry['property'] ?? '') . '|' . ($entry['buyer'] ?? '') . '|' . ($entry['sale_date'] ?? '') . '|' . number_format((float)($entry['sale_price'] ?? 0), 2, '.', '')));
        if (!isset($seenSales[$dedupeKey])) {
          $seenSales[$dedupeKey] = true;
          $salesRows[] = $entry;
        }
      }
      $stmt->close();
    }

    if ($hasSalesTable && ($stmt = $conn->prepare("SELECT s.id, s.property, s.buyer, s.sale_price, s.sale_date
      FROM sales s
      LEFT JOIN user_accounts ue ON LOWER(TRIM(ue.email)) = LOWER(TRIM(s.buyer))
      LEFT JOIN user_accounts un ON LOWER(TRIM(CONCAT(COALESCE(un.first_name,''), ' ', COALESCE(un.last_name,'')))) = LOWER(TRIM(s.buyer))
      WHERE s.agent_id = ?
         OR (COALESCE(s.agent_id, 0) = 0 AND (ue.agent_id = ? OR un.agent_id = ?))
      ORDER BY s.sale_date DESC, s.id DESC"))) {
      $stmt->bind_param('iii', $agentId, $agentId, $agentId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $entry = [
          'id' => (int)($row['id'] ?? 0),
          'property' => $row['property'] ?? 'N/A',
          'buyer' => $row['buyer'] ?? 'N/A',
          'sale_price' => (float)($row['sale_price'] ?? 0),
          'sale_date' => $row['sale_date'] ?? date('Y-m-d'),
          'source' => 'Recorded',
        ];

        $dedupeKey = strtolower(trim(($entry['property'] ?? '') . '|' . ($entry['buyer'] ?? '') . '|' . ($entry['sale_date'] ?? '') . '|' . number_format((float)($entry['sale_price'] ?? 0), 2, '.', '')));
        if (!isset($seenSales[$dedupeKey])) {
          $seenSales[$dedupeKey] = true;
          $salesRows[] = $entry;
        }
      }
      $stmt->close();
    }

    usort($salesRows, static function ($a, $b) {
      return strcmp((string)($b['sale_date'] ?? ''), (string)($a['sale_date'] ?? ''));
    });

    header('Content-Type: application/json');
    echo json_encode($salesRows);
    exit;
  }
}

/* ---- KPIs ---- */
$kpis = [
  'closed_sales' => 0,
  'ongoing_sales' => 0,
  'upcoming_viewings' => 0,
  'unread_messages' => 0,
  'commission_lots' => 0,
  'commission_premium_lots' => 0,
  'commission_regular_lots' => 0,
  'commission_total' => 0,
  'avg_rating' => 0,
  'review_count' => 0,
  'is_available' => 1,
  'full_name' => 'Agent',
];

$commissionRegularRate = 5000;
$commissionPremiumRate = 10000;

$latestAgentReviews = [];
$commissionRecords = [];

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

$salesClassSql = "
  SELECT
    SUM(CASE
      WHEN l.owner_id IS NOT NULL AND (
        normalize_status.status = 'Paid'
        OR l.payment_type = 'Fully Paid'
        OR (IFNULL(tx.total_paid, 0) >= IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
      ) THEN 1 ELSE 0 END) AS closed_sales,
    SUM(CASE
      WHEN l.owner_id IS NOT NULL AND (
        normalize_status.status = 'Installment'
        OR l.payment_type = 'Down Payment'
        OR (
          IFNULL(tx.total_paid, 0) > 0
          AND IFNULL(l.lot_price, 0) > 0
          AND IFNULL(tx.total_paid, 0) < IFNULL(l.lot_price, 0)
        )
      ) THEN 1 ELSE 0 END) AS ongoing_sales
  FROM lots l
  LEFT JOIN (
    SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid
    FROM lot_payment_transactions
    GROUP BY lot_id
  ) tx ON tx.lot_id = l.id
  LEFT JOIN (
    SELECT id,
      CASE
        WHEN status = 'Installments' THEN 'Installment'
        WHEN status = 'Sold' THEN 'Paid'
        WHEN status = '' OR status IS NULL THEN 'Available'
        ELSE status
      END AS status
    FROM lots
  ) normalize_status ON normalize_status.id = l.id
";
$salesClassRes = $conn->query($salesClassSql);
if ($salesClassRes && ($salesClassRow = $salesClassRes->fetch_assoc())) {
  $kpis['closed_sales'] = (int)($salesClassRow['closed_sales'] ?? 0);
  $kpis['ongoing_sales'] = (int)($salesClassRow['ongoing_sales'] ?? 0);
}

$hasPaymentsTableForCommission = false;
$checkPaymentsForCommission = $conn->query("SHOW TABLES LIKE 'lot_payment_transactions'");
if ($checkPaymentsForCommission && $checkPaymentsForCommission->num_rows > 0) {
  $hasPaymentsTableForCommission = true;
}

if ($hasPaymentsTableForCommission) {
  $ownerPhoneExpr = "''";
  if (hasTableColumn($conn, 'user_accounts', 'phone_number')) {
    $ownerPhoneExpr = 'u.phone_number';
  } elseif (hasTableColumn($conn, 'user_accounts', 'mobile_number')) {
    $ownerPhoneExpr = 'u.mobile_number';
  } elseif (hasTableColumn($conn, 'user_accounts', 'mobile')) {
    $ownerPhoneExpr = 'u.mobile';
  }

  $hasLotAgentColumn = hasTableColumn($conn, 'lots', 'agent_id');
  $hasUserAgentColumn = hasTableColumn($conn, 'user_accounts', 'agent_id');
  $hasViewingsTable = $conn->query("SHOW TABLES LIKE 'viewings'");
  $hasViewingsAvailable = $hasViewingsTable && $hasViewingsTable->num_rows > 0;
  $hasViewingsAgentColumn = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'agent_id');
  $hasViewingsLotId = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'lot_id');
  $hasViewingsLotNo = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'lot_no');
  $hasLotsLotNumberColumn = hasTableColumn($conn, 'lots', 'lot_number');
  $hasViewingStatusColumn = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'status');
  $hasViewingAppointmentTypeColumn = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'appointment_type');
  $hasViewingsClientFirstName = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'client_first_name');
  $hasViewingsClientLastName = $hasViewingsAvailable && hasTableColumn($conn, 'viewings', 'client_last_name');

  $viewingAgentJoinByLotId = ($hasViewingsAgentColumn && $hasViewingsLotId)
    ? "LEFT JOIN (\n      SELECT v1.lot_id, v1.agent_id\n      FROM viewings v1\n      INNER JOIN (\n        SELECT lot_id, MAX(id) AS latest_id\n        FROM viewings\n        WHERE lot_id IS NOT NULL AND lot_id > 0 AND agent_id IS NOT NULL AND agent_id > 0\n        GROUP BY lot_id\n      ) lv ON lv.latest_id = v1.id\n    ) rv ON rv.lot_id = l.id"
    : "LEFT JOIN (SELECT NULL AS lot_id, 0 AS agent_id) rv ON rv.lot_id = l.id";

  $viewingAgentJoinByLotNo = ($hasViewingsAgentColumn && $hasViewingsLotNo && $hasLotsLotNumberColumn)
    ? "LEFT JOIN (\n      SELECT LOWER(TRIM(v2.lot_no)) AS lot_no_key, v2.agent_id\n      FROM viewings v2\n      INNER JOIN (\n        SELECT LOWER(TRIM(lot_no)) AS lot_no_key, MAX(id) AS latest_id\n        FROM viewings\n        WHERE lot_no IS NOT NULL AND TRIM(lot_no) <> '' AND agent_id IS NOT NULL AND agent_id > 0\n        GROUP BY LOWER(TRIM(lot_no))\n      ) lv2 ON lv2.latest_id = v2.id\n    ) rvn ON (\n      rvn.lot_no_key = LOWER(TRIM(CAST(l.lot_number AS CHAR)))\n      OR rvn.lot_no_key LIKE CONCAT('%lot ', LOWER(TRIM(CAST(l.lot_number AS CHAR))), '%')\n    )"
    : "LEFT JOIN (SELECT NULL AS lot_no_key, 0 AS agent_id) rvn ON rvn.lot_no_key = LOWER(TRIM(CAST(l.lot_number AS CHAR)))";

  $viewingAgentJoin = $viewingAgentJoinByLotId . "\n      " . $viewingAgentJoinByLotNo;

  $ownerNameJoinByLotId = ($hasViewingsLotId && $hasViewingsClientFirstName && $hasViewingsClientLastName)
    ? "LEFT JOIN (\n      SELECT v3.lot_id, TRIM(CONCAT(COALESCE(v3.client_first_name, ''), ' ', COALESCE(v3.client_last_name, ''))) AS client_name\n      FROM viewings v3\n      INNER JOIN (\n        SELECT lot_id, MAX(id) AS latest_id\n        FROM viewings\n        WHERE lot_id IS NOT NULL AND lot_id > 0\n        GROUP BY lot_id\n      ) lv3 ON lv3.latest_id = v3.id\n    ) rvo ON rvo.lot_id = l.id"
    : "LEFT JOIN (SELECT NULL AS lot_id, '' AS client_name) rvo ON rvo.lot_id = l.id";

  $ownerNameJoinByLotNo = ($hasViewingsLotNo && $hasLotsLotNumberColumn && $hasViewingsClientFirstName && $hasViewingsClientLastName)
    ? "LEFT JOIN (\n      SELECT LOWER(TRIM(v4.lot_no)) AS lot_no_key, TRIM(CONCAT(COALESCE(v4.client_first_name, ''), ' ', COALESCE(v4.client_last_name, ''))) AS client_name\n      FROM viewings v4\n      INNER JOIN (\n        SELECT LOWER(TRIM(lot_no)) AS lot_no_key, MAX(id) AS latest_id\n        FROM viewings\n        WHERE lot_no IS NOT NULL AND TRIM(lot_no) <> ''\n        GROUP BY LOWER(TRIM(lot_no))\n      ) lv4 ON lv4.latest_id = v4.id\n    ) rvon ON (\n      rvon.lot_no_key = LOWER(TRIM(CAST(l.lot_number AS CHAR)))\n      OR rvon.lot_no_key LIKE CONCAT('%lot ', LOWER(TRIM(CAST(l.lot_number AS CHAR))), '%')\n    )"
    : "LEFT JOIN (SELECT NULL AS lot_no_key, '' AS client_name) rvon ON rvon.lot_no_key = LOWER(TRIM(CAST(l.lot_number AS CHAR)))";

  $ownerNameJoin = $ownerNameJoinByLotId . "\n      " . $ownerNameJoinByLotNo;

  $assignmentChecks = [];
  if ($hasLotAgentColumn) {
    $assignmentChecks[] = 'l.agent_id = ?';
  }
  if ($hasUserAgentColumn) {
    $assignmentChecks[] = 'u.agent_id = ?';
  }
  $assignmentChecks[] = 'rv.agent_id = ?';
  $assignmentChecks[] = 'rvn.agent_id = ?';
  $assignmentWhere = implode(' OR ', $assignmentChecks);

  $paramTypes = str_repeat('i', count($assignmentChecks));
  $paramValues = array_fill(0, count($assignmentChecks), $agentId);

  $approvedReservationClause = '0 = 1';
  if ($hasViewingStatusColumn) {
    $approvedByLotIdClause = ($hasViewingsLotId && $hasViewingsAgentColumn)
      ? "EXISTS (
          SELECT 1
          FROM viewings v_ap
          WHERE v_ap.lot_id = l.id
            AND v_ap.agent_id = ?
            AND LOWER(TRIM(COALESCE(v_ap.status, ''))) IN ('approved', 'scheduled')"
      : '0 = 1';

    if ($hasViewingsLotId && $hasViewingsAgentColumn) {
      if ($hasViewingAppointmentTypeColumn) {
        $approvedByLotIdClause .= "\n            AND LOWER(TRIM(COALESCE(v_ap.appointment_type, ''))) = 'reservation'";
      }
      $approvedByLotIdClause .= "\n          LIMIT 1\n        )";
    }

    $approvedByLotNoClause = ($hasViewingsLotNo && $hasViewingsAgentColumn && $hasLotsLotNumberColumn)
      ? "EXISTS (
          SELECT 1
          FROM viewings v_apn
          WHERE v_apn.agent_id = ?
            AND (
              LOWER(TRIM(COALESCE(v_apn.lot_no, ''))) = LOWER(TRIM(CAST(l.lot_number AS CHAR)))
              OR LOWER(TRIM(COALESCE(v_apn.lot_no, ''))) LIKE CONCAT('%lot ', LOWER(TRIM(CAST(l.lot_number AS CHAR))), '%')
            )
            AND LOWER(TRIM(COALESCE(v_apn.status, ''))) IN ('approved', 'scheduled')"
      : '0 = 1';

    if ($hasViewingsLotNo && $hasViewingsAgentColumn && $hasLotsLotNumberColumn) {
      if ($hasViewingAppointmentTypeColumn) {
        $approvedByLotNoClause .= "\n            AND LOWER(TRIM(COALESCE(v_apn.appointment_type, ''))) = 'reservation'";
      }
      $approvedByLotNoClause .= "\n          LIMIT 1\n        )";
    }

    $approvedReservationClause = "($approvedByLotIdClause OR $approvedByLotNoClause)";

    if ($hasViewingsLotId && $hasViewingsAgentColumn) {
      $paramTypes .= 'i';
      $paramValues[] = $agentId;
    }
    if ($hasViewingsLotNo && $hasViewingsAgentColumn && $hasLotsLotNumberColumn) {
      $paramTypes .= 'i';
      $paramValues[] = $agentId;
    }
  }

  $commissionSql = "
    SELECT
      q.id,
      q.property,
      q.location_name,
      q.owner_name,
      q.lot_status,
      q.payment_type,
      q.sale_amount,
      q.is_premium,
      q.commission_amount,
      q.collection_status,
      q.last_payment_date,
      q.total_paid,
      q.monthly_amount,
      q.lot_price,
      q.payment_deadline,
      q.payment_due_day,
      q.owner_email,
      q.owner_phone
    FROM (
      SELECT DISTINCT
        l.id,
        CONCAT(
          COALESCE(NULLIF(ll.location_name, ''), 'N/A'),
          ' - Block ', COALESCE(CAST(l.block_number AS CHAR), 'N/A'),
          ', Lot ', COALESCE(CAST(l.lot_number AS CHAR), 'N/A')
        ) AS property,
        COALESCE(NULLIF(TRIM(ll.location_name), ''), 'N/A') AS location_name,
        COALESCE(
          NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
          NULLIF(TRIM(COALESCE(rvo.client_name, '')), ''),
          NULLIF(TRIM(COALESCE(rvon.client_name, '')), ''),
          'Unassigned'
        ) AS owner_name,
        COALESCE(NULLIF(TRIM(u.email), ''), '') AS owner_email,
        COALESCE(NULLIF(TRIM($ownerPhoneExpr), ''), '') AS owner_phone,
        CASE
          WHEN CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END = 'Paid' THEN 'Fully Paid'
          WHEN CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END = 'Installment' THEN 'Down Payment'
          WHEN CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END = 'Reserved' THEN 'Reserved'
          WHEN CASE WHEN l.status = 'Installments' THEN 'Installment' WHEN l.status = 'Sold' THEN 'Paid' WHEN l.status = '' OR l.status IS NULL THEN 'Available' ELSE l.status END = 'Cancelled' THEN 'Cancelled'
          ELSE COALESCE(NULLIF(l.payment_type, ''), 'Recorded')
        END AS lot_status,
        COALESCE(NULLIF(l.payment_type, ''), 'Recorded') AS payment_type,
        CASE
          WHEN IFNULL(tx.total_paid, 0) > 0 THEN IFNULL(tx.total_paid, 0)
          WHEN IFNULL(l.payment_amount, 0) > 0 THEN IFNULL(l.payment_amount, 0)
          ELSE IFNULL(l.lot_price, 0)
        END AS sale_amount,
        CASE
          WHEN LOWER(TRIM(COALESCE(ll.location_name, ''))) IN ('barangay pasonanca', 'pasonanca', 'barangay mercedes', 'mercedes')
            OR LOWER(COALESCE(ll.location_name, '')) LIKE '%pasonanca%'
            OR LOWER(COALESCE(ll.location_name, '')) LIKE '%mercedes%'
          THEN 1 ELSE 0
        END AS is_premium,
        CASE
          WHEN IFNULL(l.commission_amount, 0) > 0 THEN IFNULL(l.commission_amount, 0)
          WHEN LOWER(TRIM(COALESCE(ll.location_name, ''))) IN ('barangay pasonanca', 'pasonanca', 'barangay mercedes', 'mercedes')
            OR LOWER(COALESCE(ll.location_name, '')) LIKE '%pasonanca%'
            OR LOWER(COALESCE(ll.location_name, '')) LIKE '%mercedes%'
          THEN $commissionPremiumRate
          ELSE $commissionRegularRate
        END AS commission_amount,
        CASE
          WHEN IFNULL(tx.total_paid, 0) > 0 THEN 'Collection recorded'
          WHEN COALESCE(NULLIF(l.payment_type, ''), '') = 'Down Payment' AND IFNULL(l.down_payment_amount, 0) > 0 THEN 'Approved reservation with down payment'
          WHEN COALESCE(NULLIF(l.payment_type, ''), '') = 'Down Payment' THEN 'Down payment'
          WHEN COALESCE(NULLIF(l.payment_type, ''), '') = 'Fully Paid' THEN 'Fully paid'
          ELSE 'Recorded'
        END AS collection_status,
        tx.last_payment_date,
        IFNULL(tx.total_paid, 0) AS total_paid,
        IFNULL(l.payment_amount, 0) AS monthly_amount,
        IFNULL(l.lot_price, 0) AS lot_price,
        l.payment_deadline,
        IFNULL(l.payment_due_day, 0) AS payment_due_day
      FROM lots l
      LEFT JOIN user_accounts u ON u.id = l.owner_id
      LEFT JOIN lot_locations ll ON ll.id = l.location_id
      LEFT JOIN (
        SELECT lot_id, IFNULL(SUM(amount), 0) AS total_paid, MAX(payment_date) AS last_payment_date
        FROM lot_payment_transactions
        GROUP BY lot_id
      ) tx ON tx.lot_id = l.id
      $viewingAgentJoin
      $ownerNameJoin
      WHERE ($assignmentWhere)
        AND (
          CASE
            WHEN l.status = 'Installments' THEN 'Installment'
            WHEN l.status = 'Sold' THEN 'Paid'
            WHEN l.status = '' OR l.status IS NULL THEN 'Available'
            ELSE l.status
          END = 'Paid'
          OR COALESCE(NULLIF(l.payment_type, ''), '') = 'Fully Paid'
          OR (IFNULL(tx.total_paid, 0) >= IFNULL(l.lot_price, 0) AND IFNULL(l.lot_price, 0) > 0)
          OR (
            COALESCE(NULLIF(l.payment_type, ''), '') = 'Down Payment'
            AND (IFNULL(l.down_payment_amount, 0) > 0 OR IFNULL(tx.total_paid, 0) > 0)
            AND $approvedReservationClause
          )
        )
    ) q
    ORDER BY (q.last_payment_date IS NULL) ASC, q.last_payment_date DESC, q.property ASC
  ";

  if ($stmt = $conn->prepare($commissionSql)) {
    $stmt->bind_param($paramTypes, ...$paramValues);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      // no-op here because the query is used below for record rows
    }
    $stmt->close();
  }

  if ($stmt = $conn->prepare($commissionSql)) {
    $stmt->bind_param($paramTypes, ...$paramValues);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $saleAmount = (float)($row['sale_amount'] ?? 0);
      $commissionAmount = (float)($row['commission_amount'] ?? 0);

      $commissionRecords[] = [
        'property' => (string)($row['property'] ?? 'N/A'),
        'location_name' => (string)($row['location_name'] ?? 'N/A'),
        'owner_name' => (string)($row['owner_name'] ?? 'Unassigned'),
        'owner_email' => (string)($row['owner_email'] ?? ''),
        'owner_phone' => (string)($row['owner_phone'] ?? ''),
        'lot_status' => (string)($row['lot_status'] ?? 'Recorded'),
        'payment_type' => (string)($row['payment_type'] ?? 'Recorded'),
        'sale_amount' => $saleAmount,
        'commission_amount' => $commissionAmount,
        'commission_rate' => (int)($row['is_premium'] ?? 0) === 1 ? $commissionPremiumRate : $commissionRegularRate,
        'last_payment_date' => !empty($row['last_payment_date']) ? $row['last_payment_date'] : null,
        'total_paid' => (float)($row['total_paid'] ?? 0),
        'monthly_amount' => (float)($row['monthly_amount'] ?? 0),
        'lot_price' => (float)($row['lot_price'] ?? 0),
        'payment_deadline' => (string)($row['payment_deadline'] ?? ''),
        'payment_due_day' => (int)($row['payment_due_day'] ?? 0),
      ];
    }
    $stmt->close();
  }

  $kpis['commission_premium_lots'] = 0;
  $kpis['commission_regular_lots'] = 0;
  $kpis['commission_lots'] = count($commissionRecords);
  $kpis['commission_total'] = 0;

  foreach ($commissionRecords as $record) {
    $kpis['commission_total'] += (float)($record['commission_amount'] ?? 0);
    if ((int)($record['commission_rate'] ?? 0) === $commissionPremiumRate) {
      $kpis['commission_premium_lots']++;
    } else {
      $kpis['commission_regular_lots']++;
    }
  }
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

if ($stmt = $conn->prepare("SELECT IFNULL(AVG(rating), 0) AS avg_rating, COUNT(*) AS review_count FROM agent_reviews WHERE agent_id = ?")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($row = $r->fetch_assoc()) {
    $kpis['avg_rating'] = (float)($row['avg_rating'] ?? 0);
    $kpis['review_count'] = (int)($row['review_count'] ?? 0);
  }
  $stmt->close();
}

if ($stmt = $conn->prepare("SELECT ar.rating, ar.review_text, ar.updated_at, ua.first_name, ua.last_name
                            FROM agent_reviews ar
                            LEFT JOIN user_accounts ua ON ua.id = ar.user_id
                            WHERE ar.agent_id = ?
                            ORDER BY ar.updated_at DESC
                            LIMIT 5")) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $r = $stmt->get_result();
  if ($r) $latestAgentReviews = $r->fetch_all(MYSQLI_ASSOC);
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

/* ---- Build client account lookup index (email/phone) ---- */
$clientAccountByEmail = [];
$clientAccountByPhone = [];

$userPhoneColumn = hasTableColumn($conn, 'user_accounts', 'phone_number')
  ? 'phone_number'
  : (hasTableColumn($conn, 'user_accounts', 'mobile_number') ? 'mobile_number' : null);

if ($userPhoneColumn !== null) {
  $sql = "SELECT id, first_name, middle_name, last_name, username, email, $userPhoneColumn AS phone_number, created_at FROM user_accounts ORDER BY id DESC";
} else {
  $sql = "SELECT id, first_name, middle_name, last_name, username, email, '' AS phone_number, created_at FROM user_accounts ORDER BY id DESC";
}

$userAccountResult = $conn->query($sql);
if ($userAccountResult) {
  while ($u = $userAccountResult->fetch_assoc()) {
    $emailKey = normalizeEmailKey((string)($u['email'] ?? ''));
    if ($emailKey !== '' && !isset($clientAccountByEmail[$emailKey])) {
      $clientAccountByEmail[$emailKey] = $u;
    }

    $phoneKey = normalizePhoneKey((string)($u['phone_number'] ?? ''));
    if ($phoneKey !== '' && !isset($clientAccountByPhone[$phoneKey])) {
      $clientAccountByPhone[$phoneKey] = $u;
    }
  }
}

$attachClientMatch = static function(array $viewingRow) use ($clientAccountByEmail, $clientAccountByPhone): array {
  $emailKey = normalizeEmailKey((string)($viewingRow['client_email'] ?? ''));
  $phoneKey = normalizePhoneKey((string)($viewingRow['client_phone'] ?? ''));

  $matched = null;
  if ($emailKey !== '' && isset($clientAccountByEmail[$emailKey])) {
    $matched = $clientAccountByEmail[$emailKey];
  } elseif ($phoneKey !== '' && isset($clientAccountByPhone[$phoneKey])) {
    $matched = $clientAccountByPhone[$phoneKey];
  }

  $viewingRow['is_existing_client'] = $matched ? 1 : 0;
  $viewingRow['matched_user_id'] = $matched ? (int)($matched['id'] ?? 0) : 0;
  $viewingRow['matched_username'] = $matched['username'] ?? '';
  return $viewingRow;
};

if (!empty($viewings)) {
  $viewings = array_map($attachClientMatch, $viewings);
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

if (!empty($all_viewings)) {
  $all_viewings = array_map($attachClientMatch, $all_viewings);
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

// Handle agent time slot update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_time_slot'])) {
  csrf_check();
  $slot_id = (int)($_POST['slot_id'] ?? 0);
  $avail_date = $_POST['avail_date'] ?? '';
  $time_slot = $_POST['time_slot'] ?? '';
  $max_clients = (int)($_POST['max_clients'] ?? 1);
  $errors = [];

  if ($slot_id < 1 || !$avail_date || !$time_slot || $max_clients < 1) {
    $errors[] = 'A valid date, time slot, and max clients value are required.';
  }

  if (empty($errors)) {
    $stmt = $conn->prepare("UPDATE agent_time_slots SET available_date=?, time_slot=?, max_clients=? WHERE id=? AND agent_id=?");
    if ($stmt === false) {
      $slot_error = 'Prepare failed: ' . $conn->error;
    } else {
      $stmt->bind_param('ssiii', $avail_date, $time_slot, $max_clients, $slot_id, $agentId);
      if (!$stmt->execute()) {
        $slot_error = 'Execute failed: ' . $stmt->error;
      } elseif ($stmt->affected_rows >= 0) {
        $slot_success = 'Time slot updated successfully!';
      }
      $stmt->close();
    }
  } else {
    $slot_error = implode(' ', $errors);
  }
}

// Handle agent time slot delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_time_slot'])) {
  csrf_check();
  $slot_id = (int)($_POST['slot_id'] ?? 0);

  if ($slot_id < 1) {
    $slot_error = 'Invalid time slot selected.';
  } else {
    $stmt = $conn->prepare("DELETE FROM agent_time_slots WHERE id=? AND agent_id=?");
    if ($stmt === false) {
      $slot_error = 'Prepare failed: ' . $conn->error;
    } else {
      $stmt->bind_param('ii', $slot_id, $agentId);
      if (!$stmt->execute()) {
        $slot_error = 'Execute failed: ' . $stmt->error;
      } elseif ($stmt->affected_rows > 0) {
        $slot_success = 'Time slot deleted successfully!';
      } else {
        $slot_error = 'Time slot not found.';
      }
      $stmt->close();
    }
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
  <aside class="bg-green-900 text-white flex flex-col items-center py-4 sidebar" style="width:280px; height: 100vh; position: fixed; left: 0; top: 0; bottom: 0; z-index: 10; overflow-y: auto; overflow-x: hidden;">
    <div class="flex items-center gap-3 mb-4">
      <img src="logo.png" alt="Logo" class="w-16 h-16 rounded-full bg-white/10 object-contain" />
      <div>
        <h2 class="font-bold text-lg tracking-wide whitespace-nowrap leading-tight">NUEVO PUERTA</h2>
        <span class="text-xs font-normal text-white/90 leading-none block mt-0.5">REAL ESTATE</span>
      </div>
    </div>

    <div class="bg-white/10 rounded-xl px-4 py-2 mb-4 w-56 mx-auto flex items-center">
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

    <nav class="w-full flex flex-col h-full" style="min-height:0; flex:1 1 0%;">
      <ul class="space-y-0.5 w-full flex-1" id="spa-nav" style="min-height:0; overflow:visible;">
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
            <?php if ($agentUnreadNotifications > 0): ?>
              <span id="agent-notifications-badge" style="margin-left:10px; min-width:20px; height:20px; padding:0 6px; border-radius:999px; background:#ef4444; color:#fff; font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; line-height:1;">
                <?php echo $agentUnreadNotifications > 99 ? '99+' : (int)$agentUnreadNotifications; ?>
              </span>
            <?php endif; ?>
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
      </ul>
      <ul class="w-full" style="margin-top:4px; margin-bottom:8px;">
        <li style="margin-top:8px;">
          <a href="#" onclick="confirmLogout()" class="flex items-center px-8 py-3 rounded transition hover:bg-green-800 logout-link" style="margin-top:0;">
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

      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-5 mt-6">
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M3 13h6v8H3zM9 3h6v18H9zM15 9h6v12h-6z"/></svg>
            Sales (Closed)
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['closed_sales']); ?></div>
          <div class="text-xs text-gray-500 mt-1">Fully paid lots only</div>
        </div>
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" font-size="10" fill="#fff">M</text></svg>
            Sales (Ongoing)
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format($kpis['ongoing_sales']); ?></div>
          <div class="text-xs text-gray-500 mt-1">Installment / down payment lots</div>
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
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21 16.54 13.97 22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
            Client Rating
          </div>
          <div class="text-4xl font-extrabold mt-2"><?php echo number_format((float)$kpis['avg_rating'], 1); ?></div>
          <div class="text-xs text-gray-500 mt-1"><?php echo (int)$kpis['review_count']; ?> review<?php echo ((int)$kpis['review_count'] === 1 ? '' : 's'); ?></div>
        </div>
        <div class="bg-white rounded-2xl border shadow p-5">
          <div class="flex items-center gap-3 text-green-900 font-semibold">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1l3 6 6 .9-4.5 4.2 1.1 6-5.6-3-5.6 3 1.1-6L3 7.9 9 7l3-6zm0 11a2 2 0 100 4 2 2 0 000-4z"/></svg>
            Agent Commission
          </div>
          <div class="text-3xl font-extrabold mt-2">PHP <?php echo number_format((float)$kpis['commission_total'], 2); ?></div>
          <div class="text-xs text-gray-500 mt-1"><?php echo (int)$kpis['commission_premium_lots']; ?> lot<?php echo ((int)$kpis['commission_premium_lots'] === 1 ? '' : 's'); ?> (Pasonanca/Mercedes) x PHP <?php echo number_format((float)$commissionPremiumRate, 0); ?></div>
          <div class="text-xs text-gray-500 mt-1"><?php echo (int)$kpis['commission_regular_lots']; ?> other lot<?php echo ((int)$kpis['commission_regular_lots'] === 1 ? '' : 's'); ?> x PHP <?php echo number_format((float)$commissionRegularRate, 0); ?></div>
        </div>
      </section>

      <section class="mt-6">
        <div class="bg-white rounded-2xl border shadow p-6">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
              <h3 class="text-xl font-bold text-gray-900">Collection Records</h3>
              <p class="text-sm text-gray-500">Line-by-line records of lots with recorded payments, status, amount, and commission.</p>
            </div>
            <div class="text-sm text-gray-600">
              <?php echo count($commissionRecords); ?> record<?php echo count($commissionRecords) === 1 ? '' : 's'; ?>
            </div>
          </div>

          <?php if (empty($commissionRecords)): ?>
            <div class="text-sm text-gray-500">No collection records found yet.</div>
          <?php else: ?>
            <div class="overflow-x-auto" style="max-height:420px; overflow-y:auto;">
              <table class="min-w-full border rounded-lg text-sm">
                <thead>
                  <tr class="bg-gray-50 text-gray-700">
                    <th class="py-3 px-4 text-left">Property</th>
                    <th class="py-3 px-4 text-left">Location</th>
                    <th class="py-3 px-4 text-left">Property Owner</th>
                    <th class="py-3 px-4 text-left">Status</th>
                    <th class="py-3 px-4 text-left">Collection Health</th>
                    <th class="py-3 px-4 text-left">Outstanding</th>
                    <th class="py-3 px-4 text-left">Sale Amount</th>
                    <th class="py-3 px-4 text-left">Commission</th>
                    <th class="py-3 px-4 text-left">Last Payment</th>
                    <th class="py-3 px-4 text-left">Follow-up Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($commissionRecords as $record): ?>
                    <?php
                      $statusLabel = (string)($record['lot_status'] ?? 'Recorded');
                      $statusClass = 'bg-gray-100 text-gray-700';
                      if (stripos($statusLabel, 'Fully Paid') !== false) {
                        $statusClass = 'bg-green-100 text-green-800';
                      } elseif (stripos($statusLabel, 'Down Payment') !== false) {
                        $statusClass = 'bg-yellow-100 text-yellow-800';
                      } elseif (stripos($statusLabel, 'Reserved') !== false) {
                        $statusClass = 'bg-blue-100 text-blue-800';
                      } elseif (stripos($statusLabel, 'Cancelled') !== false) {
                        $statusClass = 'bg-red-100 text-red-800';
                      }

                      $healthInfo = computeCollectionFollowup($record);
                      $healthLabel = (string)($healthInfo['health_label'] ?? 'For follow-up');
                      $healthClass = (string)($healthInfo['health_class'] ?? 'bg-yellow-100 text-yellow-800');
                      $outstandingAmount = (float)($healthInfo['outstanding_amount'] ?? 0);
                      $needsFollowup = !empty($healthInfo['needs_followup']);
                      $ownerEmail = trim((string)($record['owner_email'] ?? ''));
                      $ownerPhone = trim((string)($record['owner_phone'] ?? ''));
                      $ownerPhoneDigits = preg_replace('/\D+/', '', $ownerPhone);
                    ?>
                    <tr class="border-t">
                      <td class="py-3 px-4 text-gray-800"><?php echo h((string)$record['property']); ?></td>
                      <td class="py-3 px-4 text-gray-700"><?php echo h((string)$record['location_name']); ?></td>
                      <td class="py-3 px-4 text-gray-700"><?php echo h((string)($record['owner_name'] ?? 'Unassigned')); ?></td>
                      <td class="py-3 px-4"><span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo $statusClass; ?>"><?php echo h($statusLabel); ?></span></td>
                      <td class="py-3 px-4"><span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo h($healthClass); ?>"><?php echo h($healthLabel); ?></span></td>
                      <td class="py-3 px-4 text-gray-800"><?php echo $outstandingAmount > 0 ? ('PHP ' . number_format($outstandingAmount, 2)) : 'PHP 0.00'; ?></td>
                      <td class="py-3 px-4 text-gray-800">PHP <?php echo number_format((float)$record['sale_amount'], 2); ?></td>
                      <td class="py-3 px-4 text-green-800 font-semibold">PHP <?php echo number_format((float)$record['commission_amount'], 2); ?></td>
                      <td class="py-3 px-4 text-gray-600"><?php echo !empty($record['last_payment_date']) ? h(date('M d, Y', strtotime((string)$record['last_payment_date']))) : 'N/A'; ?></td>
                      <td class="py-3 px-4 text-gray-700">
                        <div class="flex flex-col gap-1">
                          <?php if ($needsFollowup): ?>
                            <span class="text-xs font-semibold text-red-700">Needs follow-up</span>
                          <?php else: ?>
                            <span class="text-xs font-semibold text-emerald-700">On track</span>
                          <?php endif; ?>
                          <div class="flex flex-wrap gap-2">
                            <?php if ($ownerEmail !== ''): ?>
                              <a href="mailto:<?php echo h($ownerEmail); ?>" class="inline-flex items-center px-2 py-1 rounded text-xs bg-blue-100 text-blue-800 hover:bg-blue-200">Email</a>
                            <?php endif; ?>
                            <?php if ($ownerPhoneDigits !== ''): ?>
                              <a href="tel:<?php echo h($ownerPhoneDigits); ?>" class="inline-flex items-center px-2 py-1 rounded text-xs bg-emerald-100 text-emerald-800 hover:bg-emerald-200">Call</a>
                            <?php endif; ?>
                            <?php if ($ownerEmail === '' && $ownerPhoneDigits === ''): ?>
                              <span class="text-xs text-gray-500">No contact info</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="mt-6">
        <div class="bg-white rounded-2xl border shadow p-6">
          <div class="font-semibold text-gray-800 mb-4">Latest Client Reviews</div>
          <?php if (empty($latestAgentReviews)): ?>
            <div class="text-sm text-gray-500">No reviews yet.</div>
          <?php else: ?>
            <div class="space-y-3" style="max-height:360px; overflow-y:auto; padding-right:6px;">
              <?php foreach ($latestAgentReviews as $rv): ?>
                <?php
                  $reviewer = trim((string)($rv['first_name'] ?? '') . ' ' . (string)($rv['last_name'] ?? ''));
                  if ($reviewer === '') $reviewer = 'Client';
                  $rating = max(1, min(5, (int)($rv['rating'] ?? 0)));
                ?>
                <div class="border rounded-xl p-4 bg-gray-50">
                  <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-gray-900"><?php echo h($reviewer); ?></div>
                    <div class="text-sm text-amber-600"><?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?></div>
                  </div>
                  <div class="text-sm text-gray-700 mt-2"><?php echo h((string)($rv['review_text'] ?? 'No written review.')); ?></div>
                  <div class="text-xs text-gray-500 mt-2"><?php echo !empty($rv['updated_at']) ? h(date('M d, Y h:i A', strtotime((string)$rv['updated_at']))) : ''; ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="mt-8">
        <div class="bg-white rounded-2xl border shadow p-6 mb-8">
          <div class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
            <span style="display:inline-flex;align-items:center;margin-right:6px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="12" rx="9" ry="6" fill="#2e7d32"/><circle cx="12" cy="12" r="2.5" fill="#fff"/></svg>
            </span> Set Your Availability
          </div>
          <div style="max-height:520px; overflow-y:auto; padding-right:6px;">
          <?php if (!empty($availability_success)): ?>
            <div class="mb-3 p-2 rounded bg-green-100 text-green-800 text-sm">Availability saved!</div>
          <?php elseif (!empty($availability_error)): ?>
            <div class="mb-3 p-2 rounded bg-red-100 text-red-800 text-sm"><?php echo h($availability_error); ?></div>
          <?php endif; ?>
          <?php if (!empty($slot_success) || !empty($slot_error)): ?>
            <script>
              document.addEventListener('DOMContentLoaded', function() {
                var msg = <?php echo json_encode(!empty($slot_success) ? (is_string($slot_success) ? $slot_success : 'Time slot added successfully!') : $slot_error, JSON_UNESCAPED_UNICODE); ?>;
                var title = <?php echo json_encode(!empty($slot_success) ? 'Success' : 'Unable to Save', JSON_UNESCAPED_UNICODE); ?>;
                if (typeof showAlertModal === 'function') {
                  showAlertModal(msg, title);
                } else {
                  alert(msg);
                }
              });
            </script>
          <?php endif; ?>
          <script>
            function confirmSlotDelete(event, form) {
              if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
              }
              if (!form) return false;

              if (typeof showConfirmModal === 'function') {
                showConfirmModal('Delete this time slot?', 'Confirm Action', 'Delete', 'Cancel')
                  .then(function(ok) {
                    if (ok) form.submit();
                  });
                return false;
              }

              return confirm('Delete this time slot?');
            }
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
                      <th class="py-2 px-4 text-left border">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($agent_time_slots as $slot): ?>
                      <tr class="border-t">
                        <td class="py-2 px-4 border"><?php echo h(date('M d, Y', strtotime($slot['available_date']))); ?></td>
                        <td class="py-2 px-4 border"><?php echo h(date('h:i A', strtotime($slot['time_slot']))); ?></td>
                        <td class="py-2 px-4 border"><?php echo h((string)$slot['max_clients']); ?></td>
                        <td class="py-2 px-4 border">
                          <div class="flex flex-wrap gap-2">
                            <button type="button" class="bg-blue-600 text-white px-4 py-2 rounded font-semibold hover:bg-blue-700" onclick="toggleSlotEdit(<?php echo (int)$slot['id']; ?>, true)">Edit</button>
                            <form method="post" class="inline-block m-0">
                              <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                              <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
                              <input type="hidden" name="delete_time_slot" value="1">
                              <button type="submit" class="px-4 py-2 rounded font-semibold" style="background:#dc3545 !important; color:#fff !important; border:1px solid #dc3545 !important;" onclick="return confirmSlotDelete(event, this.form);">Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                      <tr id="slot-edit-row-<?php echo (int)$slot['id']; ?>" class="border-t bg-gray-50 hidden">
                        <td colspan="4" class="py-3 px-4 border">
                          <form method="post" class="flex flex-wrap gap-3 items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
                            <div>
                              <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                              <input type="date" name="avail_date" class="border rounded px-3 py-2" value="<?php echo h($slot['available_date']); ?>" required>
                            </div>
                            <div>
                              <label class="block text-sm font-medium text-gray-700 mb-1">Time Slot</label>
                              <input type="time" name="time_slot" class="border rounded px-3 py-2" value="<?php echo h(date('H:i', strtotime($slot['time_slot']))); ?>" required>
                            </div>
                            <div>
                              <label class="block text-sm font-medium text-gray-700 mb-1">Max Clients</label>
                              <input type="number" name="max_clients" class="border rounded px-3 py-2 w-28" min="1" value="<?php echo h((string)$slot['max_clients']); ?>" required>
                            </div>
                            <div class="flex gap-2">
                              <button type="submit" name="update_time_slot" value="1" class="bg-green-700 text-white px-4 py-2 rounded font-semibold hover:bg-green-800">Update</button>
                              <button type="button" class="bg-gray-500 text-white px-4 py-2 rounded font-semibold hover:bg-gray-600" onclick="toggleSlotEdit(<?php echo (int)$slot['id']; ?>, false)">Cancel</button>
                            </div>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
          </div>
        </div>
        <script>
          function toggleSlotEdit(slotId, shouldOpen) {
            document.querySelectorAll('[id^="slot-edit-row-"]').forEach(function(row) {
              if (!shouldOpen || row.id !== 'slot-edit-row-' + slotId) {
                row.classList.add('hidden');
              }
            });

            var targetRow = document.getElementById('slot-edit-row-' + slotId);
            if (!targetRow) return;

            if (shouldOpen) {
              targetRow.classList.remove('hidden');
            } else {
              targetRow.classList.add('hidden');
            }
          }
        </script>
        
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
            <div class="space-y-3" style="max-height:420px; overflow-y:auto; padding-right:6px;">
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
                    <?php if (!empty($v['is_existing_client'])): ?>
                      <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200" title="Matched registered account<?php echo !empty($v['matched_username']) ? ': ' . h($v['matched_username']) : ''; ?>">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Existing Client
                      </span>
                    <?php endif; ?>
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

    <section id="section-sales" class="hidden" data-auto-sales="1">
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
          <span id="sales-auto-note" class="text-sm text-emerald-800 bg-emerald-50 border border-emerald-200 px-3 py-2 rounded">Auto sync enabled: sales are generated from your actual handled lots and transactions.</span>
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
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Track your recent actions, outcomes, and timestamps.</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="audit-logs-container">
          <p class="text-gray-600">Loading audit logs...</p>
        </div>
      </div>
    </section>

    <section id="section-documents" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-4">Document Review</h2>
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Prioritize pending client requirements and update statuses quickly.</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <div id="documents-container">
          <p class="text-gray-600">Loading documents...</p>
        </div>
      </div>
    </section>

    <section id="section-viewings" class="hidden">
      <h2 class="text-3xl font-bold text-green-900 mb-1">Viewing Requests</h2>
      <p class="text-gray-500 text-sm mb-5">Manage and track your assigned viewing requests</p>
      <div class="bg-white rounded-2xl border shadow vw-theme">
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
              $tabOrder = ['pending','scheduled','completed','cancelled'];
              foreach ($tabOrder as $tab):
                if (($statusCounts[$tab] ?? 0) > 0):
            ?>
              <button onclick="filterViewings('<?php echo $tab; ?>')" class="vw-filter-tab" data-filter="<?php echo $tab; ?>"><?php echo ucfirst($tab); ?> <span class="vw-count"><?php echo $statusCounts[$tab]; ?></span></button>
            <?php endif; endforeach; ?>
          </div>

          <div class="vw-calendar-layout p-4 md:p-5">
            <div class="vw-calendar-pane">
              <div class="vw-calendar-head">
                <button type="button" class="vw-cal-nav" onclick="changeViewingMonth(-1)" aria-label="Previous month">&#10094;</button>
                <div id="vw-month-label" class="vw-month-label">Month</div>
                <button type="button" class="vw-cal-nav" onclick="changeViewingMonth(1)" aria-label="Next month">&#10095;</button>
              </div>
              <div class="vw-calendar-tools">
                <button type="button" class="vw-cal-tool-btn" onclick="jumpViewingToToday()">Today</button>
                <button type="button" class="vw-cal-tool-btn" onclick="clearViewingDateFilter()">Clear Date</button>
              </div>
              <div class="vw-weekdays">
                <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
              </div>
              <div class="vw-calendar-legend">
                <span class="vw-legend-item"><span class="vw-legend-dot pending"></span>Pending</span>
                <span class="vw-legend-item"><span class="vw-legend-dot scheduled"></span>Scheduled</span>
                <span class="vw-legend-item"><span class="vw-legend-dot completed"></span>Completed</span>
                <span class="vw-legend-item"><span class="vw-legend-dot cancelled"></span>Cancelled</span>
              </div>
              <div id="vw-calendar-grid" class="vw-calendar-grid"></div>
            </div>
          </div>

          <!-- Hidden event data container — used by calendar JS for dots & counts -->
          <div id="vw-events-list" style="display:none;">
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
              $viewingDateKey = !empty($v['preferred_at']) ? date('Y-m-d', strtotime($v['preferred_at'])) : '';
            ?>
            <div class="vw-row vw-event-card p-5 hover:bg-gray-50/50 transition" data-status="<?php echo h($st); ?>" data-date="<?php echo h($viewingDateKey); ?>">
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
                    <?php if (!empty($v['is_existing_client'])): ?>
                      <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200" title="Matched registered account<?php echo !empty($v['matched_username']) ? ': ' . h($v['matched_username']) : ''; ?>">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        Existing Client
                      </span>
                    <?php endif; ?>
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
                  <?php if ($st === 'completed'): ?>
                    <!-- Static completed badge -->
                    <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                      Completed
                    </span>
                  <?php elseif (in_array($st, ['cancelled', 'no_show_agent', 'no_show_client'])): ?>
                    <!-- Static cancelled badge -->
                    <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-bold bg-red-50 text-red-700 border border-red-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                      <?php echo ($st === 'no_show_agent') ? 'No Show (Agent)' : (($st === 'no_show_client') ? 'No Show (Client)' : 'Cancelled'); ?>
                    </span>
                  <?php elseif (in_array($st, ['pending', 'requested', 'scheduled', 'rescheduled'])): ?>
                    <?php if ($st === 'pending' || $st === 'requested'): ?>
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
                        <button name="viewing_action" value="cancelled" class="vw-btn vw-btn-cancel">Submit</button>
                        <button type="button" class="vw-btn vw-btn-secondary" onclick="hideCancelReason(<?php echo (int)$v['id']; ?>)">Back</button>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Date detail modal -->
      <div id="vw-date-modal" onclick="if(event.target===this)closeDateModal()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:9000;align-items:flex-start;justify-content:center;background:rgba(15,23,42,0.55);padding:40px 16px;box-sizing:border-box;overflow-y:auto;">
        <div id="vw-date-modal-box" style="background:#fff;border-radius:20px;width:100%;max-width:700px;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.18);overflow:hidden;margin:auto;">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px 16px;border-bottom:1px solid #e2efe7;background:#fbfefc;flex-shrink:0;">
            <div>
              <h3 id="vw-modal-title" style="margin:0;font-size:19px;font-weight:800;color:#166534;"></h3>
              <p id="vw-modal-subtitle" style="margin:4px 0 0;font-size:13px;color:#64748b;"></p>
            </div>
            <button onclick="closeDateModal()" style="width:36px;height:36px;border-radius:10px;border:1px solid #bfe7cf!important;background:#eaf8f0!important;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#2f9e66;flex-shrink:0;">&#x2715;</button>
          </div>
          <div id="vw-modal-body" style="padding:16px 20px 24px;">
          </div>
        </div>
      </div>

      <style>
        .vw-theme {
          --vw-primary: #53b583;
          --vw-primary-strong: #419f70;
          --vw-primary-soft: #f4fcf7;
          --vw-primary-border: #d3ecdf;
          --vw-surface: #ffffff;
          --vw-surface-soft: #fbfefc;
          --vw-border: #e2efe7;
          --vw-text: #1f2937;
          --vw-muted: #64748b;
        }

        .vw-calendar-layout {
          display: block;
          background: linear-gradient(180deg, #edfaf3 0%, #f2fbf6 100%);
          border-radius: 16px;
        }

        .vw-calendar-pane {
          border: 1px solid #b2dcc7;
          border-radius: 14px;
          padding: 20px 24px;
          background: #ffffff;
        }

        .vw-calendar-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          background: #edfaf3;
          border-radius: 10px;
          padding: 6px 10px;
          margin-bottom: 12px;
        }

        .vw-month-label {
          font-size: 20px;
          font-weight: 800;
          color: var(--vw-primary-strong);
          letter-spacing: 0.01em;
        }

        .vw-cal-nav {
          width: 34px;
          height: 34px;
          border-radius: 8px;
          border: 1px solid var(--vw-border) !important;
          background: var(--vw-surface) !important;
          color: #334155 !important;
          font-size: 15px;
          line-height: 1;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
        }

        .vw-cal-nav:hover {
          border-color: var(--vw-primary-border) !important;
          background: var(--vw-primary-soft) !important;
          color: var(--vw-primary-strong) !important;
        }

        .vw-weekdays {
          display: grid;
          grid-template-columns: repeat(7, minmax(0, 1fr));
          gap: 8px;
          margin-bottom: 10px;
          background: #d6f5e5;
          border-radius: 8px;
          padding: 2px 4px;
        }

        .vw-calendar-tools {
          display: flex;
          gap: 8px;
          margin-bottom: 10px;
        }

        .vw-cal-tool-btn {
          border: 1px solid var(--vw-border) !important;
          background: var(--vw-surface) !important;
          color: #334155 !important;
          border-radius: 8px;
          padding: 6px 11px;
          font-size: 12px;
          font-weight: 700;
          cursor: pointer;
        }

        .vw-cal-tool-btn:hover {
          border-color: var(--vw-primary-border) !important;
          background: var(--vw-primary-soft) !important;
          color: var(--vw-primary-strong) !important;
        }

        .vw-calendar-legend {
          display: flex;
          flex-wrap: wrap;
          gap: 8px 10px;
          margin-bottom: 10px;
          background: #edfaf3;
          border-radius: 8px;
          padding: 7px 10px;
        }

        .vw-legend-item {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          font-size: 10px;
          font-weight: 700;
          color: var(--vw-muted);
          text-transform: uppercase;
          letter-spacing: 0.02em;
        }

        .vw-legend-dot {
          width: 8px;
          height: 8px;
          border-radius: 999px;
          display: inline-block;
        }

        .vw-legend-dot.pending { background: #f59e0b; }
        .vw-legend-dot.scheduled { background: #22c55e; }
        .vw-legend-dot.rescheduled { background: #3b82f6; }
        .vw-legend-dot.completed { background: #0d9488; }
        .vw-legend-dot.cancelled { background: #ef4444; }

        .vw-weekdays span {
          text-align: center;
          font-size: 13px;
          font-weight: 700;
          color: var(--vw-muted);
          text-transform: uppercase;
          padding: 6px 0;
        }

        .vw-calendar-grid {
          display: grid;
          grid-template-columns: repeat(7, minmax(0, 1fr));
          gap: 8px;
        }

        .vw-day {
          position: relative;
          border: 1px solid #cce8d9 !important;
          border-radius: 12px;
          min-height: 120px;
          background: #f9fefb !important;
          padding: 10px;
          display: grid;
          grid-template-columns: 1fr auto;
          justify-content: space-between;
          cursor: pointer;
          color: #334155 !important;
          transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }

        .vw-day:hover {
          border-color: var(--vw-primary-border) !important;
          background: #f8fdf9 !important;
          box-shadow: 0 4px 12px rgba(83, 181, 131, 0.12);
        }

        .vw-day.is-muted {
          visibility: hidden;
          pointer-events: none;
        }

        .vw-day.is-today {
          border-color: var(--vw-primary) !important;
          background: #f7fdf9 !important;
        }

        .vw-day.is-selected {
          border-color: var(--vw-primary) !important;
          background: var(--vw-primary-soft) !important;
          box-shadow: 0 5px 12px rgba(83, 181, 131, 0.16);
        }

        .vw-day-num {
          font-size: 15px;
          font-weight: 700;
          color: #334155 !important;
        }

        .vw-day-count {
          font-size: 12px;
          font-weight: 700;
          min-width: 22px;
          height: 22px;
          border-radius: 9px;
          padding: 0 6px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          background: #eaf8f0;
          color: var(--vw-primary-strong);
        }

        .vw-day-statuses {
          grid-column: 1 / -1;
          display: inline-flex;
          align-items: center;
          gap: 4px;
          margin-top: 5px;
          min-height: 8px;
        }

        .vw-day-dot {
          width: 8px;
          height: 8px;
          border-radius: 999px;
          display: inline-block;
        }

        .vw-day-dot.pending { background: #f59e0b; }
        .vw-day-dot.scheduled { background: #22c55e; }
        .vw-day-dot.rescheduled { background: #3b82f6; }
        .vw-day-dot.completed { background: #0d9488; }
        .vw-day-dot.cancelled { background: #ef4444; }

        .vw-more-status {
          font-size: 9px;
          font-weight: 700;
          color: var(--vw-muted);
          line-height: 1;
        }

        .vw-events-pane {
          border: 1px solid var(--vw-border);
          border-radius: 16px;
          overflow: hidden;
          background: var(--vw-surface);
        }

        .vw-events-toolbar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 8px;
          padding: 14px 16px;
          border-bottom: 1px solid var(--vw-border);
          background: var(--vw-surface-soft);
        }

        .vw-events-title {
          font-size: 15px;
          font-weight: 700;
          color: var(--vw-text);
          margin: 0;
        }

        #vw-events-list {
          max-height: 720px;
          overflow-y: auto;
        }

        #vw-events-list::-webkit-scrollbar {
          width: 8px;
        }

        #vw-events-list::-webkit-scrollbar-thumb {
          background: #cbd5e1;
          border-radius: 999px;
        }

        #vw-events-list::-webkit-scrollbar-thumb:hover {
          background: #94a3b8;
        }

        .vw-clear-date {
          border: 1px solid var(--vw-border) !important;
          background: var(--vw-surface) !important;
          color: #475569 !important;
          font-size: 12px;
          font-weight: 700;
          border-radius: 8px;
          padding: 7px 12px;
          cursor: pointer;
        }

        .vw-clear-date:hover {
          background: var(--vw-primary-soft) !important;
          border-color: var(--vw-primary-border) !important;
          color: var(--vw-primary-strong) !important;
        }

        .vw-event-card {
          padding: 20px;
        }

        @media (max-width: 768px) {
          .vw-day {
            min-height: 80px;
            padding: 7px;
          }
          .vw-day-num { font-size: 13px; }
        }

        /* Date modal card styles */
        .vwm-card {
          border: 1px solid #e2efe7;
          border-radius: 14px;
          padding: 16px 18px;
          margin-bottom: 12px;
          background: #fff;
          display: flex;
          gap: 14px;
          align-items: flex-start;
        }
        .vwm-card:last-child { margin-bottom: 0; }
        .vwm-date-badge {
          flex-shrink: 0;
          text-align: center;
          background: #f8fdf9;
          border: 1px solid #e2efe7;
          border-radius: 12px;
          padding: 8px 12px;
          min-width: 56px;
        }
        .vwm-date-badge .mn { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
        .vwm-date-badge .dy { font-size: 22px; font-weight: 800; color: #166534; line-height: 1.1; }
        .vwm-date-badge .tm { font-size: 10px; color: #94a3b8; font-weight: 500; }
        .vwm-body { flex: 1; min-width: 0; }
        .vwm-name-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 7px; }
        .vwm-name { font-size: 15px; font-weight: 700; color: #1f2937; }
        .vwm-badge {
          display: inline-flex; align-items: center; gap: 5px;
          font-size: 11px; font-weight: 600;
          padding: 2px 9px; border-radius: 999px;
          border: 1px solid;
        }
        .vwm-price { font-size: 12px; color: #64748b; font-weight: 500; margin-left: auto; white-space: nowrap; }
        .vwm-info-row { display: flex; flex-wrap: wrap; gap: 5px 14px; margin-bottom: 5px; }
        .vwm-info-item { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #64748b; }
        .vwm-info-item a { color: #419f70; text-decoration: none; }
        .vwm-info-item a:hover { text-decoration: underline; }
        .vwm-note { font-size: 12px; color: #64748b; font-style: italic; margin-top: 6px; display: flex; gap: 5px; align-items: flex-start; }
        .vwm-cancel-reason { font-size: 12px; color: #dc2626; margin-top: 5px; }
        .vwm-actions { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
        .vwm-group-header {
          font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
          color: #94a3b8; margin: 14px 0 8px; display: flex; align-items: center; gap: 8px;
        }
        .vwm-group-header::after { content: ''; flex: 1; height: 1px; background: #e2efe7; }
        .vwm-empty { text-align: center; padding: 36px 20px; color: #94a3b8; font-size: 14px; }
        .vwm-action-btn { font-size: 12px !important; min-width: 80px !important; min-height: 30px !important; padding: 6px 12px !important; }

        /* Viewing Button System */
        .vw-btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 7px;
          min-width: 104px;
          min-height: 36px;
          padding: 8px 14px;
          border-radius: 10px;
          font-size: 13px;
          font-weight: 700;
          border: 1px solid transparent;
          cursor: pointer;
          transition: transform 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease, border-color 0.16s ease, color 0.16s ease;
          white-space: nowrap;
          line-height: 1;
          letter-spacing: 0.01em;
        }

        .vw-btn:hover {
          transform: translateY(-1px);
        }

        /* Strong specificity + !important to beat global button rules above */
        .vw-row .vw-btn-approve {
          background: var(--vw-primary) !important;
          color: #fff !important;
          border-color: var(--vw-primary) !important;
          box-shadow: 0 5px 12px rgba(83, 181, 131, 0.18);
        }

        .vw-row .vw-btn-approve:hover {
          background: var(--vw-primary-strong) !important;
          border-color: var(--vw-primary-strong) !important;
          box-shadow: 0 7px 14px rgba(65, 159, 112, 0.2);
        }

        .vw-row .vw-btn-cancel {
          background: #fff7ed !important;
          color: #9a3412 !important;
          border-color: #fdba74 !important;
        }

        .vw-row .vw-btn-cancel:hover {
          background: #ffedd5 !important;
          border-color: #fb923c !important;
          color: #7c2d12 !important;
          box-shadow: 0 6px 12px rgba(251, 146, 60, 0.2);
        }

        .vw-row .vw-btn-secondary {
          background: #f8fafc !important;
          color: #475569 !important;
          border-color: #cbd5e1 !important;
        }

        .vw-row .vw-btn-secondary:hover {
          background: #f1f5f9 !important;
          border-color: #94a3b8 !important;
          color: #334155 !important;
        }

        /* Filter Tabs */
        .vw-filter-tab {
          display: inline-flex; align-items: center; gap: 5px;
          padding: 7px 14px; border-radius: 9px;
          font-size: 13px; font-weight: 500;
          border: 1.5px solid var(--vw-border) !important;
          background: var(--vw-surface) !important; color: #64748b !important;
          cursor: pointer; transition: all 0.15s;
        }
        .vw-filter-tab:hover { background: var(--vw-primary-soft) !important; color: #334155 !important; border-color: var(--vw-primary-border) !important; }
        .vw-filter-tab.active { background: #e9f8f0 !important; color: #1f6a47 !important; border-color: #bfe7d1 !important; }
        .vw-filter-tab.active .vw-count { background: #d5efdf; color: #1f6a47; }
        .vw-count {
          display: inline-flex; align-items: center; justify-content: center;
          min-width: 20px; height: 20px;
          padding: 0 6px; border-radius: 10px;
          font-size: 11px; font-weight: 700;
          background: #f0f5f2; color: #64748b;
        }
      </style>

      <script>
        const vwData = <?php echo json_encode(array_map(function($v) {
          return [
            'id'                  => (int)($v['id'] ?? 0),
            'status'              => (string)($v['status'] ?? ''),
            'preferred_at'        => (function($d) {
              $s = (string)($d ?? '');
              // Reject zero/invalid MySQL datetime placeholders
              return (!$s || str_starts_with($s, '0000')) ? '' : $s;
            })($v['preferred_at'] ?? null),
            'client_first_name'   => (string)($v['client_first_name'] ?? ''),
            'client_middle_name'  => (string)($v['client_middle_name'] ?? ''),
            'client_last_name'    => (string)($v['client_last_name'] ?? ''),
            'client_email'        => (string)($v['client_email'] ?? ''),
            'client_phone'        => (string)($v['client_phone'] ?? ''),
            'lot_price'           => $v['lot_price'] !== null ? (float)$v['lot_price'] : null,
            'location_name'       => (string)($v['location_name'] ?? ''),
            'block_number'        => (string)($v['block_number'] ?? ''),
            'lot_number'          => (string)($v['lot_number'] ?? ''),
            'lot_size'            => $v['lot_size'] !== null ? (float)$v['lot_size'] : null,
            'lot_no'              => (string)($v['lot_no'] ?? ''),
            'notes'               => (string)($v['notes'] ?? ''),
            'cancellation_reason' => (string)($v['cancellation_reason'] ?? ''),
            'is_existing_client'  => !empty($v['is_existing_client']),
            'matched_username'    => (string)($v['matched_username'] ?? ''),
            'created_at'          => (string)($v['created_at'] ?? ''),
          ];
        }, $all_viewings ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const vwCsrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;

        let viewingCalendarMonth = null;
        let viewingSelectedDate = null;
        let viewingActiveStatus = 'all';

        function getViewingRows() {
          return Array.from(document.querySelectorAll('.vw-event-card'));
        }

        // Returns the best available date string for a viewing, empty string if none.
        function getViewingDate(v) {
          const pa = (v.preferred_at || '').trim();
          if (pa && !pa.startsWith('0000')) return pa;
          const ca = (v.created_at || '').trim();
          if (ca && !ca.startsWith('0000')) return ca;
          return '';
        }

        function getMonthKey(dateObj) {
          return dateObj.getFullYear() + '-' + String(dateObj.getMonth() + 1).padStart(2, '0');
        }

        function getMonthKeyFromRaw(rawDate) {
          if (!rawDate) return '';
          const dt = new Date(String(rawDate).replace(' ', 'T'));
          if (isNaN(dt.getTime())) return '';
          return getMonthKey(dt);
        }

        function refreshStatusTabsForCurrentMonth() {
          if (!viewingCalendarMonth) return;

          const monthKey = getMonthKey(viewingCalendarMonth);
          const counts = {
            all: 0,
            pending: 0,
            scheduled: 0,
            completed: 0,
            cancelled: 0
          };

          vwData.forEach(function(v) {
            const rawDate = getViewingDate(v);
            const rowMonthKey = getMonthKeyFromRaw(rawDate);
            if (!rowMonthKey || rowMonthKey !== monthKey) return;

            const normalized = normalizeCalendarStatus(v.status || '');
            counts.all += 1;
            if (Object.prototype.hasOwnProperty.call(counts, normalized)) {
              counts[normalized] += 1;
            }
          });

          const tabs = Array.from(document.querySelectorAll('.vw-filter-tab'));
          tabs.forEach(function(tab) {
            const status = tab.dataset.filter || 'all';
            const countEl = tab.querySelector('.vw-count');
            const count = counts[status] ?? 0;

            if (countEl) {
              countEl.textContent = String(count);
            }

            if (status !== 'all') {
              tab.style.display = count > 0 ? '' : 'none';
            } else {
              tab.style.display = '';
            }
          });

          const activeTabVisible = tabs.some(function(tab) {
            return tab.dataset.filter === viewingActiveStatus && tab.style.display !== 'none';
          });

          if (!activeTabVisible) {
            viewingActiveStatus = 'all';
          }

          tabs.forEach(function(tab) {
            tab.classList.toggle('active', tab.dataset.filter === viewingActiveStatus);
          });
        }

        function getMonthName(dateObj) {
          return dateObj.toLocaleString('en-US', { month: 'long', year: 'numeric' });
        }

        function toLocalDateKey(dateObj) {
          const y = dateObj.getFullYear();
          const m = String(dateObj.getMonth() + 1).padStart(2, '0');
          const d = String(dateObj.getDate()).padStart(2, '0');
          return y + '-' + m + '-' + d;
        }

        function normalizeCalendarStatus(status) {
          if (status === 'pending' || status === 'requested') return 'pending';
          if (status === 'scheduled') return 'scheduled';
          if (status === 'rescheduled') return 'rescheduled';
          if (status === 'completed') return 'completed';
          if (status === 'cancelled' || status === 'no_show_agent' || status === 'no_show_client') return 'cancelled';
          return 'pending';
        }

        function formatSelectedDateLabel(dateKey) {
          if (!dateKey) return 'All requests';
          const dateObj = new Date(dateKey + 'T00:00:00');
          return 'Requests on ' + dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function updateViewingHeader() {
          const title = document.getElementById('vw-events-title');
          if (!title) return;

          const statusLabel = viewingActiveStatus === 'all'
            ? ''
            : (' - ' + viewingActiveStatus.charAt(0).toUpperCase() + viewingActiveStatus.slice(1));
          title.textContent = formatSelectedDateLabel(viewingSelectedDate) + statusLabel;

          const clearBtn = document.getElementById('vw-clear-date');
          if (clearBtn) clearBtn.style.display = viewingSelectedDate ? '' : 'none';
        }

        function renderViewingCalendar() {
          const grid = document.getElementById('vw-calendar-grid');
          const label = document.getElementById('vw-month-label');
          if (!grid || !label || !viewingCalendarMonth) return;

          refreshStatusTabsForCurrentMonth();

          const year = viewingCalendarMonth.getFullYear();
          const month = viewingCalendarMonth.getMonth();
          const firstDay = new Date(year, month, 1).getDay();
          const daysInMonth = new Date(year, month + 1, 0).getDate();
          const todayKey = toLocalDateKey(new Date());

          label.textContent = getMonthName(viewingCalendarMonth);
          grid.innerHTML = '';

          const countByDate = {};
          const statusByDate = {};
          vwData.forEach(function(v) {
            const rawDate = getViewingDate(v);
            const dateKey = rawDate ? rawDate.slice(0, 10) : '';
            const status = v.status || '';
            if (!dateKey) return;
            if (viewingActiveStatus !== 'all' && status !== viewingActiveStatus) return;
            countByDate[dateKey] = (countByDate[dateKey] || 0) + 1;
            if (!statusByDate[dateKey]) statusByDate[dateKey] = new Set();
            statusByDate[dateKey].add(normalizeCalendarStatus(status));
          });

          for (let i = 0; i < firstDay; i++) {
            const filler = document.createElement('div');
            filler.className = 'vw-day is-muted';
            grid.appendChild(filler);
          }

          for (let d = 1; d <= daysInMonth; d++) {
            const dateKey = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'vw-day';
            if (dateKey === todayKey) btn.classList.add('is-today');
            if (viewingSelectedDate === dateKey) btn.classList.add('is-selected');

            const count = countByDate[dateKey] || 0;
            const statuses = statusByDate[dateKey] ? Array.from(statusByDate[dateKey]) : [];
            const dots = statuses.slice(0, 3).map(function(st) {
              return '<span class="vw-day-dot ' + st + '"></span>';
            }).join('');
            const moreText = statuses.length > 3 ? '<span class="vw-more-status">+' + (statuses.length - 3) + '</span>' : '';

            btn.innerHTML = '<span class="vw-day-num">' + d + '</span>'
              + (count > 0 ? '<span class="vw-day-count">' + count + '</span>' : '')
              + '<span class="vw-day-statuses">' + dots + moreText + '</span>';
            btn.onclick = function() {
              viewingSelectedDate = dateKey;
              applyViewingFilters();
              renderViewingCalendar();
              openDateModal(dateKey);
            };
            grid.appendChild(btn);
          }
        }

        function applyViewingFilters() {
          let visible = 0;
          getViewingRows().forEach(function(row) {
            const statusMatch = (viewingActiveStatus === 'all' || row.dataset.status === viewingActiveStatus);
            const dateMatch = (!viewingSelectedDate || row.dataset.date === viewingSelectedDate);
            const isVisible = statusMatch && dateMatch;
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) visible++;
          });

          const emptyState = document.getElementById('vw-empty-state');
          if (emptyState) emptyState.style.display = visible === 0 ? '' : 'none';
          updateViewingHeader();
        }

        function filterViewings(status) {
          viewingActiveStatus = status;
          viewingSelectedDate = null;

          document.querySelectorAll('.vw-filter-tab').forEach(t => t.classList.remove('active'));
          const selectedTab = document.querySelector('.vw-filter-tab[data-filter="' + status + '"]');
          if (selectedTab) selectedTab.classList.add('active');
          applyViewingFilters();
          renderViewingCalendar();
        }

        function changeViewingMonth(offset) {
          if (!viewingCalendarMonth) return;
          viewingCalendarMonth = new Date(viewingCalendarMonth.getFullYear(), viewingCalendarMonth.getMonth() + offset, 1);
          viewingSelectedDate = null;
          applyViewingFilters();
          renderViewingCalendar();
        }

        function clearViewingDateFilter() {
          viewingSelectedDate = null;
          applyViewingFilters();
          renderViewingCalendar();
        }

        function jumpViewingToToday() {
          const now = new Date();
          viewingCalendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
          viewingSelectedDate = toLocalDateKey(now);
          applyViewingFilters();
          renderViewingCalendar();
        }

        (function initViewingCalendar() {
          if (!vwData.length) return;

          const now = new Date();
          viewingCalendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);

          applyViewingFilters();
          renderViewingCalendar();
        })();

        /* ---- Date detail modal ---- */
        const vwStatusCfg = {
          pending:          { label: 'Pending',           bg: '#fffbeb', color: '#92400e', border: '#fcd34d', dot: '#f59e0b' },
          requested:        { label: 'Requested',         bg: '#fffbeb', color: '#92400e', border: '#fcd34d', dot: '#f59e0b' },
          scheduled:        { label: 'Scheduled',         bg: '#f0fdf4', color: '#166534', border: '#86efac', dot: '#22c55e' },
          rescheduled:      { label: 'Rescheduled',       bg: '#eff6ff', color: '#1e40af', border: '#93c5fd', dot: '#3b82f6' },
          completed:        { label: 'Completed',         bg: '#ecfeff', color: '#115e59', border: '#5eead4', dot: '#0d9488' },
          cancelled:        { label: 'Cancelled',         bg: '#fef2f2', color: '#991b1b', border: '#fca5a5', dot: '#ef4444' },
          no_show_agent:    { label: 'No Show (Agent)',   bg: '#fef2f2', color: '#991b1b', border: '#fca5a5', dot: '#ef4444' },
          no_show_client:   { label: 'No Show (Client)',  bg: '#fef2f2', color: '#991b1b', border: '#fca5a5', dot: '#ef4444' },
        };
        const vwStatusOrder = ['pending','requested','scheduled','rescheduled','completed','cancelled','no_show_agent','no_show_client'];

        function esc(str) {
          return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function buildModalCard(v) {
          const cfg = vwStatusCfg[v.status] || vwStatusCfg['pending'];
          const locParts = [];
          if (v.location_name) locParts.push(v.location_name);
          if (v.block_number && v.lot_number) {
            let lbl = 'Block ' + v.block_number + ', Lot ' + v.lot_number;
            if (v.lot_size) lbl += ' (' + parseFloat(v.lot_size).toFixed(2) + ' sqm)';
            locParts.push(lbl);
          } else if (v.lot_no) {
            locParts.push('Lot ' + v.lot_no);
          }
          const locText = locParts.join(' — ') || 'N/A';

          let dateBadge = '<div style="font-size:12px;color:#94a3b8;">TBD</div>';
          const dateSource = getViewingDate(v);
          if (dateSource) {
            const dt = new Date(dateSource.replace(' ', 'T'));
            const mon = dt.toLocaleString('en-US', { month: 'short' }).toUpperCase();
            const dy  = dt.getDate();
            const tm  = v.preferred_at && !v.preferred_at.startsWith('0000')
              ? dt.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
              : 'Requested';
            dateBadge = '<div class="mn">' + mon + '</div><div class="dy">' + dy + '</div><div class="tm">' + esc(tm) + '</div>';
          }

          const existingBadge = v.is_existing_client
            ? '<span class="vwm-badge" style="background:#ecfdf5;color:#065f46;border-color:#6ee7b7;"><span style="width:6px;height:6px;border-radius:999px;background:#10b981;display:inline-block;"></span>Existing Client</span>'
            : '';
          const statusBadge = '<span class="vwm-badge" style="background:' + cfg.bg + ';color:' + cfg.color + ';border-color:' + cfg.border + ';"><span style="width:6px;height:6px;border-radius:999px;background:' + cfg.dot + ';display:inline-block;"></span>' + esc(cfg.label) + '</span>';
          const priceHtml = v.lot_price ? '<span class="vwm-price">&#8369;' + parseFloat(v.lot_price).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}) + '</span>' : '';

          const notesHtml = v.notes
            ? '<div class="vwm-note"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg><span>' + esc(v.notes) + '</span></div>'
            : '';
          const cancelReasonHtml = v.cancellation_reason
            ? '<div class="vwm-cancel-reason"><strong>Cancellation reason:</strong> ' + esc(v.cancellation_reason) + '</div>'
            : '';

          let actionsHtml = '';
          const actionable = ['pending','requested','scheduled','rescheduled'];
          if (actionable.includes(v.status)) {
            let approveBtnHtml = '';
            if (v.status === 'pending' || v.status === 'requested') {
              approveBtnHtml = '<form method="post" style="display:inline;">' +
                '<input type="hidden" name="csrf_token" value="' + esc(vwCsrfToken) + '">' +
                '<input type="hidden" name="approve_viewing_id" value="' + v.id + '">' +
                '<input type="hidden" name="redirect_to" value="viewings">' +
                '<button type="submit" class="vw-btn vw-btn-approve vwm-action-btn">&#10003; Approve</button></form>';
            }
            const cancelBtnHtml = '<button type="button" class="vw-btn vw-btn-cancel vwm-action-btn" onclick="showModalCancelForm(' + v.id + ')" id="vwm-cancel-init-' + v.id + '">&#x2715; Cancel</button>' +
              '<form method="post" id="vwm-cancel-form-' + v.id + '" style="display:none;width:100%;">' +
              '<input type="hidden" name="csrf_token" value="' + esc(vwCsrfToken) + '">' +
              '<input type="hidden" name="viewing_id" value="' + v.id + '">' +
              '<input type="hidden" name="redirect_to" value="viewings">' +
              '<textarea name="cancellation_reason" rows="2" style="width:100%;border:1px solid #fca5a5;border-radius:8px;padding:8px 10px;font-size:12px;resize:none;outline:none;margin-top:6px;box-sizing:border-box;" placeholder="Reason for cancellation (required)" required></textarea>' +
              '<div style="display:flex;gap:6px;margin-top:6px;">' +
              '<button name="viewing_action" value="cancelled" class="vw-btn vw-btn-cancel vwm-action-btn">Submit</button>' +
              '<button type="button" class="vw-btn vw-btn-secondary vwm-action-btn" onclick="hideModalCancelForm(' + v.id + ')">Back</button>' +
              '</div></form>';
            actionsHtml = '<div class="vwm-actions">' + approveBtnHtml + cancelBtnHtml + '</div>';
          }

          return '<div class="vwm-card">' +
            '<div class="vwm-date-badge">' + dateBadge + '</div>' +
            '<div class="vwm-body">' +
              '<div class="vwm-name-row">' +
                '<span class="vwm-name">' + esc(v.client_first_name + ' ' + v.client_last_name) + '</span>' +
                existingBadge + statusBadge + priceHtml +
              '</div>' +
              '<div class="vwm-info-row">' +
                '<span class="vwm-info-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' + esc(locText) + '</span>' +
                (v.client_email ? '<span class="vwm-info-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><a href="mailto:' + esc(v.client_email) + '">' + esc(v.client_email) + '</a></span>' : '') +
                (v.client_phone ? '<span class="vwm-info-item"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg><a href="tel:' + esc(v.client_phone) + '">' + esc(v.client_phone) + '</a></span>' : '') +
              '</div>' +
              notesHtml + cancelReasonHtml + actionsHtml +
            '</div>' +
          '</div>';
        }

        function openDateModal(dateKey) {
          const modal = document.getElementById('vw-date-modal');
          const body  = document.getElementById('vw-modal-body');
          const title = document.getElementById('vw-modal-title');
          const subtitle = document.getElementById('vw-modal-subtitle');
          if (!modal || !body) return;

          const dateObj = new Date(dateKey + 'T00:00:00');
          title.textContent = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

          // Group events by status order
          const grouped = {};
          vwData.forEach(function(v) {
            const rawDate = getViewingDate(v);
            if (!rawDate) return;
            const dKey = rawDate.slice(0, 10);
            if (dKey !== dateKey) return;
            if (viewingActiveStatus !== 'all' && v.status !== viewingActiveStatus) return;
            if (!grouped[v.status]) grouped[v.status] = [];
            grouped[v.status].push(v);
          });

          const statusGroups = vwStatusOrder.filter(s => grouped[s] && grouped[s].length > 0);
          const total = statusGroups.reduce((acc, s) => acc + grouped[s].length, 0);
          subtitle.textContent = total + ' viewing request' + (total !== 1 ? 's' : '');

          if (total === 0) {
            body.innerHTML = '<div class="vwm-empty">No viewings for this date.</div>';
          } else {
            let html = '';
            statusGroups.forEach(function(status) {
              const cfg = vwStatusCfg[status] || {};
              html += '<div class="vwm-group-header"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:999px;background:' + (cfg.dot||'#94a3b8') + ';display:inline-block;"></span>' + esc(cfg.label || status) + '</span></div>';
              grouped[status].forEach(function(v) { html += buildModalCard(v); });
            });
            body.innerHTML = html;
          }

          modal.style.display = 'flex';
          document.body.style.overflow = 'hidden';
        }

        function closeDateModal() {
          const modal = document.getElementById('vw-date-modal');
          if (modal) modal.style.display = 'none';
          document.body.style.overflow = '';
        }

        function showModalCancelForm(id) {
          document.getElementById('vwm-cancel-init-' + id).style.display = 'none';
          document.getElementById('vwm-cancel-form-' + id).style.display = 'block';
        }
        function hideModalCancelForm(id) {
          document.getElementById('vwm-cancel-form-' + id).style.display = 'none';
          document.getElementById('vwm-cancel-init-' + id).style.display = '';
        }

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') closeDateModal();
        });

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
      <p class="text-gray-700 mb-1" style="line-height: 0.5em; margin-top: -0.5em; margin-bottom: 1.5em;">Track pipeline stage, follow up, and move leads forward to viewing and reservation.</p>
      <div class="bg-white rounded-2xl border shadow p-6">
        <?php if (empty($leads)): ?>
          <div class="border border-dashed rounded-xl p-6 text-gray-500 text-sm text-center">
            No leads assigned yet.
            <div class="mt-2 text-xs text-gray-400">New website inquiries and assigned prospects will appear here.</div>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full border rounded text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="py-2 px-4 text-left border">Lead</th>
                  <th class="py-2 px-4 text-left border">Contact</th>
                  <th class="py-2 px-4 text-left border">Stage</th>
                  <th class="py-2 px-4 text-left border">Last Update</th>
                  <th class="py-2 px-4 text-left border">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($leads as $lead): ?>
                <tr class="border-t">
                  <td class="py-2 px-4 border font-semibold text-gray-900"><?php echo h(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: 'N/A'); ?></td>
                  <td class="py-2 px-4 border">
                    <div class="text-gray-800"><?php echo h($lead['email'] ?? 'N/A'); ?></div>
                    <div class="text-xs text-gray-500"><?php echo h($lead['phone'] ?? 'N/A'); ?></div>
                  </td>
                  <td class="py-2 px-4 border">
                    <?php
                      $leadStatus = strtolower(trim((string)($lead['status'] ?? 'new')));
                      $statusMap = [
                        'new' => 'bg-blue-100 text-blue-800',
                        'contacted' => 'bg-indigo-100 text-indigo-800',
                        'scheduled' => 'bg-emerald-100 text-emerald-800',
                        'reservation' => 'bg-amber-100 text-amber-800',
                        'installment' => 'bg-purple-100 text-purple-800',
                        'paid' => 'bg-green-100 text-green-800',
                        'closed' => 'bg-green-100 text-green-800',
                        'lost' => 'bg-red-100 text-red-800',
                      ];
                      $badgeClass = $statusMap[$leadStatus] ?? 'bg-gray-100 text-gray-700';
                    ?>
                    <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $badgeClass; ?>"><?php echo h(ucwords(str_replace('_', ' ', $leadStatus))); ?></span>
                  </td>
                  <td class="py-2 px-4 border text-gray-700"><?php echo !empty($lead['created_at']) ? h(date('M d, Y h:i A', strtotime($lead['created_at']))) : 'N/A'; ?></td>
                  <td class="py-2 px-4 border">
                    <a href="lead_timeline.php?id=<?php echo (int)$lead['id']; ?>" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded bg-green-700 text-white hover:bg-green-800">
                      Open Timeline
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

  if (id === 'section-notifications') {
    const badge = document.getElementById('agent-notifications-badge');
    if (badge) badge.style.display = 'none';
  }

  function triggerLoads(sectionId) {
    if (sectionId === 'section-notifications') loadAgentNotifications();
    else if (sectionId === 'section-messages') loadAgentMessages();
    else if (sectionId === 'section-audit-logs') loadAgentAuditLogs();
    else if (sectionId === 'section-documents') loadAgentDocuments();
    else if (sectionId === 'section-sales') loadAgentSales();
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
            ${!n.is_read ? `<button class=\"px-3 py-1 text-xs\" style=\"background:#22c55e !important; color:#fff !important; border:1px solid #16a34a !important;\" onclick=\"markNotificationRead(${n.id})\">Approved</button>` : ''}
            <button class="px-3 py-1 text-xs" style="background:#dc2626 !important; color:#fff !important; border:1px solid #dc2626 !important;" onclick="deleteNotification(${n.id})">Decline</button>
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

async function deleteNotification(id) {
  const proceed = await showConfirmModal('Delete this notification?');
  if (!proceed) return;
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
                ${isUnread ? `<button onclick=\"markMessageRead(${m.id})\" style=\"padding:6px 14px; font-size:13px; font-weight:600; background:#b3e0ff !important; color:#0369a1 !important; border:1px solid #38bdf8 !important; border-radius:7px; cursor:pointer;\">Mark Read</button>` : ''}
                <button onclick="deleteMessage(${m.id})" style="padding:6px 14px; font-size:13px; font-weight:600; background:#dc3545 !important; color:#fff !important; border:1px solid #dc3545 !important; border-radius:7px; cursor:pointer;">Delete</button>
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

async function deleteMessage(id) {
  const proceed = await showConfirmModal('Delete this message?');
  if (!proceed) return;
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
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No activity yet.<div class="text-xs text-gray-400 mt-1">Your actions like profile updates, document decisions, and viewing updates will appear here.</div></div>';
        return;
      }

      const toResultBadge = (details) => {
        const text = String(details || '').toLowerCase();
        if (text.includes('fail') || text.includes('error')) {
          return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">Failed</span>';
        }
        return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Success</span>';
      };

      container.innerHTML = `
        <div class="overflow-x-auto">
          <table class="min-w-full border rounded text-sm">
            <thead>
              <tr class="bg-gray-50 text-gray-700">
                <th class="py-2 px-4 text-left border">Action</th>
                <th class="py-2 px-4 text-left border">Target / Details</th>
                <th class="py-2 px-4 text-left border">Result</th>
                <th class="py-2 px-4 text-left border">Date</th>
              </tr>
            </thead>
            <tbody>
              ${logs.map(log => `
                <tr>
                  <td class="py-2 px-4 border font-semibold text-gray-900">${log.action || 'N/A'}</td>
                  <td class="py-2 px-4 border text-gray-700">${log.details || 'N/A'}</td>
                  <td class="py-2 px-4 border">${toResultBadge(log.details)}</td>
                  <td class="py-2 px-4 border text-gray-600">${log.created_at ? new Date(log.created_at).toLocaleString() : 'N/A'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
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
        container.innerHTML = '<div class="text-center py-8 text-gray-500">No documents to review.<div class="text-xs text-gray-400 mt-1">Once clients upload requirements, they will appear here with review actions.</div></div>';
        return;
      }

      const statusPriority = {
        pending_review: 1,
        under_review: 2,
        requires_revision: 3,
        approved: 4,
        rejected: 5
      };

      docs.sort((a, b) => {
        const pa = statusPriority[a.status] || 99;
        const pb = statusPriority[b.status] || 99;
        if (pa !== pb) return pa - pb;
        const ta = a.uploaded_at ? new Date(a.uploaded_at).getTime() : 0;
        const tb = b.uploaded_at ? new Date(b.uploaded_at).getTime() : 0;
        return tb - ta;
      });

      container.innerHTML = `
        <div class="overflow-x-auto">
          <table class="min-w-full border rounded text-sm">
            <thead>
              <tr class="bg-gray-50 text-gray-700">
                <th class="py-2 px-4 text-left">Client</th>
                <th class="py-2 px-4 text-left">Document</th>
                <th class="py-2 px-4 text-left">Status</th>
                <th class="py-2 px-4 text-left">Uploaded</th>
                <th class="py-2 px-4 text-left">Review</th>
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
                const actionLabel = (doc.status === 'pending_review' || doc.status === 'under_review') ? 'Review Now' : 'Update Status';
                return `
                  <tr>
                    <td class="py-2 px-4 border">${(doc.first_name || '') + ' ' + (doc.last_name || '')}</td>
                    <td class="py-2 px-4 border">
                      <div class="font-medium text-gray-800">${doc.doc_type || 'N/A'}</div>
                      <a href="${doc.file_path}" target="_blank" class="text-blue-600 hover:text-blue-800 underline">${doc.file_name}</a>
                    </td>
                    <td class="py-2 px-4 border">
                      <span class="px-2 py-1 rounded-full text-xs font-medium ${statusColor}">${doc.status.replace('_', ' ')}</span>
                    </td>
                    <td class="py-2 px-4 border">${new Date(doc.uploaded_at).toLocaleDateString()}</td>
                    <td class="py-2 px-4 border">
                      <button onclick="updateDocumentStatus(${doc.id}, '${doc.status}')" class="px-3 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700">${actionLabel}</button>
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

// Sales
let cachedSalesData = [];

function loadAgentSales() {
  const container = document.getElementById('sales-table-body');
  if (!container) return;
  
  container.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500">Loading sales records...</td></tr>';
  
  fetch(window.location.pathname + '?fetch=agent_sales_auto')
    .then(response => response.json())
    .then(sales => {
      cachedSalesData = sales;
      renderSalesTable(sales);
    })
    .catch(() => {
      container.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-red-600">Failed to load sales data.</td></tr>';
    });
}

function renderSalesTable(sales) {
  const container = document.getElementById('sales-table-body');
  if (!container) return;

  if (!sales || sales.length === 0) {
    container.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-gray-500 bg-gray-50 border-dashed border-2 rounded">No sales records found yet.</td></tr>';
    return;
  }

  container.innerHTML = sales.map(sale => `
    <tr class="border-t hover:bg-gray-50 transition">
      <td class="py-3 px-4 font-semibold text-gray-900">${sale.property}</td>
      <td class="py-3 px-4 text-gray-700">${sale.buyer}</td>
      <td class="py-3 px-4 font-bold text-green-700">PHP ${parseFloat(sale.sale_price).toLocaleString('en-PH', {minimumFractionDigits: 2})}</td>
      <td class="py-3 px-4 text-gray-600">${new Date(sale.sale_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</td>
      <td class="py-3 px-4">
         <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold ${sale.source === 'Closed' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'} border ${sale.source === 'Closed' ? 'border-green-200' : 'border-blue-200'}">
           ${sale.source}
         </span>
      </td>
    </tr>
  `).join('');
}

// Make the Filter Dropdown & Search Input Work
document.addEventListener('DOMContentLoaded', function() {
  const filterSelect = document.getElementById('filter-select');
  const filterInput = document.getElementById('filter-input');
  
  if (filterSelect && filterInput) {
    filterSelect.addEventListener('change', function() {
      if (this.value === 'all') {
        filterInput.style.display = 'none';
        filterInput.value = '';
        renderSalesTable(cachedSalesData);
      } else {
        filterInput.style.display = 'inline-block';
        filterInput.placeholder = `Search by ${this.options[this.selectedIndex].text}...`;
      }
    });

    filterInput.addEventListener('input', function() {
      const filterType = filterSelect.value;
      const query = this.value.toLowerCase();
      
      const filtered = cachedSalesData.filter(sale => {
        if (filterType === 'buyer_name') return String(sale.buyer).toLowerCase().includes(query);
        if (filterType === 'property_name') return String(sale.property).toLowerCase().includes(query);
        if (filterType === 'sale_date') return String(sale.sale_date).toLowerCase().includes(query);
        if (filterType === 'sale_id') return String(sale.id).toLowerCase().includes(query);
        return true;
      });
      
      renderSalesTable(filtered);
    });
  }
});

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

<script src="assets/js/alert-modal.js"></script>
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