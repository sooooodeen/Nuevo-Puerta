<?php
require_once 'dbconn.php';
session_start();

if (!isset($_SESSION['agent_id'])) {
  header('Location: Login/login.php');
  exit;
}

$agentId = (int)$_SESSION['agent_id'];
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Ensure the tables we touch are present (no-op if they already exist)
$conn->query("
  CREATE TABLE IF NOT EXISTS agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login_agent_id INT UNIQUE,
    photo VARCHAR(255) NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Handle updates
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $mobile= trim($_POST['mobile'] ?? '');
  $addr  = trim($_POST['address'] ?? '');
  $city  = trim($_POST['city'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $lat   = trim($_POST['latitude'] ?? '');
  $lng   = trim($_POST['longitude'] ?? '');

  // Basic validation
  if ($first === '' || $last === '' || $email === '') {
    $flash = 'First name, last name, and email are required.';
  } else {
    // Update main profile in nuevopuerta.agent_accounts
    $stmt = $conn->prepare("
      UPDATE agent_accounts
      SET first_name=?, last_name=?, email=?, mobile=?, address=?, city=?, description=?, latitude=?, longitude=?
      WHERE id=?");
    if ($stmt) {
      $stmt->bind_param('sssssssssi', $first, $last, $email, $mobile, $addr, $city, $desc, $lat, $lng, $agentId);
      $stmt->execute();
      $stmt->close();
      $flash = 'Profile saved.';
    } else {
      $flash = 'Error preparing update: '.$conn->error;
    }

    // Photo upload (optional)
    if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
      $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
      if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
        if (!is_dir(__DIR__.'/uploads')) {
          @mkdir(__DIR__.'/uploads', 0777, true);
        }
        $fname = 'agent_'.$agentId.'_'.time().'.'.$ext;
        $dest  = __DIR__.'/uploads/'.$fname;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
          // Update/insert into nuevopuerta.agents
          // Try update first
          $stmt = $conn->prepare("UPDATE agents SET photo=? WHERE login_agent_id=?");
          if ($stmt) {
            $stmt->bind_param('si', $fname, $agentId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) {
              // Insert if row not present
              $stmt = $conn->prepare("INSERT INTO agents (login_agent_id, photo) VALUES (?, ?)");
              if ($stmt) {
                $stmt->bind_param('is', $agentId, $fname);
                $stmt->execute();
                $stmt->close();
              }
            }
          }
          $flash = 'Profile & photo saved.';
        } else {
          $flash = 'Photo upload failed.';
        }
      } else {
        $flash = 'Unsupported photo type. Use jpg, png, gif, or webp.';
      }
    }
  }
}

// Load current profile (from the ONE schema: nuevopuerta)
$profile = [
  'first_name' => '',
  'last_name'  => '',
  'email'      => '',
  'mobile'     => '',
  'address'    => '',
  'city'       => '',
  'description'=> '',
  'latitude'   => '',
  'longitude'  => '',
  'photo'      => ''
];

$stmt = $conn->prepare("
    SELECT aa.first_name, aa.last_name, aa.email, aa.mobile, aa.address, aa.city, aa.description,
      aa.latitude, aa.longitude,
      ag.photo
    FROM agent_accounts aa
    LEFT JOIN agents ag ON ag.login_agent_id = aa.id
    WHERE aa.id = ?
");
if ($stmt) {
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($res) {
    $profile = array_merge($profile, $res);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#2d4e1e;--bg:#f6f7f8;--stroke:#e5e7eb;--card:#fff;--muted:#64748b;}
*{box-sizing:border-box}
body{font-family:'Inter',sans-serif;margin:0;background:var(--bg);color:#0f172a;}
nav{background:var(--green);height:70px;display:flex;align-items:center;justify-content:space-between;padding:0 20px;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.12)}
nav a{color:#fff;text-decoration:none;font-weight:600;margin:0 10px}
.wrapper{max-width:900px;margin:28px auto;padding:0 16px}
h2{margin:12px 0 18px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(2,6,23,.06)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:780px){.grid{grid-template-columns:1fr}}
label{font-size:14px;color:#0f172a;font-weight:600;margin-bottom:6px;display:block}
input[type=text],input[type=email],textarea{
  width:100%;padding:12px;border:1px solid var(--stroke);border-radius:10px;background:#fff;
}
textarea{min-height:120px;resize:vertical}
.row{margin-bottom:12px}
.btn{all:unset;background:var(--green);color:#fff;padding:12px 16px;border-radius:10px;font-weight:700;cursor:pointer}
.muted{color:var(--muted)}
.flash{margin:10px 0 16px;padding:10px 12px;border-radius:10px;background:#ecfdf5;color:#166534;border:1px solid #86efac}
.avatar{display:flex;align-items:center;gap:14px;margin:8px 0 16px}
.avatar img{width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid #f4d03f;background:#fff}
</style>
</head>
<body>
  <nav>
    <div><strong>El Nuevo Puerta</strong></div>
    <div>
      <a href="agent_dashboard.php">Dashboard</a>
      <a href="agentprofile.php">Profile</a>
      <a href="agentsales.php">Sales</a>
      <a href="agentnotification.php">Notifications</a>
      <a href="logout.php">Logout</a>
    </div>
  </nav>

  <div class="wrapper">
    <h2>My Profile</h2>
    <?php if($flash): ?><div class="flash"><?php echo h($flash); ?></div><?php endif; ?>

    <div class="card">
      <form method="post" enctype="multipart/form-data">
        <div class="avatar">
          <img src="<?php
            $photo = $profile['photo'] ? 'uploads/'.basename($profile['photo']) : 'assets/s.png';
            echo h($photo);
          ?>" alt="Profile">
          <div class="muted">Upload a new photo (jpg, png, gif, webp)
            <div class="row" style="margin-top:6px"><input type="file" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
          </div>
        </div>

        <div class="grid">
          <div class="row">
            <label>First name</label>
            <input type="text" name="first_name" value="<?php echo h($profile['first_name']); ?>" required>
          </div>
          <div class="row">
            <label>Last name</label>
            <input type="text" name="last_name" value="<?php echo h($profile['last_name']); ?>" required>
          </div>
          <div class="row">
            <label>Email</label>
            <input type="email" name="email" value="<?php echo h($profile['email']); ?>" required>
          </div>
          <div class="row">
            <label>Mobile</label>
            <input type="text" name="mobile" value="<?php echo h($profile['mobile']); ?>">
          </div>
          <div class="row">
            <label>Address</label>
            <input type="text" name="address" value="<?php echo h($profile['address']); ?>">
          </div>
          <div class="row">
            <label>City</label>
            <input type="text" name="city" value="<?php echo h($profile['city']); ?>">
          </div>
          <div class="row">
            <label>Latitude</label>
            <input type="text" name="latitude" value="<?php echo h($profile['latitude']); ?>" placeholder="Latitude">
          </div>
          <div class="row">
            <label>Longitude</label>
            <input type="text" name="longitude" value="<?php echo h($profile['longitude']); ?>" placeholder="Longitude">
          </div>
        </div>

        <div class="row">
          <label>About / Description</label>
          <textarea name="description" placeholder="Tell clients about your experience, coverage areas, languages, etc."><?php echo h($profile['description']); ?></textarea>
        </div>

        <button class="btn" type="submit">Save Changes</button>
      </form>
    </div>
  </div>
</body>
</html>
