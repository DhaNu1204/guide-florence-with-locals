<?php
/**
 * Step 7.1: the assistant's tools - the ONLY way the model reaches data (plan Phase 7, rule 1).
 *
 * Each tool is {name, description, input_schema, handler, money}. The handler receives
 * ($conn, $input, $ctx) and returns an array that is sent back to the model as JSON; it throws
 * AssistantToolError for bad input (the model sees the message and can retry).
 * `money => true` tools are never offered to a user who cannot see Daily P&L
 * (Middleware::isPnlOwner, step 6.10) - the model cannot call what it was never given.
 *
 * Every tool is read-only. Changes are proposals the browser confirms (rule 3, step 7.5).
 */

require_once __DIR__ . '/../tour_classification.php'; // deriveListFields(): the Tours list's own PAX/time/language

class AssistantToolError extends Exception {}

function assistantToolRegistry() {
    return [
        [
            'name' => 'day_summary',
            'description' => 'Departures and guests on one date, exactly as the Tours page counts them: '
                . 'a merged/auto group counts as ONE departure, cancelled bookings are left out, museum ticket '
                . 'products are excluded. Returns totals, finished (start time already passed, Europe/Rome) vs '
                . 'still to run, and counts by product, by category (the Tours page tiles) and by language.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'The day, YYYY-MM-DD (Europe/Rome calendar).'],
                ],
                'required' => ['date'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolDaySummary',
            'money' => false,
        ],
    ];
}

/** The tools this user may use: money tools only for the P&L owner. */
function assistantToolsForUser(array $user, $registry = null) {
    $owner = class_exists('Middleware') && Middleware::isPnlOwner($user);
    $out = [];
    foreach ($registry !== null ? $registry : assistantToolRegistry() as $tool) {
        if (!empty($tool['money']) && !$owner) {
            continue;
        }
        $out[] = $tool;
    }
    return $out;
}

/** Tool definitions in the shape the Messages API expects (no handler, no money flag). */
function assistantToolDefinitions(array $tools) {
    $defs = [];
    foreach ($tools as $tool) {
        $defs[] = [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['input_schema'],
        ];
    }
    return $defs;
}

function assistantValidDate($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    list($y, $m, $d) = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}

/**
 * The Tours page category tiles (src/utils/tourCapacity.js tourCategory + the private mirror in
 * Tours.jsx categorySummary), for the same numbers the page shows.
 */
function assistantTourCategory($title) {
    $t = strtolower((string) $title);
    $uffizi = strpos($t, 'uffizi') !== false;
    $accademia = strpos($t, 'accademia') !== false || strpos($t, 'david') !== false;
    $pitti = strpos($t, 'pitti') !== false || strpos($t, 'boboli') !== false
        || strpos($t, 'palatina') !== false || strpos($t, 'palatine') !== false;
    $n = ($uffizi ? 1 : 0) + ($accademia ? 1 : 0) + ($pitti ? 1 : 0);
    if ($n >= 2) return 'Combo';
    if ($uffizi) return 'Uffizi';
    if ($pitti) return 'Pitti';
    if ($accademia) return 'Accademia';
    return 'Other';
}

/**
 * day_summary: rows for ONE date (the Tours list query: tours LEFT JOIN tour_groups, tickets
 * excluded through the products table) folded into departures exactly like Tours.jsx does:
 *  - a group is one departure (counted even when all its bookings are cancelled, as the page does);
 *    its guests = participants of its non-cancelled bookings (countActivePax)
 *  - a single tour is one departure unless cancelled; guests = the list's total_participants
 *  - start time = the list's start_time_str (Bokun startTimeStr), else the stored time
 * Pure: no database, `$now` injected - tools/assistant_check.php runs it on fixtures.
 *
 * @param array $rows Each: id, group_id, title, time, participants, cancelled, language, is_private,
 *                    bokun_data, group_time, group_display_name
 */
