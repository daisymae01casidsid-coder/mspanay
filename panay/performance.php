<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$current_page = basename($_SERVER['PHP_SELF']);

/* ==========================================================
   📅 DATE FILTER (range: today | 7d | 30d | custom)
   ========================================================== */
$range = isset($_GET['range']) ? $_GET['range'] : '30d';
if (!in_array($range, ['today', '7d', '30d', 'custom'], true)) {
    $range = '30d';
}

$today = date('Y-m-d');

/* Validate custom from / to dates strictly via DateTime::createFromFormat */
$from_raw = isset($_GET['from']) ? trim($_GET['from']) : '';
$to_raw   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

$from_dt = DateTime::createFromFormat('Y-m-d', $from_raw);
$to_dt   = DateTime::createFromFormat('Y-m-d', $to_raw);

$from_valid = ($from_dt && $from_dt->format('Y-m-d') === $from_raw);
$to_valid   = ($to_dt   && $to_dt->format('Y-m-d')   === $to_raw);

switch ($range) {
    case 'today':
        $sql_from = $today;
        $sql_to   = $today;
        break;
    case '7d':
        $sql_from = date('Y-m-d', strtotime('-6 days'));
        $sql_to   = $today;
        break;
    case 'custom':
        $sql_from = $from_valid ? $from_raw : date('Y-m-d', strtotime('-29 days'));
        $sql_to   = $to_valid   ? $to_raw   : $today;
        /* Swap if from > to */
        if (strcmp($sql_from, $sql_to) > 0) {
            $tmp = $sql_from; $sql_from = $sql_to; $sql_to = $tmp;
        }
        break;
    case '30d':
    default:
        $sql_from = date('Y-m-d', strtotime('-29 days'));
        $sql_to   = $today;
        $range    = '30d';
        break;
}

/* Range label for display in the H1 badge */
if ($range === 'today') {
    $range_label = '📅 Today (' . date('M d, Y', strtotime($sql_from)) . ')';
} elseif ($range === '7d') {
    $range_label = '📅 Last 7 Days (' . date('M d', strtotime($sql_from)) . ' → ' . date('M d, Y', strtotime($sql_to)) . ')';
} elseif ($range === '30d') {
    $range_label = '📅 Last 30 Days (' . date('M d', strtotime($sql_from)) . ' → ' . date('M d, Y', strtotime($sql_to)) . ')';
} else {
    $range_label = '📅 Custom (' . date('M d, Y', strtotime($sql_from)) . ' → ' . date('M d, Y', strtotime($sql_to)) . ')';
}

/* ==========================================================
   ⏱ HELPER: format minutes → "Xm" or "Xh Ym" (null → "—")
   ========================================================== */
function fmtTime($mins) {
    if ($mins === null || $mins == 0) return '—';
    $m = round($mins);
    if ($m < 60) return $m . 'm';
    return floor($m / 60) . 'h ' . ($m % 60) . 'm';
}

/* ==========================================================
   📊 HELPER: efficiency badge (completed / total_assigned)
   >80% good (green), >50% mid (yellow), else bad (red)
   ========================================================== */
function effBadge($completed, $total) {
    $completed = (int)$completed;
    $total     = (int)$total;
    if ($total <= 0) {
        return '<span class="badge bad">—</span>';
    }
    $pct = ($completed / $total) * 100;
    if ($pct > 80)      $cls = 'good';
    elseif ($pct > 50)  $cls = 'mid';
    else                $cls = 'bad';
    return '<span class="badge ' . $cls . '">' . round($pct) . '%</span>';
}

/* Helper: filter URL for the range buttons */
function perfUrl($r) {
    return 'performance.php?' . http_build_query(['range' => $r]);
}

/* ==========================================================
   🏆 PICKER LEADERBOARD
   ========================================================== */
$picker_rows            = [];
$total_pickers          = 0;
$best_picker_name       = '—';
$best_picker_completed  = 0;

