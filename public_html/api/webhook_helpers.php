<?php
/**
 * webhook_helpers.php - pure helpers for bokun_webhook.php (step 1.4).
 *
 * No side effects, no database, no globals: this file can be included from the
 * CLI check in tools/ without config.php, which is why the caps live here.
 */

const WEBHOOK_MAX_DATES = 3;
const WEBHOOK_MAX_PAYLOAD_BYTES = 65536;
const WEBHOOK_TRUNCATED_MARKER = '...[truncated]';

/**
 * De-duplicate, sort and cap the affected dates of one event.
 *
 * @param string[] $dates 'Y-m-d' strings, may repeat
 * @param int $max
 * @return array{found:int, processed:string[]}
 */
function webhookCapDates(array $dates, $max = WEBHOOK_MAX_DATES) {
    $unique = array_values(array_unique(array_filter($dates, 'is_string')));
    sort($unique, SORT_STRING);
    return [
        'found' => count($unique),
        'processed' => array_slice($unique, 0, max(0, (int) $max)),
    ];
}

/**
 * What goes into bokun_webhook_logs.payload (a JSON column: the value MUST stay
 * valid JSON). Up to the limit the decoded event is stored as before; beyond it
 * the raw body is cut at the limit, the marker appended, and the cut text is
 * wrapped in a small JSON object so the column constraint still holds.
 *
 * @param string $rawBody
 * @param mixed $decoded json_decode($rawBody, true) or null
 * @param int $limit
 * @return string JSON text
 */
function webhookStorablePayload($rawBody, $decoded, $limit = WEBHOOK_MAX_PAYLOAD_BYTES) {
    $rawBody = (string) $rawBody;
    if (strlen($rawBody) <= $limit) {
        return json_encode($decoded);
    }
    $cut = substr($rawBody, 0, $limit);
    // Never cut inside a multibyte UTF-8 sequence (json_encode would fail on it).
    while ($cut !== '' && !mb_check_encoding($cut, 'UTF-8')) {
        $cut = substr($cut, 0, -1);
    }
    return json_encode([
        '_truncated' => true,
        '_original_bytes' => strlen($rawBody),
        '_stored_bytes' => strlen($cut),
        'payload' => $cut . WEBHOOK_TRUNCATED_MARKER,
    ]);
}

/**
 * Step 4.11: seconds to wait before each re-sync of a booking the webhook's own sync did not
 * store. Bokun calls the webhook before its booking-search returns a new GYG / website booking
 * (2 of 410 GYG bookings since 2026-09-20 were stored by the webhook's sync; later syncs found
 * them, some 1-40 s after the webhook). Checks land at +15, +45, +90 and +150 s.
 */
const WEBHOOK_RECHECK_DELAYS = [15, 30, 45, 60];

/**
 * Step 4.11: does this event need the delayed re-check? Only when the date sync really ran
 * (so not with sync disabled or failed), the booking is not a cancellation, and its tour row
 * is still missing.
 *
 * @param mixed $topic booking status from the body (CONFIRMED / CANCELLED / ...)
 * @param mixed $bookingId Bokun booking id from the body
 * @param bool $syncOk every date sync answered success
 * @param bool $alreadyStored a tours row with this bokun_booking_id exists
 * @return bool
 */
function webhookNeedsRecheck($topic, $bookingId, $syncOk, $alreadyStored) {
    if (!$syncOk || $alreadyStored) {
        return false;
    }
    if ($bookingId === null || $bookingId === '' || !is_scalar($bookingId)) {
        return false;
    }
    return strtoupper((string) $topic) !== 'CANCELLED';
}

/**
 * Step 4.11: the value stored in bokun_webhook_logs.recheck_result (VARCHAR(40)),
 * e.g. "found_after_45s/ls" (ls = LiteSpeed closed the connection first).
 *
 * @param array{result:string, waited:int} $outcome
 * @param string $closedBy how the response was closed before waiting
 * @return string
 */
function webhookRecheckLabel(array $outcome, $closedBy) {
    return substr($outcome['result'] . '_after_' . (int) $outcome['waited'] . 's/' . $closedBy, 0, 40);
}

/**
 * Step 4.11d: what the ONE shared re-check run does next. Each pending booking is re-checked
 * WEBHOOK_RECHECK_DELAYS after it was queued (cumulative: +15, +45, +90, +150 s).
 *
 * @param array $rows each ['key' => string, 'added' => int unix, 'tries' => int]
 * @param int $now unix
 * @param int[] $delays
 * @param int $grace a row due within this many seconds is checked now too, so bookings that
 *        arrived close together share one sync instead of one sync each
 * @return array{due:string[], expired:string[], next:?int} keys due now, keys out of tries,
 *         and when the earliest not-yet-due row becomes due (null = nothing waiting)
 */
function webhookRecheckPlan(array $rows, $now, array $delays = WEBHOOK_RECHECK_DELAYS, $grace = 0) {
    $offsets = [];
    $sum = 0;
    foreach ($delays as $d) { $sum += (int) $d; $offsets[] = $sum; }
    $due = [];
    $expired = [];
    $next = null;
    foreach ($rows as $r) {
        $tries = (int) $r['tries'];
        if ($tries >= count($offsets)) { $expired[] = $r['key']; continue; }
        $at = (int) $r['added'] + $offsets[$tries];
        if ($now + (int) $grace >= $at) { $due[] = $r['key']; continue; }
        $next = $next === null ? $at : min($next, $at);
    }
    return ['due' => $due, 'expired' => $expired, 'next' => $next];
}

/**
 * Constant-time comparison of the presented key with the configured secret.
 *
 * @param mixed $presented $_GET['key']
 * @param mixed $secret WEBHOOK_SECRET from the environment
 * @return bool
 */
function webhookKeyMatches($presented, $secret) {
    $secret = (string) $secret;
    if ($secret === '' || !is_scalar($presented)) {
        return false;
    }
    return hash_equals($secret, (string) $presented);
}
