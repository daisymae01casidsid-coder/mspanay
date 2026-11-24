<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired']); exit();
}

 $id    = (int)($_POST['id'] ?? 0);
 $field = $_POST['field'] ?? '';
 $value = trim($_POST['value'] ?? '');

 $allowed = [
    'received_date' => 'date',
    'received_time' => 'time',
    'cs_qty'        => 'qty',
    'sw_qty'        => 'qty',
    'it_qty'        => 'qty',
    'delivery_date' => 'date',
];

if (!$id || !isset($allowed[$field])) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid field']); exit();
}

 $kind = $allowed[$field];

if ($kind === 'qty') {
    if ($value === '') $value = '0';
    if (!preg_match('/^\d+$/', $value)) {
        echo json_encode(['ok' => false, 'msg' => 'Qty must be a whole number']); exit();
    }
    $value = (int)$value;
} elseif ($kind === 'date') {
    if ($value === '') { $value = null; }
    else {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid date']); exit();
        }
    }
} elseif ($kind === 'time') {
    if ($value === '') { $value = null; }
    else {
        $t = DateTime::createFromFormat('H:i', $value);
        if (!$t) { echo json_encode(['ok' => false, 'msg' => 'Invalid time']); exit(); }
        $r = $conn->query("SHOW COLUMNS FROM picklists LIKE 'received_time'");
        $colType = ($r && ($c = $r->fetch_assoc())) ? strtolower($c['Type']) : 'time';
        if (strpos($colType, 'datetime') !== false) {
            $g = $conn->prepare("SELECT received_time FROM picklists WHERE id = ?");
            $g->bind_param('i', $id);
            $g->execute();
            $ex = $g->get_result()->fetch_assoc()['received_time'] ?? null;
            $datePart = $ex ? date('Y-m-d', strtotime($ex)) : date('Y-m-d');
            $value = $datePart . ' ' . $value . ':00';
        } else {
            $value = $value . ':00';
        }
    }
}

 $chk = $conn->prepare("SELECT id FROM picklists WHERE id = ?");
 $chk->bind_param('i', $id);
 $chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    echo json_encode(['ok' => false, 'msg' => 'Picklist not found']); exit();
}

if ($value === null) {
    $stmt = $conn->prepare("UPDATE picklists SET `$field` = NULL WHERE id = ?");
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("UPDATE picklists SET `$field` = ? WHERE id = ?");
    $stmt->bind_param('si', $value, $id);
}

echo json_encode(['ok' => $stmt->execute()] ?: ['ok' => false, 'msg' => 'DB error: ' . $conn->error]);