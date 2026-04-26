<?php
// get_agent_slots.php
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

function tableHasColumn(mysqli $conn, string $table, string $column): bool {
    $tableSafe = $conn->real_escape_string($table);
    $columnSafe = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $res && $res->num_rows > 0;
}

$agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : null;
$lotIdFilter = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
$locationIdFilter = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
if (!$agent_id) {
    echo json_encode([]);
    exit;
}

// Ensure agent exists in agent_accounts
$check = $conn->prepare("SELECT id FROM agent_accounts WHERE id = ? LIMIT 1");
$check->bind_param('i', $agent_id);
$check->execute();
$res = $check->get_result();
if (!$res->fetch_assoc()) {
    echo json_encode([]);
    exit;
}
$check->close();

$slots = [];

$hasSlotLotId = tableHasColumn($conn, 'agent_time_slots', 'lot_id');
$hasSlotLocationId = tableHasColumn($conn, 'agent_time_slots', 'location_id');
$slotLotSelect = $hasSlotLotId ? 's.lot_id' : 'NULL AS lot_id';
$slotJoinLots = $hasSlotLotId ? 'LEFT JOIN lots l ON l.id = s.lot_id' : 'LEFT JOIN lots l ON 1=0';
$slotLocRef = $hasSlotLocationId ? 's.location_id' : 'NULL';

$sql = "SELECT s.available_date, s.time_slot, s.max_clients, s.id AS slot_id, {$slotLotSelect},
               l.block_number, l.lot_number, ll.location_name
        FROM agent_time_slots s
    {$slotJoinLots}
    LEFT JOIN lot_locations ll ON ll.id = COALESCE({$slotLocRef}, l.location_id)
        WHERE s.agent_id = ?";
$params = [$agent_id];
$types = 'i';

if ($date) {
    $sql .= " AND s.available_date = ?";
    $params[] = $date;
    $types .= 's';
} else {
    // Only return current/future slots when date is not explicitly requested.
    $sql .= " AND s.available_date >= CURDATE()";
}

if ($lotIdFilter > 0) {
    if ($hasSlotLotId) {
        $sql .= " AND (COALESCE(s.lot_id, 0) = 0 OR s.lot_id = ?)";
        $params[] = $lotIdFilter;
        $types .= 'i';
    }
}

if ($locationIdFilter > 0) {
    if ($hasSlotLocationId) {
        $sql .= " AND (COALESCE(s.location_id, 0) = 0 OR s.location_id = ?)";
        $params[] = $locationIdFilter;
        $types .= 'i';
    }
}

$sql .= " ORDER BY s.available_date ASC, s.time_slot ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $slot = [
            'available_date' => (string)$row['available_date'],
            'time_slot' => (string)$row['time_slot'],
            'weekday' => (int)date('w', strtotime((string)$row['available_date'])),
            'max_clients' => (int)($row['max_clients'] ?? 1),
            'slot_id' => (int)($row['slot_id'] ?? 0),
            'schedule_type' => 'date_specific',
            'booked_count' => 0,
            'lot_id' => (int)($row['lot_id'] ?? 0),
            'location_name' => (string)($row['location_name'] ?? ''),
            'lot_ref' => '',
            'slot_interval_minutes' => 60,
        ];

        $lotParts = [];
        if (!empty($row['block_number']) && !empty($row['lot_number'])) {
            $lotParts[] = 'Block ' . $row['block_number'] . ', Lot ' . $row['lot_number'];
        } elseif (!empty($row['lot_number'])) {
            $lotParts[] = 'Lot ' . $row['lot_number'];
        }
        if (!empty($row['location_name'])) {
            $lotParts[] = $row['location_name'];
        }
        $slot['lot_ref'] = implode(' - ', $lotParts);

        $slots[] = $slot;
    }
    $stmt->close();
}

$existingDateTimeKeys = [];
foreach ($slots as $s) {
    $existingLotId = (int)($s['lot_id'] ?? 0);
    $existingDateTimeKeys[$s['available_date'] . '|' . $s['time_slot'] . '|' . $existingLotId] = true;
}

