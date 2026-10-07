<?php
/**
 * Step 7.5: assign a guide through the assistant - with confirmation.
 *
 * propose_assignment (read-only) builds a confirm_assign card ON THE SERVER: the model cannot make
 * one through show_blocks (the validator drops that type). Nothing changes until the user taps
 * Confirm; the browser then calls the SAME endpoint the Tours page uses (tour-groups.php for a
 * group, tours.php for a single tour) with the user's own token and expected_previous_guide_id,
 * so role checks, propagateGuideToTours and logging stay exactly as they are. Afterwards the
 * browser reports back (assistant.php?action=assign_done / undo_done): one assistant_actions row
 * per confirm or undo, and the optional WhatsApp to the new guide is sent server-side from there.
 */

require_once __DIR__ . '/departure_queries.php';
require_once __DIR__ . '/guide_product_fields.php';
require_once __DIR__ . '/../twilio_helpers.php'; // normalizeWhatsapp(), twilioPost(), twilioDryRun() - no side effects

const ASSIGN_CARD_TTL_SECONDS = 600;           // 10 minutes, then the buttons are disabled
const ASSIGN_DIGEST_MINUTES = 21 * 60 + 30;    // the evening digest goes out at 21:30 Europe/Rome

if (!function_exists('fwlDepartureById')) {
    /**
     * One departure by its unit id ('g123' group, 't456' single tour), the way the lists count it:
     * cancelled bookings left out (active_bookings = 0 means everything was cancelled).
     * guide_id = the effective guide (the group's, else a member's).
     * @return array|null
     */
    function fwlDepartureById($conn, $departureId) {
        if (!is_string($departureId) || !preg_match('/^([gt])(\d{1,10})$/', $departureId, $m)) return null;
        ensureProductDurationColumn($conn);
        $isGroup = $m[1] === 'g';
        $id = (int) $m[2];
        $where = $isGroup ? 't.group_id = ?' : 't.id = ? AND t.group_id IS NULL';
        $stmt = $conn->prepare("
            SELECT MIN(t.date) AS d,
                   LEFT(COALESCE(MAX(tg.departure_time), MAX(tg.group_time), MIN(t.time)), 5) AS tm,
                   COALESCE(MAX(tg.display_name), MIN(t.title)) AS title,
                   SUM(CASE WHEN t.cancelled = 0 THEN 1 ELSE 0 END) AS active_bookings,
                   SUM(CASE WHEN t.cancelled = 0 THEN COALESCE(t.participants, 0) ELSE 0 END) AS guests,
                   GROUP_CONCAT(DISTINCT CASE WHEN t.cancelled = 0 THEN NULLIF(TRIM(t.language), '') END ORDER BY t.language SEPARATOR ', ') AS languages,
                   MAX(tg.guide_id) AS group_guide,
                   MAX(CASE WHEN t.cancelled = 0 THEN t.guide_id END) AS member_guide,
                   MAX(pr.duration_minutes) AS duration_minutes,
                   COUNT(*) AS rows_found
            FROM tours t
            LEFT JOIN tour_groups tg ON tg.id = t.group_id
            LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
            WHERE $where");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$r || (int) $r['rows_found'] === 0) return null;
        $gid = $r['group_guide'] !== null ? (int) $r['group_guide'] : ($r['member_guide'] !== null ? (int) $r['member_guide'] : null);
        $name = null;
        if ($gid !== null) {
            $g = $conn->prepare("SELECT name FROM guides WHERE id = ?");
            $g->bind_param('i', $gid);
            $g->execute();
            $row = $g->get_result()->fetch_assoc();
            $g->close();
            $name = $row ? $row['name'] : null;
        }
        return [
            'departure_id' => $departureId,
            'type' => $isGroup ? 'group' : 'single',
            'id' => $id,
            'date' => $r['d'],
            'time' => $r['tm'] ?: '00:00',
            'title' => $r['title'],
            'languages' => $r['languages'] !== null && $r['languages'] !== '' ? explode(', ', $r['languages']) : [],
            'guests' => (int) $r['guests'],
            'bookings' => (int) $r['active_bookings'],
            'guide_id' => $gid,
            'guide_name' => $name,
            'duration_minutes' => $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null,
        ];
    }
}

if (!function_exists('fwlDepartureGuideMatches')) {
    /**
     * Step 7.5: the optional expected_previous_guide_id check shared by tours.php and
     * tour-groups.php. null/''/0 all mean "no guide".
     */
    function fwlNormGuideId($v) {
        if ($v === null || $v === '' || $v === false) return null;
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }
    function fwlDepartureGuideMatches($conn, $departureId, $expected) {
        $d = fwlDepartureById($conn, $departureId);
        if ($d === null) return [false, null];
        return [$d['guide_id'] === fwlNormGuideId($expected), $d['guide_id']];
    }
}

/**
 * When may the card offer "Send WhatsApp now"? Pure; tools/assistant_check.php covers it.
 * Today -> yes. Tomorrow -> only once tonight's 21:30 digest has gone out. Otherwise the digest
 * will carry it: a line says so. The box is only shown when the template is configured, and is
 * disabled (with the reason) when the guide has no usable number.
 * @return array {offer:bool, default:bool, disabled_reason:?string, note:?string}
 */
function assistantWhatsappOffer($date, DateTime $now, $templateSet, $phoneOk) {
    $today = $now->format('Y-m-d');
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
    $afterDigest = $minutes >= ASSIGN_DIGEST_MINUTES;
    $now_ = $date === $today || ($date === $tomorrow && $afterDigest);
    if (!$now_) {
        if ($date === $tomorrow) return ['offer' => false, 'default' => false, 'disabled_reason' => null, 'note' => "Included in tonight's 21:30 message"];
        return ['offer' => false, 'default' => false, 'disabled_reason' => null, 'note' => 'Included in the 21:30 message the evening before'];
    }
    if (!$templateSet) return ['offer' => false, 'default' => false, 'disabled_reason' => null, 'note' => null];
    if (!$phoneOk) return ['offer' => true, 'default' => false, 'disabled_reason' => 'no valid WhatsApp number for this guide', 'note' => null];
    return ['offer' => true, 'default' => true, 'disabled_reason' => null, 'note' => null];
}

function assistantAssignTemplateSid() {
    return class_exists('EnvLoader') ? trim((string) EnvLoader::get('ASSIGN_WHATSAPP_TEMPLATE_SID', '')) : '';
}

/** Minutes overlap of [s1, s1+l1) and [s2, s2+l2) - pure. */
function assistantOverlaps($s1, $l1, $s2, $l2) {
    return $s1 < $s2 + $l2 && $s2 < $s1 + $l1;
}

/**
 * propose_assignment. Refusals come back as {refused: true, reason, message} and no card.
 * A card is only built once per answer (one assignment per card).
 */
function assistantToolProposeAssignment($conn, array $input, array $ctx) {
    $depId = isset($input['departure_id']) ? (string) $input['departure_id'] : '';
    $gid = isset($input['guide_id']) && is_numeric($input['guide_id']) ? (int) $input['guide_id'] : 0;
    if (!preg_match('/^[gt]\d{1,10}$/', $depId)) {
        throw new AssistantToolError('departure_id must be a departure id like g123 or t456 (from find_departures / unassigned_departures)');
    }
    $store = isset($ctx['blocks']) && $ctx['blocks'] instanceof ArrayObject ? $ctx['blocks'] : new ArrayObject();
    foreach ($store as $b) {
        if (is_array($b) && isset($b['type']) && $b['type'] === 'confirm_assign') {
            throw new AssistantToolError('one assignment per card: a card is already shown in this answer - propose the next one after the user confirms');
        }
    }
    ensureGuideFlagColumns($conn);
    $gs = $conn->prepare("SELECT id, name, phone, languages, active, is_partner_agency FROM guides WHERE id = ?");
    $gs->bind_param('i', $gid);
    $gs->execute();
    $guide = $gs->get_result()->fetch_assoc();
    $gs->close();
    if (!$guide) {
        throw new AssistantToolError('no guide with id ' . $gid . '; use find_guide first');
    }
    $dep = fwlDepartureById($conn, $depId);
    if ($dep === null) {
        throw new AssistantToolError('no departure ' . $depId . '; use find_departures first');
    }
    $now = isset($ctx['now']) && $ctx['now'] instanceof DateTime ? $ctx['now'] : new DateTime('now', new DateTimeZone('Europe/Rome'));
    $refuse = function ($reason, $message) { return ['refused' => true, 'reason' => $reason, 'message' => $message, 'card_shown' => false]; };

    if ((int) $guide['active'] === 0) {
        return $refuse('guide_inactive', $guide['name'] . ' is inactive (takes no new work). Reactivate them on the Guides page first, then ask again.');
    }
    if ($dep['bookings'] === 0) {
        return $refuse('cancelled', 'Every booking of that departure is cancelled - there is nothing to assign.');
    }
    $startTs = strtotime($dep['date'] . ' ' . $dep['time'] . ':00');
    $nowTs = strtotime($now->format('Y-m-d H:i:s'));
    if ($startTs !== false && $startTs <= $nowTs) {
        return $refuse('past', 'That departure (' . $dep['date'] . ' ' . $dep['time'] . ') has already started or is in the past - it cannot be assigned from here.');
    }
    if ($dep['guide_id'] === (int) $guide['id']) {
        return $refuse('already_assigned', $guide['name'] . ' is already the guide of that departure.');
    }

    // (a) clash: the guide's other departures that day overlapping this one (product durations,
    // 120 minutes when a product has none - stated)
    $assumed = [];
    $len = $dep['duration_minutes'];
    if ($len === null) { $len = ASSISTANT_ASSUMED_DURATION_MIN; $assumed[$dep['title']] = true; }
    $s = assistantMinutes($dep['time']);
    $clash = [];
    foreach (fwlDepartureUnits($conn, $dep['date'], $dep['date'], (int) $guide['id']) as $u) {
        if ($u['departure_id'] === $depId) continue;
        $ul = $u['duration_minutes'];
        if ($ul === null) { $ul = ASSISTANT_ASSUMED_DURATION_MIN; $assumed[$u['title']] = true; }
        $us = assistantMinutes($u['time']);
        if (assistantOverlaps($s, $len, $us, $ul)) {
            $clash[] = ['departure_id' => $u['departure_id'], 'time' => $u['time'],
                        'until' => sprintf('%02d:%02d', intdiv($us + $ul, 60) % 24, ($us + $ul) % 60), 'title' => $u['title']];
        }
    }
    // (d) on a clash: up to 3 free alternatives for this window (active, not agencies), those who
    // speak the departure's language first
    $alternatives = [];
    if ($clash) {
        $end = $s + $len;
        list($free) = assistantSplitFreeBusy(assistantGuides($conn), fwlDepartureUnits($conn, $dep['date'], $dep['date']),
            $dep['time'], sprintf('%02d:%02d', min(23, intdiv($end, 60)), $end >= 24 * 60 ? 59 : $end % 60));
        $langs = array_map('strtolower', $dep['languages']);
        usort($free, function ($a, $b) use ($langs) {
            $sa = 0; $sb = 0;
            foreach ($langs as $l) {
                if ($l !== '' && stripos((string) $a['languages'], $l) !== false) $sa = 1;
                if ($l !== '' && stripos((string) $b['languages'], $l) !== false) $sb = 1;
            }
            return $sb - $sa ?: strcmp($a['name'], $b['name']);
        });
        foreach (array_slice(array_values(array_filter($free, function ($f) use ($guide) { return (int) $f['guide_id'] !== (int) $guide['id']; })), 0, 3) as $f) {
            $alternatives[] = ['guide_id' => $f['guide_id'], 'name' => $f['name']];
        }
    }

    $phoneOk = function_exists('normalizeWhatsapp') ? normalizeWhatsapp($guide['phone']) !== null : (trim((string) $guide['phone']) !== '');
    $wa = assistantWhatsappOffer($dep['date'], $now, assistantAssignTemplateSid() !== '', $phoneOk);
    $replace = $dep['guide_id'] !== null;

    $checks = [];
    if ($clash) {
        foreach ($clash as $c) $checks[] = ['level' => 'warn', 'text' => $guide['name'] . ' already has ' . $c['time'] . '-' . $c['until'] . ' ' . $c['title']];
    } else {
        $checks[] = ['level' => 'ok', 'text' => 'No other tour for ' . $guide['name'] . ' at that time'];
    }
    if ($assumed) $checks[] = ['level' => 'warn', 'text' => 'No duration set for ' . implode(', ', array_keys($assumed)) . ' - 120 minutes assumed'];
    if ($replace) $checks[] = ['level' => 'warn', 'text' => 'Replace ' . $dep['guide_name'] . ' with ' . $guide['name'] . ' - ' . $dep['guide_name'] . " won't be notified automatically"];
    if (!empty($guide['is_partner_agency'])) $checks[] = ['level' => 'warn', 'text' => $guide['name'] . ' is a partner agency'];

    $issued = new DateTime('now', new DateTimeZone('UTC'));
    $card = [
        'type' => 'confirm_assign',
        'departure' => [
            'departure_id' => $dep['departure_id'], 'type' => $dep['type'], 'id' => $dep['id'],
            'date' => $dep['date'], 'time' => $dep['time'], 'title' => $dep['title'], 'languages' => $dep['languages'],
            'guests' => $dep['guests'], 'bookings' => $dep['bookings'],
            'current_guide_id' => $dep['guide_id'], 'current_guide_name' => $dep['guide_name'],
        ],
        'guide' => ['id' => (int) $guide['id'], 'name' => $guide['name'], 'active' => true,
                    'partner_agency' => !empty($guide['is_partner_agency'])],
        'previous_guide_id' => $dep['guide_id'],
        'replace' => $replace,
        'checks' => $checks,
        'clash' => $clash,
        'alternatives' => $alternatives,
        'whatsapp' => $wa,
        'issued_at' => $issued->format('Y-m-d\TH:i:s\Z'),
        'expires_in' => ASSIGN_CARD_TTL_SECONDS,
    ];
    $store->append($card);
    return [
        'card_shown' => true,
        'note' => 'A confirm card is shown. NOTHING has changed: the user must tap Confirm. Never say the guide is assigned.',
        'departure' => $dep['departure_id'] . ' ' . $dep['date'] . ' ' . $dep['time'] . ' ' . $dep['title'],
        'guide' => $guide['name'],
        'replace' => $replace ? ('Replace ' . $dep['guide_name'] . ' with ' . $guide['name']) : null,
        'clash' => $clash,
        'alternatives' => array_column($alternatives, 'name'),
        'assumed_120_for' => array_keys($assumed),
        'whatsapp' => $wa,
    ];
}

// ---- step 7.5: audit rows, the WhatsApp to the new guide, the report-back handlers -------------

function ensureAssistantActionsTable($conn) {
    static $done = false;
    if ($done) return;
    $conn->query("
        CREATE TABLE IF NOT EXISTS assistant_actions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action ENUM('assign','undo') NOT NULL,
            departure_id VARCHAR(16) NOT NULL,
            from_guide_id INT NULL,
            to_guide_id INT NULL,
            whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
            whatsapp_result VARCHAR(300) NULL,
            undo_of INT UNSIGNED NULL,
            undone_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assistant_actions_departure (departure_id),
            KEY idx_assistant_actions_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** Template variables for the (to be approved) guide_tour_assigned_it template. Pure. */
function assistantAssignWhatsappVars($guideName, array $dep, DateTime $now) {
    $first = trim(explode(' ', trim((string) $guideName))[0]);
    $today = $now->format('Y-m-d');
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    if ($dep['date'] === $today) {
        $when = 'oggi';
    } elseif ($dep['date'] === $tomorrow) {
        $when = 'domani';
    } else {
        $d = new DateTime($dep['date'], new DateTimeZone('Europe/Rome'));
        $days = ['Mon' => 'lun', 'Tue' => 'mar', 'Wed' => 'mer', 'Thu' => 'gio', 'Fri' => 'ven', 'Sat' => 'sab', 'Sun' => 'dom'];
        $months = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $when = $days[$d->format('D')] . ' ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n')];
    }
    // A WhatsApp template parameter may not contain a newline (Twilio 21656, step 3.10)
    $clean = function ($v) { return trim(preg_replace('/\s+/', ' ', (string) $v)); };
    return ['1' => $clean($first), '2' => $clean($dep['title']), '3' => $when, '4' => $dep['time'], '5' => (string) (int) $dep['guests']];
}

/**
 * Send the assignment WhatsApp. TWILIO_DRY_RUN=true (staging) never calls Twilio: the intended
 * send is returned as "DRY RUN: ..." and recorded on the action row.
 * @return array {sent:bool, result:string}
 */
function assistantSendAssignWhatsapp(array $guide, array $dep, DateTime $now) {
    $to = normalizeWhatsapp($guide['phone']);
    if ($to === null) return ['sent' => false, 'result' => 'not sent: no valid WhatsApp number'];
    $sid = assistantAssignTemplateSid();
    if ($sid === '') return ['sent' => false, 'result' => 'not sent: ASSIGN_WHATSAPP_TEMPLATE_SID is not set'];
    $vars = assistantAssignWhatsappVars($guide['name'], $dep, $now);
    $masked = function_exists('maskPhone') ? maskPhone($to) : (substr($to, 0, 13) . '…');
    if (twilioDryRun()) {
        return ['sent' => false, 'result' => 'DRY RUN: would send guide_tour_assigned to ' . $masked . ' ' . json_encode($vars, JSON_UNESCAPED_UNICODE)];
    }
    $cfg = [
        'account_sid' => (string) EnvLoader::get('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => (string) EnvLoader::get('TWILIO_AUTH_TOKEN', ''),
    ];
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['account_sid']) . '/Messages.json';
    $res = twilioPost($cfg, $url, [
        'MessagingServiceSid' => (string) EnvLoader::get('TWILIO_MESSAGING_SERVICE_SID', ''),
        'To' => $to,
        'ContentSid' => $sid,
        'ContentVariables' => json_encode($vars, JSON_UNESCAPED_UNICODE),
    ]);
    if ($res['http_code'] >= 200 && $res['http_code'] < 300 && $res['json']) {
        return ['sent' => true, 'result' => 'sent (' . ($res['json']['status'] ?? 'queued') . ', ' . ($res['json']['sid'] ?? '') . ')'];
    }
    $detail = $res['json'] && isset($res['json']['message']) ? $res['json']['message'] : ($res['error'] ?: 'HTTP ' . $res['http_code']);
    return ['sent' => false, 'result' => 'failed: ' . mb_substr((string) $detail, 0, 200, 'UTF-8')];
}

function assistantInsertAction($conn, $userId, $action, $depId, $from, $to, $waSent, $waResult, $undoOf) {
    $s = $conn->prepare("INSERT INTO assistant_actions (user_id, action, departure_id, from_guide_id, to_guide_id, whatsapp_sent, whatsapp_result, undo_of)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $s->bind_param('issiiisi', $userId, $action, $depId, $from, $to, $waSent, $waResult, $undoOf);
    $s->execute();
    $id = (int) $conn->insert_id;
    $s->close();
    return $id;
}

/**
 * POST assistant.php?action=assign_done {departure_id, from_guide_id, to_guide_id, send_whatsapp}
 * Called by the card AFTER the Tours endpoint saved the assignment. Checks the departure really
 * has the new guide now, records the action and - only if asked and allowed - sends the WhatsApp.
 * A failed send never touches the assignment.
 * @return array {status, body}
 */
function assistantAssignDone($conn, array $user, $body) {
    ensureAssistantActionsTable($conn);
    $depId = is_array($body) && isset($body['departure_id']) ? (string) $body['departure_id'] : '';
    $to = is_array($body) && isset($body['to_guide_id']) ? fwlNormGuideId($body['to_guide_id']) : null;
    $from = is_array($body) && array_key_exists('from_guide_id', $body) ? fwlNormGuideId($body['from_guide_id']) : null;
    if (!preg_match('/^[gt]\d{1,10}$/', $depId) || $to === null) {
        return ['status' => 400, 'body' => ['success' => false, 'error' => 'departure_id and to_guide_id are required']];
    }
    $dep = fwlDepartureById($conn, $depId);
    if ($dep === null) return ['status' => 404, 'body' => ['success' => false, 'error' => 'departure_not_found']];
    if ($dep['guide_id'] !== $to) {
        return ['status' => 409, 'body' => ['success' => false, 'error' => 'not_assigned', 'current_guide_id' => $dep['guide_id']]];
    }
    $g = $conn->prepare("SELECT id, name, phone FROM guides WHERE id = ?");
    $g->bind_param('i', $to);
    $g->execute();
    $guide = $g->get_result()->fetch_assoc();
    $g->close();
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $waSent = 0; $waResult = null;
    if (!empty($body['send_whatsapp']) && $guide) {
        $offer = assistantWhatsappOffer($dep['date'], $now, assistantAssignTemplateSid() !== '', normalizeWhatsapp($guide['phone']) !== null);
        if (!$offer['offer'] || $offer['disabled_reason'] !== null) {
            $waResult = 'not sent: ' . ($offer['disabled_reason'] ?: ($offer['note'] ?: 'not offered for this departure'));
        } else {
            $r = assistantSendAssignWhatsapp($guide, $dep, $now);
            $waSent = $r['sent'] ? 1 : 0;
            $waResult = $r['result'];
        }
    }
    $id = assistantInsertAction($conn, (int) $user['id'], 'assign', $depId, $from, $to, $waSent, $waResult, null);
    return ['status' => 200, 'body' => ['success' => true, 'action_id' => $id, 'at' => $now->format('H:i'), 'by' => $user['username'],
        'guide' => $guide ? $guide['name'] : null, 'whatsapp' => $waResult === null ? null : ['sent' => (bool) $waSent, 'result' => $waResult]]];
}

/**
 * POST assistant.php?action=undo_done {action_id} - after the card put the previous guide back
 * through the same endpoint. Marks the original row undone and adds the undo row.
 */
function assistantUndoDone($conn, array $user, $body) {
    ensureAssistantActionsTable($conn);
    $aid = is_array($body) && isset($body['action_id']) && ctype_digit((string) $body['action_id']) ? (int) $body['action_id'] : 0;
    $s = $conn->prepare("SELECT * FROM assistant_actions WHERE id = ? AND action = 'assign'");
    $s->bind_param('i', $aid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$row || (int) $row['user_id'] !== (int) $user['id']) {
        return ['status' => 404, 'body' => ['success' => false, 'error' => 'action_not_found']];
    }
    if ($row['undone_at'] !== null) {
        return ['status' => 409, 'body' => ['success' => false, 'error' => 'already_undone']];
    }
    $dep = fwlDepartureById($conn, $row['departure_id']);
    $prev = $row['from_guide_id'] !== null ? (int) $row['from_guide_id'] : null;
    if ($dep === null || $dep['guide_id'] !== $prev) {
        return ['status' => 409, 'body' => ['success' => false, 'error' => 'not_restored', 'current_guide_id' => $dep ? $dep['guide_id'] : null]];
    }
    $u = $conn->prepare("UPDATE assistant_actions SET undone_at = UTC_TIMESTAMP() WHERE id = ?");
    $u->bind_param('i', $aid);
    $u->execute();
    $u->close();
    $to = $row['to_guide_id'] !== null ? (int) $row['to_guide_id'] : null;
    $id = assistantInsertAction($conn, (int) $user['id'], 'undo', $row['departure_id'], $to, $prev, 0, null, $aid);
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    return ['status' => 200, 'body' => ['success' => true, 'action_id' => $id, 'at' => $now->format('H:i'), 'by' => $user['username']]];
}
