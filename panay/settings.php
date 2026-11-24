<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$current_page = basename($_SERVER['PHP_SELF']);
$msg      = '';
$msg_type = 'success';

/* ==========================================================
   ⚙️ Auto-create settings table (best-effort)
   ========================================================== */
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ==========================================================
   🗂️ Defaults (used when a key is missing in DB)
   ========================================================== */
$defaults = [
    'import_invoice_col'  => 'P',
    'import_order_col'    => 'B',
    'import_customer_col' => 'Q',
    'import_start_row'    => '3',
    'default_status'      => 'Pending',
    'default_warehouse'   => '',
    'status_labels'       => 'Pending,In Progress,Completed,Cancelled',
    'per_page_default'    => '25',
    'company_name'        => 'MSPANAY',
];

/* ==========================================================
   💾 Save settings
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $keys = array_keys($defaults);
    $saved = 0;

    $stmt = $conn->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    if ($stmt) {
        foreach ($keys as $k) {
            $raw = $_POST[$k] ?? $defaults[$k];
            // trim text fields; keep warehouse as empty if blank
            if (is_string($raw)) { $raw = trim($raw); }
            if ($raw === '' && in_array($k, ['import_invoice_col','import_order_col','import_customer_col',
                                              'import_start_row','default_status','status_labels',
                                              'per_page_default','company_name'], true)) {
                $raw = $defaults[$k];
            }
            // numeric sanity for numeric fields
            if (in_array($k, ['import_start_row','per_page_default'], true)) {
                if (!ctype_digit((string)$raw)) { $raw = $defaults[$k]; }
            }
            $val = (string)$raw;
            $stmt->bind_param('ss', $k, $val);
            if ($stmt->execute()) { $saved++; }
        }
        $stmt->close();
        $msg      = "✅ Settings saved successfully ($saved keys updated).";
        $msg_type = 'success';
    } else {
        $msg      = "❌ Failed to prepare statement: " . htmlspecialchars($conn->error);
        $msg_type = 'error';
    }
}

/* ==========================================================
   🧪 Test DB connection button
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_db'])) {
    if ($conn && $conn->ping()) {
        $msg      = "✅ Connection OK — database is reachable.";
        $msg_type = 'success';
    } else {
        $msg      = "❌ Connection FAILED — " . htmlspecialchars($conn->error);
        $msg_type = 'error';
    }
}

/* ==========================================================
   📖 Load all settings into $settings (with defaults)
   ========================================================== */
$settings = $defaults;
$res = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $k = $row['setting_key'];
        $v = $row['setting_value'];
        if (array_key_exists($k, $defaults)) {
            $settings[$k] = ($v === null) ? $defaults[$k] : $v;
        }
    }
    $res->free();
}

/* Status label options parsed from settings */
$statusOptions = array_filter(array_map('trim', explode(',', $settings['status_labels'])));
if (empty($statusOptions)) {
    $statusOptions = ['Pending', 'In Progress', 'Completed', 'Cancelled'];
}

