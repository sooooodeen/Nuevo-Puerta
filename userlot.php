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
if ($result === false) {
    die("SQL error: " . $conn->error);
}
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
}

// Fetch available lots grouped by location_id for the outside table
$available_lots = [];
$sql = "
  SELECT id, block_number, lot_number, lot_size, lot_price, location_id,
       COALESCE(NULLIF(status, ''), 'Available') AS lot_status
  FROM lots
  WHERE COALESCE(NULLIF(status, ''), 'Available') = 'Available'";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $available_lots[$row['location_id']][] = $row;
    }
}

// Fetch all lots grouped by location_id for blueprint rendering and click handling
$all_lots = [];
$sql = "
  SELECT id, block_number, lot_number, lot_size, lot_price, location_id,
       COALESCE(NULLIF(status, ''), 'Available') AS lot_status
  FROM lots";
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
  <title>Admin Lots Map</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
/* ---------------------------------- */
/* 1. General Styles and Font Imports */
/* ---------------------------------- */
body {
    font-family: 'Poppins', sans-serif;
    font-size: 16px; 
    line-height: 1.6;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    background-color: #f8f8f8;
    color: #333;
    overflow-x: hidden;
    overflow-y: auto;
}

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
    position: sticky;
    top: 0;
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

/* Add margin to main content so it's not hidden behind navbar */
.adminlots-main {
  margin-top: 90px;
}
.adminlots-main {
  display: flex;
  min-height: calc(100vh - 70px);
  height: auto;
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
  min-width: 0;
}
.info-panel h3 {
  margin: 0 0 10px 0;
  font-size: 1.1em;
  color: #2d4e1e;
}
.info-panel .blueprint-btn {
  background: #3a6c28;
  color: #fff;
  border: none;
  border-radius: 5px;
  padding: 8px 18px;
  font-size: 15px;
  float: right;
  margin-bottom: 10px;
  cursor: pointer;
  transition: background 0.2s;
  width: 150px;
  text-align: center;
  white-space: nowrap;
}
.info-panel .blueprint-btn:hover {
  background: #f4d03f;
  color: #2d4e1e;
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
  position: absolute;
  top: 30px;
  right: 50px;
  color: #fff;
  font-size: 40px;
  font-weight: bold;
  cursor: pointer;
  z-index: 2100;
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

/* Userlots chatbot */
.chatbot-fab {
  position: fixed;
  right: 18px;
  bottom: 18px;
  z-index: 12000;
  border: none;
  background: #23613b;
  color: #fff;
  border-radius: 999px;
  padding: 11px 14px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}

.chatbot-panel {
  position: fixed;
  right: 18px;
  bottom: 68px;
  width: 320px;
  max-width: calc(100vw - 28px);
  background: #fff;
  border: 1px solid #d7e2d6;
  border-radius: 10px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.22);
  z-index: 12001;
  display: none;
}

