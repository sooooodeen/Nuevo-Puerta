<?php
$mysqli = new mysqli('localhost', 'root', '', 'nuevopuerta');
if ($mysqli->connect_error) {
    echo $mysqli->connect_error;
    exit(1);
}
$res = $mysqli->query('SHOW COLUMNS FROM agent_accounts');
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . ' | ' . ($row['Default'] ?? 'NULL') . PHP_EOL;
}
echo '---INDEXES---' . PHP_EOL;
$idx = $mysqli->query('SHOW INDEX FROM agent_accounts');
while ($r = $idx->fetch_assoc()) {
    echo $r['Key_name'] . ' | ' . $r['Column_name'] . ' | ' . $r['Non_unique'] . PHP_EOL;
}
?>
