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
check('owner (dhanu) gets money', $names(assistantToolsForUser(['role' => 'admin', 'username' => 'dhanu', 'email' => ''], $registry)), ['day_summary', 'money']);
check('second admin (sudesh) does not', $names(assistantToolsForUser(['role' => 'admin', 'username' => 'sudesh', 'email' => ''], $registry)), ['day_summary']);
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
check('loop: tools offered to the API', array_column($fake->payloads[0]['tools'], 'name'), ['day_summary']);
check('loop: system prompt has today (Rome)', strpos($fake->payloads[0]['system'], '2026-09-28') !== false, true);
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

echo "\n" . ($fail === 0 ? "ALL OK\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
