<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "nuevopuerta";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'DB connection failed']));
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;

if ($id) {
    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'error' => 'No ID provided']);
}
$conn->close();
?>