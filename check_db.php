<?php
$conn = new mysqli('localhost', 'root', '', 'nuevopuerta');
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "=== LOTS TABLE ===\n";
$result = $conn->query('SELECT id, block_number, lot_number, location_id, status FROM lots LIMIT 10');
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "Lot ID: {$row['id']}, Block: {$row['block_number']}, Lot: {$row['lot_number']}, Location: {$row['location_id']}, Status: {$row['status']}\n";
    }
} else {
    echo "No lots found in database\n";
}

echo "\n=== PIN_LOCATIONS TABLE ===\n";
$result = $conn->query('SELECT lot_id, pin_status FROM pin_locations LIMIT 10');
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "Lot ID: {$row['lot_id']}, Pin Status: {$row['pin_status']}\n";
    }
} else {
    echo "No pins found in database\n";
}

echo "\n=== LOCATIONS TABLE ===\n";
$result = $conn->query('SELECT id, location_name FROM lot_locations');
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "Location ID: {$row['id']}, Name: {$row['location_name']}\n";
    }
} else {
    echo "No locations found\n";
}

$conn->close();
?>
