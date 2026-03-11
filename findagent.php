<?php
require_once 'dbconn.php';
header('Content-Type: text/html; charset=utf-8');

/* ============================================================
   Helpers
============================================================ */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function boolval_int($v){ return !empty($v) ? 1 : 0; }

/* ============================================================
   Handle "Schedule Viewing" POST (same page handler)
============================================================ */
$flash_ok = $flash_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='schedule_viewing') {
  $agentId   = (int)($_POST['agent_id'] ?? 0);
  // use form field names from the modal
  $clientFn  = trim($_POST['first_name'] ?? '');
  $clientLn  = trim($_POST['last_name'] ?? '');
  $clientEm  = trim($_POST['client_email'] ?? '');
  $clientPh  = trim($_POST['client_phone'] ?? '');
  $lotNo     = trim($_POST['lot_number'] ?? '');
  $prefDT    = trim($_POST['preferred_datetime'] ?? '');
  $clientLat = isset($_POST['client_lat']) && $_POST['client_lat']!=='' ? (float)$_POST['client_lat'] : null;
  $clientLng = isset($_POST['client_lng']) && $_POST['client_lng']!=='' ? (float)$_POST['client_lng'] : null;
  $location   = trim($_POST['location'] ?? '');
  $blockNo    = trim($_POST['block_number'] ?? '');
  $notes      = trim($_POST['notes'] ?? '');

  if ($agentId && $clientFn !== '' && $clientLn !== '' && $clientEm !== '' && $prefDT !== '') {
    // create table if not exists
    $conn->query("
      CREATE TABLE IF NOT EXISTS viewings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        client_first_name VARCHAR(100) NOT NULL,
        client_last_name  VARCHAR(100) NOT NULL,
        client_email      VARCHAR(160) NOT NULL,
        client_phone      VARCHAR(40) NULL,
        lot_no            VARCHAR(60) NULL,
        preferred_at      DATETIME NOT NULL,
        client_lat        DECIMAL(10,7) NULL,
        client_lng        DECIMAL(10,7) NULL,
        status ENUM('scheduled','rescheduled','completed','no_show_agent','no_show_client','cancelled') DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $conn->prepare("
      INSERT INTO viewings (agent_id, client_first_name, client_last_name, client_email, client_phone, lot_no, preferred_at, client_lat, client_lng)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $prefAt = date('Y-m-d H:i:s', strtotime($prefDT));
    $lat = $clientLat !== null ? $clientLat : null;
    $lng = $clientLng !== null ? $clientLng : null;

    $stmt->bind_param(
      'issssssdd',
      $agentId, $clientFn, $clientLn, $clientEm, $clientPh, $lotNo, $prefAt, $lat, $lng
    );
    if ($stmt->execute()) {
      $flash_ok = "Viewing requested! We’ll confirm via email.";
    } else {
      $flash_err = "Couldn’t save viewing (".h($stmt->error).").";
    }
    $stmt->close();
  } else {
    $flash_err = "Please fill in First/Last name, Email, and Preferred date/time.";
  }
}

/* ============================================================
   Inputs (Filters)
============================================================ */
$q         = isset($_GET['q']) ? trim($_GET['q']) : '';
$available = boolval_int($_GET['available'] ?? 0);
$city      = isset($_GET['city']) ? trim($_GET['city']) : '';
$clat      = isset($_GET['clat']) && $_GET['clat']!=='' ? (float)$_GET['clat'] : null;
$clng      = isset($_GET['clng']) && $_GET['clng']!=='' ? (float)$_GET['clng'] : null;

/* ============================================================
   Schema capability checks (lat/lng, is_available)
============================================================ */
$hasGeo = $hasAvail = $hasAvailability = false;

// Check for lat/lng
$chkGeo = $conn->query("
  SELECT COUNT(*) AS c
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='agent_accounts'
    AND COLUMN_NAME IN ('lat','lng')
");
if ($chkGeo) {
  $row = $chkGeo->fetch_assoc();
  $hasGeo = ((int)$row['c'] >= 2);
}

// Check for is_available
$chkAvail = $conn->query("
  SELECT COUNT(*) AS c
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='agent_accounts'
    AND COLUMN_NAME = 'is_available'
");
if ($chkAvail) {
  $row = $chkAvail->fetch_assoc();
  $hasAvail = ((int)$row['c'] >= 1);
}

// Check for availability
$chkAvailability = $conn->query("
  SELECT COUNT(*) AS c
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='agent_accounts'
    AND COLUMN_NAME = 'availability'
");
if ($chkAvailability) {
  $row = $chkAvailability->fetch_assoc();
  $hasAvailability = ((int)$row['c'] >= 1);
}

/* ============================================================
   Distinct cities
============================================================ */
$cities = [];
$cityRes = $conn->query("
  SELECT DISTINCT
    CASE
      WHEN city IS NOT NULL AND city <> '' THEN city
      WHEN address LIKE '%, %' THEN TRIM(SUBSTRING_INDEX(address, ',', -1))
      ELSE address
    END AS city_name
  FROM agent_accounts
  WHERE (city IS NOT NULL AND city <> '') OR (address IS NOT NULL AND address <> '')
  ORDER BY city_name ASC
");
if ($cityRes) {
  while($r = $cityRes->fetch_assoc()) {
    if (!empty($r['city_name'])) $cities[] = $r['city_name'];
  }
}

/* ============================================================
   Main query
============================================================ */
$params = [];
$types  = '';
$useDistance = ($hasGeo && $clat !== null && $clng !== null);

$sql = "
  SELECT
    aa.id,
    aa.first_name,
    aa.last_name,
    aa.email,
    aa.mobile,
    aa.total_sales,
    aa.address,
    aa.city,
    aa.profile_picture,
    aa.experience,
    aa.description
";
if ($hasAvailability) {
  $sql .= ", aa.availability AS is_available";
} elseif ($hasAvail) {
  $sql .= ", aa.is_available";
} else {
  $sql .= ", NULL AS is_available";
}
if ($useDistance) {
  $sql .= ",
    (6371 * ACOS(
      COS(RADIANS(?)) * COS(RADIANS(aa.lat)) *
      COS(RADIANS(aa.lng) - RADIANS(?)) +
      SIN(RADIANS(?)) * SIN(RADIANS(aa.lat))
    )) AS km
  ";
  $params[] = $clat; $params[] = $clng; $params[] = $clat;
  $types   .= 'ddd';
}
$sql .= ",
    ag.photo AS photo_fallback
  FROM agent_accounts aa
  LEFT JOIN agents ag ON aa.id = ag.login_agent_id
  WHERE 1
";

/* Free text */
if ($q !== '') {
  $sql .= " AND (
            CONCAT_WS(' ', aa.first_name, aa.last_name) LIKE ?
            OR aa.email LIKE ?
            OR aa.mobile LIKE ?
            OR aa.address LIKE ?
            OR aa.city LIKE ?
          )";
  $like = "%{$q}%";
  array_push($params, $like,$like,$like,$like,$like);
  $types .= 'sssss';
}

/* City */
if ($city !== '') {
  $sql .= " AND (
    aa.city = ?
    OR aa.address LIKE ?
    OR (aa.address LIKE '%, %' AND TRIM(SUBSTRING_INDEX(aa.address, ',', -1)) = ?)
  )";
  $params[] = $city;
  $params[] = "%{$city}%";
  $params[] = $city;
  $types   .= 'sss';
}

/* Available only */
if ($available) {
  if ($hasAvailability) {
    $sql .= " AND aa.availability = 1";
  } elseif ($hasAvail) {
    $sql .= " AND aa.is_available = 1";
  } else {
    $sql .= " AND aa.description LIKE '%available%'";
  }
}

/* Order */
if ($useDistance) {
  $sql .= " ORDER BY (km IS NULL), km ASC, aa.first_name, aa.last_name";
} else {
  if ($city !== '') {
    $sql .= " ORDER BY (aa.city <> ?) ASC, aa.first_name, aa.last_name";
    $params[] = $city; $types .= 's';
  } else {
    $sql .= " ORDER BY aa.first_name, aa.last_name";
  }
}
$sql .= " LIMIT 300";

$stmt = $conn->prepare($sql);
if (!$stmt) { die('SQL prepare error: '.$conn->error); }
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$agents = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Find an Agent</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
:root { --green:#2d4e1e; --ink:#222; --muted:#6b7280; --bg:#e3e2e2; --navH: 88px; }
*{ box-sizing:border-box; }
html,body{ margin:0; padding:0; }
body {
  font-family: 'Poppins', sans-serif;
  font-size: 16px;
  line-height: 1.6;
  background-color: #e3efe2;
  color: #333;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  text-align: center;
  overflow-y: scroll;
}

/* NAVBAR */
nav{
  height:var(--navH);
  background:var(--green);
  padding:10px 20px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:1000;
  box-shadow:0 4px 6px rgba(0,0,0,.1);
}
.nav-left,.nav-right{
  list-style:none; display:flex; gap:40px; margin:0; padding:0; align-items:center;
}
nav a{
  color:#fff; text-decoration:none; font-weight:bold; font-size:18px;
  padding:10px 15px; display:inline-block; transition:transform .2s, color .2s;
}
nav a:hover{ transform:translateY(-3px); color:#f4d03f; }

.nav-logo{
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: contain;
  background: #fff;
  padding: 4px;
  margin-right: 12px;
  position: static;
}
.nav-logo img{ width:80px; height:auto; display:block; }

/* Layout */
main{ padding: calc(var(--navH) + 24px) 16px 48px; }
.container{
  max-width: 1200px;
  margin: 0 auto;
  background: #fff;
  border-radius: 25px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  padding: 40px 40px 48px 40px;
}
.head{ display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-left:16px; margin-bottom:8px; }
.head h1{ margin:0; font-size:40px; }

/* Filters */
.filters{
  display:flex; gap:10px; flex-wrap:wrap;
  align-items:center; justify-content:center; margin-top:10px;
}
.search-bar{
  display:flex; align-items:center;
  border:2px solid var(--green); border-radius:10px;
  overflow:hidden; min-width:230px; max-width:400px; width:100%;
}
.search-bar input{
  flex:1; border:none; padding:12px; font-size:16px; outline:none;
}
select, .chk {
  padding:12px; border:2px solid var(--green);
  border-radius:10px; background:#fff;
}
.btn{
  background:var(--green); color:#fff; border:0;
  border-radius:10px; padding:12px 24px;
  cursor:pointer; font-weight:600; font-size:14px;
  transition: background 0.15s, box-shadow 0.15s;
  box-shadow: 0 2px 8px rgba(44,62,80,0.08);
  margin: 0 2px; min-width: 110px;
}
.btn.primary { background: #2d4e1e; }
.btn.secondary { background: #334155; }
.btn.accent { background: #f4d03f; color: #222; }
.btn:hover, .btn:focus {
  filter: brightness(1.08);
  box-shadow: 0 4px 16px rgba(44,62,80,0.12);
}

/* Flash messages */
.flash{ padding:10px; border-radius:6px; margin-bottom:12px; font-size:14px; }
.flash.ok{ background:#ecfdf5; color:#166534; }
.flash.err{ background:#fef2f2; color:#991b1b; }

/* Agent card original styling (we'll override widths later) */
.agent-card {
  background: #fdfdfd;
  border-radius: 18px;
  padding: 25px 20px;
  margin-bottom: 24px;
  border: 1px solid #eef2ed;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
  transition: background 0.3s, transform 0.3s, box-shadow 0.3s;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
}
.agent-card:hover {
  background: #eff5ed;
  transform: translateY(-5px);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
  border-color: #b8c9a7;
}
.agent-card .agent-avatar {
  margin-top: 24px;
  margin-bottom: 12px;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  overflow: hidden;
  background: #f6f6f6;
  border: 3px solid #2d4e1e;
  box-shadow: 0 2px 8px rgba(44,62,80,0.12);
}
.agent-card .agent-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.agent-card .agent-name {
  font-size: 1.15rem;
  font-weight: 700;
  color: #2d4e1e;
  margin-bottom: 8px;
  letter-spacing: 0.5px;
}
.agent-card .agent-meta {
  font-size: 0.9rem;
  color: #555;
  margin-bottom: 0;
  text-align: center;
}
.agent-card .agent-location {
  font-size: 0.9rem;
  color: #666;
  margin-bottom: 6px;
  text-align: center;
}
.agent-card .agent-status {
  font-size: 0.95rem;
  font-weight: 700;
  color: #2d4e1e;
  background: #eaf6e7;
  border-radius: 14px;
  padding: 4px 16px;
  margin-bottom: 10px;
  display: inline-block;
  box-shadow: 0 1px 4px rgba(44,62,80,0.08);
}
.agent-card .agent-actions {
  margin-top: 16px;
  width: 100%;
  display: flex;
  gap: 18px;
  justify-content: center;
  align-items: center;
}
.agent-card .btn {
  background: #2d4e1e;
  color: #fff;
  border-radius: 20px;
  padding: 12px 28px;
  font-size: 1rem;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: background 0.2s;
  text-decoration: none;
  white-space: nowrap;
  min-width: 120px;
  max-width: 180px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
}
.agent-card .btn:hover {
  background: #3a6c28;
  color: #fff;
}

/* Modal */
.modal{
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.6);
  align-items:center; justify-content:center; z-index:2000;
  overflow-y: auto;
  padding: 20px;
}
.modal-content{
  background:#fff; padding:20px; border-radius:12px;
  width:100%; max-width:600px; text-align:left; position:relative;
  max-height: 90vh;
  overflow-y: auto;
}
.modal h2{ margin-top:0; }
.modal label{ display:block; margin-top:10px; font-size:14px; color:#333; font-weight: 500; }
.modal input, .modal textarea{
  width:100%; padding:10px; border:1px solid #ccc;
  border-radius:6px; margin-top:4px; font-family: inherit;
}
.modal button{
  margin-top:16px; padding:10px 14px;
  border:none; border-radius:8px; font-weight:600; cursor:pointer;
}
.modal .close{
  position:absolute; right:12px; top:12px;
  cursor:pointer; font-size:20px;
}

/* Agent grid layout – 3 in a row on desktop */
.agent-grid{
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 25px;
  margin-top: 20px;
}

.agent-card{
  /* override any old widths */
  width:auto;
  min-width:0;
  max-width:none;
  flex:0 0 calc(33.333% - 24px);
}

/* 2 per row on medium screens */
@media (max-width:1100px){
  .agent-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* 1 per row on small screens */
@media (max-width:720px){
  .agent-grid {
    grid-template-columns: 1fr;
  }
}

/* top nav specific */
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
}
.main-nav .nav-left { display:flex; align-items:center; gap:10px; }
.main-nav .nav-logo { width:52px; height:52px; border-radius:8px; background:transparent; padding:4px; margin-right:0; }
.company-name { font-size:1.5rem; font-weight:700; letter-spacing:0.5px; }
.nav-links { display:flex; gap:30px; list-style:none; margin:0; padding:0; }
.nav-links a {
  color:#fff; text-decoration:none; font-size:1rem; font-weight:500;
  padding:8px 0; position:relative; transition:color 0.18s;
}
.nav-links a:hover { color:#f4d03f; }
.nav-links a::after {
  content:''; position:absolute; width:0; height:2px; bottom:-5px; left:0;
  background-color:#f4d03f; transition:width 0.3s ease-out;
}
.nav-links a:hover::after { width:100%; }
.login-btn {
  background:#ffffff; color:#2d4e1e; font-weight:600;
  border-radius:20px; padding:10px 25px;
  text-decoration:none; font-size:1rem;
  transition:all 0.2s ease; border:none;
  box-shadow:0 4px 12px rgba(44,62,80,0.1);
}
.login-btn:hover {
  background:#f4d03f; color:#2d4e1e;
  box-shadow:0 6px 15px rgba(244, 208, 63, 0.4);
}
.nav-links li.active a {
  color:#f4d03f; font-weight:600;
}
.nav-links li.active a::after{
  content:''; position:absolute; bottom:-5px; left:0;
  width:100%; height:3px; background:#f4d03f; border-radius:2px;
}

/* Map Container */
.map-container {
  width: 100%;
  height: 300px;
  border-radius: 8px;
  margin-top: 12px;
  margin-bottom: 12px;
  overflow: hidden;
  border: 2px solid #ddd;
  background: #f0f0f0;
  display: block;
}

#locationMap {
  width: 100% !important;
  height: 100% !important;
  display: block;
}

.location-info {
  margin-top: 8px;
  padding: 10px;
  background: #e8f5e9;
  border-left: 4px solid #4caf50;
  border-radius: 4px;
  font-size: 12px;
  color: #2e7d32;
  font-weight: 500;
}

.geo-loader {
  display: none;
  font-size: 12px;
  color: #1976d2;
  margin-top: 4px;
  font-weight: 500;
}

.geo-loader.active {
  display: block;
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
    <li class="active"><a href="findagent.php">Find Agent</a></li>
    <li><a href="about.html">About</a></li>
    <li><a href="faqs.html">FAQs</a></li>
    <li><a href="contact.html">Contact</a></li>
  </ul>
  <div class="nav-right">
    <a href="Login/login.php" class="login-btn">Login</a>
  </div>
</nav>

<main>
  <div class="container">
    <?php if ($flash_ok): ?>
      <div class="flash ok"><?php echo h($flash_ok); ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
      <div class="flash err"><?php echo h($flash_err); ?></div>
    <?php endif; ?>

    <div class="head">
      <h1>Find an Agent</h1>
    </div>

    <form method="get" class="filters" id="filterForm">
      <div class="search-bar">
        <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search by name, email, mobile, or address"/>
      </div>
      <select name="city">
        <option value="">All Cities</option>
        <?php foreach($cities as $c): ?>
          <option value="<?php echo h($c); ?>" <?php if($c===$city) echo 'selected';?>><?php echo h($c); ?></option>
        <?php endforeach; ?>
      </select>
      <label class="chk" style="display:flex;align-items:center;gap:6px;">
        <input type="checkbox" name="available" value="1" <?php if($available) echo 'checked';?>> Show only available agents
      </label>
      <input type="hidden" name="clat" id="clat" value="<?php echo $clat!==null?h($clat):'';?>">
      <input type="hidden" name="clng" id="clng" value="<?php echo $clng!==null?h($clng):'';?>">
      <button class="btn primary" type="submit">Apply</button>
      <button class="btn secondary" type="button" onclick="location.href='findagent.php'">Reset</button>
    </form>

    <div style="height:40px;"></div>

    <!-- AGENT GRID -->
    <div class="agent-grid">
      <?php if (empty($agents)): ?>
        <p class="muted">No agents found.</p>
      <?php else: foreach($agents as $a):
        $photo='assets/s.png';
        if(!empty($a['profile_picture']) && file_exists($a['profile_picture'])) $photo=$a['profile_picture'];
        elseif(!empty($a['photo_fallback']) && file_exists('uploads/'.$a['photo_fallback'])) $photo='uploads/'.$a['photo_fallback'];
        $name = trim(($a['first_name']??'').' '.($a['last_name']??''));
        $addr = $a['address'] ?: $a['city'];
      ?>
        <div class="agent-card">
          <div class="agent-avatar">
            <img src="<?php echo h($photo); ?>" alt="Agent Photo">
          </div>
          <div class="agent-name"><?php echo h($name); ?></div>
          <div class="agent-meta">
            <?php echo h($a['mobile']); ?> &bull;
            <?php echo h($a['total_sales']); ?> Sales &bull;
            <?php echo h($a['experience']); ?> Years
          </div>
          <div class="agent-location"><?php echo h($addr); ?></div>
          <div class="agent-status"><?php echo ($a['is_available']??0) ? 'Available' : 'Unavailable'; ?></div>
          <div class="agent-actions">
            <a href="mailto:<?php echo h($a['email']); ?>" class="btn accent">Contact</a>
            <button class="btn primary" onclick="openModal(<?php echo (int)$a['id'];?>);return false;">
              Schedule Viewing
            </button>
          </div>
        </div>
      <?php endforeach; endif;?>
    </div>
  </div>
</main>

<!-- Viewing Modal -->
<div class="modal" id="viewingModal">
  <div class="modal-content">
    <span class="close" onclick="closeModal()">&times;</span>
    <h2>Schedule Viewing</h2>
    <form method="post" id="viewingForm">
      <input type="hidden" name="action" value="schedule_viewing">
      <input type="hidden" name="agent_id" id="modal_agent_id">
      <input type="hidden" name="client_lat" id="client_lat">
      <input type="hidden" name="client_lng" id="client_lng">

      <div style="display: flex; gap: 12px;">
        <div style="flex:1;">
          <label for="first_name">First Name</label>
          <input type="text" id="first_name" name="first_name" class="form-control" required>
        </div>
        <div style="flex:1;">
          <label for="middle_name">Middle Name (optional)</label>
          <input type="text" id="middle_name" name="middle_name" class="form-control" placeholder="(Optional)">
        </div>
      </div>
      <div style="display: flex; gap: 12px; margin-top: 12px;">
        <div style="flex:1;">
          <label for="last_name">Last Name</label>
          <input type="text" id="last_name" name="last_name" class="form-control" required>
        </div>
        <div style="flex:1;">
          <label for="client_email">Email</label>
          <input type="email" id="client_email" name="client_email" class="form-control" required>
        </div>
      </div>
      <div class="dropdown-row">
      <div style="display: flex; gap: 12px; margin-top: 12px;">
        <div style="flex:1;">
          <label for="client_phone">Phone</label>
          <input type="text" id="client_phone" name="client_phone" class="form-control">
        </div>
        <div style="flex:1;">
          <label for="location">Location/Address</label>
          <div style="position: relative;">
            <input type="text" id="location" name="location" class="form-control" placeholder="Click button to auto-fill" required>
            <div class="geo-loader" id="geoLoader"><i class="fas fa-spinner fa-spin"></i> Getting location...</div>
          </div>
          <button type="button" class="btn secondary" id="getLocationBtn" onclick="getClientLocation()" style="margin-top: 6px; font-size: 12px; padding: 8px 14px; min-width: auto; background: #2d7d2d; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: background 0.2s;">📍 Get My Location</button>
          <div class="location-info" id="geoInfo" style="display:none;"></div>
        </div>
      </div>

      <label style="margin-top: 12px; display: block;"><strong>Your Location Map</strong></label>
      <div class="map-container">
        <div id="locationMap"></div>
      </div>
      <label for="preferred_datetime" style="margin-top:12px;display:block;">Preferred Date/Time</label>
      <input type="datetime-local" id="preferred_datetime" name="preferred_datetime" class="form-control" required>
      <label for="notes" style="margin-top:12px;display:block;">Additional Notes (optional)</label>
      <textarea id="notes" name="notes" class="form-control"></textarea>

      <div class="form-actions" style="margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end;">
        <button type="button" onclick="closeModal()" class="btn secondary" style="background: #666; color: white; padding: 10px 20px; border-radius: 6px; cursor: pointer; border: none; font-weight: 600;">Cancel</button>
        <button type="submit" class="btn primary" style="background: #2d4e1e; color: white; padding: 10px 20px; border-radius: 6px; cursor: pointer; border: none; font-weight: 600;">Submit Request</button>
      </div>
    </form>
  </div>
</div>

<script>
// Global map variable
let locationMap = null;
let currentMarker = null;

// Dynamic lot/block selection
document.addEventListener('DOMContentLoaded', function() {
  const locationSelect = document.getElementById('location_select');
  const blockSelect = document.getElementById('block_number');
  const lotSelect = document.getElementById('lot_number');

  locationSelect.addEventListener('change', function() {
    const locationId = this.value;
    blockSelect.innerHTML = '<option value="">-- Select Block --</option>';
    lotSelect.innerHTML = '<option value="">-- Select Lot --</option>';
    blockSelect.disabled = true;
    lotSelect.disabled = true;
    if (!locationId) return;
    fetch('admin_properties.php?api=blocks&location_id=' + encodeURIComponent(locationId))
      .then(res => res.json())
      .then(data => {
        if (data && Array.isArray(data)) {
          data.forEach(block => {
            blockSelect.innerHTML += `<option value="${block.block_no}">${block.block_no}</option>`;
          });
          blockSelect.disabled = false;
        }
      });
  });

  blockSelect.addEventListener('change', function() {
    const locationId = locationSelect.value;
    const blockNo = this.value;
    lotSelect.innerHTML = '<option value="">-- Select Lot --</option>';
    lotSelect.disabled = true;
    if (!locationId || !blockNo) return;
    fetch('admin_properties.php?api=lots&location_id=' + encodeURIComponent(locationId) + '&block_no=' + encodeURIComponent(blockNo))
      .then(res => res.json())
      .then(data => {
        if (data && Array.isArray(data)) {
          data.forEach(lot => {
            lotSelect.innerHTML += `<option value="${lot.lot_no}">${lot.lot_no}</option>`;
          });
          lotSelect.disabled = false;
        }
      });
  });
});
// Reverse geocode coordinates to address using Nominatim (free service)
async function reverseGeocode(lat, lng) {
  try {
    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`, {
      headers: {
        'User-Agent': 'Nuevo-Puerta-RealEstate/1.0'
      }
    });
    
    if (!response.ok) throw new Error('Geocoding API error');
    
    const data = await response.json();
    
    // Try to construct a readable address
    if (data.address) {
      const addr = data.address;
      const parts = [];
      // House number and street
      if (addr.house_number) parts.push(addr.house_number);
      if (addr.road) parts.push(addr.road);
      // Barangay/suburb/village
      if (addr.suburb) parts.push(addr.suburb);
      else if (addr.barangay) parts.push(addr.barangay);
      else if (addr.village) parts.push(addr.village);
      // City/town
      if (addr.city) parts.push(addr.city);
      else if (addr.town) parts.push(addr.town);
      else if (addr.county) parts.push(addr.county);
      // Region/state
      if (addr.state) parts.push(addr.state);
      // Country
      if (addr.country) parts.push(addr.country);
      // Postcode
      if (addr.postcode) parts.push(addr.postcode);
      const address = parts.filter(Boolean).join(', ');
      return address || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
    }
    
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  } catch (err) {
    console.error('Reverse geocoding error:', err);
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  }
}

// Initialize or update the map
function initializeMap(lat, lng) {
  // Wait for the map container to be visible in the DOM
  const mapContainer = document.getElementById('locationMap');
  if (!mapContainer) {
    console.warn('Map container not found. Retrying...');
    setTimeout(() => initializeMap(lat, lng), 500);
    return;
  }

  try {
    if (!locationMap) {
      // Ensure container has proper dimensions
      mapContainer.style.width = '100%';
      mapContainer.style.height = '280px';
      
      locationMap = L.map('locationMap').setView([lat, lng], 16);
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
      }).addTo(locationMap);
      
      // Trigger map resize
      setTimeout(() => {
        if (locationMap) locationMap.invalidateSize();
      }, 100);
    } else {
      locationMap.setView([lat, lng], 16);
      locationMap.invalidateSize();
    }

    // Remove old marker if exists
    if (currentMarker) {
      locationMap.removeLayer(currentMarker);
    }

    // Add new marker with custom styling
    currentMarker = L.marker([lat, lng], {
      icon: L.icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
      })
    }).addTo(locationMap).bindPopup(
      '<div style="text-align:center;"><strong>Your Location</strong><br>Lat: ' + lat.toFixed(6) + '<br>Lng: ' + lng.toFixed(6) + '</div>'
    ).openPopup();
    
    console.log('Map initialized successfully at:', lat, lng);
  } catch (err) {
    console.error('Error initializing map:', err);
  }
}

// Get client's current location
async function getClientLocation() {
  const geoLoader = document.getElementById('geoLoader');
  const geoInfo = document.getElementById('geoInfo');
  const locationField = document.getElementById('location');
  const getBtn = document.getElementById('getLocationBtn');

  if (!geoLoader || !geoInfo || !locationField || !getBtn) {
    console.error('Required form elements not found');
    alert('Form elements missing. Please try again.');
    return;
  }

  geoLoader.classList.add('active');
  getBtn.disabled = true;

  if (!navigator.geolocation) {
    alert('Geolocation is not supported by your browser');
    geoLoader.classList.remove('active');
    getBtn.disabled = false;
    return;
  }

  navigator.geolocation.getCurrentPosition(
    async (position) => {
      try {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const accuracy = Math.round(position.coords.accuracy);

        console.log('Geolocation success:', lat, lng, accuracy);

        // Store in hidden fields
        document.getElementById('client_lat').value = lat.toFixed(6);
        document.getElementById('client_lng').value = lng.toFixed(6);

        // Show accuracy info
        geoInfo.innerHTML = `✓ Accuracy: ${accuracy}m | Lat: ${lat.toFixed(6)} | Lng: ${lng.toFixed(6)}`;
        geoInfo.style.display = 'block';

        // Reverse geocode to get address
        console.log('Starting reverse geocoding...');
        const address = await reverseGeocode(lat, lng);
        console.log('Reverse geocoding result:', address);
        
        locationField.value = address;
        locationField.dispatchEvent(new Event('change', { bubbles: true }));

        // Initialize map
        console.log('Initializing map...');
        initializeMap(lat, lng);

        geoLoader.classList.remove('active');
        getBtn.disabled = false;
        
        console.log('Location capture complete');
      } catch (err) {
        console.error('Error in geolocation success callback:', err);
        geoLoader.classList.remove('active');
        getBtn.disabled = false;
        alert('Error processing location data');
      }
    },
    (error) => {
      geoLoader.classList.remove('active');
      getBtn.disabled = false;

      let errorMsg = 'Unable to get your location. ';
      if (error.code === 1) errorMsg += 'Permission denied. Please enable location access in your browser settings.';
      else if (error.code === 2) errorMsg += 'Position unavailable. Try again or use another device.';
      else if (error.code === 3) errorMsg += 'Request timeout. Please try again.';

      console.error('Geolocation error:', error);
      alert(errorMsg);
      geoInfo.innerHTML = `⚠ ${errorMsg}`;
      geoInfo.style.display = 'block';
    },
    {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 0
    }
  );
}

/* Modal functions */
function openModal(agentId) {
  const modal = document.getElementById('viewingModal');
  if (!modal) {
    console.error('Modal element not found');
    return;
  }

  document.getElementById('modal_agent_id').value = agentId;
  modal.style.display = 'flex';

  // Auto-get location when modal opens, with delay to ensure DOM is ready
  setTimeout(() => {
    const locationField = document.getElementById('location');
    if (locationField && !locationField.value) {
      console.log('Modal opened, auto-fetching location...');
      getClientLocation();
    }
  }, 300);
}

function closeModal() {
  document.getElementById('viewingModal').style.display = 'none';
}

window.onclick = function(e) {
  const modal = document.getElementById('viewingModal');
  if (e.target == modal) closeModal();
}
</script>
</body>
</html>
