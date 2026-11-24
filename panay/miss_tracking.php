<?php
/* ==========================================================
   ❌ MSPANAY — Miss Tracking
   Lists every picklist where miss_qty > 0 (configurable via ?min)
   Design is 1:1 with dashboard.php
   ========================================================== */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);

/* ===== 🧮 Helper: format pick time ===== */
function fmtTime($mins) {
    if ($mins === null || $mins == 0) return '—';
    $m = round($mins);
    if ($m < 60) return $m . 'm';
    return floor($m / 60) . 'h ' . ($m % 60) . 'm';
}

/* ===== 🔗 Helper: build URL preserving current filters ===== */
function urlWith(array $overrides = []): string {
    $g = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($g[$k]); else $g[$k] = $v;
    }
    foreach ($g as $k => $v) { if ($v === '' || $v === null) unset($g[$k]); }
    return 'miss_tracking.php' . ($g ? '?' . http_build_query($g) : '');
}

/* ===== 📅 Validate date filter (Y-m-d) ===== */
 $date_filter = trim($_GET['date'] ?? '');
 $dtChk = DateTime::createFromFormat('Y-m-d', $date_filter);
 $date_filter = ($dtChk && $dtChk->format('Y-m-d') === $date_filter) ? $date_filter : '';

/* ===== 🏬 Retailer text filter ===== */
 $retailer_filter = trim($_GET['retailer'] ?? '');

/* ===== #️⃣ Minimum miss qty (default 1 ⇒ miss_qty > 0) ===== */
 $min_miss = max(1, (int)($_GET['min'] ?? 1));

 $today     = date('Y-m-d');
 $yesterday = date('Y-m-d', strtotime('-1 day'));

/* ==========================================================
   🧱 Build the dynamic WHERE clause used by stats + main table
   (date AND retailer filters applied here)
   ========================================================== */
 $whereParts = [];
 $params     = [];
 $types      = '';

 $whereParts[] = 'COALESCE(p.miss_qty, 0) >= ?';
 $params[]     = $min_miss;
 $types       .= 'i';

 if ($date_filter !== '') {
    $whereParts[] = 'DATE(p.created_at) = ?';
    $params[]     = $date_filter;
    $types       .= 's';
 }
 if ($retailer_filter !== '') {
    $whereParts[] = 'p.customer_name LIKE ?';
    $params[]     = '%' . $retailer_filter . '%';
    $types       .= 's';
 }
 $whereSql = implode(' AND ', $whereParts);

/* ==========================================================
   📊 Summary WHERE — date filter only (no retailer per spec)
   ========================================================== */
 $sumParts = ['COALESCE(miss_qty, 0) > 0'];
 $sumParams = []; $sumTypes = '';
 if ($date_filter !== '') {
    $sumParts[]  = 'DATE(created_at) = ?';
    $sumParams[] = $date_filter;
    $sumTypes   .= 's';
 }
 $sumWhereSql = implode(' AND ', $sumParts);

/* ==========================================================
   📈 Overall stat cards
   ========================================================== */
 $stats = ['affected_picklists' => 0, 'total_misses' => 0,
           'avg_misses' => 0, 'worst_miss' => 0];
 try {
    $sql = "SELECT COUNT(*) AS affected_picklists,
                   SUM(miss_qty) AS total_misses,
                   AVG(miss_qty) AS avg_misses,
                   MAX(miss_qty) AS worst_miss
            FROM picklists p
            WHERE " . $whereSql;
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        $stats['affected_picklists'] = (int)$res['affected_picklists'];
        $stats['total_misses']       = (int)$res['total_misses'];
        $stats['avg_misses']         = $res['avg_misses'] !== null ? round((float)$res['avg_misses'], 1) : 0;
        $stats['worst_miss']         = (int)$res['worst_miss'];
    }
    $stmt->close();
 } catch (Throwable $e) { /* leave defaults */ }

/* ==========================================================
   📋 Main table — picklists with misses (LIMIT 200)
   ========================================================== */
 $picklists = null;
 try {
    $sql = "SELECT p.picklist_no, p.customer_name, p.picker_name, p.checker_name,
                   p.miss_qty, p.status, p.created_at,
                   p.start_time, p.end_time,
                   TIMESTAMPDIFF(MINUTE, p.start_time, p.end_time) AS pick_minutes
            FROM picklists p
            WHERE " . $whereSql . "
            ORDER BY p.miss_qty DESC, p.created_at DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $picklists = $stmt->get_result();
 } catch (Throwable $e) { $picklists = null; }

