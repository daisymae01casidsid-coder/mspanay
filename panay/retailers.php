<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);
 $msg = '';
 $msg_type = 'success';

/* ==========================================================
   🏬 Auto-create retailers table (best-effort, like import_history)
   ========================================================== */
 $conn->query("CREATE TABLE IF NOT EXISTS retailers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    contact_person VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    area VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_retailer_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ===== Does picklists have customer_name? (for backfill + live count) ===== */
 $hasCustomerName = false;
try {
    $cf = $conn->query("SHOW COLUMNS FROM picklists LIKE 'customer_name'");
    $hasCustomerName = ($cf && $cf->num_rows > 0);
} catch (Throwable $e) { $hasCustomerName = false; }

/* ==========================================================
   🔧 AUTO-BACKFILL from picklists.customer_name
   Every distinct shop you've ever delivered to (but not yet in
   retailers) gets a row, so the page is never empty.
   ========================================================== */
if ($hasCustomerName) {
    try {
        $res = $conn->query("
            SELECT DISTINCT p.customer_name AS name
            FROM picklists p
            WHERE p.customer_name IS NOT NULL
              AND p.customer_name <> ''
              AND p.customer_name NOT IN (SELECT name FROM retailers)
        ");
        $toInsert = [];
        if ($res) {
            while ($m = $res->fetch_assoc()) { $toInsert[] = $m['name']; }
            $res->free();
        }
        if ($toInsert) {
            $ins = $conn->prepare("INSERT INTO retailers (name, status) VALUES (?, 'active')");
            foreach ($toInsert as $nm) {
                $ins->bind_param("s", $nm);
                $ins->execute();
            }
            $ins->close();
        }
    } catch (Throwable $e) { /* best effort */ }
}

/* ==========================================================
   ➕ ADD
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_retailer'])) {
    $name          = trim($_POST['name'] ?? '');
    $code          = trim($_POST['code'] ?? '');
    $contact       = trim($_POST['contact_person'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $area          = trim($_POST['area'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name !== '') {
        $check = $conn->prepare("SELECT id FROM retailers WHERE LOWER(name) = LOWER(?)");
        $check->bind_param("s", $name);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $msg = "\"{$name}\" already exists."; $msg_type = 'error';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO retailers (name, code, contact_person, phone, area, address, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sssssss", $name, $code, $contact, $phone, $area, $address, $status);
            $stmt->execute();
            $msg = "Retailer \"{$name}\" added.";
        }
    } else {
        $msg = "Retailer name is required."; $msg_type = 'error';
    }
}

/* ==========================================================
   ✏️ EDIT
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_retailer'])) {
    $id            = (int)($_POST['id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $code          = trim($_POST['code'] ?? '');
    $contact       = trim($_POST['contact_person'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $area          = trim($_POST['area'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($id > 0 && $name !== '') {
        $check = $conn->prepare("SELECT id FROM retailers WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $check->bind_param("si", $name, $id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $msg = "\"{$name}\" already exists."; $msg_type = 'error';
        } else {
            $stmt = $conn->prepare(
                "UPDATE retailers
                    SET name = ?, code = ?, contact_person = ?, phone = ?, area = ?, address = ?, status = ?
                  WHERE id = ?"
            );
            $stmt->bind_param("sssssssi", $name, $code, $contact, $phone, $area, $address, $status, $id);
            $stmt->execute();
            $msg = "Retailer updated.";
        }
    } else {
        $msg = "Retailer name is required."; $msg_type = 'error';
    }
}

/* ==========================================================
   🗑 DELETE
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_retailer'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM retailers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $msg = "Retailer deleted.";
    }
}

/* ==========================================================
   📋 DATA (with live picklist count when possible)
   ========================================================== */
 $retailers = [];
if ($hasCustomerName) {
    $sql = "SELECT r.*,
                   (SELECT COUNT(*) FROM picklists p WHERE p.customer_name = r.name) AS picklist_count
            FROM retailers r
            ORDER BY r.name";
} else {
    $sql = "SELECT r.*, 0 AS picklist_count FROM retailers r ORDER BY r.name";
}
 $res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) { $retailers[] = $row; }
}

 $total       = count($retailers);
 $active      = 0; $inactive = 0; $sumPicks = 0;
