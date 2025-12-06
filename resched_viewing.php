<?php
require_once 'dbconn.php';
session_start();
function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    $newDate = trim($_POST['new_date'] ?? '');
    $newTime = trim($_POST['new_time'] ?? '');
    if ($newDate && $newTime) {
        $dt = $newDate . ' ' . $newTime . ':00';
        $stmt = $conn->prepare("UPDATE viewings SET preferred_at=?, status='rescheduled' WHERE id=?");
        $stmt->bind_param('si', $dt, $id);
        if ($stmt->execute()) {
            $msg = "Viewing successfully rescheduled.";
        } else {
            $msg = "Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $msg = "Please choose both date and time.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reschedule Viewing</title>
<style>
body{font-family:Arial,sans-serif;background:#f8f8f8;padding:40px;}
.container{max-width:500px;margin:auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.1);}
label{display:block;margin:12px 0 6px;}
input,button{width:100%;padding:10px;margin-bottom:12px;border-radius:6px;border:1px solid #ccc;}
button{background:#2d4e1e;color:#fff;font-weight:bold;border:none;cursor:pointer;}
.msg{margin-bottom:12px;color:#155724;background:#d4edda;padding:10px;border-radius:6px;}
</style>
</head>
<body>
<div class="container">
<h2>Reschedule Viewing</h2>
<?php if($msg): ?><div class="msg"><?php echo h($msg); ?></div><?php endif; ?>
<form method="post">
    <label for="new_date">New Date</label>
    <input type="date" id="new_date" name="new_date" required>
    <label for="new_time">New Time</label>
    <input type="time" id="new_time" name="new_time" required>
    <button type="submit">Save</button>
</form>
<p><a href="my_viewings.php">← Back to My Viewings</a></p>
</div>
</body>
</html>
