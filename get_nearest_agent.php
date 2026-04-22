<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'dbconn.php'; 

header('Content-Type: application/json');

// Detect which availability column exists
$availCol = 'availability'; // default
$colCheck = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agent_accounts' AND COLUMN_NAME='availability'");
$hasAvailability = $colCheck && ($colCheck->fetch_assoc()['c'] ?? 0) > 0;
if (!$hasAvailability) {
    $colCheck2 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agent_accounts' AND COLUMN_NAME='is_available'");
    $hasIsAvailable = $colCheck2 && ($colCheck2->fetch_assoc()['c'] ?? 0) > 0;
    $availCol = $hasIsAvailable ? 'is_available' : null;
}
$availWhere = $availCol ? "$availCol = 1" : "1=1";

$hasReviewsTable = false;
$reviewsCheck = $conn->query("SHOW TABLES LIKE 'agent_reviews'");
if ($reviewsCheck && $reviewsCheck->num_rows > 0) {
    $hasReviewsTable = true;
}

$reviewsSelect = $hasReviewsTable
    ? ", IFNULL(ar.avg_rating, 0) AS avg_rating, IFNULL(ar.review_count, 0) AS review_count"
    : ", 0 AS avg_rating, 0 AS review_count";

$reviewsJoin = $hasReviewsTable
    ? " LEFT JOIN (
            SELECT agent_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
            FROM agent_reviews
            GROUP BY agent_id
        ) ar ON ar.agent_id = agent_accounts.id"
    : '';

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lng = isset($_GET['lng']) ? filter_var($_GET['lng'], FILTER_VALIDATE_FLOAT) : null;
$location = trim($_GET['location'] ?? '');

$agent = null;

if ($lat !== false && $lng !== false && $lat !== null && $lng !== null) {
    $sql = "
        SELECT agent_accounts.id, first_name, last_name, email, mobile, city, address, profile_picture
        $reviewsSelect,
        (
            6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + 
                sin(radians(?)) * sin(radians(latitude))
            )
        ) AS distance_km
        FROM agent_accounts
        $reviewsJoin
        WHERE $availWhere
          AND latitude IS NOT NULL 
          AND longitude IS NOT NULL
        ORDER BY distance_km ASC
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param('ddd', $lat, $lng, $lat);
    $stmt->execute();
    $res = $stmt->get_result();
    $agent = $res->fetch_assoc();
    $stmt->close();

    // if no available agent was returned, try again ignoring availability filter
    if (!$agent) {
        $fallbackSql = str_replace("WHERE $availWhere", 'WHERE 1=1', $sql);
        $stmt2 = $conn->prepare($fallbackSql);
        if ($stmt2) {
            $stmt2->bind_param('ddd', $lat, $lng, $lat);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $agent = $res2->fetch_assoc();
            $stmt2->close();
        }
    }

} elseif ($location !== '') {
    $sql = "
        SELECT agent_accounts.id, first_name, last_name, email, mobile, city, address, profile_picture
        $reviewsSelect
        FROM agent_accounts
        $reviewsJoin
        WHERE $availWhere AND (city = ? OR address LIKE ?)
        ORDER BY id ASC LIMIT 1
    ";
    $like = "%$location%";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['error' => 'SQL prepare failed: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param('ss', $location, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $agent = $res->fetch_assoc();
    $stmt->close();
}

if ($agent) {
    echo json_encode([
        'id'          => (int)$agent['id'],
        'name'        => $agent['first_name'].' '.$agent['last_name'],
        'email'       => $agent['email'],
        'mobile'      => $agent['mobile'],
        'city'        => $agent['city'] ?? '',
        'address'     => $agent['address'] ?? '',
        'photo'       => $agent['profile_picture'] ?: 'assets/default-agent.png',
        'avg_rating'  => round((float)($agent['avg_rating'] ?? 0), 1),
        'review_count'=> (int)($agent['review_count'] ?? 0),
        'distance_km' => isset($agent['distance_km']) ? round($agent['distance_km'], 2) : null
    ]);
} else {
    echo json_encode(['error' => 'No agent found near your location.']);
}

if (isset($conn)) {
    $conn->close();
}