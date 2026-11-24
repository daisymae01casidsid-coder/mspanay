<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $msg = '';
 $msg_type = 'success';

/* ================= CONFIG: PHOTO UPLOAD ================= */
define('UPLOAD_DIR', __DIR__ . '/uploads/staff/');          // physical path
define('UPLOAD_URL', 'uploads/staff/');                     // relative path stored in DB / used in <img src>
define('MAX_PHOTO_BYTES', 2 * 1024 * 1024);                 // 2 MB
define('ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// Make sure the upload directory exists
if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0775, true); }

/**
 * Handle an uploaded photo file ($_FILES key). Returns:
 *   ['ok'=>true,  'path'=>$relativePath]  on success
 *   ['ok'=>false, 'msg'=>$errorMessage]  on failure
 */
function handle_photo_upload($field) {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];              // no file = no change
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Photo upload failed (code ' . $f['error'] . ').'];
    }
    if ($f['size'] > MAX_PHOTO_BYTES) {
        return ['ok' => false, 'msg' => 'Photo is larger than 2 MB.'];
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
    if (!in_array($mime, ALLOWED_MIME, true)) {
        return ['ok' => false, 'msg' => 'Only JPG, PNG, WEBP and GIF are allowed.'];
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return ['ok' => false, 'msg' => 'Photo has an unsupported extension.'];
    }
    $safe = 'staff_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $safe)) {
        return ['ok' => false, 'msg' => 'Could not save the uploaded photo.'];
    }
    return ['ok' => true, 'path' => UPLOAD_URL . $safe];
}

/** Delete a stored photo file from disk (silently ignores missing). */
function delete_photo_file($relPath) {
    if (!$relPath) { return; }
    $abs = __DIR__ . '/' . $relPath;
    if (is_file($abs)) { @unlink($abs); }
}

/** Return initials (max 2 chars) from a full name. */
function initials_of($name) {
    $parts = preg_split('/\s+/', trim($name) ?: '?');
    $ini = '';
    foreach ($parts as $p) { if ($p !== '') { $ini .= strtoupper($p[0]); } }
    return substr($ini, 0, 2) ?: '?';
}

/* ================= ONE-TIME SEED: PICKERS ================= */
 $default_pickers = [
    'ALEX SANAO', 'DAN LOYD CALICO', 'DONARD LAMES', 'FRANZ JAMELO',
    'JAWORSKIE HISUG', 'JEARLD LOSSBANES', 'JOHN ESPINOSA', 'JOHNREY SANTILLIAN',
    'JOSE POLIQUET', 'JOSHUA HISUG', 'JOWORSKIE HISUG', 'MARK FAURA',
    'MARKJUNE PINUELA', 'MIKKO JAMEHO', 'OSB CM', 'PAUL JORNADAL',
    'RONEL HISUG', 'RUPERTO LUMAWAG', 'VINCE PINUELA',
];
 $seed_check = $conn->prepare("SELECT id FROM staff_list WHERE LOWER(full_name) = LOWER(?) AND role = 'picker'");
 $seed_insert = $conn->prepare("INSERT INTO staff_list (full_name, role, warehouse) VALUES (?, 'picker', '')");
foreach ($default_pickers as $pname) {
    $seed_check->bind_param("s", $pname);
    $seed_check->execute();
    if ($seed_check->get_result()->num_rows === 0) {
        $seed_insert->bind_param("s", $pname);
        $seed_insert->execute();
    }
}
 $seed_check->close(); $seed_insert->close();

/* ================= ONE-TIME SEED: CHECKERS ================= */
 $default_checkers = [
    'CHRISTIAN GALLENTES', 'DONARD LAMES', 'HERMAN HISUG', 'JAWORSKIE HISUG',
    'JOHNREY JUARIO', 'JONY SIONILLO', 'JOSE POLIQUET', 'JUMARK HISUG',
    'LEEBY YOCOGCO', 'LEOBY LACHICA', 'MARKJUNE PINUELA', 'NELSON JAMELO',
    'NORLEM CASTOR', 'RAY ESPINOSA', 'ROGGEN ALBIA', 'RUPERTO LUMAWAG',
];
 $checker_check = $conn->prepare("SELECT id FROM staff_list WHERE LOWER(full_name) = LOWER(?) AND role = 'checker'");
 $checker_insert = $conn->prepare("INSERT INTO staff_list (full_name, role, warehouse) VALUES (?, 'checker', '')");
