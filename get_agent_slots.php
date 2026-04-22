<?php
// get_agent_slots.php
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
$agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$agent_id) {
    echo json_encode([]);
    exit;
}

// Ensure agent exists in agent_accounts
$check = $conn->prepare("SELECT id FROM agent_accounts WHERE id = ? LIMIT 1");
$check->bind_param('i', $agent_id);
$check->execute();
$res = $check->get_result();
if (!$res->fetch_assoc()) {
    echo json_encode([]);
    exit;
}
$check->close();



$sql = "SELECT s.available_date, s.time_slot, s.max_clients, s.id AS slot_id
    FROM agent_time_slots s WHERE s.agent_id = ?";
$params = [$agent_id];
$types = 'i';
if ($date) {
        $sql .= " AND s.available_date = ?";
        $params[] = $date;
        $types .= 's';
}
$sql .= " ORDER BY s.available_date ASC, s.time_slot ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$slots = [];
while ($row = $res->fetch_assoc()) {
        // Count bookings for this slot (date, time, agent)
        $count_stmt = $conn->prepare("SELECT COUNT(*) FROM viewings WHERE agent_id = ? AND DATE(preferred_at) = ? AND TIME(preferred_at) = ? AND status IN ('pending','scheduled','rescheduled')");
        $count_stmt->bind_param('iss', $agent_id, $row['available_date'], $row['time_slot']);
        $count_stmt->execute();
        $count_stmt->bind_result($booked_count);
        $count_stmt->fetch();
        $count_stmt->close();
        $row['booked_count'] = (int)$booked_count;
        $slots[] = $row;
}
$stmt->close();
$conn->close();
echo json_encode($slots);
