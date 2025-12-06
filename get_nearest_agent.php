<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. ENSURE dbconn.php IS CORRECT ---
// This file must correctly set up the $conn mysqli object.
require_once 'dbconn.php'; 

header('Content-Type: application/json');

// --- 2. INPUT VALIDATION & SANITIZATION ---
$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lng = isset($_GET['lng']) ? filter_var($_GET['lng'], FILTER_VALIDATE_FLOAT) : null;
$location = trim($_GET['location'] ?? '');

$sql = null; // Default value
$agent = null; // Default agent result

// Check if valid coordinates were passed
if ($lat !== false && $lng !== false && $lat !== null && $lng !== null) {
    // --- 3. THE HAVERSINE CALCULATION (Proximity Search) ---
    // Make sure 'latitude' and 'longitude' exist in the 'agent_accounts' table
    // and hold DECIMAL/DOUBLE values.
    $sql = "
        SELECT id, first_name, last_name, email, mobile, city, address, profile_picture,
        (
            6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + 
                sin(radians(?)) * sin(radians(latitude))
            )
        ) AS distance_km
        FROM agent_accounts
        WHERE availability = 1 
          AND latitude IS NOT NULL 
          AND longitude IS NOT NULL
        ORDER BY distance_km ASC
        LIMIT 1
    ";
    
    // Bind parameters: 'ddd' for double, double, double ($lat, $lng, $lat)
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        // Handle SQL preparation error
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error, 'sql' => $sql]);
        exit;
    }
    
    $stmt->bind_param('ddd', $lat, $lng, $lat);
    $stmt->execute();
    $res = $stmt->get_result();
    $agent = $res->fetch_assoc();
    $stmt->close();

} elseif ($location !== '') {
    // --- 4. FALLBACK BY CITY/ADDRESS (Text Search) ---
    $sql = "
        SELECT id, first_name, last_name, email, mobile, city, address, profile_picture 
        FROM agent_accounts
        WHERE availability = 1 AND (city = ? OR address LIKE ?)
        ORDER BY id ASC LIMIT 1
    ";
    $like = "%$location%";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error, 'sql' => $sql]);
        exit;
    }
    
    // Bind parameters: 'ss' for string, string ($location, $like)
    $stmt->bind_param('ss', $location, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $agent = $res->fetch_assoc();
    $stmt->close();
}

// --- 5. OUTPUT RESPONSE ---
if ($agent) {
    echo json_encode([
        'id'        => (int)$agent['id'],
        'name'      => $agent['first_name'].' '.$agent['last_name'],
        'email'     => $agent['email'],
        'mobile'    => $agent['mobile'],
        'city'      => $agent['city'] ?? '',
        'address'   => $agent['address'] ?? '',
        // Use 'profile_picture' or default. The column name was updated here.
        'photo'     => $agent['profile_picture'] ?: 'assets/default-agent.png',
        // DEBUG: show the distance if available
        'distance_km' => isset($agent['distance_km']) ? round($agent['distance_km'], 2) : 'N/A'
    ]);
} else {
    echo json_encode(['debug' => 'No agent found', 'sql' => $sql, 'lat_input' => $lat, 'lng_input' => $lng]);
}

// Close the DB connection (assuming $conn is available)
if (isset($conn)) {
    $conn->close();
}