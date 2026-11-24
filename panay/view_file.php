<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);

/* ==========================================================
   🔧 AUTO-REPAIR: punan ang pick_date / imported_file ng mga
   LUMANG rows (na-import bago ma-add ang columns). Isang beses
   lang ito gagana — kapag wala nang NULL, tatalon agad.
   ========================================================== */
try {
    $nc = $conn->query("SELECT COUNT(*) AS c FROM picklists WHERE pick_date IS NULL");
    if ($nc && (int)$nc->fetch_assoc()['c'] > 0) {
        $conn->query("UPDATE picklists SET pick_date = DATE(created_at) WHERE pick_date IS NULL");
    }
} catch (Throwable $e) {}

try {
    $ec = $conn->query("SELECT COUNT(*) AS c FROM picklists WHERE imported_file IS NULL OR imported_file = ''");
    if ($ec && (int)$ec->fetch_assoc()['c'] > 0) {
        /* best-effort: itugma ang pick_date ↔ import_date sa history */
        $conn->query("UPDATE picklists p
                      LEFT JOIN import_history h ON p.pick_date = h.import_date
                      SET p.imported_file = h.filename
                      WHERE (p.imported_file IS NULL OR p.imported_file = '')
                        AND h.filename IS NOT NULL");
    }
} catch (Throwable $e) {}

/* ===== Inputs ===== */
 $file = trim($_GET['file'] ?? '');
 $date = trim($_GET['date'] ?? '');
 $dtChk = DateTime::createFromFormat('Y-m-d', $date);
 $date = ($dtChk && $dtChk->format('Y-m-d') === $date) ? $date : '';

/* ===== 🔍 Column detection (safe, walang crash) ===== */
 $hasPickDate = false; $hasFile = false;
try {
    $res = $conn->query("SHOW COLUMNS FROM picklists");
    while ($res && ($c = $res->fetch_assoc())) {
        $f = strtolower($c['Field']);
        if ($f === 'pick_date')     $hasPickDate = true;
        if ($f === 'imported_file') $hasFile = true;
    }
} catch (Throwable $e) {}

/* ==========================================================
   🔗 FALLBACK CHAIN: file → pick_date → created_at → LAHAT
   Siguradong may makikita ka. Imposibleng "Showing all 0".
   ========================================================== */
 $attempts = [];
if ($file !== '' && $hasFile) {
    $attempts[] = ['where' => "imported_file = ?",    'params' => [$file], 'types' => 's',
                   'label' => "📄 " . $file, 'isFile' => true];
}
if ($date !== '' && $hasPickDate) {
    $attempts[] = ['where' => "DATE(pick_date) = ?",  'params' => [$date], 'types' => 's',
                   'label' => "📅 " . date('M d, Y', strtotime($date)) . " (pick date)", 'isFile' => false];
}
if ($date !== '') {
    $attempts[] = ['where' => "DATE(created_at) = ?", 'params' => [$date], 'types' => 's',
                   'label' => "📅 " . date('M d, Y', strtotime($date)) . " (import date)", 'isFile' => false];
}

 $chosen = null;
foreach ($attempts as $a) {
    try {
        $t = $conn->prepare("SELECT COUNT(*) AS c FROM picklists WHERE " . $a['where']);
        $t->bind_param($a['types'], ...$a['params']);
        $t->execute();
        if ((int)$t->get_result()->fetch_assoc()['c'] > 0) { $chosen = $a; break; }
    } catch (Throwable $e) { continue; }
}

 $whereSql = ""; $params = []; $types = ''; $notice = '';
if ($chosen) {
    $whereSql = " WHERE " . $chosen['where'];
    $params   = $chosen['params'];
    $types    = $chosen['types'];
    if ($file !== '' && !$chosen['isFile']) {
        $notice = "ℹ️ Walang rows na naka-tag sa file na \"<b>" . htmlspecialchars($file) . "</b>\" (lumang import) — ipinapakita ang <b>" . htmlspecialchars($chosen['label']) . "</b> sa halip.";
    }
} elseif ($file !== '' || $date !== '') {
    $notice = "ℹ️ Walang tugma sa hinahanap — ipinapakita muna ang <b>LAHAT</b> ng picklists.";
}

/* ===== Per page (default: ALL para makita lahat agad) ===== */
 $pp       = $_GET['per_page'] ?? 'all';
 $per_page = ($pp === 'all') ? 0 : max(10, (int)$pp);
 $page     = max(1, (int)($_GET['page'] ?? 1));

/* ===== Count ===== */
 $total_rows = 0;
try {
    $cstmt = $conn->prepare("SELECT COUNT(*) AS c FROM picklists p" . $whereSql);
    if ($params) $cstmt->bind_param($types, ...$params);
    $cstmt->execute();
    $total_rows = (int)$cstmt->get_result()->fetch_assoc()['c'];
} catch (Throwable $e) { $total_rows = 0; }

 $total_pages = $per_page ? max(1, ceil($total_rows / $per_page)) : 1;
if ($page > $total_pages) $page = $total_pages;
 $limitSql = $per_page ? " LIMIT " . (int)$per_page . " OFFSET " . (int)(($page - 1) * $per_page) : "";

/* ===== Data ===== */
 $picklists = null;
try {
    $sql = "SELECT p.* FROM picklists p" . $whereSql . " ORDER BY p.created_at DESC" . $limitSql;
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $picklists = $stmt->get_result();
} catch (Throwable $e) { $picklists = null; }

/* ===== Stats para sa selection na ito ===== */
 $stats = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0, 'Cancelled' => 0];
