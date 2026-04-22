<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Require login
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['photo'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$f = $_FILES['photo'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Upload error code: '.$f['error']]);
    exit;
}

// <= 8MB
$maxBytes = 8 * 1024 * 1024;
if ($f['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'File too large (max 8MB)']);
    exit;
}

// Simple extension check
$allowedExt = ['jpg','jpeg','png','gif','webp'];
$origName   = $f['name'];
$ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    exit;
}

// Ensure /assets/profile_photos
$destDir = __DIR__ . '/assets/profile_photos';
if (!is_dir($destDir)) {
    if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit;
    }
}

// Build filename
$userId   = (int)$_SESSION['user_id'];
$base     = 'u'.$userId.'_'.date('Ymd_His').'_'.uniqid();
$filename = $base.'.'.$ext;
$destPath = $destDir.'/'.$filename;

if (!move_uploaded_file($f['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

$publicPath = 'assets/profile_photos/'.$filename;
$_SESSION['profile_photo'] = $publicPath;

echo json_encode([
    'success' => true,
    'path'    => $publicPath,
    'message' => 'Uploaded'
]);
exit;
