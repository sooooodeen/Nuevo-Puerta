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

// Fetch all lots grouped by location_id
$all_lots = [];
$sql = "SELECT id, block_number, lot_number, lot_size, lot_price, location_id, status AS lot_status FROM lots";
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
.modal-content {
  display: block;
  margin: 60px auto;
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

@media (max-width: 600px) {
  .modal-content { max-width: 98vw; }
  .close { right: 20px; top: 10px; font-size: 32px; }
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
}
.location-error {
  background-color: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
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
    <li><a href="index.html">Home</a></li>
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
      <h2 class="viewing-modal-title">Request a Viewing</h2>
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
              <button type="button" id="pickSuggestedAgentBtn" style="background:#23613b;color:#fff;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;margin-right:8px;">Pick This Agent</button>
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
            <input type="datetime-local" class="form-input" id="preferredDateTime" name="preferred_date" required>
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
          <button type="submit" class="btn-submit">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ORIGINAL inquire modal (kept but IDs changed to avoid conflicts) -->
<div id="inquireModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.4);z-index:999;align-items:flex-start;justify-content:center;">
  <div style="background:#fff;padding:32px 24px;border-radius:12px;max-width:400px;margin:10px auto 0 auto;position:relative;">
    <button onclick="closeInquireModal()" style="position:absolute;top:12px;right:12px;">&times;</button>
    <h2>Request a Viewing</h2>
    <form id="inquireForm" method="POST" action="submit_lead.php">
      <input type="text" name="first_name" placeholder="First Name" required>
      <input type="text" name="last_name" placeholder="Last Name" required>
      <input type="email" name="email" placeholder="Email" required>
      <input type="text" name="phone" placeholder="Mobile Number" required>
      
      <label for="user_location_old" style="font-weight:600;">Enter Your Location</label>
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="text" id="user_location_old" name="location" class="form-input" style="width:140px;" required>
        <button type="button" id="getAgentBtnOld" class="btn-submit" style="padding:6px 12px;">Get Agent</button>
      </div>

      <input type="date" name="preferred_date" required>
      <textarea name="note" placeholder="Notes or questions"></textarea>

      <label>
        <input type="checkbox" name="consent" required>
        I agree to the privacy policy and to be contacted.
      </label>

      <button type="submit">Submit Request</button>
    </form>
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
const allLots    = <?php echo json_encode($all_lots); ?>;
const blueprints = <?php echo json_encode($blueprints); ?>;

locations.forEach(loc => {
  const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);
  marker.on('click', function() {
    map.setView([loc.latitude, loc.longitude], 16);
    marker.bindPopup(`<b>${loc.location_name}</b>`).openPopup();

    const lots = allLots[loc.id] || [];
    const blueprint_url = blueprints[loc.id] || null;

    updateInfoPanel({
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
      if (lot.lot_status === 'Sale' || lot.lot_status === 'Available') cls = 'sale';
      else if (lot.lot_status === 'Sold') cls = 'sold';
      else if (lot.lot_status === 'Reserved') cls = 'reserved';

      rows += `
        <tr>
          <td>${lot.block_number}</td>
          <td>${lot.lot_number}</td>
          <td>${lot.lot_size} sqm</td>
          <td>${(+lot.lot_price).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td><span class="lot-status ${cls}">${lot.lot_status}</span></td>
          <td>
            ${lot.lot_status === 'Sold'
              ? `<button class="inquire-btn" disabled style="background:#ccc;cursor:not-allowed;">Inquire</button>`
              : `<button class="inquire-btn" onclick='openViewingModal(${JSON.stringify(lot)})'>Inquire</button>`}
          </td>
        </tr>`;
    });
  } else {
    rows = `<tr><td colspan="6" style="color:#b71c1c;font-weight:bold;">No lots available</td></tr>`;
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
      <div class="blueprint-white-bg">
        <img class="modal-content" id="blueprintImg" src="${data.blueprint_url || ''}" alt="Blueprint" style="user-select:none;"/>
      </div>
    </div>
  `;

  if (data.blueprint_url) {
    const btn    = document.getElementById('viewBlueprintBtn');
    const modal  = document.getElementById('blueprintModalBox');
    const closeB = document.getElementById('closeBlueprintModal');

    btn.onclick      = () => { modal.style.display = 'block'; enableBlueprintZoom(); };
    closeB.onclick   = () => { modal.style.display = 'none'; };
    window.onclick   = (e) => { if (e.target === modal) modal.style.display = 'none'; };
  }
}

/* -------------------- BLUEPRINT ZOOM -------------------- */
function enableBlueprintZoom() {
  const img = document.getElementById('blueprintImg');
  if (!img) return;

  let zoom = 1, min = 1, max = 10;
  let isDrag = false, startX = 0, startY = 0, panX = 0, panY = 0, lastX = 0, lastY = 0;

  function upd() { img.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`; }

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

/* -------------------- VIEWING MODAL -------------------- */
let currentLot = null;

function openViewingModal(lot) {
  currentLot = lot || null;

  if (currentLot && currentLot.lot_status === 'Reserved') {
    if (!confirm('Warning: This lot is reserved and may not be available. Do you want to proceed with your inquiry?')) {
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
    if (mapDiv._leaflet_id) {
      mapDiv._leaflet_id = null;
      mapDiv.innerHTML = '';
    }
    const map = L.map('user-select-map').setView([13.41, 122.56], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    let marker = null;
    map.on('click', function(e) {
      const lat = e.latlng.lat;
      const lng = e.latlng.lng;
      document.getElementById('user_lat').value = lat;
      document.getElementById('user_lng').value = lng;
      if (marker) {
        marker.setLatLng(e.latlng);
      } else {
        marker = L.marker(e.latlng, {draggable:true}).addTo(map);
        marker.on('dragend', function(ev) {
          const pos = ev.target.getLatLng();
          document.getElementById('user_lat').value = pos.lat;
          document.getElementById('user_lng').value = pos.lng;
        });
      }
    });
    // If lat/lng already set, show marker
    const lat = document.getElementById('user_lat').value;
    const lng = document.getElementById('user_lng').value;
    if (lat && lng) {
      marker = L.marker([lat, lng], {draggable:true}).addTo(map);
      map.setView([lat, lng], 14);
      marker.on('dragend', function(ev) {
        const pos = ev.target.getLatLng();
        document.getElementById('user_lat').value = pos.lat;
        document.getElementById('user_lng').value = pos.lng;
      });
    }
  }, 300);
}

function closeViewingModal() {
  document.getElementById('viewingModal').style.display = 'none';
  currentLot = null;
}

/* Geolocation helpers used by the buttons in your HTML */
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

  navigator.geolocation.getCurrentPosition(
    pos => {
      document.getElementById('user_lat').value = pos.coords.latitude;
      document.getElementById('user_lng').value = pos.coords.longitude;
      statusDiv.className = 'location-status location-success';
      statusDiv.textContent = 'Location captured successfully!';
      setTimeout(() => statusDiv.style.display = 'none', 3000);

      const agentDiv = document.getElementById('suggestedAgent');
      if (agentDiv) {
        agentDiv.innerHTML = '';
        agentDiv.style.display = 'none';
        delete agentDiv.dataset.agentId;
      }
      if (document.getElementById('agentActions')) document.getElementById('agentActions').style.display = 'none';
      if (document.getElementById('otherAgentSelect')) document.getElementById('otherAgentSelect').style.display = 'none';
    },
    err => {
      statusDiv.className = 'location-status location-error';
      statusDiv.textContent = 'Error: ' + err.message;
    }
  );
}

function clearLocationUser() {
  document.getElementById('user_lat').value = '';
  document.getElementById('user_lng').value = '';
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

/* -------------------- GET NEAREST AGENT -------------------- */
document.getElementById('getAgentBtn').onclick = function () {
  const location = document.getElementById('user_location').value.trim();
  const lat = document.getElementById('user_lat').value.trim();
  const lng = document.getElementById('user_lng').value.trim();

  if (!location && (!lat || !lng)) {
    alert('Please enter your address or latitude/longitude.');
    return;
  }

  const params = new URLSearchParams();
  if (location) params.append('location', location);
  if (lat) params.append('lat', lat);
  if (lng) params.append('lng', lng);

  fetch('get_nearest_agent.php?' + params.toString())
    .then(res => res.json())
    .then((data) => {
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;

      if (data && data.id) {
        agentDiv.innerHTML = `
          <div style="display:flex;align-items:center;gap:12px;padding:10px 0;">
            <img src="${data.photo}" alt="Agent Photo" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e6e6e6;">
            <div>
              <div style="font-weight:700;color:#23613b;font-size:1.1em;">${data.name}</div>
              <div style="font-size:0.98em;color:#444;">${data.email}<br>${data.mobile}<br>${data.city}${data.address ? ', ' + data.address : ''}</div>
            </div>
          </div>`;
        agentDiv.dataset.agentId = data.id;
        agentDiv.style.display = 'block';
        document.getElementById('agentActions').style.display = 'block';
        document.getElementById('otherAgentSelect').style.display = 'none';
      } else {
        agentDiv.textContent = 'No agent found near your location.';
        agentDiv.style.display = 'block';
        delete agentDiv.dataset.agentId;
        document.getElementById('agentActions').style.display = 'none';
        document.getElementById('otherAgentSelect').style.display = 'none';
      }
    })
    .catch(() => {
      const agentDiv = document.getElementById('suggestedAgent');
      if (!agentDiv) return;
      agentDiv.textContent = 'Could not find agent (network error).';
      agentDiv.style.display = 'block';
      delete agentDiv.dataset.agentId;
      document.getElementById('agentActions').style.display = 'none';
      document.getElementById('otherAgentSelect').style.display = 'none';
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
  formData.append('firstName', document.getElementById('firstName').value);
  formData.append('lastName', document.getElementById('lastName').value);
  formData.append('email', document.getElementById('email').value);
  formData.append('phone', document.getElementById('phone').value);
  formData.append('lot_no', lot_no);
  formData.append('preferredDateTime', document.getElementById('preferredDateTime').value || '');
  formData.append('latitude', document.getElementById('user_lat').value || '');
  formData.append('longitude', document.getElementById('user_lng').value || '');
  formData.append('location_id', document.getElementById('location_id').value || '');
  formData.append('lot_id', document.getElementById('lot_id').value || '');

  fetch('submit_viewing.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then((data) => {
      if (data.success) {
        alert('Your viewing request has been submitted! We will contact you soon.');
        closeViewingModal();
      } else {
        alert('Error submitting request: ' + (data.error || 'Unknown error'));
      }
    })
    .catch((err) => alert('Network error: ' + err.message));
});

/* -------------------- CLICK OUTSIDE TO CLOSE MODAL -------------------- */
window.addEventListener('click', function (event) {
  const modal = document.getElementById('viewingModal');
  if (event.target === modal) closeViewingModal();
});

/* Manual agent list */
function fetchAllAgentsForSelect() {
  fetch('get_all_agents.php')
    .then(res => res.json())
    .then(list => {
      const select = document.getElementById('manualAgentSelect');
      select.innerHTML = '<option value="">Select an agent</option>';
      list.forEach(agent => {
        select.innerHTML += `<option value="${agent.id}">${agent.name} (${agent.email})</option>`;
      });
    });
}

document.getElementById('pickSuggestedAgentBtn').onclick = function() {
  const agentDiv = document.getElementById('suggestedAgent');
  if (agentDiv && agentDiv.dataset.agentId) {
    document.getElementById('manualAgentSelect').value = '';
    document.getElementById('otherAgentSelect').style.display = 'none';
  }
};

document.getElementById('chooseOtherAgentBtn').onclick = function() {
  fetchAllAgentsForSelect();
  document.getElementById('otherAgentSelect').style.display = 'block';
};

document.getElementById('manualAgentSelect').onchange = function() {
  const agentDiv = document.getElementById('suggestedAgent');
  if (!agentDiv) return;
  if (this.value) {
    agentDiv.dataset.agentId = this.value;
  } else {
    delete agentDiv.dataset.agentId;
  }
};
</script>
</body>
</html>