function assistantDaySummarize(array $rows, $date, DateTime $now) {
    $units = [];
    $cancelled = 0;
    foreach ($rows as $r) {
        $derived = deriveListFields(isset($r['bokun_data']) ? $r['bokun_data'] : null,
            isset($r['participants']) ? $r['participants'] : 0,
            isset($r['language']) ? $r['language'] : null,
            isset($r['title']) ? $r['title'] : '');
        $start = $derived['start_time_str'] !== null ? substr($derived['start_time_str'], 0, 5)
            : (isset($r['time']) && $r['time'] !== null ? substr((string) $r['time'], 0, 5) : null);
        $isCancelled = !empty($r['cancelled']);
        $lang = trim((string) $derived['language']);

        if (!empty($r['group_id'])) {
            $key = 'g' . (int) $r['group_id'];
            if (!isset($units[$key])) {
                $units[$key] = [
                    'group' => true,
                    'title' => (isset($r['group_display_name']) && $r['group_display_name'] !== '') ? $r['group_display_name'] : $r['title'],
                    'start' => null,
                    'guests' => 0,
                    'languages' => [],
                    'private' => false,
                ];
            }
            if ($start !== null && ($units[$key]['start'] === null || $start < $units[$key]['start'])) {
                $units[$key]['start'] = $start;
            }
            if (!$isCancelled) {
                $units[$key]['guests'] += (int) $r['participants'];
                if ($lang !== '') { $units[$key]['languages'][$lang] = true; }
            }
            if ($units[$key]['start'] === null && !empty($r['group_time'])) {
                $units[$key]['start'] = substr((string) $r['group_time'], 0, 5);
            }
            continue;
        }
        if ($isCancelled) {
            $cancelled++;
            continue;
        }
        $units['t' . (int) $r['id']] = [
            'group' => false,
            'title' => (string) $r['title'],
            'start' => $start,
            'guests' => (int) $derived['total_participants'],
            'languages' => $lang !== '' ? [$lang => true] : [],
            'private' => !empty($r['is_private']),
        ];
    }

    $nowHm = $now->format('Y-m-d') === $date ? $now->format('H:i') : null;
    $dayIsPast = $now->format('Y-m-d') > $date;
    $out = [
        'date' => $date,
        'weekday' => (new DateTime($date, new DateTimeZone('Europe/Rome')))->format('l'),
        'departures' => 0,
        'guests' => 0,
        'cancelled_single_bookings' => $cancelled,
        'finished' => ['departures' => 0, 'guests' => 0],
        'to_run' => ['departures' => 0, 'guests' => 0],
        'by_product' => [],
        'by_category' => [],
        'by_language' => [],
        'now_europe_rome' => $now->format('Y-m-d H:i'),
        'counting_rule' => 'group = 1 departure; cancelled bookings excluded; ticket products excluded; finished = start time already passed',
    ];
    foreach ($units as $u) {
        $out['departures']++;
        $out['guests'] += $u['guests'];
        $finished = $dayIsPast || ($nowHm !== null && $u['start'] !== null && $u['start'] <= $nowHm);
        $bucket = $finished ? 'finished' : 'to_run';
        $out[$bucket]['departures']++;
        $out[$bucket]['guests'] += $u['guests'];

        $p = $u['title'] !== '' ? $u['title'] : 'Untitled';
        if (!isset($out['by_product'][$p])) { $out['by_product'][$p] = ['departures' => 0, 'guests' => 0]; }
        $out['by_product'][$p]['departures']++;
        $out['by_product'][$p]['guests'] += $u['guests'];

        $cat = assistantTourCategory($u['title']);
        if ($u['private']) { $cat = $cat === 'Other' ? 'Private (other)' : 'Private ' . $cat; }
        if (!isset($out['by_category'][$cat])) { $out['by_category'][$cat] = ['departures' => 0, 'guests' => 0]; }
        $out['by_category'][$cat]['departures']++;
        $out['by_category'][$cat]['guests'] += $u['guests'];

        $langs = array_keys($u['languages']);
        sort($langs);
        $l = count($langs) > 0 ? implode(', ', $langs) : 'Unknown';
        if (!isset($out['by_language'][$l])) { $out['by_language'][$l] = ['departures' => 0, 'guests' => 0]; }
        $out['by_language'][$l]['departures']++;
        $out['by_language'][$l]['guests'] += $u['guests'];
    }
    foreach (['by_product', 'by_category', 'by_language'] as $k) {
        uasort($out[$k], function ($a, $b) { return $b['departures'] - $a['departures']; });
    }
    return $out;
}

/** Handler: the Tours list's own WHERE for one day (t.date = ?, tickets excluded, manual rows kept). */
function assistantToolDaySummary($conn, array $input, array $ctx) {
    $date = isset($input['date']) ? $input['date'] : null;
    if (!assistantValidDate($date)) {
        throw new AssistantToolError('date must be a real date in YYYY-MM-DD format');
    }
    $stmt = $conn->prepare("
        SELECT t.id, t.group_id, t.title, t.time, t.participants, t.cancelled, t.language, t.is_private,
               t.bokun_data, tg.group_time, tg.display_name AS group_display_name
        FROM tours t
        LEFT JOIN tour_groups tg ON t.group_id = tg.id
        LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
        WHERE t.date = ? AND (pr.product_type = 'tour' OR t.product_id IS NULL)
        ORDER BY t.time ASC, t.id ASC
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return assistantDaySummarize($rows, $date, $ctx['now']);
}
