<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.10): never deployed
/**
 * Step 6.10 - READ ONLY. What each P&L endpoint answers for a given caller.
 *
 *   php tools/pnl_access_check.php --host=https://stagingwithlocals.deetech.cc \
 *       [--token=<64 hex>] [--label=viewer]
 *
 * Without --token it probes unauthenticated (expect 401 everywhere). GET requests only -
 * the POST routes are probed with an empty body, which the owner gate rejects before any
 * write can happen, and which the owner's own run reports as a validation error, not a write.
 */
$host = null; $token = null; $label = 'anonymous'; $withPost = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--host=(.+)$/', $a, $m)) { $host = rtrim($m[1], '/'); }
    elseif (preg_match('/^--token=([0-9a-f]{64})$/', $a, $m)) { $token = $m[1]; }
    elseif (preg_match('/^--label=(.+)$/', $a, $m)) { $label = $m[1]; }
    elseif ($a === '--with-post') { $withPost = true; }
}
if (!$host) { fwrite(STDERR, "--host is required\n"); exit(1); }

$targets = [
    ['GET',  '/api/pnl.php?date=2026-09-01',                       'day view'],
    ['GET',  '/api/pnl.php?start=2026-09-01&end=2026-09-30',       'month view'],
    ['GET',  '/api/pnl.php?action=settings',                       'rate & cost settings'],
    ['GET',  '/api/pnl.php?action=links&start=2026-09-01&end=2026-09-30', 'merged costing links'],
    ['GET',  '/api/pnl_links.php',                                 'helper, direct hit'],
];
if ($withPost) {
    $targets[] = ['POST', '/api/pnl.php?action=settings', 'settings write'];
    $targets[] = ['POST', '/api/pnl.php?action=costs',    'manual amount override'];
    $targets[] = ['POST', '/api/pnl.php?action=link',     'merge for costing'];
    $targets[] = ['POST', '/api/pnl.php?action=unlink',   'unmerge'];
}

function probe($host, $token, $method, $path) {
    $headers = "Accept: application/json\r\n";
    if ($token) { $headers .= "Authorization: Bearer $token\r\n"; }
    $opts = ['method' => $method, 'header' => $headers, 'timeout' => 60, 'ignore_errors' => true];
    if ($method === 'POST') {
        $opts['header'] .= "Content-Type: application/json\r\n";
        $opts['content'] = '{}';
    }
    $body = @file_get_contents($host . $path, false, stream_context_create(['http' => $opts]));
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
    $err = '';
    $j = json_decode((string) $body, true);
    if (is_array($j) && isset($j['error'])) { $err = is_string($j['error']) ? $j['error'] : json_encode($j['error']); }
    return [$code, $err];
}

printf("=== %s as %s ===\n", $host, $label);
printf("%-6s %-52s %-6s %s\n", 'method', 'endpoint', 'code', 'body.error');
echo str_repeat('-', 108) . "\n";
foreach ($targets as [$method, $path, $what]) {
    [$code, $err] = probe($host, $token, $method, $path);
    printf("%-6s %-52s %-6s %s\n", $method, $path, $code, $err !== '' ? $err : "($what)");
}
