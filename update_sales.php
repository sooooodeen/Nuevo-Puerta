<?php
require 'db_connection.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['sales']) || !is_array($data['sales'])) {
    echo json_encode(['success' => false, 'error' => 'No sales data received']);
    exit;
}

foreach ($data['sales'] as $sale) {
    $property = $sale['property'] ?? '';
    $buyer = $sale['buyer'] ?? '';
    $sale_price = is_numeric($sale['sale_price']) ? $sale['sale_price'] : 0;
    $sale_date = $sale['sale_date'] ?? '';
    $id = $sale['id'] ?? null;

    if (!empty($id)) {
        $stmt = $conn->prepare("UPDATE sales SET property=?, buyer=?, sale_price=?, sale_date=? WHERE id=?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
        $stmt->bind_param("ssdsi", $property, $buyer, $sale_price, $sale_date, $id);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO sales (property, buyer, sale_price, sale_date) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
        $stmt->bind_param("ssds", $property, $buyer, $sale_price, $sale_date);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }
        $stmt->close();
    }
}

echo json_encode(['success' => true]);
?>