try {
    if ($whereSql !== '') {
        $sst = $conn->prepare("SELECT status, COUNT(*) AS total FROM picklists p" . $whereSql . " GROUP BY status");
        if ($params) $sst->bind_param($types, ...$params);
        $sst->execute();
        $sres = $sst->get_result();
    } else {
        $sres = $conn->query("SELECT status, COUNT(*) AS total FROM picklists GROUP BY status");
    }
    while ($r = $sres->fetch_assoc()) $stats[$r['status']] = (int)$r['total'];
} catch (Throwable $e) { /* stay 0 */ }
 $total = array_sum($stats);

function duration($start, $end) {
    if (!$start) return '—';
    $s = strtotime($start);
    $e = $end ? strtotime($end) : time();
    $mins = max(0, floor(($e - $s) / 60));
    if ($mins < 60) return $mins . ' min';
    return floor($mins / 60) . 'h ' . ($mins % 60) . 'm';
}

function roleCells($row, $role) {
    $p      = ($role === 'picker');
    $name   = $p ? ($row['picker_name']     ?? '') : ($row['checker_name']    ?? '');
    if ($name === $row['picklist_no']) $name = '';
    $start  = $p ? ($row['start_time']      ?? null) : ($row['checker_start'] ?? null);
    $end    = $p ? ($row['end_time']        ?? null) : ($row['checker_end']   ?? null);
    $status = $p ? ($row['status']          ?? 'Pending') : ($row['checker_status'] ?? 'Pending');
    $inprog = ($status === 'In Progress');
    ?>
    <td><?= $name !== '' ? htmlspecialchars($name) : '<span class="dim">—</span>' ?></td>
    <td><?= $start ? date('m/d/y g:i A', strtotime($start)) : '—' ?></td>
    <td><?= $end ? date('m/d/y g:i A', strtotime($end)) : ($inprog ? '<span class="running">●</span>' : '—') ?></td>
    <td class="dur"><b><?= duration($start, $end) ?></b></td>
    <td><span class="badge <?= strtolower(str_replace(' ', '', $status)) ?>"><?= htmlspecialchars($status) ?></span></td>
    <td class="act">
        <?php if ($status === 'Pending'): ?>
            <form method="POST" action="time_action.php" class="inline-form">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="role" value="<?= $role ?>">
                <button type="submit" class="btn btn-blue">▶</button>
            </form>
        <?php elseif ($inprog): ?>
            <form method="POST" action="time_action.php" class="inline-form">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="role" value="<?= $role ?>">
                <button type="submit" class="btn btn-green">✔</button>
            </form>
        <?php else: ?><span class="dim">✓</span><?php endif; ?>
    </td>
    <?php
}

