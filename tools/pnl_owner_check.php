<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.10): never deployed
/**
 * Step 6.10 - the owner gate, checked without a database or a network.
 *
 *   php tools/pnl_owner_check.php
 *
 * Middleware::isPnlOwner() decides who sees revenue, cost and profit. It must say yes to
 * the owner and no to everyone else - including a second admin, which is exactly the case
 * production has (dhanu and sudesh are both admins; only dhanu is the owner).
 */
require_once __DIR__ . '/../public_html/api/EnvLoader.php';
require_once __DIR__ . '/../public_html/api/Middleware.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = ($got === $want);
    if (!$ok) { $fail++; }
    printf("%-58s %-5s (want %s)  %s\n", $label, var_export($got, true), var_export($want, true), $ok ? 'OK' : 'FAIL');
}

$owner       = ['id' => 1, 'role' => 'admin',  'username' => 'dhanu',  'email' => 'admin@florencewithlocals.com'];
$otherAdmin  = ['id' => 5, 'role' => 'admin',  'username' => 'sudesh', 'email' => ''];
$viewer      = ['id' => 2, 'role' => 'viewer', 'username' => 'viewer', 'email' => 'Sudeshshiwanka25@gmail.com'];
$ownerByCase = ['id' => 1, 'role' => 'admin',  'username' => 'DhAnU',  'email' => ''];
$viewerNamedLikeOwner = ['id' => 9, 'role' => 'viewer', 'username' => 'dhanu', 'email' => ''];

echo "default owner list: " . implode(',', Middleware::pnlOwnerUsers()) . "\n\n";
check('owner (admin + on the list)',                 Middleware::isPnlOwner($owner), true);
check('second admin, not on the list',               Middleware::isPnlOwner($otherAdmin), false);
check('viewer',                                      Middleware::isPnlOwner($viewer), false);
check('owner username in a different case',          Middleware::isPnlOwner($ownerByCase), true);
check('viewer whose username matches the owner',     Middleware::isPnlOwner($viewerNamedLikeOwner), false);
check('no user at all',                              Middleware::isPnlOwner(null), false);
check('empty row',                                   Middleware::isPnlOwner([]), false);

// The list is configurable without a code change.
putenv('PNL_OWNER_USERS=someone.else@example.com');
$_ENV['PNL_OWNER_USERS'] = 'someone.else@example.com';
check('env override: owner drops off the list',      Middleware::isPnlOwner($owner), false);
check('env override: matches by email',              Middleware::isPnlOwner(['role' => 'admin', 'username' => 'x', 'email' => 'Someone.Else@example.com']), true);
unset($_ENV['PNL_OWNER_USERS']);
putenv('PNL_OWNER_USERS=');

echo "\n" . ($fail === 0 ? "ALL OK\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
