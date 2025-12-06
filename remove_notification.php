<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false]); exit; }
$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
$id = (int)($data['id'] ?? 0);
if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid notification.']); exit; }

$conn = new mysqli('localhost','root','','nuevopuerta');
if ($conn->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$stmt = $conn->prepare("DELETE FROM viewings WHERE id=? AND client_email=?");
$stmt->bind_param('is', $id, $_SESSION['email']);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>$ok]);