<?php
require_once 'dbconn.php';
header('Content-Type: application/json');

// ---- Config: which schema + table hold your lots ----
$DB_SCHEMA = 'nuevopuerta';
$TABLE     = 'lots';

// Optional filters coming from the UI
$locationParam = isset($_GET['location']) ? trim($_GET['location']) : '';
$agentIdParam  = isset($_GET['agent_id']) ? trim($_GET['agent_id']) : ''; // only used if you add a join later

// 1) Discover real columns of nuevopuerta.lots (no hardcoding)
$cols = [];
$colRes = $conn->prepare("
  SELECT COLUMN_NAME 
  FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
");
$colRes->bind_param('ss', $DB_SCHEMA, $TABLE);
$colRes->execute();
$colRows = $colRes->get_result();
while ($r = $colRows->fetch_assoc()) {
  $cols[] = $r['COLUMN_NAME'];
}
$colRes->close();

if (empty($cols)) {
  http_response_code(500);
  echo json_encode(['error' => "Could not read columns for {$DB_SCHEMA}.{$TABLE}"]);
  exit;
}

// Helper: check if a column exists
$has = function(string $name) use ($cols) {
  return in_array($name, $cols, true);
};

// 2) Build SELECT safely
$selectCols = array_map(fn($c) => "`$c`", $cols); // select all existing columns
$sql   = "SELECT " . implode(",", $selectCols) . " FROM `{$DB_SCHEMA}`.`{$TABLE}`";
$where = [];
$params = [];
$types  = "";

// Add WHERE only if that column actually exists
if ($locationParam !== '' && $has('location')) {
  $where[] = "`location` = ?";
  $params[] = $locationParam;
  $types   .= "s";
}

// (Example) If later you add an agent-lot mapping, you can extend here with JOINs/filters.
// For now we keep it simple and robust.

if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY " . ($has('price') ? "`price`" : $selectCols[0]) . " ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['error' => 'Prepare failed: ' . $conn->error, 'sql' => $sql]);
  exit;
}
if ($types !== "") {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

// 3) Map your actual columns to a stable JSON schema
function pick(array $row, array $keys, $default = null) {
  foreach ($keys as $k) {
    if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
      return $row[$k];
    }
  }
  return $default;
}

$out = [];
while ($row = $res->fetch_assoc()) {
  // Build a consistent JSON regardless of DB naming
  $out[] = [
    'id'       => pick($row, ['id','lot_id','lotid','pk','uid']),
    'lot_no'   => pick($row, ['lot_no','lot_number','lotname','lot_name','lot','name','code','label']),
    'size'     => pick($row, ['size','area','sqm','square_meters','lot_size']),
    'price'    => pick($row, ['price','amount','cost','selling_price']),
    'status'   => pick($row, ['status','availability','state']),
    'location' => pick($row, ['location','address','site','barangay','phase_block','phase']),
    // keep the full original row too (handy for the UI)
    '_raw'     => $row
  ];
}

echo json_encode($out);
