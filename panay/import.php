<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* =====================================================
   🎛️ FIXED COLUMN MAPPING
   ===================================================== */
const COL_PICKLIST_NO   = 'P';
const COL_ORDER_NO      = 'B';
const COL_CUSTOMER_NAME = 'Q';
const COL_WAREHOUSE     = 'B';
const COL_PICKER_NAME   = 'P';
const COL_CHECKER_NAME  = 'P';
const DATA_START_ROW    = 3;   // first Excel row that contains data

/**
 * true  = a Picklist No that exists ANYWHERE in the DB (any date)
 *         is skipped — an invoice can never be imported twice.
 * false = duplicates are only checked against the selected date.
 */
const SKIP_IF_PICKLIST_EXISTS_ANY_DATE = true;

/* ===================================================== */

function redirect_back(string $type, string $msg, string $date): void {
    header("Location: add_picklist.php?$type=" . urlencode($msg) . "&date=" . urlencode($date));
    exit();
}

function col_index_from_letters(string $letters): int {
    $index = 0;
    $letters = strtoupper($letters);
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

/**
 * Read all rows from an .xlsx using only ZipArchive + SimpleXML (no libraries).
 * Returns ALL rows: [ [col0, col1, ...], ... ]
 */
function read_xlsx_rows(string $filePath): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception("PHP extension 'zip' is not enabled. Enable extension=zip in php.ini, or import a CSV file instead.");
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Could not open the .xlsx file (it may be corrupted or not a real Excel file).");
    }

    // 1. Shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $xml = @simplexml_load_string($ssXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else {
                    foreach ($si->r as $run) { $text .= (string)$run->t; }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // 2. First worksheet path (with fallbacks)
    $sheetPath = '';
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wb   = @simplexml_load_string($wbXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $firstSheet = $wb->sheets->sheet[0];
            $rid = '';
            foreach ($firstSheet->attributes() as $aName => $aVal) {
                if ($aName === 'id') { $rid = (string)$aVal; }
            }
            if ($rid === '') {
                $rAttrs = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string)($rAttrs['id'] ?? '');
            }
            if ($rid !== '') {
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = (string)$rel['Target'];
                        if ($target !== '') {
                            if ($target[0] === '/') {
                                $sheetPath = ltrim($target, '/');
                            } elseif (strpos($target, 'xl/') === 0) {
                                $sheetPath = $target;
                            } else {
                                $sheetPath = 'xl/' . $target;
                            }
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
        if ($name && preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name)) {
            $candidates[] = $name;
        }
    }

    $sheetXml = false;
    foreach (array_unique($candidates) as $candidate) {
        $sheetXml = $zip->getFromName($candidate);
        if ($sheetXml !== false) break;
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new Exception("Could not read the worksheet inside the .xlsx file.");
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new Exception("Could not parse the worksheet data.");
    }

    // 3. Extract rows
    $rowsData = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        $cells    = [];
        $maxIndex = -1;

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

            $value = clean_number(trim($value));
            $cells[$colIndex] = $value;
            if ($colIndex > $maxIndex) $maxIndex = $colIndex;
        }

        if ($maxIndex < 0) continue;

        $ordered = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $ordered[] = $cells[$i] ?? '';
        }
        $rowsData[] = $ordered;
    }

    return $rowsData;
}

// =====================================================================
// MAIN
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_FILES['csv_file'])
    || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    redirect_back('error', 'Please choose a valid file.', date('Y-m-d'));
}

// 📅 Invoice date
 $import_date = trim($_POST['import_date'] ?? '');
 $dt = DateTime::createFromFormat('Y-m-d', $import_date);
if (!$dt || $dt->format('Y-m-d') !== $import_date) {
    redirect_back('error', 'Please select a valid invoice date.', date('Y-m-d'));
}
 $import_date = $dt->format('Y-m-d');

// File type
 $fileName = $_FILES['csv_file']['name'];
 $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'csv'])) {
    redirect_back('error', 'Only .xlsx or .csv files are allowed.', $import_date);
}

