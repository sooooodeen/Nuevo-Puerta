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
$sql = "SELECT id, first_name, last_name, email FROM agent_accounts WHERE status = 'active' ORDER BY first_name, last_name";
$result = $conn->query($sql);
$agents = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $agents[] = [
            'id' => $row['id'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'email' => $row['email']
        ];
    }
}
$conn->close();
echo json_encode($agents);
