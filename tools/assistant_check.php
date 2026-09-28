<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.1): never deployed
/**
 * Step 7.1 - the assistant's pure parts, checked without a database, a network or an API key.
 *
 *   php tools/assistant_check.php
 *
 * day_summary counting (the Tours page rules), the money-tool filter, body validation, and the
 * tool loop driven by a fake Claude client (tool error round-trip, round limit, time budget).
 */
require_once __DIR__ . '/../public_html/api/EnvLoader.php';
require_once __DIR__ . '/../public_html/api/Middleware.php';
require_once __DIR__ . '/../public_html/api/lib/assistant_core.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = ($got === $want);
    if (!$ok) { $fail++; }
    printf("%-64s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $ok ? '' : '  got ' . json_encode($got) . ' want ' . json_encode($want));
}

// ---- day_summary ------------------------------------------------------------------------------
$bokun = function ($total, $start) {
    return json_encode(['productBookings' => [['fields' => ['totalParticipants' => $total, 'startTimeStr' => $start]]]]);
};
$rows = [
    // group 7 (Uffizi 09:00): 3 bookings, one cancelled -> 1 departure, 4 + 2 guests
    ['id' => 1, 'group_id' => 7, 'title' => 'Uffizi Gallery Tour', 'time' => '09:00:00', 'participants' => 4, 'cancelled' => 0, 'language' => 'English', 'is_private' => 0, 'bokun_data' => $bokun(4, '09:00'), 'group_time' => '09:00:00', 'group_display_name' => 'Uffizi Gallery Tour'],
    ['id' => 2, 'group_id' => 7, 'title' => 'Uffizi Gallery Tour', 'time' => '09:00:00', 'participants' => 2, 'cancelled' => 0, 'language' => 'English', 'is_private' => 0, 'bokun_data' => $bokun(2, '09:00'), 'group_time' => '09:00:00', 'group_display_name' => 'Uffizi Gallery Tour'],
    ['id' => 3, 'group_id' => 7, 'title' => 'Uffizi Gallery Tour', 'time' => '09:00:00', 'participants' => 5, 'cancelled' => 1, 'language' => 'English', 'is_private' => 0, 'bokun_data' => $bokun(5, '09:00'), 'group_time' => '09:00:00', 'group_display_name' => 'Uffizi Gallery Tour'],
    // single Accademia 14:30, Bokun says 3 guests (participants column says 1) -> list uses 3
    ['id' => 4, 'group_id' => null, 'title' => 'Accademia David Tour', 'time' => '14:30:00', 'participants' => 1, 'cancelled' => 0, 'language' => 'Italian', 'is_private' => 0, 'bokun_data' => $bokun(3, '14:30'), 'group_time' => null, 'group_display_name' => null],
    // single cancelled -> not a departure
    ['id' => 5, 'group_id' => null, 'title' => 'Pitti Palace Tour', 'time' => '10:00:00', 'participants' => 2, 'cancelled' => 1, 'language' => 'English', 'is_private' => 0, 'bokun_data' => $bokun(2, '10:00'), 'group_time' => null, 'group_display_name' => null],
    // private single, hand-entered (no bokun_data) at 11:00
    ['id' => 6, 'group_id' => null, 'title' => 'Private Uffizi Tour', 'time' => '11:00:00', 'participants' => 2, 'cancelled' => 0, 'language' => null, 'is_private' => 1, 'bokun_data' => null, 'group_time' => null, 'group_display_name' => null],
];
$now = new DateTime('2026-09-28 12:00', new DateTimeZone('Europe/Rome'));
$s = assistantDaySummarize($rows, '2026-09-28', $now);
check('departures (group once, cancelled single left out)', $s['departures'], 3);
check('guests (6 + 3 + 2)', $s['guests'], 11);
check('cancelled single bookings', $s['cancelled_single_bookings'], 1);
check('finished at 12:00 (09:00 group + 11:00 private)', $s['finished'], ['departures' => 2, 'guests' => 8]);
check('to run at 12:00 (14:30)', $s['to_run'], ['departures' => 1, 'guests' => 3]);
check('category Uffizi', $s['by_category']['Uffizi'], ['departures' => 1, 'guests' => 6]);
check('category Private Uffizi', $s['by_category']['Private Uffizi'], ['departures' => 1, 'guests' => 2]);
check('category Accademia', $s['by_category']['Accademia'], ['departures' => 1, 'guests' => 3]);
check('language Unknown for the hand-entered row', $s['by_language']['Unknown'], ['departures' => 1, 'guests' => 2]);
check('weekday', $s['weekday'], 'Monday');
$past = assistantDaySummarize($rows, '2026-09-28', new DateTime('2026-09-29 08:00', new DateTimeZone('Europe/Rome')));
check('a past day: everything finished', $past['finished']['departures'], 3);
$future = assistantDaySummarize($rows, '2026-09-28', new DateTime('2026-09-27 23:00', new DateTimeZone('Europe/Rome')));
check('a future day: everything to run', $future['to_run']['departures'], 3);
check('empty day', assistantDaySummarize([], '2026-09-28', $now)['departures'], 0);
check('valid date', assistantValidDate('2026-09-28'), true);
check('invalid date 2026-02-30', assistantValidDate('2026-02-30'), false);
check('invalid date text', assistantValidDate('tomorrow'), false);

