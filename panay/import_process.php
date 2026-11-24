<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =====================================================
   🎛️ "OSB INPUT" FILE LAYOUT (fallback letters — auto-detect
      ang gumagamit nito kapag hindi nahanap ang header row)
   -----------------------------------------------------
   A–I  : Salesman order info (Retailer, SO Number…)
   J–R  : Invoice / Picklist info
   S–AB : Actual picked qty + Picker / Checker
   ===================================================== */
const COL_PROCESS_DATE  = 'B';
const COL_SALESMAN      = 'C';
const COL_RETAILER_CODE = 'D';
const COL_RETAILER      = 'E';
const COL_SO_NUMBER     = 'F';
const COL_ASO_NUMBER    = 'G';
const COL_SO_DATE       = 'H';
const COL_SO_VALUE      = 'I';
const COL_TYPE          = 'J';   // Mobile / MR / Portal
const COL_SO_STATUS     = 'K';   // Completed / Allocated
const COL_CASES         = 'L';   // # of Cases  (planned CS)
const COL_SW_QTY        = 'M';   // # of SW
const COL_IT_QTY        = 'N';   // # of IT
const COL_OSB_NAME      = 'O';   // OSB Name (MIA, NIZZY…)
const COL_INVOICE       = 'P';   // Invoice #  → picklist_no
const COL_RECV_DATE     = 'Q';   // Picklist Received DATE
const COL_RECV_TIME     = 'R';   // Picklist Received TIME
const COL_ACT_CS        = 'S';   // ACTUAL PICKED QTY – CS
const COL_ACT_SW        = 'T';   // ACTUAL PICKED QTY – SW
const COL_ACT_IT        = 'U';   // ACTUAL PICKED QTY – IT
const COL_DELIVERY_DATE = 'V';   // Delivery DATE
const COL_PICKER_NAME   = 'W';
const COL_PICKER_START  = 'X';
const COL_PICKER_FINISH = 'Y';
const COL_CHECKER_NAME  = 'Z';
const COL_CHECKER_START = 'AA';
const COL_CHECKER_FINISH= 'AB';

const DATA_START_ROW_FALLBACK = 3;

const SKIP_IF_PICKLIST_EXISTS_ANY_DATE = true;
const UPDATE_EXISTING = true;       // true = pag na-import na ang invoice, i-UPDATE ang details
const QTY_TO_ALL_IN_BATCH = true;   // true = batch qty (hal. 7009 IT) nakikita sa bawat invoice ng batch

/* ===================================================== */

function redirect_back(string $type, string $msg, string $date): void {
    header("Location: add_picklist.php?$type=" . urlencode($msg) . "&date=" . urlencode($date));
    exit();
}

function col_index_from_letters(string $letters): int {
    if (trim($letters) === '') return -1;
    $index = 0;
    $letters = strtoupper(trim($letters));
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function clean_number(string $value): string {
    if (preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $value)) {
        $value = (string)(float)$value;
        if (strpos($value, '.') !== false) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
    }
    return $value;
}

/* 📅 Excel date → 'Y-m-d'  (serial number o text tulad ng "4-Jun-26", "2026/4/6") */
function norm_date($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^\d{1,5}(\.\d+)?$/', $v)) {              // Excel serial (46177 = 2026-06-04)
        $n = (float)$v;
        if ($n >= 20000 && $n <= 80000) {
            return gmdate('Y-m-d', (int)round(($n - 25569) * 86400));
        }
        return null;
    }
    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); // 2026/4/6
    }
    $t = strtotime($v);                                        // 4-Jun-26
    return $t !== false ? date('Y-m-d', $t) : null;
}

/* ⏰ Excel time → 'H:i:s'  (fraction o text tulad ng "8:53") */
function norm_time($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 0 && (float)$v < 1) {    // Excel time fraction
        $secs = (int)round(((float)$v) * 86400);
        return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }
    $t = strtotime($v);
    return $t !== false ? date('H:i:s', $t) : null;
}

