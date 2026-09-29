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
require_once __DIR__ . '/departure_queries.php';        // step 7.2: the unassigned report + per-departure rows
require_once __DIR__ . '/pnl_core.php';                 // step 7.3: the Daily P&L computation (money tool)

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
        [
            'name' => 'find_departures',
            'description' => 'Departures on one date, optionally filtered. A departure is a merged/auto group or a single '
                . 'tour (departure_id g<id> or t<id>); cancelled bookings and museum tickets are left out. Each has '
                . 'time, product title, language(s), guests, bookings and the current guide (null = no guide). '
                . 'time matches within 15 minutes; product_text is a case- and accent-insensitive part of the title '
                . '(e.g. "uffizi", "david"); language e.g. "English", "Italian", "Spanish". Max 50 rows.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour (optional)'],
                    'product_text' => ['type' => 'string', 'description' => 'part of the product title (optional)'],
                    'language' => ['type' => 'string', 'description' => 'tour language (optional)'],
                ],
                'required' => ['date'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolFindDepartures',
            'money' => false,
        ],
        [
            'name' => 'unassigned_departures',
            'description' => 'Departures with NO guide between two dates (inclusive) - exactly the Unassigned Report of '
                . 'the Tours page (same query). Max 50 rows listed; total is the full count. Range up to 93 days.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['start', 'end'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolUnassignedDepartures',
            'money' => false,
        ],
        [
            'name' => 'find_guide',
            'description' => 'Look up a guide by (part of) their name. Ignores case and accents and tolerates small '
                . 'typos ("Guilia" finds Giulia). Returns the top 3 with a score 0-1 and confident=true only when the '
                . 'best match is clearly ahead; when not confident, ask the user which one they mean. Inactive guides '
                . '(no new work) are still found and marked inactive: true - say so; partner agencies are marked '
                . 'partner_agency: true.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name_text' => ['type' => 'string', 'description' => 'the name as the user wrote it'],
                ],
                'required' => ['name_text'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolFindGuide',
            'money' => false,
        ],
        [
            'name' => 'guide_schedule',
            'description' => 'The departures a guide is assigned to between two dates (inclusive). Get guide_id from '
                . 'find_guide first. Max 50 rows; range up to 93 days.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'guide_id' => ['type' => 'integer'],
                    'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['guide_id', 'start', 'end'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolGuideSchedule',
            'money' => false,
        ],
        [
            'name' => 'free_guides',
            'description' => 'Active guides with no departure overlapping a time window on one date, plus the busy ones, '
                . 'partner agencies in their own list (never count them as free guides) and any availability replies for '
                . "tours that day. Inactive guides are left out. Each departure lasts its product's duration; for products "
                . 'without one 120 minutes is assumed - the result lists those products (assumed_120_for); mention them '
                . 'only when that list is not empty.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'from' => ['type' => 'string', 'description' => 'HH:MM window start'],
                    'to' => ['type' => 'string', 'description' => 'HH:MM window end (after from)'],
                ],
                'required' => ['date', 'from', 'to'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolFreeGuides',
            'money' => false,
        ],
        [
            'name' => 'money',
            'description' => 'Money for tours RUNNING in a date range (not bookings made then), computed by the Daily P&L '
                . 'page itself: Net Revenue (retail - commission - card fee), each cost line as the page labels it '
                . '(Tickets, Guide, Radio, Gelato, Staff, Other), Total Costs, Profit, departures and guests; manual '
                . 'overrides and merged departures included. product_text: a page product line (Combo, Uffizi, Accademia, '
                . 'Pitti, Borghese, Mixed, Other, Tickets) or part of a title; channel: e.g. GetYourGuide, Viator; '
                . 'breakdown: ["product"] and/or ["channel"]. Range up to 93 days.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'product_text' => ['type' => 'string'],
                    'channel' => ['type' => 'string'],
                    'breakdown' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['product', 'channel']]],
                ],
                'required' => ['start', 'end'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolMoney',
            'money' => true,
        ],
        [
            'name' => 'show_blocks',
            'description' => 'Show structured data next to your short text answer (the app renders these as cards). '
                . 'Call it once, after the data tools, with every block for this answer. Types: '
                . 'stat {type, label, value}; '
                . 'departure_list {type, rows: [{departure_id, date, time, title, language?, guests?, bookings?, guide?}]}; '
                . 'table {type, columns: [..], rows: [[..], ..]}; '
                . 'choices {type, prompt, options: [{label, value}]} when the user must pick one; '
                . 'link {type, label, route, query} to open an app page (routes: /tours, /guides, /payments, /tickets, '
                . '/priority-tickets, /radios, /guide-reports, /daily-pnl; query keys: /tours takes date, or start + end, '
                . 'plus unassigned=1, guide_id, language; /daily-pnl takes date, or start + end; other routes take none). '
                . 'Copy values from tool results; never invent rows. Invalid blocks are dropped.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'blocks' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                        'description' => 'up to 6 blocks, each with a "type"',
                    ],
                ],
                'required' => ['blocks'],
                'additionalProperties' => false,
            ],
            'handler' => 'assistantToolShowBlocks',
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