foreach ($default_checkers as $cname) {
    $checker_check->bind_param("s", $cname);
    $checker_check->execute();
    if ($checker_check->get_result()->num_rows === 0) {
        $checker_insert->bind_param("s", $cname);
        $checker_insert->execute();
    }
}
 $checker_check->close(); $checker_insert->close();

/* ================= ADD STAFF ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $name     = trim($_POST['full_name'] ?? '');
    $role     = $_POST['role'] ?? '';
    $wh       = trim($_POST['warehouse'] ?? '');
    $age      = isset($_POST['age']) && $_POST['age'] !== '' ? (int)$_POST['age'] : null;
    $position = trim($_POST['position'] ?? '');

    if ($name !== '' && in_array($role, ['picker', 'checker'], true)) {
        if ($age !== null && ($age < 16 || $age > 90)) {
            $msg = 'Age must be between 16 and 90.'; $msg_type = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM staff_list WHERE LOWER(full_name) = LOWER(?) AND role = ?");
            $check->bind_param("ss", $name, $role);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                $msg = "\"{$name}\" is already in the " . ucfirst($role) . " list."; $msg_type = 'error';
            } else {
                $up = handle_photo_upload('photo');
                if (!$up['ok']) { $msg = $up['msg']; $msg_type = 'error'; }
                else {
                    $photo = $up['path'];
                    $stmt = $conn->prepare(
                        "INSERT INTO staff_list (full_name, role, warehouse, photo, age, position)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param("ssssis", $name, $role, $wh, $photo, $age, $position);
                    $stmt->execute();
                    $msg = ucfirst($role) . " \"{$name}\" added.";
                }
            }
        }
    }
}

/* ================= EDIT STAFF ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff'])) {
    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['full_name'] ?? '');
    $role     = $_POST['role'] ?? '';
    $wh       = trim($_POST['warehouse'] ?? '');
    $age      = isset($_POST['age']) && $_POST['age'] !== '' ? (int)$_POST['age'] : null;
    $position = trim($_POST['position'] ?? '');
    $removePhoto = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';

    if ($id > 0 && $name !== '' && in_array($role, ['picker', 'checker'], true)) {
        if ($age !== null && ($age < 16 || $age > 90)) {
            $msg = 'Age must be between 16 and 90.'; $msg_type = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM staff_list WHERE LOWER(full_name) = LOWER(?) AND role = ? AND id <> ?");
            $check->bind_param("ssi", $name, $role, $id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                $msg = "\"{$name}\" is already in the " . ucfirst($role) . " list."; $msg_type = 'error';
            } else {
                // fetch existing photo so we know what to replace / keep / delete
                $g = $conn->prepare("SELECT photo FROM staff_list WHERE id = ?");
                $g->bind_param("i", $id); $g->execute();
                $oldPhoto = $g->get_result()->fetch_assoc()['photo'] ?? null;
                $g->close();

                $up = handle_photo_upload('photo');
                if (!$up['ok']) { $msg = $up['msg']; $msg_type = 'error'; }
                else {
                    $newPhoto = $oldPhoto;
                    if ($up['path']) {                          // new photo uploaded
                        $newPhoto = $up['path'];
                        if ($oldPhoto) { delete_photo_file($oldPhoto); }
                    } elseif ($removePhoto) {                   // user cleared it
                        $newPhoto = null;
                        if ($oldPhoto) { delete_photo_file($oldPhoto); }
                    }

                    $stmt = $conn->prepare(
                        "UPDATE staff_list
                            SET full_name = ?, role = ?, warehouse = ?, photo = ?, age = ?, position = ?
                          WHERE id = ?"
                    );
                    $stmt->bind_param("ssssssi", $name, $role, $wh, $newPhoto, $age, $position, $id);
                    $stmt->execute();
                    $msg = "Staff updated successfully.";
                }
            }
        }
    }
}

/* ================= DELETE STAFF ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $g = $conn->prepare("SELECT photo FROM staff_list WHERE id = ?");
        $g->bind_param("i", $id); $g->execute();
        $photo = $g->get_result()->fetch_assoc()['photo'] ?? null;
        $g->close();
        if ($photo) { delete_photo_file($photo); }

        $stmt = $conn->prepare("DELETE FROM staff_list WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $msg = "Staff deleted.";
    }
}

/* ================= DATA FOR PAGE ================= */
 $pickers = [];
 $res = $conn->query("SELECT * FROM staff_list WHERE role = 'picker' ORDER BY full_name");
