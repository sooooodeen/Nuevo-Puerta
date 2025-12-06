<?php
$host = "localhost";
$user = "root";
$pass = ""; // your db password
$db = "nuevopuerta"; // your db name

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ADD id TO THE SELECT QUERY!
$sql = "SELECT id, property, buyer, sale_price, sale_date FROM sales";
$result = $conn->query($sql);

$data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}
echo json_encode($data);
$conn->close();
?>