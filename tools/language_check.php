<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only check (step 6.1): never deployed
/**
 * Step 6.1 unit tests for the canonical language value (no database):
 *   php tools/language_check.php
 */
require_once __DIR__ . '/../public_html/api/tour_classification.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) { $failures++; }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

// --- the five values production actually holds pass through untouched -------------------------
foreach (['English', 'Italian', 'Spanish', 'French', 'German'] as $v) {
    check("'$v' is already canonical", tourLanguageCanonical($v) === $v, (string) tourLanguageCanonical($v));
}

// --- nothing usable -> NULL, which the UI shows as "Unknown" ----------------------------------
foreach ([null, '', '   ', 123, []] as $v) {
    check('empty/none -> null (filterable as Unknown)', tourLanguageCanonical($v) === null, var_export($v, true));
}

// --- codes and other spellings fold in ---------------------------------------------------------
$cases = [
    'EN' => 'English', 'en' => 'English', 'eng' => 'English', 'english' => 'English', 'Inglese' => 'English',
    'IT' => 'Italian', 'italiano' => 'Italian', ' italian ' => 'Italian',
    'ES' => 'Spanish', 'espanol' => 'Spanish', 'Spagnolo' => 'Spanish',
    'FR' => 'French', 'francais' => 'French', 'Francese' => 'French',
    'DE' => 'German', 'deutsch' => 'German', 'Tedesco' => 'German',
    'PT' => 'Portuguese', 'portugues' => 'Portuguese',
];
foreach ($cases as $in => $want) {
    check("'$in' -> $want", tourLanguageCanonical($in) === $want, (string) tourLanguageCanonical($in));
}

// --- a phrase keeps only the language ------------------------------------------------------------
check("'English guided tour' -> English", tourLanguageCanonical('English guided tour') === 'English');
check("'Tour in Spanish' -> Spanish", tourLanguageCanonical('Tour in Spanish') === 'Spanish');

// --- something unknown is kept, but in one predictable shape --------------------------------------
check("an unknown language is kept, title-cased", tourLanguageCanonical('dutch') === 'Dutch', (string) tourLanguageCanonical('dutch'));
check("... and a multi-word value keeps its first word only",
    tourLanguageCanonical('dutch, flemish') === 'Dutch', (string) tourLanguageCanonical('dutch, flemish'));

// --- the function is idempotent: running it twice changes nothing ----------------------------------
foreach (['EN', 'espanol', 'English', 'dutch', ' italian '] as $v) {
    $once = tourLanguageCanonical($v);
    check("idempotent for '$v'", tourLanguageCanonical($once) === $once, (string) $once);
}

// --- deriveListFields returns the canonical form too ------------------------------------------------
$payload = json_encode(['productBookings' => [['fields' => ['language' => 'ES', 'totalParticipants' => 3]]]]);
$d = deriveListFields($payload, 0, null, '');
check('deriveListFields canonicalises what it finds', $d['language'] === 'Spanish', (string) $d['language']);
$d2 = deriveListFields(json_encode(['productBookings' => [[]]]), 2, 'EN', 'Uffizi tour');
check('... and canonicalises the stored value it is given', $d2['language'] === 'English', (string) $d2['language']);

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
