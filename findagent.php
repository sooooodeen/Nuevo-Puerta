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
$hasGeo = $hasAvail = false;

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

/* ============================================================
   Distinct cities
============================================================ */
$cities = [];
$cityRes = $conn->query("
  SELECT DISTINCT
    CASE
      WHEN city IS NOT NULL AND city <> '' THEN city
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
if ($hasAvail) {
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
  $sql .= " AND (aa.city = ? OR aa.address LIKE ?)";
  $params[] = $city;
  $params[] = "%{$city}%";
  $types   .= 'ss';
}

/* Available only */
if ($available) {
  if ($hasAvail) {
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
<style>
:root { --green:#2d4e1e; --ink:#222; --muted:#6b7280; --bg:#e3e2e2; --navH: 88px; }
*{ box-sizing:border-box; }
html,body{ margin:0; padding:0; }
body{
  font-family:'Inter','Roboto',Arial,Helvetica,sans-serif;
  font-size:16px; line-height:1.6;
  background:var(--bg); color:var(--ink);
  text-align:center;
}

/* NAVBAR */
nav{
  height:var(--navH);
  background:var(--green);
  padding:10px 20px;
  display:flex; align-items:center; justify-content:space-between;
  position:fixed; inset:0 0 auto 0; z-index:1000;
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
  max-width:1200px; margin:0 auto; background:#fff; border-radius:12px;
  box-shadow:0 10px 6px rgba(0,0,0,.06); padding:28px;
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
  background: #f9fbf7;
  border-radius: 16px;
  padding: 14px 10px;
  margin-bottom: 18px;
  border: 2px solid #eaf7e1;
  box-shadow: 0 8px 32px rgba(44,62,80,0.18), 0 1.5px 8px rgba(44,62,80,0.10);
  transition: box-shadow 0.25s, border-color 0.25s, transform 0.18s;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
}
.agent-card:hover {
  border-color: #205c20;
  box-shadow: 0 16px 48px rgba(44,62,80,0.22), 0 3px 16px rgba(44,62,80,0.14);
  transform: translateY(-6px) scale(1.02);
  z-index: 2;
}
.agent-card .agent-avatar {
  margin-top: 24px;
  margin-bottom: 12px;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  overflow: hidden;
  background: #f6f6f6;
  border: 3px solid #205c20;
  box-shadow: 0 2px 8px rgba(44,62,80,0.12);
}
.agent-card .agent-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.agent-card .agent-name {
  font-size: 1rem;
  font-weight: 800;
  color: #205c20;
  margin-bottom: 4px;
  letter-spacing: 0.5px;
}
.agent-card .agent-meta {
  font-size: 0.85rem;
  color: #444;
  margin-bottom: 2px;
  text-align: center;
}
.agent-card .agent-location {
  font-size: 0.85rem;
  color: #666;
  margin-bottom: 6px;
  text-align: center;
}
.agent-card .agent-status {
  font-size: 0.90rem;
  font-weight: 700;
  color: #207c20;
  background: #dbead1ff;
  border-radius: 14px;
  padding: 2px 12px;
  margin-bottom: 10px;
  display: inline-block;
  box-shadow: 0 1px 4px rgba(44,62,80,0.08);
}
.agent-card .agent-actions {
  margin-top: 8px;
  width: 100%;
  display: flex;
  gap: 8px;
  justify-content: center;
}
.agent-card .btn {
  background: #205c20;
  color: #fff;
  border-radius: 8px;
  padding: 4px 14px;
  font-size: 0.90rem;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: background .2s;
  text-decoration: none;
  white-space: nowrap;
  min-width: 0;
  max-width: 140px;
  box-sizing: border-box;
}
.agent-card .btn:hover {
  background: #f4d03f;
}

/* Modal */
.modal{
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.6);
  align-items:center; justify-content:center; z-index:2000;
}
.modal-content{
  background:#fff; padding:16px; border-radius:12px;
  width:100%; max-width:480px; text-align:left; position:relative;
}
.modal h2{ margin-top:0; }
.modal label{ display:block; margin-top:10px; font-size:14px; color:#333; }
.modal input, .modal textarea{
  width:100%; padding:10px; border:1px solid #ccc;
  border-radius:6px; margin-top:4px;
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
  display:flex;
  flex-wrap:wrap;
  gap:24px;
  justify-content:center;
  margin-top:20px;
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
  .agent-card{
    flex:0 0 calc(50% - 24px);
  }
}

/* 1 per row on small screens */
@media (max-width:720px){
  .agent-card{
    flex:0 0 100%;
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
</style>
</head>
<body>
<nav class="main-nav">
  <div class="nav-left">
    <img src="assets/f.png" alt="Logo" class="nav-logo">
    <span class="company-name">El Nuevo Puerta Real Estate</span>
  </div>
  <ul class="nav-links">
    <li><a href="index.html">Home</a></li>
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
        <input type="checkbox" name="available" value="1" <?php if($available) echo 'checked';?>> Available only
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
      <div style="display: flex; gap: 12px; margin-top: 12px;">
        <div style="flex:1;">
          <label for="client_phone">Phone</label>
          <input type="text" id="client_phone" name="client_phone" class="form-control">
        </div>
        <div style="flex:1;">
          <label for="location">Location</label>
          <input type="text" id="location" name="location" class="form-control" required>
        </div>
      </div>
      <div style="display: flex; gap: 12px; margin-top: 12px;">
        <div style="flex:1;">
          <label for="block_number">Block Number</label>
          <input type="text" id="block_number" name="block_number" class="form-control" required>
        </div>
        <div style="flex:1;">
          <label for="lot_number">Lot Number</label>
          <input type="text" id="lot_number" name="lot_number" class="form-control" required>
        </div>
      </div>
      <label for="preferred_datetime" style="margin-top:12px;display:block;">Preferred Date/Time</label>
      <input type="datetime-local" id="preferred_datetime" name="preferred_datetime" class="form-control" required>
      <label for="notes" style="margin-top:12px;display:block;">Additional Notes (optional)</label>
      <textarea id="notes" name="notes" class="form-control"></textarea>

      <div class="form-actions" style="margin-top:10px;">
        <button type="submit" class="btn primary">Submit</button>
      </div>
    </form>
  </div>
</div>

<script>
// Geolocation on filter (if you add a button with id="geoBtn")
document.getElementById('geoBtn')?.addEventListener('click', ()=>{
  if(!navigator.geolocation){alert('Geolocation not supported');return;}
  navigator.geolocation.getCurrentPosition(pos=>{
    document.getElementById('clat').value=pos.coords.latitude.toFixed(6);
    document.getElementById('clng').value=pos.coords.longitude.toFixed(6);
    document.getElementById('filterForm').submit();
  },()=>alert('Unable to get your location.'),{enableHighAccuracy:true,timeout:8000});
});

/* Modal functions */
function openModal(agentId){
  document.getElementById('modal_agent_id').value=agentId;
  if(navigator.geolocation){
    navigator.geolocation.getCurrentPosition(pos=>{
      document.getElementById('client_lat').value=pos.coords.latitude.toFixed(6);
      document.getElementById('client_lng').value=pos.coords.longitude.toFixed(6);
    });
  }
  document.getElementById('viewingModal').style.display='flex';
}
function closeModal(){
  document.getElementById('viewingModal').style.display='none';
}
window.onclick=function(e){
  if(e.target==document.getElementById('viewingModal')) closeModal();
}
</script>
</body>
</html>
