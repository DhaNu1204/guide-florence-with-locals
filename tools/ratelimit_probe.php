<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only verification helper (step 1.3): never deployed, tools/ is not shipped
/**
 * Fire N login attempts at an API host and print the status codes.
 *
 *   php tools/ratelimit_probe.php --host=https://stagingwithlocals.deetech.cc --count=6 --username=probe-nobody
 *   php tools/ratelimit_probe.php --host=... --count=11 --username=<real user> --pause-every=5 --pause=61
 *
 * --host          base URL (no trailing slash)                    required
 * --count         number of attempts                              default 6
 * --username      username to try                                 default probe-nobody
 * --password      password to send (default: a wrong one)         default "wrong-password"
 * --password-stdin read the password from stdin (for a correct login)
 * --pause-every   pause after every N attempts (0 = never)        default 0
 * --pause         seconds to pause                                default 0
 * --no-forward    do not send a random X-Forwarded-For header
 *
 * Every request carries a random X-Forwarded-For (and X-Real-IP) unless --no-forward,
 * so a server that trusted those headers would hand out a fresh bucket each time.
 */
$opts = getopt('', ['host:', 'count::', 'username::', 'password::', 'password-stdin', 'pause-every::', 'pause::', 'no-forward']);
$host = rtrim($opts['host'] ?? '', '/');
if ($host === '') { fwrite(STDERR, "usage: --host=https://... [--count=N] [--username=U] [--password=P|--password-stdin] [--pause-every=N --pause=S] [--no-forward]\n"); exit(2); }
$count = max(1, (int) ($opts['count'] ?? 6));
$username = $opts['username'] ?? 'probe-nobody';
$password = $opts['password'] ?? 'wrong-password';
if (isset($opts['password-stdin'])) { $password = rtrim((string) stream_get_contents(STDIN), "\r\n"); }
$pauseEvery = (int) ($opts['pause-every'] ?? 0);
$pause = (int) ($opts['pause'] ?? 0);
$forward = !isset($opts['no-forward']);

$codes = [];
for ($n = 1; $n <= $count; $n++) {
    $fake = sprintf('%d.%d.%d.%d', mt_rand(11, 223), mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254));
    $headers = ['Content-Type: application/json', 'User-Agent: ratelimit-probe/1.3'];
    if ($forward) { $headers[] = "X-Forwarded-For: $fake"; $headers[] = "X-Real-IP: $fake"; }
    $ch = curl_init("$host/api/auth.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $password]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $hdr = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    $retry = preg_match('/^Retry-After:\s*(\d+)/mi', $hdr, $m) ? $m[1] : '-';
    $remaining = preg_match('/^X-RateLimit-Remaining:\s*(\d+)/mi', $hdr, $m) ? $m[1] : '-';
    $json = json_decode($body, true);
    $msg = is_array($json) ? ($json['message'] ?? ($json['error'] ?? '')) : substr(trim($body), 0, 40);
    $codes[] = $code;
    printf("%2d  %s  HTTP %d  retry-after=%s  remaining=%s  xff=%s  %s\n", $n, date('H:i:s'), $code, $retry, $remaining, $forward ? $fake : '-', $msg);
    if ($pauseEvery > 0 && $pause > 0 && $n % $pauseEvery === 0 && $n < $count) {
        printf("    ... pausing %d s\n", $pause);
        sleep($pause);
    }
}
echo 'codes: ' . implode(' ', $codes) . "\n";
