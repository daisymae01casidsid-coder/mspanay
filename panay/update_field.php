<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok' => false]); exit(); }

 $id    = (int)($_POST['id'] ?? 0);
 $field = $_POST['field'] ?? '';
 $value = trim($_POST['value'] ?? '');

/* ✅ Whitelist lang ang pwedeng i-edit */
 $fields = [
    'received_date' => 'date',  'received_time' => 'time',
    'cs_qty' => 'int', 'sw_qty' => 'int', 'it_qty' => 'int', 'miss_qty' => 'int',
    'delivery_date' => 'date',
    'picker_name' => 'text', 'checker_name' => 'text',
    'start_time' => 'datetime', 'end_time' => 'datetime',
    'checker_start' => 'datetime', 'checker_end' => 'datetime',
];

if (!$id || !isset($fields[$field])) { echo json_encode(['ok' => false]); exit(); }
 $type = $fields[$field];

/* ===== Normalize ng value ===== */
if ($type === 'int') {
    $value = ($value === '') ? 0 : max(0, (int)$value);
} elseif ($type === 'date') {
    if ($value !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) { echo json_encode(['ok' => false]); exit(); }
    } else $value = null;
} elseif ($type === 'time') {
    if ($value !== '') {
        $d = DateTime::createFromFormat('H:i', $value);
        if (!$d) { echo json_encode(['ok' => false]); exit(); }
        $value .= ':00';
    } else $value = null;
} elseif ($type === 'datetime') {
    if ($value !== '') {
        $value = str_replace('T', ' ', $value);      /* datetime-local → 'Y-m-d H:i' */
        if (strlen($value) === 16) $value .= ':00';
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if (!$d) { echo json_encode(['ok' => false]); exit(); }
    } else $value = null;
} else { /* text */
    if ($value === '') $value = null;
}

/* ===== 1️⃣ I-save ang in-edit na field ===== */
if ($type === 'int') {
    $stmt = $conn->prepare("UPDATE picklists SET `$field` = ? WHERE id = ?");
    $stmt->bind_param('ii', $value, $id);
} else {
    $stmt = $conn->prepare("UPDATE picklists SET `$field` = ? WHERE id = ?");
    $stmt->bind_param('si', $value, $id);
}
 $stmt->execute();

/* ===== 2️⃣ ⏱ AUTO STATUS — kapag Start/Finish ang binago ===== */
 $newStatus = null;
if ($type === 'datetime') {
    $r = $conn->query("SELECT start_time, end_time, checker_start, checker_end, status FROM picklists WHERE id = $id");
    $row = $r ? $r->fetch_assoc() : null;

    if ($row && ($row['status'] ?? '') !== 'Cancelled') {   /* 🔒 huwag galawin ang Cancelled */

        $pickerSt  = !empty($row['end_time'])    ? 'Completed' : (!empty($row['start_time'])    ? 'In Progress' : 'Pending');
        $checkerSt = !empty($row['checker_end']) ? 'Completed' : (!empty($row['checker_start']) ? 'In Progress' : 'Pending');

        /* 🔧 'both' = tapos na PAREHO (picker + checker) bago maging Completed ang buong row
              'any'  = kahit isa lang ang tapos, Completed na agad */
        $overall_mode = 'both';

        if ($overall_mode === 'both') {
            $newStatus = ($pickerSt === 'Completed' && $checkerSt === 'Completed') ? 'Completed'
                       : (($pickerSt !== 'Pending' || $checkerSt !== 'Pending') ? 'In Progress' : 'Pending');
        } else {
            $newStatus = ($pickerSt === 'Completed' || $checkerSt === 'Completed') ? 'Completed'
                       : (($pickerSt !== 'Pending' || $checkerSt !== 'Pending') ? 'In Progress' : 'Pending');
        }

        $u = $conn->prepare("UPDATE picklists SET status = ? WHERE id = ?");
        $u->bind_param('si', $newStatus, $id);
        $u->execute();

        /* checker_status (fallback ng checker badge) — kung may column */
        if ($field === 'checker_start' || $field === 'checker_end') {
            try {
                $conn->query("UPDATE picklists SET checker_status = '" . $conn->real_escape_string($newStatus) . "' WHERE id = $id");
            } catch (Throwable $e) { /* walang column, okay lang */ }
        }
    }
}

echo json_encode(['ok' => true, 'status' => $newStatus]);