// ---- money tools never offered to a non-owner ------------------------------------------------
$registry = array_merge(assistantToolRegistry(), [['name' => 'money', 'description' => 'x', 'input_schema' => ['type' => 'object'], 'handler' => 'strlen', 'money' => true]]);
$names = function ($tools) { return array_map(function ($t) { return $t['name']; }, $tools); };
check('owner (dhanu) gets money', in_array('money', $names(assistantToolsForUser(['role' => 'admin', 'username' => 'dhanu', 'email' => ''], $registry)), true), true);
check('second admin (sudesh) does not', in_array('money', $names(assistantToolsForUser(['role' => 'admin', 'username' => 'sudesh', 'email' => ''], $registry)), true), false);
check('API definitions carry no handler / money flag', array_keys(assistantToolDefinitions(assistantToolRegistry())[0]), ['name', 'description', 'input_schema']);

// ---- body validation --------------------------------------------------------------------------
$bad = function ($body) { try { assistantParseBody($body); return 'accepted'; } catch (InvalidArgumentException $e) { return 'rejected'; } };
check('message required', $bad(['message' => '  ']), 'rejected');
check('1001 characters rejected', $bad(['message' => str_repeat('a', 1001)]), 'rejected');
check('1000 characters (multi-byte) accepted', $bad(['message' => str_repeat('è', 1000)]), 'accepted');
check('conversation_id must be positive', $bad(['message' => 'hi', 'conversation_id' => -3]), 'rejected');
check('not JSON', $bad(null), 'rejected');
check('parsed', assistantParseBody(['message' => ' ciao ', 'conversation_id' => '12']), ['ciao', 12]);

// ---- tool loop with a fake client --------------------------------------------------------------
class FakeClaude {
    public $script; public $payloads = [];
    public function __construct(array $script) { $this->script = $script; }
    public function model() { return 'claude-sonnet-5'; }
    public function createMessage(array $payload, $timeout = null) {
        $this->payloads[] = $payload;
        $next = array_shift($this->script);
        return is_callable($next) ? $next($payload) : $next;
    }
}
$toolUse = function ($id, $input) {
    return ['stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'day_summary', 'input' => $input]]];
};
$answer = ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 150, 'output_tokens' => 20], 'content' => [['type' => 'text', 'text' => 'Oggi 3 tour.']]];
$user = ['id' => 1, 'role' => 'admin', 'username' => 'dhanu', 'email' => ''];

$fake = new FakeClaude([$toolUse('tu1', ['date' => 'oggi']), $answer]);
$r = assistantRunLoop($fake, null, $user, assistantToolRegistry(), [], 'quanti tour oggi', $now);
check('loop: answer text', $r['text'], 'Oggi 3 tour.');
check('loop: two rounds', $r['rounds'], 2);
check('loop: tokens summed', [$r['input_tokens'], $r['output_tokens']], [250, 30]);
check('loop: bad date -> tool error, not a crash', $r['tools_called'][0]['ok'], false);
$sent = $fake->payloads[1]['messages'];
check('loop: tool_result returned with is_error', [$sent[2]['content'][0]['type'], $sent[2]['content'][0]['is_error']], ['tool_result', true]);
check('loop: all 7 tools offered to the API', array_column($fake->payloads[0]['tools'], 'name'),
    ['day_summary', 'find_departures', 'unassigned_departures', 'find_guide', 'guide_schedule', 'free_guides', 'show_blocks']);
check('loop: system = cached rules + context with today (Rome)',
    [count($fake->payloads[0]['system']), $fake->payloads[0]['system'][0]['cache_control'] ?? null,
     isset($fake->payloads[0]['system'][1]['cache_control']), strpos($fake->payloads[0]['system'][1]['text'], '2026-09-28') !== false],
    [2, ['type' => 'ephemeral'], false, true]);
check('loop: the cached rules hold no date or user (stable prefix)',
    preg_match('/20\d\d-\d\d-\d\d|dhanu/', $fake->payloads[0]['system'][0]['text']), 0);
