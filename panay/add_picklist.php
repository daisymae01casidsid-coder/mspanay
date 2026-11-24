<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);

/* Messages come back from import_process.php via URL */
 $success = $_GET['success'] ?? '';
 $error   = $_GET['error']   ?? '';
 $date    = $_GET['date']    ?? date('Y-m-d');

 $dt = DateTime::createFromFormat('Y-m-d', $date);
 $date = $dt ? $dt->format('Y-m-d') : date('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add / Import Picklist</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px — matches dashboard & import_history) ===== */
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

    /* ===== Compact main ===== */
    .main-content { margin-left:var(--sidebar-w); flex:1; padding:12px 14px; }
    h1 { font-size:16px; margin-bottom:10px; }
    .page-sub { color:#64748b; font-size:12px; margin:-6px 0 10px; }

    /* ===== Compact alerts ===== */
    .alert { padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:12px; }
    .ok  { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .bad { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

    /* ===== Compact form card ===== */
    .card { background:#fff; border-radius:6px; padding:16px;
            box-shadow:0 1px 4px rgba(0,0,0,.07); max-width:560px; }
    .form-group { margin-bottom:12px; }
    .form-group label { display:block; font-size:10px; text-transform:uppercase;
                        color:#64748b; font-weight:700; margin-bottom:4px; letter-spacing:.4px; }
    .form-group input[type=date] {
        width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px;
        font-size:12px; font-family:inherit; }
    .form-group input[type=date]:focus { outline:none; border-color:#22c55e; box-shadow:0 0 0 2px #dcfce7; }

    /* ===== Compact drop zone ===== */
    .drop { border:2px dashed #94a3b8; border-radius:6px; padding:18px 14px; text-align:center;
            background:#f8fafc; cursor:pointer; transition:border-color .2s, background .2s; }
    .drop:hover, .drop.dragover { border-color:#22c55e; background:#f0fdf4; }
    .drop .icon { font-size:26px; }
    .drop .text { font-size:12px; color:#475569; margin-top:6px; }
    .drop .picked { font-size:11px; color:#16a34a; font-weight:700; margin-top:6px; word-break:break-all; }
    .drop input[type=file] { display:none; }

    /* ===== Compact buttons ===== */
    .btn { display:inline-block; padding:6px 12px; border:none; border-radius:6px; color:#fff;
           font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; font-family:inherit; }
    .btn-green { background:#22c55e; width:100%; padding:10px; font-size:13px; font-weight:700; }
    .btn-green:hover { background:#16a34a; }
    .btn-gray { background:#64748b; }
    .btn-gray:hover { background:#475569; }
    .btn-blue  { background:#3b82f6; }
    .btn-blue:hover { background:#2563eb; }

    /* ===== Compact hint ===== */
    .hint { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;
            font-size:11px; color:#64748b; margin-top:14px; padding:10px 12px; line-height:1.7; }

    .actions { margin-top:14px; display:flex; gap:8px; }

    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
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
        <h1>➕ Add / Import Picklist</h1>
        <p class="page-sub">Mag-import ng Excel (.xlsx) o CSV file para maging picklists.</p>

        <?php if ($success): ?><div class="alert ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert bad">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card">
            <form method="POST" action="import_process.php" enctype="multipart/form-data" id="importForm">

                <div class="form-group">
                    <label>📅 Invoice / Pick Date</label>
                    <input type="date" name="import_date" value="<?= htmlspecialchars($date) ?>" required>
                </div>

                <div class="form-group">
                    <label>📁 Excel File (.xlsx or .csv)</label>
                    <div class="drop" id="dropZone"
                         onclick="document.getElementById('fileInput').click()">
                        <div class="icon">📊</div>
                        <div class="text" id="dropText">Click to choose a file<br>
                            <small style="color:#94a3b8">or drag &amp; drop here</small>
                        </div>
                        <div class="picked" id="pickedName"></div>
                        <input type="file" name="csv_file" id="fileInput" accept=".xlsx,.csv" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-green">⬆ Import Picklist</button>
            </form>

            <div class="hint">
                • Duplicate invoices are skipped automatically (never imported twice).<br>
                • Data must start at row <b>3</b> of the sheet — Invoice column <b>P</b>, Order <b>B</b>, Customer <b>Q</b>
                (change letters at the top of <b>import_process.php</b> if your file layout changes).
            </div>

            <div class="actions">
                <a href="dashboard.php" class="btn btn-gray">← Back to Monitoring</a>
                <a href="import_history.php" class="btn btn-blue">📄 View Import History</a>
            </div>
        </div>

    </main>
</div>

<script>
    const dropZone  = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const dropText  = document.getElementById('dropText');
    const picked    = document.getElementById('pickedName');

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            picked.textContent = '✅ ' + fileInput.files[0].name;
            dropText.style.display = 'none';
        }
    });

    ['dragenter', 'dragover'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('dragover'); })
    );
    ['dragleave', 'drop'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('dragover'); })
    );
    dropZone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    document.getElementById('importForm').addEventListener('submit', function () {
        const btn = this.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = '⏳ Importing... please wait';
    });
</script>

</body>
</html>