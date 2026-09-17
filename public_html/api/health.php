<?php
/**
 * health.php - unauthenticated deploy / uptime probe (step 0.2).
 *
 * GET only, no auth, no rate limit. Returns
 *   {ok:true|false, db:true|false, env:<APP_ENV>, sha:<git sha>, env_source:<where .env was read>, time:<UTC>}
 * - db  = a "SELECT 1" on the configured database succeeds.
 * - sha = contents of public_html/api/VERSION (written by scripts/deploy.sh at
 *         deploy time, git rev-parse HEAD); "unknown" when the file is missing.
 * Never exposes DB names, paths or the PHP version.
 *
 * Deliberately does NOT include config.php: config.php exits with a 500 when the
 * DB is unreachable, but this probe must still answer (with db:false, HTTP 503).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/EnvLoader.php';
EnvLoader::load();

$env = strtolower(trim((string) EnvLoader::get('APP_ENV', 'unknown')));
if ($env === '') {
    $env = 'unknown';
}

$sha = 'unknown';
$versionFile = __DIR__ . '/VERSION';
if (is_readable($versionFile)) {
    $v = trim((string) file_get_contents($versionFile));
    if (preg_match('/^[0-9a-f]{7,40}$/', $v)) {
        $sha = $v;
    }
}

$db = false;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(
        (string) EnvLoader::get('DB_HOST', 'localhost'),
        (string) EnvLoader::get('DB_USER', 'root'),
        (string) EnvLoader::get('DB_PASS', ''),
        (string) EnvLoader::get('DB_NAME', 'florence_guides')
    );
    if (!$conn->connect_error) {
        $res = @$conn->query('SELECT 1');
        $db = ($res !== false);
        $conn->close();
    }
} catch (\Throwable $e) {
    $db = false;
}

http_response_code($db ? 200 : 503);
echo json_encode([
    'ok'   => $db,
    'db'   => $db,
    'env'  => $env,
    'sha'  => $sha,
    'env_source' => EnvLoader::source(), // step 2.2: outside_webroot | inside_webroot | none (no path)
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
]);
