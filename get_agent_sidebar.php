<?php
session_start();
require 'db_connection.php';

$agent_id = $_SESSION['agent_id'] ?? null;
if (!$agent_id) {
    echo json_encode(['error' => 'No agent ID']);
    exit;
}

$stmt = $conn->prepare("SELECT first_name, last_name, id FROM agents_accounts WHERE id = ?");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode(['error' => 'Agent not found']);
}
?>