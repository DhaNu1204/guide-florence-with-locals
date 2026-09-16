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
