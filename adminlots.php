<?php
// Database connection
$host = 'localhost';
$dbname = 'lotmanager';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lat = $_POST['lat'];
    $lng = $_POST['lng'];
    $location_name = $_POST['location_name']; // New field

    $sql = "INSERT INTO lot_locations (latitude, longitude, location_name)
            VALUES (:latitude, :longitude, :location_name)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':latitude', $lat);
    $stmt->bindParam(':longitude', $lng);
    $stmt->bindParam(':location_name', $location_name);

    if ($stmt->execute()) {
        header("Location: adminlots.php?success=true");
        exit;
    } else {
        header("Location: adminlots.php?success=false");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
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
      background-color: transparent;
      padding: 25px;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .sidebar {
      width: 265px;
      background-color: #3e5f3e;
      border-radius: 5px;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 40px 15px;
      height: calc(100vh - 120px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .logo-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 30px;
    }

    .logo-title h2 {
      color: white;
      font-size: 18px;
      font-weight: 600;
      margin: 0;
      line-height: 1.2;
      font-family: 'Inter', sans-serif;
    }

    .sidebar img.profile-pic {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      object-fit: cover;
      background-color: transparent;
    }

    .user-profile {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background-color: white;
      padding: 16px;
      border-radius: 8px;
      width: 100%;
      margin-bottom: 30px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
      text-align: center;
    }

    .user-profile img {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      object-fit: cover;
      margin-right: 8px;
      background-color: #d9d9d9;
      margin-bottom: 10px;
    }

    .user-details {
      font-size: 12px;
      color: black;
      line-height: 1.2;
    }

    .nav {
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    .nav a {
      color: white;
      text-decoration: none;
      padding: 10px;
      margin-bottom: 6px;
      text-align: center;
      border-radius: 5px;
      display: block;
    }

    .nav a:hover {
      background-color: #3D4D26;
    }

    .nav-icon {
      width: 24px;
      height: 24px;
      margin-right: 4px;
      vertical-align: middle;
    }

    .container {
      flex: 1;
      padding: 40px;
      display: flex;
      flex-direction: column;
    }

    .divider {
      width: 5px;
      background-color: #2D4D26;
      height: calc(100vh - 40px);
      margin-top: 20px;
      border-radius: 5px;
    }

    .main-content {
      flex: 1;
      padding: 40px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
    }

    .profile-card {
      display: flex;
      background-color: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      width: 100%;
      height: 100%;
    }

    .secondary-sidebar {
      width: 20%;
      background-color: #f8f9fa;
      padding: 20px;
      border-right: 1px solid #ddd;
      height: 100%;
      overflow-y: auto;
    }

    .secondary-sidebar a {
        text-decoration: none; /* Remove underline */
        color: inherit; /* Keep the text color unchanged */
    }

    .secondary-sidebar a:hover {
        text-decoration: none; /* Ensure no underline on hover */
        color: inherit; /* Keep the text color unchanged on hover */
    }

    .sidebar-header {
      font-size: 24px;
      padding-bottom: 1.5rem;
      cursor: default;
    }

    .sidebar-section {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .sidebar-item {
      padding-top: 3px;
      padding-bottom: 3px;
      cursor: pointer;
      transition: background-color 0.3s ease;
    }

    .sidebar-item:hover {
      background-color: #e0e0e0;
      color: #2d482d;
      border-radius: 5px;
    }

    .form-card {
      flex: 1;
      background-color: #fff;
      border-radius: 10px;
      padding: 30px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      height: 100%;
      overflow-y: auto;
    }

    .form-card h2 {
      font-size: 28px;
      color: #2d482d;
      margin-bottom: 25px;
      cursor: default;
    }

    .map-card {
      flex: 1;
      background-color: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      padding: 20px;
      margin-left: 20px;
      height: 100%;
      overflow-y: auto;
    }

    #map {
      height: 500px; /* Set a fixed height for the map container */
      width: 100%;
      margin-top: 20px;
      cursor: default;
    }

    .save-btn {
      align-self: flex-end;
      background-color: #2d482d;
      color: white;
      padding: 10px 30px;
      border: none;
      font-size: 14px;
      border-radius: 5px;
      cursor: pointer;
      margin-top: 10px;
    }
  </style>
</head>
<body>

  <div class="sidebar-wrapper">
    <div class="sidebar">
      <div class="logo-title">
        <img src="assets/a.png" alt="Logo" class="profile-pic">
        <h2>NUEVO PUERTA</h2>
      </div>

      <div class="user-profile">
        <img src="assets/s.png" alt="User Image">
        <div class="user-details">
          <div>John Eithan Apolinario</div>
          <div>Super Admin</div>
        </div>
      </div>

      <div class="nav">
        <a href="admindashboard.php"><img src="assets/mdi_home.png" class="nav-icon"> <span>Home</span></a>
        <a href="adminaccounts.php"><img src="assets/mdi_user.png" class="nav-icon"> <span>Manage Accounts</span></a>
        <a href="adminlots.php"><img src="assets/lotpinicon.png" class="nav-icon"> <span>Lots</span></a>
        <a href="logout.php"><img src="assets/ic_baseline-logout.png" class="nav-icon"> <span>Logout</span></a>
      </div>
    </div>
  </div>

  <div class="divider"></div>

  <div class="main-content">
    <div class="profile-card">
      <div class="secondary-sidebar">
        <div class="sidebar-section">
          <div class="sidebar-header">Lots CMS</div>
          <div class="sidebar-item">
            <a href="adminlots.php">
              <span>Pin New Location</span>
            </a>
          </div>
          <div class="sidebar-item">
            <a href="adminblueprints.php">
              <span>Upload Blueprint</span>
            </a>
          </div>
        </div>
      </div>

      <div class="map-card">
        <h2>Admin Dashboard - Map View</h2>
        <div id="map"></div>

        <form method="POST">
          <input type="hidden" id="lat" name="lat" required>
          <input type="hidden" id="lng" name="lng" required>
          <input type="hidden" id="location_name" name="location_name" required>
          <button type="submit">Save Lot Location</button>
        </form>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script>
  const map = L.map('map').setView([6.9214, 122.0790], 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
  }).addTo(map);

  let marker;

  map.on('click', function (e) {
    const { lat, lng } = e.latlng;

    document.getElementById('lat').value = lat;
    document.getElementById('lng').value = lng;

    if (marker) {
      map.removeLayer(marker);
    }

    marker = L.marker([lat, lng]).addTo(map);

    // Reverse geocoding with Nominatim (alert removed)
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
      .then(response => response.json())
      .then(data => {
        const name = data.display_name || 'Unknown location';
        document.getElementById('location_name').value = name;
      })
      .catch(() => {
        document.getElementById('location_name').value = 'Unknown';
      });
  });

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('success') === 'true') {
    alert("Lot location saved successfully!");
    window.location.href = 'adminlots.php';
  } else if (urlParams.get('success') === 'false') {
    alert("Failed to save lot location.");
  }
</script>
</body>
</html>
