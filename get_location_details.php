<?php
$conn = new mysqli("localhost", "root", "", "nuevopuerta");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;

// Get location name
$sql = "SELECT location_name FROM lot_locations WHERE id = $location_id";
$result = $conn->query($sql);
$location_name = '';
if ($result && $row = $result->fetch_assoc()) {
    $location_name = $row['location_name'];
}

// Get blueprint
$sql = "SELECT filename FROM blueprints WHERE location_id = $location_id LIMIT 1";
$result = $conn->query($sql);
$blueprint_url = '';
if ($result && $row = $result->fetch_assoc()) {
    $blueprint_url = 'blueprints/' . $row['filename'];
}

// Get lots
$sql = "SELECT block_number, lot_number, lot_size, lot_price, lot_status FROM lots WHERE location_id = $location_id";
$result = $conn->query($sql);
$lots = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $lots[] = $row;
    }
}

echo json_encode([
    'location_name' => $location_name,
    'blueprint_url' => $blueprint_url,
    'lots' => $lots
]);