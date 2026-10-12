#!/bin/bash
# Step 6.18 - what the real API sends for the Viator account. Server-side, CLI only, never deployed.
# usage: viator_account_http_check.sh <site dir> <host url> <start Y-m-d> <end Y-m-d> <tour id>...
# Tokens are minted and deleted on the server (smoke_tokens.php); nothing is written to tours.
set -u
API=~/domains/deetech.cc/public_html/$1/api; HOST=$2; S=$3; E=$4; shift 4
PHP=/opt/alt/php82/usr/bin/php
OUT=$(FWL_API_DIR=$API php ~/backups/smoke_tokens.php)
A=$(echo "$OUT" | sed -n 's/^ADMIN=//p'); V=$(echo "$OUT" | sed -n 's/^VIEWER=//p')
trap 'FWL_API_DIR=$API php ~/backups/smoke_tokens.php --delete $A $V' EXIT
TMP=$(mktemp); trap 'rm -f $TMP; FWL_API_DIR=$API php ~/backups/smoke_tokens.php --delete $A $V' EXIT

echo "== Tours list view $S..$E (Viator rows) =="
sleep 2; curl -s -m 60 -H "Authorization: Bearer $A" "$HOST/api/tours.php?view=list&start_date=$S&end_date=$E&per_page=500" > $TMP
$PHP -r '$j = json_decode(file_get_contents($argv[1]), true); $n = [];
  foreach (($j["data"] ?? []) as $t) { if (stripos((string)$t["booking_channel"], "viator") === false) continue;
    $a = $t["viator_account"] ?? "null"; $n[$a] = ($n[$a] ?? 0) + 1;
    if ($a !== "legacy") printf("  t%d %s %s %s channel=%s viator_account=%s\n", $t["id"], $t["external_id"] ?? "(hand-entered)", $t["date"], substr($t["time"],0,5), $t["booking_channel"], $a); }
  echo "  counts: " . json_encode($n) . "\n";' $TMP

for ID in "$@"; do
  sleep 2; curl -s -m 60 -H "Authorization: Bearer $A" "$HOST/api/tours.php/$ID?view=full" > $TMP
  $PHP -r '$j = json_decode(file_get_contents($argv[1]), true); $t = $j["data"] ?? [];
    printf("== Booking Details t%s: %s %s channel=%s viator_account=%s\n", $argv[2], $t["external_id"] ?? "(hand-entered)", $t["date"] ?? "?", $t["booking_channel"] ?? "?", var_export($t["viator_account"] ?? null, true));' $TMP $ID
done

echo "== Daily P&L channel lines, day $S (single-day view) =="
sleep 2; curl -s -m 90 -H "Authorization: Bearer $A" "$HOST/api/pnl.php?date=$S" > $TMP
$PHP -r '$s = file_get_contents($argv[1]); preg_match_all("/\"(Viator[^\"]*)\"/", $s, $m); $c = array_count_values($m[1]); ksort($c);
  echo "  " . (json_encode($c) ?: "none") . "  (bytes " . strlen($s) . ")\n";' $TMP
