<?php
session_start();
require 'db_connection.php';
$agentId = (int)($_SESSION['agent_id'] ?? 0);

function resolveUserId(mysqli $conn, string $buyer): ?int {
    $buyer = trim($buyer);
    if ($buyer === '') {
        return null;
    }

    // Try email first.
    if ($stmt = $conn->prepare("SELECT id FROM user_accounts WHERE email = ? LIMIT 1")) {
        $stmt->bind_param("s", $buyer);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return (int)$row['id'];
        }
        $stmt->close();
    }

    // Then full name match.
    if ($stmt = $conn->prepare("SELECT id FROM user_accounts WHERE TRIM(CONCAT(first_name, ' ', last_name)) = ? LIMIT 1")) {
        $stmt->bind_param("s", $buyer);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return (int)$row['id'];
        }
        $stmt->close();
    }

    return null;
}

function resolveLotId(mysqli $conn, string $property): ?int {
    $property = trim($property);
    if ($property === '') {
        return null;
    }

    // If property is a numeric lot id.
    if (ctype_digit($property)) {
        $lotId = (int)$property;
        if ($stmt = $conn->prepare("SELECT id FROM lots WHERE id = ? LIMIT 1")) {
            $stmt->bind_param("i", $lotId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->fetch_assoc()) {
                $stmt->close();
                return $lotId;
            }
            $stmt->close();
        }
    }

    // Match common text formats: "Block X Lot Y", "X-Y", or lot number only.
    $sql = "
        SELECT id
        FROM lots
        WHERE TRIM(CONCAT('Block ', block_number, ' Lot ', lot_number)) = ?
           OR TRIM(CONCAT(block_number, '-', lot_number)) = ?
           OR CAST(lot_number AS CHAR) = ?
        LIMIT 1
    ";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sss", $property, $property, $property);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return (int)$row['id'];
        }
        $stmt->close();
    }

    return null;
}

function assignOwnerFromSale(mysqli $conn, string $property, string $buyer): array {
    $userId = resolveUserId($conn, $buyer);
    $lotId = resolveLotId($conn, $property);

    if (!$userId || !$lotId) {
        return [
            'assigned' => false,
            'lot_id' => $lotId,
            'user_id' => $userId,
            'reason' => !$userId ? 'buyer_not_found' : 'lot_not_found'
        ];
    }

    if ($stmt = $conn->prepare("UPDATE lots SET owner_id = ?, status = CASE WHEN status = 'Available' THEN 'Sold' ELSE status END WHERE id = ?")) {
        $stmt->bind_param("ii", $userId, $lotId);
        $ok = $stmt->execute();
        $stmt->close();
        return [
            'assigned' => (bool)$ok,
            'lot_id' => $lotId,
            'user_id' => $userId,
            'reason' => $ok ? null : 'update_failed'
        ];
    }

    return [
        'assigned' => false,
        'lot_id' => $lotId,
        'user_id' => $userId,
        'reason' => 'prepare_failed'
    ];
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['sales']) || !is_array($data['sales'])) {
    echo json_encode(['success' => false, 'error' => 'No sales data received']);
    exit;
}

$ownerAssignments = [];

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
        $ownerAssignments[] = assignOwnerFromSale($conn, $property, $buyer);
    } else {
        $stmt = $conn->prepare("INSERT INTO sales (agent_id, property, buyer, sale_price, sale_date) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
        $stmt->bind_param("issds", $agentId, $property, $buyer, $sale_price, $sale_date);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            exit;
        }
        $stmt->close();
        $ownerAssignments[] = assignOwnerFromSale($conn, $property, $buyer);
    }
}

$assignedCount = 0;
$notAssigned = [];
foreach ($ownerAssignments as $item) {
    if (!empty($item['assigned'])) {
        $assignedCount++;
    } else {
        $notAssigned[] = $item;
    }
}

echo json_encode([
    'success' => true,
    'owner_assigned_count' => $assignedCount,
    'owner_unassigned_count' => count($notAssigned),
    'owner_unassigned' => $notAssigned
]);
?>