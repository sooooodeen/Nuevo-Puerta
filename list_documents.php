<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
$user_id = (int)$_SESSION['user_id'];

$conn = new mysqli('localhost','root','','nuevopuerta');
if ($conn->connect_error) { echo json_encode(['success'=>false,'documents'=>[]]); exit; }
$res = $conn->query("SELECT id, file_name, file_path, uploaded_at, doc_type, status FROM user_documents WHERE user_id=$user_id ORDER BY uploaded_at DESC");
$docs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();
echo json_encode(['success'=>true,'documents'=>$docs]);