/* ==========================================================
   🏪 Summary by retailer (top 10)
   ========================================================== */
 $by_retailer = [];
 try {
    $sql = "SELECT customer_name,
                   COUNT(*) AS picklist_count,
                   SUM(miss_qty) AS total_misses,
                   AVG(miss_qty) AS avg_misses
            FROM picklists
            WHERE " . $sumWhereSql . "
            GROUP BY customer_name
            ORDER BY total_misses DESC
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    if ($sumParams) $stmt->bind_param($sumTypes, ...$sumParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $by_retailer[] = $r;
    $stmt->close();
 } catch (Throwable $e) {}

/* ==========================================================
   👷 Summary by picker (top 10)
   ========================================================== */
 $by_picker = [];
 try {
    $pickerWhere = "COALESCE(miss_qty, 0) > 0 AND picker_name IS NOT NULL AND picker_name <> ''";
    if ($date_filter !== '') {
        $pickerWhere .= " AND DATE(created_at) = ?";
    }
    $sql = "SELECT picker_name,
                   COUNT(*) AS picklist_count,
                   SUM(miss_qty) AS total_misses
            FROM picklists
            WHERE " . $pickerWhere . "
            GROUP BY picker_name
            ORDER BY total_misses DESC
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    if ($sumParams) $stmt->bind_param($sumTypes, ...$sumParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $by_picker[] = $r;
    $stmt->close();
 } catch (Throwable $e) {}

/* Whether any filter is currently active */
 $no_filters = ($date_filter === '' && $retailer_filter === '' && $min_miss === 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>❌ Miss Tracking — MSPANAY</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px) ===== */
    .sidebar { width:var(--sidebar-w); background:#1e293b; color:#cbd5e1;
               display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; overflow-y:auto; }
    .sidebar-brand { font-size:15px; font-weight:700; color:#fff; padding:12px; border-bottom:1px solid #334155; }
    .sidebar-user  { padding:8px 12px; background:#0f172a; font-size:11px; line-height:1.5; }
    .sidebar-user small { color:#94a3b8; }
    .sidebar-nav { flex:1; padding-top:4px; }
    .sidebar-nav a { display:block; padding:8px 12px; color:#cbd5e1; text-decoration:none;
                     font-size:12px; border-left:3px solid transparent; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background:#334155; color:#fff; }
    .sidebar-nav a.active { border-left-color:#22c55e; }
    .sidebar-logout { display:block; padding:10px 12px; color:#f87171; text-decoration:none;
                      font-size:12px; border-top:1px solid #334155; }
    .sidebar-logout:hover { background:#7f1d1d; color:#fff; }

    /* ===== Main content ===== */
    .main-content { margin-left:var(--sidebar-w); flex:1; padding:12px 14px; }
    h1 { font-size:16px; margin-bottom:12px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .range-badge { font-size:11px; color:#1e40af; background:#dbeafe;
                   padding:2px 10px; border-radius:10px; font-weight:600; }

    /* ===== Stat cards ===== */
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:150px; background:#fff; border-radius:10px; padding:18px 16px;
            text-align:center; box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:34px; display:block; line-height:1.2; }
    .card span { font-size:13px; color:#64748b; font-weight:600; }
    .card.affected { border-top-color:#ef4444; } .card.affected b { color:#b91c1c; }
    .card.total    { border-top-color:#f59e0b; } .card.total    b { color:#b45309; }
    .card.avg      { border-top-color:#3b82f6; } .card.avg      b { color:#1d4ed8; }
    .card.worst    { border-top-color:#7c2d12; } .card.worst    b { color:#991b1b; }

    /* ===== Filter bar ===== */
    .filters { margin-bottom:10px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters a { padding:3px 10px; background:#f8fafc; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; }
    .filters a:hover { background:#f3f4f6; }
    .filters a.on, .filters a.active { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters input[type=text],
    .filters input[type=number],
    .filters input[type=date] {
        padding:3px 8px; border:1px solid #d1d5db; border-radius:12px; font-size:11px;
        font-family:inherit; color:#374151; outline:none; background:#fff; cursor:pointer;
    }
    .filters input[type=text]:focus,
    .filters input[type=number]:focus,
    .filters input[type=date]:focus { border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; }
    .filters .date-form { display:inline-flex; align-items:center; gap:2px; background:#fff;
                          border:1px solid #d1d5db; border-radius:12px; padding:2px 4px 2px 9px; }
    .filters .date-form .cal-ico { font-size:11px; }
    .filters .date-form input[type=date] { border:none; background:transparent; padding:1px 2px;
                                           width:120px; border-radius:0; cursor:pointer; }
    .filters .date-form.has-date { background:#dbeafe; border-color:#93c5fd; }
    .filters .date-form.has-date input[type=date] { color:#1e40af; font-weight:700; }
    .filters .date-form .clear { font-size:10px; color:#991b1b; text-decoration:none;
                                 padding:0 5px; font-weight:700; }
    .filters .date-form .clear:hover { color:#7f1d1d; }
    .filters .text-form { display:inline-flex; align-items:center; gap:3px; margin-left:auto; }
    .filters .text-form input[type=text]   { width:140px; }
    .filters .text-form input[type=number] { width:64px; text-align:center; }
    .filters .text-form button { padding:3px 9px; border:1px solid #1e293b; border-radius:12px;
                                 background:#1e293b; color:#fff; font-size:11px; font-weight:700;
                                 cursor:pointer; }
    .filters .text-form button:hover { background:#334155; }
    .filters .lbl { font-size:11px; color:#64748b; font-weight:600; }

    /* ===== Main table ===== */
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07);
                  overflow-x:auto; margin-bottom:14px; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:700px; }
    th, td { padding:5px 8px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; color:#fff; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    .num { text-align:center; }
    .dim { color:#9ca3af; }

    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.miss       { background:#fee2e2; color:#991b1b; }
    .badge.pending    { background:#fef3c7; color:#92400e; }
    .badge.inprogress { background:#dbeafe; color:#1e40af; }
    .badge.completed  { background:#dcfce7; color:#166534; }
    .badge.cancelled  { background:#fee2e2; color:#991b1b; }

    /* ===== Two-column summary panels ===== */
    .section-title { font-size:13px; font-weight:700; margin:16px 0 8px; }
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .panel { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
    .panel-head { padding:6px 12px; color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; }
    .panel-head.g-red    { background:#b91c1c; }
    .panel-head.g-orange { background:#c2410c; }
    .panel table { min-width:0; }
    .panel th, .panel td { font-size:11px; }

    .empty { text-align:center; padding:25px; color:#9ca3af; }

    @media (max-width:900px) { .two-col { grid-template-columns:1fr; } }
    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
        .filters .text-form { margin-left:0; }
    }
</style>
</head>
<body>
<div class="layout">

    <aside class="sidebar" id="sidebar">
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
        <h1>❌ Miss Tracking
            <?php if ($date_filter !== ''): ?>
                <span class="range-badge">📅 <?= date('M d, Y', strtotime($date_filter)) ?></span>
            <?php endif; ?>
            <?php if ($retailer_filter !== ''): ?>
                <span class="range-badge" style="color:#7c2d12;background:#fed7aa;">🏬 <?= htmlspecialchars($retailer_filter) ?></span>
            <?php endif; ?>
            <?php if ($min_miss > 1): ?>
                <span class="range-badge" style="color:#b45309;background:#fef3c7;"># ≥ <?= (int)$min_miss ?></span>
            <?php endif; ?>
        </h1>

        <div class="cards">
            <div class="card affected"><b><?= (int)$stats['affected_picklists'] ?></b><span>Affected Picklists</span></div>
            <div class="card total"><b><?= (int)$stats['total_misses'] ?></b><span>Total Misses</span></div>
            <div class="card avg"><b><?= htmlspecialchars(number_format($stats['avg_misses'], 1)) ?></b><span>Avg Misses / Picklist</span></div>
            <div class="card worst"><b><?= (int)$stats['worst_miss'] ?></b><span>Worst Single Picklist</span></div>
        </div>

        <div class="filters">
            <!-- ===== 📅 Calendar (instant date filter) ===== -->
            <form method="GET" action="miss_tracking.php" class="date-form <?= $date_filter !== '' ? 'has-date' : '' ?>">
                <?php if ($retailer_filter !== ''): ?>
                    <input type="hidden" name="retailer" value="<?= htmlspecialchars($retailer_filter) ?>">
                <?php endif; ?>
                <?php if ($min_miss > 1): ?>
                    <input type="hidden" name="min" value="<?= (int)$min_miss ?>">
                <?php endif; ?>
                <span class="cal-ico">📅</span>
                <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>"
                       onchange="this.form.submit()" title="Filter by date">
                <?php if ($date_filter !== ''): ?>
                    <a href="<?= urlWith(['date' => null]) ?>" class="clear" title="Clear date">✕</a>
                <?php endif; ?>
            </form>

            <a href="<?= urlWith(['date' => null, 'retailer' => null, 'min' => null]) ?>"
               class="<?= $no_filters ? 'on' : '' ?>">All</a>
            <a href="<?= urlWith(['date' => $today]) ?>"
               class="<?= $date_filter === $today ? 'on' : '' ?>">Today</a>
            <a href="<?= urlWith(['date' => $yesterday]) ?>"
               class="<?= $date_filter === $yesterday ? 'on' : '' ?>">Yesterday</a>

            <form method="GET" action="miss_tracking.php" class="text-form">
                <?php if ($date_filter !== ''): ?>
                    <input type="hidden" name="date" value="<?= htmlspecialchars($date_filter) ?>">
                <?php endif; ?>
                <span class="lbl">Retailer</span>
                <input type="text" name="retailer" value="<?= htmlspecialchars($retailer_filter) ?>"
                       placeholder="e.g. SaveMore" title="Filter by retailer (partial match)">
                <span class="lbl">Min</span>
                <input type="number" name="min" value="<?= (int)$min_miss ?>" min="1" step="1"
                       title="Minimum miss qty to show">
                <button type="submit" title="Apply filters">🔍</button>
            </form>
        </div>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Retailer</th>
                    <th>Picker</th>
                    <th>Checker</th>
                    <th class="num">Miss Qty</th>
                    <th>Status</th>
                    <th class="num">Pick Time</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($picklists && $picklists->num_rows > 0): while ($row = $picklists->fetch_assoc()):
                $status = $row['status'] ?? '';
                $statusCls = strtolower(str_replace(' ', '', $status));
                if (!in_array($statusCls, ['pending','inprogress','completed','cancelled'])) $statusCls = 'pending';
                $retailer = $row['customer_name'] ?? '';
                $picker   = $row['picker_name'] ?? '';
                $checker  = $row['checker_name'] ?? '';
            ?>
                <tr>
                    <td><b><?= htmlspecialchars($row['picklist_no'] ?? '') ?: '—' ?></b></td>
                    <td><?= htmlspecialchars($retailer) !== '' ? htmlspecialchars($retailer) : '<span class="dim">—</span>' ?></td>
                    <td><?= htmlspecialchars($picker) !== '' ? htmlspecialchars($picker) : '<span class="dim">—</span>' ?></td>
                    <td><?= htmlspecialchars($checker) !== '' ? htmlspecialchars($checker) : '<span class="dim">—</span>' ?></td>
                    <td class="num"><span class="badge miss"><?= (int)($row['miss_qty'] ?? 0) ?></span></td>
                    <td><span class="badge <?= $statusCls ?>"><?= htmlspecialchars($status ?: 'Pending') ?></span></td>
                    <td class="num"><?= htmlspecialchars(fmtTime($row['pick_minutes'] ?? null)) ?></td>
                    <td><?= !empty($row['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) : '<span class="dim">—</span>' ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="empty">🎉 No picklists with misses found<?= $no_filters ? '' : ' for the current filters' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="section-title">📋 Miss Summary Breakdown</div>
        <div class="two-col">
            <!-- ===== 🏪 Top Retailers by Misses ===== -->
            <div class="panel">
                <div class="panel-head g-red">🏪 Top Retailers by Misses</div>
                <table>
                    <thead>
                        <tr>
                            <th>Retailer</th>
                            <th class="num">Picklists</th>
                            <th class="num">Total Misses</th>
                            <th class="num">Avg Misses</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($by_retailer): foreach ($by_retailer as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['customer_name'] ?? '') ?: '—' ?></td>
                            <td class="num"><?= (int)$r['picklist_count'] ?></td>
                            <td class="num"><b style="color:#991b1b;"><?= (int)$r['total_misses'] ?></b></td>
                            <td class="num"><?= htmlspecialchars(number_format((float)($r['avg_misses'] ?? 0), 1)) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="empty">No retailer data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ===== 👷 Top Pickers by Misses ===== -->
            <div class="panel">
                <div class="panel-head g-orange">👷 Top Pickers by Misses</div>
                <table>
                    <thead>
                        <tr>
                            <th>Picker</th>
                            <th class="num">Picklists</th>
                            <th class="num">Total Misses</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($by_picker): foreach ($by_picker as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['picker_name'] ?? '') ?: '—' ?></td>
                            <td class="num"><?= (int)$r['picklist_count'] ?></td>
                            <td class="num"><b style="color:#991b1b;"><?= (int)$r['total_misses'] ?></b></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="empty">No picker data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>
</body>
</html>