// ---- step 7.2: shared helpers ----------------------------------------------------------------

const ASSISTANT_LIST_CAP = 50;
const ASSISTANT_MAX_RANGE_DAYS = 93;
const ASSISTANT_ASSUMED_DURATION_MIN = 120; // step 7.2b: only for products without duration_minutes
const ASSISTANT_ACTIVE_RULE = 'inactive guides (guides.active = 0) take no new work and are left out; partner agencies are listed apart';

/** [first 50 rows, total, truncated] */
function assistantCap(array $rows) {
    $total = count($rows);
    return [array_slice($rows, 0, ASSISTANT_LIST_CAP), $total, $total > ASSISTANT_LIST_CAP];
}

/** Lower case, accents off, punctuation to spaces, single spaces. "Nicolò D'Amico" -> "nicolo d amico" */
function assistantNorm($s) {
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'ł' => 'l', 'ý' => 'y',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Optimal string alignment distance: Levenshtein + adjacent transpositions ("guilia" -> "giulia" = 1). */
function assistantOsa($a, $b) {
    $la = strlen($a); $lb = strlen($b);
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    $d = [];
    for ($i = 0; $i <= $la; $i++) { $d[$i] = [$i]; }
    for ($j = 0; $j <= $lb; $j++) { $d[0][$j] = $j; }
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$la][$lb];
}

function assistantTokenSim($q, $n) {
    if ($q === $n) return 1.0;
    if (strlen($q) === 1) return (strpos($n, $q) === 0) ? 0.9 : 0.0;          // an initial: "giulia r"
    if (strlen($q) >= 3 && strpos($n, $q) === 0) return 0.9;                    // a prefix: "cater" -> caterina
    $sim = 1 - assistantOsa($q, $n) / max(strlen($q), strlen($n));
    return max(0.0, $sim);
}

/**
 * Score 0..1 of a typed name against a guide's full name: every typed word is matched to its best
 * word in the name (typos, initials, prefixes allowed) and the scores averaged; the whole string is
 * compared too and the better of the two wins.
 */
function assistantNameScore($query, $fullName) {
    $q = assistantNorm($query);
    $n = assistantNorm($fullName);
    if ($q === '' || $n === '') return 0.0;
    $qt = explode(' ', $q);
    $nt = explode(' ', $n);
    $sum = 0.0;
    foreach ($qt as $w) {
        $best = 0.0;
        foreach ($nt as $x) { $best = max($best, assistantTokenSim($w, $x)); }
        $sum += $best;
    }
    $byWords = $sum / count($qt);
    $whole = 1 - assistantOsa(str_replace(' ', '', $q), str_replace(' ', '', $n)) / max(strlen(str_replace(' ', '', $q)), strlen(str_replace(' ', '', $n)));
    return round(max($byWords, $whole), 3);
}

/**
 * Top 3 guides for a typed name. confident = the best scores >= 0.75 and is at least 0.15 ahead of
 * the next one (two guides both called Anna are never "confident").
 * Pure: tools/assistant_check.php runs it on a fixed list.
 */
function assistantRankGuides($query, array $guides) {
    $scored = [];
    foreach ($guides as $g) {
        $row = ['guide_id' => (int) $g['id'], 'name' => (string) $g['name'],
                'languages' => isset($g['languages']) ? (string) $g['languages'] : '',
                'score' => assistantNameScore($query, $g['name'])];
        // step 7.2b: still findable, but flagged
        if (array_key_exists('active', $g) && (int) $g['active'] === 0) { $row['inactive'] = true; }
        if (!empty($g['is_partner_agency'])) { $row['partner_agency'] = true; }
        $scored[] = $row;
    }
    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) return strcmp($a['name'], $b['name']);
        return $a['score'] < $b['score'] ? 1 : -1;
    });
    $top = array_values(array_filter(array_slice($scored, 0, 3), function ($m) { return $m['score'] >= 0.5; }));
    $best = isset($top[0]) ? $top[0]['score'] : 0;
    $second = isset($top[1]) ? $top[1]['score'] : 0;
    return ['matches' => $top, 'confident' => $best >= 0.75 && ($best - $second) >= 0.15];
}

