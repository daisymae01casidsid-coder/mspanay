<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $allowed     = ['Pending', 'In Progress', 'Completed', 'Cancelled'];
 $filter      = $_GET['status'] ?? '';
 $file_filter = trim($_GET['file'] ?? '');

/* ===== 📅 Instant date filter (calendar) ===== */
 $date_filter = trim($_GET['date'] ?? '');
 $dtChk = DateTime::createFromFormat('Y-m-d', $date_filter);
 $date_filter = ($dtChk && $dtChk->format('Y-m-d') === $date_filter) ? $date_filter : '';

/* ==========================================================
   🔒 STEP 1: pick_date column (best-effort; may created_at fallback)
   ========================================================== */
 $pickDateCol = 'p.created_at';
 $hasPickDate = false;

try {
    $colRes = $conn->query("SHOW COLUMNS FROM picklists");
    while ($colRes && ($c = $colRes->fetch_assoc())) {
        if (strtolower($c['Field']) === 'pick_date') { $hasPickDate = true; break; }
    }
} catch (Throwable $e) { $hasPickDate = false; }

if (!$hasPickDate) {
    try {
        $conn->query("ALTER TABLE picklists ADD COLUMN pick_date DATE NULL");
        $hasPickDate = true;
    } catch (Throwable $e) { $hasPickDate = false; }
}

if ($hasPickDate) {
    try {
        $probe = $conn->query("SELECT pick_date FROM picklists LIMIT 1");
        if ($probe) $probe->free();
        $pickDateCol = 'p.pick_date';
    } catch (Throwable $e) { $pickDateCol = 'p.created_at'; }
}

/* ==========================================================
   📄 import_history check
   ========================================================== */
 $hasHistory = false;
try {
    $t = $conn->query("SHOW TABLES LIKE 'import_history'");
    if ($t && $t->num_rows) {
        $c = $conn->query("SHOW COLUMNS FROM import_history LIKE 'import_date'");
        $hasHistory = ($c && $c->num_rows > 0);
    }
} catch (Throwable $e) { $hasHistory = false; }

/* ==========================================================
   ❌ miss_qty column (best-effort auto-add)
   ========================================================== */
 $hasMissCol = false;
try {
    $mc = $conn->query("SHOW COLUMNS FROM picklists LIKE 'miss_qty'");
    $hasMissCol = ($mc && $mc->num_rows > 0);
} catch (Throwable $e) { $hasMissCol = false; }

if (!$hasMissCol) {
    try { $conn->query("ALTER TABLE picklists ADD COLUMN miss_qty INT NULL DEFAULT 0"); } catch (Throwable $e) {}
}

/* Quick dates */
 $today     = date('Y-m-d');
 $yesterday = date('Y-m-d', strtotime('-1 day'));

/* ==========================================================
   👷✅ Staff lists — para sa Name dropdown (Picker / Checker)
   ========================================================== */
 $picker_opts = []; $checker_opts = [];
try {
    $r = $conn->query("SELECT full_name FROM staff_list WHERE role = 'picker' ORDER BY full_name");
    while ($o = $r->fetch_assoc()) $picker_opts[] = $o['full_name'];
} catch (Throwable $e) { $picker_opts = []; }
try {
    $r = $conn->query("SELECT full_name FROM staff_list WHERE role = 'checker' ORDER BY full_name");
    while ($o = $r->fetch_assoc()) $checker_opts[] = $o['full_name'];
} catch (Throwable $e) { $checker_opts = []; }

/* Helper: build URL preserving current filters (pass null to remove a param) */
function urlWith(array $overrides = []): string {
    $g = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($g[$k]); else $g[$k] = $v;
    }
    foreach ($g as $k => $v) { if ($v === '' || $v === null) unset($g[$k]); }
    return 'dashboard.php' . ($g ? '?' . http_build_query($g) : '');
}

function duration($start, $end) {
    if (!$start) return '—';
    $s = strtotime($start);
    $e = $end ? strtotime($end) : time();
    $mins = max(0, floor(($e - $s) / 60));
    if ($mins < 60) return $mins . ' min';
    return floor($mins / 60) . 'h ' . ($mins % 60) . 'm';
}