foreach ($retailers as $r) {
    if (($r['status'] ?? 'active') === 'inactive') $inactive++; else $active++;
    $sumPicks += (int)($r['picklist_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Retailers</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px — matches dashboard, import_history & add_picklist) ===== */
    .sidebar { width:var(--sidebar-w); background:#1e293b; color:#cbd5e1; display:flex; flex-direction:column;
               position:fixed; top:0; left:0; bottom:0; overflow-y:auto; }
    .sidebar-brand { font-size:15px; font-weight:700; color:#fff; padding:12px 12px; border-bottom:1px solid #334155; }
    .sidebar-user  { padding:8px 12px; background:#0f172a; font-size:11px; line-height:1.5; }
    .sidebar-user small { color:#94a3b8; }
    .sidebar-nav { flex:1; padding-top:4px; }
    .sidebar-nav a { display:block; padding:8px 12px; color:#cbd5e1; text-decoration:none; font-size:12px;
                     border-left:3px solid transparent; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background:#334155; color:#fff; }
    .sidebar-nav a.active { border-left-color:#22c55e; }
    .sidebar-logout { display:block; padding:10px 12px; color:#f87171; text-decoration:none; font-size:12px; border-top:1px solid #334155; }
    .sidebar-logout:hover { background:#7f1d1d; color:#fff; }

    /* ===== Main (identical to dashboard) ===== */
    .main-content { margin-left:var(--sidebar-w); flex:1; padding:12px 14px; }
    h1 { font-size:16px; margin-bottom:12px; }

    /* ===== Alert ===== */
    .alert { padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:12px;
             animation:slideDown .25s ease; transition:opacity .5s; }
    @keyframes slideDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
    .alert.success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .alert.error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

    /* ===== Stat cards (dashboard .cards / .card) ===== */
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:150px; background:#fff; border-radius:10px; padding:18px 16px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:34px; display:block; line-height:1.2; }
    .card span { font-size:13px; color:#64748b; font-weight:600; }
    .card.total     { border-top-color:#64748b; } .card.total b     { color:#475569; }
    .card.active    { border-top-color:#22c55e; } .card.active b    { color:#15803d; }
    .card.inactive  { border-top-color:#f59e0b; } .card.inactive b  { color:#b45309; }
    .card.picks     { border-top-color:#3b82f6; } .card.picks b     { color:#1d4ed8; }

    /* ===== Filter bar (dashboard .filters chip style) ===== */
    .filters { margin-bottom:8px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters .search { padding:4px 10px; background:#f8fafc; border:1px solid #d1d5db; border-radius:12px;
                       font-size:11px; font-family:inherit; color:#1f2937; width:200px; }
    .filters .search:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; background:#fff; }
    .filters .chip { padding:3px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                     font-size:11px; color:#374151; text-decoration:none; cursor:pointer; font-family:inherit; }
    .filters .chip:hover { background:#f3f4f6; }
    .filters .chip.on { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }

    /* ===== Panel / table (dashboard .table-wrap) ===== */
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:760px; }
    th, td { padding:5px 8px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    td.empty { text-align:center; color:#9ca3af; padding:25px; }
    .name-cell { font-weight:700; color:#1f2937; }
    .dim { color:#9ca3af; }
    .num { text-align:center; }
    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.active   { background:#dcfce7; color:#166534; }
    .badge.inactive { background:#fef3c7; color:#92400e; }
    .badge.picks    { background:#dbeafe; color:#1e40af; }

    /* ===== Buttons (dashboard flat .btn) ===== */
    .btn { padding:2px 8px; border:none; border-radius:3px; color:#fff; font-size:10px; font-weight:700;
           cursor:pointer; text-decoration:none; font-family:inherit; transition:background .15s, transform .1s; }
    .btn:active { transform:scale(.97); }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-red   { background:#ef4444; } .btn-red:hover   { background:#dc2626; }
    .btn-lg    { padding:5px 12px; font-size:11px; border-radius:4px; }
    .btn-block { display:block; width:100%; margin-top:10px; }
    .inline-form { display:inline-block; margin-left:4px; }

    /* ===== Modal ===== */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:100;
                     align-items:flex-start; justify-content:center; padding:40px 14px; overflow-y:auto; }
    .modal-overlay.show { display:flex; }
    .modal { background:#fff; border-radius:6px; width:100%; max-width:460px; overflow:hidden;
             box-shadow:0 12px 40px rgba(0,0,0,.25); }
    .modal-head { display:flex; align-items:center; gap:8px; padding:6px 14px; color:#fff;
                  font-size:11px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; background:#1d4ed8; }
    .modal-head .modal-close { margin-left:auto; background:none; border:none; font-size:16px; line-height:1;
                               cursor:pointer; color:#fff; opacity:.85; }
    .modal-head .modal-close:hover { opacity:1; }
    .modal-body { padding:14px 16px 16px; }
    .modal-overlay.show .modal { animation:popIn .2s ease; }
    @keyframes popIn { from { transform:translateY(-10px); opacity:0; } to { transform:translateY(0); opacity:1; } }
    .modal-actions { margin-top:14px; }
    .divider { border:none; border-top:1px dashed #e5e7eb; margin:14px 0 8px; }

    /* ===== Forms ===== */
    label { display:block; margin:10px 0 4px; font-size:10px; font-weight:700;
            letter-spacing:.3px; text-transform:uppercase; color:#64748b; }
    label small { text-transform:none; letter-spacing:0; font-weight:400; }
    input[type="text"], select { width:100%; padding:7px 10px; background:#f8fafc; border:1px solid #d1d5db;
                                 border-radius:6px; color:#1f2937; font-size:12px; font-family:inherit;
                                 transition:border-color .15s, box-shadow .15s; }
    input[type="text"]::placeholder { color:#9ca3af; }
    input[type="text"]:focus, select:focus { outline:none; background:#fff; border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }

    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
        .field-row { grid-template-columns:1fr; }
        .filters .search { width:100%; }
    }
</style>
</head>
<body>
<div class="layout">

    <aside class="sidebar">
        <div class="sidebar-brand">📦 MSPANAY</div>
        <div class="sidebar-user">
            👤 <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?><br>
            <small><?= htmlspecialchars($_SESSION['role'] ?? '') ?></small>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">📊 Monitoring</a>
            <a href="add_picklist.php" class="<?= $current_page === 'add_picklist.php' ? 'active' : '' ?>">➕ Add / Import</a>
            <a href="import_history.php" class="<?= $current_page === 'import_history.php' ? 'active' : '' ?>">📄 History</a>
            <a href="retailers.php" class="<?= $current_page === 'retailers.php' ? 'active' : '' ?>">🏬 Retailers</a>
            <a href="staff.php" class="<?= $current_page === 'staff.php' ? 'active' : '' ?>">👥 Staff</a>
            <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">📈 Reports</a>
            <a href="performance.php" class="<?= $current_page === 'performance.php' ? 'active' : '' ?>">🏆 Performance</a>
            <a href="miss_tracking.php" class="<?= $current_page === 'miss_tracking.php' ? 'active' : '' ?>">❌ Miss Tracking</a>
            <a href="users.php" class="<?= $current_page === 'users.php' ? 'active' : '' ?>">👤 Users</a>
            <a href="settings.php" class="<?= $current_page === 'settings.php' ? 'active' : '' ?>">⚙️ Settings</a>
        </nav>
        <a href="logout.php" class="sidebar-logout">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <h1>🏬 Retailers
            <span style="font-size:11px;color:#475569;background:#f1f5f9;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                <?= $total ?> shops
            </span>
        </h1>

        <?php if ($msg): ?>
            <p class="alert <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></p>
        <?php endif; ?>

        <div class="cards">
            <div class="card total"><b><?= $total ?></b><span>Total</span></div>
            <div class="card active"><b><?= $active ?></b><span>Active</span></div>
            <div class="card inactive"><b><?= $inactive ?></b><span>Inactive</span></div>
            <div class="card picks"><b><?= $sumPicks ?></b><span>Picklists</span></div>
        </div>

        <!-- ================= FILTER BAR ================= -->
        <div class="filters">
            <input type="text" class="search" id="retailSearch"
                   placeholder="🔍 Search name, code, contact, area..."
                   oninput="filterTable()">
            <button type="button" class="chip on" data-status="all" onclick="setStatus(this)">All</button>
            <button type="button" class="chip" data-status="active" onclick="setStatus(this)">Active</button>
            <button type="button" class="chip" data-status="inactive" onclick="setStatus(this)">Inactive</button>
            <button type="button" class="btn btn-blue btn-lg" onclick="openModal('retailModal')">➕ Add Retailer</button>
            <span class="pp" id="countLabel">📄 <?= $total ?> retailer<?= $total === 1 ? '' : 's' ?></span>
        </div>

        <!-- ================= TABLE ================= -->
        <div class="table-wrap">
        <table id="retailTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Retailer</th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Area</th>
                    <th class="num">Picklists</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($total): ?>
                <?php $i = 1; foreach ($retailers as $r):
                    $st = ($r['status'] ?? 'active');
                    $pc = (int)($r['picklist_count'] ?? 0);
                ?>
                <tr data-status="<?= htmlspecialchars($st) ?>"
                    data-name="<?= htmlspecialchars(strtolower(($r['name'] ?? '') . ' ' . ($r['code'] ?? '') . ' ' . ($r['contact_person'] ?? '') . ' ' . ($r['phone'] ?? '') . ' ' . ($r['area'] ?? '') . ' ' . ($r['address'] ?? ''))) ?>">
                    <td class="dim"><?= str_pad($i++, 2, '0', STR_PAD_LEFT) ?></td>
                    <td class="name-cell"><?= htmlspecialchars($r['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['code'] ?? '') ?: '<span class="dim">—</span>' ?></td>
                    <td><?= htmlspecialchars($r['contact_person'] ?? '') ?: '<span class="dim">—</span>' ?></td>
                    <td><?= htmlspecialchars($r['phone'] ?? '') ?: '<span class="dim">—</span>' ?></td>
                    <td><?= htmlspecialchars($r['area'] ?? '') ?: '<span class="dim">—</span>' ?></td>
                    <td class="num"><?php if ($pc): ?><span class="badge picks"><?= $pc ?></span><?php else: ?><span class="dim">0</span><?php endif; ?></td>
                    <td><span class="badge <?= $st === 'inactive' ? 'inactive' : 'active' ?>"><?= ucfirst($st) ?></span></td>
                    <td>
                        <button type="button" class="btn btn-blue" onclick="openRetailerModal(<?= (int)$r['id'] ?>)">✏ Edit</button>
                        <form method="POST" class="inline-form"
                              onsubmit="return confirm('Delete retailer &quot;<?= htmlspecialchars($r['name'] ?? '') ?>&quot;?\n\nExisting picklists keep their retailer name.')">
                            <input type="hidden" name="delete_retailer" value="1">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-red">🗑 Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="9" class="empty">No retailers yet. Click "Add Retailer" above, or import a picklist file — known shops are added automatically.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

    </main>
</div>

<!-- ================= POPUP: ADD RETAILER ================= -->
<div class="modal-overlay" id="retailModal">
    <div class="modal">
        <div class="modal-head">
            <span>➕ Add Retailer</span>
            <button type="button" class="modal-close" onclick="closeModal('retailModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="add_retailer" value="1">

                <label>Retailer Name <small>(required)</small></label>
                <input type="text" name="name" placeholder="e.g. ABC GENERAL MERCHANDISE" required>

                <div class="field-row">
                    <div>
                        <label>Code <small>(optional)</small></label>
                        <input type="text" name="code" placeholder="e.g. RT-001">
                    </div>
                    <div>
                        <label>Area <small>(optional)</small></label>
                        <input type="text" name="area" placeholder="e.g. Cebu City">
                    </div>
                </div>

                <div class="field-row">
                    <div>
                        <label>Contact Person <small>(optional)</small></label>
                        <input type="text" name="contact_person" placeholder="e.g. Juan Dela Cruz">
                    </div>
                    <div>
                        <label>Phone <small>(optional)</small></label>
                        <input type="text" name="phone" placeholder="e.g. 0917 123 4567">
                    </div>
                </div>

                <label>Address <small>(optional)</small></label>
                <input type="text" name="address" placeholder="e.g. 123 Osmeña Blvd, Cebu City">

                <label>Status</label>
                <select name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-blue btn-block">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= POPUP: EDIT RETAILER ================= -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-head">
            <span>✏ Edit Retailer</span>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="edit_retailer" value="1">
                <input type="hidden" name="id" id="e_id">

                <label>Retailer Name <small>(required)</small></label>
                <input type="text" name="name" id="e_name" required>

                <div class="field-row">
                    <div>
                        <label>Code <small>(optional)</small></label>
                        <input type="text" name="code" id="e_code">
                    </div>
                    <div>
                        <label>Area <small>(optional)</small></label>
                        <input type="text" name="area" id="e_area">
                    </div>
                </div>

                <div class="field-row">
                    <div>
                        <label>Contact Person <small>(optional)</small></label>
                        <input type="text" name="contact_person" id="e_contact">
                    </div>
                    <div>
                        <label>Phone <small>(optional)</small></label>
                        <input type="text" name="phone" id="e_phone">
                    </div>
                </div>

                <label>Address <small>(optional)</small></label>
                <input type="text" name="address" id="e_address">

                <label>Status</label>
                <select name="status" id="e_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-blue btn-block">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const retailerData = <?= json_encode($retailers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function openModal(id)  { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function openRetailerModal(id) {
        const r = retailerData.find(x => parseInt(x.id) === parseInt(id));
        if (!r) return;
        document.getElementById('e_id').value       = r.id;
        document.getElementById('e_name').value     = r.name || '';
        document.getElementById('e_code').value     = r.code || '';
        document.getElementById('e_area').value     = r.area || '';
        document.getElementById('e_contact').value  = r.contact_person || '';
        document.getElementById('e_phone').value    = r.phone || '';
        document.getElementById('e_address').value  = r.address || '';
        document.getElementById('e_status').value   = r.status || 'active';
        openModal('editModal');
    }

    /* ===== Search + status filter ===== */
    let curStatus = 'all';

    function setStatus(btn) {
        curStatus = btn.dataset.status;
        document.querySelectorAll('.filters .chip').forEach(c => c.classList.remove('on'));
        btn.classList.add('on');
        filterTable();
    }

    function filterTable() {
        const q = (document.getElementById('retailSearch').value || '').toLowerCase();
        let shown = 0;
        document.querySelectorAll('#retailTable tbody tr').forEach(tr => {
            if (!tr.dataset.name) return;            // empty row
            const matchQ = !q || tr.dataset.name.indexOf(q) !== -1;
            const matchS = (curStatus === 'all') || (tr.dataset.status === curStatus);
            const show = matchQ && matchS;
            tr.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        const label = document.getElementById('countLabel');
        if (label) label.textContent = '📄 ' + shown + ' retailer' + (shown === 1 ? '' : 's') + ' shown';
    }

    /* Close on backdrop / Esc */
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('show'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    });

    /* Auto-dismiss alert */
    const alertBox = document.querySelector('.alert');
    if (alertBox) setTimeout(() => { alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 4000);
</script>

</body>
</html>