/** "9", "9:5"? no - "9", "09", "9:30", "09.30", "930" are not all valid; accepted: H, HH, H:MM, HH:MM, HH.MM */
function assistantParseTime($value) {
    if (!is_string($value) && !is_int($value)) return null;
    $v = trim((string) $value);
    if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?$/', $v, $m)) return null;
    $h = (int) $m[1]; $i = isset($m[2]) ? (int) $m[2] : 0;
    if ($h > 23 || $i > 59) return null;
    return sprintf('%02d:%02d', $h, $i);
}

function assistantMinutes($hm) {
    return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
}

/** Validates a start/end pair; returns [start, end] or throws. */
function assistantRange(array $input, $startKey = 'start', $endKey = 'end') {
    $s = isset($input[$startKey]) ? $input[$startKey] : null;
    $e = isset($input[$endKey]) ? $input[$endKey] : null;
    if (!assistantValidDate($s) || !assistantValidDate($e)) {
        throw new AssistantToolError("$startKey and $endKey must be real dates in YYYY-MM-DD format");
    }
    if ($s > $e) {
        throw new AssistantToolError("$startKey must not be after $endKey");
    }
    $days = (new DateTime($s))->diff(new DateTime($e))->days + 1;
    if ($days > ASSISTANT_MAX_RANGE_DAYS) {
        throw new AssistantToolError('the range is ' . $days . ' days; ask for at most ' . ASSISTANT_MAX_RANGE_DAYS);
    }
    return [$s, $e];
}

function assistantGuides($conn) {
    ensureGuideFlagColumns($conn); // step 7.2b
    $res = $conn->query("SELECT id, name, languages, active, is_partner_agency FROM guides ORDER BY name ASC, id ASC");
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = $r; }
    return $out;
}

/** A departure row as the model sees it (no internal numeric id / type duplication). */
function assistantDepartureOut(array $u, $withDate = true) {
    $o = ['departure_id' => $u['departure_id'], 'type' => $u['type']];
    if ($withDate) { $o['date'] = $u['date']; }
    $o['time'] = $u['time'];
    $o['title'] = $u['title'];
    $o['languages'] = $u['languages'];
    $o['guests'] = $u['guests'];
    $o['bookings'] = $u['bookings'];
    $o['guide_id'] = $u['guide_id'];
    $o['guide'] = $u['guide_name'];
    return $o;
}

/**
 * find_departures filter, pure: time within +-15 min, product text in the title (case/accent
 * insensitive), language among the departure's languages (case-insensitive).
 */
function assistantFilterDepartures(array $units, $time = null, $productText = null, $language = null) {
    $out = [];
    $pt = $productText !== null ? assistantNorm($productText) : '';
    $lang = $language !== null ? assistantNorm($language) : '';
    foreach ($units as $u) {
        if ($time !== null && abs(assistantMinutes($u['time']) - assistantMinutes($time)) > 15) continue;
        if ($pt !== '' && strpos(assistantNorm($u['title']), $pt) === false) continue;
        if ($lang !== '') {
            $ok = false;
            foreach ($u['languages'] as $l) { if (assistantNorm($l) === $lang) { $ok = true; break; } }
            if (!$ok) continue;
        }
        $out[] = $u;
    }
    return $out;
}

/**
 * free_guides core, pure: a guide is busy when one of their departures [start, start + duration)
 * overlaps the window [from, to). Duration = the product's duration_minutes, else 120 (step 7.2b;
 * the titles that fell back are returned). Inactive guides are left out; partner agencies go to
 * their own list, never into free.
 * @return array [free, busy, partner_agencies, assumed_120_for (titles)]
 */