function roleCells($row, $role, $picker_opts, $checker_opts) {
    $p        = ($role === 'picker');
    $nameFld  = $p ? 'picker_name'   : 'checker_name';
    $startFld = $p ? 'start_time'    : 'checker_start';
    $endFld   = $p ? 'end_time'      : 'checker_end';
    $name     = $p ? ($row['picker_name']  ?? '') : ($row['checker_name']  ?? '');
    if ($name === $row['picklist_no']) $name = '';
    $start    = $p ? ($row['start_time']    ?? null) : ($row['checker_start'] ?? null);
    $end      = $p ? ($row['end_time']      ?? null) : ($row['checker_end']   ?? null);

    /* Badge = status ng activity na ito (hindi ang overall status) */
    if (($row['status'] ?? '') === 'Cancelled') {
        $status = 'Cancelled';
    } elseif ($p) {
        $status = !empty($row['end_time']) ? 'Completed'
                : (!empty($row['start_time']) ? 'In Progress' : 'Pending');
    } else {
        $status = !empty($row['checker_end']) ? 'Completed'
                : (!empty($row['checker_start']) ? 'In Progress'
                : ($row['checker_status'] ?? 'Pending'));
    }
    $inprog   = ($status === 'In Progress');
    $opts     = $p ? $picker_opts : $checker_opts;

    /* 📌 Base date para sa time-only inputs (hindi na kailangan ulitin ang date)
       Picker  : date ng start_time → received_date → created_at
       Checker : date ng checker_start → start_time → received_date → created_at */
    $anchor = '';
    $chain  = $p ? ['start_time', 'received_date', 'created_at']
                 : ['checker_start', 'start_time', 'received_date', 'created_at'];
    foreach ($chain as $dk) {
        if (!empty($row[$dk])) {
            $t = strtotime($row[$dk]);
            if ($t) { $anchor = date('Y-m-d', $t); break; }
        }
    }
    $baseDate = ($anchor !== '') ? $anchor : date('Y-m-d');

    $startDisp = ($start && strtotime($start)) ? date('H:i', strtotime($start)) : '';
    $endDisp   = ($end   && strtotime($end))   ? date('H:i', strtotime($end))   : '';
    ?>
    <td class="mask-cell" title="Click 👁 to hide / click •••• to reveal">
        <span class="mask-dots">••••</span>
        <select class="cell-edit sel" data-id="<?= $row['id'] ?>" data-field="<?= $nameFld ?>">
            <option value="">—</option>
            <?php foreach ($opts as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $o === $name ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
            <?php if ($name !== '' && !in_array($name, $opts)): /* lumang name na wala na sa list */ ?>
                <option value="<?= htmlspecialchars($name) ?>" selected><?= htmlspecialchars($name) ?></option>
            <?php endif; ?>
        </select>
        <button type="button" class="mask-btn" title="Hide / Show name">👁</button>
    </td>
    <?php if ($p): /* Picker Start — date + time (ito ang anchor ng date) */ ?>
    <td><input type="datetime-local" class="cell-edit dtm" data-id="<?= $row['id'] ?>" data-field="<?= $startFld ?>"
               value="<?= ($start && strtotime($start)) ? date('Y-m-d\TH:i', strtotime($start)) : '' ?>"></td>
    <?php else: /* Checker Start — ⏰ time only na */ ?>
    <td><input type="time" class="cell-edit tm" data-id="<?= $row['id'] ?>" data-field="<?= $startFld ?>"
               data-date="<?= $baseDate ?>" value="<?= $startDisp ?>" title="Time only — date auto from start"></td>
    <?php endif; ?>
    <td><input type="time" class="cell-edit tm" data-id="<?= $row['id'] ?>" data-field="<?= $endFld ?>"
               data-date="<?= $baseDate ?>" value="<?= $endDisp ?>" title="Time only — date auto from start"><?= $inprog ? ' <span class="running">●</span>' : '' ?></td>
    <td class="dur"><b><?= duration($start, $end) ?></b></td>
    <td><span class="badge <?= strtolower(str_replace(' ', '', $status)) ?>"><?= htmlspecialchars($status) ?></span></td>
    <td class="act">
        <?php if ($status === 'Pending'): ?>
            <button type="button" class="btn btn-blue act-btn"
                    data-id="<?= $row['id'] ?>" data-role="<?= $role ?>" data-action="start">▶</button>
        <?php elseif ($inprog): ?>
            <button type="button" class="btn btn-green act-btn"
                    data-id="<?= $row['id'] ?>" data-role="<?= $role ?>" data-action="complete">✔</button>
        <?php else: ?><span class="dim">✓</span><?php endif; ?>
    </td>
    <?php
}

/* ===== Base filters (status + file) ===== */
 $whereBase = []; $paramsBase = []; $typesBase = '';
if (in_array($filter, $allowed)) { $whereBase[] = "p.status = ?";        $paramsBase[] = $filter;      $typesBase .= 's'; }
if ($file_filter !== '')         { $whereBase[] = "p.imported_file = ?"; $paramsBase[] = $file_filter; $typesBase .= 's'; }

 $pp       = $_GET['per_page'] ?? '25';
 $per_page = ($pp === 'all') ? 0 : max(10, (int)$pp);
 $page     = max(1, (int)($_GET['page'] ?? 1));

/* ==========================================================
   🔒 STEP 2: queries na may automatic retry (created_at fallback)
   ========================================================== */
 $total_rows  = 0;
 $total_pages = 1;
 $picklists   = null;
 $stats = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0, 'Cancelled' => 0];
 $usedCol = '';

 $attemptCols = ($pickDateCol !== 'p.created_at') ? [$pickDateCol, 'p.created_at'] : ['p.created_at'];