.chatbot-head {
  background: #2d4e1e;
  color: #fff;
  padding: 10px 12px;
  border-radius: 10px 10px 0 0;
  font-weight: 600;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.chatbot-body {
  padding: 10px 12px;
  max-height: 260px;
  overflow-y: auto;
  font-size: 13px;
}

.chatbot-msg {
  background: #f3f7f1;
  border: 1px solid #e3ece0;
  border-radius: 8px;
  padding: 8px;
  margin-bottom: 8px;
}

.chatbot-quick {
  border-top: 1px solid #edf2ea;
  padding: 10px;
  display: grid;
  gap: 6px;
}

.chatbot-quick button {
  border: 1px solid #b7cdb8;
  background: #fff;
  color: #2d4e1e;
  border-radius: 6px;
  padding: 7px 8px;
  text-align: left;
  cursor: pointer;
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

/* Request Viewing Modal Styles */
.viewing-modal {
  display: none;
  position: fixed;
  z-index: 3000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(5px);
}

.viewing-modal-content {
  background: white;
  margin: 10px auto 0 auto;
  padding: 0;
  border-radius: 15px;
  width: 90%;
  max-width: 600px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  position: relative;
  animation: modalSlideIn 0.3s ease;
  max-height: 90vh;
  overflow-y: auto;
}

@keyframes modalSlideIn {
  from { transform: translateY(-50px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.viewing-modal-header {
  background: linear-gradient(135deg, #2d4e1e, #3a6c28);
  padding: 20px 30px;
  border-radius: 15px 15px 0 0;
  color: white;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.viewing-modal-title {
  font-size: 24px;
  font-weight: 600;
  margin: 0;
}

.viewing-close {
  background: none;
  border: none;
  color: white;
  font-size: 28px;
  cursor: pointer;
  padding: 0;
  width: 35px;
  height: 35px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background 0.2s;
}

.viewing-close:hover {
  background: rgba(255, 255, 255, 0.2);
}

.viewing-modal-body {
  padding: 30px;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group.full-width {
  grid-column: 1 / -1;
}

.form-label {
  font-weight: 600;
  color: #2d4e1e;
  margin-bottom: 8px;
  font-size: 14px;
}

.form-input {
  padding: 12px 15px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-size: 14px;
  transition: border-color 0.2s, box-shadow 0.2s;
  background: #fafafa;
}

.form-input:focus {
  outline: none;
  border-color: #2d4e1e;
  box-shadow: 0 0 0 3px rgba(45, 78, 30, 0.1);
  background: white;
}

.form-textarea {
  min-height: 100px;
  resize: vertical;
  font-family: inherit;
}

.form-actions {
  display: flex;
  gap: 15px;
  justify-content: flex-end;
  margin-top: 30px;
  padding-top: 20px;
  border-top: 1px solid #e0e0e0;
}

.btn-cancel {
  background: #6c757d;
  color: white;
  border: none;
  padding: 12px 25px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-cancel:hover {
  background: #5a6268;
  transform: translateY(-1px);
}

.btn-submit {
  background: linear-gradient(135deg, #2d4e1e, #3a6c28);
  color: white;
  border: none;
  padding: 12px 25px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-submit:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 15px rgba(45, 78, 30, 0.3);
}

.required {
  color: #e74c3c;
}

/* Lot modal mini styles */
.lot-modal-header {
  background: #2d4e1e;
  padding: 18px 30px;
  border-radius: 10px 10px 0 0;
  display: flex;
  align-items: center;
}
.lot-modal-flex {
  display: flex;
  gap: 30px;
}
.lot-modal-left {
  flex: 1;
  min-width: 220px;
}
.lot-modal-img {
  width: 100%;
  max-width: 220px;
  border-radius: 8px;
  display: block;
  margin-bottom: 10px;
}
.lot-modal-desc {
  margin-bottom: 10px;
  font-size: 0.98em;
}
.lot-modal-size, .lot-modal-price {
  margin-bottom: 5px;
}
.lot-modal-right {
  flex: 1;
  min-width: 220px;
}
.plans-tabs { margin-bottom: 10px; }
.plan-tab {
  background: #e6e6e6;
  border: none;
  padding: 7px 18px;
  margin-right: 5px;
  border-radius: 5px;
  cursor: pointer;
  font-weight: bold;
}
.plan-tab.active { background: #2d4e1e; color: #fff; }
.pay-btn {
  background: #d6e09b;
  border: none;
  padding: 5px 15px;
  margin-right: 5px;
  border-radius: 5px;
  cursor: pointer;
  font-weight: bold;
}
.blueprint-btn, .inquire-btn {
  background: #2d4e1e;
  color: #fff;
  border: none;
  padding: 8px 18px;
  border-radius: 5px;
  cursor: pointer;
  font-weight: bold;
  margin-top: 10px;
}

@media (max-width: 900px) {
  .lot-modal-flex { flex-direction: column; }
  .lot-modal-left, .lot-modal-right { min-width: 0; }
}
@media (max-width: 1100px) {
  .adminlots-main { flex-direction: column; }
  .info-panel { min-width: unset; margin-top: 20px; }
}

@media (max-width: 768px) {
  .main-nav {
    height: auto;
    min-height: 68px;
    padding: 10px 14px;
    gap: 10px;
    flex-wrap: wrap;
  }

  .nav-left {
    width: 100%;
  }

  .company-name {
    font-size: 1.15rem;
  }

  .nav-links {
    width: 100%;
    gap: 14px;
    overflow-x: auto;
    white-space: nowrap;
    padding-bottom: 4px;
  }

  .nav-links a {
    font-size: 0.92rem;
  }

  .login-btn {
    padding: 8px 16px;
    font-size: 0.92rem;
    margin-left: auto;
  }

  .adminlots-main {
    padding: 12px;
    gap: 12px;
    margin-top: 0;
  }

  .map-panel,
  .info-panel {
    padding: 14px;
  }

  #map {
    height: 52vh;
    min-height: 280px;
  }

  #lotsInfoTable {
    overflow-x: auto;
  }
}

/* Location status */
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
  display: block !important;
}
.location-error {
  background-color: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
  display: block !important;
}

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

<!-- Request Viewing Modal -->
<div id="viewingModal" class="viewing-modal">
  <div class="viewing-modal-content" style="margin:10px auto 0 auto;">
    <div class="viewing-modal-header">
      <h2 class="viewing-modal-title">Reservation Request</h2>
      <button class="viewing-close" onclick="closeViewingModal()">&times;</button>
    </div>

    <div class="viewing-modal-body">
      <form id="viewingForm" method="POST" action="">
        <!-- Required for PHP -->
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
              <input type="number" step="any" class="form-input" id="user_lat" name="client_lat" placeholder="Latitude" readonly>
              <input type="number" step="any" class="form-input" id="user_lng" name="client_lng" placeholder="Longitude" readonly>
            </div>

            <div style="margin-top:10px;display:flex;gap:10px;">
              <button type="button" onclick="getCurrentLocationUser()" class="btn-location btn-submit" style="padding:6px 12px;">Get Current Location</button>
              <button type="button" onclick="enableMapPinMode()" class="btn-location" style="padding:6px 12px;background:#e6e6e6;color:#23613b;border:none;border-radius:6px;cursor:pointer;">Pin on Map</button>
              <button type="button" onclick="clearLocationUser()" class="btn-location btn-cancel" style="padding:6px 12px;">Clear Location</button>
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
            <button type="button" id="getAgentBtn" class="btn-submit" style="padding:6px 12px;">Get Agent</button>

            <!-- Suggested agent info will be shown here -->
            <div id="suggestedAgent" style="margin-top:10px;"></div>

            <div id="agentActions" style="margin-top:10px;display:none;">
              <button type="button" id="pickSuggestedAgentBtn" class="pick-agent-btn" style="background:#23613b;color:#fff;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;margin-right:8px;transition:background 0.2s,box-shadow 0.2s;">Pick This Agent</button>
              </style>
              <style>
              .pick-agent-btn:hover, .pick-agent-btn:focus {
                background: #1a4726 !important;
                box-shadow: 0 2px 8px rgba(44, 62, 80, 0.15);
              }
              </style>
              <button type="button" id="chooseOtherAgentBtn" style="background:#e6e6e6;color:#23613b;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;">Choose Other Agent</button>
            </div>
            <div id="otherAgentSelect" style="margin-top:10px;display:none;">
              <label for="manualAgentSelect" style="font-weight:500;">Select Another Agent:</label>
              <select id="manualAgentSelect" style="width:100%;padding:6px 8px;border-radius:5px;border:1px solid #ccc;margin-top:4px;"></select>
            </div>
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
            <textarea class="form-input form-textarea" id="notes" name="notes" placeholder="Tell us anything we should know..."></textarea>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn-cancel" onclick="closeViewingModal()">Cancel</button>
          <button type="submit" class="btn-submit">Submit Reservation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal for user messages -->
<div id="userMessageModal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.32);align-items:center;justify-content:center;">
  <div style="background:#fff;padding:32px 28px 24px 28px;border-radius:12px;max-width:350px;width:90vw;box-shadow:0 8px 32px rgba(44,62,80,0.18);position:relative;text-align:center;">
    <div id="userMessageModalText" style="font-size:1.08em;color:#222;margin-bottom:18px;"></div>
    <button onclick="closeUserMessageModal()" style="background:#23613b;color:#fff;padding:8px 22px;border:none;border-radius:6px;font-weight:600;font-size:1em;cursor:pointer;">OK</button>
    <button id="userMessageModalCloseBtn" style="display:none;position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.5em;color:#888;cursor:pointer;">&times;</button>
  </div>
</div>

<button id="chatbotFab" class="chatbot-fab" type="button">Help</button>
<div id="chatbotPanel" class="chatbot-panel" aria-live="polite">
  <div class="chatbot-head">
    <span>Userlots Assistant</span>
    <button type="button" id="chatbotClose" style="border:none;background:transparent;color:#fff;font-size:18px;cursor:pointer;">&times;</button>
  </div>
  <div class="chatbot-body" id="chatbotBody">
    <div class="chatbot-msg">Hi! I can help with reservation flow, viewing schedule, and lot status.</div>
  </div>
  <div class="chatbot-quick">
    <button type="button" onclick="chatbotReply('reservation')">How do I reserve a lot?</button>
    <button type="button" onclick="chatbotReply('flow')">What is the payment flow?</button>
    <button type="button" onclick="chatbotReply('status')">What do Available/Reserved/Paid mean?</button>
    <button type="button" onclick="chatbotReply('contact')">Contact Us</button>
  </div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
/* -------------------- MAP & INFO PANEL -------------------- */
const map = L.map('map').setView([6.9214, 122.0790], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

// PHP dumps
const locations  = <?php echo json_encode($locations); ?>;
const availableLots = <?php echo json_encode($available_lots); ?>;
const allLots    = <?php echo json_encode($all_lots); ?>;
const blueprints = <?php echo json_encode($blueprints); ?>;

function appendChatbotMessage(text) {
  const body = document.getElementById('chatbotBody');
  if (!body) return;
  const node = document.createElement('div');
  node.className = 'chatbot-msg';
  node.textContent = text;
  body.appendChild(node);
  body.scrollTop = body.scrollHeight;
}

function chatbotReply(topic) {
  if (topic === 'reservation') {
    appendChatbotMessage('Select a location, choose an available lot, then click Reserve. Fill out the form and submit — the lot will be marked Reserved right away. An agent will review and contact you to confirm.');
    return;
  }
  if (topic === 'flow') {
    appendChatbotMessage('Flow: 1️⃣ Schedule Viewing → 2️⃣ Reserve Lot → 3️⃣ Pay Installments / Full Payment → 4️⃣ Fully Paid → 5️⃣ Title Turnover. Status is updated by your assigned agent as your documents and payments are processed.');
    return;
  }
  if (topic === 'status') {
    appendChatbotMessage('🟢 Available = open lot, ready for reservation.\n🟡 Reserved = under reservation, pending agent approval.\n🔴 Sold/Paid = lot is fully paid and being processed for title turnover.');
    return;
  }
  if (topic === 'contact') {
    appendChatbotMessage('📞 Contact El Nuevo Puerta Real Estate:\n\n📱 Mobile: +63 912 345 6789\n📧 Email: info@elnuevopuerta.com\n🏢 Office: Main Road, Zamboanga City\n⏰ Hours: Mon–Sat 8AM–5PM\n\nOr visit our Contact page for more info.');
    return;
  }
  appendChatbotMessage('Available = open lot. Reserved = lot is under reservation (pending agent approval). Paid = lot is fully paid and ready for turnover processing.');
}

document.addEventListener('DOMContentLoaded', function() {
  const fab = document.getElementById('chatbotFab');
  const panel = document.getElementById('chatbotPanel');
  const close = document.getElementById('chatbotClose');
  if (fab && panel) {
    fab.addEventListener('click', function() {
      panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    });
  }
  if (close && panel) {
    close.addEventListener('click', function() {
      panel.style.display = 'none';
    });
  }
});

locations.forEach(loc => {
  const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);
  marker.on('click', function() {
    map.setView([loc.latitude, loc.longitude], 16);
    marker.bindPopup(`<b>${loc.location_name}</b>`).openPopup();

    const lots = availableLots[loc.id] || [];
    const blueprint_url = blueprints[loc.id] || null;

    updateInfoPanel({
      location_id: loc.id,
      location_name: loc.location_name,
      lots,
      blueprint_url
    });
  });
});

function updateInfoPanel(data) {
  let rows = '';
  if (data.lots.length) {
    data.lots.forEach(lot => {
      let cls = '';
      const status = lot.lot_status || 'Available';
      if (status === 'Sale' || status === 'Available') cls = 'sale';
      else if (status === 'Sold' || status === 'Paid') cls = 'sold';
      else if (status === 'Reserved') cls = 'reserved';

      let actionBtn = '';
      if (status === 'Sold' || status === 'Paid') {
        actionBtn = `<button class="inquire-btn" disabled style="background:#ccc;cursor:not-allowed;">Unavailable</button>`;
      } else if (status === 'Reserved') {
        actionBtn = `<button class="inquire-btn" disabled style="background:#e9c46a;color:#2d4e1e;cursor:not-allowed;" title="This lot is under reservation pending agent approval">Under Reservation</button>`;
      } else {
        actionBtn = `<button class="inquire-btn" onclick='openViewingModal(${JSON.stringify(lot)})'>Reserve</button>`;
      }

      rows += `
        <tr>
          <td>${lot.block_number}</td>
          <td>${lot.lot_number}</td>
          <td>${lot.lot_size} sqm</td>
          <td>&#8369;${(+lot.lot_price).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
          <td><span class="lot-status ${cls}">${status}</span></td>
          <td>${actionBtn}</td>
        </tr>`;
    });
  } else {
    rows = `<tr><td colspan="6" style="color:#b71c1c;font-weight:bold;">No available lots</td></tr>`;
  }

  const panel = document.getElementById('infoPanel');
  panel.removeAttribute('style'); // clear centering styles used for placeholder

  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <h3>${data.location_name}</h3>
      ${data.blueprint_url ? `<button class="blueprint-btn" id="viewBlueprintBtn">View Blueprint</button>` : ''}
    </div>
    <table class="lots-table">
      <thead>
        <tr>
          <th>Block Number</th>
          <th>Lot Number</th>
          <th>Lot Size</th>
          <th>Lot Price</th>
          <th>Lot Status</th>
          <th></th>
        </tr>
      </thead>
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
      
      <div class="blueprint-white-bg" style="overflow: hidden; width: 90vw; height: 80vh; display: flex; align-items: center; justify-content: center; background: #fff;">
        <div id="blueprint-wrapper" style="position: relative; display: inline-block; transform-origin: center center; transition: transform 0.1s;">
          <img id="blueprintImg" src="${data.blueprint_url || ''}" alt="Blueprint" style="user-select: none; display: block; max-width: 100%; max-height: 80vh; pointer-events: none;"/>
          <div id="draw-layer" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
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
  const wrapper = document.getElementById('blueprint-wrapper');
  const container = document.querySelector('.blueprint-white-bg');
  if (!wrapper || !container) return;

  let zoom = 1, min = 0.5, max = 10;
  let isDrag = false, startX = 0, startY = 0, panX = 0, panY = 0, lastX = 0, lastY = 0;

  function upd() { 
    wrapper.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`;
  }

  zoom = 1; panX = 0; panY = 0; upd(); 
  wrapper.style.cursor = 'grab';

  container.onwheel = (e) => {
    e.preventDefault();
    const prev = zoom;
    zoom = e.deltaY < 0 ? Math.min(zoom + 0.2, max) : Math.max(zoom - 0.2, min);
    panX = panX * (zoom / prev);
    panY = panY * (zoom / prev);
    upd();
  };

  container.onmousedown = (e) => {
    isDrag = true; 
    wrapper.style.cursor = 'grabbing';
    startX = e.pageX; 
    startY = e.pageY; 
    lastX = panX; 
    lastY = panY;

    function move(ev) { 
      if (isDrag) { 
        panX = lastX + (ev.pageX - startX)/zoom; 
        panY = lastY + (ev.pageY - startY)/zoom; 
        upd(); 
      } 
    }
    function up() { 
      isDrag = false; 
      wrapper.style.cursor = 'grab'; 
      window.removeEventListener('mousemove', move); 
      window.removeEventListener('mouseup', up); 
    }

    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
    e.preventDefault();
  };
}

/* -------------------- LOAD AND DRAW PIN LOCATIONS -------------------- */
function loadAndDrawPins(locationId) {
  const layer = document.getElementById('draw-layer');
  if (!layer || !locationId) return;

  fetch(`${window.location.pathname}?fetch_pins=1&location_id=${locationId}`)
    .then(response => response.json())
    .then(data => {
      layer.innerHTML = ''; // Clear previous pins
      if (!data.success || !Array.isArray(data.pins) || data.pins.length === 0) return;

      // Setup Native SVG Canvas overlay
      const svgMain = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svgMain.setAttribute('width', '100%');
      svgMain.setAttribute('height', '100%');
      svgMain.setAttribute('viewBox', '0 0 100 100'); // Translates percentages perfectly
      svgMain.setAttribute('preserveAspectRatio', 'none');
      svgMain.style.position = 'absolute';
      svgMain.style.top = '0';
      svgMain.style.left = '0';
      svgMain.style.pointerEvents = 'auto'; // Make shapes clickable/hoverable
      
      const staticGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      svgMain.appendChild(staticGroup);
      layer.appendChild(svgMain);

      data.pins.forEach(pin => {
        if (!Array.isArray(pin.coordinates) || pin.coordinates.length < 3) return;

        // Draw Polygon
        let poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        poly.setAttribute('points', pin.coordinates.map(pt => `${pt.x},${pt.y}`).join(' '));
        
        let stat = (pin.pin_status || 'Available').toLowerCase();
        poly.setAttribute('fill', stat === 'sold' ? 'rgba(220,53,69,0.5)' : (stat === 'reserved' ? 'rgba(255,193,7,0.5)' : 'rgba(40,167,69,0.5)'));
        poly.setAttribute('stroke', stat === 'sold' ? '#dc3545' : (stat === 'reserved' ? '#ffc107' : '#28a745'));
        poly.setAttribute('stroke-width', '0.2');
        poly.setAttribute('vector-effect', 'non-scaling-stroke');
        
        // Add a tooltip for hover events
        let title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        title.textContent = `Block ${pin.block_number} - Lot ${pin.lot_number}\nStatus: ${pin.pin_status}`;
        poly.appendChild(title);

        // Add visual hover effect & clicking logic
        poly.style.cursor = (stat === 'sold' || stat === 'reserved') ? 'not-allowed' : 'pointer';
        poly.onmouseover = () => { 
            poly.setAttribute('fill', stat === 'sold' ? 'rgba(220,53,69,0.8)' : (stat === 'reserved' ? 'rgba(255,193,7,0.8)' : 'rgba(40,167,69,0.8)')); 
        };
        poly.onmouseout = () => { 
            poly.setAttribute('fill', stat === 'sold' ? 'rgba(220,53,69,0.5)' : (stat === 'reserved' ? 'rgba(255,193,7,0.5)' : 'rgba(40,167,69,0.5)')); 
        };

        // Open modal on click; show notices for reserved/sold lots
        poly.onclick = () => {
          const targetLot = allLots[locationId]?.find(l => Number(l.id) === Number(pin.lot_id));
          if (!targetLot) return;
          const lotStat = String(targetLot.lot_status || '').toLowerCase();
          if (lotStat === 'sold' || lotStat === 'paid') {
            showUserMessageModal('This lot is already sold and is no longer available.');
          } else if (lotStat === 'reserved') {
            showUserMessageModal('This lot is currently under reservation pending agent approval. It is not available at this time.');
          } else {
            document.getElementById('blueprintModalBox').style.display = 'none';
            openViewingModal(targetLot);
          }
        };

        staticGroup.appendChild(poly);

        // Calculate polygon center to place text
        let sumX = 0, sumY = 0;
        pin.coordinates.forEach(pt => { sumX += Number(pt.x); sumY += Number(pt.y); });
        let cx = sumX / pin.coordinates.length;
        let cy = sumY / pin.coordinates.length;

        // Overlay the Block and Lot Number Text
        let text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', cx);
        text.setAttribute('y', cy);
        text.setAttribute('fill', '#000');
        text.setAttribute('font-size', '1.5'); 
        text.setAttribute('font-weight', 'bold');
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'central');
        text.setAttribute('pointer-events', 'none'); // Allow clicks to pass through to the polygon
        text.textContent = `B${pin.block_number} L${pin.lot_number}`;
        
        staticGroup.appendChild(text);
      });
    })
    .catch(error => console.error('Error loading pins:', error));
}

// Ensure getStatusColor is either removed or updated if referenced elsewhere, 
// though the new function embeds the colors directly.

/* -------------------- VIEWING MODAL -------------------- */
let currentLot = null;

function openViewingModal(lot) {
  currentLot = lot || null;

  if (currentLot && currentLot.lot_status === 'Reserved') {
    if (!confirm('Warning: This lot is already in reservation stage. Do you still want to request a viewing?')) {
      return;
    }
  }

  document.getElementById('viewingModal').style.display = 'block';
  document.getElementById('viewingForm').reset();
  
  document.getElementById('location_id').value = currentLot ? currentLot.location_id : '';
  document.getElementById('lot_id').value = currentLot ? currentLot.id : '';

  const ag = document.getElementById('suggestedAgent');
  if (ag) {
    ag.innerHTML = '';
    ag.style.display = 'none';
    delete ag.dataset.agentId;
  }
  if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
  if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';

  // Force re-initialize modal map
  setTimeout(() => {
    const mapDiv = document.getElementById('user-select-map');
    if (!mapDiv) return;
    
    // Remove previous map instance if exists
    if (window.userLocationMap && window.userLocationMap.map) {
      window.userLocationMap.map.remove();
    }
    if (mapDiv._leaflet_id) {
      mapDiv._leaflet_id = null;
      mapDiv.innerHTML = '';
    }
    
    const map = L.map('user-select-map').setView([13.41, 122.56], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    
    let marker = null;
    
    map.on('click', async function(e) {
      const lat = e.latlng.lat;
      const lng = e.latlng.lng;
      document.getElementById('user_lat').value = lat;
      document.getElementById('user_lng').value = lng;
      
      // Reverse geocode the clicked location
      const address = await reverseGeocodeUser(lat, lng);
      document.getElementById('user_location').value = address;

      updateLocationStatus(`Pinned location selected from map | Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)} | Updated: ${getStatusTimeSuffix()}`, 'success');
      
      // Always remove existing marker first so there is only one visible pin.
      if (marker && map.hasLayer(marker)) {
        map.removeLayer(marker);
      }
      if (window.userLocationMap && window.userLocationMap.marker && map.hasLayer(window.userLocationMap.marker)) {
        map.removeLayer(window.userLocationMap.marker);
      }

      marker = L.marker(e.latlng, {
        draggable:true,
        icon: L.icon({
          iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
          shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
          iconSize: [25, 41],
          iconAnchor: [12, 41],
          popupAnchor: [1, -34],
          shadowSize: [41, 41]
        })
      }).addTo(map);
      marker.bindPopup(`<strong>Selected Location</strong><br>Lat: ${lat.toFixed(6)}<br>Lng: ${lng.toFixed(6)}`).openPopup();
      marker.on('dragend', async function(ev) {
        const pos = ev.target.getLatLng();
        document.getElementById('user_lat').value = pos.lat;
        document.getElementById('user_lng').value = pos.lng;
        const dragAddress = await reverseGeocodeUser(pos.lat, pos.lng);
        document.getElementById('user_location').value = dragAddress;
        marker.setPopupContent(`<strong>Selected Location</strong><br>Lat: ${pos.lat.toFixed(6)}<br>Lng: ${pos.lng.toFixed(6)}`);
        updateLocationStatus(`Pinned location updated by dragging marker | Lat: ${pos.lat.toFixed(6)}, Lng: ${pos.lng.toFixed(6)} | Updated: ${getStatusTimeSuffix()}`, 'success');
      });

      // Keep global marker reference synchronized to avoid duplicate markers.
      if (window.userLocationMap) {
        window.userLocationMap.marker = marker;
      }
    });
    
    // If lat/lng already set, show marker
    const lat = document.getElementById('user_lat').value;
    const lng = document.getElementById('user_lng').value;
    if (lat && lng) {
      marker = L.marker([lat, lng], {
        draggable:true,
        icon: L.icon({
          iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
          shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
          iconSize: [25, 41],
          iconAnchor: [12, 41],
          popupAnchor: [1, -34],
          shadowSize: [41, 41]
        })
      }).addTo(map);
      map.setView([lat, lng], 14);
      marker.on('dragend', async function(ev) {
        const pos = ev.target.getLatLng();
        document.getElementById('user_lat').value = pos.lat;
        document.getElementById('user_lng').value = pos.lng;
        const dragAddress = await reverseGeocodeUser(pos.lat, pos.lng);
        document.getElementById('user_location').value = dragAddress;
        marker.setPopupContent(`<strong>Selected Location</strong><br>Lat: ${pos.lat.toFixed(6)}<br>Lng: ${pos.lng.toFixed(6)}`);
        updateLocationStatus(`Pinned location updated by dragging marker | Lat: ${pos.lat.toFixed(6)}, Lng: ${pos.lng.toFixed(6)} | Updated: ${getStatusTimeSuffix()}`, 'success');
      });

      if (window.userLocationMap) {
        window.userLocationMap.marker = marker;
      }
    }
    
    // Store map and marker globally for access by other functions
    window.userLocationMap = { map, marker };
  }, 300);
}

function closeViewingModal() {
  document.getElementById('viewingModal').style.display = 'none';
  currentLot = null;
}

function updateLocationStatus(message, type = 'success', autoHideMs = 0) {
  const statusDiv = document.getElementById('user-location-status');
  if (!statusDiv) return;

  statusDiv.style.display = 'block';
  statusDiv.className = type === 'error' ? 'location-status location-error' : 'location-status location-success';
  statusDiv.textContent = message;

  if (autoHideMs > 0) {
    setTimeout(() => {
      statusDiv.style.display = 'none';
    }, autoHideMs);
  }
}

function getStatusTimeSuffix() {
  return new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function enableMapPinMode() {
  const mapDiv = document.getElementById('user-select-map');

  updateLocationStatus(`Pin mode enabled: click anywhere on the map to set your location. (${getStatusTimeSuffix()})`, 'success');

  if (mapDiv) {
    mapDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

/* Geolocation helpers used by the buttons in your HTML */
async function getCurrentLocationUser() {
  const locationField = document.getElementById('user_location');

  updateLocationStatus(`Getting your location... (${getStatusTimeSuffix()})`, 'success');

  if (!navigator.geolocation) {
    updateLocationStatus('Geolocation is not supported by this browser.', 'error');
    return;
  }

  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      try {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const accuracy = Math.round(pos.coords.accuracy);

        // Store coordinates
        document.getElementById('user_lat').value = lat;
        document.getElementById('user_lng').value = lng;

        // Show updated status every time location changes.
        updateLocationStatus(`✓ Location captured! (Accuracy: ${accuracy}m) | Updated: ${getStatusTimeSuffix()}`, 'success');

        // Reverse geocode to get address
        console.log('Reverse geocoding for:', lat, lng);
        const address = await reverseGeocodeUser(lat, lng);
        console.log('Address result:', address);
        
        locationField.value = address;
        locationField.dispatchEvent(new Event('change', { bubbles: true }));

        // Trigger map update after a short delay to show marker
        setTimeout(() => {
          updateUserMapMarker(lat, lng);
        }, 100);

        // Reset agent suggestions
        const agentDiv = document.getElementById('suggestedAgent');
        if (agentDiv) {
          agentDiv.innerHTML = '';
          agentDiv.style.display = 'none';
          delete agentDiv.dataset.agentId;
        }
        if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
        if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
      } catch (err) {
        console.error('Error processing location:', err);
        updateLocationStatus('Error processing location data', 'error');
      }
    },
    err => {
      let errorMsg = 'Error getting location: ';
      if (err.code === 1) errorMsg += 'Permission denied. Please enable location access.';
      else if (err.code === 2) errorMsg += 'Position unavailable.';
      else if (err.code === 3) errorMsg += 'Request timeout.';
      else errorMsg += err.message;
      
      console.error('Geolocation error:', err);
      updateLocationStatus(errorMsg, 'error');
    },
    {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 0
    }
  );
}

// Reverse geocode using Nominatim - Extract detailed address
async function reverseGeocodeUser(lat, lng) {
  try {
    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`, {
      headers: {
        'User-Agent': 'Nuevo-Puerta-RealEstate/1.0'
      }
    });
    
    if (!response.ok) throw new Error('Geocoding API error');
    
    const data = await response.json();
    
    // Use the full display_name as base, it has the complete formatted address
    if (data.display_name) {
      // Clean up the display name by removing the country code at the end if present
      let fullAddress = data.display_name;
      
      // Remove country code suffix (e.g., ", Philippines")
      const parts = fullAddress.split(',');
      if (parts.length > 1) {
        // Keep all but the last part (which is usually the country)
        fullAddress = parts.slice(0, -1).join(',').trim();
      }
      
      // Additional parsing for even more specificity
      if (data.address) {
        const addr = data.address;
        const detailedParts = [];
        
        // Try to build a more specific address
        if (addr.house_number || addr.road) {
          if (addr.house_number) detailedParts.push(addr.house_number);
          if (addr.road) detailedParts.push(addr.road);
        }
        
        if (addr.suburb || addr.neighbourhood || addr.village || addr.hamlet) {
          detailedParts.push(addr.suburb || addr.neighbourhood || addr.village || addr.hamlet);
        }
        
        if (addr.city || addr.town || addr.municipality) {
          detailedParts.push(addr.city || addr.town || addr.municipality);
        }
        
        if (addr.county || addr.state || addr.province) {
          detailedParts.push(addr.county || addr.state || addr.province);
        }
        
        if (addr.postcode) {
          detailedParts.push(addr.postcode);
        }
        
        if (detailedParts.length > 0) {
          return detailedParts.join(', ');
        }
      }
      
      return fullAddress;
    }
    
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  } catch (err) {
    console.error('Reverse geocoding error:', err);
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  }
}

// Update map with user's current location marker
function updateUserMapMarker(lat, lng) {
  const mapDiv = document.getElementById('user-select-map');
  if (!mapDiv) return;

  try {
    // Check if map already exists
    const mapInstance = window.userLocationMap;
    
    if (mapInstance && mapInstance.map) {
      // Update existing map view and marker
      mapInstance.map.setView([lat, lng], 16);
      mapInstance.map.eachLayer((layer) => {
        if (layer instanceof L.Marker) {
          mapInstance.map.removeLayer(layer);
        }
      });
      if (mapInstance.marker) {
        mapInstance.map.removeLayer(mapInstance.marker);
      }
      mapInstance.marker = L.marker([lat, lng], {
        draggable: true,
        icon: L.icon({
          iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
          shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
          iconSize: [25, 41],
          iconAnchor: [12, 41],
          popupAnchor: [1, -34],
          shadowSize: [41, 41]
        })
      }).addTo(mapInstance.map);
      
      mapInstance.marker.on('dragend', function(ev) {
        const pos = ev.target.getLatLng();
        document.getElementById('user_lat').value = pos.lat;
        document.getElementById('user_lng').value = pos.lng;
      });
      
      mapInstance.marker.bindPopup(`<strong>Your Location</strong><br>Lat: ${lat.toFixed(6)}<br>Lng: ${lng.toFixed(6)}`).openPopup();
      mapInstance.map.invalidateSize();
    }
  } catch (err) {
    console.error('Error updating map marker:', err);
  }
}

function clearLocationUser() {
  document.getElementById('user_lat').value = '';
  document.getElementById('user_lng').value = '';
  document.getElementById('user_location').value = '';

  // Remove existing pin from map when clearing location.
  if (window.userLocationMap && window.userLocationMap.map && window.userLocationMap.marker) {
    window.userLocationMap.map.removeLayer(window.userLocationMap.marker);
    window.userLocationMap.marker = null;
  }
  if (window.userLocationMap && window.userLocationMap.map) {
    window.userLocationMap.map.eachLayer((layer) => {
      if (layer instanceof L.Marker) {
        window.userLocationMap.map.removeLayer(layer);
      }
    });
  }

  const statusDiv = document.getElementById('user-location-status');
  statusDiv.style.display = 'none';
  updateLocationStatus(`Location cleared. You can type address, get current location, or pin on map. (${getStatusTimeSuffix()})`, 'success', 2200);

  const agentDiv = document.getElementById('suggestedAgent');
  if (agentDiv) {
    agentDiv.innerHTML = '';
    agentDiv.style.display = 'none';
    delete agentDiv.dataset.agentId;
  }
  if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
  if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
}

/* -------------------- GET NEAREST AGENT -------------------- */
let manualAgentCache = [];

function setPickButtonState(isPicked) {
  const pickBtn = document.getElementById('pickSuggestedAgentBtn');
  if (!pickBtn) return;

  if (isPicked) {
    pickBtn.textContent = 'Picked ✓';
    pickBtn.style.background = '#14532d';
    pickBtn.style.boxShadow = '0 0 0 2px rgba(20,83,45,0.2)';
  } else {
    pickBtn.textContent = 'Pick This Agent';
    pickBtn.style.background = '#23613b';
    pickBtn.style.boxShadow = 'none';
  }
}

function renderSelectedAgentCard(agent, tagText) {
  const tag = tagText ? `<div style="margin-bottom:6px;font-size:12px;font-weight:700;color:#23613b;">${tagText}</div>` : '';
  return `
    <div class="agent-card">
      ${tag}
      <div class="agent-card-photo">
        <img src="${agent.photo}" alt="Agent Photo">
      </div>
      <div class="agent-card-info">
        <div class="agent-card-name">${agent.name}</div>
        <div class="agent-card-contact">${agent.email}<br>${agent.mobile || ''}</div>
        <div class="agent-card-location">${agent.city || ''}${agent.address ? ', ' + agent.address : ''}</div>
      </div>
    </div>`;
}

function setActiveAgent(agent, tagText) {
  const agentDiv = document.getElementById('suggestedAgent');
  if (!agentDiv || !agent || !agent.id) return;

  agentDiv.innerHTML = renderSelectedAgentCard(agent, tagText);
  agentDiv.dataset.agentId = String(agent.id);
  agentDiv.style.display = 'block';

  const actions = document.getElementById('agentActions');
  if (actions) actions.style.display = 'block';

  // Automatically load slots for the selected/nearest agent.
  loadAgentSlots(agent.id);
}

document.getElementById('getAgentBtn').onclick = function () {
  const getAgentBtn = document.getElementById('getAgentBtn');
  const statusDiv = document.getElementById('user-location-status');
  const chooseOtherBtn = document.getElementById('chooseOtherAgentBtn');
  const MIN_LOOKUP_LOADING_MS = 1800;
  const lookupStart = Date.now();
  const location = document.getElementById('user_location').value.trim();
  const lat = document.getElementById('user_lat').value.trim();
  const lng = document.getElementById('user_lng').value.trim();

  if (!location && (!lat || !lng)) {
    showUserMessageModal('Please enter your address or latitude/longitude.');
    return;
  }

  const params = new URLSearchParams();
  if (location) params.append('location', location);
  if (lat) params.append('lat', lat);
  if (lng) params.append('lng', lng);

  // Loading effect similar to "Get Current Location"
  let loadingTick = 0;
  let loadingInterval = null;
  if (statusDiv) {
    statusDiv.style.display = 'block';
    statusDiv.className = 'location-status';
    statusDiv.textContent = 'Finding nearest agent...';
    loadingInterval = setInterval(() => {
      loadingTick = (loadingTick + 1) % 4;
      const dots = '.'.repeat(loadingTick);
      statusDiv.textContent = `Finding nearest agent${dots}`;
    }, 300);
  }
  if (getAgentBtn) {
    getAgentBtn.disabled = true;
    getAgentBtn.textContent = 'Getting Agent...';
  }

  function finishLookupUI(afterFinish) {
    const elapsed = Date.now() - lookupStart;
    const waitMore = Math.max(0, MIN_LOOKUP_LOADING_MS - elapsed);
    setTimeout(() => {
      if (loadingInterval) clearInterval(loadingInterval);
      if (getAgentBtn) {
        getAgentBtn.disabled = false;
        getAgentBtn.textContent = 'Get Agent';
      }
      afterFinish();
    }, waitMore);
  }

  fetch('get_nearest_agent.php?' + params.toString())
    .then(res => res.json())
    .then((data) => {
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;

      finishLookupUI(() => {
        if (data && data.id) {
          setActiveAgent(data, 'Nearest Agent (Auto-Selected)');
          setPickButtonState(false);
          document.getElementById('otherAgentSelect').style.display = 'none';
          const manual = document.getElementById('manualAgentSelect');
          if (manual) manual.value = '';
          if (chooseOtherBtn) chooseOtherBtn.style.display = 'inline-block';
          if (statusDiv) {
            statusDiv.style.display = 'block';
            statusDiv.className = 'location-status location-success';
            statusDiv.textContent = 'Nearest agent found and auto-selected.';
            setTimeout(() => { statusDiv.style.display = 'none'; }, 2500);
          }
        } else {
          agentDiv.innerHTML = '<div class="agent-card agent-card-empty">No agent found near your location.</div>';
          agentDiv.style.display = 'block';
          delete agentDiv.dataset.agentId;
          setPickButtonState(false);
          document.getElementById('agentActions').style.display = 'none';
          document.getElementById('otherAgentSelect').style.display = 'none';
          if (statusDiv) {
            statusDiv.style.display = 'block';
            statusDiv.className = 'location-status location-error';
            statusDiv.textContent = 'No nearby agent found.';
          }
        }
      });
    })
    .catch(() => {
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;
      finishLookupUI(() => {
        agentDiv.textContent = 'Could not find agent (network error).';
        agentDiv.style.display = 'block';
        delete agentDiv.dataset.agentId;
        setPickButtonState(false);
        document.getElementById('agentActions').style.display = 'none';
        document.getElementById('otherAgentSelect').style.display = 'none';
        if (statusDiv) {
          statusDiv.style.display = 'block';
          statusDiv.className = 'location-status location-error';
          statusDiv.textContent = 'Could not get nearest agent. Please try again.';
        }
      });
    });
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
        showUserMessageModal(
          '✅ Reservation request submitted!\n\nYour request is pending agent approval.\n\n' +
          'The lot will be marked as Reserved only after agent approval.\n\nWe will reach out to you soon.',
          closeViewingModal
        );
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

/* Manual agent list */
function fetchAllAgentsForSelect() {
  const lat = document.getElementById('user_lat').value.trim();
  const lng = document.getElementById('user_lng').value.trim();
  const params = new URLSearchParams();
  if (lat && lng) {
    params.append('lat', lat);
    params.append('lng', lng);
  }

  const query = params.toString();
  fetch('get_all_agents.php' + (query ? ('?' + query) : ''))
    .then(res => res.json())
    .then(list => {
      manualAgentCache = Array.isArray(list) ? list : [];
      const select = document.getElementById('manualAgentSelect');
      select.innerHTML = '<option value="">Select an agent</option>';
      manualAgentCache.forEach(agent => {
        const dist = (typeof agent.distance_km === 'number') ? ` - ${agent.distance_km} km` : '';
        const avail = Number(agent.is_available) === 0 ? ' [Unavailable]' : '';
        select.innerHTML += `<option value="${agent.id}">${agent.name}${dist}${avail}</option>`;
      });
    });
}

document.getElementById('pickSuggestedAgentBtn').onclick = function() {
  const agentDiv = document.getElementById('suggestedAgent');
  if (agentDiv && agentDiv.dataset.agentId) {
    loadAgentSlots(agentDiv.dataset.agentId);
    setPickButtonState(true);
    document.getElementById('manualAgentSelect').value = '';
    document.getElementById('otherAgentSelect').style.display = 'none';
    document.getElementById('chooseOtherAgentBtn').style.display = 'inline-block';
  }
};

document.getElementById('chooseOtherAgentBtn').onclick = function() {
  setPickButtonState(false);
  fetchAllAgentsForSelect();
  document.getElementById('otherAgentSelect').style.display = 'block';
};

document.getElementById('manualAgentSelect').onchange = function() {
  const agentDiv = document.getElementById('suggestedAgent');
  if (!agentDiv) return;
  if (this.value) {
    const selectedId = Number(this.value);
    const picked = manualAgentCache.find(a => Number(a.id) === selectedId);
    if (picked) {
      // Replace the currently suggested agent card with the newly chosen agent.
      setActiveAgent(picked, 'Manually Selected Agent');
      setPickButtonState(false);
      document.getElementById('otherAgentSelect').style.display = 'none';
    } else {
      agentDiv.dataset.agentId = this.value;
      loadAgentSlots(this.value);
      setPickButtonState(false);
    }
  } else {
    delete agentDiv.dataset.agentId;
    agentDiv.innerHTML = '';
    agentDiv.style.display = 'none';
    setPickButtonState(false);
    document.getElementById('agentActions').style.display = 'none';
    document.getElementById('otherAgentSelect').style.display = 'none';
    this.value = '';
    
    const slotSelect = document.getElementById('agentTimeSlot');
    slotSelect.innerHTML = '<option value="">Please pick an agent first</option>';
    slotSelect.disabled = true;
  }
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
</script>
</body>
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

