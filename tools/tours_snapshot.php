<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 3.4): never deployed
/**
 * Step 3.4 data-identity proof - READ ONLY. Writes one line per tour holding a short checksum of
 * EVERY column, so two snapshots taken around a sync can be diffed column by column:
 *     <id>\t<md5_8 of column 1>\t<md5_8 of column 2>\t...
 * The header line lists the column names in the same order.
 *
 *   php tools/tours_snapshot.php --out=/path/file.tsv
 *   FWL_API_DIR=/path/to/api php tools/tours_snapshot.php --out=...
 *   php tools/tours_snapshot.php --diff=before.tsv,after.tsv
 *
 * `last_sync` and `updated_at` are stamped by every sync by design; the diff reports them
 * separately from the columns that carry data.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';

$out = null; $diff = null; $listIds = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) { $out = $m[1]; }
    elseif (preg_match('/^--diff=([^,]+),(.+)$/', $arg, $m)) { $diff = [$m[1], $m[2]]; }
    elseif ($arg === '--ids') { $listIds = true; }
}

$STAMPS = ['last_sync', 'updated_at'];

if ($diff) {
    $load = function ($path) {
        $cols = []; $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line !== '' && $line[0] === '#') {
                if (strpos($line, "#columns\t") === 0) {
                    $cols = array_slice(explode("\t", $line), 1);
                }
                continue;
            }
            $p = explode("\t", $line);
            $rows[array_shift($p)] = $p;
        }
        return [$cols, $rows];
    };
    list($colsA, $a) = $load($diff[0]);
    list($colsB, $b) = $load($diff[1]);
    if ($colsA !== $colsB) { fwrite(STDERR, "column lists differ between the two snapshots\n"); exit(1); }

    $perCol = array_fill_keys($colsA, 0);
    $changedRows = []; $stampOnly = 0;
    foreach ($a as $id => $rowA) {
        if (!isset($b[$id])) { continue; }
        $rowB = $b[$id];
        $dataChanged = false; $anyChanged = false;
        foreach ($colsA as $i => $col) {
            if (($rowA[$i] ?? '') !== ($rowB[$i] ?? '')) {
                $perCol[$col]++;
                $anyChanged = true;
                if (!in_array($col, $STAMPS, true)) { $dataChanged = true; }
            }
        }
        if ($dataChanged) { $changedRows[] = $id; }
        elseif ($anyChanged) { $stampOnly++; }
    }
    $added = array_diff_key($b, $a);
    $removed = array_diff_key($a, $b);

    printf("rows before %d | after %d | new %d | gone %d\n", count($a), count($b), count($added), count($removed));
    printf("rows whose DATA changed                   : %d\n", count($changedRows));
    printf("rows where only last_sync/updated_at moved: %d\n", $stampOnly);
    echo "columns that changed (rows affected):\n";
    arsort($perCol);
    foreach ($perCol as $col => $n) {
        if ($n > 0) { printf("  %-24s %6d%s\n", $col, $n, in_array($col, $STAMPS, true) ? '   (sync stamp, expected)' : ''); }
    }
    if ($listIds && $changedRows) { echo "ids: " . implode(',', $changedRows) . "\n"; }
    if ($added) { echo "new ids: " . implode(',', array_slice(array_keys($added), 0, 300)) . "\n"; }
    if ($removed) { echo "gone ids: " . implode(',', array_slice(array_keys($removed), 0, 300)) . "\n"; }
    exit(0);
}

require_once $apiDir . '/config.php';
if (!$out) { fwrite(STDERR, "--out=<file> is required\n"); exit(1); }

$cols = [];
$res = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'tours' ORDER BY ORDINAL_POSITION");
while ($r = $res->fetch_assoc()) { $cols[] = $r['COLUMN_NAME']; }

$select = [];
foreach ($cols as $i => $c) {
    $select[] = "LEFT(MD5(IFNULL(`$c`, '~NULL~')), 8) c$i";
}

$fh = fopen($out, 'w');
fwrite($fh, "# tours snapshot " . gmdate('c') . " db=" . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "\n");
fwrite($fh, "#columns\t" . implode("\t", $cols) . "\n");
$n = 0;
$res = $conn->query("SELECT id, " . implode(', ', $select) . " FROM tours ORDER BY id");
while ($r = $res->fetch_assoc()) {
    $line = $r['id'];
    foreach ($cols as $i => $c) { $line .= "\t" . $r['c' . $i]; }
    fwrite($fh, $line . "\n");
    $n++;
}
fclose($fh);
echo "snapshot: $n rows, " . count($cols) . " columns -> $out\n";