function assistantSplitFreeBusy(array $guides, array $units, $from, $to) {
    $f = assistantMinutes($from); $t = assistantMinutes($to);
    $busy = []; $day = []; $assumed = [];
    foreach ($units as $u) {
        if ($u['guide_id'] === null) continue;
        $s = assistantMinutes($u['time']);
        $len = isset($u['duration_minutes']) && $u['duration_minutes'] !== null ? (int) $u['duration_minutes'] : null;
        if ($len === null) { $len = ASSISTANT_ASSUMED_DURATION_MIN; $assumed[$u['title']] = true; }
        $day[$u['guide_id']][] = $u['time'];
        if ($s < $t && $s + $len > $f) {
            $busy[$u['guide_id']][] = ['departure_id' => $u['departure_id'], 'time' => $u['time'], 'title' => $u['title'],
                                       'until' => sprintf('%02d:%02d', intdiv($s + $len, 60) % 24, ($s + $len) % 60)];
        }
    }
    $free = []; $busyOut = []; $partners = [];
    foreach ($guides as $g) {
        $id = (int) $g['id'];
        if (array_key_exists('active', $g) && (int) $g['active'] === 0) continue; // no new work
        if (!empty($g['is_partner_agency'])) {
            $partners[] = ['guide_id' => $id, 'name' => $g['name'], 'free_in_window' => !isset($busy[$id]),
                           'departures_in_window' => isset($busy[$id]) ? $busy[$id] : []];
            continue;
        }
        if (isset($busy[$id])) {
            $busyOut[] = ['guide_id' => $id, 'name' => $g['name'], 'departures' => $busy[$id]];
        } else {
            $free[] = ['guide_id' => $id, 'name' => $g['name'], 'languages' => (string) $g['languages'],
                       'other_departures_that_day' => isset($day[$id]) ? $day[$id] : []];
        }
    }
    return [$free, $busyOut, $partners, array_keys($assumed)];
}

// ---- step 7.2: tool handlers -----------------------------------------------------------------

function assistantToolFindDepartures($conn, array $input, array $ctx) {
    $date = isset($input['date']) ? $input['date'] : null;
    if (!assistantValidDate($date)) {
        throw new AssistantToolError('date must be a real date in YYYY-MM-DD format');
    }
    $time = null;
    if (isset($input['time']) && $input['time'] !== '') {
        $time = assistantParseTime($input['time']);
        if ($time === null) throw new AssistantToolError('time must be HH:MM (24-hour)');
    }
    $pt = isset($input['product_text']) && trim((string) $input['product_text']) !== '' ? (string) $input['product_text'] : null;
    $lang = isset($input['language']) && trim((string) $input['language']) !== '' ? (string) $input['language'] : null;
    $matches = assistantFilterDepartures(fwlDepartureUnits($conn, $date, $date), $time, $pt, $lang);
    list($rows, $total, $truncated) = assistantCap($matches);
    return [
        'date' => $date,
        'filters' => ['time' => $time !== null ? $time . ' (+-15 min)' : null, 'product_text' => $pt, 'language' => $lang],
        'total' => $total,
        'truncated' => $truncated,
        'departures' => array_map(function ($u) { return assistantDepartureOut($u, false); }, $rows),
    ];
}

function assistantToolUnassignedDepartures($conn, array $input, array $ctx) {
    list($start, $end) = assistantRange($input);
    // The Tours page's own conditions for start_date+end_date with the default product_type=tour,
    // then the report's "t.cancelled = 0" - identical to tours.php?action=unassigned-report.
    $rows = fwlUnassignedReport($conn,
        ["t.date >= ?", "t.date <= ?", "(pr.product_type = 'tour' OR t.product_id IS NULL)", "t.cancelled = 0"],
        [$start, $end], 'ss');
    list($list, $total, $truncated) = assistantCap($rows);
    return [
        'start' => $start,
        'end' => $end,
        'total' => $total,
        'truncated' => $truncated,
        'departures' => array_map(function ($r) {
            return ['departure_id' => $r['tour_unit'], 'date' => $r['date'], 'time' => $r['time'], 'title' => $r['title'],
                    'language' => $r['language'], 'guests' => $r['pax'], 'bookings' => $r['bookings']];
        }, $list),
    ];
}

function assistantToolFindGuide($conn, array $input, array $ctx) {
    $q = isset($input['name_text']) ? trim((string) $input['name_text']) : '';
    if ($q === '' || mb_strlen($q, 'UTF-8') > 80) {
        throw new AssistantToolError('name_text is required (up to 80 characters)');
    }
    $r = assistantRankGuides($q, assistantGuides($conn));
    return ['query' => $q, 'confident' => $r['confident'], 'matches' => $r['matches'],
            'total' => count($r['matches']), 'truncated' => false, 'active_rule' => ASSISTANT_ACTIVE_RULE];
}

function assistantToolGuideSchedule($conn, array $input, array $ctx) {
    $gid = isset($input['guide_id']) && is_numeric($input['guide_id']) ? (int) $input['guide_id'] : 0;
    list($start, $end) = assistantRange($input);
    $stmt = $conn->prepare("SELECT id, name FROM guides WHERE id = ?");
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$g) {
        throw new AssistantToolError('no guide with id ' . $gid . '; use find_guide first');
    }
    list($rows, $total, $truncated) = assistantCap(fwlDepartureUnits($conn, $start, $end, $gid));
    return [
        'guide' => ['guide_id' => (int) $g['id'], 'name' => $g['name']],
        'start' => $start, 'end' => $end,
        'total' => $total, 'truncated' => $truncated,
        'guests_total' => array_sum(array_map(function ($u) { return $u['guests']; }, $rows)),
        'departures' => array_map(function ($u) { return assistantDepartureOut($u); }, $rows),
    ];
}

