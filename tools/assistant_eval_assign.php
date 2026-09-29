<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.5): never deployed
/**
 * Step 7.5 - the assignment eval: 8 questions through the real engine (lib/assistant_core.php).
 *
 *   FWL_API_DIR=<api dir> php82 tools/assistant_eval_assign.php [--user=dhanu] [--only=1,4]
 *
 * Pass = the right confirm card (departure + guide, clash / replace flags) or the right refusal /
 * choices, AND nothing written: CHECKSUM TABLE tours, tour_groups and the assistant_actions count
 * are identical before and after. Departures and guides are picked from the data at run time and
 * printed. Staging only (APP_ENV=staging). Load rule: 2 s between questions, 8 questions.
 *
 * The inactive-guide case uses a guide that is already inactive; if there is none, a temporary
 * guide row "Zz Evaltest" (active = 0, no phone, no tours) is created and deleted at the end -
 * no real guide is ever deactivated.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/assistant_core.php';

$username = 'dhanu'; $only = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--user=(.+)$/', $a, $m)) $username = $m[1];
    if (preg_match('/^--only=([\d,]+)$/', $a, $m)) $only = array_map('intval', explode(',', $m[1]));
}
if (strcasecmp((string) EnvLoader::get('APP_ENV', ''), 'staging') !== 0) { fwrite(STDERR, "refused: staging only\n"); exit(2); }
if (!assistantEnabled() || ClaudeClient::fromEnv() === null) { fwrite(STDERR, "assistant disabled or no key\n"); exit(3); }
$st = $conn->prepare("SELECT id, role, username, email FROM users WHERE username = ?");
$st->bind_param('s', $username); $st->execute(); $user = $st->get_result()->fetch_assoc(); $st->close();
if (!$user || $user['role'] !== 'admin') { fwrite(STDERR, "need an admin user\n"); exit(2); }
ensureAssistantTables($conn);
ensureAssistantActionsTable($conn);
ensureGuideFlagColumns($conn);
$client = ClaudeClient::fromEnv();

function fingerprint() {
    global $conn;
    $c = [];
    $r = $conn->query("CHECKSUM TABLE tours, tour_groups");
    while ($x = $r->fetch_assoc()) $c[] = $x['Table'] . '=' . $x['Checksum'];
    $c[] = 'assistant_actions=' . $conn->query("SELECT COUNT(*) n FROM assistant_actions")->fetch_assoc()['n'];
    return implode(' ', $c);
}
$before = fingerprint();
echo "before: $before\n\n";

$rome = new DateTimeZone('Europe/Rome');
$now = new DateTime('now', $rome);
$today = $now->format('Y-m-d');
$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$horizon = (clone $now)->modify('+14 days')->format('Y-m-d');

$guides = assistantGuides($conn);
$activeGuides = array_values(array_filter($guides, function ($g) { return (int) $g['active'] === 1 && (int) $g['is_partner_agency'] === 0; }));
$units = array_values(array_filter(fwlDepartureUnits($conn, $tomorrow, $horizon), function ($u) { return $u['bookings'] > 0; }));
$byDate = [];
foreach ($units as $u) $byDate[$u['date']][] = $u;
$len = function ($u) { return $u['duration_minutes'] !== null ? $u['duration_minutes'] : ASSISTANT_ASSUMED_DURATION_MIN; };
$min = function ($t) { return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2); };
$busyAt = function ($gid, $u) use ($byDate, $len, $min) {
    foreach ($byDate[$u['date']] as $o) {
        if ($o['guide_id'] === (int) $gid && $o['departure_id'] !== $u['departure_id']
            && assistantOverlaps($min($u['time']), $len($u), $min($o['time']), $len($o))) return true;
    }
    return false;
};
$freeGuide = function ($u, $exclude = []) use ($activeGuides, $busyAt) {
    foreach ($activeGuides as $g) {
        if (in_array((int) $g['id'], $exclude, true) || (int) $g['id'] === (int) $u['guide_id']) continue;
        if (!$busyAt($g['id'], $u)) return $g;
    }
    return null;
};
$uniqueAt = function ($u) use ($byDate) {
    return count(array_filter($byDate[$u['date']], function ($o) use ($u) { return $o['time'] === $u['time']; })) === 1;
};
$keyword = function ($title) {
    foreach (['Uffizi', 'Accademia', 'Vasari', 'Pitti', 'Boboli', 'Duomo', 'Bargello', 'Palazzo Vecchio', 'Medici'] as $k) {
        if (stripos($title, $k) !== false) return $k;
    }
    return $title;
};
$dayWords = function ($ymd, $it = false) use ($tomorrow) {
    if ($ymd === $tomorrow) return $it ? 'domani' : 'tomorrow';
    $d = new DateTime($ymd);
    return $it ? ('il ' . (int) $d->format('j') . '/' . (int) $d->format('n')) : ('on ' . $d->format('l j F'));
};
$firstCounts = array_count_values(array_map(function ($g) { return assistantNorm(explode(' ', trim($g['name']))[0]); }, $guides));

// 1 exact: an unassigned departure alone at its time + a free guide
$exact = null; $exactGuide = null;
foreach ($units as $u) {
    if ($u['guide_id'] === null && $uniqueAt($u) && ($g = $freeGuide($u))) { $exact = $u; $exactGuide = $g; break; }
}
// 2 ambiguous time: two departures at the same date + time
$amb = null;
foreach ($byDate as $d => $list) {
    $times = array_count_values(array_column($list, 'time'));
    foreach ($times as $t => $n) { if ($n >= 2) { $amb = ['date' => $d, 'time' => $t, 'n' => $n]; break 2; } }
}
// 3 typo: the exact case with the guide's first name misspelt (two inner letters swapped)
$typoName = null;
if ($exactGuide) {
    $parts = explode(' ', $exactGuide['name']);
    $f = $parts[0];
    if (mb_strlen($f) >= 5) { $parts[0] = mb_substr($f, 0, 2) . mb_substr($f, 3, 1) . mb_substr($f, 2, 1) . mb_substr($f, 4); $typoName = implode(' ', $parts); }
}
// 4 inactive guide
$inactive = null; $tempGuideId = null;
foreach ($guides as $g) { if ((int) $g['active'] === 0) { $inactive = $g; break; } }
if (!$inactive && $exact) {
    if (!$conn->query("INSERT INTO guides (name, phone, email, languages, active) VALUES ('Zz Evaltest', '', 'zz-evaltest@example.invalid', 'English', 0)")) {
        fwrite(STDERR, "could not create the temporary inactive guide\n"); exit(2);
    }
    $tempGuideId = (int) $conn->insert_id;
    $inactive = ['id' => $tempGuideId, 'name' => 'Zz Evaltest'];
    register_shutdown_function(function () use ($conn, $tempGuideId) {
        $conn->query("DELETE FROM guides WHERE id = " . (int) $tempGuideId . " AND name = 'Zz Evaltest'");
        echo "temporary guide deleted: " . $conn->affected_rows . "\n";
    });
}
// 5 clash: an active guide already on one departure, asked for an overlapping one
$clashU = null; $clashG = null;
foreach ($units as $u) {
    if (!$uniqueAt($u)) continue;
    foreach ($activeGuides as $g) {
        if ((int) $g['id'] !== (int) $u['guide_id'] && $busyAt($g['id'], $u)) { $clashU = $u; $clashG = $g; break 2; }
    }
}
// 6 replace: a departure that has a guide + another free guide
$repU = null; $repG = null;
foreach ($units as $u) {
    if ($u['guide_id'] !== null && $uniqueAt($u) && ($g = $freeGuide($u))) { $repU = $u; $repG = $g; break; }
}
// 7 past: yesterday's first departure alone at its time
$pastU = null;
$yUnits = array_values(array_filter(fwlDepartureUnits($conn, $yesterday, $yesterday), function ($u) { return $u['bookings'] > 0; }));
foreach ($yUnits as $u) {
    if (count(array_filter($yUnits, function ($o) use ($u) { return $o['time'] === $u['time']; })) === 1) { $pastU = $u; break; }
}
$pastG = $activeGuides[0] ?? null;
// 8 Italian: tomorrow, keyword + hour unique, guide with a unique first name and free
$itU = null; $itG = null;
foreach ($byDate[$tomorrow] ?? [] as $u) {
    $k = $keyword($u['title']);
    if ($k === $u['title'] || !$uniqueAt($u)) continue;
    $same = array_filter($byDate[$tomorrow], function ($o) use ($u, $k, $keyword) { return $keyword($o['title']) === $k && substr($o['time'], 0, 2) === substr($u['time'], 0, 2); });
    if (count($same) !== 1) continue;
    foreach ($activeGuides as $g) {
        if ((int) $g['id'] === (int) $u['guide_id'] || $busyAt($g['id'], $u)) continue;
        if (($firstCounts[assistantNorm(explode(' ', trim($g['name']))[0])] ?? 0) === 1) { $itU = $u; $itG = $g; break 2; }
    }
}

$say = function ($u) use ($keyword, $dayWords) { return 'the ' . $u['time'] . ' ' . $keyword($u['title']) . ' ' . $dayWords($u['date']); };
$Q = [
    1 => ['en', $exact ? "Assign {$exactGuide['name']} to " . $say($exact) : null, 'card',
          $exact ? ['dep' => $exact['departure_id'], 'guide' => (int) $exactGuide['id'], 'replace' => false] : null],
    2 => ['en', $amb && $activeGuides ? "Assign {$activeGuides[0]['name']} to the {$amb['time']} tour " . $dayWords($amb['date']) : null, 'choices', $amb],
    3 => ['en', $typoName ? "Assign $typoName to " . $say($exact) : null, 'typo',
          $exact ? ['dep' => $exact['departure_id'], 'guide' => (int) $exactGuide['id'], 'name' => $exactGuide['name']] : null],
    4 => ['en', $inactive && $exact ? "Assign {$inactive['name']} to " . $say($exact) : null, 'refuse', '/inactive|reactivat/i'],
    5 => ['en', $clashU ? "Assign {$clashG['name']} to " . $say($clashU) : null, 'clash',
          $clashU ? ['dep' => $clashU['departure_id'], 'guide' => (int) $clashG['id']] : null],
    6 => ['en', $repU ? "Assign {$repG['name']} to " . $say($repU) : null, 'replace',
          $repU ? ['dep' => $repU['departure_id'], 'guide' => (int) $repG['id'], 'old' => $repU['guide_name'], 'new' => $repG['name']] : null],
    7 => ['en', $pastU && $pastG ? "Assign {$pastG['name']} to the {$pastU['time']} " . $keyword($pastU['title']) . ' yesterday' : null, 'refuse', '/past|already (started|happened|took place)|yesterday/i'],
    8 => ['it', $itU ? 'assegna ' . explode(' ', trim($itG['name']))[0] . ' alle ' . ltrim(substr($itU['time'], 0, 2), '0') . (substr($itU['time'], 3, 2) !== '00' ? ':' . substr($itU['time'], 3, 2) : '') . ' ' . $keyword($itU['title']) . ' domani' : null, 'card',
          $itU ? ['dep' => $itU['departure_id'], 'guide' => (int) $itG['id'], 'replace' => $itU['guide_id'] !== null] : null],
];

$pass = 0; $ran = 0;
foreach ($Q as $n => list($lang, $question, $kind, $exp)) {
    if ($only !== null && !in_array($n, $only, true)) continue;
    if ($question === null) { printf("Q%d SKIP  no suitable data\n\n", $n); continue; }
    $ran++;
    sleep(2); // load rule
    $res = assistantHandle($conn, $client, $user, $question, null);
    $b = $res['body'];
    $text = (string) ($b['text'] ?? '');
    $blocks = json_decode(json_encode($b['blocks'] ?? []), true);
    $cards = array_values(array_filter($blocks, function ($x) { return $x['type'] === 'confirm_assign'; }));
    $choices = array_values(array_filter($blocks, function ($x) { return $x['type'] === 'choices'; }));
    $card = $cards[0] ?? null;
    $why = [];
    if (count($cards) > 1) $why[] = count($cards) . ' cards';
    $cardIs = function ($dep, $gid) use ($card) { return $card && $card['departure']['departure_id'] === $dep && (int) $card['guide']['id'] === $gid; };
    switch ($kind) {
        case 'card':
            if (!$cardIs($exp['dep'], $exp['guide'])) $why[] = 'no card for ' . $exp['dep'] . ' / guide ' . $exp['guide'];
            elseif ((bool) $card['replace'] !== $exp['replace']) $why[] = 'replace flag wrong';
            break;
        case 'choices':
            if ($card) $why[] = 'a card was shown for an ambiguous time';
            if (!$choices) $why[] = 'no choices block';
            elseif (count($choices[0]['options']) < 2) $why[] = 'fewer than 2 options';
            break;
        case 'typo': // "did you mean" choices naming the guide, or (confident fuzzy match) the right card
            $named = false;
            foreach ($choices as $c) foreach ($c['options'] as $o) if (stripos($o['label'] . ' ' . $o['value'], explode(' ', $exp['name'])[0]) !== false) $named = true;
            if (!$named && !$cardIs($exp['dep'], $exp['guide'])) $why[] = 'neither did-you-mean choices with ' . $exp['name'] . ' nor the right card';
            break;
        case 'refuse':
            if ($card) $why[] = 'a card was shown';
            if (!preg_match($exp, $text)) $why[] = 'refusal reason not stated';
            break;
        case 'clash':
            if (!$cardIs($exp['dep'], $exp['guide'])) $why[] = 'no card';
            elseif (!$card['clash']) $why[] = 'no clash on the card';
            elseif (!preg_grep('/already has/', array_column($card['checks'], 'text'))) $why[] = 'no clash warning line';
            break;
        case 'replace':
            if (!$cardIs($exp['dep'], $exp['guide'])) $why[] = 'no card';
            elseif (!$card['replace'] || !preg_grep('/^Replace ' . preg_quote($exp['old'], '/') . ' with ' . preg_quote($exp['new'], '/') . '/', array_column($card['checks'], 'text'))) $why[] = 'no "Replace X with Y" line';
            break;
    }
    if (preg_match('/\b(is now assigned|has been assigned|assigned successfully|ho assegnato|è stat[oa] assegnat)/iu', $text)) $why[] = 'claims the guide is assigned';
    $detected = assistantDetectLanguage($text); // the server's own detector; null = cannot tell (short text)
    if ($detected !== null && $detected !== $lang) $why[] = 'answered in ' . ($detected === 'it' ? 'Italian' : 'English');
    if ($res['status'] !== 200) $why = ['status ' . $res['status'] . ' ' . ($b['error'] ?? '')];
    $ok = !$why;
    if ($ok) $pass++;
    printf("Q%d %s  [%s] %s\n", $n, $ok ? 'PASS' : 'FAIL', strtoupper($lang), $question);
    echo "     expect: $kind " . json_encode($exp, JSON_UNESCAPED_UNICODE) . "\n";
    echo "     A: " . str_replace("\n", ' / ', $text) . "\n";
    if ($card) echo "     card: {$card['departure']['departure_id']} {$card['departure']['date']} {$card['departure']['time']} -> {$card['guide']['name']}"
        . ' replace=' . json_encode($card['replace']) . ' clash=' . count($card['clash']) . ' alternatives=' . json_encode(array_column($card['alternatives'], 'name'))
        . ' whatsapp=' . json_encode($card['whatsapp']) . "\n     checks: " . implode(' | ', array_column($card['checks'], 'text')) . "\n";
    if ($choices) echo "     choices: " . implode(' | ', array_column($choices[0]['options'], 'label')) . "\n";
    echo "     tools: " . implode(', ', $b['meta']['tools_called'] ?? []) . "\n";
    if (!$ok) echo "     WHY: " . implode('; ', $why) . "\n";
    echo "\n";
}
$after = fingerprint();
$same = $after === $before;
echo "after:  $after\n";
echo "database unchanged: " . ($same ? 'YES' : 'NO - SOMETHING WROTE') . "\n";
echo "RESULT $pass/$ran passed\n";
exit($pass === $ran && $same ? 0 : 1);
