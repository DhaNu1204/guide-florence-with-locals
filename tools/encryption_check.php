<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 1.6): never deployed
/**
 * Step 1.6 encryption check (no database):
 *   php tools/encryption_check.php
 * - encrypts a 32-char secret 200x and decrypts each -> expects 200/200
 * - isEncrypted() true for every output, false for a raw 32-char alphanumeric string
 * - legacy (un-prefixed) ciphertext still decrypts through ensureDecrypted()
 * - old plaintext passes through ensureDecrypted() unchanged
 * - initWithKey('') -> encrypt() throws (fail closed)
 */
require_once __DIR__ . '/../public_html/api/Encryption.php';

$failures = 0;
function check($label, $ok, $detail = '') { global $failures; if (!$ok) { $failures++; } printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : ''); }

$masterKey = base64_encode(random_bytes(32));
check('initWithKey(random 32-byte key) succeeds', Encryption::initWithKey($masterKey) === true);

$secret = substr(bin2hex(random_bytes(32)), 0, 32);
$encOk = 0; $decOk = 0; $prefixed = 0; $distinct = [];
for ($i = 0; $i < 200; $i++) {
    $c = Encryption::encrypt($secret);
    if (is_string($c) && $c !== '') { $encOk++; }
    if (Encryption::isEncrypted($c)) { $prefixed++; }
    $distinct[$c] = true;
    if (Encryption::decrypt($c) === $secret && Encryption::ensureDecrypted($c) === $secret) { $decOk++; }
}
check('200 encryptions produced ciphertext', $encOk === 200, "$encOk/200");
check('200 decryptions returned the secret', $decOk === 200, "$decOk/200");
check('isEncrypted() true for all 200 outputs', $prefixed === 200, "$prefixed/200");
check('random IV: all 200 ciphertexts distinct', count($distinct) === 200, count($distinct) . ' distinct');
check('isEncrypted() false for a raw 32-char alphanumeric string', Encryption::isEncrypted($secret) === false);
check('isEncrypted() false for base64-looking text without the prefix', Encryption::isEncrypted(base64_encode(random_bytes(64))) === false);
check('ensureDecrypted() of a raw value returns it unchanged (old plaintext row)', Encryption::ensureDecrypted($secret) === $secret);

$legacy = Encryption::encryptLegacy($secret);
check('legacy ciphertext is not flagged as current format', Encryption::isEncrypted($legacy) === false);
check('legacy ciphertext still decrypts via ensureDecrypted()', Encryption::ensureDecrypted($legacy) === $secret);
check('legacy ciphertext still decrypts via decrypt()', Encryption::decrypt($legacy) === $secret);

$tampered = Encryption::encrypt($secret); $tampered[strlen($tampered) - 3] = ($tampered[strlen($tampered) - 3] === 'A') ? 'B' : 'A';
check('tampered ciphertext fails (HMAC)', Encryption::decrypt($tampered) === false);

Encryption::initWithKey(base64_encode(random_bytes(32)));
check('ciphertext from another key does not decrypt', Encryption::decrypt($legacy) === false && Encryption::ensureDecrypted($tampered) === false);

check("initWithKey('') returns false", Encryption::initWithKey('') === false);
$threw = false;
try { Encryption::encrypt($secret); } catch (RuntimeException $e) { $threw = ($e->getMessage() === 'encryption_unavailable'); }
check("encrypt() throws 'encryption_unavailable' without a key (never returns plaintext)", $threw);
$threw = false;
try { Encryption::ensureEncrypted($secret); } catch (RuntimeException $e) { $threw = true; }
check('ensureEncrypted() throws without a key too', $threw);
check('decrypt() without a key returns false, not the input', Encryption::decrypt('enc:v1:' . base64_encode(random_bytes(64))) === false);

echo $failures === 0 ? "ALL OK\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
