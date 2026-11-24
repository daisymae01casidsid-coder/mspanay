<?php
require_once 'config.php';

 $isAjax = (($_POST['ajax'] ?? '') === '1');
if ($isAjax) header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { echo json_encode(['ok' => false, 'msg' => 'Session expired']); exit(); }
    header("Location: login.php"); exit();
}

 $id     = (int)($_POST['id'] ?? 0);
 $action = $_POST['action'] ?? '';
 $role   = ($_POST['role'] ?? '') === 'checker' ? 'checker' : 'picker';

if ($id <= 0 || !in_array($action, ['start', 'complete'], true)) {
    if ($isAjax) { echo json_encode(['ok' => false, 'msg' => 'Invalid request']); exit(); }
    header("Location: dashboard.php"); exit();
}

/* checker_status column exists? */
 $hasCheckerStatus = false;
try {
    $c = $conn->query("SHOW COLUMNS FROM picklists LIKE 'checker_status'");
    $hasCheckerStatus = ($c && $c->num_rows > 0);
} catch (Throwable $e) { $hasCheckerStatus = false; }

 $now = date('Y-m-d H:i:s');

if ($role === 'picker') {

    if ($action === 'start') {
        $sql = "UPDATE picklists SET start_time = ?,
                status = IF(status = 'Pending', 'In Progress', status)
                WHERE id = ?";
    } else {
        /* ✔ Picker finish → Completed LANG kung tapos na rin ang checker */
        $sql = $hasCheckerStatus
            ? "UPDATE picklists SET end_time = ?,
               status = CASE
                            WHEN (checker_end IS NOT NULL OR checker_status = 'Completed') AND status <> 'Cancelled' THEN 'Completed'
                            WHEN status = 'Pending' THEN 'In Progress'
                            ELSE status
                        END
               WHERE id = ?"
            : "UPDATE picklists SET end_time = ?,
               status = IF(status = 'Pending', 'In Progress', 'Completed')
               WHERE id = ?";
    }

} else {

    if ($action === 'start') {
        $sql = $hasCheckerStatus
            ? "UPDATE picklists SET checker_start = ?,
               checker_status = IF(checker_status IS NULL OR checker_status = 'Pending', 'In Progress', checker_status)
               WHERE id = ?"
            : "UPDATE picklists SET checker_start = ? WHERE id = ?";
    } else {
        /* ✔ Checker finish → Completed LANG kung tapos na rin ang picker */
        $sql = $hasCheckerStatus
            ? "UPDATE picklists SET checker_end = ?,
               checker_status = IF(checker_status IS NULL OR checker_status IN ('Pending','In Progress'), 'Completed', checker_status),
               status = CASE WHEN end_time IS NOT NULL AND status <> 'Cancelled' THEN 'Completed' ELSE status END
               WHERE id = ?"
            : "UPDATE picklists SET checker_end = ? WHERE id = ?";
    }
}

 $ok = false;
try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $now, $id);
    $ok = $stmt->execute();
} catch (Throwable $e) { $ok = false; }

if ($isAjax) {
    echo json_encode([
        'ok'       => $ok,
        'time'     => date('H:i'),
        'date'     => date('Y-m-d'),
        'datetime' => date('Y-m-d\TH:i'),
        'msg'      => $ok ? '' : 'Save failed'
    ]);
} else {
    header("Location: dashboard.php");
}
exit();