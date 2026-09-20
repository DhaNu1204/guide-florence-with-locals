<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.1): never deployed
/**
 * Step 6.1 measurement - READ ONLY. What is actually in tours.language today, how messy it is,
 * and how many of the empty ones could be filled from the stored Bokun payload.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/config.php';
require_once $apiDir . '/tour_classification.php';

function rows($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) { echo "  ! " . $conn->error . "\n"; return []; }
    $o = [];
    while ($x = $r->fetch_assoc()) { $o[] = $x; }
    return $o;
}
function one($conn, $sql) { $r = rows($conn, $sql); return $r ? array_values($r[0])[0] : null; }

echo "=== step 6.1: tours.language audit ===\n";
echo "db: " . one($conn, "SELECT DATABASE()") . "   now(utc): " . gmdate('c') . "\n\n";

$total = (int) one($conn, "SELECT COUNT(*) FROM tours");
$upcoming = (int) one($conn, "SELECT COUNT(*) FROM tours WHERE date >= CURDATE()");
printf("rows total %d | upcoming (date >= today) %d\n", $total, $upcoming);
printf("with a non-empty language : total %s | upcoming %s\n",
    one($conn, "SELECT COUNT(*) FROM tours WHERE language IS NOT NULL AND language <> ''"),
    one($conn, "SELECT COUNT(*) FROM tours WHERE date >= CURDATE() AND language IS NOT NULL AND language <> ''"));
printf("NULL or empty             : total %s | upcoming %s\n",
    one($conn, "SELECT COUNT(*) FROM tours WHERE language IS NULL OR language = ''"),
    one($conn, "SELECT COUNT(*) FROM tours WHERE date >= CURDATE() AND (language IS NULL OR language = '')"));

echo "\n--- distinct values EXACTLY as stored (binary compare, so case shows up) ---\n";
foreach (rows($conn, "SELECT language, COUNT(*) n,
                             SUM(date >= CURDATE()) upcoming,
                             MIN(date) first_seen, MAX(date) last_seen
                      FROM tours
                      WHERE language IS NOT NULL AND language <> ''
                      GROUP BY BINARY language ORDER BY n DESC") as $r) {
    printf("  %-24s %6s rows (%4s upcoming)  %s .. %s\n",
        "'" . $r['language'] . "'", $r['n'], $r['upcoming'], $r['first_seen'], $r['last_seen']);
}

echo "\n--- how messy? ---\n";
printf("  distinct values, case-sensitive   : %s\n", one($conn, "SELECT COUNT(DISTINCT BINARY language) FROM tours WHERE language IS NOT NULL AND language <> ''"));
printf("  distinct values, case-insensitive : %s\n", one($conn, "SELECT COUNT(DISTINCT LOWER(language)) FROM tours WHERE language IS NOT NULL AND language <> ''"));
printf("  values with surrounding spaces    : %s\n", one($conn, "SELECT COUNT(*) FROM tours WHERE language <> TRIM(language)"));
printf("  values shorter than 3 chars (codes like EN/IT) : %s\n", one($conn, "SELECT COUNT(*) FROM tours WHERE language IS NOT NULL AND CHAR_LENGTH(TRIM(language)) BETWEEN 1 AND 2"));
printf("  values longer than 20 chars (free text)        : %s\n", one($conn, "SELECT COUNT(*) FROM tours WHERE CHAR_LENGTH(language) > 20"));

echo "\n--- could the empty ones be filled from bokun_data? ---\n";
$res = $conn->query("SELECT id, title, participants, bokun_data FROM tours
                     WHERE (language IS NULL OR language = '') AND bokun_data IS NOT NULL AND bokun_data <> ''");
$fillable = [];
$unknown = 0;
while ($row = $res->fetch_assoc()) {
    $d = deriveListFields($row['bokun_data'], $row['participants'], null, $row['title']);
    if (!empty($d['language'])) { $fillable[$d['language']] = ($fillable[$d['language']] ?? 0) + 1; }
    else { $unknown++; }
}
$fillTotal = array_sum($fillable);
printf("  empty rows WITH a payload: %s\n", one($conn, "SELECT COUNT(*) FROM tours WHERE (language IS NULL OR language = '') AND bokun_data IS NOT NULL AND bokun_data <> ''"));
printf("  ... a language can be derived for %d of them:\n", $fillTotal);
arsort($fillable);
foreach ($fillable as $k => $v) { printf("      %-14s %s\n", $k, $v); }
printf("  ... and %d would stay unknown\n", $unknown);
printf("  empty rows with NO payload (manual tours): %s\n",
    one($conn, "SELECT COUNT(*) FROM tours WHERE (language IS NULL OR language = '') AND (bokun_data IS NULL OR bokun_data = '')"));

echo "\n--- groups whose members disagree (a mixed departure) ---\n";
foreach (rows($conn, "SELECT COUNT(*) n FROM (
                        SELECT t.group_id FROM tours t
                        WHERE t.group_id IS NOT NULL AND t.cancelled = 0
                        GROUP BY t.group_id
                        HAVING COUNT(DISTINCT NULLIF(TRIM(LOWER(t.language)), '')) > 1) x") as $r) {
    printf("  groups with more than one language among their members: %s\n", $r['n']);
}
foreach (rows($conn, "SELECT t.group_id, GROUP_CONCAT(DISTINCT t.language ORDER BY t.language SEPARATOR ', ') langs, COUNT(*) members
                      FROM tours t WHERE t.group_id IS NOT NULL AND t.cancelled = 0
                      GROUP BY t.group_id
                      HAVING COUNT(DISTINCT NULLIF(TRIM(LOWER(t.language)), '')) > 1
                      ORDER BY t.group_id DESC LIMIT 5") as $r) {
    printf("    group %-9s %s members -> %s\n", $r['group_id'], $r['members'], $r['langs']);
}

echo "\ndone (nothing was written)\n";