if ($checkWeekly = $conn->query("SHOW TABLES LIKE 'agent_weekly_schedules'")) {
    if ($checkWeekly->num_rows > 0) {
        $hasWeeklyTimeSlot = tableHasColumn($conn, 'agent_weekly_schedules', 'time_slot');
        $hasWeeklyStartDate = tableHasColumn($conn, 'agent_weekly_schedules', 'start_date');
        $hasWeeklyLocationId = tableHasColumn($conn, 'agent_weekly_schedules', 'location_id');
        $hasWeeklyIsActive = tableHasColumn($conn, 'agent_weekly_schedules', 'is_active');
        $weeklyLocRef = $hasWeeklyLocationId ? 'ws.location_id' : 'NULL';
        $weeklyTimeSelect = $hasWeeklyTimeSlot ? 'ws.time_slot' : 'NULL AS time_slot';
        $weeklyStartDateSelect = $hasWeeklyStartDate ? 'ws.start_date' : 'NULL AS start_date';
        $weeklyStatusFilter = $hasWeeklyIsActive ? ' AND COALESCE(ws.is_active, 1) = 1' : '';
        $weeklySql = "SELECT ws.id, ws.weekday, {$weeklyStartDateSelect}, {$weeklyTimeSelect}, ws.start_time, ws.end_time, ws.slot_interval_minutes, ws.max_clients, ws.lot_id,
                             COALESCE({$weeklyLocRef}, l.location_id) AS location_id,
                             l.block_number, l.lot_number, ll.location_name
                      FROM agent_weekly_schedules ws
                      LEFT JOIN lots l ON l.id = ws.lot_id
                      LEFT JOIN lot_locations ll ON ll.id = COALESCE({$weeklyLocRef}, l.location_id)
                      WHERE ws.agent_id = ?{$weeklyStatusFilter}";
        $weeklyParams = [$agent_id];
        $weeklyTypes = 'i';

        // Do not hard-filter weekly schedules by selected lot/location.
        // Weekly availability should remain visible to the client for the chosen agent,
        // while date-specific slots still apply as explicit overrides.

        $weeklyStmt = $conn->prepare($weeklySql);
        if ($weeklyStmt) {
            $weeklyStmt->bind_param($weeklyTypes, ...$weeklyParams);
            $weeklyStmt->execute();
            $weeklyRes = $weeklyStmt->get_result();
            $weeklyRows = $weeklyRes ? $weeklyRes->fetch_all(MYSQLI_ASSOC) : [];
            $weeklyStmt->close();

            // Fallback: if strict lot/location filtering yields no weekly rows,
            // return the agent's weekly schedules so availability still appears.
            if (empty($weeklyRows) && ($lotIdFilter > 0 || $locationIdFilter > 0)) {
                  $fallbackSql = "SELECT ws.id, ws.weekday, {$weeklyStartDateSelect}, {$weeklyTimeSelect}, ws.start_time, ws.end_time, ws.slot_interval_minutes, ws.max_clients, ws.lot_id,
                                       COALESCE({$weeklyLocRef}, l.location_id) AS location_id,
                                       l.block_number, l.lot_number, ll.location_name
                                FROM agent_weekly_schedules ws
                                LEFT JOIN lots l ON l.id = ws.lot_id
                                LEFT JOIN lot_locations ll ON ll.id = COALESCE({$weeklyLocRef}, l.location_id)
                                WHERE ws.agent_id = ?{$weeklyStatusFilter}";
                $fallbackStmt = $conn->prepare($fallbackSql);
                if ($fallbackStmt) {
                    $fallbackStmt->bind_param('i', $agent_id);
                    $fallbackStmt->execute();
                    $fallbackRes = $fallbackStmt->get_result();
                    if ($fallbackRes) {
                        $weeklyRows = $fallbackRes->fetch_all(MYSQLI_ASSOC);
                    }
                    $fallbackStmt->close();
                }
            }

            $datePool = [];
            if ($date) {
                $datePool[] = $date;
            } else {
                $today = new DateTimeImmutable('today');
                for ($i = 0; $i < 21; $i++) {
                    $datePool[] = $today->modify('+' . $i . ' day')->format('Y-m-d');
                }
            }

            foreach ($weeklyRows as $weekly) {
                $weekday = (int)($weekly['weekday'] ?? -1);
                $maxClients = (int)($weekly['max_clients'] ?? 1);
                if ($weekday < 0 || $weekday > 6 || $maxClients < 1) {
                    continue;
                }

                $weeklyStartDate = trim((string)($weekly['start_date'] ?? ''));
                if ($weeklyStartDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weeklyStartDate)) {
                    $weeklyStartDate = '';
                }

                $manualTime = trim((string)($weekly['time_slot'] ?? ''));
                // Check for both empty string and the default time MySQL sets for empty strings (00:00:00)
                if ($manualTime === '' || $manualTime === '00:00:00') {
                    $manualTime = trim((string)($weekly['start_time'] ?? ''));
                }

                $timeCandidates = [];
                if ($manualTime !== '' && $manualTime !== '00:00:00') {
                    $tParts = explode(':', $manualTime);
                    if (count($tParts) >= 2) {
                        $hh = str_pad((string)((int)$tParts[0]), 2, '0', STR_PAD_LEFT);
                        $mm = str_pad((string)((int)$tParts[1]), 2, '0', STR_PAD_LEFT);
                        $timeCandidates[] = $hh . ':' . $mm . ':00';
                    }
                }

                // Backward-compatible expansion for old range-based records.
                if (empty($timeCandidates)) {
                    $interval = (int)($weekly['slot_interval_minutes'] ?? 60);
                    if ($interval < 15 || $interval > 240) {
                        $interval = 60;
                    }
                    
                    // Safely parse start_time and end_time
                    $rawStartTime = trim((string)($weekly['start_time'] ?? ''));
                    $rawEndTime = trim((string)($weekly['end_time'] ?? ''));
                    
                    if ($rawStartTime !== '' && $rawEndTime !== '') {
                        $startTs = strtotime('1970-01-01 ' . $rawStartTime);
                        $endTs = strtotime('1970-01-01 ' . $rawEndTime);
                        
                        if ($startTs !== false && $endTs !== false) {
                            $startMinutes = (int)date('H', $startTs) * 60 + (int)date('i', $startTs);
                            $endMinutes = (int)date('H', $endTs) * 60 + (int)date('i', $endTs);
                            
                            if ($endMinutes <= $startMinutes) {
                                $endMinutes = $startMinutes + $interval;
                            }
                            
                            for ($minute = $startMinutes; $minute < $endMinutes; $minute += $interval) {
                                $hh = str_pad((string)floor($minute / 60), 2, '0', STR_PAD_LEFT);
                                $mm = str_pad((string)($minute % 60), 2, '0', STR_PAD_LEFT);
                                $timeCandidates[] = $hh . ':' . $mm . ':00';
                            }
                        }
                    }
                }

                foreach ($datePool as $candidateDate) {
                    $phpWeekday = (int)date('w', strtotime($candidateDate));
                    if ($phpWeekday !== $weekday) {
                        continue;
                    }

                    // If this weekly schedule has a start_date, only apply it for that week
                    if ($weeklyStartDate !== '') {
                        // Only allow dates from start_date to start_date + 6 days (one week)
                        $startTs = strtotime($weeklyStartDate);
                        $endTs = $startTs + (6 * 86400);  // 6 days after start_date
                        $candidateTs = strtotime($candidateDate);
                        if ($candidateTs < $startTs || $candidateTs > $endTs) {
                            continue;
                        }
                    }

                    foreach ($timeCandidates as $time) {
                        $weeklyLotId = (int)($weekly['lot_id'] ?? 0);
                        $dtKeyExact = $candidateDate . '|' . $time . '|' . $weeklyLotId;
                        $dtKeyGlobal = $candidateDate . '|' . $time . '|0';
                        if (($weeklyLotId === 0 && isset($existingDateTimeKeys[$dtKeyGlobal]))
                            || ($weeklyLotId > 0 && (isset($existingDateTimeKeys[$dtKeyExact]) || isset($existingDateTimeKeys[$dtKeyGlobal])))) {
                            continue;
                        }

                        $lotParts = [];
                        if (!empty($weekly['block_number']) && !empty($weekly['lot_number'])) {
                            $lotParts[] = 'Block ' . $weekly['block_number'] . ', Lot ' . $weekly['lot_number'];
                        } elseif (!empty($weekly['lot_number'])) {
                            $lotParts[] = 'Lot ' . $weekly['lot_number'];
                        }
                        if (!empty($weekly['location_name'])) {
                            $lotParts[] = $weekly['location_name'];
                        }

                        $slots[] = [
                            'available_date' => $candidateDate,
                            'time_slot' => $time,
                            'max_clients' => $maxClients,
                            'slot_id' => 'weekly-' . (int)$weekly['id'] . '-' . $candidateDate . '-' . str_replace(':', '', $time),
                            'schedule_type' => 'weekly',
                            'weekday' => $weekday,
                            'booked_count' => 0,
                            'lot_id' => (int)($weekly['lot_id'] ?? 0),
                            'location_name' => (string)($weekly['location_name'] ?? ''),
                            'lot_ref' => implode(' - ', $lotParts),
                            'slot_interval_minutes' => (int)($weekly['slot_interval_minutes'] ?? 60),
                        ];
                        $existingDateTimeKeys[$dtKeyExact] = true;
                    }
                }
            }
        }
    }
}

