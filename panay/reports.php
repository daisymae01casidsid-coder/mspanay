<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

 $current_page = basename($_SERVER['PHP_SELF']);

/* ===== Date range filter ===== */
$today = date('Y-m-d');
$week_ago = date('Y-m-d', strtotime('-7 days'));
$month_ago = date('Y-m-d', strtotime('-30 days'));

$range = $_GET['range'] ?? '30d';
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';

$validDate = function ($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
};
$date_from = $validDate($date_from);
$date_to = $validDate($date_to);

if ($range === 'today') {
    $sql_from = $today; $sql_to = $today;
} elseif ($range === '7d') {
    $sql_from = $week_ago; $sql_to = $today;
} elseif ($range === '30d') {
    $sql_from = $month_ago; $sql_to = $today;
} elseif ($range === 'custom' && $date_from && $date_to) {
    $sql_from = $date_from; $sql_to = $date_to;
} else {
    $range = '30d'; $sql_from = $month_ago; $sql_to = $today;
}

/* ===== Overall stats ===== */
$stats = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'pending' => 0, 'cancelled' => 0,
          'total_misses' => 0, 'avg_minutes' => 0, 'completion_rate' => 0, 'miss_rate' => 0];

$stmt = $conn->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
           SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
           SUM(COALESCE(miss_qty, 0)) as total_misses,
           AVG(CASE WHEN start_time IS NOT NULL AND end_time IS NOT NULL
               THEN TIMESTAMPDIFF(MINUTE, start_time, end_time) END) as avg_minutes
    FROM picklists
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $sql_from, $sql_to);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if ($res) {
    $stats['total'] = (int)$res['total'];
    $stats['completed'] = (int)$res['completed'];
    $stats['in_progress'] = (int)$res['in_progress'];
    $stats['pending'] = (int)$res['pending'];
    $stats['cancelled'] = (int)$res['cancelled'];
    $stats['total_misses'] = (int)$res['total_misses'];
    $stats['avg_minutes'] = $res['avg_minutes'] ? round($res['avg_minutes'], 1) : 0;
    $stats['completion_rate'] = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100, 1) : 0;
    $stats['miss_rate'] = $stats['total'] > 0 ? round(($stats['total_misses'] / $stats['total']) * 100, 1) : 0;
}
$stmt->close();

/* ===== Daily breakdown (for chart + table) ===== */
$daily = [];
$stmt = $conn->prepare("
    SELECT DATE(created_at) as date,
           COUNT(*) as total,
           SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
           SUM(COALESCE(miss_qty, 0)) as misses,
           AVG(CASE WHEN start_time IS NOT NULL AND end_time IS NOT NULL
               THEN TIMESTAMPDIFF(MINUTE, start_time, end_time) END) as avg_minutes
    FROM picklists
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->bind_param("ss", $sql_from, $sql_to);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $daily[] = $r; }
$stmt->close();

/* ===== Status breakdown (for pie chart) ===== */
$status_breakdown = [];
$stmt = $conn->prepare("
    SELECT status, COUNT(*) as count
    FROM picklists
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY status
");
$stmt->bind_param("ss", $sql_from, $sql_to);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $status_breakdown[$r['status']] = (int)$r['count']; }
$stmt->close();

/* ===== Top retailers by volume ===== */
$top_retailers = [];
$stmt = $conn->prepare("
    SELECT customer_name, COUNT(*) as total, SUM(COALESCE(miss_qty, 0)) as misses
    FROM picklists
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND customer_name IS NOT NULL AND customer_name <> ''
    GROUP BY customer_name
    ORDER BY total DESC
    LIMIT 10
");
$stmt->bind_param("ss", $sql_from, $sql_to);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $top_retailers[] = $r; }
$stmt->close();

$range_label = ($range === 'today') ? 'Today' : (($range === '7d') ? 'Last 7 Days' : (($range === '30d') ? 'Last 30 Days' : "$date_from → $date_to"));

