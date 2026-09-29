<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.5): never deployed
/**
 * Step 7.5 - staging verification of the confirm path, over real HTTP, exactly as the card calls it:
 * PUT tour-groups.php / tours.php with expected_previous_guide_id, then assistant.php?action=assign_done,
 * undo the same way (+ undo_done). Also: stale card -> 409, viewer -> 403. Everything ends where it began.
 *
 *   FWL_API_DIR=<api dir> php82 tools/assign_verify.php --host=https://stagingwithlocals... \
 *       --admin=<raw token> --viewer=<raw token> --group=<id> --group-guide=<id> --single=<id> --single-guide=<id>
 */
$apiDir = getenv('FWL_API_DIR');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cli';
require $apiDir . '/config.php';
$o = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) $o[$m[1]] = $m[2];
if (strcasecmp((string) EnvLoader::get('APP_ENV', ''), 'staging') !== 0 || stripos($o['host'] ?? '', 'staging') === false) { fwrite(STDERR, "staging only\n"); exit(2); }

function http($method, $path, $token, $body = null) {
    global $o;
    sleep(2); // load rule
    $ch = curl_init($o['host'] . '/api/' . $path);
    $h = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'User-Agent: Mozilla/5.0 (FWL step 7.5 verify)'];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string) $r, true)];
}
function groupState($gid) {
    global $conn;
    $g = $conn->query("SELECT guide_id FROM tour_groups WHERE id = " . (int) $gid)->fetch_assoc();
    $m = [];
    $r = $conn->query("SELECT id, guide_id FROM tours WHERE group_id = " . (int) $gid . " ORDER BY id");
    while ($x = $r->fetch_assoc()) $m[] = $x['guide_id'] === null ? 'null' : (int) $x['guide_id'];
    return ['group' => $g['guide_id'] === null ? null : (int) $g['guide_id'], 'members' => $m];
}
function tourGuide($tid) {
    global $conn;
    $x = $conn->query("SELECT guide_id FROM tours WHERE id = " . (int) $tid)->fetch_assoc();
    return $x['guide_id'] === null ? null : (int) $x['guide_id'];
}
function actions($since) {
    global $conn;
    $o = [];
    $r = $conn->query("SELECT id, action, departure_id, from_guide_id, to_guide_id, whatsapp_sent, whatsapp_result, undo_of, undone_at FROM assistant_actions WHERE id > " . (int) $since . " ORDER BY id");
    while ($x = $r->fetch_assoc()) $o[] = $x;
    return $o;
}
$row = function ($label, $expect, $got, $ok) { printf("%-4s %-52s expect %-34s got %s\n", $ok ? 'PASS' : 'FAIL', $label, $expect, $got); };
$A = $o['admin']; $V = $o['viewer'];
$gid = (int) $o['group']; $newG = (int) $o['group-guide'];
$tid = (int) $o['single']; $newT = (int) $o['single-guide'];
$startId = (int) $conn->query("SELECT COALESCE(MAX(id), 0) m FROM assistant_actions")->fetch_assoc()['m'];

