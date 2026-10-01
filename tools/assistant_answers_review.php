<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only: never deployed
/**
 * Weekly review, part 3 - assistant answers that look wrong. READ-ONLY.
 *
 *   FWL_API_DIR=<api dir> php82 tools/assistant_answers_review.php [--days=7] [--end=YYYY-MM-DD]
 *
 * Period = the N days (Europe/Rome) ending on --end (default today). Prints:
 *   1. every question of the period with the assistant's reply (Rome time, each cut to ~300 chars),
 *      grouped by conversation, and FLAGS on replies that look wrong:
 *        error      - the request logged an error (assistant_logs.error, cap hits shown apart)
 *        empty      - no reply text
 *        apology    - "couldn't", "something went wrong", "non sono riuscito", "mi dispiace", ...
 *        language   - question Italian and reply English, or the other way round
 *        repeated   - the same user asked nearly the same thing again in the conversation
 *        numbers    - two different counts for the same date in one conversation
 *   2. undos of assistant assignments (assistant_actions) - an assignment that was taken back
 *   3. REFERENCE FIGURES per Rome day, period start .. end + 7 days, computed the way the app does
 *      (departure = group or single tour, cancelled bookings and ticket products left out - the SQL of
 *      fwlDepartureUnits() in api/lib/departure_queries.php; unassigned = no group guide and no
 *      member guide, as tours.php?action=unassigned-report): departures, guests, unassigned
 *      departures. The review compares flagged factual answers with these numbers.
 *
 * Only prepared SELECTs. No INSERT/UPDATE/DELETE/ALTER/CREATE, no file writes, no network calls.
 * It deliberately does NOT call fwlDepartureUnits() - that helper can add a column on first use.
 * Never prints tokens, keys, passwords or phone numbers (it reads none of them).
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cli';
require $apiDir . '/config.php';

$days = 7; $end = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--days=(\d{1,3})$/', $a, $m)) $days = max(1, (int) $m[1]);
    if (preg_match('/^--end=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $end = $m[1];
}
$rome = new DateTimeZone('Europe/Rome');
$utc = new DateTimeZone('UTC');
$last = $end ? new DateTime($end . ' 00:00:00', $rome) : new DateTime('today', $rome);
$first = (clone $last)->modify('-' . ($days - 1) . ' days');
$fromUtc = (clone $first)->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = (clone $last)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$romeTime = function ($utcTs) use ($utc, $rome) { return (new DateTime($utcTs, $utc))->setTimezone($rome)->format('D d M H:i'); };
$has = function ($t) use ($conn) { $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'"); return $r && $r->num_rows > 0; };
$cut = function ($s, $n = 300) { $s = trim(preg_replace('/\s+/u', ' ', (string) $s)); return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s; };

echo "assistant answers review - " . $first->format('Y-m-d') . " .. " . $last->format('Y-m-d') . " (Europe/Rome, $days day(s))\n\n";

$users = [];
$r = $conn->query("SELECT id, username FROM users");
while ($x = $r->fetch_assoc()) $users[(int) $x['id']] = $x['username'];

// --- helpers for the flags -------------------------------------------------------------------
function aarText($json) {
    $d = json_decode((string) $json, true);
    if (is_array($d)) return (string) ($d['text'] ?? ($d['question'] ?? ''));
    return (string) $json;
}
function aarIsItalian($s) {
    $s = ' ' . mb_strtolower($s) . ' ';
    $n = 0;
    foreach ([' il ', ' la ', ' di ', ' che ', ' per ', ' sono ', ' quanti ', ' quante ', ' oggi ', ' domani ', ' guida ', ' chi ', ' non ', ' è ', ' della ', ' ci '] as $w) $n += substr_count($s, $w);
    return $n >= 2;
}
function aarIsEnglish($s) {
    $s = ' ' . mb_strtolower($s) . ' ';
    $n = 0;
    foreach ([' the ', ' is ', ' are ', ' how ', ' many ', ' today ', ' tomorrow ', ' guide ', ' who ', ' what ', ' there ', ' for ', ' and ', ' with '] as $w) $n += substr_count($s, $w);
    return $n >= 2;
}
function aarApology($s) {
    return (bool) preg_match('/couldn.?t|could not|can.?t (find|access|load)|something went wrong|sorry|i was unable|non sono riuscit|non riesco|mi dispiace|qualcosa è andato storto|si è verificato un errore/iu', $s);
}
function aarNorm($s) { return preg_replace('/[^a-z0-9àèéìòù ]/u', '', mb_strtolower(trim($s))); }
/** "12 departures" / "12 partenze" / "34 guests" / "34 ospiti" -> [kind => number] */
function aarCounts($s) {
    $out = [];
    if (preg_match_all('/(\d{1,4})\s+(departures?|partenze|guests?|ospiti|persone|unassigned|senza guida)/iu', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $k = mb_strtolower($x[2]);
            $kind = preg_match('/^(guest|ospiti|persone)/u', $k) ? 'guests' : (preg_match('/^(unassigned|senza)/u', $k) ? 'unassigned' : 'departures');
            $out[$kind][] = (int) $x[1];
        }
    }
    return $out;
}

