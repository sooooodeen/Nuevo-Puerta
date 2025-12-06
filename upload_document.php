<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

$user_id = (int)$_SESSION['user_id'];
$doc_type = $_POST['doc_type'] ?? '';
$status = 'Pending';

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'No file uploaded']); exit;
}

$uploadDir = 'uploads/documents/';
if (!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

$f = $_FILES['document'];
$ext = pathinfo($f['name'], PATHINFO_EXTENSION);
$fileName = uniqid('doc_').'.'.$ext;
$filePath = $uploadDir.$fileName;

if (!move_uploaded_file($f['tmp_name'], $filePath)) {
    echo json_encode(['success'=>false,'message'=>'Upload failed']); exit;
}

// Save to DB
$conn = new mysqli('localhost','root','','nuevopuerta');
if ($conn->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }
$stmt = $conn->prepare("INSERT INTO user_documents (user_id,file_name,file_path,doc_type,status) VALUES (?,?,?,?,?)");
$stmt->bind_param('issss',$user_id,$f['name'],$filePath,$doc_type,$status);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>true,'path'=>$filePath,'name'=>$f['name']]);