check('loop: top-level automatic cache breakpoint', $fake->payloads[0]['cache_control'] ?? null, ['type' => 'ephemeral']);
check('loop: low effort on Sonnet', $fake->payloads[0]['output_config'], ['effort' => 'low']);

$script = array_fill(0, 5, $toolUse('tu', ['date' => 'x']));
$script[] = function ($payload) { return ['stop_reason' => 'end_turn', 'usage' => [], 'content' => [['type' => 'text', 'text' => isset($payload['tool_choice']) ? 'forced' : 'free']]]; };
$fake = new FakeClaude($script);
$r = assistantRunLoop($fake, null, $user, assistantToolRegistry(), [], 'loop forever', $now);
check('loop: 6th call has tool_choice none and answers', [$r['rounds'], $r['text']], [6, 'forced']);

$fake = new FakeClaude([$answer]);
$r = assistantRunLoop($fake, null, $user, assistantToolRegistry(), [], 'late', $now, microtime(true) - 38);
check('loop: under 6 s left -> no call, polite text', [$r['rounds'], $r['stop'], count($fake->payloads)], [0, 'time_budget', 0]);

$fake = new FakeClaude([$answer]);
$hist = [['role' => 'user', 'content' => 'ciao'], ['role' => 'assistant', 'content' => 'Ciao!']];
assistantRunLoop($fake, null, $user, [], $hist, 'e domani?', $now);
check('loop: history first, question last, no tools key when none', [count($fake->payloads[0]['messages']), isset($fake->payloads[0]['tools'])], [3, false]);


// ---- step 7.2: prompt caching token split + blocks through the loop ---------------------------
$fake = new FakeClaude([
    ['stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 40, 'cache_creation_input_tokens' => 1500, 'output_tokens' => 10],
     'content' => [['type' => 'tool_use', 'id' => 'b1', 'name' => 'show_blocks', 'input' => ['blocks' => [
        ['type' => 'stat', 'label' => 'Departures', 'value' => 11, 'extra' => 'dropped field'],
        ['type' => 'link', 'label' => 'Open', 'route' => '/admin', 'query' => []],
        ['type' => 'departure_list', 'rows' => [['departure_id' => 'g12', 'date' => '2026-09-29', 'time' => '9:30', 'title' => 'Uffizi', 'guests' => '8', 'guide' => null, 'secret' => 'x']]],
     ]]]]],
    ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 60, 'cache_read_input_tokens' => 1500, 'output_tokens' => 15], 'content' => [['type' => 'text', 'text' => '11 departures.']]],
]);
$r = assistantRunLoop($fake, null, $user, assistantToolRegistry(), [], 'how many', $now);
check('cache: uncached input, reads and writes counted apart', [$r['input_tokens'], $r['cache_write_tokens'], $r['cache_read_tokens']], [100, 1500, 1500]);
check('blocks: 2 of 3 kept (bad route dropped)', array_column($r['blocks'], 'type'), ['stat', 'departure_list']);
check('blocks: unknown fields stripped, values normalised', [$r['blocks'][0], $r['blocks'][1]['rows'][0]],
    [['type' => 'stat', 'label' => 'Departures', 'value' => 11],
     ['departure_id' => 'g12', 'date' => '2026-09-29', 'time' => '09:30', 'title' => 'Uffizi', 'guests' => 8, 'guide' => null]]);
check('blocks: show_blocks logged without its payload', $r['tools_called'][0]['input'], ['blocks' => 3]);
$fake = new FakeClaude([
    ['stop_reason' => 'tool_use', 'usage' => [], 'content' => [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => 'd1', 'name' => 'day_summary', 'input' => ['date' => 'bad']]]],
    ['stop_reason' => 'tool_use', 'usage' => [], 'content' => [['type' => 'text', 'text' => 'Tomorrow: 10 departures, 41 guests.'], ['type' => 'tool_use', 'id' => 'b2', 'name' => 'show_blocks', 'input' => ['blocks' => [['type' => 'stat', 'label' => 'Departures', 'value' => 10]]]]]],
    ['stop_reason' => 'end_turn', 'usage' => [], 'content' => [['type' => 'text', 'text' => 'Want the split by product?']]],
]);
$r = assistantRunLoop($fake, null, $user, assistantToolRegistry(), [], 'how many tomorrow', $now);
check('text: answer written with show_blocks + final line kept, "let me check" dropped', $r['text'], "Tomorrow: 10 departures, 41 guests.\nWant the split by product?");