// --- 1. questions and replies -------------------------------------------------------------------
$flagCount = 0;
if (!$has('assistant_messages')) {
    echo "no assistant_messages table yet (the assistant has never answered here)\n";
} else {
    // errors per conversation+question from the logs (cap hits apart)
    $logErr = [];
    if ($has('assistant_logs')) {
        $st = $conn->prepare("SELECT conversation_id, question, error, created_at FROM assistant_logs
                               WHERE created_at >= ? AND created_at < ? AND error IS NOT NULL AND error <> ''");
        $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
        while ($x = $res->fetch_assoc()) $logErr[(int) $x['conversation_id']][] = $x;
        $st->close();
    }
    $st = $conn->prepare("SELECT m.id, m.conversation_id, m.role, m.content_json, m.created_at, c.user_id
                            FROM assistant_messages m JOIN assistant_conversations c ON c.id = m.conversation_id
                           WHERE m.created_at >= ? AND m.created_at < ?
                           ORDER BY m.conversation_id, m.id");
    $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
    $conv = [];
    while ($x = $res->fetch_assoc()) $conv[(int) $x['conversation_id']][] = $x;
    $st->close();
    if (!$conv) echo "(no questions in this period)\n";
    $nQ = 0;
    foreach ($conv as $cid => $msgs) {
        $u = $users[(int) $msgs[0]['user_id']] ?? ('#' . $msgs[0]['user_id']);
        echo "=== conversation $cid - $u\n";
        $seenQ = []; $countsByDate = [];
        for ($i = 0; $i < count($msgs); $i++) {
            $m = $msgs[$i];
            if ($m['role'] !== 'user') continue;
            $nQ++;
            $q = aarText($m['content_json']);
            $reply = ($i + 1 < count($msgs) && $msgs[$i + 1]['role'] === 'assistant') ? aarText($msgs[$i + 1]['content_json']) : null;
            $flags = [];
            if ($reply === null || trim($reply) === '') $flags[] = 'empty';
            if ($reply !== null && aarApology($reply)) $flags[] = 'apology';
            if ($reply !== null && ((aarIsItalian($q) && aarIsEnglish($reply) && !aarIsItalian($reply))
                || (aarIsEnglish($q) && !aarIsItalian($q) && aarIsItalian($reply) && !aarIsEnglish($reply)))) $flags[] = 'language';
            $nq = aarNorm($q);
            foreach ($seenQ as $prev) { similar_text($prev, $nq, $pct); if ($pct >= 80) { $flags[] = 'repeated'; break; } }
            $seenQ[] = $nq;
            foreach ($logErr[$cid] ?? [] as $le) {
                if (aarNorm($le['question']) === $nq) { $flags[] = $le['error'] === 'daily_cap_reached' ? 'cap-hit' : 'error: ' . mb_substr($le['error'], 0, 60); break; }
            }
            if ($reply !== null && preg_match('/(\d{4}-\d{2}-\d{2}|today|tomorrow|oggi|domani)/iu', $q . ' ' . $reply, $dm)) {
                $key = mb_strtolower($dm[1]);
                foreach (aarCounts($reply) as $kind => $nums) {
                    foreach ($nums as $n) {
                        if (isset($countsByDate[$key][$kind]) && $countsByDate[$key][$kind] !== $n) $flags[] = "numbers ($kind for $key: {$countsByDate[$key][$kind]} vs $n)";
                        $countsByDate[$key][$kind] = $n;
                    }
                }
            }
            $flags = array_values(array_unique($flags));
            if ($flags) $flagCount++;
            echo "  [" . $romeTime($m['created_at']) . "] Q: " . $cut($q, 200) . "\n";
            echo "      A: " . ($reply === null ? '(no reply stored)' : $cut($reply)) . "\n";
            if ($flags) echo "      FLAG: " . implode('; ', $flags) . "\n";
        }
    }
    echo "\nquestions: $nQ, replies flagged: $flagCount\n";
}

// --- 2. undos -----------------------------------------------------------------------------------
echo "\nUNDOS (an assignment from the assistant taken back)\n";
if (!$has('assistant_actions')) {
    echo "no assistant_actions table yet\n";
} else {
    $st = $conn->prepare("SELECT created_at, user_id, action FROM assistant_actions WHERE created_at >= ? AND created_at < ? AND action = 'undo' ORDER BY created_at");
    $st->bind_param('ss', $fromUtc, $toUtc); $st->execute(); $res = $st->get_result();
    $n = 0;
    while ($x = $res->fetch_assoc()) { $n++; echo "  " . $romeTime($x['created_at']) . " " . ($users[(int) $x['user_id']] ?? ('#' . $x['user_id'])) . "\n"; }
    $st->close();
    if (!$n) echo "(none)\n";
}

// --- 3. reference figures -----------------------------------------------------------------------
$refFrom = $first->format('Y-m-d');
$refTo = (clone $last)->modify('+7 days')->format('Y-m-d');
echo "\nREFERENCE FIGURES $refFrom .. $refTo (departure = group or single tour; cancelled + tickets left out)\n";
// Same SELECT as fwlDepartureUnits() (api/lib/departure_queries.php), read-only copy.
$st = $conn->prepare("SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit,
                             COALESCE(MAX(tg.group_date), MIN(t.date)) AS unit_date,
                             SUM(COALESCE(t.participants, 0)) AS pax,
                             COALESCE(MAX(tg.guide_id), MAX(t.guide_id)) AS guide_id
                        FROM tours t
                        LEFT JOIN tour_groups tg ON t.group_id = tg.id
                        LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
                       WHERE t.date >= ? AND t.date <= ? AND (pr.product_type = 'tour' OR t.product_id IS NULL) AND t.cancelled = 0
                       GROUP BY tour_unit");
$st->bind_param('ss', $refFrom, $refTo); $st->execute(); $res = $st->get_result();
$ref = [];
while ($x = $res->fetch_assoc()) {
    $d = $x['unit_date'];
    if (!isset($ref[$d])) $ref[$d] = ['dep' => 0, 'pax' => 0, 'unassigned' => 0];
    $ref[$d]['dep']++;
    $ref[$d]['pax'] += (int) $x['pax'];
    if ($x['guide_id'] === null) $ref[$d]['unassigned']++;
}
$st->close();
ksort($ref);
printf("%-15s %10s %7s %11s\n", 'day', 'departures', 'guests', 'unassigned');
for ($d = new DateTime($refFrom, $rome); $d->format('Y-m-d') <= $refTo; $d->modify('+1 day')) {
    $k = $d->format('Y-m-d');
    $a = $ref[$k] ?? ['dep' => 0, 'pax' => 0, 'unassigned' => 0];
    printf("%-15s %10d %7d %11d\n", $d->format('Y-m-d D'), $a['dep'], $a['pax'], $a['unassigned']);
}