function assistantToolFreeGuides($conn, array $input, array $ctx) {
    $date = isset($input['date']) ? $input['date'] : null;
    if (!assistantValidDate($date)) {
        throw new AssistantToolError('date must be a real date in YYYY-MM-DD format');
    }
    $from = isset($input['from']) ? assistantParseTime($input['from']) : null;
    $to = isset($input['to']) ? assistantParseTime($input['to']) : null;
    if ($from === null || $to === null || $from >= $to) {
        throw new AssistantToolError('from and to must be HH:MM with from before to');
    }
    list($free, $busy, $partners, $assumed) = assistantSplitFreeBusy(assistantGuides($conn), fwlDepartureUnits($conn, $date, $date), $from, $to);

    $availability = null;
    $has = $conn->query("SHOW TABLES LIKE 'availability_requests'");
    if ($has && $has->num_rows > 0) {
        $stmt = $conn->prepare("SELECT ar.guide_id, g.name, ar.status, LEFT(t.time, 5) AS time, t.title
                                FROM availability_requests ar
                                JOIN tours t ON t.id = ar.tour_id
                                LEFT JOIN guides g ON g.id = ar.guide_id
                                WHERE t.date = ?
                                ORDER BY t.time, g.name");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $res = $stmt->get_result();
        $availability = [];
        while ($r = $res->fetch_assoc()) {
            $availability[] = ['guide_id' => (int) $r['guide_id'], 'name' => $r['name'], 'status' => $r['status'],
                               'tour_time' => $r['time'], 'tour' => $r['title']];
        }
        $stmt->close();
    }
    list($freeRows, $total, $truncated) = assistantCap($free);
    return [
        'date' => $date,
        'window' => $from . '-' . $to,
        'duration_rule' => "each departure lasts its product's duration; products without one are assumed to last 120 minutes",
        'assumed_120_for' => $assumed,
        'active_rule' => ASSISTANT_ACTIVE_RULE,
        'total' => $total,
        'truncated' => $truncated,
        'free' => $freeRows,
        'busy' => $busy,
        'partner_agencies' => $partners,
        'availability_replies' => $availability,
    ];
}

// ---- step 7.2: answer blocks -----------------------------------------------------------------

const ASSISTANT_MAX_BLOCKS = 6;
const ASSISTANT_LINK_ROUTES = ['/tours', '/guides', '/payments', '/tickets', '/priority-tickets', '/radios', '/guide-reports', '/daily-pnl'];
// Step 7.4: the query keys each page really reads (Tours / Daily P&L deep links); others take none.
const ASSISTANT_LINK_QUERY_KEYS = [
    '/tours' => ['date', 'start', 'end', 'unassigned', 'guide_id', 'language'],
    '/daily-pnl' => ['date', 'start', 'end'],
];

function assistantStr($v, $max) {
    if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
    $s = trim((string) $v);
    if ($s === '' || mb_strlen($s, 'UTF-8') > $max) return null;
    return $s;
}

function assistantOptInt($row, $key) {
    if (!array_key_exists($key, $row) || $row[$key] === null) return [true, null];
    if (is_int($row[$key]) || (is_string($row[$key]) && ctype_digit($row[$key]))) return [true, (int) $row[$key]];
    return [false, null];
}

/**
 * One block in, the clean block out (only the schema's fields, re-built from scratch) or null.
 * Nothing the model sends is passed through as-is.
 */
function assistantValidateBlock($b) {
    if (!is_array($b) || !isset($b['type']) || !is_string($b['type'])) return null;
    switch ($b['type']) {
        case 'stat':
            $label = assistantStr(isset($b['label']) ? $b['label'] : null, 80);
            $value = isset($b['value']) && (is_int($b['value']) || is_float($b['value'])) ? $b['value'] : assistantStr(isset($b['value']) ? $b['value'] : null, 40);
            return ($label !== null && $value !== null) ? ['type' => 'stat', 'label' => $label, 'value' => $value] : null;

        case 'departure_list':
            if (!isset($b['rows']) || !is_array($b['rows']) || count($b['rows']) === 0 || count($b['rows']) > ASSISTANT_LIST_CAP) return null;
            $rows = [];
            foreach ($b['rows'] as $r) {
                if (!is_array($r)) return null;
                $id = isset($r['departure_id']) && is_string($r['departure_id']) && preg_match('/^[gt]\d{1,10}$/', $r['departure_id']) ? $r['departure_id'] : null;
                $date = isset($r['date']) && assistantValidDate($r['date']) ? $r['date'] : null;
                $time = isset($r['time']) ? assistantParseTime($r['time']) : null;
                $title = assistantStr(isset($r['title']) ? $r['title'] : null, 200);
                if ($id === null || $date === null || $time === null || $title === null) return null;
                $row = ['departure_id' => $id, 'date' => $date, 'time' => $time, 'title' => $title];
                if (isset($r['language'])) {
                    $lang = is_array($r['language']) ? implode(', ', array_filter($r['language'], 'is_string')) : $r['language'];
                    $lang = assistantStr($lang, 60);
                    if ($lang === null) return null;
                    $row['language'] = $lang;
                }
                foreach (['guests', 'bookings'] as $k) {
                    list($ok, $n) = assistantOptInt($r, $k);
                    if (!$ok) return null;
                    if ($n !== null) $row[$k] = $n;
                }
                if (array_key_exists('guide', $r)) {
                    $gname = $r['guide'] === null ? null : assistantStr($r['guide'], 100);
                    if ($r['guide'] !== null && $gname === null) return null;
                    $row['guide'] = $gname;
                }
                $rows[] = $row;
            }
            return ['type' => 'departure_list', 'rows' => $rows];

        case 'table':
            if (!isset($b['columns'], $b['rows']) || !is_array($b['columns']) || !is_array($b['rows'])) return null;
            $cols = [];
            foreach ($b['columns'] as $c) { $c = assistantStr($c, 40); if ($c === null) return null; $cols[] = $c; }
            if (count($cols) < 1 || count($cols) > 8 || count($b['rows']) > ASSISTANT_LIST_CAP) return null;
            $rows = [];
            foreach ($b['rows'] as $r) {
                if (!is_array($r) || count($r) !== count($cols)) return null;
                $clean = [];
                foreach (array_values($r) as $cell) {
                    if ($cell === null || is_int($cell) || is_float($cell)) { $clean[] = $cell; continue; }
                    if (is_bool($cell)) { $clean[] = $cell ? 'yes' : 'no'; continue; }
                    if (!is_string($cell) || mb_strlen($cell, 'UTF-8') > 200) return null;
                    $clean[] = $cell;
                }
                $rows[] = $clean;
            }
            return ['type' => 'table', 'columns' => $cols, 'rows' => $rows];

        case 'choices':
            $prompt = assistantStr(isset($b['prompt']) ? $b['prompt'] : null, 200);
            if ($prompt === null || !isset($b['options']) || !is_array($b['options']) || count($b['options']) < 2 || count($b['options']) > 8) return null;
            $opts = [];
            foreach ($b['options'] as $o) {
                if (is_string($o)) { $o = ['label' => $o, 'value' => $o]; }
                if (!is_array($o)) return null;
                $label = assistantStr(isset($o['label']) ? $o['label'] : null, 120);
                $value = assistantStr(isset($o['value']) ? $o['value'] : (isset($o['label']) ? $o['label'] : null), 200);
                if ($label === null || $value === null) return null;
                $opts[] = ['label' => $label, 'value' => $value];
            }
            return ['type' => 'choices', 'prompt' => $prompt, 'options' => $opts];

        case 'link':
            $label = assistantStr(isset($b['label']) ? $b['label'] : null, 80);
            $route = isset($b['route']) && is_string($b['route']) && in_array($b['route'], ASSISTANT_LINK_ROUTES, true) ? $b['route'] : null;
            if ($label === null || $route === null) return null;
            $query = [];
            if (isset($b['query']) && $b['query'] !== null) {
                if (!is_array($b['query'])) return null;
                foreach ($b['query'] as $k => $v) {
                    $allowedKeys = isset(ASSISTANT_LINK_QUERY_KEYS[$route]) ? ASSISTANT_LINK_QUERY_KEYS[$route] : [];
                    if (!in_array($k, $allowedKeys, true)) return null;
                    $v = assistantStr($v, 60);
                    if ($v === null || !preg_match('/^[A-Za-z0-9 _:.,-]+$/', $v)) return null;
                    $query[$k] = $v;
                }
            }
            return ['type' => 'link', 'label' => $label, 'route' => $route, 'query' => (object) $query];
    }
    return null;
}

/** show_blocks: validate, keep the good ones for the reply, tell the model what was dropped. */
function assistantToolShowBlocks($conn, array $input, array $ctx) {
    if (!isset($input['blocks']) || !is_array($input['blocks'])) {
        throw new AssistantToolError('blocks must be an array');
    }
    $store = isset($ctx['blocks']) && $ctx['blocks'] instanceof ArrayObject ? $ctx['blocks'] : new ArrayObject();
    $accepted = 0; $dropped = [];
    foreach (array_values($input['blocks']) as $i => $b) {
        if (count($store) >= ASSISTANT_MAX_BLOCKS) { $dropped[] = "block $i: more than " . ASSISTANT_MAX_BLOCKS . ' blocks'; continue; }
        $clean = assistantValidateBlock($b);
        if ($clean === null) {
            $dropped[] = "block $i (" . (is_array($b) && isset($b['type']) && is_string($b['type']) ? $b['type'] : '?') . '): does not match the schema';
            continue;
        }
        $store->append($clean);
        $accepted++;
    }
    return ['accepted' => $accepted, 'dropped' => $dropped];
}

// ---- step 7.3: money (P&L owner only) ---------------------------------------------------------

const ASSISTANT_MONEY_SECONDS = 20;
const ASSISTANT_MONEY_CHUNK_DAYS = 31;
// The Daily P&L page's own labels (DailyPnL.jsx COST_FIELDS / summary cards).
const ASSISTANT_PNL_COST_LABELS = [
    'ticket_cost' => 'Tickets', 'guide_cost' => 'Guide', 'radio_cost' => 'Radio',
    'gelato_cost' => 'Gelato', 'staff_cost' => 'Staff', 'other_cost' => 'Other',
];
const ASSISTANT_PNL_CATEGORIES = ['Combo', 'Uffizi', 'Accademia', 'Pitti', 'Borghese', 'Mixed', 'Other', 'Tickets'];

/** The page's product line for a P&L row: its category, or "Tickets" for a ticket product. */
function assistantPnlCategory(array $r) {
    return !empty($r['is_ticket']) ? 'Tickets' : (string) $r['category'];
}

/**
 * Filter P&L rows (pure). product_text naming a page category ("uffizi", "tickets") keeps that
 * category - the page's "Profit by product" line; any other text matches the title.
 * channel keeps departures whose bookings are ALL from that channel; a departure mixing channels
 * cannot be split without new maths, so it is left out and counted.
 * @return array [rows, product rule used, mixed-channel departures left out]
 */
function assistantPnlFilter(array $rows, $productText = null, $channel = null) {
    $rule = null;
    if ($productText !== null && trim($productText) !== '') {
        $pt = assistantNorm($productText);
        $cat = null;
        foreach (ASSISTANT_PNL_CATEGORIES as $c) { if (assistantNorm($c) === $pt || assistantNorm($c) === rtrim($pt, 's')) { $cat = $c; } }
        if ($cat !== null) {
            $rule = "category $cat (the page's Profit by product line)";
            $rows = array_values(array_filter($rows, function ($r) use ($cat) { return assistantPnlCategory($r) === $cat; }));
        } else {
            $rule = "title contains \"$productText\"";
            $rows = array_values(array_filter($rows, function ($r) use ($pt) { return strpos(assistantNorm($r['title']), $pt) !== false; }));
        }
    }
    $mixed = 0;
    if ($channel !== null && trim($channel) !== '') {
        $ch = assistantNorm($channel);
        $keep = [];
        foreach ($rows as $r) {
            $chs = array_values(array_unique(array_map('assistantNorm', (array) $r['channels'])));
            $hits = array_filter($chs, function ($c) use ($ch) { return strpos($c, $ch) !== false; });
            if (count($hits) === 0) continue;
            if (count($hits) < count($chs)) { if ($r['bookings'] > 0) $mixed++; continue; }
            $keep[] = $r;
        }
        $rows = $keep;
    }
    return [$rows, $rule, $mixed];
}

/** Totals in the page's words (pnlTotals does the maths; this only renames). */
function assistantPnlSummary(array $t) {
    $costs = [];
    foreach (ASSISTANT_PNL_COST_LABELS as $k => $label) {
        $costs[] = ['label' => $label, 'amount' => $t[$k]];
    }
    return [
        'net_revenue' => $t['net'],            // page card "Net Revenue"
        'retail' => $t['retail'],
        'commission' => $t['commission'],
        'card_fee' => $t['card_fee'],
        'costs' => $costs,
        'total_cost' => $t['total_cost'],      // page card "Total Costs"
        'profit' => $t['profit'],              // page card "Day/Week/Month Profit"
        // Step 7.4: NOT the Tours page's departure count - P&L rows include ticket-only products.
        'pnl_rows_incl_ticket_only' => $t['units'],
        'guests' => $t['pax'],
        'estimated_departures' => $t['estimated_units'],
    ];
}

function assistantToolMoney($conn, array $input, array $ctx) {
    // Lock (b): the handler checks the owner itself, whatever the offered tool list said.
    $user = isset($ctx['user']) && is_array($ctx['user']) ? $ctx['user'] : [];
    if (!class_exists('Middleware') || !Middleware::isPnlOwner($user)) {
        throw new AssistantToolError('not allowed: money figures are only available on the owner account');
    }
    list($start, $end) = assistantRange($input);
    $pt = isset($input['product_text']) && trim((string) $input['product_text']) !== '' ? (string) $input['product_text'] : null;
    $ch = isset($input['channel']) && trim((string) $input['channel']) !== '' ? (string) $input['channel'] : null;
    $breakdown = isset($input['breakdown']) && is_array($input['breakdown']) ? $input['breakdown'] : [];

    $t0 = microtime(true);
    pnlEnsureTables($conn);
    $settings = pnlLoadSettings($conn);
    $rows = [];
    $cursor = new DateTime($start);
    $last = new DateTime($end);
    while ($cursor <= $last) {
        if (microtime(true) - $t0 > ASSISTANT_MONEY_SECONDS) {
            throw new AssistantToolError('the range is too large to compute in time; ask for a shorter range (e.g. one month)');
        }
        $chunkEnd = (clone $cursor)->modify('+' . (ASSISTANT_MONEY_CHUNK_DAYS - 1) . ' days');
        if ($chunkEnd > $last) { $chunkEnd = clone $last; }
        foreach (pnlBuildRows($conn, $cursor->format('Y-m-d'), $chunkEnd->format('Y-m-d'), $settings) as $r) { $rows[] = $r; }
        $cursor = (clone $chunkEnd)->modify('+1 day');
    }
    if (microtime(true) - $t0 > ASSISTANT_MONEY_SECONDS) {
        throw new AssistantToolError('the range is too large to compute in time; ask for a shorter range (e.g. one month)');
    }
    list($rows, $rule, $mixed) = assistantPnlFilter($rows, $pt, $ch);

    $out = ['start' => $start, 'end' => $end, 'currency' => 'EUR',
            'filters' => ['product' => $rule, 'channel' => $ch]];
    $out += assistantPnlSummary(pnlTotals($rows));
    if ($ch !== null) {
        $out['mixed_channel_departures_left_out'] = $mixed;
    }
    // The page's month view adds the monthly overheads; same fields, only for a whole calendar month.
    $s = new DateTime($start);
    if ($pt === null && $ch === null && $s->format('d') === '01' && $end === $s->format('Y-m-t')) {
        $overhead = round($settings['staff_monthly'] + $settings['office_monthly'] + $settings['other_monthly'], 2);
        $out['monthly_overhead'] = $overhead;
        $out['profit_after_overhead'] = round($out['profit'] - $overhead, 2);
    }
    if (in_array('product', $breakdown, true)) {
        $by = [];
        foreach ($rows as $r) {
            if ($r['bookings'] === 0) continue;
            $by[assistantPnlCategory($r)][] = $r;
        }
        $out['by_product'] = [];
        foreach (ASSISTANT_PNL_CATEGORIES as $c) {
            if (!isset($by[$c])) continue;
            $t = pnlTotals($by[$c]);
            $out['by_product'][] = ['product' => $c, 'pnl_rows_incl_ticket_only' => $t['units'], 'guests' => $t['pax'],
                                    'net_revenue' => $t['net'], 'total_cost' => $t['total_cost'], 'profit' => $t['profit']];
        }
    }
    if (in_array('channel', $breakdown, true)) {
        $by = [];
        foreach ($rows as $r) {
            if ($r['bookings'] === 0) continue;
            $chs = array_values(array_unique((array) $r['channels']));
            sort($chs);
            $by[count($chs) ? implode(' + ', $chs) : 'Unknown'][] = $r;
        }
        $out['by_channel'] = [];
        foreach ($by as $k => $list) {
            $t = pnlTotals($list);
            $out['by_channel'][] = ['channel' => $k, 'pnl_rows_incl_ticket_only' => $t['units'], 'guests' => $t['pax'],
                                    'net_revenue' => $t['net'], 'total_cost' => $t['total_cost'], 'profit' => $t['profit']];
        }
        usort($out['by_channel'], function ($a, $b) { return $b['net_revenue'] <=> $a['net_revenue']; });
        $out['channel_note'] = 'a departure with bookings from several channels is listed under the combined name (e.g. "GetYourGuide + Viator")';
    }
    $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $out;
}
