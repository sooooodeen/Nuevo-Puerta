<?php
session_start();
// Security Check: Uncomment this line in your real app
// if (!isset($_SESSION['admin_id'])) { header("Location: Login/login.php"); exit; }

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "nuevopuerta";

$conn = new mysqli($servername, $username, $password, $dbname);

// --- AJAX HANDLER: SAVE COORDINATES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_map') {
    $lot_id = intval($_POST['lot_id']);
    $coords = $conn->real_escape_string($_POST['coords']);
    
    $sql = "UPDATE lots SET coordinates = '$coords' WHERE id = $lot_id";
    if ($conn->query($sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// --- FETCH DATA ---
$locations = $conn->query("SELECT * FROM lot_locations");

$selected_loc = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
$lots = [];
$blueprint_img = '';

if ($selected_loc) {
    // Get Blueprint Image
    $bp_res = $conn->query("SELECT filename FROM blueprints WHERE location_id = $selected_loc LIMIT 1");
    if ($bp_res && $row = $bp_res->fetch_assoc()) {
        $blueprint_img = 'blueprints/' . $row['filename'];
    }

    // Get Lots
    $lots_res = $conn->query("SELECT * FROM lots WHERE location_id = $selected_loc ORDER BY block_number ASC, lot_number ASC");
    if ($lots_res) {
        while($row = $lots_res->fetch_assoc()) {
            $lots[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Blueprint Mapper</title>
    <style>
        body { font-family: sans-serif; display: flex; height: 100vh; margin: 0; overflow: hidden; }
        
        /* Sidebar */
        .sidebar { width: 300px; background: #f4f4f4; border-right: 1px solid #ddd; display: flex; flex-direction: column; }
        .sidebar-header { padding: 15px; background: #2d4e1e; color: white; }
        .lot-list { flex: 1; overflow-y: auto; padding: 10px; }
        
        .lot-item { 
            padding: 10px; border: 1px solid #ccc; background: white; margin-bottom: 5px; cursor: pointer; border-radius: 4px; 
            display: flex; justify-content: space-between; align-items: center;
        }
        .lot-item:hover { background: #e9e9e9; }
        .lot-item.active { border: 2px solid #2d4e1e; background: #d4edda; }
        .lot-item.mapped { border-left: 5px solid #28a745; }
        .status-badge { font-size: 0.8em; padding: 2px 6px; border-radius: 4px; background: #eee; }

        /* Canvas Area */
        .canvas-container { flex: 1; background: #555; position: relative; overflow: auto; display: flex; justify-content: center; align-items: flex-start; padding: 20px; }
        
        #map-wrapper { position: relative; display: inline-block; box-shadow: 0 0 20px rgba(0,0,0,0.5); background: white; }
        #blueprint-img { display: block; max-width: none; pointer-events: none; } /* Image doesn't capture clicks */
        
        /* Drawing Layer */
        #draw-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; cursor: crosshair; z-index: 10; }
        
        /* Boxes */
        .map-box { position: absolute; border: 2px solid rgba(255, 0, 0, 0.7); background: rgba(255, 0, 0, 0.2); }
        .map-box.saved { border-color: rgba(40, 167, 69, 0.8); background: rgba(40, 167, 69, 0.3); }
        .map-box:hover { background: rgba(255, 255, 0, 0.4); z-index: 20; }
        
        .controls { padding: 10px; background: #fff; border-bottom: 1px solid #ddd; }
        select { width: 100%; padding: 8px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h3>Map Lots</h3>
        <a href="admindashboard.php" style="color:#cfffdc; font-size:0.9em;">&larr; Back to Dashboard</a>
    </div>
    
    <div class="controls">
        <label>Select Location:</label>
        <form method="GET">
            <select name="location_id" onchange="this.form.submit()">
                <option value="">-- Choose --</option>
                <?php while($loc = $locations->fetch_assoc()): ?>
                    <option value="<?= $loc['id'] ?>" <?= $selected_loc == $loc['id'] ? 'selected' : '' ?>>
                        <?= $loc['location_name'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </form>
    </div>

    <div class="lot-list">
        <?php if(empty($lots) && $selected_loc): ?>
            <p style="text-align:center; color:#666;">No lots found.</p>
        <?php endif; ?>

        <?php foreach($lots as $lot): 
            $isMapped = !empty($lot['coordinates']);
        ?>
            <div class="lot-item <?= $isMapped ? 'mapped' : '' ?>" 
                 id="lot-<?= $lot['id'] ?>"
                 onclick="selectLot(<?= $lot['id'] ?>, '<?= $lot['coordinates'] ?>')">
                <div>
                    <strong>Blk <?= $lot['block_number'] ?> - Lot <?= $lot['lot_number'] ?></strong>
                    <br>
                    <span class="status-badge"><?= $lot['status'] ?></span>
                </div>
                <div id="icon-<?= $lot['id'] ?>"><?= $isMapped ? '✅' : '⬜' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="canvas-container">
    <?php if ($blueprint_img): ?>
        <div id="map-wrapper">
            <img id="blueprint-img" src="<?= $blueprint_img ?>">
            <div id="draw-layer"></div>
        </div>
    <?php else: ?>
        <div style="color:white; margin-top:100px;">Please select a location with a blueprint to start mapping.</div>
    <?php endif; ?>
</div>

<script>
let activeLotId = null;
let isDrawing = false;
let startX, startY;
const drawLayer = document.getElementById('draw-layer');

// 1. SELECT A LOT
function selectLot(id, existingCoords) {
    // Highlight sidebar item
    document.querySelectorAll('.lot-item').forEach(el => el.classList.remove('active'));
    document.getElementById('lot-' + id).classList.add('active');
    
    activeLotId = id;
    
    // Clear temporary boxes
    document.querySelectorAll('.temp-box').forEach(el => el.remove());

    // If already mapped, flash the existing box (Visual feedback)
    if(existingCoords && existingCoords !== '') {
        try {
            const c = JSON.parse(existingCoords);
            // You could scroll to it or highlight it here
            console.log("Selected mapped lot:", c);
        } catch(e) {}
    }
}

if (drawLayer) {
    // 2. MOUSE DOWN (Start Drawing)
    drawLayer.addEventListener('mousedown', (e) => {
        if (!activeLotId) {
            alert("Please click a lot name on the left sidebar first!");
            return;
        }
        isDrawing = true;
        const rect = drawLayer.getBoundingClientRect();
        
        // Calculate Percentage Coordinates (Responsive)
        startX = (e.clientX - rect.left) / rect.width * 100;
        startY = (e.clientY - rect.top) / rect.height * 100;
        
        // Create a temporary box visually
        const box = document.createElement('div');
        box.className = 'map-box temp-box';
        box.id = 'current-draw';
        box.style.left = startX + '%';
        box.style.top = startY + '%';
        box.style.width = '0%';
        box.style.height = '0%';
        drawLayer.appendChild(box);
    });

    // 3. MOUSE MOVE (Resize Box)
    drawLayer.addEventListener('mousemove', (e) => {
        if (!isDrawing) return;
        const rect = drawLayer.getBoundingClientRect();
        const currentX = (e.clientX - rect.left) / rect.width * 100;
        const currentY = (e.clientY - rect.top) / rect.height * 100;
        
        const box = document.getElementById('current-draw');
        
        const width = Math.abs(currentX - startX);
        const height = Math.abs(currentY - startY);
        const left = Math.min(startX, currentX);
        const top = Math.min(startY, currentY);
        
        box.style.width = width + '%';
        box.style.height = height + '%';
        box.style.left = left + '%';
        box.style.top = top + '%';
    });

    // 4. MOUSE UP (Save Box)
    drawLayer.addEventListener('mouseup', (e) => {
        if (!isDrawing) return;
        isDrawing = false;
        
        const box = document.getElementById('current-draw');
        if (!box) return;
        
        // Finalize coordinates
        const w = parseFloat(box.style.width);
        const h = parseFloat(box.style.height);
        const x = parseFloat(box.style.left);
        const y = parseFloat(box.style.top);
        
        // Prevent accidental tiny clicks
        if (w < 0.5 || h < 0.5) {
            box.remove();
            return;
        }
        
        const coords = { x: x, y: y, w: w, h: h };
        
        // Save to Database via AJAX
        saveCoordinates(activeLotId, coords, box);
    });
}

function saveCoordinates(lotId, coords, boxElement) {
    const formData = new FormData();
    formData.append('action', 'save_map');
    formData.append('lot_id', lotId);
    formData.append('coords', JSON.stringify(coords));

    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            // Update UI
            boxElement.classList.remove('temp-box');
            boxElement.classList.add('saved');
            boxElement.removeAttribute('id'); // Remove current-draw id
            
            // Mark list item as done
            const listItem = document.getElementById('lot-' + lotId);
            listItem.classList.add('mapped');
            document.getElementById('icon-' + lotId).innerText = '✅';
            
            // Update the onclick data so we know it's mapped next time
            listItem.setAttribute('onclick', `selectLot(${lotId}, '${JSON.stringify(coords)}')`);
        } else {
            alert("Error saving: " + data.error);
            boxElement.remove();
        }
    })
    .catch(err => {
        console.error(err);
        alert("Connection error");
        boxElement.remove();
    });
}

// 5. LOAD EXISTING BOXES ON START
// (Optional: Draw all existing boxes lightly so you can see what's done)
<?php 
if (!empty($lots)) {
    foreach($lots as $lot) {
        if (!empty($lot['coordinates'])) {
            echo "drawExistingBox(" . $lot['coordinates'] . ");\n";
        }
    }
}
?>

function drawExistingBox(c) {
    const box = document.createElement('div');
    box.className = 'map-box saved';
    box.style.left = c.x + '%';
    box.style.top = c.y + '%';
    box.style.width = c.w + '%';
    box.style.height = c.h + '%';
    if(drawLayer) drawLayer.appendChild(box);
}
</script>

</body>
</html>