if (!empty($slots)) {
    $countSql = "SELECT DATE(preferred_at) AS slot_date, TIME(preferred_at) AS slot_time, IFNULL(lot_id, 0) AS lot_id, COUNT(*) AS booked_count
        FROM viewings
        WHERE agent_id = ?
          AND status IN ('pending','requested','scheduled','rescheduled')";
    $countTypes = 'i';
    $countParams = [$agent_id];

    if ($date) {
        $countSql .= " AND DATE(preferred_at) = ?";
        $countTypes .= 's';
        $countParams[] = $date;
    } else {
        $countSql .= " AND DATE(preferred_at) >= CURDATE()";
    }

    $countSql .= " GROUP BY DATE(preferred_at), TIME(preferred_at), IFNULL(lot_id, 0)";
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $countRes = $countStmt->get_result();

        $bookedMap = [];
        while ($countRow = $countRes->fetch_assoc()) {
            $lotKey = (int)($countRow['lot_id'] ?? 0);
            $key = $countRow['slot_date'] . '|' . $countRow['slot_time'] . '|' . $lotKey;
            $bookedMap[$key] = (int)($countRow['booked_count'] ?? 0);
        }
        $countStmt->close();

        foreach ($slots as &$slot) {
            $slotLotId = (int)($slot['lot_id'] ?? 0);
            $slotKeyExact = $slot['available_date'] . '|' . $slot['time_slot'] . '|' . $slotLotId;
            $slotKeyAnyLot = $slot['available_date'] . '|' . $slot['time_slot'] . '|0';

            if ($slotLotId > 0 && isset($bookedMap[$slotKeyExact])) {
                $slot['booked_count'] = $bookedMap[$slotKeyExact];
            } elseif (isset($bookedMap[$slotKeyAnyLot])) {
                $slot['booked_count'] = $bookedMap[$slotKeyAnyLot];
            }
        }
        unset($slot);
    }

    // Hide already elapsed times for the current day.
    $today = date('Y-m-d');
    $nowHm = date('H:i:s');
    $slots = array_values(array_filter($slots, static function ($slot) use ($today, $nowHm) {
        if (($slot['available_date'] ?? '') !== $today) {
            return true;
        }
        return (($slot['time_slot'] ?? '00:00:00') >= $nowHm);
    }));

    usort($slots, static function ($a, $b) {
        $ad = (string)($a['available_date'] ?? '');
        $bd = (string)($b['available_date'] ?? '');
        if ($ad !== $bd) {
            return strcmp($ad, $bd);
        }

        $at = (string)($a['time_slot'] ?? '');
        $bt = (string)($b['time_slot'] ?? '');
        if ($at !== $bt) {
            return strcmp($at, $bt);
        }

        return strcmp((string)($a['lot_ref'] ?? ''), (string)($b['lot_ref'] ?? ''));
    });
}

$conn->close();
echo json_encode($slots);