try {
    $sql = "SELECT s.full_name,
                   COUNT(p.id) AS total_assigned,
                   SUM(CASE WHEN p.end_time IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN p.start_time IS NOT NULL AND p.end_time IS NULL THEN 1 ELSE 0 END) AS in_progress,
                   AVG(CASE WHEN p.start_time IS NOT NULL AND p.end_time IS NOT NULL
                       THEN TIMESTAMPDIFF(MINUTE, p.start_time, p.end_time) END) AS avg_minutes,
                   SUM(COALESCE(p.miss_qty, 0)) AS total_misses
            FROM staff_list s
            LEFT JOIN picklists p ON p.picker_name = s.full_name AND DATE(p.created_at) BETWEEN ? AND ?
            WHERE s.role = 'picker'
            GROUP BY s.id, s.full_name
            ORDER BY completed DESC, avg_minutes ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $sql_from, $sql_to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $picker_rows[] = $row;
            $total_pickers++;
            if ((int)$row['completed'] > $best_picker_completed) {
                $best_picker_completed = (int)$row['completed'];
                $best_picker_name      = $row['full_name'];
            }
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    $picker_rows = [];
}

/* ==========================================================
   🏆 CHECKER LEADERBOARD
   ========================================================== */
$checker_rows            = [];
$total_checkers          = 0;
$best_checker_name       = '—';
$best_checker_completed  = 0;

try {
    $sql = "SELECT s.full_name,
                   COUNT(p.id) AS total_assigned,
                   SUM(CASE WHEN p.checker_end IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN p.checker_start IS NOT NULL AND p.checker_end IS NULL THEN 1 ELSE 0 END) AS in_progress,
                   AVG(CASE WHEN p.checker_start IS NOT NULL AND p.checker_end IS NOT NULL
                       THEN TIMESTAMPDIFF(MINUTE, p.checker_start, p.checker_end) END) AS avg_minutes,
                   SUM(COALESCE(p.miss_qty, 0)) AS total_misses
            FROM staff_list s
            LEFT JOIN picklists p ON p.checker_name = s.full_name AND DATE(p.created_at) BETWEEN ? AND ?
            WHERE s.role = 'checker'
            GROUP BY s.id, s.full_name
            ORDER BY completed DESC, avg_minutes ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $sql_from, $sql_to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $checker_rows[] = $row;
            $total_checkers++;
            if ((int)$row['completed'] > $best_checker_completed) {
                $best_checker_completed = (int)$row['completed'];
                $best_checker_name      = $row['full_name'];
            }
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    $checker_rows = [];
}

/* Trim best-name for display (short preview) */
$best_picker_short  = ($best_picker_name !== '—')
    ? htmlspecialchars(mb_strimwidth($best_picker_name, 0, 18, '…'))
    : '—';
$best_checker_short = ($best_checker_name !== '—')
    ? htmlspecialchars(mb_strimwidth($best_checker_name, 0, 18, '…'))
    : '—';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🏆 Performance — MSPANAY</title>
