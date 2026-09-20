<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.1): never deployed
/**
 * Step 6.1 - normalise tours.language onto one canonical spelling and fill the empties that a
 * language can be derived for.
 *
 *   FWL_API_DIR=... php tools/language_normalise.php            DRY RUN - prints the plan only
 *   FWL_API_DIR=... php tools/language_normalise.php --apply    writes
 *
 * Nothing is written without --apply, and the ids of every row it would change are saved first.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/tour_classification.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$saveTo = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--save=(.+)$/', $a, $m)) { $saveTo = $m[1]; }
}

echo "=== step 6.1 language normalisation (" . ($apply ? 'APPLY' : 'dry run') . ") ===\n";
echo "db: " . $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'] . "\n\n";

// --- 1. the mapping table: every stored value -> its canonical form -------------------------
echo "--- mapping: what is stored now -> what it becomes ---\n";
$res = $conn->query("SELECT language, COUNT(*) n FROM tours
                     WHERE language IS NOT NULL AND language <> ''
                     GROUP BY BINARY language ORDER BY n DESC");
$changes = [];
$unchanged = 0;
while ($r = $res->fetch_assoc()) {
    $canon = tourLanguageCanonical($r['language']);
    $same = ($canon === $r['language']);
    if ($same) { $unchanged += (int) $r['n']; }
    else { $changes[] = ['from' => $r['language'], 'to' => $canon, 'n' => (int) $r['n']]; }
    printf("  %-22s -> %-14s %6s rows%s\n", "'" . $r['language'] . "'", (string) $canon, $r['n'],
        $same ? '   (already canonical)' : '   *** CHANGES ***');
}
printf("\n  rows already canonical: %d | rows that would change: %d\n",
    $unchanged, array_sum(array_column($changes, 'n')));

// --- 2. the empties that can be filled from the payload -------------------------------------
echo "\n--- empty rows that a language can be derived for ---\n";
$fill = [];
$res = $conn->query("SELECT id, title, participants, bokun_data FROM tours
                     WHERE (language IS NULL OR language = '') AND bokun_data IS NOT NULL AND bokun_data <> ''");
while ($row = $res->fetch_assoc()) {
    $d = deriveListFields($row['bokun_data'], $row['participants'], null, $row['title']);
    if (!empty($d['language'])) { $fill[(int) $row['id']] = $d['language']; }
}
$byLang = array_count_values($fill);
arsort($byLang);
printf("  fillable: %d\n", count($fill));
foreach ($byLang as $k => $v) { printf("      %-14s %s\n", $k, $v); }
$stillEmpty = (int) $conn->query("SELECT COUNT(*) n FROM tours WHERE language IS NULL OR language = ''")
    ->fetch_assoc()['n'] - count($fill);
printf("  would stay Unknown: %d\n", $stillEmpty);

if ($saveTo) {
    $lines = ["id\tcurrent_language\tnew_language"];
    foreach ($changes as $c) {
        $q = $conn->query("SELECT id FROM tours WHERE BINARY language = '" . $conn->real_escape_string($c['from']) . "'");
        while ($x = $q->fetch_assoc()) { $lines[] = $x['id'] . "\t" . $c['from'] . "\t" . $c['to']; }
    }
    foreach ($fill as $id => $lang) { $lines[] = $id . "\t\t" . $lang; }
    file_put_contents($saveTo, implode("\n", $lines) . "\n");
    chmod($saveTo, 0600);
    printf("\n  saved %d affected row ids to %s\n", count($lines) - 1, $saveTo);
}

if (!$apply) {
    echo "\ndry run - nothing was written. Re-run with --apply to write.\n";
    exit(0);
}

// --- 3. write ---------------------------------------------------------------------------------
$normalised = 0;
foreach ($changes as $c) {
    $stmt = $conn->prepare("UPDATE tours SET language = ? WHERE BINARY language = ?");
    $stmt->bind_param('ss', $c['to'], $c['from']);
    $stmt->execute();
    $normalised += $stmt->affected_rows;
    $stmt->close();
}
$filled = 0;
$stmt = $conn->prepare("UPDATE tours SET language = ? WHERE id = ? AND (language IS NULL OR language = '')");
foreach ($fill as $id => $lang) {
    $stmt->bind_param('si', $lang, $id);
    $stmt->execute();
    $filled += $stmt->affected_rows;
}
$stmt->close();

printf("\nnormalised %d row(s), filled %d row(s)\n", $normalised, $filled);
echo "--- the column now holds ---\n";
$res = $conn->query("SELECT COALESCE(NULLIF(TRIM(language), ''), '(none)') l, COUNT(*) n FROM tours GROUP BY l ORDER BY n DESC");
while ($r = $res->fetch_assoc()) { printf("  %-14s %s\n", $r['l'], $r['n']); }