/* helper for output */
function val($k) {
    global $settings;
    return htmlspecialchars($settings[$k] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>⚙️ Settings — MSPANAY</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px) ===== */
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

    .main-content { margin-left:var(--sidebar-w); flex:1; padding:12px 14px; transition:margin-left .05s; }
    h1 { font-size:16px; margin-bottom:12px; }

    .alert { padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:12px; }
    .alert.success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .alert.error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

    .card { background:#fff; border-radius:6px; padding:16px; box-shadow:0 1px 4px rgba(0,0,0,.07);
            max-width:640px; margin-bottom:14px; }
    .section-title { font-size:13px; font-weight:700; margin:0 0 10px; padding-bottom:6px;
                     border-bottom:2px solid #e5e7eb; color:#1f2937; }
    .section-desc  { font-size:11px; color:#64748b; margin-bottom:12px; line-height:1.5; }
    .field-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .field { margin-bottom:10px; }
    label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b;
            margin-bottom:4px; letter-spacing:.3px; }
    label small { text-transform:none; letter-spacing:0; font-weight:400; }
    input[type=text], input[type=number], select { width:100%; padding:7px 10px; background:#f8fafc;
            border:1px solid #d1d5db; border-radius:6px; font-size:12px; font-family:inherit; color:#1f2937; }
    input:focus, select:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; background:#fff; }

    .btn { padding:8px 16px; border:none; border-radius:6px; color:#fff; font-size:12px; font-weight:700;
           cursor:pointer; font-family:inherit; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-block { display:block; width:100%; margin-top:14px; padding:10px; font-size:13px; }

    .hint { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:11px; color:#64748b;
            padding:10px 12px; line-height:1.7; margin-top:14px; }
    .test-row { display:flex; gap:8px; align-items:center; margin-top:10px; }
    .test-row .btn { padding:7px 14px; }

    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
        .field-grid { grid-template-columns:1fr; }
        .card { max-width:100%; }
    }
</style>
</head>
<body>
<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">📦 <?= htmlspecialchars($settings['company_name'] ?? 'MSPANAY') ?></div>
        <div class="sidebar-user">
            👤 <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?><br>
            <small><?= htmlspecialchars($_SESSION['role'] ?? '') ?></small>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"      class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">📊 Monitoring</a>
            <a href="add_picklist.php"   class="<?= $current_page === 'add_picklist.php' ? 'active' : '' ?>">➕ Add / Import</a>
            <a href="import_history.php" class="<?= $current_page === 'import_history.php' ? 'active' : '' ?>">📄 History</a>
            <a href="retailers.php"      class="<?= $current_page === 'retailers.php' ? 'active' : '' ?>">🏬 Retailers</a>
            <a href="staff.php"          class="<?= $current_page === 'staff.php' ? 'active' : '' ?>">👥 Staff</a>
            <a href="reports.php"        class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">📈 Reports</a>
            <a href="performance.php"    class="<?= $current_page === 'performance.php' ? 'active' : '' ?>">🏆 Performance</a>
            <a href="miss_tracking.php"  class="<?= $current_page === 'miss_tracking.php' ? 'active' : '' ?>">❌ Miss Tracking</a>
            <a href="users.php"          class="<?= $current_page === 'users.php' ? 'active' : '' ?>">👤 Users</a>
            <a href="settings.php"       class="<?= $current_page === 'settings.php' ? 'active' : '' ?>">⚙️ Settings</a>
        </nav>
        <a href="logout.php" class="sidebar-logout">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <h1>⚙️ Settings</h1>

        <?php if ($msg !== ''): ?>
            <div class="alert <?= htmlspecialchars($msg_type) ?>"><?= $msg ?></div>
        <?php endif; ?>

        <form method="post" action="settings.php" autocomplete="off">
            <input type="hidden" name="save_settings" value="1">

            <!-- ============ Section 1: Import Column Mapping ============ -->
            <div class="card">
                <div class="section-title">📁 Import Column Mapping</div>
                <div class="section-desc">
                    Map which Excel columns contain your data. Use column letters (A, B, C... P, Q, etc.)
                </div>
                <div class="field-grid">
                    <div class="field">
                        <label for="import_invoice_col">Invoice # Column <small>(letter)</small></label>
                        <input type="text" id="import_invoice_col" name="import_invoice_col"
                               maxlength="3" value="<?= val('import_invoice_col') ?>"
                               placeholder="P" style="text-transform:uppercase;">
                    </div>
                    <div class="field">
                        <label for="import_order_col">Order # Column <small>(letter)</small></label>
                        <input type="text" id="import_order_col" name="import_order_col"
                               maxlength="3" value="<?= val('import_order_col') ?>"
                               placeholder="B" style="text-transform:uppercase;">
                    </div>
                    <div class="field">
                        <label for="import_customer_col">Customer Name Column <small>(letter)</small></label>
                        <input type="text" id="import_customer_col" name="import_customer_col"
                               maxlength="3" value="<?= val('import_customer_col') ?>"
                               placeholder="Q" style="text-transform:uppercase;">
                    </div>
                    <div class="field">
                        <label for="import_start_row">Data Start Row <small>(number)</small></label>
                        <input type="number" id="import_start_row" name="import_start_row"
                               min="1" max="100" value="<?= val('import_start_row') ?>"
                               placeholder="3">
                    </div>
                </div>
                <div class="hint">
                    ℹ️ <b>How it works:</b> When importing an Excel file, the system reads the
                    <b>Invoice #</b> from the column letter you set (e.g. <code>P</code>),
                    the <b>Order #</b> from <code>B</code>, and the <b>Customer Name</b>
                    from <code>Q</code>. Data starts reading from the row number you specify
                    (row 1 is the title, row 2 is usually the header, row 3 is the first data row).
                </div>
            </div>

            <!-- ============ Section 2: Default Values ============ -->
            <div class="card">
                <div class="section-title">📋 Default Values</div>
                <div class="section-desc">
                    These defaults are applied when creating or importing new picklists.
                </div>
                <div class="field-grid">
                    <div class="field">
                        <label for="default_status">Default Status</label>
                        <select id="default_status" name="default_status">
                            <?php foreach ($statusOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"
                                    <?= ($settings['default_status'] === $opt) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="default_warehouse">Default Warehouse</label>
                        <input type="text" id="default_warehouse" name="default_warehouse"
                               maxlength="120" value="<?= val('default_warehouse') ?>"
                               placeholder="(optional)">
                    </div>
                    <div class="field">
                        <label for="per_page_default">Default Rows Per Page</label>
                        <input type="number" id="per_page_default" name="per_page_default"
                               min="5" max="500" value="<?= val('per_page_default') ?>"
                               placeholder="25">
                    </div>
                </div>
            </div>

            <!-- ============ Section 3: Status Labels ============ -->
            <div class="card">
                <div class="section-title">🏷️ Status Labels</div>
                <div class="section-desc">
                    Comma-separated list of statuses used in the system. These appear in the
                    status dropdowns and filters across the dashboard.
                </div>
                <div class="field">
                    <label for="status_labels">Status Labels <small>(comma-separated)</small></label>
                    <input type="text" id="status_labels" name="status_labels"
                           value="<?= val('status_labels') ?>"
                           placeholder="Pending,In Progress,Completed,Cancelled"
                           style="width:100%;">
                </div>
                <div class="hint">
                    ⚠️ <b>Caution:</b> Changing labels affects status filters and reports.
                    Avoid removing labels that are already assigned to existing picklists —
                    they will appear unfiltered until re-assigned.
                </div>
            </div>

            <!-- ============ Section 4: Company ============ -->
            <div class="card">
                <div class="section-title">🏢 Company</div>
                <div class="section-desc">
                    The name shown in the sidebar brand (top-left of every page).
                </div>
                <div class="field">
                    <label for="company_name">Company Name</label>
                    <input type="text" id="company_name" name="company_name"
                           maxlength="60" value="<?= val('company_name') ?>"
                           placeholder="MSPANAY">
                </div>
            </div>

            <!-- ============ Test DB ============ -->
            <div class="card">
                <div class="section-title">🔌 Database Connection</div>
                <div class="section-desc">
                    Verify the connection to the MySQL database used by MSPANAY.
                </div>
                <div class="test-row">
                    <form method="post" action="settings.php" style="margin:0;padding:0;display:inline;">
                        <input type="hidden" name="test_db" value="1">
                        <button type="submit" class="btn btn-blue">🧪 Test Connection</button>
                    </form>
                </div>
            </div>

            <!-- ============ Save Button ============ -->
            <button type="submit" class="btn btn-green btn-block">💾 Save Settings</button>
        </form>

    </main>
</div>

<script>
    const alertBox = document.querySelector('.alert');
    if (alertBox) setTimeout(() => {
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.remove(), 500);
    }, 4000);
</script>
</body>
</html>