<style>
    :root { --sidebar-w: 220px; }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',Tahoma,sans-serif; background:#f1f3f7; color:#1f2937; font-size:13px; }
    .layout { display:flex; min-height:100vh; }

    /* ===== Sidebar (fixed 220px) ===== */
    .sidebar { width:var(--sidebar-w); background:#1e293b; color:#cbd5e1; display:flex; flex-direction:column;
               position:fixed; top:0; left:0; bottom:0; overflow-y:auto; }
    .sidebar-brand { font-size:15px; font-weight:700; color:#fff; padding:12px; border-bottom:1px solid #334155; }
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
    h1 .range-badge {
        font-size:11px; color:#1e40af; background:#dbeafe; padding:2px 10px;
        border-radius:10px; vertical-align:middle; margin-left:6px; font-weight:600;
    }

    /* ===== Stat cards ===== */
    .cards { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .card { flex:1; min-width:150px; background:#fff; border-radius:10px; padding:18px 16px; text-align:center;
            box-shadow:0 2px 6px rgba(0,0,0,.08); border-top:4px solid #64748b; }
    .card b { font-size:34px; display:block; line-height:1.2; }
    .card span { font-size:13px; color:#64748b; font-weight:600; }
    .card small { font-size:10px; color:#9ca3af; display:block; margin-top:3px; min-height:13px; }
    .card.total { border-top-color:#64748b; } .card.total b { color:#475569; }
    .card.pick  { border-top-color:#22c55e; } .card.pick  b { color:#15803d; }
    .card.check { border-top-color:#3b82f6; } .card.check b { color:#1d4ed8; }
    .card.best  { border-top-color:#a855f7; } .card.best  b { color:#7c3aed; }

    /* ===== Filters bar ===== */
    .filters { margin-bottom:10px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters a { padding:4px 12px; background:#fff; border:1px solid #d1d5db; border-radius:12px;
                 font-size:11px; color:#374151; text-decoration:none; }
    .filters a:hover { background:#f3f4f6; }
    .filters a.on { background:#1e293b; color:#fff; border-color:#1e293b; }
    .filters input[type=date] { padding:3px 6px; border:1px solid #d1d5db; border-radius:6px; font-size:11px;
                                font-family:inherit; color:#374151; outline:none; }
    .filters input[type=date]:focus { border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; }
    .filters .arrow { color:#9ca3af; font-size:11px; }
    .filters .apply-btn { padding:4px 10px; background:#22c55e; color:#fff; border:none;
                          border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; }
    .filters .apply-btn:hover { background:#16a34a; }
    .filters .range-info { margin-left:auto; font-size:11px; color:#64748b; }
    .filters .range-info b { color:#1f2937; }

    /* ===== Two-column leaderboard ===== */
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .panel { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
    .panel-head { display:flex; align-items:center; gap:8px; padding:6px 12px; color:#fff;
                  font-size:11px; font-weight:700; text-transform:uppercase; }
    .panel-head.g-picker  { background:#15803d; }
    .panel-head.g-checker { background:#1d4ed8; }
    .panel-head .cnt { background:rgba(255,255,255,.22); padding:1px 8px; border-radius:8px; font-size:10px; }
    .panel-head .sub { margin-left:auto; font-size:10px; font-weight:500; opacity:.85; text-transform:none; letter-spacing:0; }

    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; }
    th, td { padding:6px 8px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; color:#fff; letter-spacing:.3px; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    .num { text-align:center; }
    .rank { text-align:center; font-weight:700; color:#64748b; }
    .rank.top1 { color:#b45309; }   /* gold */
    .rank.top2 { color:#6b7280; }   /* silver */
    .rank.top3 { color:#b45309; opacity:.75; }  /* bronze */
    .name-cell { font-weight:600; color:#1f2937; }
    .empty-row td { text-align:center; padding:25px; color:#9ca3af; }

    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; }
    .badge.good { background:#dcfce7; color:#166534; }
    .badge.mid  { background:#fef3c7; color:#92400e; }
    .badge.bad  { background:#fee2e2; color:#991b1b; }

    .miss-zero { color:#9ca3af; }
    .miss-has  { color:#b91c1c; font-weight:700; }

    @media (max-width:900px) {
        .two-col { grid-template-columns:1fr; }
    }
    @media (max-width:768px) {
        .sidebar { position:relative; width:100%; height:auto; }
        .main-content { margin-left:0; padding:8px; }
        .cards .card { min-width:120px; }
        .filters .range-info { display:none; }
    }
</style>
</head>
<body>
<div class="layout">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar">
        <div class="sidebar-brand">📦 MSPANAY</div>
        <div class="sidebar-user">
            👤 <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?><br>
            <small><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? '')) ?></small>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"      class="<?= $current_page === 'dashboard.php'      ? 'active' : '' ?>">📊 Monitoring</a>
            <a href="add_picklist.php"   class="<?= $current_page === 'add_picklist.php'   ? 'active' : '' ?>">➕ Add / Import</a>
            <a href="import_history.php" class="<?= $current_page === 'import_history.php' ? 'active' : '' ?>">📄 History</a>
            <a href="retailers.php"      class="<?= $current_page === 'retailers.php'      ? 'active' : '' ?>">🏬 Retailers</a>
            <a href="staff.php"          class="<?= $current_page === 'staff.php'          ? 'active' : '' ?>">👥 Staff</a>
            <a href="reports.php"        class="<?= $current_page === 'reports.php'        ? 'active' : '' ?>">📈 Reports</a>
            <a href="performance.php"    class="<?= $current_page === 'performance.php'    ? 'active' : '' ?>">🏆 Performance</a>
            <a href="miss_tracking.php"  class="<?= $current_page === 'miss_tracking.php'  ? 'active' : '' ?>">❌ Miss Tracking</a>
            <a href="users.php"          class="<?= $current_page === 'users.php'          ? 'active' : '' ?>">👤 Users</a>
            <a href="settings.php"       class="<?= $current_page === 'settings.php'       ? 'active' : '' ?>">⚙️ Settings</a>
        </nav>
        <a href="logout.php" class="sidebar-logout">🚪 Logout</a>
    </aside>

    <!-- ============ MAIN ============ -->
    <main class="main-content">

        <h1>🏆 Performance
            <span class="range-badge"><?= $range_label ?></span>
        </h1>

        <!-- ============ STAT CARDS ============ -->
        <!-- Color mapping: Total Pickers=.pick (green), Total Checkers=.check (blue),
             Best Picker=.best (purple), Best Checker=.total (gray) — all 4 variants used -->
        <div class="cards">
            <div class="card pick">
                <b><?= $total_pickers ?></b>
                <span>Total Pickers</span>
                <small>Active picker staff</small>
            </div>
            <div class="card check">
                <b><?= $total_checkers ?></b>
                <span>Total Checkers</span>
                <small>Active checker staff</small>
            </div>
            <div class="card best">
                <b title="<?= htmlspecialchars($best_picker_name) ?>"><?= $best_picker_short ?></b>
                <span>Best Picker</span>
                <small><?= $best_picker_completed ?> completed</small>
            </div>
            <div class="card total">
                <b title="<?= htmlspecialchars($best_checker_name) ?>"><?= $best_checker_short ?></b>
                <span>Best Checker</span>
                <small><?= $best_checker_completed ?> completed</small>
            </div>
        </div>

        <!-- ============ FILTER BAR ============ -->
        <div class="filters">
            <a href="<?= perfUrl('today') ?>" class="<?= $range === 'today' ? 'on' : '' ?>">Today</a>
            <a href="<?= perfUrl('7d') ?>"    class="<?= $range === '7d'    ? 'on' : '' ?>">Last 7 Days</a>
            <a href="<?= perfUrl('30d') ?>"   class="<?= $range === '30d'   ? 'on' : '' ?>">Last 30 Days</a>

            <form method="GET" action="performance.php" id="customRangeForm" style="display:flex; align-items:center; gap:5px;">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="from" value="<?= htmlspecialchars($sql_from) ?>"
                       id="fromDate" max="<?= htmlspecialchars($today) ?>">
                <span class="arrow">→</span>
                <input type="date" name="to" value="<?= htmlspecialchars($sql_to) ?>"
                       id="toDate" max="<?= htmlspecialchars($today) ?>">
                <button type="submit" class="apply-btn">Apply</button>
            </form>

            <span class="range-info">
                Showing data for: <b><?= date('M d, Y', strtotime($sql_from)) ?></b> → <b><?= date('M d, Y', strtotime($sql_to)) ?></b>
            </span>
        </div>

        <!-- ============ TWO-COLUMN LEADERBOARD ============ -->
        <div class="two-col">

            <!-- ===== PICKERS PANEL ===== -->
            <div class="panel">
                <div class="panel-head g-picker">
                    <span>👷 Pickers Leaderboard</span>
                    <span class="cnt"><?= $total_pickers ?></span>
                    <span class="sub">by completed count</span>
                </div>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="num" style="width:34px;">#</th>
                            <th>Name</th>
                            <th class="num">Assigned</th>
                            <th class="num">Done</th>
                            <th class="num">In Prog.</th>
                            <th class="num">Avg Time</th>
                            <th class="num">Misses</th>
                            <th class="num">Efficiency</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($picker_rows)): ?>
                        <?php $rank = 0; foreach ($picker_rows as $row):
                            $rank++;
                            $completed   = (int)$row['completed'];
                            $assigned    = (int)$row['total_assigned'];
                            $in_prog     = (int)$row['in_progress'];
                            $misses      = (int)$row['total_misses'];
                            $rank_cls = '';
                            if ($rank === 1)      $rank_cls = ' top1';
                            elseif ($rank === 2)  $rank_cls = ' top2';
                            elseif ($rank === 3)  $rank_cls = ' top3';
                        ?>
                        <tr>
                            <td class="rank<?= $rank_cls ?>"><?= $rank ?></td>
                            <td class="name-cell"><?= htmlspecialchars($row['full_name']) ?></td>
                            <td class="num"><?= $assigned ?></td>
                            <td class="num"><b style="color:#15803d;"><?= $completed ?></b></td>
                            <td class="num"><?= $in_prog ?></td>
                            <td class="num"><?= htmlspecialchars(fmtTime($row['avg_minutes'])) ?></td>
                            <td class="num <?= $misses > 0 ? 'miss-has' : 'miss-zero' ?>"><?= $misses ?></td>
                            <td class="num"><?= effBadge($completed, $assigned) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row"><td colspan="8">No picker data for this period.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ===== CHECKERS PANEL ===== -->
            <div class="panel">
                <div class="panel-head g-checker">
                    <span>✅ Checkers Leaderboard</span>
                    <span class="cnt"><?= $total_checkers ?></span>
                    <span class="sub">by completed count</span>
                </div>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="num" style="width:34px;">#</th>
                            <th>Name</th>
                            <th class="num">Assigned</th>
                            <th class="num">Done</th>
                            <th class="num">In Prog.</th>
                            <th class="num">Avg Time</th>
                            <th class="num">Misses</th>
                            <th class="num">Efficiency</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($checker_rows)): ?>
                        <?php $rank = 0; foreach ($checker_rows as $row):
                            $rank++;
                            $completed   = (int)$row['completed'];
                            $assigned    = (int)$row['total_assigned'];
                            $in_prog     = (int)$row['in_progress'];
                            $misses      = (int)$row['total_misses'];
                            $rank_cls = '';
                            if ($rank === 1)      $rank_cls = ' top1';
                            elseif ($rank === 2)  $rank_cls = ' top2';
                            elseif ($rank === 3)  $rank_cls = ' top3';
                        ?>
                        <tr>
                            <td class="rank<?= $rank_cls ?>"><?= $rank ?></td>
                            <td class="name-cell"><?= htmlspecialchars($row['full_name']) ?></td>
                            <td class="num"><?= $assigned ?></td>
                            <td class="num"><b style="color:#1d4ed8;"><?= $completed ?></b></td>
                            <td class="num"><?= $in_prog ?></td>
                            <td class="num"><?= htmlspecialchars(fmtTime($row['avg_minutes'])) ?></td>
                            <td class="num <?= $misses > 0 ? 'miss-has' : 'miss-zero' ?>"><?= $misses ?></td>
                            <td class="num"><?= effBadge($completed, $assigned) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row"><td colspan="8">No checker data for this period.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </div><!-- /.two-col -->

    </main>
</div>

<script>
/* ==========================================================
   Filter UX helpers — auto-sync custom range "to" with "from"
   ========================================================== */
document.addEventListener('DOMContentLoaded', function () {
    const form   = document.getElementById('customRangeForm');
    if (!form) return;
    const fromEl = document.getElementById('fromDate');
    const toEl   = document.getElementById('toDate');

    /* When "from" changes, ensure "to" is never before "from" */
    fromEl.addEventListener('change', function () {
        if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
            toEl.value = fromEl.value;
        }
        /* Auto-submit on from change for snappy UX */
        form.submit();
    });

    /* When "to" changes, ensure "from" is never after "to" */
    toEl.addEventListener('change', function () {
        if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
            fromEl.value = toEl.value;
        }
        form.submit();
    });
});
</script>
</body>
</html>