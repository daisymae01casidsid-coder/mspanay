<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

/* ==========================================================
   USERS — User Management for MSPANAY admin accounts
   Design matches dashboard.php
   ========================================================== */

$msg      = '';
$msg_type = 'success';

/* ---------- Auto-create users table if not exists ---------- */
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'viewer',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    // Ignore — most likely already exists
}

$allowed_roles   = ['admin', 'supervisor', 'viewer'];
$allowed_status  = ['active', 'inactive'];

/* ================= ADD USER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username  = trim($_POST['username']  ?? '');
    $password  =        $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role      = $_POST['role']   ?? 'viewer';
    $status    = $_POST['status'] ?? 'active';

    if ($username === '' || $password === '' || $full_name === '') {
        $msg = 'All fields are required.'; $msg_type = 'error';
    } elseif (!in_array($role, $allowed_roles, true)) {
        $msg = 'Invalid role selected.'; $msg_type = 'error';
    } elseif (!in_array($status, $allowed_status, true)) {
        $msg = 'Invalid status selected.'; $msg_type = 'error';
    } else {
        // Check username uniqueness
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param("s", $username);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $res->fetch_assoc()) {
            $msg = 'Username already exists. Please choose another.'; $msg_type = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param("sssss", $username, $hash, $full_name, $role, $status);
            if ($ins->execute()) {
                $msg = "User '{$username}' added successfully."; $msg_type = 'success';
            } else {
                $msg = 'Failed to add user. Please try again.'; $msg_type = 'error';
            }
            $ins->close();
        }
        $chk->close();
    }
}

/* ================= EDIT USER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id          = (int)($_POST['id']          ?? 0);
    $username    = trim($_POST['username']     ?? '');
    $full_name   = trim($_POST['full_name']    ?? '');
    $role        = $_POST['role']              ?? 'viewer';
    $status      = $_POST['status']            ?? 'active';
    $new_password = $_POST['new_password']     ?? '';

    if ($id <= 0 || $username === '' || $full_name === '') {
        $msg = 'Invalid user data.'; $msg_type = 'error';
    } elseif (!in_array($role, $allowed_roles, true)) {
        $msg = 'Invalid role selected.'; $msg_type = 'error';
    } elseif (!in_array($status, $allowed_status, true)) {
        $msg = 'Invalid status selected.'; $msg_type = 'error';
    } else {
        // Check username uniqueness excluding current id
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $chk->bind_param("si", $username, $id);
        $chk->execute();
        $res = $chk->get_result();
        if ($res && $res->fetch_assoc()) {
            $msg = 'Username is already in use by another account.'; $msg_type = 'error';
        } else {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, status=? WHERE id=?");
                $upd->bind_param("sssssi", $username, $hash, $full_name, $role, $status, $id);
            } else {
                $upd = $conn->prepare("UPDATE users SET username=?, full_name=?, role=?, status=? WHERE id=?");
                $upd->bind_param("ssssi", $username, $full_name, $role, $status, $id);
            }
            if ($upd->execute()) {
                $msg = "User '{$username}' updated successfully."; $msg_type = 'success';
            } else {
                $msg = 'Failed to update user. Please try again.'; $msg_type = 'error';
            }
            $upd->close();
        }
        $chk->close();
    }
}

/* ================= DELETE USER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$_SESSION['user_id']) {
        $msg = "You cannot delete your own account while logged in."; $msg_type = 'error';
    } elseif ($id <= 0) {
        $msg = 'Invalid user id.'; $msg_type = 'error';
    } else {
        $del = $conn->prepare("DELETE FROM users WHERE id = ?");
        $del->bind_param("i", $id);
        if ($del->execute()) {
            $msg = 'User deleted successfully.'; $msg_type = 'success';
        } else {
            $msg = 'Failed to delete user. Please try again.'; $msg_type = 'error';
        }
        $del->close();
    }
}

/* ================= LIST USERS + STATS ================= */
$users_list = [];
try {
    $res = $conn->query("SELECT id, username, full_name, role, status, created_at FROM users ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) { $users_list[] = $row; }
    }
} catch (Throwable $e) {
    $users_list = [];
}