/* CSV export */
$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . $sql_from . '_to_' . $sql_to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Total', 'Completed', 'Misses', 'Avg Time (min)']);
    foreach (array_reverse($daily) as $d) {
        fputcsv($out, [$d['date'], $d['total'], $d['completed'], $d['misses'], round($d['avg_minutes'] ?? 0, 1)]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }
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
    .main-content { margin-left:var(--sidebar-w); flex:1; padding:12px 14px; }
    h1 { font-size:16px; margin-bottom:12px; }
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:120px; background:#fff; border-radius:10px; padding:14px 12px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:28px; display:block; line-height:1.2; }
    .card span { font-size:11px; color:#64748b; font-weight:600; }
    .card.total { border-top-color:#64748b; } .card.total b { color:#475569; }
    .card.completed { border-top-color:#22c55e; } .card.completed b { color:#15803d; }
    .card.misses { border-top-color:#ef4444; } .card.misses b { color:#b91c1c; }
    .card.time { border-top-color:#3b82f6; } .card.time b { color:#1d4ed8; }
    .card.rate { border-top-color:#a855f7; } .card.rate b { color:#7c3aed; }
    .filters { margin-bottom:10px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters a, .filters button.chip { padding:4px 12px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; cursor:pointer; font-family:inherit; }
    .filters a:hover { background:#f3f4f6; }
    .filters a.on { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters input[type=date] { padding:3px 6px; border:1px solid #d1d5db; border-radius:6px; font-size:11px; font-family:inherit; }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }
    .btn { padding:5px 12px; border:none; border-radius:4px; color:#fff; font-size:11px; font-weight:700;
           cursor:pointer; text-decoration:none; font-family:inherit; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .charts-row { display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-bottom:14px; }
    @media (max-width:900px) { .charts-row { grid-template-columns:1fr; } }
    .chart-box { background:#fff; border-radius:6px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .chart-box h3 { font-size:12px; color:#64748b; margin-bottom:10px; text-transform:uppercase; letter-spacing:.4px; }
    .chart-box canvas { max-height:240px; }
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; margin-bottom:14px; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:500px; }
    th, td { padding:6px 10px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    .num { text-align:center; }
    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.good { background:#dcfce7; color:#166534; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    .section-title { font-size:13px; font-weight:700; margin:16px 0 8px; color:#1f2937; }
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
        <h1>📈 Reports
            <span style="font-size:11px;color:#1e40af;background:#dbeafe;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                <?= htmlspecialchars($range_label) ?>
            </span>
        </h1>

        <div class="cards">
            <div class="card total"><b><?= $stats['total'] ?></b><span>Total Picklists</span></div>
            <div class="card completed"><b><?= $stats['completed'] ?></b><span>Completed</span></div>
            <div class="card misses"><b><?= $stats['total_misses'] ?></b><span>Total Misses</span></div>
            <div class="card time"><b><?= $stats['avg_minutes'] ?>m</b><span>Avg Pick Time</span></div>
            <div class="card rate"><b><?= $stats['completion_rate'] ?>%</b><span>Completion Rate</span></div>
        </div>

        <div class="filters">
            <a href="?range=today" class="<?= $range === 'today' ? 'on' : '' ?>">Today</a>
            <a href="?range=7d" class="<?= $range === '7d' ? 'on' : '' ?>">Last 7 Days</a>
            <a href="?range=30d" class="<?= $range === '30d' ? 'on' : '' ?>">Last 30 Days</a>
            <form method="GET" style="display:flex; gap:4px; align-items:center;">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="from" value="<?= htmlspecialchars($date_from ?: $month_ago) ?>">
                <input type="date" name="to" value="<?= htmlspecialchars($date_to ?: $today) ?>">
                <button type="submit" class="chip" style="padding:4px 10px; background:#fff; border:1px solid #d1d5db; border-radius:12px; font-size:11px; cursor:pointer;">Apply</button>
            </form>
            <span class="pp">
                <a href="?range=<?= $range ?>&from=<?= $date_from ?>&to=<?= $date_to ?>&export=csv" class="btn btn-green">📥 Export CSV</a>
            </span>
        </div>

        <div class="charts-row">
            <div class="chart-box">
                <h3>📊 Daily Picklist Volume</h3>
                <canvas id="dailyChart"></canvas>
            </div>
            <div class="chart-box">
                <h3>🥧 Status Breakdown</h3>
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="section-title">📋 Daily Summary</div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="num">Total</th>
                    <th class="num">Completed</th>
                    <th class="num">Misses</th>
                    <th class="num">Avg Time</th>
                    <th class="num">Miss Rate</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($daily): ?>
                <?php foreach (array_reverse($daily) as $d):
                    $dr = $d['total'] > 0 ? round(($d['misses'] / $d['total']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($d['date'])) ?></td>
                    <td class="num"><b><?= $d['total'] ?></b></td>
                    <td class="num"><?= $d['completed'] ?></td>
                    <td class="num"><?= $d['misses'] ?></td>
                    <td class="num"><?= $d['avg_minutes'] ? round($d['avg_minutes'], 0) . 'm' : '—' ?></td>
                    <td class="num"><span class="badge <?= $dr > 10 ? 'bad' : 'good' ?>"><?= $dr ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">No data for this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($top_retailers): ?>
        <div class="section-title">🏪 Top 10 Retailers by Volume</div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Retailer</th>
                    <th class="num">Picklists</th>
                    <th class="num">Misses</th>
                    <th class="num">Miss Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($top_retailers as $r):
                    $mr = $r['total'] > 0 ? round(($r['misses'] / $r['total']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><?= str_pad($i++, 2, '0', STR_PAD_LEFT) ?></td>
                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                    <td class="num"><b><?= $r['total'] ?></b></td>
                    <td class="num"><?= $r['misses'] ?></td>
                    <td class="num"><span class="badge <?= $mr > 10 ? 'bad' : 'good' ?>"><?= $mr ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
const dailyData = <?= json_encode($daily) ?>;
const statusData = <?= json_encode($status_breakdown) ?>;

// Daily volume chart
new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: {
        labels: dailyData.map(d => d.date.substring(5)),
        datasets: [{
            label: 'Picklists',
            data: dailyData.map(d => d.total),
            backgroundColor: '#3b82f6',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// Status breakdown pie
const sLabels = Object.keys(statusData);
const sValues = Object.values(statusData);
const sColors = sLabels.map(s => {
    if (s === 'Completed') return '#22c55e';
    if (s === 'In Progress') return '#3b82f6';
    if (s === 'Pending') return '#f59e0b';
    if (s === 'Cancelled') return '#ef4444';
    return '#64748b';
});
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: sLabels, datasets: [{ data: sValues, backgroundColor: sColors }] },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
    }
});
</script>
</body>
</html>