// ---- step 7.2: block validator edge cases ------------------------------------------------------
$v = function ($b) { $c = assistantValidateBlock($b); return $c === null ? null : json_decode(json_encode($c), true); };
check('block: unknown type dropped', $v(['type' => 'html', 'html' => '<b>x</b>']), null);
check('block: departure_list with a bad id dropped', $v(['type' => 'departure_list', 'rows' => [['departure_id' => '12', 'date' => '2026-09-29', 'time' => '09:30', 'title' => 'x']]]), null);
check('block: table row width must match columns', $v(['type' => 'table', 'columns' => ['Name', 'Free'], 'rows' => [['Anna']]]), null);
check('block: table ok (bool -> yes/no)', $v(['type' => 'table', 'columns' => ['Name', 'Free'], 'rows' => [['Anna', true]]]),
    ['type' => 'table', 'columns' => ['Name', 'Free'], 'rows' => [['Anna', 'yes']]]);
check('block: choices needs 2+ options; strings accepted', $v(['type' => 'choices', 'prompt' => 'Which?', 'options' => ['A', 'B']]),
    ['type' => 'choices', 'prompt' => 'Which?', 'options' => [['label' => 'A', 'value' => 'A'], ['label' => 'B', 'value' => 'B']]]);
check('block: choices with 1 option dropped', $v(['type' => 'choices', 'prompt' => 'Which?', 'options' => ['A']]), null);
check('block: link ok', $v(['type' => 'link', 'label' => 'Open in Tours', 'route' => '/tours', 'query' => ['date' => '2026-09-30']]),
    ['type' => 'link', 'label' => 'Open in Tours', 'route' => '/tours', 'query' => ['date' => '2026-09-30']]);
check('block: link with an unknown query key dropped', $v(['type' => 'link', 'label' => 'x', 'route' => '/tours', 'query' => ['token' => 'abc']]), null);
check('block: link with a javascript: value dropped', $v(['type' => 'link', 'label' => 'x', 'route' => '/tours', 'query' => ['date' => 'javascript:alert(1)']]), null);
$many = ['blocks' => array_fill(0, 8, ['type' => 'stat', 'label' => 'x', 'value' => 1])];
$store = new ArrayObject();
$res = assistantToolShowBlocks(null, $many, ['blocks' => $store]);
check('show_blocks: at most 6 kept', [count($store), $res['accepted'], count($res['dropped'])], [6, 6, 2]);

// ---- step 7.2: guide name matching -------------------------------------------------------------
$guides = [
    ['id' => 1, 'name' => 'Giulia Rossi', 'languages' => 'English'],
    ['id' => 2, 'name' => 'Anna Bianchi', 'languages' => 'English'],
    ['id' => 3, 'name' => 'Anna Verdi', 'languages' => 'Italian'],
    ['id' => 4, 'name' => "Nicol\u{00F2} Conti", 'languages' => 'Italian'],
    ['id' => 5, 'name' => 'Meritxell Puig', 'languages' => 'Spanish'],
    ['id' => 6, 'name' => 'Caterina Neri', 'languages' => 'English'],
];
$top = function ($q) use ($guides) { $r = assistantRankGuides($q, $guides); return [$r['matches'][0]['guide_id'] ?? null, $r['confident']]; };
check('guide: "Guilia" -> Giulia, confident', $top('Guilia'), [1, true]);
check('guide: "giulia r" -> Giulia, confident', $top('giulia r'), [1, true]);
check('guide: "NICOLO" (no accent) -> Nicolo, confident', $top('NICOLO'), [4, true]);
check('guide: "Meritxel" (missing letter) -> Meritxell', $top('Meritxel'), [5, true]);
check('guide: "anna" -> two Annas, NOT confident', [$top('anna')[1], count(assistantRankGuides('anna', $guides)['matches']) >= 2], [false, true]);
check('guide: "anna verdi" -> Anna Verdi, confident', $top('anna verdi'), [3, true]);
check('guide: "cater" (prefix) -> Caterina', $top('cater')[0], 6);
check('guide: nonsense -> no match', count(assistantRankGuides('zzqx', $guides)['matches']), 0);