function dt_of(?string $date, string $timeRaw): ?string {
    $t = norm_time($timeRaw);
    if ($t === null) return null;
    return ($date ?: date('Y-m-d')) . ' ' . $t;
}

/* "CA_632399 MANQIL-SAUL" → "MANQIL-SAUL" */
function clean_retailer(string $raw, string $code = ''): string {
    $name = trim($raw);
    $name = preg_replace('/^[A-Za-z]{2}_[A-Za-z0-9]+\s+/', '', $name);
    if (trim($code) !== '') {
        $name = trim(str_ireplace(trim($code), '', $name));
    }
    return $name !== '' ? $name : trim($raw);
}

/* ---------- 📄 IMPORT HISTORY ---------- */
function ensure_history_table($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS import_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        total_rows INT NOT NULL DEFAULT 0,
        success_rows INT NOT NULL DEFAULT 0,
        failed_rows INT NOT NULL DEFAULT 0,
        import_date DATE NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        imported_by INT NULL,
        INDEX idx_ih_date (import_date),
        INDEX idx_ih_at (imported_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $res = $conn->query("SHOW COLUMNS FROM import_history LIKE 'import_date'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE import_history ADD COLUMN import_date DATE NULL AFTER failed_rows");
    }
}

function log_import($conn, string $fileName, int $totalRows, int $successRows, int $failedRows, string $importDate, int $userId): void {
    ensure_history_table($conn);
    $stmt = $conn->prepare("INSERT INTO import_history
        (filename, total_rows, success_rows, failed_rows, import_date, imported_by)
        VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("siiisi", $fileName, $totalRows, $successRows, $failedRows, $importDate, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

/* ---------- 📊 XLSX reader (ZipArchive + SimpleXML, walang library) ---------- */
function read_xlsx_rows(string $filePath): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception("PHP extension 'zip' is not enabled. Enable extension=zip in php.ini, or import a CSV file instead.");
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Could not open the .xlsx file (it may be corrupted or not a real Excel file).");
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $xml = @simplexml_load_string($ssXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) { $text = (string)$si->t; }
                else { foreach ($si->r as $run) { $text .= (string)$run->t; } }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetPath = '';
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wb   = @simplexml_load_string($wbXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $firstSheet = $wb->sheets->sheet[0];
            $rid = '';
            $rAttrs = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string)($rAttrs['id'] ?? '');
            if ($rid === '') {
                foreach ($firstSheet->attributes() as $aName => $aVal) {
                    if (substr((string)$aName, -3) === 'id}') { $rid = (string)$aVal; }
                }
            }
            if ($rid !== '') {
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = (string)$rel['Target'];
                        if ($target !== '') {
                            if ($target[0] === '/')               $sheetPath = ltrim($target, '/');
                            elseif (strpos($target, 'xl/') === 0) $sheetPath = $target;
                            else                                  $sheetPath = 'xl/' . $target;
                        }
                        break;
                    }
                }
            }
        }
    }

    $candidates = [];
    if ($sheetPath !== '') $candidates[] = $sheetPath;
    $candidates[] = 'xl/worksheets/sheet1.xml';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name && preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name)) $candidates[] = $name;
    }

    $sheetXml = false;
    foreach (array_unique($candidates) as $candidate) {
        $sheetXml = $zip->getFromName($candidate);
        if ($sheetXml !== false) break;
    }
    $zip->close();

    if ($sheetXml === false) throw new Exception("Could not read the worksheet inside the .xlsx file.");
    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false)    throw new Exception("Could not parse the worksheet data.");

    $rowsData = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        $cells = []; $maxIndex = -1;
        foreach ($rowNode->c as $cell) {
            $ref      = (string)$cell['r'];
            $letters  = preg_replace('/[0-9]/', '', $ref);
            $colIndex = col_index_from_letters($letters);
            $type     = (string)$cell['t'];
            $value = '';
            if ($type === 's') {
                $value = $sharedStrings[(int)$cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cell->is->t;
                foreach ($cell->is->r ?? [] as $run) { $value .= (string)$run->t; }
            } elseif ($type === 'b') {
                $value = ((int)$cell->v) ? 'TRUE' : 'FALSE';
            } else {
                $value = (string)$cell->v;
            }
            $cells[$colIndex] = clean_number(trim($value));
            if ($colIndex > $maxIndex) $maxIndex = $colIndex;
        }
        if ($maxIndex < 0) continue;
        $ordered = [];
        for ($i = 0; $i <= $maxIndex; $i++) $ordered[] = $cells[$i] ?? '';
        $rowsData[] = $ordered;
    }
    return $rowsData;
}