function pageUrl($p, $pp) {
    return "?" . http_build_query(array_merge($_GET, ['page' => $p, 'per_page' => $pp]));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View File Picklists</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    .sidebar { width:170px; background:#1e293b; color:#cbd5e1; display:flex; flex-direction:column;
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

    .main-content { margin-left:170px; flex:1; padding:12px 14px; }
    h1 { font-size:16px; margin-bottom:10px; word-break:break-all; }

    .notice { background:#fef9c3; color:#854d0e; border:1px solid #fde68a; border-radius:6px;
              padding:8px 12px; font-size:11px; margin-bottom:10px; }

    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .card { flex:1; min-width:130px; background:#fff; border-radius:10px; padding:14px 12px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:26px; display:block; line-height:1.2; }
    .card span { font-size:11px; color:#64748b; font-weight:600; }
    .card.pending   { border-top-color:#f59e0b; } .card.pending b   { color:#b45309; }
    .card.progress  { border-top-color:#3b82f6; } .card.progress b  { color:#1d4ed8; }
    .card.completed { border-top-color:#22c55e; } .card.completed b { color:#15803d; }

    .filters { margin-bottom:8px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
    .filters a { padding:3px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; }
    .filters a:hover { background:#f3f4f6; }
    .filters a.on { background:#22c55e; color:#fff; border-color:#22c55e; }
    .filters .back { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters .lbl { font-size:11px; color:#64748b; margin-left:auto; }

    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:1400px; }
    th, td { padding:3px 7px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    thead tr.group-row th { text-align:center; font-size:11px; padding:5px; border-bottom:2px solid #0f172a; }
    thead tr.sub-row th { background:#111827; padding:4px 7px; }
    .g-received{background:#475569} .g-qty{background:#9a3412} .g-delivery{background:#a16207}
    .g-picker{background:#15803d}   .g-checker{background:#1d4ed8}
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
            <a href="dashboard.php">📊 Monitoring</a>
            <a href="add_picklist.php">➕ Add / Import</a>
            <a href="import_history.php" class="active">📄 History</a>
            <a href="staff.php">👥 Staff</a>
        </nav>
        <a href="logout.php" class="sidebar-logout">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <h1>📄 <?= $file !== '' ? htmlspecialchars($file) : 'All Picklists' ?>
            <?php if ($date !== ''): ?>
                <span style="font-size:11px;color:#1e40af;background:#dbeafe;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                    📅 <?= date('M d, Y', strtotime($date)) ?>
                </span>
            <?php endif; ?>
        </h1>

        <?php if ($notice !== ''): ?><div class="notice"><?= $notice ?></div><?php endif; ?>

        <div class="cards">
            <div class="card"><b><?= $total ?></b><span>Total Invoices</span></div>
            <div class="card pending"><b><?= $stats['Pending'] ?></b><span>Pending</span></div>
            <div class="card progress"><b><?= $stats['In Progress'] ?></b><span>In Progress</span></div>
            <div class="card completed"><b><?= $stats['Completed'] ?></b><span>Completed</span></div>
        </div>

        <div class="filters">
            <a href="import_history.php" class="back">← Back to History</a>
            <span class="lbl">Rows:
                <?php foreach (['25','50','100','all'] as $opt): ?>
                    <a href="<?= pageUrl(1, $opt) ?>" class="<?= $pp === $opt ? 'on' : '' ?>"><?= $opt === 'all' ? 'All' : $opt ?></a>
                <?php endforeach; ?>
            </span>
        </div>

        <div class="table-wrap">
        <table>
            <thead>
                <tr class="group-row">
                    <th rowspan="2" style="background:#111827; vertical-align:bottom;">Invoice #</th>
                    <th rowspan="2" style="background:#111827; vertical-align:bottom;">Order No</th>
                    <th rowspan="2" style="background:#111827; vertical-align:bottom;">Customer</th>
                    <th colspan="2" class="g-received">📋 Picklist Received</th>
                    <th colspan="3" class="g-qty">📦 Picked Qty</th>
                    <th rowspan="2" class="g-delivery" style="vertical-align:bottom;">🚚 Delivery</th>
                    <th colspan="6" class="g-picker">👷 Picker</th>
                    <th colspan="6" class="g-checker">✅ Checker</th>
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
                <tr>
                    <td><b><?= htmlspecialchars($row['picklist_no']) ?></b></td>
                    <td><?= htmlspecialchars($row['order_no'] ?? '') ?: '—' ?></td>
                    <td><?= htmlspecialchars($row['customer_name'] ?? '') ?: '—' ?></td>
                    <td><?= !empty($row['received_date']) ? date('m/d/y', strtotime($row['received_date'])) : '—' ?></td>
                    <td><?= !empty($row['received_time']) ? date('g:i A', strtotime($row['received_time'])) : '—' ?></td>
                    <td class="num"><?= (int)($row['cs_qty'] ?? 0) ?></td>
                    <td class="num"><?= (int)($row['sw_qty'] ?? 0) ?></td>
                    <td class="num"><?= (int)($row['it_qty'] ?? 0) ?></td>
                    <td><?= !empty($row['delivery_date']) ? date('m/d/y', strtotime($row['delivery_date'])) : '—' ?></td>
                    <?php roleCells($row, 'picker');  ?>
                    <?php roleCells($row, 'checker'); ?>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="21" style="text-align:center;padding:25px;color:#9ca3af;">No invoices found.</td></tr>
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
        <div class="pagination"><span class="info">Showing all <?= $total_rows ?> invoices</span></div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>