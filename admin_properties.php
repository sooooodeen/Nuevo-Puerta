<?php
// admin_properties.php
session_start();
require_once 'dbconn.php';

/* ---------- Auth (admin only) ---------- */
if (!isset($_SESSION['admin_id'])) {
  header('Location: Login/index.html'); exit;
}
$admin_id = (int)$_SESSION['admin_id'];

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (function_exists('mysqli_set_charset')) { @mysqli_set_charset($conn,'utf8mb4'); }
date_default_timezone_set('Asia/Manila');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function check_csrf() {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
  }
}

/* ---------- Bootstrap tables (safe) ---------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS properties(
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    location_id INT,
    block_no VARCHAR(20),
    lot_no VARCHAR(20),
    size_sqm DECIMAL(10,2),
    price DECIMAL(12,2),
    description TEXT,
    images_json JSON,
    status ENUM('available','reserved','sold') DEFAULT 'available',
    featured TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prop_loc (location_id,status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Admin name/role (for header card) ---------- */
$admin_name = 'Admin'; $admin_role = 'Super Admin';
if ($r = $conn->query("SELECT first_name,last_name,role FROM admin_accounts WHERE id={$admin_id} LIMIT 1")) {
  if ($u = $r->fetch_assoc()) { $admin_name = trim(($u['first_name']??'').' '.($u['last_name']??'')); $admin_role = $u['role']??$admin_role; }
  $r->close();
}

