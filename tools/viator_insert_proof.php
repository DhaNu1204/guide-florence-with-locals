<?php
// Step 6.9 item 2 proof, second half - what label a NEWLY INSERTED Viator booking gets.
// Staging only: it deletes one staging row and lets the real sync re-create it. CLI, never deployed.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
$apiDir = getenv('FWL_API_DIR');
require_once $apiDir . '/config.php';
require_once $apiDir . '/viator_helpers.php';
if (strpos((string) ($GLOBALS['db_name'] ?? ''), '_stg') === false) {
    fwrite(STDERR, "refusing: not the staging database\n"); exit(1);
}
define('BOKUN_SYNC_LIB', true);
require_once $apiDir . '/bokun_sync.php';

$today = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$horizon = (new DateTime('+55 days', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$r = $conn->query("SELECT id, external_id, date, viator_account FROM tours
                    WHERE viator_account='legacy' AND cancelled=0 AND date >= '$today' AND date <= '$horizon'
                    ORDER BY date LIMIT 1");
$row = $r->fetch_assoc();
if (!$row) { fwrite(STDERR, "no candidate\n"); exit(1); }
echo "victim: id={$row['id']} {$row['external_id']} on {$row['date']} (currently {$row['viator_account']})\n\n";

function reinsert($conn, $row, $label) {
    global $apiDir;
    // delete by REFERENCE, not by the id we first saw: a re-inserted row gets a new id
    $conn->query("DELETE FROM tours WHERE external_id = '" . $conn->real_escape_string($row['external_id']) . "'");
    $gone = $conn->query("SELECT COUNT(*) FROM tours WHERE external_id = '" . $conn->real_escape_string($row['external_id']) . "'")->fetch_row()[0];
    printf("  deleted (rows with that reference now: %s), re-syncing %s ...\n", $gone, $row['date']);
    syncBookings($row['date'], $row['date'], 'manual', 'step-6.9-insert-proof');
    $r = $conn->query("SELECT id, viator_account FROM tours WHERE external_id = '" . $conn->real_escape_string($row['external_id']) . "'");
    $back = $r->fetch_assoc();
    printf("  came back as: id=%s viator_account=%s   -> expected %s  %s\n\n",
        $back['id'] ?? 'MISSING', var_export($back['viator_account'] ?? null, true), $label,
        (($back['viator_account'] ?? null) === $label) ? 'PASS' : '*** FAIL ***');
    return ($back['viator_account'] ?? null) === $label;
}

ensureViatorSwitchTable($conn);
$ok = true;

echo "A) no cutover recorded - he has not switched yet, so a Viator insert is still LEGACY\n";
$conn->query("UPDATE viator_switch SET cutover_at = NULL WHERE id = 1");
$ok = reinsert($conn, $row, VIATOR_ACCOUNT_LEGACY) && $ok;

echo "B) cutover recorded in the past - a Viator insert created after it is CURRENT\n";
$conn->query("UPDATE viator_switch SET cutover_at = '2020-01-01 00:00:00' WHERE id = 1");
$ok = reinsert($conn, $row, VIATOR_ACCOUNT_CURRENT) && $ok;

echo "C) put staging back: cutover cleared, the row stamped legacy again\n";
$conn->query("UPDATE viator_switch SET cutover_at = NULL WHERE id = 1");
$conn->query("UPDATE tours SET viator_account = 'legacy' WHERE external_id = '" . $conn->real_escape_string($row['external_id']) . "'");
$r = $conn->query("SELECT viator_account FROM tours WHERE external_id = '" . $conn->real_escape_string($row['external_id']) . "'");
printf("  restored to: %s, cutover: %s\n", $r->fetch_row()[0], var_export(viatorCutoverAt($conn), true));

echo "\n" . ($ok ? "PASS\n" : "FAIL\n");
exit($ok ? 0 : 1);