$total_count   = count($users_list);
$admin_count   = 0;
$sup_count     = 0;
$active_count  = 0;
foreach ($users_list as $u) {
    if (($u['role']   ?? '') === 'admin')      { $admin_count++; }
    if (($u['role']   ?? '') === 'supervisor') { $sup_count++; }
    if (($u['status'] ?? '') === 'active')     { $active_count++; }
}

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_page    = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>👤 Users · MSPANAY</title>
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
    .card.total  { border-top-color:#64748b; } .card.total b  { color:#475569; }
    .card.admin  { border-top-color:#ef4444; } .card.admin b  { color:#b91c1c; }
    .card.sup    { border-top-color:#3b82f6; } .card.sup b    { color:#1d4ed8; }
    .card.active { border-top-color:#22c55e; } .card.active b { color:#15803d; }

    /* ===== Filter bar (dashboard .filters) ===== */
    .filters { margin-bottom:10px; display:flex; align-items:center; flex-wrap:wrap; gap:5px;
               background:#fff; border-radius:6px; padding:8px 10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .filters .pp { margin-left:auto; font-size:11px; color:#64748b; }

    /* ===== Buttons ===== */
    .btn { padding:5px 12px; border:none; border-radius:4px; color:#fff; font-size:11px; font-weight:700;
           cursor:pointer; text-decoration:none; font-family:inherit; transition:background .15s, transform .1s; }
    .btn:active { transform:scale(.97); }
    .btn:disabled { opacity:.6; cursor:default; }
    .btn-blue  { background:#3b82f6; } .btn-blue:hover  { background:#2563eb; }
    .btn-red   { background:#ef4444; } .btn-red:hover   { background:#dc2626; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#16a34a; }
    .btn-lg    { padding:5px 12px; font-size:11px; border-radius:4px; }
    .btn-block { display:block; width:100%; margin-top:10px; }

    /* ===== Table ===== */
    .table-wrap { background:#fff; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:11px; min-width:600px; }
    th, td { padding:6px 8px; text-align:left; border-bottom:1px solid #eef1f5; white-space:nowrap; }
    thead th { background:#111827; font-size:10px; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    tbody tr:hover td { background:#f6f9fc; }
    tbody tr:last-child td { border-bottom:none; }
    .act-col { white-space:nowrap; text-align:right; }
    .num-col { width:40px; text-align:center; color:#9ca3af; }

    /* ===== Badges ===== */
    .badge { display:inline-block; padding:1px 7px; border-radius:8px; font-size:10px; font-weight:700; line-height:16px; }
    .badge.admin       { background:#fee2e2; color:#991b1b; }
    .badge.supervisor  { background:#dbeafe; color:#1e40af; }
    .badge.viewer      { background:#f1f5f9; color:#475569; }
    .badge.active      { background:#dcfce7; color:#166534; }
    .badge.inactive    { background:#fef3c7; color:#92400e; }

    /* ===== Modal ===== */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:100;
                     align-items:flex-start; justify-content:center; padding:40px 14px; overflow-y:auto; }
    .modal-overlay.show { display:flex; }
    .modal { background:#fff; border-radius:6px; width:100%; max-width:420px; overflow:hidden;
             box-shadow:0 12px 40px rgba(0,0,0,.25); }
    .modal-head { display:flex; align-items:center; gap:8px; padding:6px 14px; color:#fff;
                  font-size:11px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; background:#1d4ed8; }
    .modal-head .modal-close { margin-left:auto; background:none; border:none; font-size:16px; line-height:1;
                               cursor:pointer; color:#fff; opacity:.85; }
    .modal-head .modal-close:hover { opacity:1; }
    .modal-body { padding:14px 16px 16px; }
    .modal-overlay.show .modal { animation:popIn .2s ease; }
    @keyframes popIn { from { transform:translateY(-10px); opacity:0; } to { transform:translateY(0); opacity:1; } }

    /* ===== Forms ===== */
    label { display:block; margin:10px 0 4px; font-size:10px; font-weight:700;
            letter-spacing:.3px; text-transform:uppercase; color:#64748b; }
    label small { text-transform:none; letter-spacing:0; font-weight:400; }
    input[type="text"], input[type="password"], select { width:100%; padding:7px 10px; background:#f8fafc;
                                 border:1px solid #d1d5db; border-radius:6px; color:#1f2937; font-size:12px;
                                 font-family:inherit; transition:border-color .15s, box-shadow .15s; }
    input[type="text"]::placeholder, input[type="password"]::placeholder { color:#9ca3af; }
    input[type="text"]:focus, input[type="password"]:focus, select:focus { outline:none; background:#fff;
                                             border-color:#3b82f6; box-shadow:0 0 0 2px #dbeafe; }
    .inline-form { display:inline-block; margin-left:4px; }
    .dim { color:#9ca3af; font-style:italic; }
    .self-row td { background:#fefce8 !important; }

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
            <a href="dashboard.php"       class="<?= $current_page === 'dashboard.php'       ? 'active' : '' ?>">📊 Monitoring</a>
            <a href="add_picklist.php"    class="<?= $current_page === 'add_picklist.php'    ? 'active' : '' ?>">➕ Add / Import</a>
            <a href="import_history.php"  class="<?= $current_page === 'import_history.php'  ? 'active' : '' ?>">📄 History</a>
            <a href="retailers.php"       class="<?= $current_page === 'retailers.php'       ? 'active' : '' ?>">🏬 Retailers</a>
            <a href="staff.php"           class="<?= $current_page === 'staff.php'           ? 'active' : '' ?>">👥 Staff</a>
            <a href="reports.php"         class="<?= $current_page === 'reports.php'         ? 'active' : '' ?>">📈 Reports</a>
            <a href="performance.php"     class="<?= $current_page === 'performance.php'     ? 'active' : '' ?>">🏆 Performance</a>
            <a href="miss_tracking.php"   class="<?= $current_page === 'miss_tracking.php'   ? 'active' : '' ?>">❌ Miss Tracking</a>
            <a href="users.php"           class="<?= $current_page === 'users.php'           ? 'active' : '' ?>">👤 Users</a>
            <a href="settings.php"        class="<?= $current_page === 'settings.php'        ? 'active' : '' ?>">⚙️ Settings</a>
        </nav>
        <a href="logout.php" class="sidebar-logout">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <h1>👤 Users
            <span style="font-size:11px;color:#475569;background:#f1f5f9;padding:2px 10px;border-radius:10px;vertical-align:middle;">
                <?= $total_count ?> users
            </span>
        </h1>

        <?php if ($msg): ?>
            <p class="alert <?= htmlspecialchars($msg_type) ?>" id="alertBox"><?= htmlspecialchars($msg) ?></p>
        <?php endif; ?>

        <div class="cards">
            <div class="card total"><b><?= $total_count ?></b><span>Total Users</span></div>
            <div class="card admin"><b><?= $admin_count ?></b><span>Admins</span></div>
            <div class="card sup"><b><?= $sup_count ?></b><span>Supervisors</span></div>
            <div class="card active"><b><?= $active_count ?></b><span>Active</span></div>
        </div>

        <div class="filters">
            <button type="button" class="btn btn-blue btn-lg" onclick="openModal('userModal')">➕ Add User</button>
            <span class="pp">👥 <?= $total_count ?> users on file</span>
        </div>

        <div class="table-wrap">
            <table id="usersTable">
                <thead>
                    <tr>
                        <th class="num-col">#</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="act-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($total_count): ?>
                    <?php $i = 1; foreach ($users_list as $u):
                        $uid       = (int)$u['id'];
                        $role      = $u['role']     ?? 'viewer';
                        $status    = $u['status']   ?? 'active';
                        $is_self   = ($uid === $current_user_id);
                        $role_badge = in_array($role, $allowed_roles, true) ? $role : 'viewer';
                    ?>
                        <tr <?= $is_self ? 'class="self-row"' : '' ?>>
                            <td class="num-col"><?= $i++ ?></td>
                            <td>
                                <?= htmlspecialchars($u['username']) ?>
                                <?php if ($is_self): ?>
                                    <span class="badge active" style="margin-left:4px;">YOU</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><span class="badge <?= htmlspecialchars($role_badge) ?>"><?= htmlspecialchars(ucfirst($role)) ?></span></td>
                            <td><span class="badge <?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                            <td class="dim"><?= htmlspecialchars($u['created_at'] ?? '') ?></td>
                            <td class="act-col">
                                <button type="button" class="btn btn-blue" onclick="openUserModal(<?= $uid ?>)">✏️ Edit</button>
                                <?php if ($is_self): ?>
                                    <button type="button" class="btn btn-red" disabled title="You cannot delete your own account">🗑️ Delete</button>
                                <?php else: ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete user \'<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>\'? This cannot be undone.');">
                                        <input type="hidden" name="delete_user" value="1">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-red">🗑️ Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="dim" style="text-align:center;padding:25px;">No users found. Click "Add User" to create one.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<!-- ================= MODAL: ADD USER ================= -->
<div class="modal-overlay" id="userModal">
    <div class="modal">
        <div class="modal-head">
            <span>➕ Add New User</span>
            <button type="button" class="modal-close" onclick="closeModal('userModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="add_user" value="1">

                <label>Username</label>
                <input type="text" name="username" placeholder="e.g. jdelacruz" required maxlength="100">

                <label>Password</label>
                <input type="password" name="password" placeholder="Set a strong password" required>

                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="e.g. Juan Dela Cruz" required maxlength="150">

                <label>Role</label>
                <select name="role" required>
                    <option value="admin">Admin</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="viewer" selected>Viewer</option>
                </select>

                <label>Status</label>
                <select name="status" required>
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button type="submit" class="btn btn-blue btn-block">💾 Save User</button>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: EDIT USER ================= -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-head">
            <span>✏️ Edit User</span>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" autocomplete="off" id="editForm">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="id" id="e_id" value="0">

                <label>Username</label>
                <input type="text" name="username" id="e_username" required maxlength="100">

                <label>Full Name</label>
                <input type="text" name="full_name" id="e_full_name" required maxlength="150">

                <label>Role</label>
                <select name="role" id="e_role">
                    <option value="admin">Admin</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="viewer">Viewer</option>
                </select>

                <label>Status</label>
                <select name="status" id="e_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <label>New Password <small>(leave blank to keep current)</small></label>
                <input type="password" name="new_password" id="e_new_password" placeholder="••••••••">

                <button type="submit" class="btn btn-blue btn-block">💾 Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
    /* ===== User data for edit modal ===== */
    const userData = <?= json_encode($users_list, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    /* ===== Modal control ===== */
    function openModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('show');
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    }

    /* ===== Populate edit modal from userData ===== */
    function openUserModal(id) {
        const u = userData.find(x => Number(x.id) === Number(id));
        if (!u) { alert('User not found.'); return; }
        document.getElementById('e_id').value         = u.id;
        document.getElementById('e_username').value   = u.username;
        document.getElementById('e_full_name').value  = u.full_name;
        document.getElementById('e_role').value       = u.role     || 'viewer';
        document.getElementById('e_status').value     = u.status   || 'active';
        document.getElementById('e_new_password').value = '';
        openModal('editModal');
    }

    /* ===== Close on backdrop click ===== */
    document.querySelectorAll('.modal-overlay').forEach(function(ov) {
        ov.addEventListener('click', function(e) {
            if (e.target === ov) { ov.classList.remove('show'); }
        });
    });

    /* ===== Close on Escape key ===== */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            document.querySelectorAll('.modal-overlay.show').forEach(function(m) {
                m.classList.remove('show');
            });
        }
    });

    /* ===== Auto-dismiss alert after 4s ===== */
    (function() {
        const a = document.getElementById('alertBox');
        if (a) {
            setTimeout(function() {
                a.style.opacity = '0';
                setTimeout(function() { if (a) a.style.display = 'none'; }, 500);
            }, 4000);
        }
    })();
</script>

</body>
</html>