/* =====================================================================
   MAIN
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_FILES['csv_file'])
    || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    redirect_back('error', 'Please choose a valid file.', date('Y-m-d'));
}

 $import_date = trim($_POST['import_date'] ?? '');
 $dt = DateTime::createFromFormat('Y-m-d', $import_date);
if (!$dt || $dt->format('Y-m-d') !== $import_date) {
    redirect_back('error', 'Please select a valid invoice date.', date('Y-m-d'));
}
 $import_date = $dt->format('Y-m-d');

 $fileName = $_FILES['csv_file']['name'];
 $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'csv'])) {
    redirect_back('error', 'Only .xlsx or .csv files are allowed.', $import_date);
}

 $inserted = 0; $updated = 0;
 $skippedExisting = 0; $noInvoiceRows = 0;
 $created_by = (int)$_SESSION['user_id'];

try {
    /* ---------- 🔧 AUTO-CREATE missing columns (isang beses lang) ---------- */
    foreach ([
        'imported_file' => "VARCHAR(255) NULL",
        'pick_date'     => "DATE NULL",
        'received_date' => "DATE NULL",
        'received_time' => "TIME NULL",
        'delivery_date' => "DATE NULL",
        'checker_status'=> "VARCHAR(20) NULL DEFAULT 'Pending'",
        'salesman_name' => "VARCHAR(120) NULL",
        'retailer_code' => "VARCHAR(60) NULL",
        'aso_number'    => "VARCHAR(80) NULL",
        'so_date'       => "DATE NULL",
        'so_value'      => "DECIMAL(14,2) NULL",
        'order_type'    => "VARCHAR(30) NULL",
        'so_status'     => "VARCHAR(30) NULL",
    ] as $col => $def) {
        try { $conn->query("ALTER TABLE picklists ADD COLUMN `$col` $def"); } catch (Throwable $e) {}
    }

    /* ---------- Read ALL rows ---------- */
    $rows = [];
    if ($ext === 'csv') {
        if (($handle = fopen($_FILES['csv_file']['tmp_name'], 'r')) === false) {
            throw new Exception("Could not open the uploaded file.");
        }
        $first = true;
        while (($data = fgetcsv($handle)) !== false) {
            if ($first) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$data[0]);
                $first = false;
            }
            if (count($data) === 1 && trim((string)$data[0]) === '') continue;
            $rows[] = $data;
        }
        fclose($handle);
    } else {
        $rows = read_xlsx_rows($_FILES['csv_file']['tmp_name']);
    }
    if (count($rows) === 0) throw new Exception("No rows found in the file.");

    /* ---------- 🔍 AUTO-DETECT header row + first data row ---------- */
    $hdrIdx = -1;
    $dIdx0 = col_index_from_letters(COL_RETAILER_CODE);
    $sIdx0 = col_index_from_letters(COL_SALESMAN);
    for ($r = 0; $r < min(12, count($rows)); $r++) {
        $dv = strtolower(trim((string)($rows[$r][$dIdx0] ?? '')));
        $sv = strtolower(trim((string)($rows[$r][$sIdx0] ?? '')));
        if ($dv === 'retailer code' || strpos($sv, 'salesman') !== false) { $hdrIdx = $r; break; }
    }
    $dataStartIdx = ($hdrIdx >= 0) ? $hdrIdx + 1 : DATA_START_ROW_FALLBACK - 1;
    $dataRows = array_slice($rows, $dataStartIdx);
    if (count($dataRows) === 0) throw new Exception("No data found after the header row.");

    /* ---------- 🔍 AUTO-DETECT column positions mula sa header row ---------- */
    $auto = [];
    if ($hdrIdx >= 0) {
        foreach ($rows[$hdrIdx] as $idx => $h) {
            $h = strtolower(trim((string)$h));
            if ($h === '') continue;
            $key = null;
            if (strpos($h, 'invoice') !== false)            $key = 'invoice';
            elseif (strpos($h, 'osb') !== false)            $key = 'osb';
            elseif ($h === 'retailer code')                 $key = 'rcode';
            elseif ($h === 'retailer')                      $key = 'rname';
            elseif (strpos($h, 'salesman') !== false)       $key = 'salesman';
            elseif (strpos($h, 'aso') !== false)            $key = 'aso';
            elseif (strpos($h, 'so number') !== false)      $key = 'so';
            elseif (strpos($h, 'so date') !== false)        $key = 'sodate';
            elseif (strpos($h, 'so value') !== false)       $key = 'soval';
            elseif (strpos($h, 'date of process') !== false)$key = 'pdate';
            elseif ($h === 'so status')                     $key = 'sostatus';
            elseif ($h === 'type')                          $key = 'type';
            elseif (strpos($h, '# of cases') !== false || $h === 'cases') $key = 'cases';
            elseif (strpos($h, '# of sw') !== false)        $key = 'sw';
            elseif (strpos($h, '# of it') !== false)        $key = 'it';
            elseif ($h === 'cs')                            $key = 'act_cs';
            elseif ($h === 'sw')                            $key = 'act_sw';
            elseif ($h === 'it')                            $key = 'act_it';
            elseif ($h === 'time')                          $key = 'recv_time';
            elseif ($h === 'date')                          $key = !isset($auto['recv_date']) ? 'recv_date' : 'delivery';
            elseif ($h === 'name')                          $key = !isset($auto['picker'])   ? 'picker'   : 'checker';
            elseif ($h === 'start')                         $key = !isset($auto['p_start'])  ? 'p_start'  : 'c_start';
            elseif ($h === 'finish')                        $key = !isset($auto['p_finish']) ? 'p_finish' : 'c_finish';
            if ($key !== null && !isset($auto[$key])) $auto[$key] = $idx;
        }
    }
    $C = [
        'pdate'    => $auto['pdate']    ?? col_index_from_letters(COL_PROCESS_DATE),
        'salesman' => $auto['salesman'] ?? col_index_from_letters(COL_SALESMAN),
        'rcode'    => $auto['rcode']    ?? col_index_from_letters(COL_RETAILER_CODE),
        'rname'    => $auto['rname']    ?? col_index_from_letters(COL_RETAILER),
        'so'       => $auto['so']       ?? col_index_from_letters(COL_SO_NUMBER),
        'aso'      => $auto['aso']      ?? col_index_from_letters(COL_ASO_NUMBER),
        'sodate'   => $auto['sodate']   ?? col_index_from_letters(COL_SO_DATE),
        'soval'    => $auto['soval']    ?? col_index_from_letters(COL_SO_VALUE),
        'type'     => $auto['type']     ?? col_index_from_letters(COL_TYPE),
        'sostatus' => $auto['sostatus'] ?? col_index_from_letters(COL_SO_STATUS),
        'cases'    => $auto['cases']    ?? col_index_from_letters(COL_CASES),
        'sw'       => $auto['sw']       ?? col_index_from_letters(COL_SW_QTY),
        'it'       => $auto['it']       ?? col_index_from_letters(COL_IT_QTY),
        'osb'      => $auto['osb']      ?? col_index_from_letters(COL_OSB_NAME),
        'invoice'  => $auto['invoice']  ?? col_index_from_letters(COL_INVOICE),
        'recv_date'=> $auto['recv_date']?? col_index_from_letters(COL_RECV_DATE),
        'recv_time'=> $auto['recv_time']?? col_index_from_letters(COL_RECV_TIME),
        'act_cs'   => $auto['act_cs']   ?? col_index_from_letters(COL_ACT_CS),
        'act_sw'   => $auto['act_sw']   ?? col_index_from_letters(COL_ACT_SW),
        'act_it'   => $auto['act_it']   ?? col_index_from_letters(COL_ACT_IT),
        'delivery' => $auto['delivery'] ?? col_index_from_letters(COL_DELIVERY_DATE),
        'picker'   => $auto['picker']   ?? col_index_from_letters(COL_PICKER_NAME),
        'p_start'  => $auto['p_start']  ?? col_index_from_letters(COL_PICKER_START),
        'p_finish' => $auto['p_finish'] ?? col_index_from_letters(COL_PICKER_FINISH),
        'checker'  => $auto['checker']  ?? col_index_from_letters(COL_CHECKER_NAME),
        'c_start'  => $auto['c_start']  ?? col_index_from_letters(COL_CHECKER_START),
        'c_finish' => $auto['c_finish'] ?? col_index_from_letters(COL_CHECKER_FINISH),
    ];

    /* ---------- Which columns exist in DB? ---------- */
    $dbCols = [];
    $res = $conn->query("SHOW COLUMNS FROM picklists");
    while ($r = $res->fetch_assoc()) $dbCols[] = $r['Field'];
    $has = function ($c) use ($dbCols) { return in_array($c, $dbCols, true); };

    /* ---------- Existing picklist numbers ---------- */
    $existing = [];
    if (SKIP_IF_PICKLIST_EXISTS_ANY_DATE) {
        $res = $conn->query("SELECT DISTINCT picklist_no FROM picklists");
        if ($res) { while ($r = $res->fetch_assoc()) $existing[$r['picklist_no']] = true; }
    } else {
        $chk = $conn->prepare("SELECT DISTINCT picklist_no FROM picklists WHERE pick_date = ?");
        if ($chk) {
            $chk->bind_param("s", $import_date);
            $chk->execute();
            $res = $chk->get_result();
            while ($r = $res->fetch_assoc()) $existing[$r['picklist_no']] = true;
            $chk->close();
        }
    }

    $g = function (array $data, int $idx): string {
        return $idx >= 0 ? trim((string)($data[$idx] ?? '')) : '';
    };
    $to_int = function ($v): int {
        $v = trim((string)$v);
        return ($v === '' || !is_numeric($v)) ? 0 : (int)$v;
    };

    /* ==========================================================
       🔄 BATCH forward-fill — ang qty / received time / picker /
          checker ay isang beses lang nakasulat per batch
          (merged cells), kaya dinadala pababa sa lahat ng
          invoices ng batch na iyon.
       ========================================================== */
    $batch = null;
    $newBatch = function () use (&$batch) {
        $batch = ['recv_date'=>'','recv_time'=>'','cases'=>'','sw'=>'','it'=>'',
                  'act_cs'=>'','act_sw'=>'','act_it'=>'','delivery'=>'',
                  'picker'=>'','p_start'=>'','p_finish'=>'',
                  'checker'=>'','c_start'=>'','c_finish'=>''];
    };
    $newBatch();

    foreach ($dataRows as $data) {

        $invoice = $g($data, $C['invoice']);

        /* ---- Bagong batch? (may nakitang qty / oras / picker / checker) ---- */
        if ($g($data, $C['recv_time']) !== '' || $g($data, $C['recv_date']) !== ''
            || $g($data, $C['cases'])   !== '' || $g($data, $C['sw'])       !== ''
            || $g($data, $C['it'])      !== '' || $g($data, $C['act_cs'])   !== ''
            || $g($data, $C['act_sw'])  !== '' || $g($data, $C['act_it'])  !== ''
            || $g($data, $C['delivery'])!== '' || $g($data, $C['picker'])  !== ''
            || $g($data, $C['checker']) !== '') {
            $newBatch();
            $batch['recv_date'] = $g($data, $C['recv_date']);
            $batch['recv_time'] = $g($data, $C['recv_time']);
            $batch['cases']     = $g($data, $C['cases']);
            $batch['sw']        = $g($data, $C['sw']);
            $batch['it']        = $g($data, $C['it']);
            $batch['act_cs']    = $g($data, $C['act_cs']);
            $batch['act_sw']    = $g($data, $C['act_sw']);
            $batch['act_it']    = $g($data, $C['act_it']);
            $batch['delivery']  = $g($data, $C['delivery']);
            $batch['picker']    = $g($data, $C['picker']);
            $batch['p_start']   = $g($data, $C['p_start']);
            $batch['p_finish']  = $g($data, $C['p_finish']);
            $batch['checker']   = $g($data, $C['checker']);
            $batch['c_start']   = $g($data, $C['c_start']);
            $batch['c_finish']  = $g($data, $C['c_finish']);
        }

        /* Walang invoice = Allocated row o blank row → skip (pero na-save na ang batch info) */
        if ($invoice === '') { $noInvoiceRows++; continue; }

        /* ---------- Per-invoice fields ---------- */
        $retailer_raw  = $g($data, $C['rname']);
        $retailer_code = $g($data, $C['rcode']);
        $customer      = clean_retailer($retailer_raw, $retailer_code);
        $salesman      = $g($data, $C['salesman']);
        $so_number     = clean_number($g($data, $C['so']));
        $aso_number    = clean_number($g($data, $C['aso']));
        $soDate        = norm_date($g($data, $C['sodate']));
        $soValRaw      = $g($data, $C['soval']);
        $soValue       = ($soValRaw !== '' && is_numeric($soValRaw)) ? (float)$soValRaw : null;
        $orderType     = $g($data, $C['type']);
        $soStatus      = $g($data, $C['sostatus']);
        $warehouse     = $g($data, $C['osb']);

        /* ---------- Qty: actual picked (S/T/U) mas优先, else planned (L/M/N) ---------- */
        if (QTY_TO_ALL_IN_BATCH) {
            $csRaw = ($batch['act_cs'] !== '') ? $batch['act_cs'] : $batch['cases'];
            $swRaw = ($batch['act_sw'] !== '') ? $batch['act_sw'] : $batch['sw'];
            $itRaw = ($batch['act_it'] !== '') ? $batch['act_it'] : $batch['it'];
        } else {
            $csRaw = ($g($data, $C['act_cs']) !== '') ? $g($data, $C['act_cs']) : $g($data, $C['cases']);
            $swRaw = ($g($data, $C['act_sw']) !== '') ? $g($data, $C['act_sw']) : $g($data, $C['sw']);
            $itRaw = ($g($data, $C['act_it']) !== '') ? $g($data, $C['act_it']) : $g($data, $C['it']);
        }

        /* ---------- 📅 Dates & ⏰ times ---------- */
        $recvDate  = norm_date($batch['recv_date']);
        $recvTime  = norm_time($batch['recv_time']);
        $delivDate = norm_date($batch['delivery']);
        $pickDate  = $recvDate ?: $import_date;
        $baseDate  = $delivDate ?: ($recvDate ?: $import_date);

        $pStart = dt_of($baseDate, $batch['p_start']);
        $pEnd   = dt_of($baseDate, $batch['p_finish']);
        $cStart = dt_of($baseDate, $batch['c_start']);
        $cEnd   = dt_of($baseDate, $batch['c_finish']);

        /* ---------- 🏷 Status ---------- */
        if ($pEnd && $cEnd)              { $status = 'Completed'; }
        elseif ($pStart || $cStart)      { $status = 'In Progress'; }
        else                             { $status = 'Pending'; }
        $checkerStatus = $cEnd ? 'Completed' : ($cStart ? 'In Progress' : 'Pending');

        /* ---------- Field set (shared ng INSERT at UPDATE) ---------- */
        $set = [
            'order_no'       => [$so_number, 's'],
            'customer_name'  => [$customer, 's'],
            'warehouse'      => [$warehouse, 's'],
            'cs_qty'         => [$to_int($csRaw), 'i'],
            'sw_qty'         => [$to_int($swRaw), 'i'],
            'it_qty'         => [$to_int($itRaw), 'i'],
            'received_date'  => [$recvDate, 's'],
            'received_time'  => [$recvTime, 's'],
            'delivery_date'  => [$delivDate, 's'],
            'picker_name'    => [$batch['picker'], 's'],
            'start_time'     => [$pStart, 's'],
            'end_time'       => [$pEnd, 's'],
            'checker_name'   => [$batch['checker'], 's'],
            'checker_start'  => [$cStart, 's'],
            'checker_end'    => [$cEnd, 's'],
            'status'         => [$status, 's'],
            'checker_status' => [$checkerStatus, 's'],
            'pick_date'      => [$pickDate, 's'],
            'salesman_name'  => [$salesman, 's'],
            'retailer_code'  => [$retailer_code, 's'],
            'aso_number'     => [$aso_number, 's'],
            'so_date'        => [$soDate, 's'],
            'so_value'       => [$soValue, 'd'],
            'order_type'     => [$orderType, 's'],
            'so_status'      => [$soStatus, 's'],
            'source'         => ['excel', 's'],
            'imported_file'  => [$fileName, 's'],
            'created_by'     => [$created_by, 'i'],
        ];

        try {
            if (isset($existing[$invoice])) {
                if (UPDATE_EXISTING) {
                    /* 🔄 UPDATE — pinapalamanan ang existing invoice ng kumpletong details */
                    $sets = []; $types = ''; $vals = [];
                    foreach ($set as $col => $v) {
                        if (!$has($col)) continue;
                        $sets[] = "`$col` = ?"; $types .= $v[1]; $vals[] = $v[0];
                    }
                    $types .= 's'; $vals[] = $invoice;
                    $stmt = $conn->prepare("UPDATE picklists SET " . implode(',', $sets) . " WHERE picklist_no = ?");
                    if ($stmt) {
                        $stmt->bind_param($types, ...$vals);
                        $stmt->execute();
                        $stmt->close();
                        $updated++;
                    }
                } else {
                    $skippedExisting++;
                }
                continue;
            }

            /* ➕ INSERT — bagong invoice */
            $cols = ['`picklist_no`']; $ph = ['?']; $types = 's'; $vals = [$invoice];
            foreach ($set as $col => $v) {
                if (!$has($col)) continue;
                $cols[] = "`$col`"; $ph[] = '?'; $types .= $v[1]; $vals[] = $v[0];
            }
            $stmt = $conn->prepare("INSERT INTO picklists (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")");
            if ($stmt) {
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $inserted++;
                    $existing[$invoice] = true;
                } else {
                    $skippedExisting++;
                }
                $stmt->close();
            } else {
                $skippedExisting++;
            }
        } catch (Throwable $e) {
            $skippedExisting++;
        }
    }

    if ($inserted + $updated + $skippedExisting === 0) {
        throw new Exception("No invoices found — check the column letters at the top of import_process.php.");
    }

} catch (Exception $e) {
    redirect_back('error', 'Import failed: ' . $e->getMessage(), $import_date);
}

/* ---------- 📄 Save to Import History ---------- */
 $totalInvoices = $inserted + $updated + $skippedExisting;
log_import($conn, $fileName, $totalInvoices, $inserted + $updated, $skippedExisting, $import_date, $created_by);

/* ---------- Legacy import_logs (kung may gumagamit pa) ---------- */
 $logCheck = $conn->query("SHOW TABLES LIKE 'import_logs'");
if ($logCheck && $logCheck->num_rows) {
    $log = $conn->prepare("INSERT INTO import_logs (file_name, total_inserted, total_skipped, import_date, imported_by)
                           VALUES (?, ?, ?, ?, ?)");
    if ($log) {
        $totalSkipped = $skippedExisting + $noInvoiceRows;
        $log->bind_param("siisi", $fileName, $inserted, $totalSkipped, $import_date, $created_by);
        $log->execute();
        $log->close();
    }
}

 $msg = "Import finished: $inserted inserted, $updated updated, $skippedExisting skipped (already exists), "
     . "$noInvoiceRows rows without invoice (Allocated/blank). Retailer, CS/SW/IT, picker & checker imported.";
redirect_back('success', $msg, $import_date);