// ---- step 7.2: departures filter, free/busy, times, dates --------------------------------------
$units = [
    ['departure_id' => 'g1', 'type' => 'group', 'id' => 1, 'date' => '2026-09-30', 'time' => '09:30', 'title' => 'Uffizi Gallery Tour', 'languages' => ['English'], 'guests' => 8, 'bookings' => 3, 'guide_id' => 1, 'guide_name' => 'Giulia Rossi'],
    ['departure_id' => 't7', 'type' => 'single', 'id' => 7, 'date' => '2026-09-30', 'time' => '09:45', 'title' => "Galleria dell'Accademia - David", 'languages' => ['Italian'], 'guests' => 2, 'bookings' => 1, 'guide_id' => null, 'guide_name' => null],
    ['departure_id' => 'g2', 'type' => 'group', 'id' => 2, 'date' => '2026-09-30', 'time' => '14:00', 'title' => 'Uffizi Gallery Tour', 'languages' => ['English', 'Spanish'], 'guests' => 9, 'bookings' => 4, 'guide_id' => 5, 'guide_name' => 'Meritxell Puig'],
];
$ids = function ($rows) { return array_column($rows, 'departure_id'); };
check('find: 09:40 matches 09:30 and 09:45 (+-15)', $ids(assistantFilterDepartures($units, '09:40')), ['g1', 't7']);
check('find: 10:00 matches only 09:45 (09:30 is 30 min away)', $ids(assistantFilterDepartures($units, '10:00')), ['t7']);
check('find: 10:01 matches nothing (16 min)', $ids(assistantFilterDepartures($units, '10:01')), []);
check('find: "UFFIZI" case-insensitive', $ids(assistantFilterDepartures($units, null, 'UFFIZI')), ['g1', 'g2']);
check('find: "david" finds the Accademia title', $ids(assistantFilterDepartures($units, null, 'david')), ['t7']);
check('find: "accad\u{00E8}mia" accent-insensitive', $ids(assistantFilterDepartures($units, null, "accad\u{00E8}mia")), ['t7']);
check('find: language spanish matches the mixed group', $ids(assistantFilterDepartures($units, null, null, 'spanish')), ['g2']);
list($free, $busy) = assistantSplitFreeBusy($guides, $units, '10:00', '12:00');
check('free 10-12: Giulia busy (09:30 + 2 h overlaps), Meritxell free', [array_column($busy, 'guide_id'), in_array(5, array_column($free, 'guide_id'), true)], [[1], true]);
list($free, $busy) = assistantSplitFreeBusy($guides, $units, '11:30', '14:00');
check('free 11:30-14:00: a tour ending 11:30 or starting 14:00 does not overlap', count($busy), 0);
check('free: other departures that day listed', $free[array_search(5, array_column($free, 'guide_id'))]['other_departures_that_day'], ['14:00']);
check('time parse', [assistantParseTime('9'), assistantParseTime('9:30'), assistantParseTime('14.05'), assistantParseTime('25:00'), assistantParseTime('9am')], ['09:00', '09:30', '14:05', null, null]);
$rng = function ($input) { try { return assistantRange($input); } catch (AssistantToolError $e) { return 'error'; } };
check('range: start after end refused', $rng(['start' => '2026-10-02', 'end' => '2026-10-01']), 'error');
check('range: 94 days refused, 93 ok', [$rng(['start' => '2026-10-01', 'end' => '2027-01-02']), $rng(['start' => '2026-10-01', 'end' => '2027-01-01'])], ['error', ['2026-10-01', '2027-01-01']]);

$tz = new DateTimeZone('Europe/Rome');
$r = assistantDateRanges(new DateTime('2026-09-29 00:30', $tz)); // Tuesday
check('dates Tue 29 Sep: this week to Sun 4 Oct', $r['this week'], 'Tue 29 Sep (2026-09-29) to Sun 4 Oct (2026-10-04)');
check('dates Tue 29 Sep: weekend Sat 3 - Sun 4 Oct', $r['weekend'], 'Sat 3 Oct (2026-10-03) to Sun 4 Oct (2026-10-04)');
check('dates Tue 29 Sep: this month to Wed 30 Sep', $r['this month'], 'Tue 29 Sep (2026-09-29) to Wed 30 Sep (2026-09-30)');
check('dates Tue 29 Sep: next month = October', $r['next month'], 'Thu 1 Oct (2026-10-01) to Sat 31 Oct (2026-10-31)');
$r = assistantDateRanges(new DateTime('2026-10-04 18:00', $tz)); // Sunday
check('dates on a Sunday: this week = today only; weekend = today', [$r['this week'], $r['weekend']],
    ['Sun 4 Oct (2026-10-04) to Sun 4 Oct (2026-10-04)', 'Sun 4 Oct (2026-10-04) to Sun 4 Oct (2026-10-04)']);
$r = assistantDateRanges(new DateTime('2026-10-03 10:00', $tz)); // Saturday
check('dates on a Saturday: weekend = today and tomorrow', $r['weekend'], 'Sat 3 Oct (2026-10-03) to Sun 4 Oct (2026-10-04)');

echo "\n" . ($fail === 0 ? "ALL OK\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
