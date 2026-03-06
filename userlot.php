<?php
$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "nuevopuerta";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch lot locations
$locations = [];
$sql = "SELECT id, location_name, latitude, longitude FROM lot_locations";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
}

// Fetch all lots grouped by location_id
$all_lots = [];
$sql = "SELECT id, block_number, lot_number, lot_size, lot_price, location_id, status AS lot_status, coordinates FROM lots";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_lots[$row['location_id']][] = $row;
    }
}

// Fetch all blueprints by location_id
$blueprints = [];
$sql = "SELECT location_id, filename FROM blueprints";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $blueprints[$row['location_id']] = 'blueprints/' . $row['filename'];
    }
}

// AJAX endpoint to fetch pin locations for a specific location
if (isset($_GET['fetch_pins']) && isset($_GET['location_id'])) {
    $location_id = intval($_GET['location_id']);
    
    $pins = [];
    $sql = "SELECT p.lot_id, p.polygon_coordinates, p.pin_status, l.block_number, l.lot_number
            FROM pin_locations p
            INNER JOIN lots l ON l.id = p.lot_id
            WHERE l.location_id = $location_id";
    
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pins[] = [
                'lot_id' => (int)$row['lot_id'],
                'block_number' => $row['block_number'],
                'lot_number' => $row['lot_number'],
                'coordinates' => json_decode($row['polygon_coordinates'], true),
                'pin_status' => $row['pin_status']
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'pins' => $pins]);
    $conn->close();
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>El Nuevo Puerta - View Lots</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
/* ---------------------------------- */
/* 1. General Styles */
/* ---------------------------------- */
body { font-family: 'Poppins', sans-serif; font-size: 16px; line-height: 1.6; margin: 0; padding: 0; background-color: #f8f8f8; color: #333; }

/* ---------------------------------- */
/* 2. Navigation */
/* ---------------------------------- */
.main-nav { background: #2d4e1e; color: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 80px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 1000; }
.nav-left { display: flex; align-items: center; gap: 10px; }
.nav-logo { width: 52px; height: 52px; border-radius: 8px; object-fit: contain; padding: 4px; }
.company-name { font-size: 1.5rem; font-weight: 700; letter-spacing: 0.5px; }
.nav-links { display: flex; gap: 30px; list-style: none; margin: 0; padding: 0; }
.nav-links a { color: #fff; text-decoration: none; font-size: 1rem; font-weight: 500; padding: 8px 0; position: relative; }
.nav-links a:hover { color: #f4d03f; }
.nav-links li.active a { color: #f4d03f; font-weight: 600; }
.login-btn { background: #ffffff; color: #2d4e1e; font-weight: 600; border-radius: 20px; padding: 10px 25px; text-decoration: none; box-shadow: 0 4px 12px rgba(44,62,80,0.1); }
.login-btn:hover { background: #f4d03f; color: #2d4e1e; }

/* Add margin to main content so it's not hidden behind navbar */
.adminlots-main {
  margin-top: 90px;
}
.adminlots-main {
  display: flex;
  height: calc(100vh - 70px);
  padding: 20px;
  gap: 20px;
  box-sizing: border-box;
  margin-top: -5px;
}
.map-panel {
  flex: 1.8;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  padding: 20px;
  display: flex;
  flex-direction: column;
}
#map {
  flex: 1;
  height: calc(100vh - 90px);
  min-height: 350px;
  border-radius: 8px;
}
.info-panel {
  flex: 1.5;
  background: #f8f9fa;
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  padding: 20px;
  display: flex;
  flex-direction: column;
  min-width: 440px;
}
.info-panel h3 {
  margin: 0 0 10px 0;
  font-size: 1.1em;
  color: #2d4e1e;
}
.info-panel .blueprint-btn {
  background: linear-gradient(135deg, #2d6a1e 0%, #3a8c28 100%);
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px 22px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.25s ease;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 2px 8px rgba(45,106,30,0.18);
  letter-spacing: 0.2px;
}
.info-panel .blueprint-btn:hover {
  background: linear-gradient(135deg, #245a16 0%, #2d6a1e 100%);
  box-shadow: 0 4px 16px rgba(45,106,30,0.3);
  transform: translateY(-1px);
}
.info-panel .blueprint-btn:active {
  transform: translateY(0);
  box-shadow: 0 2px 6px rgba(45,106,30,0.15);
}

/* Blueprint Modal Styles */
.modal {
  display: none;
  position: fixed;
  z-index: 2000;
  left: 0; top: 0;
  width: 100%; height: 100%;
  overflow: auto;
  background: rgba(0,0,0,0.7);
}
.blueprint-white-bg {
  position: relative;
  display: inline-block;
}
.blueprint-canvas {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 10;
  transition: transform 0.2s;
  cursor: zoom-in;
}
.modal-content {
  display: block;
  margin: 0;
  max-width: 90vw;
  max-height: 80vh;
  border-radius: 8px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.4);
}
/* Zoomable image styles */
.modal-content {
  transition: transform 0.2s;
  cursor: zoom-in;
}
.modal-content.zoomed {
  cursor: grab;
  transform: scale(2);
  transition: transform 0.2s;
}
.close {
  position: fixed;
  top: 18px;
  right: 24px;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.55);
  color: #fff;
  font-size: 24px;
  font-weight: 400;
  line-height: 1;
  border: 1.5px solid rgba(255,255,255,0.25);
  border-radius: 50%;
  cursor: pointer;
  z-index: 2100;
  transition: background 0.2s, transform 0.2s;
}
.close:hover {
  background: rgba(220,53,69,0.85);
  transform: scale(1.1);
}

.blueprint-white-bg {
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  max-width: 90vw;
  max-height: 80vh;
  margin: 40px auto;
  border-radius: 10px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.12);
  overflow: hidden;
}
.blueprint-image-container {
  position: relative;
  display: inline-block;
}

@media (max-width: 600px) {
  .modal-content { max-width: 98vw; }
  .close { right: 20px; top: 10px; font-size: 32px; }
}

/* Pin Location Legend */
.pin-legend {
  position: absolute;
  top: 80px;
  left: 50px;
  background: rgba(255, 255, 255, 0.95);
  padding: 15px;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.2);
  z-index: 2100;
  font-size: 14px;
}
.pin-legend h4 {
  margin: 0 0 10px 0;
  font-size: 16px;
  color: #2d4e1e;
}
.pin-legend-item {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 5px;
}
.pin-legend-color {
  width: 20px;
  height: 20px;
  border-radius: 3px;
  border: 2px solid;
}

.lots-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
  font-size: 15px;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.lots-table th, .lots-table td {
  padding: 8px 10px;
  text-align: center;
  border-bottom: 1px solid #e0e0e0;
}
.lots-table th {
  background: #e8f5e9;
  color: #2d4e1e;
  font-weight: bold;
}
.lots-table tr:last-child td {
  border-bottom: none;
}
.lot-status {
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: bold;
  color: #fff;
  display: inline-block;
}
.lot-status.sale { background: #3a6c28; }
.lot-status.sold { background: #b71c1c; }
.lot-status.reserved { background: #f4d03f; color: #2d4e1e; }
.inquire-btn {
  background: #3a6c28;
  color: #fff;
  border: none;
  border-radius: 5px;
  padding: 4px 12px;
  font-size: 13px;
  cursor: pointer;
  transition: background 0.2s;
}
.inquire-btn:hover {
  background: #2d4e1e;
}

/* ---------------------------------- */
/* 6. Viewing Modal & Forms */
/* ---------------------------------- */
.viewing-modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); }
.viewing-modal-content { background: white; margin: 2% auto; padding: 0; border-radius: 15px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
.viewing-modal-header { background: #2d4e1e; padding: 20px; color: white; display: flex; justify-content: space-between; align-items: center; }
.viewing-modal-body { padding: 20px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
.form-group { display: flex; flex-direction: column; }
.full-width { grid-column: 1 / -1; }
.form-input { padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
.btn-submit { background: #2d4e1e; color: white; padding: 10px 20px; border-radius: 5px; border: none; cursor: pointer; }
.btn-cancel { background: #6c757d; color: white; padding: 10px 20px; border-radius: 5px; border: none; cursor: pointer; }

/* Agent Card Styles */
.agent-card { display: flex; align-items: center; gap: 18px; background: #f9fafb; border: 1.5px solid #e6e6e6; border-radius: 12px; padding: 14px 18px; margin-bottom: 6px; }
.agent-card-photo img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; }
.agent-card-name { font-weight: 700; color: #23613b; }
.location-status { margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 13px; display: none; }
.location-success { background-color: #d4edda; color: #155724; }
.location-error { background-color: #f8d7da; color: #721c24; }

/* Active nav */
.nav-links li.active a {
    color: #f4d03f;
    font-weight: 600;
}
.nav-links li.active a::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 100%;
    height: 3px;
    background: #f4d03f;
    border-radius: 2px;
}

/* Hidden by default */
#suggestedAgent, #agentActions, #otherAgentSelect {
  display: none;
}

/* --- Suggested Agent Section --- */
.agent-suggest-label {
  font-size: 13px;
  font-weight: 600;
  color: #14532d;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.agent-suggest-label svg {
  width: 16px; height: 16px; fill: #14532d;
}
.agent-distance-badge {
  display: inline-block;
  background: #dcfce7;
  color: #166534;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 20px;
  margin-left: 8px;
  border: 1px solid #bbf7d0;
}
.agent-avail-dot {
  display: inline-block;
  width: 9px; height: 9px;
  border-radius: 50%;
  margin-right: 4px;
  vertical-align: middle;
}
.agent-avail-dot.available { background: #22c55e; box-shadow: 0 0 4px #22c55e88; }
.agent-avail-dot.unavailable { background: #ef4444; }
.agent-avail-text {
  font-size: 12px;
  font-weight: 500;
}
.agent-avail-text.available { color: #166534; }
.agent-avail-text.unavailable { color: #b91c1c; }

/* Pick / Choose buttons */
.agent-action-btns {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}
.btn-pick-agent {
  background: linear-gradient(135deg, #14532d, #166534);
  color: #fff;
  padding: 8px 20px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 6px;
}
.btn-pick-agent:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(20,83,45,0.3);
}
.btn-choose-other {
  background: #f1f5f9;
  color: #334155;
  padding: 8px 20px;
  border: 1.5px solid #cbd5e1;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-choose-other:hover {
  background: #e2e8f0;
  border-color: #94a3b8;
}

/* Other agents list */
.other-agents-panel {
  margin-top: 14px;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 16px;
  max-height: 320px;
  overflow-y: auto;
}
.other-agents-panel::-webkit-scrollbar { width: 6px; }
.other-agents-panel::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 6px; }
.other-agents-title {
  font-size: 14px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 10px;
}
.other-agent-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  margin-bottom: 8px;
  background: #fff;
  cursor: pointer;
  transition: all 0.2s;
}
.other-agent-card:hover {
  border-color: #14532d;
  background: #f0fdf4;
  box-shadow: 0 2px 8px rgba(20,83,45,0.08);
}
.other-agent-card.selected {
  border-color: #14532d;
  background: #dcfce7;
  box-shadow: 0 0 0 2px rgba(20,83,45,0.15);
}
.other-agent-card img {
  width: 44px; height: 44px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid #e2e8f0;
}
.other-agent-meta {
  flex: 1;
  min-width: 0;
}
.other-agent-name {
  font-weight: 600;
  font-size: 14px;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.other-agent-loc {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.other-agent-right {
  text-align: right;
  flex-shrink: 0;
}
.other-agent-dist {
  font-size: 12px;
  font-weight: 600;
  color: #14532d;
}

/* Detecting location spinner */
.detecting-loc {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background: #f0fdf4;
  border: 1.5px solid #bbf7d0;
  border-radius: 10px;
  color: #166534;
  font-size: 14px;
  font-weight: 500;
}
.detecting-loc .spinner {
  width: 18px; height: 18px;
  border: 3px solid #bbf7d0;
  border-top-color: #14532d;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Modal for user messages */
#userMessageModal {
  display: none;
  position: fixed;
  z-index: 2000;
  left: 0;
  top: 0;
  width: 100vw;
  height: 100vh;
  background: rgba(0, 0, 0, 0.32);
  align-items: center;
  justify-content: center;
}
#userMessageModal > div {
  background: #fff;
  padding: 32px 28px 24px 28px;
  border-radius: 12px;
  max-width: 350px;
  width: 90vw;
  box-shadow: 0 8px 32px rgba(44, 62, 80, 0.18);
  position: relative;
  text-align: center;
}
#userMessageModalText {
  font-size: 1.08em;
  color: #222;
  margin-bottom: 18px;
}
#userMessageModal button {
  background: #23613b;
  color: #fff;
  padding: 8px 22px;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  font-size: 1em;
  cursor: pointer;
}
#userMessageModalCloseBtn {
  display: none;
  position: absolute;
  top: 10px;
  right: 14px;
  background: none;
  border: none;
  font-size: 1.5em;
  color: #888;
  cursor: pointer;
}
  </style>
</head>
<body>

<header>
 <nav class="main-nav">
  <div class="nav-left">
    <img src="assets/f.png" alt="Logo" class="nav-logo">
    <span class="company-name">El Nuevo Puerta Real Estate</span>
  </div>
 <ul class="nav-links">
    <li><a href="index.php">Home</a></li>
    <li><a href="index.php">Home</a></li>
    <li class="active"><a href="userlot.php">View Lots</a></li>
    <li><a href="findagent.php">Find Agent</a></li>
    <li><a href="about.html">About</a></li>
    <li><a href="faqs.html">FAQs</a></li>
    <li><a href="contact.html">Contact</a></li>
 </ul>
  <div class="nav-right">
    <a href="Login/login.php" class="login-btn">Login</a>
  </div>
</nav>
</header>

<div class="adminlots-main">
  <div class="map-panel">
    <div id="map"></div>
  </div>
  <div class="info-panel" id="infoPanel" style="display:flex;align-items:center;justify-content:center;min-height:200px;color:#bbb;font-size:1.2em;">
    Select a pinned location on the map to view details.
  </div>
</div>

<div id="blueprintModalBox" class="modal">
  <span class="close-bp" onclick="closeBlueprint()">&times;</span>
  <div class="blueprint-white-bg">
    <div id="blueprint-wrapper">
        <img class="modal-content" id="blueprintImg" draggable="false">
        <svg id="blueprint-svg-layer" preserveAspectRatio="none"></svg>
    </div>
  </div>
  <div id="tooltip" class="lot-tooltip"></div>
</div>

<div id="viewingModal" class="viewing-modal">
  <div class="viewing-modal-content">
    <div class="viewing-modal-header">
      <h2 class="viewing-modal-title">Request a Viewing</h2>
      <button class="viewing-close" onclick="closeViewingModal()">&times;</button>
    </div>

    <div class="viewing-modal-body">
      <form id="viewingForm" method="POST" action="">
        <input type="hidden" name="viewing_action" value="request">
        <input type="hidden" name="location_id" id="location_id" value="">
        <input type="hidden" name="lot_id" id="lot_id" value="">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">First Name <span class="required">*</span></label>
            <input type="text" class="form-input" id="firstName" name="client_first_name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-input" id="middleName" name="client_middle_name" placeholder="(Optional)">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Last Name <span class="required">*</span></label>
            <input type="text" class="form-input" id="lastName" name="client_last_name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email <span class="required">*</span></label>
            <input type="email" class="form-input" id="email" name="client_email" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Phone <span class="required">*</span></label>
            <input type="tel" class="form-input" id="phone" name="client_phone" required>
          </div>
          <div class="form-group">
            <label class="form-label">Location <span class="required">*</span></label>
            <input type="text" class="form-input" id="user_location" name="location" placeholder="Address or Area" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group full-width">
            <label class="form-label">Geolocation</label>
            <div style="display:none;">
              <input type="number" step="any" class="form-input" id="user_lat" name="client_lat" readonly>
              <input type="number" step="any" class="form-input" id="user_lng" name="client_lng" readonly>
            </div>

            <div style="margin-top:10px;display:flex;gap:10px;">
              <button type="button" onclick="getCurrentLocationUser()" class="btn-submit" style="padding:6px 12px;">Get Current Location</button>
              <button type="button" onclick="clearLocationUser()" class="btn-cancel" style="padding:6px 12px;">Clear Location</button>
            </div>

            <div id="user-location-status" class="location-status"></div>

            <div style="margin-top:16px;">
              <label style="font-size:13px;color:#14532d;font-weight:500;">Or select location on map:</label>
              <div id="user-select-map" style="height:350px;width:100%;border-radius:8px;margin-top:8px;"></div>
              <div style="font-size:12px;color:#666;margin-top:4px;">Click on the map to set your location.</div>
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group full-width">
            <label class="form-label">Assign Agent <span class="required">*</span></label>
            <button type="button" id="getAgentBtn" class="btn-submit" style="padding:10px 20px;font-size:15px;display:flex;align-items:center;gap:8px;width:fit-content;">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1118 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Find Nearest Agent
            </button>
            <div style="font-size:12px;color:#64748b;margin-top:4px;">We'll detect your location and suggest the nearest available agent.</div>

            <!-- Loading state -->
            <div id="agentLoading" style="display:none;margin-top:12px;">
              <div class="detecting-loc">
                <div class="spinner"></div>
                <span id="agentLoadingText">Detecting your location...</span>
              </div>
            </div>

            <!-- Suggested agent card -->
            <div id="suggestedAgent" style="margin-top:12px;"></div>

            <!-- Pick / Choose Other buttons -->
            <div id="agentActions" style="display:none;">
              <div class="agent-action-btns">
                <button type="button" id="pickSuggestedAgentBtn" class="btn-pick-agent">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                  Pick This Agent
                </button>
                <button type="button" id="chooseOtherAgentBtn" class="btn-choose-other">Choose Other Agent</button>
              </div>
            </div>

            <!-- Other agents panel -->
            <div id="otherAgentSelect" style="display:none;">
              <div class="other-agents-panel">
                <div class="other-agents-title">All Available Agents</div>
                <div id="otherAgentsList"></div>
              </div>
            </div>

            <!-- Hidden select for backward compatibility -->
            <select id="manualAgentSelect" style="display:none;"></select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group full-width">
            <label class="form-label">Preferred Date & Time</label>
            <select class="form-input" id="agentTimeSlot" name="preferredDateTime" required disabled>
              <option value="">Please pick an agent first</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group full-width">
            <label class="form-label">Notes (optional)</label>
            <textarea class="form-input" id="notes" name="notes" rows="3"></textarea>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn-cancel" onclick="closeViewingModal()">Cancel</button>
          <button type="submit" class="btn-submit">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="userMessageModal">
  <div>
    <div id="userMessageModalText" style="margin-bottom:20px; font-size:1.1em;"></div>
    <button onclick="closeUserMessageModal()" class="btn-submit">OK</button>
  </div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/@panzoom/panzoom/dist/panzoom.min.js"></script>
<script>
/* -------------------- MAP & DATA -------------------- */
const map = L.map('map').setView([6.9214, 122.0790], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);

const locations  = <?php echo json_encode($locations); ?>;
const allLots    = <?php echo json_encode($all_lots); ?>;
const blueprints = <?php echo json_encode($blueprints); ?>;
let panzoomInstance = null; // Hold our zoom instance

locations.forEach(loc => {
  if(!loc.latitude || !loc.longitude) return;
  const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);
  marker.on('click', function() {
    map.setView([loc.latitude, loc.longitude], 16);
    marker.bindPopup(`<b>${loc.location_name}</b>`).openPopup();
    
    // Update Info Panel
    const lots = allLots[loc.id] || [];
    const blueprint_url = blueprints[loc.id] || null;

    updateInfoPanel({
      location_id: loc.id,
      location_name: loc.location_name,
      lots,
      blueprint_url
    });
  });
});

function renderInfoPanel(id, name, lots, hasBlueprint) {
  let rows = '';
  if (lots.length) {
    lots.forEach(lot => {
      let cls = (lot.lot_status === 'Sale' || lot.lot_status === 'Available') ? 'Available' : lot.lot_status;
      let btnHtml = lot.lot_status === 'Sold' 
         ? `<button class="inquire-btn" disabled style="background:#ccc;cursor:not-allowed;">Inquire</button>`
         : `<button class="inquire-btn" onclick='openViewingModal(${JSON.stringify(lot)})'>Inquire</button>`;
         
      rows += `<tr>
          <td>${lot.block_number}</td>
          <td>${lot.lot_number}</td>
          <td>${lot.lot_size} sqm</td>
          <td>${(+lot.lot_price).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td><span class="lot-status ${cls}">${lot.lot_status}</span></td>
          <td>${btnHtml}</td>
        </tr>`;
    });
  } else {
    rows = `<tr><td colspan="6" style="color:#b71c1c;">No lots available</td></tr>`;
  }

  const panel = document.getElementById('infoPanel');
  panel.removeAttribute('style'); // Clear placeholder centering
  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <h3>${name}</h3>
      ${hasBlueprint ? `<button class="blueprint-btn" onclick="openBlueprint(${id})">View Blueprint</button>` : ''}
    </div>
    <table class="lots-table">
      <thead><tr><th>Block</th><th>Lot</th><th>Size</th><th>Price</th><th>Status</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <div id="blueprintModalBox" class="modal">
      <span class="close" id="closeBlueprintModal">&times;</span>
      <div class="pin-legend">
        <h4>Lot Status</h4>
        <div class="pin-legend-item">
          <div class="pin-legend-color" style="background: rgba(40, 167, 69, 0.3); border-color: #28a745;"></div>
          <span>Available</span>
        </div>
        <div class="pin-legend-item">
          <div class="pin-legend-color" style="background: rgba(255, 193, 7, 0.3); border-color: #ffc107;"></div>
          <span>Reserved</span>
        </div>
        <div class="pin-legend-item">
          <div class="pin-legend-color" style="background: rgba(220, 53, 69, 0.3); border-color: #dc3545;"></div>
          <span>Sold</span>
        </div>
      </div>
      <div class="blueprint-white-bg">
        <div class="blueprint-image-container">
          <img class="modal-content" id="blueprintImg" src="${data.blueprint_url || ''}" alt="Blueprint" style="user-select:none;"/>
          <canvas id="blueprintCanvas" class="blueprint-canvas"></canvas>
        </div>
      </div>
    </div>
  `;

  if (data.blueprint_url) {
    const btn    = document.getElementById('viewBlueprintBtn');
    const modal  = document.getElementById('blueprintModalBox');
    const closeB = document.getElementById('closeBlueprintModal');
    const resolvedLocationId = data.location_id || (Array.isArray(data.lots) && data.lots.length ? data.lots[0].location_id : null);

    btn.onclick      = () => {
      modal.style.display = 'block';
      setTimeout(() => {
        enableBlueprintZoom();
        loadAndDrawPins(resolvedLocationId);
      }, 120);
    };
    closeB.onclick   = () => { modal.style.display = 'none'; };
    window.onclick   = (e) => { if (e.target === modal) modal.style.display = 'none'; };
  }
}

/* -------------------- BLUEPRINT ZOOM -------------------- */
function enableBlueprintZoom() {
  const img = document.getElementById('blueprintImg');
  const canvas = document.getElementById('blueprintCanvas');
  if (!img) return;

  let zoom = 1, min = 1, max = 10;
  let isDrag = false, startX = 0, startY = 0, panX = 0, panY = 0, lastX = 0, lastY = 0;

  function upd() { 
    img.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`;
    if (canvas) canvas.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`;
  }

  zoom = 1; panX = 0; panY = 0; upd(); img.style.cursor = 'zoom-in';

  img.onwheel = (e) => {
    e.preventDefault();
    const prev = zoom;
    zoom = e.deltaY < 0 ? Math.min(zoom + 0.2, max) : Math.max(zoom - 0.2, min);
    panX = panX * (zoom / prev);
    panY = panY * (zoom / prev);
    upd();
    img.style.cursor = zoom > 1 ? 'grab' : 'zoom-in';
  };

  img.onmousedown = (e) => {
    if (zoom === 1) return;
    isDrag = true; img.style.cursor = 'grabbing';
    startX = e.pageX; startY = e.pageY; lastX = panX; lastY = panY;

    function move(ev) { if (isDrag) { panX = lastX + (ev.pageX - startX)/zoom; panY = lastY + (ev.pageY - startY)/zoom; upd(); } }
    function up()    { isDrag = false; img.style.cursor = zoom > 1 ? 'grab' : 'zoom-in'; window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); }

    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
    e.preventDefault();
  };

  img.ondragstart = () => false;
}

/* -------------------- LOAD AND DRAW PIN LOCATIONS -------------------- */
function loadAndDrawPins(locationId) {
  const img = document.getElementById('blueprintImg');
  const canvas = document.getElementById('blueprintCanvas');
  
  if (!img || !canvas || !locationId) return;
  
  const drawPins = () => {
    const naturalWidth = img.naturalWidth || img.width || 1;
    const naturalHeight = img.naturalHeight || img.height || 1;
    
    const rect = img.getBoundingClientRect();
    canvas.width = Math.max(1, Math.round(rect.width));
    canvas.height = Math.max(1, Math.round(rect.height));

    const ctx = canvas.getContext('2d');

    fetch(`${window.location.pathname}?fetch_pins=1&location_id=${locationId}`)
      .then(response => response.json())
      .then(data => {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!data.success || !Array.isArray(data.pins) || data.pins.length === 0) return;

        data.pins.forEach(pin => {
          if (!Array.isArray(pin.coordinates) || pin.coordinates.length < 3) return;

          // Detect coordinate system: if max coordinate > 1000, it's using natural dimensions
          const allX = pin.coordinates.map(pt => Number(pt.x) || 0);
          const maxX = Math.max(...allX);
          
          let scaleX, scaleY;
          if (maxX > 1000) {
            // Coordinates are in natural dimension space (from migrated pins)
            scaleX = canvas.width / naturalWidth;
            scaleY = canvas.height / naturalHeight;
          } else {
            // Coordinates are in offsetWidth space (from admin's display size)
            // We need to scale to match the current display
            scaleX = 1;
            scaleY = 1;
          }

          const colors = getStatusColor(pin.pin_status);
          ctx.fillStyle = colors.fill;
          ctx.strokeStyle = colors.stroke;
          ctx.lineWidth = 2;

          ctx.beginPath();
          ctx.moveTo((Number(pin.coordinates[0].x) || 0) * scaleX, (Number(pin.coordinates[0].y) || 0) * scaleY);
          for (let i = 1; i < pin.coordinates.length; i++) {
            ctx.lineTo((Number(pin.coordinates[i].x) || 0) * scaleX, (Number(pin.coordinates[i].y) || 0) * scaleY);
          }
          ctx.closePath();
          ctx.fill();
          ctx.stroke();

          const centerX = pin.coordinates.reduce((sum, p) => sum + (Number(p.x) || 0) * scaleX, 0) / pin.coordinates.length;
          const centerY = pin.coordinates.reduce((sum, p) => sum + (Number(p.y) || 0) * scaleY, 0) / pin.coordinates.length;
          ctx.fillStyle = '#000';
          ctx.font = 'bold 12px Arial';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(`B${pin.block_number} L${pin.lot_number}`, centerX, centerY);
        });
      })
      .catch(error => console.error('Error loading pins:', error));
  };

  if (!img.complete || img.naturalWidth === 0) {
    img.onload = () => setTimeout(drawPins, 100);
  } else {
    setTimeout(drawPins, 100);
  }
}

function getStatusColor(status) {
  const colors = {
    'Available': { stroke: '#28a745', fill: 'rgba(40, 167, 69, 0.3)' },
    'Reserved': { stroke: '#ffc107', fill: 'rgba(255, 193, 7, 0.3)' },
    'Sold': { stroke: '#dc3545', fill: 'rgba(220, 53, 69, 0.3)' }
  };
  return colors[status] || colors['Available'];
}

/* -------------------- LOAD AND DRAW PIN LOCATIONS -------------------- */
function loadAndDrawPins(locationId) {
  const img = document.getElementById('blueprintImg');
  const canvas = document.getElementById('blueprintCanvas');
  
  if (!img || !canvas || !locationId) return;
  
  const drawPins = () => {
    const naturalWidth = img.naturalWidth || img.width || 1;
    const naturalHeight = img.naturalHeight || img.height || 1;
    
    const rect = img.getBoundingClientRect();
    canvas.width = Math.max(1, Math.round(rect.width));
    canvas.height = Math.max(1, Math.round(rect.height));

    const ctx = canvas.getContext('2d');

    fetch(`${window.location.pathname}?fetch_pins=1&location_id=${locationId}`)
      .then(response => response.json())
      .then(data => {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!data.success || !Array.isArray(data.pins) || data.pins.length === 0) return;

        data.pins.forEach(pin => {
          if (!Array.isArray(pin.coordinates) || pin.coordinates.length < 3) return;

          // Detect coordinate system: if max coordinate > 1000, it's using natural dimensions
          const allX = pin.coordinates.map(pt => Number(pt.x) || 0);
          const maxX = Math.max(...allX);
          
          let scaleX, scaleY;
          if (maxX > 1000) {
            // Coordinates are in natural dimension space (from migrated pins)
            scaleX = canvas.width / naturalWidth;
            scaleY = canvas.height / naturalHeight;
          } else {
            // Coordinates are in offsetWidth space (from admin's display size)
            // We need to scale to match the current display
            scaleX = 1;
            scaleY = 1;
          }

          const colors = getStatusColor(pin.pin_status);
          ctx.fillStyle = colors.fill;
          ctx.strokeStyle = colors.stroke;
          ctx.lineWidth = 2;

          ctx.beginPath();
          ctx.moveTo((Number(pin.coordinates[0].x) || 0) * scaleX, (Number(pin.coordinates[0].y) || 0) * scaleY);
          for (let i = 1; i < pin.coordinates.length; i++) {
            ctx.lineTo((Number(pin.coordinates[i].x) || 0) * scaleX, (Number(pin.coordinates[i].y) || 0) * scaleY);
          }
          ctx.closePath();
          ctx.fill();
          ctx.stroke();

          const centerX = pin.coordinates.reduce((sum, p) => sum + (Number(p.x) || 0) * scaleX, 0) / pin.coordinates.length;
          const centerY = pin.coordinates.reduce((sum, p) => sum + (Number(p.y) || 0) * scaleY, 0) / pin.coordinates.length;
          ctx.fillStyle = '#000';
          ctx.font = 'bold 12px Arial';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(`B${pin.block_number} L${pin.lot_number}`, centerX, centerY);
        });
      })
      .catch(error => console.error('Error loading pins:', error));
  };

  if (!img.complete || img.naturalWidth === 0) {
    img.onload = () => setTimeout(drawPins, 100);
  } else {
    setTimeout(drawPins, 100);
  }
}

function getStatusColor(status) {
  const colors = {
    'Available': { stroke: '#28a745', fill: 'rgba(40, 167, 69, 0.3)' },
    'Reserved': { stroke: '#ffc107', fill: 'rgba(255, 193, 7, 0.3)' },
    'Sold': { stroke: '#dc3545', fill: 'rgba(220, 53, 69, 0.3)' }
  };
  return colors[status] || colors['Available'];
}

/* -------------------- VIEWING & AGENT LOGIC (PRESERVED) -------------------- */
let currentLot = null;

function openViewingModal(lot) {
  currentLot = lot || null;
  if (currentLot && currentLot.lot_status === 'Reserved') {
    if (!confirm('This lot is reserved. Proceed anyway?')) return;
  }
  document.getElementById('viewingModal').style.display = 'block';
  document.getElementById('viewingForm').reset();
  document.getElementById('location_id').value = currentLot ? currentLot.location_id : '';
  document.getElementById('lot_id').value = currentLot ? currentLot.id : '';
  
  // Close Blueprint if open
  closeBlueprint();
  
  // Initialize Mini Map
  setTimeout(initUserMap, 300);
}

function closeViewingModal() {
  document.getElementById('viewingModal').style.display = 'none';
  currentLot = null;
}

function initUserMap() {
    const mapDiv = document.getElementById('user-select-map');
    if (!mapDiv) return;
    if (mapDiv._leaflet_id) { mapDiv._leaflet_id = null; mapDiv.innerHTML = ''; }
    
    const uMap = L.map('user-select-map').setView([6.9214, 122.0790], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(uMap);
    
    let marker = null;
    uMap.on('click', (e) => {
        updateUserLoc(e.latlng.lat, e.latlng.lng, uMap, marker);
        if(!marker) {
             marker = L.marker(e.latlng, {draggable:true}).addTo(uMap);
             marker.on('dragend', (ev) => updateUserLoc(ev.target.getLatLng().lat, ev.target.getLatLng().lng));
        } else {
             marker.setLatLng(e.latlng);
        }
    });
}
function updateUserLoc(lat, lng, mapRef, markerRef) {
    document.getElementById('user_lat').value = lat;
    document.getElementById('user_lng').value = lng;
}

/* Geolocation */
function getCurrentLocationUser() {
  const statusDiv = document.getElementById('user-location-status');
  statusDiv.style.display = 'block';
  statusDiv.className = 'location-status';
  statusDiv.textContent = 'Getting location...';

  if (!navigator.geolocation) {
    statusDiv.className = 'location-status location-error';
    statusDiv.textContent = 'Geolocation is not supported by this browser.';
    return;
  }

  const geoOptions = {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 300000
  };

  navigator.geolocation.getCurrentPosition(
    pos => {
      const accuracy = pos.coords.accuracy;
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;

      document.getElementById('user_lat').value = lat;
      document.getElementById('user_lng').value = lng;

      // Reverse geocode to get address
      statusDiv.textContent = `Location captured! (±${Math.round(accuracy)}m accuracy) - Getting address...`;

      fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`)
        .then(response => response.json())
        .then(data => {
          let address = '';
          if (data && data.display_name) {
            // Extract meaningful address components
            const addr = data.address || {};
            const components = [];

            if (addr.city) components.push(addr.city);
            else if (addr.town) components.push(addr.town);
            else if (addr.village) components.push(addr.village);

            if (addr.suburb || addr.neighbourhood) components.push(addr.suburb || addr.neighbourhood);
            if (addr.state || addr.region) components.push(addr.state || addr.region);
            if (addr.country) components.push(addr.country);

            address = components.length > 0 ? components.join(', ') : data.display_name.split(', ').slice(0, 3).join(', ');
          }

          document.getElementById('user_location').value = address;
          statusDiv.className = 'location-status location-success';
          statusDiv.textContent = `Location set! (±${Math.round(accuracy)}m accuracy)`;
          setTimeout(() => statusDiv.style.display = 'none', 4000);

          const agentDiv = document.getElementById('suggestedAgent');
          if (agentDiv) {
            agentDiv.innerHTML = '';
            agentDiv.style.display = 'none';
            delete agentDiv.dataset.agentId;
          }
          if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
          if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
        })
        .catch(err => {
          console.warn('Reverse geocoding failed:', err);
          // Still set coordinates even if address lookup fails
          document.getElementById('user_location').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
          statusDiv.className = 'location-status location-success';
          statusDiv.textContent = `Location captured! (±${Math.round(accuracy)}m accuracy) - Coordinates only`;
          setTimeout(() => statusDiv.style.display = 'none', 4000);

          const agentDiv = document.getElementById('suggestedAgent');
          if (agentDiv) {
            agentDiv.innerHTML = '';
            agentDiv.style.display = 'none';
            delete agentDiv.dataset.agentId;
          }
          if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
          if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
        });
    },
    err => {
      // Try fallback with lower accuracy
      if (err.code === err.TIMEOUT || err.code === err.POSITION_UNAVAILABLE) {
        statusDiv.textContent = 'High accuracy failed, trying basic location...';
        navigator.geolocation.getCurrentPosition(
          fallbackPos => {
            const accuracy = fallbackPos.coords.accuracy;
            const lat = fallbackPos.coords.latitude;
            const lng = fallbackPos.coords.longitude;

            document.getElementById('user_lat').value = lat;
            document.getElementById('user_lng').value = lng;

            // Reverse geocode with fallback position
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`)
              .then(response => response.json())
              .then(data => {
                let address = '';
                if (data && data.display_name) {
                  const addr = data.address || {};
                  const components = [];

                  if (addr.city) components.push(addr.city);
                  else if (addr.town) components.push(addr.town);
                  else if (addr.village) components.push(addr.village);

                  if (addr.suburb || addr.neighbourhood) components.push(addr.suburb || addr.neighbourhood);
                  if (addr.state || addr.region) components.push(addr.state || addr.region);
                  if (addr.country) components.push(addr.country);

                  address = components.length > 0 ? components.join(', ') : data.display_name.split(', ').slice(0, 3).join(', ');
                }

                document.getElementById('user_location').value = address;
                statusDiv.className = 'location-status location-success';
                statusDiv.textContent = `Location set! (±${Math.round(accuracy)}m accuracy)`;
                setTimeout(() => statusDiv.style.display = 'none', 4000);

                const agentDiv = document.getElementById('suggestedAgent');
                if (agentDiv) {
                  agentDiv.innerHTML = '';
                  agentDiv.style.display = 'none';
                  delete agentDiv.dataset.agentId;
                }
                if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
                if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
              })
              .catch(fallbackErr => {
                console.warn('Reverse geocoding failed:', fallbackErr);
                document.getElementById('user_location').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                statusDiv.className = 'location-status location-success';
                statusDiv.textContent = `Location captured! (±${Math.round(accuracy)}m accuracy) - Coordinates only`;
                setTimeout(() => statusDiv.style.display = 'none', 4000);

                const agentDiv = document.getElementById('suggestedAgent');
                if (agentDiv) {
                  agentDiv.innerHTML = '';
                  agentDiv.style.display = 'none';
                  delete agentDiv.dataset.agentId;
                }
                if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
                if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
              });
          },
          fallbackErr => {
            statusDiv.className = 'location-status location-error';
            statusDiv.textContent = 'Error: ' + fallbackErr.message;
          },
          { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 }
        );
      } else {
        statusDiv.className = 'location-status location-error';
        statusDiv.textContent = 'Error: ' + err.message;
      }
    },
    geoOptions
  );
}
function clearLocationUser() {
  document.getElementById('user_lat').value = '';
  document.getElementById('user_lng').value = '';
  document.getElementById('user_location').value = '';
  const statusDiv = document.getElementById('user-location-status');
  statusDiv.style.display = 'none';

  const agentDiv = document.getElementById('suggestedAgent');
  if (agentDiv) {
    agentDiv.innerHTML = '';
    agentDiv.style.display = 'none';
    delete agentDiv.dataset.agentId;
  }
  if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
  if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
}

/* -------------------- GET NEAREST AGENT (with auto-geolocation) -------------------- */
function fetchNearestAgent(lat, lng, locationText) {
  const loadingDiv = document.getElementById('agentLoading');
  const loadingText = document.getElementById('agentLoadingText');
  loadingText.textContent = 'Finding nearest available agent...';

  const params = new URLSearchParams();
  if (locationText) params.append('location', locationText);
  if (lat) params.append('lat', lat);
  if (lng) params.append('lng', lng);

  fetch('get_nearest_agent.php?' + params.toString())
    .then(res => res.json())
    .then((data) => {
      loadingDiv.style.display = 'none';
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;

      if (data && data.id) {
        const distHtml = data.distance_km !== null
          ? `<span class="agent-distance-badge">📍 ${data.distance_km} km away</span>`
          : '';

        agentDiv.innerHTML = `
          <div class="agent-suggest-label">
            <svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 00-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 00-7-7zm0 9.5A2.5 2.5 0 1112 6.5a2.5 2.5 0 010 5z"/></svg>
            Nearest Available Agent ${distHtml}
          </div>
          <div class="agent-card">
            <div class="agent-card-photo">
              <img src="${data.photo}" alt="Agent Photo" onerror="this.src='assets/default-agent.png'">
            </div>
            <div class="agent-card-info">
              <div class="agent-card-name">${data.name}</div>
              <div style="margin:2px 0;">
                <span class="agent-avail-dot available"></span>
                <span class="agent-avail-text available">Available</span>
              </div>
              <div class="agent-card-contact">${data.email} &bull; ${data.mobile}</div>
              <div class="agent-card-location">${data.city}${data.address ? ', ' + data.address : ''}</div>
            </div>
          </div>`;
        agentDiv.dataset.agentId = data.id;
        agentDiv.style.display = 'block';
        document.getElementById('agentActions').style.display = 'block';
        document.getElementById('otherAgentSelect').style.display = 'none';
      } else {
        agentDiv.innerHTML = `
          <div class="agent-card agent-card-empty">No available agent found near your location. Try choosing an agent manually below.</div>`;
        agentDiv.style.display = 'block';
        delete agentDiv.dataset.agentId;
        document.getElementById('agentActions').style.display = 'none';
        // Auto-open the other agents panel
        fetchAllAgentsForSelect();
        document.getElementById('otherAgentSelect').style.display = 'block';
      }
    })
    .catch(() => {
      loadingDiv.style.display = 'none';
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;
      agentDiv.innerHTML = '<div class="agent-card agent-card-empty">Could not reach server. Please try again.</div>';
      agentDiv.style.display = 'block';
      delete agentDiv.dataset.agentId;
      document.getElementById('agentActions').style.display = 'none';
    });
}

document.getElementById('getAgentBtn').onclick = function () {
  const locationText = document.getElementById('user_location').value.trim();
  let lat = document.getElementById('user_lat').value.trim();
  let lng = document.getElementById('user_lng').value.trim();

  const loadingDiv = document.getElementById('agentLoading');
  const loadingText = document.getElementById('agentLoadingText');

  // Reset previous results
  const agentDiv = document.getElementById('suggestedAgent');
  agentDiv.innerHTML = ''; agentDiv.style.display = 'none';
  delete agentDiv.dataset.agentId;
  document.getElementById('agentActions').style.display = 'none';
  document.getElementById('otherAgentSelect').style.display = 'none';

  // If coordinates already captured, go directly
  if (lat && lng) {
    loadingDiv.style.display = 'block';
    loadingText.textContent = 'Finding nearest available agent...';
    fetchNearestAgent(lat, lng, locationText);
    return;
  }

  // Auto-detect location via Geolocation API with high accuracy
  if (navigator.geolocation) {
    loadingDiv.style.display = 'block';
    loadingText.textContent = 'Detecting your location...';

    const geoOptions = {
      enableHighAccuracy: true,
      timeout: 15000, // Increased timeout for better accuracy
      maximumAge: 300000 // Accept positions up to 5 minutes old
    };

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        lat = pos.coords.latitude;
        lng = pos.coords.longitude;
        const accuracy = pos.coords.accuracy; // Accuracy in meters

        document.getElementById('user_lat').value = lat;
        document.getElementById('user_lng').value = lng;

        // Update the map marker in the mini-map if available
        const statusDiv = document.getElementById('user-location-status');
        statusDiv.className = 'location-status location-success';
        statusDiv.textContent = `Location detected! (±${Math.round(accuracy)}m accuracy)`;
        statusDiv.style.display = 'block';
        setTimeout(() => statusDiv.style.display = 'none', 4000);

        loadingText.textContent = 'Finding nearest available agent...';
        fetchNearestAgent(lat, lng, locationText);
      },
      (err) => {
        // Try again with lower accuracy if high accuracy failed
        if (err.code === err.TIMEOUT || err.code === err.POSITION_UNAVAILABLE) {
          loadingText.textContent = 'High accuracy failed, trying basic location...';
          navigator.geolocation.getCurrentPosition(
            (pos) => {
              lat = pos.coords.latitude;
              lng = pos.coords.longitude;
              const accuracy = pos.coords.accuracy;

              document.getElementById('user_lat').value = lat;
              document.getElementById('user_lng').value = lng;

              const statusDiv = document.getElementById('user-location-status');
              statusDiv.className = 'location-status location-success';
              statusDiv.textContent = `Location detected! (±${Math.round(accuracy)}m accuracy)`;
              statusDiv.style.display = 'block';
              setTimeout(() => statusDiv.style.display = 'none', 4000);

              loadingText.textContent = 'Finding nearest available agent...';
              fetchNearestAgent(lat, lng, locationText);
            },
            (fallbackErr) => {
              // Geolocation failed — try with text location
              if (locationText) {
                loadingText.textContent = 'Location access denied. Searching by address...';
                fetchNearestAgent(null, null, locationText);
              } else {
                loadingDiv.style.display = 'none';
                showUserMessageModal('Please allow location access or enter your address to find the nearest agent.');
              }
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 }
          );
        } else {
          // Geolocation failed — try with text location
          if (locationText) {
            loadingText.textContent = 'Location access denied. Searching by address...';
            fetchNearestAgent(null, null, locationText);
          } else {
            loadingDiv.style.display = 'none';
            showUserMessageModal('Please allow location access or enter your address to find the nearest agent.');
          }
        }
      },
      geoOptions
    );
  } else if (locationText) {
    // Browser doesn't support geolocation, fall back to text
    loadingDiv.style.display = 'block';
    loadingText.textContent = 'Finding nearest agent by address...';
    fetchNearestAgent(null, null, locationText);
  } else {
    showUserMessageModal('Please allow location access or enter your address to find the nearest agent.');
  }
};

/* -------------------- SUBMIT VIEWING REQUEST -------------------- */
document.getElementById('viewingForm').addEventListener('submit', function (e) {
  e.preventDefault();

  const agentDiv = document.getElementById('suggestedAgent');
  const agent_id = agentDiv && agentDiv.dataset ? agentDiv.dataset.agentId || null : null;
  const lot_no = currentLot ? currentLot.lot_number : '';

  const formData = new FormData();
  formData.append('agent_id', agent_id);
  formData.append('client_first_name', document.getElementById('firstName').value);
  formData.append('client_middle_name', document.getElementById('middleName').value);
  formData.append('client_last_name', document.getElementById('lastName').value);
  formData.append('client_email', document.getElementById('email').value);
  formData.append('client_phone', document.getElementById('phone').value);
  formData.append('location', document.getElementById('user_location').value);
  formData.append('lot_no', lot_no);
  formData.append('preferredDateTime', document.getElementById('agentTimeSlot').value || '');
  formData.append('notes', document.getElementById('notes').value || '');
  formData.append('client_lat', document.getElementById('user_lat').value || '');
  formData.append('client_lng', document.getElementById('user_lng').value || '');
  formData.append('location_id', document.getElementById('location_id').value || '');
  formData.append('lot_id', document.getElementById('lot_id').value || '');

  fetch('submit_viewing.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then((data) => {
      if (data.success) {
        showUserMessageModal('Your viewing request has been submitted! We will contact you soon.', closeViewingModal);
      } else {
        showUserMessageModal('Error submitting request: ' + (data.error || 'Unknown error'));
      }
    })
    .catch((err) => showUserMessageModal('Network error: ' + err.message));
});

/* -------------------- CLICK OUTSIDE TO CLOSE MODAL -------------------- */
window.addEventListener('click', function (event) {
  const modal = document.getElementById('viewingModal');
  if (event.target === modal) closeViewingModal();
});

// Load agent slots and populate dropdown
function loadAgentSlots(agentId) {
  console.log('Loading slots for agent:', agentId);
  const select = document.getElementById('agentTimeSlot');
  select.disabled = true;
  select.innerHTML = '<option value="">Loading available slots...</option>';
  fetch('get_agent_slots.php?agent_id=' + agentId)
    .then(res => res.json())
    .then(slots => {
      console.log('Slots received:', slots);
      select.innerHTML = '<option value="">-- Select Date & Time --</option>';
      // Group slots by date and display number of clients accommodated
      const grouped = {};
      slots.forEach(slot => {
        if (!grouped[slot.available_date]) grouped[slot.available_date] = [];
        grouped[slot.available_date].push(slot);
      });
      Object.keys(grouped).forEach(date => {
        const optgroup = document.createElement('optgroup');
        // Format date as e.g. December 25, 2025
        const dateObj = new Date(date);
        const dateLabel = dateObj.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        optgroup.label = dateLabel;
        grouped[date].forEach(slot => {
          // Format time as e.g. 12:05 PM
          const timeLabel = slot.time_slot.length > 5 ? slot.time_slot.slice(0,5) : slot.time_slot;
          const [h, m] = timeLabel.split(':');
          const d = new Date(); d.setHours(h, m);
          const formattedTime = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
          const opt = document.createElement('option');
          opt.value = date + ' ' + slot.time_slot;
          let label = formattedTime;
          if (slot.max_clients > 1) {
            label += ` (${slot.booked_count}/${slot.max_clients} slots taken)`;
          } else {
            label += slot.booked_count >= slot.max_clients ? ' (Full)' : ' (Available)';
          }
          if (slot.booked_count >= slot.max_clients) {
            opt.disabled = true;
            opt.style.color = '#aaa';
          }
          opt.textContent = label;
          optgroup.appendChild(opt);
        });
        select.appendChild(optgroup);
      });
      select.disabled = false;
    })
    .catch(err => {
      console.error('Error fetching agent slots:', err);
      select.innerHTML = '<option value="">Could not load slots</option>';
      select.disabled = true;
    });
}
window.loadAgentSlots = loadAgentSlots;


/* Manual agent list — now renders rich cards */
function fetchAllAgentsForSelect() {
  const lat = document.getElementById('user_lat').value.trim();
  const lng = document.getElementById('user_lng').value.trim();
  let url = 'get_all_agents.php';
  if (lat && lng) url += '?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng);

  const listDiv = document.getElementById('otherAgentsList');
  listDiv.innerHTML = '<div style="text-align:center;padding:12px;color:#64748b;">Loading agents...</div>';

  fetch(url)
    .then(res => res.json())
    .then(list => {
      if (!list.length) {
        listDiv.innerHTML = '<div style="text-align:center;padding:12px;color:#b91c1c;">No agents available.</div>';
        return;
      }
      let html = '';
      list.forEach(agent => {
        const distText = agent.distance_km !== null ? agent.distance_km + ' km' : '';
        const availDot = agent.is_available
          ? '<span class="agent-avail-dot available"></span><span class="agent-avail-text available">Available</span>'
          : '<span class="agent-avail-dot unavailable"></span><span class="agent-avail-text unavailable">Unavailable</span>';
        html += `
          <div class="other-agent-card" data-agent-id="${agent.id}" onclick="selectOtherAgent(${agent.id}, this)">
            <img src="${agent.photo}" alt="" onerror="this.src='assets/default-agent.png'">
            <div class="other-agent-meta">
              <div class="other-agent-name">${agent.name}</div>
              <div class="other-agent-loc">${agent.city}${agent.address ? ', ' + agent.address : ''}</div>
              <div style="margin-top:2px;">${availDot}</div>
            </div>
            <div class="other-agent-right">
              ${distText ? '<div class="other-agent-dist">' + distText + '</div>' : ''}
            </div>
          </div>`;
      });
      listDiv.innerHTML = html;
    })
    .catch(() => {
      listDiv.innerHTML = '<div style="text-align:center;padding:12px;color:#b91c1c;">Could not load agents.</div>';
    });
}

function selectOtherAgent(agentId, el) {
  // Deselect all
  document.querySelectorAll('.other-agent-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');

  const agentDiv = document.getElementById('suggestedAgent');
  agentDiv.dataset.agentId = agentId;
  loadAgentSlots(agentId);
}

document.getElementById('pickSuggestedAgentBtn').onclick = function() {
  const agentDiv = document.getElementById('suggestedAgent');
  if (agentDiv && agentDiv.dataset.agentId) {
    loadAgentSlots(agentDiv.dataset.agentId);
    document.getElementById('otherAgentSelect').style.display = 'none';
  }
};

document.getElementById('chooseOtherAgentBtn').onclick = function() {
    document.getElementById('otherAgentSelect').style.display = 'block';
    fetch('get_all_agents.php').then(r=>r.json()).then(list => {
        const sel = document.getElementById('manualAgentSelect');
        sel.innerHTML = '<option value="">Select...</option>';
        list.forEach(a => sel.innerHTML += `<option value="${a.id}">${a.name}</option>`);
    });
};


function showUserMessageModal(message, onClose) {
  const modal = document.getElementById('userMessageModal');
  const text = document.getElementById('userMessageModalText');
  text.textContent = message;
  modal.style.display = 'flex';
  // Allow closing with OK button
  modal.querySelector('button').onclick = function() {
    modal.style.display = 'none';
    if (typeof onClose === 'function') onClose();
  };
  // Allow closing with X button (hidden by default)
  document.getElementById('userMessageModalCloseBtn').onclick = function() {
    modal.style.display = 'none';
    if (typeof onClose === 'function') onClose();
  };
}
function closeUserMessageModal() {
    document.getElementById('userMessageModal').style.display = 'none';
}

// Close Modals on Outside Click
window.onclick = function(e) {
    if (e.target == bpModal) closeBlueprint();
    if (e.target == document.getElementById('viewingModal')) closeViewingModal();
}
</script>
<style>
/* Agent Card Styles */
.agent-card {
  display: flex;
  align-items: center;
  gap: 18px;
  background: #f9fafb;
  border: 1.5px solid #e6e6e6;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(44,62,80,0.07);
  padding: 14px 18px;
  margin-bottom: 6px;
  transition: box-shadow 0.18s;
}
.agent-card:hover {
  box-shadow: 0 4px 16px rgba(44,62,80,0.13);
}
.agent-card-photo img {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  object-fit: cover;
  border: 2.5px solid #23613b22;
  background: #fff;
}
.agent-card-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.agent-card-name {
  font-weight: 700;
  color: #23613b;
  font-size: 1.18em;
  margin-bottom: 2px;
}
.agent-card-contact {
  font-size: 0.99em;
  color: #444;
  margin-bottom: 2px;
}
.agent-card-location {
  font-size: 0.97em;
  color: #6b7280;
}
.agent-card-empty {
  color: #b91c1c;
  background: #fef2f2;
  border: 1.5px solid #fca5a5;
  border-radius: 10px;
  padding: 10px 14px;
  text-align: center;
}
</style>
</body>
</html>

