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

/* ---------------------------------- */
/* 3. Main Content & Map */
/* ---------------------------------- */
.adminlots-main { margin-top: 0; display: flex; height: calc(100vh - 80px); padding: 20px; gap: 20px; box-sizing: border-box; }
.map-panel { flex: 1.8; background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 20px; display: flex; flex-direction: column; }
#map { flex: 1; border-radius: 8px; width: 100%; height: 100%; }
.info-panel { flex: 1.5; background: #f8f9fa; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 20px; display: flex; flex-direction: column; min-width: 440px; overflow-y: auto; }
.info-panel h3 { margin: 0 0 10px 0; font-size: 1.1em; color: #2d4e1e; }

/* ---------------------------------- */
/* 4. Blueprint Modal (UPDATED) */
/* ---------------------------------- */
.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: hidden; background: rgba(0,0,0,0.85); }
.blueprint-white-bg { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
.close-bp { position: absolute; top: 20px; right: 40px; color: #fff; font-size: 40px; font-weight: bold; cursor: pointer; z-index: 2100; }

/* Wrapper for Image + SVG Overlay */
#blueprint-wrapper { position: relative; display: inline-block; line-height: 0; transform-origin: center center; cursor: grab; }
#blueprint-wrapper:active { cursor: grabbing; }
.modal-content { display: block; max-width: 90vw; max-height: 90vh; pointer-events: none; /* Let clicks pass to SVG */ }
#blueprint-svg-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 10; pointer-events: none; }

/* Interactive Lot Shapes */
.lot-shape { cursor: pointer; stroke: #fff; stroke-width: 1px; transition: opacity 0.2s; pointer-events: auto; }
.lot-shape:hover { opacity: 0.8; stroke: #333; stroke-width: 2px; }

/* Dynamic Status Colors */
.status-available { fill: rgba(46, 204, 113, 0.5); stroke: green; } /* Green */
.status-sold { fill: rgba(231, 76, 60, 0.5); stroke: red; }       /* Red */
.status-reserved { fill: rgba(241, 196, 15, 0.5); stroke: gold; } /* Yellow */

/* Tooltip */
.lot-tooltip { position: absolute; background: rgba(0,0,0,0.8); color: #fff; padding: 6px 12px; border-radius: 4px; font-size: 13px; pointer-events: none; display: none; z-index: 3000; white-space: pre-line; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }

/* ---------------------------------- */
/* 5. Tables & Buttons */
/* ---------------------------------- */
.blueprint-btn { background: #3a6c28; color: #fff; border: none; border-radius: 5px; padding: 8px 18px; font-size: 15px; cursor: pointer; }
.blueprint-btn:hover { background: #f4d03f; color: #2d4e1e; }
.lots-table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; border-radius: 8px; overflow: hidden; }
.lots-table th, .lots-table td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #e0e0e0; }
.lots-table th { background: #e8f5e9; color: #2d4e1e; font-weight: bold; }
.lot-status { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; color: #fff; display: inline-block; }
.lot-status.Available { background: #2ecc71; }
.lot-status.Sale { background: #2ecc71; }
.lot-status.Sold { background: #e74c3c; }
.lot-status.Reserved { background: #f1c40f; color: #333; }
.inquire-btn { background: #3a6c28; color: #fff; border: none; border-radius: 5px; padding: 4px 12px; cursor: pointer; }
.inquire-btn:hover { background: #2d4e1e; }

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

/* User Message Modal */
#userMessageModal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.32); align-items: center; justify-content: center; }
#userMessageModal > div { background: #fff; padding: 30px; border-radius: 12px; max-width: 350px; text-align: center; position: relative; }
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
            <button type="button" id="getAgentBtn" class="btn-submit" style="padding:6px 12px;">Get Agent</button>
            <div id="suggestedAgent" style="margin-top:10px; display:none;"></div>
            <div id="agentActions" style="margin-top:10px; display:none;">
              <button type="button" id="pickSuggestedAgentBtn" class="btn-submit" style="margin-right:10px;">Pick This Agent</button>
              <button type="button" id="chooseOtherAgentBtn" class="btn-cancel">Choose Other Agent</button>
            </div>
            <div id="otherAgentSelect" style="margin-top:10px; display:none;">
              <label>Select Another Agent:</label>
              <select id="manualAgentSelect" style="width:100%; padding:8px; margin-top:5px;"></select>
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
    const hasBlueprint = blueprints[loc.id] ? true : false;
    renderInfoPanel(loc.id, loc.location_name, lots, hasBlueprint);
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
  `;
}

/* -------------------- INTERACTIVE BLUEPRINT LOGIC -------------------- */
const bpModal = document.getElementById("blueprintModalBox");
const bpImg = document.getElementById("blueprintImg");
const svgLayer = document.getElementById("blueprint-svg-layer");
const bpWrapper = document.getElementById("blueprint-wrapper");
const tooltip = document.getElementById("tooltip");

function openBlueprint(locationId) {
    if (!blueprints[locationId]) return;
    
    bpModal.style.display = "block";
    bpImg.src = blueprints[locationId];
    svgLayer.innerHTML = ''; // Clear old drawings
    
    // Set up our SVG container to read percentages easily
    svgLayer.setAttribute('viewBox', '0 0 100 100');
    
    // Clean up old panzoom completely before starting a new one
    if (panzoomInstance) {
        panzoomInstance.destroy();
    }
    bpWrapper.style.transform = 'scale(1) translate(0px, 0px)'; 

    // Initialize Panzoom
    panzoomInstance = Panzoom(bpWrapper, {
        maxScale: 10,
        minScale: 0.5,
        step: 0.2,
        cursor: 'grab'
    });

    // Draw Lots (Polygons & Rectangles)
    const lots = allLots[locationId] || [];
    lots.forEach(lot => {
        if (lot.coordinates) {
            try {
                const c = JSON.parse(lot.coordinates);
                // Standardize the status string (Available, Sold, Reserved)
                let status = (lot.lot_status || 'Available').toLowerCase();
                let shape;
                
                // If it's a Polygon (from new admin map tool)
                if (c.type === 'polygon' && Array.isArray(c.points)) {
                    shape = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                    const pointsStr = c.points.map(pt => `${pt.x},${pt.y}`).join(' ');
                    shape.setAttribute("points", pointsStr);
                } 
                // Fallback for old Rectangles
                else {
                    shape = document.createElementNS("http://www.w3.org/2000/svg", "rect");
                    shape.setAttribute("x", c.x);
                    shape.setAttribute("y", c.y);
                    shape.setAttribute("width", c.w);
                    shape.setAttribute("height", c.h);
                }
                
                // Add the CSS class that colors it green/yellow/red dynamically
                shape.setAttribute("class", "lot-shape status-" + status);
                
                // Mouse interactions
                shape.addEventListener("mouseenter", (e) => showTooltip(e, lot));
                shape.addEventListener("mousemove", moveTooltip);
                shape.addEventListener("mouseleave", hideTooltip);
                shape.addEventListener("click", (e) => {
                    e.stopPropagation(); // Prevent zoom click
                    if(status !== 'sold') openViewingModal(lot);
                });
                
                svgLayer.appendChild(shape);
            } catch(e) { console.error("Coords Error", e); }
        }
    });
}

// Bind mouse wheel for zooming (bound once to the parent container)
const bpContainer = document.querySelector('.blueprint-white-bg');
if (bpContainer) {
    bpContainer.addEventListener('wheel', function(e) {
        e.preventDefault(); // Prevent page scrolling
        if(panzoomInstance) panzoomInstance.zoomWithWheel(e);
    });
}

function showTooltip(e, lot) {
    tooltip.style.display = "block";
    tooltip.innerHTML = `<b>Block ${lot.block_number} Lot ${lot.lot_number}</b><br>
                         Status: ${lot.lot_status}<br>
                         Price: ₱${(+lot.lot_price).toLocaleString()}<br>
                         Size: ${lot.lot_size} sqm`;
}

function moveTooltip(e) {
    tooltip.style.left = (e.clientX + 15) + "px";
    tooltip.style.top = (e.clientY + 15) + "px";
}

function hideTooltip() { 
    tooltip.style.display = "none"; 
}

function closeBlueprint() { 
    bpModal.style.display = "none"; 
    if(panzoomInstance) {
        panzoomInstance.reset();
        panzoomInstance.destroy();
        panzoomInstance = null;
    }
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
  const status = document.getElementById('user-location-status');
  status.style.display = 'block'; status.innerHTML = 'Locating...';
  if (!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(pos => {
      document.getElementById('user_lat').value = pos.coords.latitude;
      document.getElementById('user_lng').value = pos.coords.longitude;
      status.innerHTML = 'Found!';
  }, err => { status.innerHTML = 'Error.'; });
}
function clearLocationUser() {
  document.getElementById('user_lat').value = '';
  document.getElementById('user_lng').value = '';
  document.getElementById('user-location-status').style.display = 'none';
}

/* Agent Logic */
document.getElementById('getAgentBtn').onclick = function() {
    const loc = document.getElementById('user_location').value;
    const lat = document.getElementById('user_lat').value;
    const lng = document.getElementById('user_lng').value;
    
    // Calls your existing PHP API
    fetch(`get_nearest_agent.php?location=${loc}&lat=${lat}&lng=${lng}`)
    .then(r=>r.json()).then(data => {
        const agDiv = document.getElementById('suggestedAgent');
        agDiv.style.display = 'block';
        if(data && data.id) {
            agDiv.innerHTML = `<div class="agent-card">
                <div class="agent-card-photo"><img src="${data.photo}"></div>
                <div class="agent-card-info">
                   <div class="agent-card-name">${data.name}</div>
                   <div>${data.email}<br>${data.mobile}</div>
                </div>
            </div>`;
            agDiv.dataset.agentId = data.id;
            document.getElementById('agentActions').style.display = 'block';
        } else {
            agDiv.innerHTML = '<div style="padding:10px;color:red;">No agent found.</div>';
        }
    });
};

document.getElementById('pickSuggestedAgentBtn').onclick = function() {
    const id = document.getElementById('suggestedAgent').dataset.agentId;
    if(id) loadAgentSlots(id);
};

document.getElementById('chooseOtherAgentBtn').onclick = function() {
    document.getElementById('otherAgentSelect').style.display = 'block';
    fetch('get_all_agents.php').then(r=>r.json()).then(list => {
        const sel = document.getElementById('manualAgentSelect');
        sel.innerHTML = '<option value="">Select...</option>';
        list.forEach(a => sel.innerHTML += `<option value="${a.id}">${a.name}</option>`);
    });
};

document.getElementById('manualAgentSelect').onchange = function() {
    if(this.value) loadAgentSlots(this.value);
};

function loadAgentSlots(id) {
    const sel = document.getElementById('agentTimeSlot');
    sel.disabled = true; sel.innerHTML = '<option>Loading...</option>';
    fetch('get_agent_slots.php?agent_id='+id).then(r=>r.json()).then(slots => {
        sel.innerHTML = '<option value="">-- Select Date --</option>';
        slots.forEach(s => {
             const opt = document.createElement('option');
             opt.value = s.available_date + ' ' + s.time_slot;
             opt.text = s.available_date + ' (' + s.time_slot + ')';
             sel.appendChild(opt);
        });
        sel.disabled = false;
    });
}

// User Message Modal Helper
function showUserMessageModal(msg, cb) {
    const m = document.getElementById('userMessageModal');
    document.getElementById('userMessageModalText').innerText = msg;
    m.style.display = 'flex';
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
</body>
</html>