foreach ($attemptCols as $col) {
    $useDate = ($date_filter !== '');

    $dateSql = ''; $dateBind = [];
    if ($useDate) {
        if ($col === 'p.pick_date') {
            $dateSql = "(DATE(COALESCE(p.pick_date, p.created_at)) = ? OR DATE(p.created_at) = ?";
            $dateBind[] = $date_filter;
            $dateBind[] = $date_filter;
        } else {
            $dateSql = "(DATE(p.created_at) = ?";
            $dateBind[] = $date_filter;
        }
        if ($hasHistory) {
            $dateSql .= " OR p.imported_file IN (SELECT filename FROM import_history WHERE DATE(COALESCE(import_date, DATE(imported_at))) = ?)";
            $dateBind[] = $date_filter;
        }
        $dateSql .= ")";
    }

    $where = $whereBase;
    if ($useDate) $where[] = $dateSql;
    $whereSql = $where ? " WHERE " . implode(' AND ', $where) : "";
    $params = array_merge($paramsBase, $dateBind);
    $types  = $typesBase . str_repeat('s', count($dateBind));

    try {
        /* 1) Count */
        $cstmt = $conn->prepare("SELECT COUNT(*) AS c FROM picklists p" . $whereSql);
        if ($params) $cstmt->bind_param($types, ...$params);
        $cstmt->execute();
        $total_rows = (int)$cstmt->get_result()->fetch_assoc()['c'];

        $total_pages = $per_page ? max(1, ceil($total_rows / $per_page)) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $limitSql = $per_page ? " LIMIT " . (int)$per_page . " OFFSET " . (int)(($page - 1) * $per_page) : "";

        /* 2) Data */
        $dstmt = $conn->prepare("SELECT p.*, u.full_name FROM picklists p LEFT JOIN users u ON p.created_by = u.id"
                     . $whereSql . " ORDER BY p.created_at DESC" . $limitSql);
        if ($params) $dstmt->bind_param($types, ...$params);
        $dstmt->execute();
        $picklists = $dstmt->get_result();

        /* 3) Stats */
        if ($useDate) {
            $sst = $conn->prepare("SELECT status, COUNT(*) AS total FROM picklists p WHERE " . $dateSql . " GROUP BY status");
            $stypes = str_repeat('s', count($dateBind));
            if ($dateBind) $sst->bind_param($stypes, ...$dateBind);
            $sst->execute();
            $sres = $sst->get_result();
        } else {
            $sres = $conn->query("SELECT status, COUNT(*) AS total FROM picklists GROUP BY status");
        }
        while ($r = $sres->fetch_assoc()) $stats[$r['status']] = (int)$r['total'];

        $usedCol = $col;
        break;

    } catch (Throwable $e) {
        $picklists = null;
        $total_rows = 0;
        $stats = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0, 'Cancelled' => 0];
        continue;
    }
}
 $total = array_sum($stats);

