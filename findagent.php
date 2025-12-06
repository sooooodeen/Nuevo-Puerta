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
   - Auto-creates a simple 'viewings' table if it doesn't exist
============================================================ */
$flash_ok = $flash_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='schedule_viewing') {
  $agentId   = (int)($_POST['agent_id'] ?? 0);
  $clientFn  = trim($_POST['client_first_name'] ?? '');
  $clientLn  = trim($_POST['client_last_name'] ?? '');
  $clientEm  = trim($_POST['client_email'] ?? '');
  $clientPh  = trim($_POST['client_phone'] ?? '');
  $lotNo     = trim($_POST['lot_no'] ?? '');
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

    // bind with nullables
    $stmt->bind_param(
      "issssssdd",
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
   Distinct "cities" (we’ll derive from address & city columns)
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
  // Prefer same city first if user selected a city
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
body{ font-family:'Inter','Roboto',Arial,Helvetica,sans-serif; font-size:16px; line-height:1.6; background:var(--bg); color:var(--ink); text-align:center; }

  /* ==== NAVBAR ==== */
    nav{
      height:var(--navH);
      background:var(--green);
      padding:10px 20px;
      display:flex; align-items:center; justify-content:space-between;
      position:fixed; inset:0 0 auto 0; z-index:1000;
      box-shadow:0 4px 6px rgba(0,0,0,.1);
    }
    .nav-left,.nav-right{ list-style:none; display:flex; gap:40px; margin:0; padding:0; align-items:center; }
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
      position: static; /* Remove absolute positioning */
    }
    .nav-logo img{ width:80px; height:auto; display:block; }

    /* ==== Page Layout ==== */
    main{ padding: calc(var(--navH) + 24px) 16px 48px; }
    .container{
      max-width:1200px; margin:0 auto; background:#fff; border-radius:12px;
      box-shadow:0 10px 6px rgba(0,0,0,.06); padding:28px;
    }
    .hero{ text-align:center; margin-bottom:16px; }
    .hero h1{ font-size:35px; margin-bottom: -5px; margin-top:-5px;}
    .hero p{ font-size: 20px; margin:0; color:var(--muted); }

    .grid{ display:grid; gap:28px; grid-template-columns:1.2fr .8fr; margin-top:20px; }
    @media (max-width:980px){ .grid{ grid-template-columns:1fr; } }

    /* Form */
    .contact-form{ display:grid; 
        gap:14px; }
    .field label{ display:block; font-weight:600; margin-bottom:6px; font-size: 15px; }
    .field input,.field textarea{
      width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; outline:none; background:#fff;
    }
    .field input:focus,.field textarea:focus{ border-color:#9ca3af; }
    .btn-primary{ background:var(--green); color:#fff; border:0; border-radius:10px; padding:12px 16px; cursor:pointer; font-weight:600; }
    .btn-primary:hover{ filter:brightness(1.05); }
    .hp{ position:absolute !important; left:-9999px !important; opacity:0 !important; }

    /* Info */
    .info h3{ margin:0 0 10px; }
    .info ul{ list-style:none; padding:0; margin:0 0 12px; }
    .info li{ margin:6px 0; }
    .info a{ color:var(--green); text-decoration:none; }
    .social a{ margin-right:12px; }

     
/* ---------------------------------- */
/* 2. Navigation */
/* ---------------------------------- */
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
.nav-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.nav-logo {
    width: 52px;
    height: 52px;
    border-radius: 8px;
    object-fit: contain;
    background: transparent;
    padding: 4px;
    margin-right: 0;
}
.company-name {
    font-size: 1.5rem; 
    font-weight: 700;
    letter-spacing: 0.5px;
}
.nav-links {
    display: flex;
    gap: 30px; 
    list-style: none;
    margin: 0;
    padding: 0;
}
.nav-links a {
    color: #fff;
    text-decoration: none;
    font-size: 1rem;
    font-weight: 500;
    padding: 8px 0;
    transition: color 0.18s;
    position: relative;
}
.nav-links a:hover {
    color: #f4d03f;
}
.nav-links a::after {
    content: '';
    position: absolute;
    width: 0;
    height: 2px;
    bottom: -5px;
    left: 0;
    background-color: #f4d03f;
    transition: width 0.3s ease-out;
}
.nav-links a:hover::after {
    width: 100%;
}
.login-btn {
    background: #ffffff;
    color: #2d4e1e;
    font-weight: 600;
    border-radius: 20px; 
    padding: 10px 25px;
    text-decoration: none;
    font-size: 1rem;
    transition: all 0.2s ease;
    border: none;
    box-shadow: 0 4px 12px rgba(44,62,80,0.1);
}
.login-btn:hover {
    background: #f4d03f;
    color: #2d4e1e;
    box-shadow: 0 6px 15px rgba(244, 208, 63, 0.4);
}

/* ---------------------------------- */
/* 3. Header (Hero Section) - ADJUSTED HEIGHT */
/* ---------------------------------- */
/* 3. Header (Hero Section) - MAX HEIGHT GUARANTEED */
/* ---------------------------------- */
header {
    text-align: center;
    color: white; 
    position: relative;
    /* Use min-height to force the header to occupy at least 70% of the screen height */
    min-height: 40vh; 
    
    /* Ensure content is centered vertically within this large space */
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    
    /* Image and overlay properties */
    background: url('assets/g.jpg') center/cover no-repeat; 
    position: relative;
    z-index: 1; 
    padding: 20px; /* Reduced padding since min-height controls the size */
}

/* Add a subtle overlay to improve text readability */
header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.35); /* Slightly darker overlay for contrast */
    z-index: -1; 
}

/* Ensure the text inside the header maintains its styling */
header h1 {
    font-size: 2.8em; 
    font-weight: 800; 
    margin-bottom: 0.3em; 
    text-shadow: 2px 2px 8px rgba(0,0,0,0.4); 
    line-height: 1.15;
}
header div { /* For the sub-heading/slogan */
    font-size: 1.5em; 
    font-weight: 400;
    margin-bottom: 0; /* Remove bottom margin since height is controlled by flexbox */
    margin-top: 0.5em; 
    text-shadow: 1px 1px 6px rgba(0,0,0,0.3);
}

/* Responsive adjustment */
@media (max-width: 768px) {
    header {
        min-height: 50vh; /* Adjust height for smaller screens */
    }
    header h1 {
        font-size: 2em !important; 
    }
    header div {
        font-size: 1.1em !important; 
    }
}


/* FILTERS */
.head{ display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-left:16px; margin-bottom:8px; }
.head h1{ margin:0; font-size:40px; }
.filters{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:center; margin-top:10px; }
.search-bar{ display:flex; align-items:center; border:2px solid var(--green); border-radius:10px; overflow:hidden; min-width:230px; max-width:400px; width:100%; }
.search-bar input{ flex:1; border:none; padding:12px; font-size:16px; outline:none; }
select, .chk { padding:12px; border:2px solid var(--green); border-radius:10px; background:#fff; }
.btn{ background:var(--green); color:#fff; border:0; border-radius:10px; padding:12px 24px; cursor:pointer; font-weight:600; font-size:14px; transition: background 0.15s, box-shadow 0.15s; box-shadow: 0 2px 8px rgba(44,62,80,0.08); margin: 0 2px; min-width: 110px; }
.btn.primary { background: #2d4e1e; }
.btn.secondary { background: #334155; }
.btn.accent { background: #f4d03f; color: #222; }
.btn:hover, .btn:focus { filter: brightness(1.08); box-shadow: 0 4px 16px rgba(44,62,80,0.12); }

/* Reduce the width of the search input */
.agent-search-input {
  width: 340px; /* or your preferred width */
  max-width: 100%;
}

/* Reduce gap between filter buttons */
.filters {
  gap: 8px !important;
}
.filters .btn {
  margin: 0 0px !important;
}

/* CARDS */
.list{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin-top:12px; }
@media (max-width:1100px){ .list{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:720px){ .list{ grid-template-columns:1fr; } }

.card {
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 4px 24px rgba(44,62,80,0.10), 0 1.5px 8px rgba(44,62,80,0.08);
  padding: 32px 20px 24px 20px;
  width: 320px;
  min-height: 340px;
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: box-shadow 0.2s, transform 0.2s;
  position: relative;
  border: 1px solid #f2f2f2;
  margin: 0 auto;
}

.card:hover {
  box-shadow: 0 12px 40px rgba(44,62,80,0.18), 0 3px 16px rgba(44,62,80,0.12);
  transform: translateY(-6px) scale(1.03);
}

.avatar img {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #e6e6e6;
  margin-bottom: 12px;
}

.info {
  text-align: center;
  width: 100%;
}

.info h3 {
  margin: 0 0 6px 0;
  font-size: 20px;
  color: #2d4e1e;
  font-weight: 700;
}

.stats-row {
  display: flex;
  justify-content: center;
  gap: 18px;
  margin: 10px 0 8px 0;
}

.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 13px;
  color: #444;
}

.stat-label {
  font-size: 12px;
  color: #888;
}

.badges {
  display: flex;
  gap: 8px;
  justify-content: center;
  margin: 8px 0;
}

.badge {
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 999px;
  border: 1px solid #e5e7eb;
  background: #f8fafc;
}

.badge.ok {
  border-color: #16a34a;
  color: #166534;
  background: #ecfdf5;
}

.badge.off {
  border-color: #9ca3af;
  color: #374151;
  background: #f3f4f6;
}

.btn {
  background: #2d4e1e;
  color: #fff;
  border: 0;
  border-radius: 10px;
  padding: 12px 24px;
  cursor: pointer;
  font-weight: 600;
  font-size: 16px;
  transition: background 0.15s, box-shadow 0.15s;
  box-shadow: 0 2px 8px rgba(44,62,80,0.08);
  margin: 0 2px;
  min-width: 110px;
}

.btn.primary {
  background: #2d4e1e;
}

.btn.secondary {
  background: #334155;
}

.btn.accent {
  background: #f4d03f;
  color: #222;
}

.btn:hover, .btn:focus {
  filter: brightness(1.08);
  box-shadow: 0 4px 16px rgba(44,62,80,0.12);
}

/* MODAL */
.modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); align-items:center; justify-content:center; z-index:2000; }
.modal-content{ background:#fff; padding:16px; border-radius:12px; width:100%; max-width:480px; text-align:left; position:relative; }
.modal h2{ margin-top:0; }
.modal label{ display:block; margin-top:10px; font-size:14px; color:#333; }
.modal input{ width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; margin-top:4px; }
.modal button{ margin-top:16px; padding:10px 14px; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
.modal .close{ position:absolute; right:12px; top:12px; cursor:pointer; font-size:20px; }
.flash{ padding:10px; border-radius:6px; margin-bottom:12px; font-size:14px; }
.flash.ok{ background:#ecfdf5; color:#166534; }
.flash.err{ background:#fef2f2; color:#991b1b; }


list, .container > div[style*="flex-wrap"] {
  display: grid !important;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  justify-items: center;
  margin-top: 15px;
}

.agent-card {
  background: #f9fbf7;
  border-radius: 16px;
  padding: 14px 10px;
  margin-bottom: 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 250px;
  max-width: 310px;
  border: 2px solid #eaf7e1;
  box-shadow: 0 8px 32px rgba(44,62,80,0.18), 0 1.5px 8px rgba(44,62,80,0.10);
  transition: box-shadow 0.25s, border-color 0.25s, transform 0.18s;
  width: 320px;
  min-width: 320px;
  max-width: 320px;
  min-height: 310px;
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
/* Add space between the top of the agent card and the photo */
.agent-card .agent-avatar {
  margin-top: 24px;
  margin-bottom: 12px;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  overflow: hidden;
  background: #f6f6f6;
  border: 3px solid #205c20; /* Consistent green border */
  box-shadow: 0 2px 8px rgba(44,62,80,0.12);
}
.agent-card .agent-name {
  font-size: 1.0rem;
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
  border-radius: px;
  padding: 4px 14px;
  font-size: 0.90rem;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: background .2s;
  text-decoration: none;
  white-space: nowrap; /* Force single line */
  min-width: 0;
  max-width: 140px;
  box-sizing: border-box;
}
.agent-card .btn:hover {
  background: #f4d03f;
}
@media (max-width:1100px){
  .agent-card {
    width: 95vw;
    min-width: 0;
    max-width: 100%;
  }
}
@media (max-width:720px){
  .agent-card {
    width: 98vw;
    min-width: 0;
    max-width: 100%;
  }
}
/* Reduce gap between notes box and button */
.form-actions {
  margin-top: 4px;
}

/* Subtle scrollbar for notes textarea when resized larger */
#notes {
  width: 100%;
  min-height: 60px;
  max-height: 130px;
  resize: vertical;
  box-sizing: border-box;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: #cceccc #f8f8f8;
}

/* For Webkit browsers (Chrome, Edge, Safari) */
#notes::-webkit-scrollbar {
  height: 8px;
  width: 8px;
  background: #f8f8f8;
}
#notes::-webkit-scrollbar-thumb {
  background: #cceccc;
  border-radius: 8px;
}

/* Smaller filter/search bar */
.search-bar input,
select,
.chk {
  font-size: 0.95rem;
  padding: 7px 8px;
}
.btn {
  font-size: 0.95rem;
  padding: 7px 12px;
  min-width: 60px;
  border-radius: 7px;
}
.head h1 {
  font-size: 1.5rem;
}
.list {
  gap: 32px;
  margin-top: 24px;
}
/* Responsive: stack cards on small screens */
@media (max-width:1100px){
  .list, .container > div[style*="flex-wrap"] {
    grid-template-columns: repeat(2, 1fr);
  }
  .agent-card {
    width: 95vw;
    min-width: 0;
    max-width: 100%;
  }
}
@media (max-width:720px){
  .list, .container > div[style*="flex-wrap"] {
    grid-template-columns: 1fr;
  }
  .agent-card {
    width: 98vw;
    min-width: 0;
    max-width: 100%;
  }
}



/* Active link color */
.nav-links li.active a {
    color: #f4d03f; /* Gold text color */
    font-weight: 600;
}

/* Add the decorative line/bar */
.nav-links li.active a::after {
    content: '';
    position: absolute;
    bottom: -5px; /* Adjust this value to control distance from text */
    left: 0;
    width: 100%;
    height: 3px; /* Thickness of the line */
    background: #f4d03f; /* Gold line color */
    border-radius: 2px;
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
      <?php if ($flash_ok): ?><div class="flash ok"><?php echo h($flash_ok); ?></div><?php endif; ?>
      <?php if ($flash_err): ?><div class="flash err"><?php echo h($flash_err); ?></div><?php endif; ?>

      <div class="head">
        <h1>Find an Agent</h1>
      </div>
      <form method="get" class="filters" id="filterForm" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 10px;">
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

      <div style="height:40px;"></div> <!-- spacer -->

      <div style="display:flex;flex-wrap:wrap;gap:24px;justify-content:center;">
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
      <img src="<?php echo h($photo); ?>" alt="Agent Photo" style="width:100%;height:100%;object-fit:cover;">
    </div>
    <div class="agent-name"><?php echo h($name); ?></div>
    <div class="agent-meta"><?php echo h($a['mobile']); ?> &bull; <?php echo h($a['total_sales']); ?> Sales &bull; <?php echo h($a['experience']); ?> Years</div>
    <div class="agent-location"><?php echo h($addr); ?></div>
    <div class="agent-status"><?php echo ($a['is_available']??0) ? 'Available' : 'Unavailable'; ?></div>
    <div class="agent-actions" style="display:flex;gap:8px;justify-content:center;margin-top:10px;">
      <a href="mailto:<?php echo h($a['email']); ?>"
         class="btn accent"
         style="display:flex;align-items:center;justify-content:center;text-align:center;font-weight:600;border-radius:10px;font-size:0.90rem;padding:4px 20px;">
        Contact
      </a>
      <button class="btn primary"
              style="display:flex;align-items:center;justify-content:center;text-align:center;font-weight:600;border-radius:10px;font-size:0.90rem;padding:4px 25px;"
              onclick="openModal(<?php echo (int)$a['id'];?>);return false;">
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

        <!-- First Name and Last Name in one line -->
        <div style="display: flex; gap: 12px;">
          <div style="flex:1;">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" class="form-control" required>
          </div>
          <div style="flex:1;">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" class="form-control" required>
          </div>
        </div>

        <label>Email<input type="email" name="client_email" required></label>
        <label>Phone<input type="text" name="client_phone"></label>
        <label for="location">Location</label>
        <input type="text" id="location" name="location" class="form-control" required>
        <div style="display: flex; gap: 12px;">
          <div style="flex:1;">
            <label for="block_number">Block Number</label>
            <input type="text" id="block_number" name="block_number" class="form-control" required>
          </div>
          <div style="flex:1;">
            <label for="lot_number">Lot Number</label>
            <input type="text" id="lot_number" name="lot_number" class="form-control" required>
          </div>
        </div>
        <label for="preferred_datetime">Preferred Date/Time</label>
        <input type="datetime-local" id="preferred_datetime" name="preferred_datetime" class="form-control" required>
        <label for="notes">Additional Notes (optional)</label>
        <textarea id="notes" name="notes" class="form-control"></textarea>

        <div class="form-actions">
          <button type="submit" class="btn">Submit</button>
        </div>
      </form>
    </div>
  </div>

<script>
/* Geolocation button */
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
  // auto-fill client coords if we have them
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