// 🎛️ Fixed column indexes
 $map = [
    'picklist_no'   => col_index_from_letters(COL_PICKLIST_NO),
    'order_no'      => col_index_from_letters(COL_ORDER_NO),
    'customer_name' => col_index_from_letters(COL_CUSTOMER_NAME),
    'warehouse'     => col_index_from_letters(COL_WAREHOUSE),
    'picker_name'   => col_index_from_letters(COL_PICKER_NAME),
    'checker_name'  => col_index_from_letters(COL_CHECKER_NAME),
];

 $inserted = 0;
 $skippedExisting = 0;   // already imported before (any date)
 $skippedEmpty    = 0;   // empty Picklist No
 $created_by = $_SESSION['user_id'];

try {
    // ---------- Read ALL rows ----------
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

    if (count($rows) === 0) {
        throw new Exception("No rows found in the file.");
    }

    $dataRows = array_slice($rows, DATA_START_ROW - 1);
    if (count($dataRows) === 0) {
        throw new Exception("No data found at or after row " . DATA_START_ROW . ".");
    }

    // ---------- Load Picklist Nos already in the DB ----------
    $existing = [];   // picklist_no => true

    if (SKIP_IF_PICKLIST_EXISTS_ANY_DATE) {
        // ⛔ Rule: exists on ANY date → skip (never import the same invoice twice)
        $res = $conn->query("SELECT DISTINCT picklist_no FROM picklists");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $existing[$r['picklist_no']] = true;
            }
        }
    } else {
        // Rule only for the selected date
        $chk = $conn->prepare("SELECT DISTINCT picklist_no FROM picklists WHERE pick_date = ?");
        if ($chk) {
            $chk->bind_param("s", $import_date);
            $chk->execute();
            $res = $chk->get_result();
            while ($r = $res->fetch_assoc()) {
                $existing[$r['picklist_no']] = true;
            }
            $chk->close();
        }
    }

    // ---------- Insert rows ----------
    $stmt = $conn->prepare("INSERT INTO picklists
        (picklist_no, order_no, customer_name, warehouse, picker_name, checker_name, pick_date, status, source, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'excel', ?)");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }

    $getVal = function (array $data, int $idx): string {
        return $idx >= 0 ? trim((string)($data[$idx] ?? '')) : '';
    };

    foreach ($dataRows as $data) {
        $picklist_no = $getVal($data, $map['picklist_no']);
        if ($picklist_no === '') { $skippedEmpty++; continue; }

        // ⛔ DUPLICATE RULE — checked BEFORE insert, so nothing ever aborts
        if (isset($existing[$picklist_no])) { $skippedExisting++; continue; }

        $order_no      = $getVal($data, $map['order_no']);
        $customer_name = $getVal($data, $map['customer_name']);
        $warehouse     = $getVal($data, $map['warehouse']);
        $picker_name   = $getVal($data, $map['picker_name']);
        $checker_name  = $getVal($data, $map['checker_name']);

        $stmt->bind_param("sssssssi",
            $picklist_no, $order_no, $customer_name,
            $warehouse, $picker_name, $checker_name,
            $import_date,
            $created_by
        );

        try {
            if ($stmt->execute()) {
                $inserted++;
                $existing[$picklist_no] = true;   // mark it for the rest of this file too
            } else {
                $skippedExisting++;
            }
        } catch (Throwable $e) {
            $skippedExisting++;   // safety net: skip, never abort
        }
    }
    $stmt->close();

} catch (Exception $e) {
    redirect_back('error', 'Import failed: ' . $e->getMessage(), $import_date);
}

// ---------- Log ----------
 $logCheck = $conn->query("SHOW TABLES LIKE 'import_logs'");
if ($logCheck && $logCheck->num_rows) {
    $log = $conn->prepare("INSERT INTO import_logs (file_name, total_inserted, total_skipped, import_date, imported_by)
                           VALUES (?, ?, ?, ?, ?)");
    if ($log) {
        $totalSkipped = $skippedExisting + $skippedEmpty;
        $log->bind_param("siisi", $fileName, $inserted, $totalSkipped, $import_date, $created_by);
        $log->execute();
        $log->close();
    }
}

 $msg = "Import finished: $inserted inserted, $skippedExisting skipped (already imported before), $skippedEmpty skipped (empty Picklist No).";
redirect_back('success', $msg, $import_date);