/* ---------- API endpoints (AJAX) ---------- */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['api'])) {
  header('Content-Type: application/json');

  if ($_GET['api']==='locations') {
    $rows=[]; $q=$conn->query("SELECT id, location_name FROM lot_locations ORDER BY location_name");
    while($q && $row=$q->fetch_assoc()) $rows[]=$row;
    echo json_encode($rows); exit;
  }

  if ($_GET['api']==='list_properties') {
    $where = "1=1";
    $params=[]; $types='';
    if (!empty($_GET['location_id'])) { $where.=" AND p.location_id=?"; $params[]=(int)$_GET['location_id']; $types.='i'; }
    if (!empty($_GET['status'])) { $where.=" AND p.status=?"; $params[]=$_GET['status']; $types.='s'; }
    $sql = "SELECT p.*, l.location_name
            FROM properties p
            LEFT JOIN lot_locations l ON l.id=p.location_id
            WHERE $where
            ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result(); $rows=[];
    while($res && $row=$res->fetch_assoc()) $rows[]=$row;
    $stmt->close();
    echo json_encode($rows); exit;
  }

  if ($_GET['api']==='get_property' && !empty($_GET['id'])) {
    $id=(int)$_GET['id'];
    $stmt=$conn->prepare("SELECT * FROM properties WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$id);
    $stmt->execute(); $res=$stmt->get_result();
    echo json_encode($res?$res->fetch_assoc():null); exit;
  }

  http_response_code(404); echo json_encode(['success'=>false,'error'=>'Not found']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['api'])) {
  header('Content-Type: application/json'); check_csrf();

  // INSERT / UPDATE
  if ($_POST['api']==='save_property') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = trim($_POST['title'] ?? '');
    $location_id = (int)($_POST['location_id'] ?? 0);
    $block_no = trim($_POST['block_no'] ?? '');
    $lot_no = trim($_POST['lot_no'] ?? '');
    $size_sqm = (float)($_POST['size_sqm'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status']??'available', ['available','reserved','sold'], true) ? $_POST['status'] : 'available';
    $featured = !empty($_POST['featured']) ? 1 : 0;

    // Handle images: combine existing_images (array of URLs) + new uploads
    $images = [];
    if (!empty($_POST['existing_images'])) {
      $decoded = json_decode($_POST['existing_images'], true);
      if (is_array($decoded)) $images = array_values(array_filter($decoded));
    }

    if (!empty($_FILES['images']['name'][0])) {
      $uploadDir = __DIR__ . '/uploads/properties';
      if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
      for ($i=0; $i<count($_FILES['images']['name']); $i++) {
        if ($_FILES['images']['error'][$i]===UPLOAD_ERR_OK) {
          $ext = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
          $safe = preg_replace('/[^a-zA-Z0-9_\.-]/','_', basename($_FILES['images']['name'][$i]));
          $fname = time().'_'.mt_rand(1000,9999).'_'.$safe;
          $dest = $uploadDir . '/' . $fname;
          if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dest)) {
            $images[] = 'uploads/properties/'.$fname; // public path
          }
        }
      }
    }
    $images_json = json_encode($images, JSON_UNESCAPED_SLASHES);

    if ($id>0) {
      $stmt=$conn->prepare("
        UPDATE properties
           SET title=?, location_id=?, block_no=?, lot_no=?, size_sqm=?, price=?,
               description=?, images_json=?, status=?, featured=?
         WHERE id=?");
      $stmt->bind_param('sissddsssii',
        $title,$location_id,$block_no,$lot_no,$size_sqm,$price,
        $description,$images_json,$status,$featured,$id
      );
      $ok=$stmt->execute(); $stmt->close();
      echo json_encode(['success'=>$ok, 'message'=>$ok?'Updated':'Failed to update']); exit;
    } else {
      $stmt=$conn->prepare("
        INSERT INTO properties
          (title, location_id, block_no, lot_no, size_sqm, price, description, images_json, status, featured)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
      $stmt->bind_param('sissddsssii',
        $title,$location_id,$block_no,$lot_no,$size_sqm,$price,$description,$images_json,$status,$featured
      );
      $ok=$stmt->execute(); $newId=$stmt->insert_id; $stmt->close();
      echo json_encode(['success'=>$ok, 'id'=>$newId, 'message'=>$ok?'Added':'Failed to add']); exit;
    }
  }

  // DELETE
  if ($_POST['api']==='delete_property' && !empty($_POST['id'])) {
    $id=(int)$_POST['id'];
    $stmt=$conn->prepare("DELETE FROM properties WHERE id=?");
    $stmt->bind_param('i',$id);
    $ok=$stmt->execute(); $stmt->close();
    echo json_encode(['success'=>$ok]); exit;
  }

  http_response_code(404); echo json_encode(['success'=>false,'error'=>'Not found']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin · Properties (CMS)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --green:#1f3e1f; --green-2:#234f2a; --bg:#f6f7f8; --ink:#0f172a; --muted:#64748b;
  --card:#ffffff; --stroke:#e5e7eb; --shadow:0 10px 30px rgba(2,6,23,.08);
  --chip-ok:#16a34a; --chip-res:#d97706; --chip-sold:#b91c1c;
}
*{box-sizing:border-box} html,body{margin:0;padding:0}
body{font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg);display:flex;min-height:100vh}

/* Sidebar (static) */
.sidebar{
  width:280px;background:#134024;color:#fff;display:flex;flex-direction:column;align-items:center;gap:18px;padding:18px 14px;position:sticky;top:0;height:100vh
}
.logo{display:flex;align-items:center;gap:10px;margin:6px 0 10px}
.logo img{width:60px;height:60px;border-radius:50%;object-fit:cover;background:#0a2a15}
.brand{font-weight:800;letter-spacing:.5px}
.profile{
  width:100%;background:#fff;color:#134024;border-radius:12px;padding:12px;display:flex;align-items:center;gap:10px
}
.profile .avatar{width:36px;height:36px;border-radius:50%;background:#d1fae5;display:grid;place-items:center;font-weight:800}
.profile .meta{line-height:1.1}
.nav{width:100%}
.nav a{display:flex;align-items:center;gap:10px;padding:12px 10px;border-radius:10px;text-decoration:none;color:#fff;font-weight:600;opacity:.95}
.nav a:hover,.nav a.active{background:#1b5a31;opacity:1}
.nav .icon{width:20px;text-align:center}

/* Main */
.main{flex:1;display:flex;flex-direction:column;padding:24px}
.header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px}
.header h2{margin:0;font-size:28px;font-weight:800}
.kit{display:flex;gap:10px;flex-wrap:wrap}

.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:16px;box-shadow:var(--shadow)}
.filterbar{display:flex;gap:10px;align-items:center}
select,input[type="text"],input[type="number"],textarea{border:1px solid var(--stroke);border-radius:10px;padding:10px 12px;font:inherit}
button.btn{all:unset;cursor:pointer;background:#1b5a31;color:#fff;padding:10px 14px;border-radius:10px;font-weight:700;box-shadow:0 6px 14px rgba(27,90,49,.25)}
button.btn:hover{transform:translateY(-1px)}
button.btn.gray{background:#475569}

/* Table */
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid var(--stroke);padding:10px 12px;text-align:left;font-size:14px}
.table th{background:#f3f4f6}
.status{padding:4px 10px;border-radius:999px;font-weight:700;font-size:12px;display:inline-block}
.status.available{background:#ecfdf5;color:#065f46;border:1px solid #86efac}
.status.reserved{background:#fff7ed;color:#92400e;border:1px solid #fed7aa}
.status.sold{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

/* Modal (drawer-like) */
.modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:flex-start;justify-content:center;padding:30px 14px;z-index:99}
.modal .panel{width:min(980px,100%);background:#fff;border-radius:14px;overflow:hidden}
.modal header{display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid var(--stroke)}
.modal .content{padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal .content .full{grid-column:1/-1}
.imgs{display:flex;gap:10px;flex-wrap:wrap}
.imgs img{width:90px;height:70px;object-fit:cover;border-radius:8px;border:1px solid var(--stroke)}

.notice{display:none;margin-bottom:10px;padding:10px 12px;border-radius:8px;font-weight:600}
.notice.ok{display:block;background:#d1fae5;color:#065f46;border:1px solid #86efac}
.notice.err{display:block;background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
</style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
  <div class="logo">
    <img src="assets/a.png" alt="Logo">
    <div class="brand">NUEVO PUERTA</div>
  </div>

  <div class="profile">
    <div class="avatar"><?php echo strtoupper(substr($admin_name,0,1)); ?></div>
    <div class="meta">
      <div style="font-weight:700"><?php echo h($admin_name); ?></div>
      <div style="font-size:12px;opacity:.8"><?php echo h($admin_role); ?></div>
    </div>
  </div>

  <nav class="nav">
    <a href="admindashboard.php"><span class="icon">🏠</span>Dashboard</a>
    <a href="adminaccounts.php"><span class="icon">👥</span>Manage Accounts</a>
    <a class="active" href="admin_properties.php"><span class="icon">🏗️</span>Properties (CMS)</a>
    <a href="logout.php"><span class="icon">⎋</span>Logout</a>
  </nav>
</aside>

<!-- Main -->
<main class="main">
  <div class="header">
    <h2>Properties CMS</h2>
    <div class="kit">
      <button class="btn" id="btnAdd">➕ Add Property</button>
    </div>
  </div>

  <section class="card" style="margin-bottom:12px">
    <div class="filterbar">
      <select id="f_location">
        <option value="">All Locations</option>
      </select>
      <select id="f_status">
        <option value="">All Status</option>
        <option value="available">Available</option>
        <option value="reserved">Reserved</option>
        <option value="sold">Sold</option>
      </select>
      <button class="btn gray" id="btnRefresh">Refresh</button>
    </div>
  </section>

  <section class="card">
    <div id="msg" class="notice"></div>
    <div style="overflow:auto">
      <table class="table" id="tbl">
        <thead>
          <tr>
            <th width="24%">Title</th>
            <th>Location</th>
            <th>Block</th>
            <th>Lot</th>
            <th>Size (sqm)</th>
            <th>Price</th>
            <th>Status</th>
            <th width="150">Actions</th>
          </tr>
        </thead>
        <tbody id="rows"></tbody>
      </table>
    </div>
  </section>
</main>

<!-- Modal -->
<div class="modal" id="modal">
  <div class="panel">
    <header>
      <strong id="m_title">Add Property</strong>
      <button class="btn gray" id="m_close">Close</button>
    </header>
    <div class="content">
      <div class="notice" id="m_notice"></div>

      <input type="hidden" id="m_id">
      <input type="hidden" id="csrf" value="<?php echo h($_SESSION['csrf_token']); ?>">

      <div>
        <label>Title</label>
        <input id="m_title_inp" type="text" placeholder="e.g., Lot 3 - Block 4, Phase 2">
      </div>

      <div>
        <label>Location</label>
        <select id="m_location"></select>
      </div>

      <div>
        <label>Block No.</label>
        <input id="m_block" type="text">
      </div>
      <div>
        <label>Lot No.</label>
        <input id="m_lot" type="text">
      </div>

      <div>
        <label>Size (sqm)</label>
        <input id="m_size" type="number" step="0.01" min="0">
      </div>
      <div>
        <label>Price</label>
        <input id="m_price" type="number" step="0.01" min="0">
      </div>

      <div>
        <label>Status</label>
        <select id="m_status">
          <option value="available">Available</option>
          <option value="reserved">Reserved</option>
          <option value="sold">Sold</option>
        </select>
      </div>

      <div>
        <label><input type="checkbox" id="m_featured"> Featured</label>
      </div>

      <div class="full">
        <label>Description</label>
        <textarea id="m_desc" rows="3" placeholder="Short description..."></textarea>
      </div>

      <div class="full">
        <label>Images (you can select multiple)</label>
        <input id="m_images" type="file" accept="image/*" multiple>
        <div class="imgs" id="m_imgs_preview"></div>
      </div>

      <div class="full" style="display:flex;gap:10px;justify-content:flex-end;margin-top:6px">
        <button class="btn gray" id="m_save_close">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
const Q = sel => document.querySelector(sel);
const QA = sel => document.querySelectorAll(sel);

function showMsg(el, text, ok=true){ el.className = 'notice ' + (ok?'ok':'err'); el.textContent = text; el.style.display='block'; setTimeout(()=>{el.style.display='none'}, 2600); }

async function fetchJSON(url){ const r = await fetch(url); if(!r.ok) throw new Error('Network'); return r.json(); }

async function loadLocations() {
  const data = await fetchJSON('admin_properties.php?api=locations');
  const fLoc = Q('#f_location'), mLoc = Q('#m_location');
  fLoc.innerHTML = '<option value="">All Locations</option>';
  mLoc.innerHTML = '<option value="">Select location</option>';
  data.forEach(x=>{
    const o1=document.createElement('option'); o1.value=x.id; o1.textContent=x.location_name; fLoc.appendChild(o1);
    const o2=document.createElement('option'); o2.value=x.id; o2.textContent=x.location_name; mLoc.appendChild(o2);
  });
}

async function loadTable() {
  const loc = encodeURIComponent(Q('#f_location').value||'');
  const st  = encodeURIComponent(Q('#f_status').value||'');
  const data = await fetchJSON(`admin_properties.php?api=list_properties&location_id=${loc}&status=${st}`);
  const tb = Q('#rows'); tb.innerHTML='';
  if(!data.length){
    tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#64748b">No properties found.</td></tr>';
    return;
  }
  data.forEach(r=>{
    const tr=document.createElement('tr');
    const statusClass = r.status==='available'?'available':(r.status==='reserved'?'reserved':'sold');
    tr.innerHTML = `
      <td>${escapeHtml(r.title||'')}</td>
      <td>${escapeHtml(r.location_name||'-')}</td>
      <td>${escapeHtml(r.block_no||'-')}</td>
      <td>${escapeHtml(r.lot_no||'-')}</td>
      <td>${Number(r.size_sqm||0).toLocaleString()}</td>
      <td>${Number(r.price||0).toLocaleString()}</td>
      <td><span class="status ${statusClass}">${r.status}</span></td>
      <td>
        <button class="btn" onclick="openEdit(${r.id})">Edit</button>
        <button class="btn gray" onclick="delProp(${r.id})">Delete</button>
      </td>`;
    tb.appendChild(tr);
  });
}

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

function openModal(){ Q('#modal').style.display='flex'; }
function closeModal(){ Q('#modal').style.display='none'; resetForm(); }
function resetForm(){
  Q('#m_id').value=''; Q('#m_title').textContent='Add Property';
  Q('#m_title_inp').value=''; Q('#m_location').value='';
  Q('#m_block').value=''; Q('#m_lot').value='';
  Q('#m_size').value=''; Q('#m_price').value='';
  Q('#m_status').value='available'; Q('#m_featured').checked=false;
  Q('#m_desc').value=''; Q('#m_images').value='';
  Q('#m_imgs_preview').innerHTML='';
  Q('#m_notice').style.display='none';
  // store current images (existing) in dataset
  Q('#m_imgs_preview').dataset.images = '[]';
}

Q('#btnAdd').onclick = ()=>{ resetForm(); openModal(); };
Q('#m_close').onclick = ()=> closeModal();
Q('#btnRefresh').onclick = ()=> loadTable();

Q('#m_images').addEventListener('change', ()=>{
  const box=Q('#m_imgs_preview'); box.innerHTML='';
  const existing = JSON.parse(box.dataset.images||'[]');
  existing.forEach(u=> addPreview(u)); // keep existing shown
  for(const f of Q('#m_images').files){
    const url = URL.createObjectURL(f);
    addPreview(url); // preview only
  }
});

function addPreview(url){
  const img=document.createElement('img'); img.src=url; Q('#m_imgs_preview').appendChild(img);
}

async function openEdit(id){
  const r = await fetchJSON(`admin_properties.php?api=get_property&id=${id}`);
  resetForm();
  Q('#m_title').textContent='Edit Property';
  Q('#m_id').value = r.id;
  Q('#m_title_inp').value = r.title||'';
  Q('#m_location').value = r.location_id||'';
  Q('#m_block').value = r.block_no||'';
  Q('#m_lot').value = r.lot_no||'';
  Q('#m_size').value = r.size_sqm||'';
  Q('#m_price').value = r.price||'';
  Q('#m_status').value = r.status||'available';
  Q('#m_featured').checked = Number(r.featured||0)===1;
  Q('#m_desc').value = r.description||'';
  const imgs = r.images_json ? JSON.parse(r.images_json) : [];
  Q('#m_imgs_preview').dataset.images = JSON.stringify(imgs);
  Q('#m_imgs_preview').innerHTML='';
  imgs.forEach(u=> addPreview(u));
  openModal();
}

async function delProp(id){
  if(!confirm('Delete this property?')) return;
  const fd = new FormData();
  fd.append('api','delete_property');
  fd.append('id',id);
  fd.append('csrf_token', Q('#csrf').value);
  const r = await fetch('admin_properties.php',{method:'POST',body:fd});
  const j = await r.json();
  showMsg(Q('#msg'), j.success?'Deleted successfully':'Failed to delete', j.success);
  if (j.success) loadTable();
}

Q('#m_save_close').onclick = async ()=>{
  // validation
  const title=Q('#m_title_inp').value.trim();
  const loc=Q('#m_location').value;
  if(!title||!loc){ showMsg(Q('#m_notice'),'Title and Location are required',false); return; }

  const fd = new FormData();
  fd.append('api','save_property');
  fd.append('csrf_token', Q('#csrf').value);
  if (Q('#m_id').value) fd.append('id', Q('#m_id').value);
  fd.append('title', title);
  fd.append('location_id', loc);
  fd.append('block_no', Q('#m_block').value);
  fd.append('lot_no', Q('#m_lot').value);
  fd.append('size_sqm', Q('#m_size').value||0);
  fd.append('price', Q('#m_price').value||0);
  fd.append('description', Q('#m_desc').value);
  fd.append('status', Q('#m_status').value);
  fd.append('featured', Q('#m_featured').checked ? 1 : 0);

  // keep previously saved images (URLs)
  fd.append('existing_images', Q('#m_imgs_preview').dataset.images || '[]');
  // add new images
  const files = Q('#m_images').files;
  for(let i=0;i<files.length;i++) fd.append('images[]', files[i]);

  const r = await fetch('admin_properties.php',{method:'POST',body:fd});
  const j = await r.json();
  showMsg(Q('#m_notice'), j.message || (j.success?'Saved':'Failed to save'), j.success);
  if (j.success){
    setTimeout(()=>{ closeModal(); loadTable(); }, 900);
  }
};

// init
(async function(){
  await loadLocations();
  await loadTable();
})();
</script>
</body>
</html>