function pageUrl($p, $pp) {
    return "?" . http_build_query(array_merge($_GET, ['page' => $p, 'per_page' => $pp]));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Picklist Monitoring</title>
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
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:150px; background:#fff; border-radius:10px; padding:18px 16px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:34px; display:block; line-height:1.2; }
    .card span { font-size:13px; color:#64748b; font-weight:600; }
    .card.pending   { border-top-color:#f59e0b; } .card.pending b   { color:#b45309; }
    .card.progress  { border-top-color:#3b82f6; } .card.progress b  { color:#1d4ed8; }
    .card.completed { border-top-color:#22c55e; } .card.completed b { color:#15803d; }
    .card.cancelled { border-top-color:#ef4444; } .card.cancelled b { color:#b91c1c; }
    .filters { margin-bottom:8px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
    .filters a { padding:3px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; }
    .filters a:hover { background:#f3f4f6; }
    .filters a.active { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters a.file-chip { background:#fef9c3; color:#854d0e; }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }
    .filters .pp a { padding:2px 7px; }
    .filters .pp a.on { background:#22c55e; color:#fff; border-color:#22c55e; }
    .date-form { display:flex; align-items:center; gap:2px; background:#fff; border:1px solid #d1d5db;
                 border-radius:12px; padding:2px 4px 2px 9px; margin-right:4px; }
    .date-form .cal-ico { font-size:11px; }
    .date-form input[type=date] { border:none; background:transparent; font-size:11px; font-family:inherit;
                                  color:#374151; padding:1px 2px; outline:none; cursor:pointer; width:118px; }
    .date-form.has-date { background:#dbeafe; border-color:#93c5fd; }
    .date-form.has-date input[type=date] { color:#1e40af; font-weight:700; }
    .date-form .clear { font-size:10px; color:#991b1b; text-decoration:none; padding:0 5px; font-weight:700; }
    .date-form .clear:hover { color:#7f1d1d; }
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:1420px; table-layout:fixed; }
    th, td { padding:3px 7px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    thead th { font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; position:relative; }
    thead tr.group-row th { text-align:center; font-size:11px; padding:5px; border-bottom:2px solid #0f172a; }
    thead tr.sub-row th { background:#111827; padding:4px 7px; }
    .g-received{background:#475569} .g-qty{background:#9a3412} .g-delivery{background:#a16207}
    .g-picker{background:#15803d}   .g-checker{background:#1d4ed8} .g-miss{background:#b91c1c}
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    .num { text-align:center; }
    .dur { background:#f8fafc; }
    .dim { color:#9ca3af; }
    .act { min-width:44px; text-align:center; }
    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.pending    { background:#fef3c7; color:#92400e; }
    .badge.inprogress { background:#dbeafe; color:#1e40af; }
    .badge.completed  { background:#dcfce7; color:#166534; }
    .badge.cancelled  { background:#fee2e2; color:#991b1b; }
    .running { color:#16a34a; font-weight:700; animation:blink 1.5s infinite; }
    @keyframes blink { 50% { opacity:.3; } }
    .btn { padding:2px 8px; border:none; border-radius:3px; color:#fff; font-size:10px; font-weight:700; cursor:pointer; }
    .btn:disabled { opacity:.6; cursor:default; }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .inline-form { display:inline-block; }
    .pagination { margin-top:8px; display:flex; justify-content:center; align-items:center; gap:4px; flex-wrap:wrap; }
    .pagination a, .pagination span.pg {
        padding:3px 9px; background:#fff; border:1px solid #d1d5db; border-radius:4px;
        font-size:11px; color:#374151; text-decoration:none;
    }
    .pagination a:hover { background:#f3f4f6; }
    .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; font-weight:700; }
    .pagination .info { color:#64748b; font-size:11px; }

    /* ===== ✏️ Editable cells ===== */
    td input.cell-edit, td select.cell-edit {
        border: 1px solid transparent; background: transparent; font: inherit; font-size: 11px;
        padding: 1px 3px; border-radius: 3px; color: #1f2937; outline: none; cursor: pointer;
    }
    td input.cell-edit:hover, td select.cell-edit:hover { border-color: #cbd5e1; background: #fff; }
    td input.cell-edit:focus, td select.cell-edit:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 2px #dbeafe; }
    td input.cell-edit.dirty, td select.cell-edit.dirty { border-color: #f59e0b; background: #fffbeb; }
    td input.cell-edit.saved, td select.cell-edit.saved { border-color: #22c55e; background: #dcfce7; }
    td input.cell-edit.save-err, td select.cell-edit.save-err { border-color: #ef4444; background: #fee2e2; }
    td.num input.cell-edit { width: 46px; text-align: center; }
    input.cell-edit.dt { width: 110px; }
    input.cell-edit.tm { width: 72px; }
    input.cell-edit.dtm { width: 148px; }
    td select.cell-edit { max-width: 140px; }

    /* ===== ↔️ Column resize handle (drag column borders) ===== */
    th .col-resize {
        position:absolute; top:0; right:0; bottom:0; width:5px;
        cursor:col-resize; z-index:5;
        background:transparent;
    }
    th .col-resize:hover,
    th .col-resize.dragging { background:rgba(34,197,94,.6); }

    /* ===== 🔒 Click-to-hide (mask) — Invoice #, Picker & Checker Name ===== */
    .mask-cell { position: relative; white-space: nowrap; }
    .mask-cell .mask-dots {
        display: none; font-weight: 700; letter-spacing: 1px;
        color: #9ca3af; user-select: none; cursor: pointer;
    }
    .mask-cell .mask-text { cursor: pointer; }
    .mask-cell .mask-btn {
        border: none; background: transparent; cursor: pointer;
        font-size: 10px; padding: 0 0 0 3px; vertical-align: middle;
        line-height: 1; opacity: .55;
    }
    .mask-cell .mask-btn:hover { opacity: 1; }
    .mask-cell.hidden .mask-dots { display: inline; }
    .mask-cell.hidden .mask-text,
    .mask-cell.hidden select.cell-edit { display: none !important; }
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
        <h1>📦 Picklist Monitoring
            <?php if ($date_filter !== ''): ?>
                <span style="font-size:11px;color:#1e40af;background:#dbeafe;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                    📅 <?= date('M d, Y', strtotime($date_filter)) ?>
                </span>
            <?php endif; ?>
        </h1>

        <div class="cards">
            <div class="card"><b><?= $total ?></b><span>Total</span></div>
            <div class="card pending"><b><?= $stats['Pending'] ?></b><span>Pending</span></div>
            <div class="card progress"><b><?= $stats['In Progress'] ?></b><span>In Progress</span></div>
            <div class="card completed"><b><?= $stats['Completed'] ?></b><span>Completed</span></div>
            <div class="card cancelled"><b><?= $stats['Cancelled'] ?></b><span>Cancelled</span></div>
        </div>

        <div class="filters">
            <!-- ===== 📅 Calendar (left corner) ===== -->
            <form method="GET" action="dashboard.php" class="date-form <?= $date_filter !== '' ? 'has-date' : '' ?>">
                <?php if (in_array($filter, $allowed)): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
                <?php endif; ?>
                <?php if ($file_filter !== ''): ?>
                    <input type="hidden" name="file" value="<?= htmlspecialchars($file_filter) ?>">
                <?php endif; ?>
                <span class="cal-ico">📅</span>
                <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>"
                       onchange="this.form.submit()" title="Select date">
                <?php if ($date_filter !== ''): ?>
                    <a href="<?= urlWith(['date' => null, 'page' => null]) ?>" class="clear" title="Clear date">✕</a>
                <?php endif; ?>
            </form>

            <a href="<?= urlWith(['status' => null, 'file' => null, 'date' => null, 'page' => null]) ?>"
               class="<?= $filter === '' && $file_filter === '' && $date_filter === '' ? 'active' : '' ?>">All</a>

            <a href="<?= urlWith(['date' => $today, 'page' => null]) ?>"
               class="<?= $date_filter === $today ? 'active' : '' ?>">Today</a>
            <a href="<?= urlWith(['date' => $yesterday, 'page' => null]) ?>"
               class="<?= $date_filter === $yesterday ? 'active' : '' ?>">Yesterday</a>

            <?php foreach ($allowed as $s): ?>
                <a href="<?= urlWith(['status' => $s, 'page' => null]) ?>"
                   class="<?= $filter === $s ? 'active' : '' ?>"><?= $s ?></a>
            <?php endforeach; ?>

            <?php if ($file_filter !== ''): ?>
                <a href="<?= urlWith(['file' => null, 'page' => null]) ?>" class="active file-chip">
                    📄 <?= htmlspecialchars($file_filter) ?> ✕
                </a>
            <?php endif; ?>

            <span class="pp">Rows:
                <?php foreach (['25','50','100','all'] as $opt): ?>
                    <a href="<?= pageUrl(1, $opt) ?>" class="<?= $pp === $opt ? 'on' : '' ?>"><?= $opt === 'all' ? 'All' : $opt ?></a>
                <?php endforeach; ?>
            </span>
        </div>

        <div class="table-wrap">
        <table id="pickTable">
            <colgroup>
                <col data-col="invoice"><col data-col="retailer">
                <col data-col="recv-date"><col data-col="recv-time">
                <col data-col="cs"><col data-col="sw"><col data-col="it">
                <col data-col="delivery">
                <col data-col="p-name"><col data-col="p-start"><col data-col="p-finish"><col data-col="p-dur"><col data-col="p-stat"><col data-col="p-act">
                <col data-col="c-name"><col data-col="c-start"><col data-col="c-finish"><col data-col="c-dur"><col data-col="c-stat"><col data-col="c-act">
                <col data-col="miss">
            </colgroup>
            <thead>
                <tr class="group-row">
                    <th rowspan="2" style="background:#111827; vertical-align:bottom;">Invoice #</th>
                    <th rowspan="2" style="background:#111827; vertical-align:bottom;">Retailer</th>
                    <th colspan="2" class="g-received">📋 Picklist Received</th>
                    <th colspan="3" class="g-qty">📦 Picked Qty</th>
                    <th rowspan="2" class="g-delivery" style="vertical-align:bottom;">🚚 Delivery</th>
                    <th colspan="6" class="g-picker">👷 Picker</th>
                    <th colspan="6" class="g-checker">✅ Checker</th>
                    <th rowspan="2" class="g-miss" style="vertical-align:bottom;">❌ Miss</th>
                </tr>
                <tr class="sub-row">
                    <th class="g-received">Date</th><th class="g-received">Time</th>
                    <th class="g-qty">CS</th><th class="g-qty">SW</th><th class="g-qty">IT</th>
                    <th>Name</th><th>Start</th><th>Finish</th><th>Duration</th><th>Status</th><th>Action</th>
                    <th>Name</th><th>Start</th><th>Finish</th><th>Duration</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($picklists && $picklists->num_rows > 0): while ($row = $picklists->fetch_assoc()): ?>
                <tr data-status="<?= htmlspecialchars($row['status'] ?? '') ?>">
                    <td class="mask-cell" title="Click 👁 to hide / click •••• to reveal">
                        <span class="mask-dots">••••</span>
                        <b class="mask-text"><?= htmlspecialchars($row['picklist_no']) ?></b>
                        <button type="button" class="mask-btn" title="Hide / Show invoice">👁</button>
                    </td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? '') ?: '—' ?></td>
                    <td><input type="date" class="cell-edit dt" data-id="<?= $row['id'] ?>" data-field="received_date"
                               value="<?= !empty($row['received_date']) ? date('Y-m-d', strtotime($row['received_date'])) : '' ?>"></td>
                    <td><input type="time" class="cell-edit tm" data-id="<?= $row['id'] ?>" data-field="received_time"
                               value="<?= !empty($row['received_time']) ? date('H:i', strtotime($row['received_time'])) : '' ?>"></td>
                    <td class="num"><input type="number" class="cell-edit" data-id="<?= $row['id'] ?>" data-field="cs_qty"
                               value="<?= (int)($row['cs_qty'] ?? 0) ?>" min="0" step="1"></td>
                    <td class="num"><input type="number" class="cell-edit" data-id="<?= $row['id'] ?>" data-field="sw_qty"
                               value="<?= (int)($row['sw_qty'] ?? 0) ?>" min="0" step="1"></td>
                    <td class="num"><input type="number" class="cell-edit" data-id="<?= $row['id'] ?>" data-field="it_qty"
                               value="<?= (int)($row['it_qty'] ?? 0) ?>" min="0" step="1"></td>
                    <td><input type="date" class="cell-edit dt" data-id="<?= $row['id'] ?>" data-field="delivery_date"
                               value="<?= !empty($row['delivery_date']) ? date('Y-m-d', strtotime($row['delivery_date'])) : '' ?>"></td>
                    <?php roleCells($row, 'picker',  $picker_opts, $checker_opts); ?>
                    <?php roleCells($row, 'checker', $picker_opts, $checker_opts); ?>
                    <td class="num"><input type="number" class="cell-edit" data-id="<?= $row['id'] ?>" data-field="miss_qty"
                               value="<?= (int)($row['miss_qty'] ?? 0) ?>" min="0" step="1" title="Miss"></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="21" style="text-align:center;padding:25px;color:#9ca3af;">No picklists found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($per_page && $total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= pageUrl(1, $pp) ?>">«</a>
                <a href="<?= pageUrl($page - 1, $pp) ?>">‹</a>
            <?php endif; ?>
            <?php
                for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++):
                    if ($i === $page): ?><span class="pg current"><?= $i ?></span>
                    else: ?><a href="<?= pageUrl($i, $pp) ?>"><?= $i ?></a><?php endif;
                endfor;
            ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?= pageUrl($page + 1, $pp) ?>">›</a>
                <a href="<?= pageUrl($total_pages, $pp) ?>">»</a>
            <?php endif; ?>
            <span class="info">Page <?= $page ?>/<?= $total_pages ?> (<?= $total_rows ?>)</span>
        </div>
        <?php else: ?>
        <div class="pagination"><span class="info">Showing all <?= $total_rows ?></span></div>
        <?php endif; ?>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* Timestamp helper — time-only inputs gamit ang kanilang data-date */
    function tsOf(inp) {
        if (!inp || !inp.value) return null;
        if (inp.type === 'time') {
            const d = inp.dataset.date;
            if (!d) return null;
            const t = new Date(d + 'T' + inp.value).getTime();
            return isNaN(t) ? null : t;
        }
        const t = new Date(inp.value).getTime();
        return isNaN(t) ? null : t;
    }

    function addDaysStr(dateStr, n) {
        const dt = new Date(dateStr + 'T00:00:00');
        dt.setDate(dt.getDate() + n);
        return dt.getFullYear() + '-' +
               String(dt.getMonth() + 1).padStart(2, '0') + '-' +
               String(dt.getDate()).padStart(2, '0');
    }

    /* Time-only inputs → ipapadala bilang full datetime (base date + time) */
    function fullValue(inp) {
        if (inp.type !== 'time' || !inp.value) return inp.value;
        const d = inp.dataset.date;
        if (!d) return inp.value;
        let datePart = d;
        const stTd  = inp.closest('td').previousElementSibling;
        const stInp = stTd ? stTd.querySelector('input.cell-edit') : null;
        const sTs = tsOf(stInp);
        const eTs = new Date(d + 'T' + inp.value).getTime();
        if (sTs && eTs && eTs < sTs) datePart = addDaysStr(d, 1); /* overnight — next day */
        return datePart + ' ' + inp.value + ':00';
    }

    function updateDurationFrom(finInp) {
        const finTd = finInp.closest('td');
        const durTd = finTd.nextElementSibling;
        if (!durTd || !durTd.classList.contains('dur')) return;
        const stInp = finTd.previousElementSibling.querySelector('input');
        const s = tsOf(stInp);
        if (!s) { durTd.innerHTML = '<b>—</b>'; return; }
        let e = finInp.value ? tsOf(finInp) : Date.now();
        if (e === null) e = Date.now();
        if (finInp.value && e < s) e += 86400000; /* overnight */
        const mins = Math.max(0, Math.floor((e - s) / 60000));
        durTd.innerHTML = '<b>' + (mins < 60 ? mins + ' min' : Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm') + '</b>';
    }

    /* ===== 🔄 AUTO STATUS: kapag may tinype na Start/Finish, auto ang badge, duration at button ===== */
    function refreshRole(inp) {
        const f = inp.dataset.field;
        if (f !== 'start_time' && f !== 'end_time' && f !== 'checker_start' && f !== 'checker_end') return;

        const isPicker = (f === 'start_time' || f === 'end_time');
        const isEnd    = (f === 'end_time'    || f === 'checker_end');

        const td     = inp.closest('td');
        const stTd   = isEnd ? td.previousElementSibling : td;
        const finTd  = isEnd ? td : td.nextElementSibling;
        const stInp  = stTd.querySelector('input');
        const finInp = finTd.querySelector('input');
        const durTd  = finTd.nextElementSibling;   /* Duration td */
        const statTd = durTd.nextElementSibling;   /* Status td   */
        const actTd  = statTd.nextElementSibling;  /* Action td   */
        const role   = isPicker ? 'picker' : 'checker';

        /* i-sync ang data-date ng Finish galing sa Start */
        if (!isEnd && stInp) {
            if (stInp.type === 'datetime-local' && stInp.value)   finInp.dataset.date = stInp.value.slice(0, 10);
            else if (stInp.type === 'time' && stInp.dataset.date) finInp.dataset.date = stInp.dataset.date;
        }

        /* ⏱ duration */
        updateDurationFrom(finInp);

        /* 🏷 status base sa oras na nakalagay */
        const tr = inp.closest('tr');
        let status;
        if (tr.dataset.status === 'Cancelled') status = 'Cancelled';
        else if (finInp.value)                 status = 'Completed';
        else if (stInp.value)                  status = 'In Progress';
        else                                   status = 'Pending';

        const cls = (status === 'In Progress') ? 'inprogress' : status.toLowerCase();
        statTd.innerHTML = '<span class="badge ' + cls + '">' + status + '</span>';

        /* 🔘 action button — tugma sa bagong status */
        if (status === 'Pending') {
            actTd.innerHTML = '<button type="button" class="btn btn-blue act-btn" data-id="' + inp.dataset.id + '" data-role="' + role + '" data-action="start">▶</button>';
        } else if (status === 'In Progress') {
            actTd.innerHTML = '<button type="button" class="btn btn-green act-btn" data-id="' + inp.dataset.id + '" data-role="' + role + '" data-action="complete">✔</button>';
            if (!finTd.querySelector('.running')) finInp.insertAdjacentHTML('afterend', ' <span class="running">●</span>');
        } else {
            actTd.innerHTML = '<span class="dim">✓</span>';
            const dot = finTd.querySelector('.running');
            if (dot) dot.remove();
        }
    }

    /* ===== ✏️ Cell editing (silent save, walang movement) ===== */
    function saveCell(inp) {
        const val = inp.value;
        if (val === inp.dataset.orig) { inp.classList.remove('dirty'); return; }
        inp.classList.add('dirty');
        inp.classList.remove('saved', 'save-err');

        fetch('update_field.php', {
            method: 'POST',
            body: new URLSearchParams({ id: inp.dataset.id, field: inp.dataset.field, value: fullValue(inp) })
        })
        .then(r => r.json())
        .then(res => {
            inp.classList.remove('dirty');
            if (res.ok) {
                inp.dataset.orig = val;
                inp.classList.add('saved');
                refreshRole(inp);   /* ⏱ auto duration + auto status + auto button */
                if (res.status) inp.closest('tr').dataset.status = res.status;
                setTimeout(() => inp.classList.remove('saved'), 1500);
            } else {
                inp.value = inp.dataset.orig;
                inp.classList.add('save-err');
            }
        })
        .catch(() => {
            inp.classList.remove('dirty');
            inp.classList.add('save-err');
        });
    }

    document.querySelectorAll('.cell-edit').forEach(inp => {
        inp.dataset.orig = inp.value;
        inp.addEventListener('focus', () => inp.classList.remove('saved', 'save-err'));
        inp.addEventListener('change', function () { saveCell(this); });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
    });

    /* ===== 🔒 Click-to-hide (mask) — Invoice #, Picker & Checker Name ===== */
    function setMask(cell, hide) {
        cell.classList.toggle('hidden', hide);
        const btn = cell.querySelector('.mask-btn');
        if (btn) btn.textContent = hide ? '🙈' : '👁';
    }

    document.querySelectorAll('.mask-cell').forEach(cell => {
        const btn = cell.querySelector('.mask-btn');
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                setMask(cell, !cell.classList.contains('hidden'));
            });
        }
        cell.addEventListener('click', function (e) {
            if (e.target.closest('.mask-btn')) return;
            const tag = e.target.tagName;
            if (tag === 'SELECT' || tag === 'INPUT' || tag === 'OPTION') return;
            const hidden = this.classList.contains('hidden');
            if (hidden) {
                setMask(this, false);
            } else if (e.target.classList.contains('mask-text')) {
                setMask(this, true);
            }
        });
    });

    /* ===== ▶✔ Action buttons — AJAX, ZERO movement, walang reload ===== */
    let lastAction = 0;

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.act-btn');
        if (!btn || btn.disabled) return;

        btn.disabled = true;

        fetch('time_action.php', {
            method: 'POST',
            body: new URLSearchParams({
                id: btn.dataset.id, role: btn.dataset.role,
                action: btn.dataset.action, ajax: '1'
            })
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) { btn.disabled = false; return; }
            lastAction = Date.now();
            applyAction(btn, btn.dataset.role, btn.dataset.action, res);
        })
        .catch(() => { btn.disabled = false; });
    });

    /* In-place row update — walang reload, walang scroll */
    function applyAction(btn, role, action, res) {
        const actTd  = btn.closest('td');
        const statTd = actTd.previousElementSibling;
        const durTd  = statTd.previousElementSibling;
        const finTd  = durTd.previousElementSibling;
        const stTd   = finTd.previousElementSibling;
        const stInp  = stTd.querySelector('input');
        const finInp = finTd.querySelector('input');

        function flash(el) {
            el.classList.add('saved');
            setTimeout(() => el.classList.remove('saved'), 1200);
        }

        if (action === 'start') {
            if (stInp.type === 'datetime-local') {
                stInp.value = res.datetime;
            } else {
                stInp.value = res.time;
                stInp.dataset.date = res.date;
            }
            stInp.dataset.orig = stInp.value;
            finInp.dataset.date = res.date;
            flash(stInp);
            statTd.innerHTML = '<span class="badge inprogress">In Progress</span>';
            if (!finTd.querySelector('.running')) {
                finInp.insertAdjacentHTML('afterend', ' <span class="running">●</span>');
            }
            btn.className = 'btn btn-green act-btn';
            btn.textContent = '✔';
            btn.dataset.action = 'complete';
            btn.disabled = false;
        } else {
            finInp.value = res.time;
            finInp.dataset.orig = res.time;
            finInp.dataset.date = res.date;
            flash(finInp);
            statTd.innerHTML = '<span class="badge completed">Completed</span>';
            const dot = finTd.querySelector('.running');
            if (dot) dot.remove();
            actTd.innerHTML = '<span class="dim">✓</span>';
        }
        updateDurationFrom(finInp);
    }

    /* =====================================================================
       ↔️ TABLE COLUMN RESIZE — drag the right border of any <th>
       Per-column widths are saved to localStorage (keyed by data-col).
       ===================================================================== */
    const COL_PREFIX = 'mspanay_col_';
    const table = document.getElementById('pickTable');
    const colgroup = table ? table.querySelector('colgroup') : null;
    const cols = colgroup ? Array.from(colgroup.querySelectorAll('col')) : [];

    /* Default starting widths (px) — tweak freely */
    const DEFAULT_WIDTHS = {
        'invoice': 110, 'retailer': 130,
        'recv-date': 100, 'recv-time': 70,
        'cs': 50, 'sw': 50, 'it': 50,
        'delivery': 100,
        'p-name': 120, 'p-start': 130, 'p-finish': 70, 'p-dur': 70, 'p-stat': 95, 'p-act': 50,
        'c-name': 120, 'c-start': 70,  'c-finish': 70, 'c-dur': 70, 'c-stat': 95, 'c-act': 50,
        'miss': 50
    };

    /* Apply saved or default width to each <col> */
    cols.forEach(col => {
        const key = col.dataset.col;
        if (!key) return;
        const saved = parseInt(localStorage.getItem(COL_PREFIX + key) || '', 10);
        const w = (!isNaN(saved) ? saved : DEFAULT_WIDTHS[key] || 90);
        col.style.width = w + 'px';
    });

    /* Add a drag handle to every leaf-level <th> (the sub-row ones) */
    const subRowThs = table.querySelectorAll('thead tr.sub-row th');
    subRowThs.forEach((th, idx) => {
        const handle = document.createElement('div');
        handle.className = 'col-resize';
        handle.dataset.colIndex = idx;
        th.appendChild(handle);

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            handle.classList.add('dragging');
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'col-resize';

            const col = cols[idx];
            if (!col) return;
            const startX = e.clientX;
            const startW = parseInt(getComputedStyle(col).width, 10) || 90;

            function onMove(ev) {
                const w = Math.max(40, startW + (ev.clientX - startX));
                col.style.width = w + 'px';
            }
            function onUp() {
                handle.classList.remove('dragging');
                document.body.style.userSelect = '';
                document.body.style.cursor = '';
                const finalW = parseInt(getComputedStyle(col).width, 10) || 90;
                const key = col.dataset.col;
                if (key) localStorage.setItem(COL_PREFIX + key, String(finalW));
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    });

    /* Auto-refresh every 60s — hindi gagalaw kung may tinatype ka o kakaclick lang */
    function scheduleRefresh() {
        setTimeout(() => {
            const busy = document.querySelector('.cell-edit:focus, .cell-edit.dirty');
            if (busy || Date.now() - lastAction < 120000) { scheduleRefresh(); return; }
            location.reload();
        }, 60000);
    }
    scheduleRefresh();
});
</script>
</body>
</html>