while ($row = $res->fetch_assoc()) { $pickers[] = $row; }

 $checkers = [];
 $res = $conn->query("SELECT * FROM staff_list WHERE role = 'checker' ORDER BY full_name");
while ($row = $res->fetch_assoc()) { $checkers[] = $row; }

 $picker_count  = count($pickers);
 $checker_count = count($checkers);
 $all_staff     = array_merge($pickers, $checkers);

 $current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pickers &amp; Checkers</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (identical to dashboard) ===== */
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
    .card.total   { border-top-color:#64748b; } .card.total b   { color:#475569; }
    .card.pick    { border-top-color:#22c55e; } .card.pick b    { color:#15803d; }
    .card.check   { border-top-color:#3b82f6; } .card.check b   { color:#1d4ed8; }

    /* ===== Filter / toolbar bar (dashboard .filters) ===== */
    .filters { margin-bottom:8px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
    .filters a, .filters button.chip { padding:3px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; cursor:pointer; font-family:inherit; }
    .filters a:hover, .filters button.chip:hover { background:#f3f4f6; }
    .filters a.active { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }

    /* ===== Panel (dashboard .table-wrap + group header band) ===== */
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:900px) { .two-col { grid-template-columns:1fr; } }
    .panel { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
    .panel-head { display:flex; align-items:center; gap:8px; padding:6px 12px; color:#fff; font-size:11px;
                  font-weight:700; letter-spacing:.3px; text-transform:uppercase; }
    .panel-head.g-picker  { background:#15803d; }
    .panel-head.g-checker { background:#1d4ed8; }
    .panel-head .rule { flex:1; }
    .panel-head .cnt { background:rgba(255,255,255,.22); padding:1px 8px; border-radius:8px; font-size:10px; font-weight:700; }

    /* ===== Search (inside panel) ===== */
    .search-wrap { padding:10px 10px 2px; }
    .search { width:100%; padding:6px 10px; background:#f8fafc; border:1px solid #d1d5db; border-radius:12px;
              color:#1f2937; font-size:11px; font-family:inherit; transition:border-color .15s, box-shadow .15s; }
    .search::placeholder { color:#9ca3af; }
    .search:focus { outline:none; background:#fff; border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; }

    /* ===== Staff list (dashboard tbody look) ===== */
    .list { list-style:none; }
    .empty { color:#9ca3af; font-style:italic; padding:25px 12px !important; text-align:center; font-size:11px; }
    .staff-item { display:flex; align-items:center; padding:6px 10px; font-size:11px; cursor:pointer;
                  border-bottom:1px solid #eef1f5; transition:background .12s; }
    .staff-item:hover { background:#f6f9fc; }
    .staff-item:last-child { border-bottom:none; }
    .idx { width:18px; flex-shrink:0; font-size:10px; font-weight:700; color:#9ca3af; }

    /* avatar */
    .avatar { width:30px; height:30px; border-radius:50%; margin:0 9px 0 4px; flex-shrink:0;
              border:2px solid #e5e7eb; background:#e2e8f0;
              display:inline-flex; align-items:center; justify-content:center;
              font-size:11px; font-weight:700; color:#475569; overflow:hidden; }
    .avatar.green { border-color:#bbf7d0; background:#dcfce7; color:#15803d; }
    .avatar.blue  { border-color:#bfdbfe; background:#dbeafe; color:#1d4ed8; }
    .avatar img  { width:100%; height:100%; object-fit:cover; display:block; }

    .s-name { flex:1; font-weight:700; color:#1f2937; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .s-sub  { display:block; font-size:9px; color:#9ca3af; font-weight:400; margin-top:2px; }
    .s-sub .badge { display:inline-block; padding:1px 6px; border-radius:8px; font-size:9px; font-weight:700;
                    background:#f1f5f9; color:#475569; margin-right:3px; line-height:14px; }
    .s-sub .badge.pick  { background:#dcfce7; color:#166534; }
    .s-sub .badge.check { background:#dbeafe; color:#1e40af; }
    .s-sub .badge.age   { background:#fef3c7; color:#92400e; }
    .s-sub .badge.wh    { background:#ede9fe; color:#5b21b6; }
    .row-del { display:flex; }

    /* ===== Buttons (dashboard flat .btn) ===== */
    .btn { padding:2px 8px; border:none; border-radius:3px; color:#fff; font-size:10px; font-weight:700;
           cursor:pointer; font-family:inherit; transition:background .15s, transform .1s; }
    .btn:active { transform:scale(.97); }
    .btn:disabled { opacity:.6; cursor:default; }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .btn-red   { background:#ef4444; } .btn-red:hover   { background:#dc2626; }
    .btn-outline-red { background:#fff; color:#b91c1c; border:1px solid #fecaca; padding:2px 8px; border-radius:3px; }
    .btn-outline-red:hover { background:#fee2e2; }
    .btn-lg { padding:5px 12px; font-size:11px; border-radius:4px; }
    .btn-block { display:block; width:100%; margin-top:10px; }

    /* ===== Modal ===== */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:100;
                     align-items:flex-start; justify-content:center; padding:40px 14px; overflow-y:auto; }
    .modal-overlay.show { display:flex; }
    .modal { background:#fff; border-radius:6px; width:100%; max-width:420px; overflow:hidden;
             box-shadow:0 12px 40px rgba(0,0,0,.25); }
    .modal-head { display:flex; align-items:center; gap:8px; padding:6px 14px; color:#fff;
                  font-size:11px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; }
    .modal-head.g-blue   { background:#1d4ed8; }
    .modal-head.g-danger { background:#b91c1c; }
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
    input[type="text"], input[type="number"], input[type="file"], select { width:100%; padding:7px 10px; background:#f8fafc;
                                 border:1px solid #d1d5db; border-radius:6px; color:#1f2937; font-size:12px;
                                 font-family:inherit; transition:border-color .15s, box-shadow .15s; }
    input[type="text"]::placeholder { color:#9ca3af; }
    input[type="text"]:focus, input[type="number"]:focus, select:focus { outline:none; background:#fff; border-color:#3b82f6;
                                             box-shadow:0 0 0 2px #dbeafe; }
    input[type="file"] { padding:5px 8px; font-size:11px; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }

    /* ===== Photo picker ===== */
    .photo-row { display:flex; align-items:center; gap:12px; margin-top:6px; }
    .photo-preview { width:60px; height:60px; border-radius:50%; border:2px dashed #cbd5e1;
                     background:#f8fafc; display:flex; align-items:center; justify-content:center;
                     color:#94a3b8; font-size:16px; font-weight:700; overflow:hidden; flex-shrink:0; }
    .photo-preview img { width:100%; height:100%; object-fit:cover; }
    .photo-controls { flex:1; }
    .photo-clear { margin-top:6px; display:inline-flex; align-items:center; gap:4px;
                   font-size:11px; color:#b91c1c; cursor:pointer; }

    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
        .field-row { grid-template-columns:1fr; }
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
        <h1>👥 Pickers &amp; Checkers
            <span style="font-size:11px;color:#475569;background:#f1f5f9;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                <?= $picker_count + $checker_count ?> staff
            </span>
        </h1>

        <?php if ($msg): ?>
            <p class="alert <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></p>
        <?php endif; ?>

        <div class="cards">
            <div class="card total"><b><?= $picker_count + $checker_count ?></b><span>Total</span></div>
            <div class="card pick"><b><?= $picker_count ?></b><span>Pickers</span></div>
            <div class="card check"><b><?= $checker_count ?></b><span>Checkers</span></div>
        </div>

        <div class="filters">
            <button type="button" class="btn btn-blue btn-lg" onclick="openModal('staffModal')">➕ Add Staff</button>
            <span class="pp">👥 <?= $picker_count + $checker_count ?> staff on file</span>
        </div>

        <div class="two-col">

            <!-- ============ PICKERS (GREEN — matches dashboard g-picker) ============ -->
            <div class="panel">
                <div class="panel-head g-picker">
                    <span>👷 Pickers</span>
                    <span class="rule"></span>
                    <span class="cnt"><?= $picker_count ?></span>
                </div>

                <div class="search-wrap">
                    <input type="text" class="search" id="pickerSearch"
                           placeholder="Search picker..."
                           oninput="filterList('pickerSearch', 'pickerList')">
                </div>

                <ul class="list" id="pickerList">
                    <?php if ($picker_count): ?>
                        <?php $i = 1; foreach ($pickers as $p): ?>
                            <li class="staff-item"
                                data-name="<?= htmlspecialchars($p['full_name']) ?>"
                                onclick="openStaffModal(<?= (int)$p['id'] ?>)">
                                <span class="idx"><?= str_pad($i++, 2, '0', STR_PAD_LEFT) ?></span>
                                <?php if (!empty($p['photo']) && is_file(__DIR__ . '/' . $p['photo'])): ?>
                                    <span class="avatar green"><img src="<?= htmlspecialchars($p['photo']) ?>" alt=""></span>
                                <?php else: ?>
                                    <span class="avatar green"><?= htmlspecialchars(initials_of($p['full_name'])) ?></span>
                                <?php endif; ?>
                                <span class="s-name">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                    <span class="s-sub">
                                        <?php if (!empty($p['position'])): ?>
                                            <span class="badge pick"><?= htmlspecialchars($p['position']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['age'])): ?>
                                            <span class="badge age"><?= (int)$p['age'] ?> yrs</span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['warehouse'])): ?>
                                            <span class="badge wh"><?= htmlspecialchars($p['warehouse']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                                <form method="POST" class="row-del" onclick="event.stopPropagation()">
                                    <input type="hidden" name="delete_staff" value="1">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn-outline-red" onclick="return confirm('Delete this picker?')">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="empty">No pickers yet. Click "Add Staff" above.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- ============ CHECKERS (BLUE — matches dashboard g-checker) ============ -->
            <div class="panel">
                <div class="panel-head g-checker">
                    <span>✅ Checkers</span>
                    <span class="rule"></span>
                    <span class="cnt"><?= $checker_count ?></span>
                </div>

                <div class="search-wrap">
                    <input type="text" class="search" id="checkerSearch"
                           placeholder="Search checker..."
                           oninput="filterList('checkerSearch', 'checkerList')">
                </div>

                <ul class="list" id="checkerList">
                    <?php if ($checker_count): ?>
                        <?php $i = 1; foreach ($checkers as $c): ?>
                            <li class="staff-item"
                                data-name="<?= htmlspecialchars($c['full_name']) ?>"
                                onclick="openStaffModal(<?= (int)$c['id'] ?>)">
                                <span class="idx"><?= str_pad($i++, 2, '0', STR_PAD_LEFT) ?></span>
                                <?php if (!empty($c['photo']) && is_file(__DIR__ . '/' . $c['photo'])): ?>
                                    <span class="avatar blue"><img src="<?= htmlspecialchars($c['photo']) ?>" alt=""></span>
                                <?php else: ?>
                                    <span class="avatar blue"><?= htmlspecialchars(initials_of($c['full_name'])) ?></span>
                                <?php endif; ?>
                                <span class="s-name">
                                    <?= htmlspecialchars($c['full_name']) ?>
                                    <span class="s-sub">
                                        <?php if (!empty($c['position'])): ?>
                                            <span class="badge check"><?= htmlspecialchars($c['position']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['age'])): ?>
                                            <span class="badge age"><?= (int)$c['age'] ?> yrs</span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['warehouse'])): ?>
                                            <span class="badge wh"><?= htmlspecialchars($c['warehouse']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                                <form method="POST" class="row-del" onclick="event.stopPropagation()">
                                    <input type="hidden" name="delete_staff" value="1">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn-outline-red" onclick="return confirm('Delete this checker?')">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="empty">No checkers yet. Click "Add Staff" above.</li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>

    </main>
</div>

<!-- ================= POPUP: ADD NEW STAFF ================= -->
<div class="modal-overlay" id="staffModal">
    <div class="modal">
        <div class="modal-head g-blue">
            <span>➕ Add New Staff</span>
            <button type="button" class="modal-close" onclick="closeModal('staffModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="add_staff" value="1">

                <label>Photo <small>(optional, max 2 MB)</small></label>
                <div class="photo-row">
                    <div class="photo-preview" id="addPreview">—</div>
                    <div class="photo-controls">
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="previewPhoto(this, 'addPreview')">
                    </div>
                </div>

                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="e.g. ALEX SANAO" required>

                <label>Role</label>
                <select name="role" required>
                    <option value="picker">Picker</option>
                    <option value="checker">Checker</option>
                </select>

                <div class="field-row">
                    <div>
                        <label>Age <small>(optional)</small></label>
                        <input type="number" name="age" min="16" max="90" placeholder="e.g. 28">
                    </div>
                    <div>
                        <label>Warehouse <small>(optional)</small></label>
                        <input type="text" name="warehouse" placeholder="e.g. WH-01">
                    </div>
                </div>

                <label>Position <small>(optional)</small></label>
                <input type="text" name="position" placeholder="e.g. Senior Picker">

                <div class="modal-actions">
                    <button type="submit" class="btn btn-blue btn-block">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= POPUP: VIEW / EDIT STAFF ================= -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-head g-danger">
            <span>👤 Staff Details</span>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="edit_staff" value="1">
                <input type="hidden" name="id" id="e_id">
                <input type="hidden" name="remove_photo" id="e_remove_photo" value="0">

                <label>Photo <small>(optional, max 2 MB)</small></label>
                <div class="photo-row">
                    <div class="photo-preview" id="editPreview">—</div>
                    <div class="photo-controls">
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="previewPhoto(this, 'editPreview')">
                        <label class="photo-clear" id="photoClearWrap" style="display:none;">
                            <input type="checkbox" id="photoClear" onchange="toggleRemovePhoto(this)">
                            Remove current photo
                        </label>
                    </div>
                </div>

                <label>Full Name</label>
                <input type="text" name="full_name" id="e_name" required>

                <label>Role</label>
                <select name="role" id="e_role">
                    <option value="picker">Picker</option>
                    <option value="checker">Checker</option>
                </select>

                <div class="field-row">
                    <div>
                        <label>Age <small>(optional)</small></label>
                        <input type="number" name="age" id="e_age" min="16" max="90" placeholder="e.g. 28">
                    </div>
                    <div>
                        <label>Warehouse <small>(optional)</small></label>
                        <input type="text" name="warehouse" id="e_wh" placeholder="e.g. WH-01">
                    </div>
                </div>

                <label>Position <small>(optional)</small></label>
                <input type="text" name="position" id="e_position" placeholder="e.g. Senior Picker">

                <div class="modal-actions">
                    <button type="submit" class="btn btn-blue btn-block">Save Changes</button>
                </div>
            </form>

            <hr class="divider">

            <form method="POST" onsubmit="return confirm('Delete this staff permanently?')">
                <input type="hidden" name="delete_staff" value="1">
                <input type="hidden" name="id" id="d_id">
                <button type="submit" class="btn btn-red btn-block">Delete Staff</button>
            </form>
        </div>
    </div>
</div>

<script>
    const staffData = <?= json_encode($all_staff, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function openModal(id)  { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function openStaffModal(id) {
        const s = staffData.find(x => parseInt(x.id) === parseInt(id));
        if (!s) return;

        document.getElementById('e_id').value        = s.id;
        document.getElementById('e_name').value      = s.full_name || '';
        document.getElementById('e_role').value      = s.role || 'picker';
        document.getElementById('e_age').value       = s.age || '';
        document.getElementById('e_wh').value        = s.warehouse || '';
        document.getElementById('e_position').value  = s.position || '';
        document.getElementById('d_id').value        = s.id;

        // reset photo controls
        document.getElementById('e_remove_photo').value = '0';
        const clearBox = document.getElementById('photoClear');
        const clearWrap = document.getElementById('photoClearWrap');
        if (clearBox) clearBox.checked = false;

        // show current photo (or initials) in the preview
        const preview = document.getElementById('editPreview');
        preview.innerHTML = '';
        if (s.photo) {
            const img = document.createElement('img');
            img.src = s.photo;
            preview.appendChild(img);
            if (clearWrap) clearWrap.style.display = '';
        } else {
            preview.textContent = (s.full_name || '?').trim().charAt(0).toUpperCase();
            if (clearWrap) clearWrap.style.display = 'none';
        }

        openModal('editModal');
    }

    function toggleRemovePhoto(checkbox) {
        document.getElementById('e_remove_photo').value = checkbox.checked ? '1' : '0';
        if (checkbox.checked) {
            const preview = document.getElementById('editPreview');
            preview.innerHTML = '';
            preview.textContent = '?';
        }
    }

    function previewPhoto(input, previewId) {
        const preview = document.getElementById(previewId);
        const file = input.files && input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = '';
            const img = document.createElement('img');
            img.src = e.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
        // selecting a new file cancels "remove"
        if (previewId === 'editPreview') {
            document.getElementById('e_remove_photo').value = '0';
            const clearBox = document.getElementById('photoClear');
            if (clearBox) clearBox.checked = false;
        }
    }

    function filterList(inputId, listId) {
        const q = document.getElementById(inputId).value.toLowerCase();
        document.querySelectorAll('#' + listId + ' .staff-item').forEach(li => {
            li.style.display = li.dataset.name.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.classList.remove('show');
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
        }
    });

    const alertBox = document.querySelector('.alert');
    if (alertBox) setTimeout(() => { alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 4000);
</script>

</body>
</html>