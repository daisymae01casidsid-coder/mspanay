<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);

/* =====================================================
   🛠️ Siguraduhing may import_history table + import_date
   ===================================================== */
 $conn->query("CREATE TABLE IF NOT EXISTS import_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    total_rows INT NOT NULL DEFAULT 0,
    success_rows INT NOT NULL DEFAULT 0,
    failed_rows INT NOT NULL DEFAULT 0,
    import_date DATE NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by INT NULL,
    INDEX idx_ih_date (import_date),
    INDEX idx_ih_at (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

 $hasImportDate = false;
 $col = $conn->query("SHOW COLUMNS FROM import_history LIKE 'import_date'");
if ($col && $col->num_rows) $hasImportDate = true;

/* =====================================================
   🔧 AUTO-BACKFILL import_history from picklists.imported_file
   Same best-effort auto-repair pattern as dashboard's pick_date / miss_qty.
   Every distinct filename already present in picklists but missing from
   import_history gets a summary row inserted, so the page is never empty
   once you have imported at least one file.
   ===================================================== */
 $hasImportedFile = false;
try {
    $cf = $conn->query("SHOW COLUMNS FROM picklists LIKE 'imported_file'");
    $hasImportedFile = ($cf && $cf->num_rows > 0);
} catch (Throwable $e) { $hasImportedFile = false; }

if ($hasImportedFile) {
    try {
        $res = $conn->query("
            SELECT p.imported_file AS filename,
                   COUNT(*)        AS total,
                   MIN(p.created_at) AS first_at
            FROM picklists p
            WHERE p.imported_file IS NOT NULL
              AND p.imported_file <> ''
              AND p.imported_file NOT IN (SELECT filename FROM import_history)
            GROUP BY p.imported_file
        ");
        $toInsert = [];
        if ($res) {
            while ($m = $res->fetch_assoc()) { $toInsert[] = $m; }
            $res->free();
        }
        if ($toInsert) {
            $ins = $conn->prepare("
                INSERT INTO import_history
                    (filename, total_rows, success_rows, failed_rows, import_date, imported_at, imported_by)
                VALUES (?, ?, ?, 0, ?, ?, NULL)
            ");
            foreach ($toInsert as $m) {
                $fname   = $m['filename'];
                $total   = (int)$m['total'];
                $success = (int)$total;                         // assume all imported rows succeeded
                $firstAt = $m['first_at'];
                $impDate = $firstAt ? date('Y-m-d', strtotime($firstAt)) : null;
                $ins->bind_param("siiss", $fname, $total, $success, $impDate, $firstAt);
                $ins->execute();
            }
            $ins->close();
        }
    } catch (Throwable $e) { /* best effort — page still works */ }
}

/* ===== ✅ Delete success message ===== */
 $justDeleted  = (int)($_GET['deleted'] ?? 0) === 1;
 $deletedRows  = (int)($_GET['rows'] ?? 0);
 $deletedExtra = $deletedRows > 0 ? " — {$deletedRows} picklists removed" : "";

/* ================= FILTERS ================= */
 $validDate = function ($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
};

 $instant_date = $validDate($_GET['date'] ?? '');
 $filter_month = (preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '');
 $date_from    = $validDate($_GET['from'] ?? '');
 $date_to      = $validDate($_GET['to'] ?? '');

 $dateCol = $hasImportDate ? 'h.import_date' : 'h.imported_at';

 $where = []; $params = []; $types = '';

if ($instant_date !== '') {
    if ($hasImportDate) {
        $where[] = "(DATE(h.import_date) = ? OR DATE(h.imported_at) = ?)";
        $params[] = $instant_date; $params[] = $instant_date; $types .= 'ss';
    } else {
        $where[] = "DATE(h.imported_at) = ?";
        $params[] = $instant_date; $types .= 's';
    }
} else {
    if ($filter_month !== '') {
        $where[] = "DATE_FORMAT($dateCol, '%Y-%m') = ?";
        $params[] = $filter_month; $types .= 's';
    }
    if ($date_from !== '') {
        $where[] = "DATE($dateCol) >= ?";
        $params[] = $date_from; $types .= 's';
    }
    if ($date_to !== '') {
        $where[] = "DATE($dateCol) <= ?";
        $params[] = $date_to; $types .= 's';
    }
}

 $sql = "SELECT h.*, u.full_name
        FROM import_history h
        LEFT JOIN users u ON h.imported_by = u.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
 $sql .= " ORDER BY COALESCE(h.import_date, DATE(h.imported_at)) DESC, h.imported_at DESC";

 $stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $history = $stmt->get_result();

/* ===== Summary ===== */
 $total_files = $history->num_rows;
 $files = [];
while ($r = $history->fetch_assoc()) {
    $files[] = $r;
}

/* ===== Stat cards (from current filter view) ===== */
 $sumTotal   = 0;
 $sumSuccess = 0;
 $sumFailed  = 0;
foreach ($files as $r) {
    $sumTotal   += (int)($r['total_rows']   ?? 0);
    $sumSuccess += (int)($r['success_rows'] ?? 0);
    $sumFailed  += (int)($r['failed_rows']  ?? 0);
}

/* ===== Label ===== */
 $range_label = "All Time";
if ($instant_date !== '') {
    $range_label = "📅 " . date('M d, Y', strtotime($instant_date));
} elseif ($filter_month !== '') {
    $range_label = date('F Y', strtotime($filter_month . '-01'));
} elseif ($date_from !== '' || $date_to !== '') {
    $range_label = ($date_from ?: '...') . " → " . ($date_to ?: '...');
}

/* ===== Quick buttons ===== */
 $today     = date('Y-m-d');
 $yesterday = date('Y-m-d', strtotime('-1 day'));
 $thisMonth = date('Y-m');
 $lastMonth = date('Y-m', strtotime('first day of last month'));
 $isAll = ($instant_date === '' && $filter_month === '' && $date_from === '' && $date_to === '');

/* ===== URL builder that preserves current filters ===== */
function ihUrl(array $overrides = []): string {
    $g = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($g[$k]); else $g[$k] = $v;
    }
    foreach ($g as $k => $v) { if ($v === '' || $v === null) unset($g[$k]); }
    return 'import_history.php' . ($g ? '?' . http_build_query($g) : '');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import History</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px — matches dashboard & add_picklist) ===== */
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

    /* ===== Alert / toast ===== */
    .toast { background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:6px;
             padding:8px 14px; margin-bottom:10px; font-size:12px; font-weight:600;
             animation:slideDown .25s ease; }
    @keyframes slideDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }

    /* ===== Stat cards (dashboard .cards / .card) ===== */
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:150px; background:#fff; border-radius:10px; padding:18px 16px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:34px; display:block; line-height:1.2; }
    .card span { font-size:13px; color:#64748b; font-weight:600; }
    .card.total   { border-top-color:#64748b; } .card.total b   { color:#475569; }
    .card.files   { border-top-color:#3b82f6; } .card.files b   { color:#1d4ed8; }
    .card.success { border-top-color:#22c55e; } .card.success b { color:#15803d; }
    .card.failed  { border-top-color:#ef4444; } .card.failed b  { color:#b91c1c; }

    /* ===== Filter bar (dashboard .filters chip style) ===== */
    .filters { margin-bottom:8px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters .date-form { display:flex; align-items:center; gap:4px; background:#f8fafc; border:1px solid #d1d5db;
                          border-radius:12px; padding:2px 4px 2px 9px; }
    .filters .date-form.has-date { background:#dbeafe; border-color:#93c5fd; }
    .filters .date-form input[type=date] { border:none; background:transparent; font-size:11px; font-family:inherit;
                                           color:#374151; padding:1px 2px; outline:none; cursor:pointer; width:118px; }
    .filters .date-form.has-date input[type=date] { color:#1e40af; font-weight:700; }
    .filters .date-form .clear { font-size:10px; color:#991b1b; text-decoration:none; padding:0 5px; font-weight:700; }
    .filters a, .filters button.chip { padding:3px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; cursor:pointer; font-family:inherit; }
    .filters a:hover, .filters button.chip:hover { background:#f3f4f6; }
    .filters a.on { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }

    /* ===== Panel / table (dashboard .table-wrap) ===== */
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:600px; }
    th, td { padding:5px 8px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    td.empty { text-align:center; color:#9ca3af; padding:25px; }
    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.success { background:#dcfce7; color:#166534; }
    .badge.failed  { background:#fee2e2; color:#991b1b; }
    .badge.total   { background:#dbeafe; color:#1e40af; }

    /* ===== Buttons (dashboard flat .btn) ===== */
    .btn { padding:2px 8px; border:none; border-radius:3px; color:#fff; font-size:10px; font-weight:700;
           cursor:pointer; text-decoration:none; font-family:inherit; transition:background .15s, transform .1s; }
    .btn:active { transform:scale(.97); }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .btn-red   { background:#ef4444; } .btn-red:hover   { background:#dc2626; }
    .btn-grey  { background:#64748b; } .btn-grey:hover  { background:#475569; }
    .btn-lg    { padding:5px 12px; font-size:11px; border-radius:4px; }
    .inline-form { display:inline-block; margin-left:4px; }

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
        <h1>📄 Import History
            <?php if ($range_label !== 'All Time'): ?>
                <span style="font-size:11px;color:#1e40af;background:#dbeafe;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                    <?= htmlspecialchars($range_label) ?>
                </span>
            <?php endif; ?>
        </h1>

        <?php if ($justDeleted): ?>
            <div class="toast">🗑 Deleted successfully<?= htmlspecialchars($deletedExtra) ?>.</div>
        <?php endif; ?>

        <div class="cards">
            <div class="card total"><b><?= $total_files ?></b><span>Files</span></div>
            <div class="card files"><b><?= $sumTotal ?></b><span>Picklists</span></div>
            <div class="card success"><b><?= $sumSuccess ?></b><span>Successful</span></div>
            <div class="card failed"><b><?= $sumFailed ?></b><span>Failed</span></div>
        </div>

        <!-- ================= FILTER BAR ================= -->
        <div class="filters">
            <form method="GET" action="import_history.php" class="date-form <?= $instant_date !== '' ? 'has-date' : '' ?>"
                  style="border:none;background:transparent;padding:0;">
                <span style="font-size:11px;">⚡</span>
                <input type="date" name="date" value="<?= htmlspecialchars($instant_date) ?>"
                       onchange="this.form.submit()" title="Instant date filter">
                <?php if ($instant_date !== ''): ?>
                    <a href="<?= ihUrl(['date' => null]) ?>" class="clear" title="Clear date">✕</a>
                <?php endif; ?>
            </form>

            <a href="<?= ihUrl(['date' => null, 'month' => null, 'from' => null, 'to' => null]) ?>"
               class="<?= $isAll ? 'on' : '' ?>">All</a>
            <a href="<?= ihUrl(['date' => $today, 'month' => null, 'from' => null, 'to' => null]) ?>"
               class="<?= $instant_date === $today ? 'on' : '' ?>">Today</a>
            <a href="<?= ihUrl(['date' => $yesterday, 'month' => null, 'from' => null, 'to' => null]) ?>"
               class="<?= $instant_date === $yesterday ? 'on' : '' ?>">Yesterday</a>
            <a href="<?= ihUrl(['date' => null, 'month' => $thisMonth, 'from' => null, 'to' => null]) ?>"
               class="<?= ($filter_month === $thisMonth && $instant_date === '') ? 'on' : '' ?>">This Month</a>
            <a href="<?= ihUrl(['date' => null, 'month' => $lastMonth, 'from' => null, 'to' => null]) ?>"
               class="<?= ($filter_month === $lastMonth && $instant_date === '') ? 'on' : '' ?>">Last Month</a>

            <span class="pp">📄 <?= $total_files ?> file<?= $total_files === 1 ? '' : 's' ?> shown</span>
        </div>

        <!-- ================= TABLE ================= -->
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>File Name</th>
                    <th>Date &amp; Time</th>
                    <th>Rows</th>
                    <th>Imported By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($total_files > 0): ?>
                <?php foreach ($files as $row):
                    $viewDate = !empty($row['import_date']) ? $row['import_date'] : date('Y-m-d', strtotime($row['imported_at']));
                    $rTotal   = (int)($row['total_rows']   ?? 0);
                    $rSuccess = (int)($row['success_rows'] ?? 0);
                    $rFailed  = (int)($row['failed_rows']  ?? 0);
                ?>
                <tr>
                    <td>📄 <?= htmlspecialchars($row['filename']) ?></td>
                    <td><?= date('m/d/y g:i A', strtotime($row['imported_at'])) ?></td>
                    <td>
                        <span class="badge total"><?= $rTotal ?></span>
                        <?php if ($rSuccess): ?><span class="badge success">✓ <?= $rSuccess ?></span><?php endif; ?>
                        <?php if ($rFailed): ?><span class="badge failed">✕ <?= $rFailed ?></span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['full_name'] ?? '') ?: '—' ?></td>
                    <td>
                        <a href="dashboard.php?file=<?= urlencode($row['filename']) ?>&date=<?= urlencode($viewDate) ?>"
                           class="btn btn-blue">📋 View (<?= $rSuccess ?>)</a>
                        <form method="POST" action="delete_history.php" class="inline-form"
                              onsubmit="return confirm('⚠ Delete &quot;<?= htmlspecialchars($row['filename']) ?>&quot;?\n\nThis will ALSO delete all picklists imported from this file!');">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-red">🗑 Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="empty">
                        <?php if (!$isAll): ?>
                            📅 No imports found for "<b><?= htmlspecialchars($range_label) ?></b>".<br>
                            <small>Try clicking <b>All</b> or pick a different date.</small>
                        <?php else: ?>
                            No files imported yet.<br>
                            <small>Go to <b>Add / Import</b> to upload your first picklist file.</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

    </main>
</div>

<script>
    /* Auto-dismiss the success toast after 4s, like the other pages */
    const toast = document.querySelector('.toast');
    if (toast) setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .5s'; setTimeout(() => toast.remove(), 500); }, 4000);
</script>

</body>
</html>