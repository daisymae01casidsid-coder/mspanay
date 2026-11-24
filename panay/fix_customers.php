<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

/* ⚠️ Kopyahin mo rito ang SAME autoload/require line na nasa taas ng
   import_process.php mo (para sa PhpSpreadsheet), hal.:
   require 'vendor/autoload.php';                                   */

function cleanCustomerName($retailerRaw, $retailerCode = '') {
    $name = trim((string)$retailerRaw);
    $name = preg_replace('/^[A-Za-z]{2,3}_[A-Za-z0-9]+\s+/i', '', $name);
    if (trim((string)$retailerCode) !== '') {
        $name = trim(str_ireplace(trim((string)$retailerCode), '', $name));
    }
    return $name !== '' ? $name : trim((string)$retailerRaw);
}

 $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv_file']['tmp_name'])) {
    try {
        $ext    = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        $reader = ($ext === 'csv')
            ? new PhpOffice\PhpSpreadsheet\Reader\Csv()
            : new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $sheet = $reader->load($_FILES['csv_file']['tmp_name'])->getSheet(0);

        $upd   = $conn->prepare("UPDATE picklists SET customer_name = ? WHERE picklist_no = ?");
        $fixed = 0; $notFound = 0;

        for ($r = 3; $r <= 1000; $r++) {          /* data starts row 3 */
            $invoice = trim((string)$sheet->getCell('P' . $r)->getValue());
            if ($invoice === '') continue;

            $code = trim((string)$sheet->getCell('D' . $r)->getValue());
            $raw  = trim((string)$sheet->getCell('E' . $r)->getValue());
            $name = cleanCustomerName($raw, $code);
            if ($name === '') continue;

            $upd->bind_param('ss', $name, $invoice);
            $upd->execute();
            ($upd->affected_rows > 0) ? $fixed++ : $notFound++;
        }
        $msg = "✅ $fixed picklists na-update. $notFound hindi nahanap sa DB.";
    } catch (Throwable $e) {
        $msg = "❌ " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fix Customers</title></head>
<body style="font-family:sans-serif;max-width:520px;margin:40px auto;">
    <h2>🔧 Fix Customer Names (one-time)</h2>
    <?php if ($msg): ?><p><?= $msg ?></p><?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <p>Ilagay ang SAME Excel file na na-import mo:</p>
        <input type="file" name="csv_file" accept=".xlsx,.csv" required>
        <button type="submit">Fix Now</button>
    </form>
    <p><a href="dashboard.php">← Back to Dashboard</a></p>
</body></html>