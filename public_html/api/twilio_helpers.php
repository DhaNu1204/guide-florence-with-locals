<?php
/**
 * twilio_helpers.php - the small Twilio pieces the guide digest needs.
 *
 * Step 3.9: these three functions used to live in twilio_reminders.php, whose whole reason for
 * existing - the per-tour "~60 minutes before" reminder - was retired in step 3.10 and is now
 * deleted. guide_digest.php and tools/digest_preview.php are the only callers.
 *
 * DESIGN CONTRACT (unchanged, do not break):
 *   - No top-level side effects: including this file only defines functions.
 *   - It touches NO payment logic.
 *   - TWILIO_DRY_RUN (default false): when true no HTTP call is ever made to Twilio.
 *   - The Twilio Auth Token is read from the environment and used only for the HTTP Basic auth
 *     header. It is never logged or echoed.
 */

require_once __DIR__ . '/EnvLoader.php';
/**
 * Step 0.1 (staging): true when TWILIO_DRY_RUN=true. In dry-run mode no
 * request is sent to Twilio; see twDryRunNote().
 */
function twilioDryRun() {
    return EnvLoader::getBool('TWILIO_DRY_RUN', false);
}
/**
 * Normalize a stored phone number into a Twilio WhatsApp address.
 *
 * Keeps digits only, drops a leading international "00" prefix, and requires a
 * plausible E.164-length number. We deliberately do NOT guess/inject a country
 * code - a wrong guess would message the wrong person - so numbers stored
 * without a country code are treated as unusable (null).
 *
 * @return string|null e.g. "whatsapp:+17088408565", or null if unusable.
 */
function normalizeWhatsapp($phone) {
    if ($phone === null) {
        return null;
    }

    $raw = trim((string) $phone);
    if ($raw === '') {
        return null;
    }

    // Strip everything that is not a digit.
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null || $digits === '') {
        return null;
    }

    // A leading "00" is the international access prefix - drop it (E.164 uses "+").
    if (strlen($digits) > 2 && substr($digits, 0, 2) === '00') {
        $digits = substr($digits, 2);
    }

    // E.164 numbers are 8..15 digits including country code.
    $len = strlen($digits);
    if ($len < 8 || $len > 15) {
        return null;
    }

    return 'whatsapp:+' . $digits;
}
/**
 * Low-level Twilio REST POST with HTTP Basic auth and a short timeout.
 * Returns ['http_code'=>int, 'body'=>string, 'json'=>array|null, 'error'=>string|null].
 * The token is only placed in the Authorization header - never logged.
 *
 * @param array $fields  form fields (application/x-www-form-urlencoded)
 */
function twilioPost($cfg, $url, array $fields) {
    if (!function_exists('curl_init')) {
        return ['http_code' => 0, 'body' => '', 'json' => null, 'error' => 'curl unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $cfg['account_sid'] . ':' . $cfg['auth_token'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['http_code' => $code, 'body' => '', 'json' => null, 'error' => ($err ?: 'curl error')];
    }

    $json = json_decode($body, true);
    return ['http_code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null, 'error' => null];
}
