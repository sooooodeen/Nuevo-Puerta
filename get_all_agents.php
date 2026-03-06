<?php
header('Content-Type: application/json');
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "nuevopuerta";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode([]);
    exit;
}

// Detect availability column
$availCol = null;
$colCheck = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agent_accounts' AND COLUMN_NAME IN ('availability','is_available')");
while ($colCheck && $r = $colCheck->fetch_assoc()) {
    if ($r['COLUMN_NAME'] === 'availability') { $availCol = 'availability'; break; }
    if ($r['COLUMN_NAME'] === 'is_available') $availCol = 'is_available';
}
$availSelect = $availCol ? ", IFNULL($availCol, 1) AS is_avail" : ", 1 AS is_avail";

// Optional user coordinates for distance calculation
$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lng = isset($_GET['lng']) ? filter_var($_GET['lng'], FILTER_VALIDATE_FLOAT) : null;

$distSelect = "";
$distOrder = "first_name, last_name";
$params = [];
$types = "";

if ($lat !== null && $lat !== false && $lng !== null && $lng !== false) {
    $distSelect = ", (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_km";
    $distOrder = "distance_km ASC";
    $types = "ddd";
    $params = [$lat, $lng, $lat];
}

$sql = "SELECT id, first_name, last_name, email, mobile, city, address, profile_picture, latitude, longitude $availSelect $distSelect FROM agent_accounts ORDER BY $distOrder";

$agents = [];
if ($types) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $agents[] = formatAgent($row);
        $stmt->close();
    }
} else {
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) $agents[] = formatAgent($row);
    }
}

$conn->close();
echo json_encode($agents);

function formatAgent($row) {
    return [
        'id'          => (int)$row['id'],
        'name'        => trim($row['first_name'] . ' ' . $row['last_name']),
        'email'       => $row['email'],
        'mobile'      => $row['mobile'] ?? '',
        'city'        => $row['city'] ?? '',
        'address'     => $row['address'] ?? '',
        'photo'       => $row['profile_picture'] ?: 'assets/default-agent.png',
        'is_available'=> (int)($row['is_avail'] ?? 1),
        'distance_km' => isset($row['distance_km']) ? round($row['distance_km'], 2) : null
    ];
}
