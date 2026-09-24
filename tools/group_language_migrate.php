<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.12): never deployed
/**
 * Step 6.12 - one-off: give every auto group on/after GROUP_LANGUAGE_KEY_FROM its language.
 *
 *   FWL_API_DIR=/path/to/api php tools/group_language_migrate.php            (dry run, writes nothing)
 *   FWL_API_DIR=/path/to/api php tools/group_language_migrate.php --apply
 *
 * - single-language auto group: keeps its id, guide, payments and links; only bucket_key is
 *   rewritten in place ("961801|2026-09-25|14:30" -> "961801|2026-09-25|14:30|English").
 * - mixed-language auto group: split. The language the guide speaks keeps the id (no guide: the
 *   larger PAX). Every other language becomes a new group (or a loose booking when it is a
 *   single one); a member that only carried the group's guide by propagation loses it.
 * - NOT split, listed for the owner: a payment recorded on any member, a guide whose language
 *   cannot be matched to exactly one of the group's languages, or no half left with 2 bookings.
 *   The sync leaves these alone (mixedLanguageAutoGroupIds) until he decides.
 * - manual merges and dates before GROUP_LANGUAGE_KEY_FROM: not read, not touched.
 * - pnl_tour_costs / pnl_unit_links rows of a rewritten group get the same new bucket_key.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/group_helpers.php';
require_once $apiDir . '/tour_classification.php';

require_once __DIR__ . '/group_language_migrate_lib.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
echo ($apply ? 'APPLY' : 'DRY RUN') . ' - auto groups dated ' . GROUP_LANGUAGE_KEY_FROM . " and later\n";
$out = groupLanguageMigrate($conn, $apply);
if ($out === null) { fwrite(STDERR, "auto_group lock busy (a sync is grouping) - try again\n"); exit(1); }
list($stats, $report) = $out;
foreach ($report as $l) { echo $l, "\n"; }
foreach ($stats as $k => $v) { echo "$k=$v "; }
echo "\n";
