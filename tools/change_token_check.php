<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 4.11): never deployed
/**
 * Step 4.11 offline check for the pure change-token helpers: php tools/change_token_check.php
 */
require_once __DIR__ . '/../public_html/api/lib/change_token.php';
$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}
$tok = changeTokenMake(1791550800, 'a1b2c3d4e5');
check('make', $tok === '1791550800.a1b2c3d4e5', $tok);
check('parse round trip', changeTokenParse($tok) === ['t' => 1791550800, 'hash' => 'a1b2c3d4e5']);
check('unchanged answer is under 200 bytes', strlen(json_encode(['success' => true, 'data' => ['token' => $tok, 'changed' => false]])) < 200);
foreach ([null, '', 'x', '1791550800', '1791550800.A1B2C3D4E5', '1791550800.a1b2c3d4e', "1791550800.a1b2c3d4e5\n", ['a'], '1791550800.a1b2c3d4e5;DROP'] as $bad) {
    check('rejects ' . json_encode($bad), changeTokenParse($bad) === null);
}
echo $failures === 0 ? "ALL OK\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