// ---- group ----
$g0 = groupState($gid);
$prev = $g0['group'];
echo "group $gid before: " . json_encode($g0) . "\n";
list($c, $b) = http('PUT', "tour-groups.php/$gid", $V, ['guide_id' => $newG, 'expected_previous_guide_id' => $prev]);
$row('viewer confirms (group PUT)', '403, unchanged', "$c, " . (groupState($gid) == $g0 ? 'unchanged' : 'CHANGED'), $c === 403 && groupState($gid) == $g0);
list($c, $b) = http('PUT', "tour-groups.php/$gid", $A, ['guide_id' => $newG, 'expected_previous_guide_id' => $newG === 1 ? 2 : 1]);
$row('stale card (wrong expected_previous_guide_id)', '409 departure_changed, unchanged', "$c " . ($b['error'] ?? '') . ', ' . (groupState($gid) == $g0 ? 'unchanged' : 'CHANGED'), $c === 409 && ($b['error'] ?? '') === 'departure_changed' && groupState($gid) == $g0);
list($c, $b) = http('PUT', "tour-groups.php/$gid", $A, ['guide_id' => $newG, 'expected_previous_guide_id' => $prev]);
$g1 = groupState($gid);
$prop = count(array_filter($g1['members'], function ($x) use ($newG) { return $x !== $newG; })) === 0;
$row('confirm (group PUT, expected = shown guide)', "200, group+all members = $newG", "$c, " . json_encode($g1), $c === 200 && $g1['group'] === $newG && $prop);
list($c, $b) = http('POST', 'assistant.php?action=assign_done', $A, ['departure_id' => "g$gid", 'from_guide_id' => $prev, 'to_guide_id' => $newG, 'send_whatsapp' => true]);
$aid = (int) ($b['action_id'] ?? 0);
$row('assign_done (send_whatsapp ticked)', '200 + action_id', "$c id=$aid at=" . ($b['at'] ?? '') . ' by=' . ($b['by'] ?? '') . ' wa=' . json_encode($b['whatsapp'] ?? null), $c === 200 && $aid > 0);
list($c, $b) = http('PUT', "tour-groups.php/$gid", $A, ['guide_id' => $prev, 'expected_previous_guide_id' => $newG]);
$g2 = groupState($gid);
$row('undo (group PUT back, expected = new guide)', '200, back to before', "$c, " . json_encode($g2), $c === 200 && $g2['group'] === $g0['group']);
list($c, $b) = http('POST', 'assistant.php?action=undo_done', $A, ['action_id' => $aid]);
$row('undo_done', '200', "$c id=" . ($b['action_id'] ?? ''), $c === 200);
list($c, $b) = http('POST', 'assistant.php?action=undo_done', $A, ['action_id' => $aid]);
$row('undo_done twice', '409 already_undone', "$c " . ($b['error'] ?? ''), $c === 409);

// ---- single tour ----
$t0 = tourGuide($tid);
echo "tour $tid before: " . json_encode($t0) . "\n";
list($c, $b) = http('PUT', "tours.php/$tid", $A, ['guide_id' => $newT, 'expected_previous_guide_id' => $newT]);
$row('stale card (single PUT)', '409 departure_changed, unchanged', "$c " . ($b['error'] ?? '') . ', ' . json_encode(tourGuide($tid)), $c === 409 && tourGuide($tid) === $t0);
list($c, $b) = http('PUT', "tours.php/$tid", $A, ['guide_id' => $newT, 'expected_previous_guide_id' => $t0]);
$row('confirm (single PUT)', "200, guide $newT", "$c, " . json_encode(tourGuide($tid)), $c === 200 && tourGuide($tid) === $newT);
list($c, $b) = http('POST', 'assistant.php?action=assign_done', $A, ['departure_id' => "t$tid", 'from_guide_id' => $t0, 'to_guide_id' => $newT, 'send_whatsapp' => false]);
$aid2 = (int) ($b['action_id'] ?? 0);
$row('assign_done (single)', '200 + action_id', "$c id=$aid2", $c === 200 && $aid2 > 0);
list($c, $b) = http('GET', 'tours.php?view=list&date=' . $conn->query("SELECT date FROM tours WHERE id = $tid")->fetch_assoc()['date'], $A);
$seen = null;
foreach (($b['data'] ?? []) as $t) if ((int) $t['id'] === $tid) $seen = $t['guide_id'] ?? null;
$row('Tours list reload shows the guide', (string) $newT, json_encode($seen), (int) $seen === $newT);
list($c, $b) = http('PUT', "tours.php/$tid", $A, ['guide_id' => $t0, 'expected_previous_guide_id' => $newT, 'force' => true]);
$row('undo (single PUT back)', '200, back to ' . json_encode($t0), "$c, " . json_encode(tourGuide($tid)), $c === 200 && tourGuide($tid) === $t0);
list($c, $b) = http('POST', 'assistant.php?action=undo_done', $A, ['action_id' => $aid2]);
$row('undo_done (single)', '200', (string) $c, $c === 200);
echo "\nassistant_actions rows written:\n";
foreach (actions($startId) as $x) echo '  ' . json_encode($x, JSON_UNESCAPED_UNICODE) . "\n";
echo "final: group " . json_encode(groupState($gid)) . " tour $tid " . json_encode(tourGuide($tid)) . "\n";
