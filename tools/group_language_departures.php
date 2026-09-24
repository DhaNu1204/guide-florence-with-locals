<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.12): never deployed
/**
 * Step 6.12 - READ ONLY. Every upcoming departure (product, date, time) whose active bookings
 * speak more than one language, and how it is grouped now: one line per booking.
 *
 *   FWL_API_DIR=/path/to/api php tools/group_language_departures.php [--from=YYYY-MM-DD]
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
$conn->query("SET SESSION TRANSACTION READ ONLY");

$from = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $a, $m)) { $from = $m[1]; }
}

$sql = "SELECT t.product_id, t.date, TIME_FORMAT(t.time, '%H:%i') hm, t.id, t.language, t.participants, t.group_id,
               tg.is_manual_merge, tg.guide_id, tg.bucket_key, t.is_private
          FROM tours t
          LEFT JOIN tour_groups tg ON tg.id = t.group_id
          JOIN (SELECT product_id, date, time FROM tours
                 WHERE date >= ? AND cancelled = 0 AND product_id IS NOT NULL AND language IS NOT NULL AND language <> ''
                 GROUP BY product_id, date, time HAVING COUNT(DISTINCT language) > 1) d
            ON d.product_id = t.product_id AND d.date = t.date AND d.time = t.time
         WHERE t.cancelled = 0
         ORDER BY t.date, t.time, t.product_id, t.language, t.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $from);
$stmt->execute();
$res = $stmt->get_result();
$n = 0; $last = null;
while ($r = $res->fetch_assoc()) {
    $dep = $r['product_id'] . ' ' . $r['date'] . ' ' . $r['hm'];
    if ($dep !== $last) { echo "\n$dep\n"; $last = $dep; $n++; }
    printf("  t%-6d %-8s %d pax  %s\n", $r['id'], $r['language'], $r['participants'],
        $r['group_id'] ? ('g' . $r['group_id'] . ($r['is_manual_merge'] ? ' manual' : ' auto') . ($r['guide_id'] ? ' guide' : '') . ' ' . $r['bucket_key'])
                       : ($r['is_private'] ? 'private (never grouped)' : 'on its own'));
}
echo "\n$n upcoming departure(s) with more than one language\n";

