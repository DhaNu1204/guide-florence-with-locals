<?php
require_once 'config.php';

// Apply rate limiting for webhooks (30 per minute)
applyRateLimit('webhook');

require_once __DIR__ . '/webhook_helpers.php';

// Step 1.4: shared secret. WEBHOOK_SECRET comes from the server .env (EnvLoader,
// like every other env var). Missing/empty -> 503 and stop: never fall open.
// Wrong or missing ?key -> 401, one error_log line, and NO bokun_webhook_logs row.
$webhookSecret = (string) EnvLoader::get('WEBHOOK_SECRET', '');
if ($webhookSecret === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'webhook_not_configured']);
    exit();
}
if (!webhookKeyMatches($_GET['key'] ?? null, $webhookSecret)) {
    error_log('bokun_webhook: rejected request with a missing or wrong key from ' . RateLimiter::getClientIp());
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit();
}

// Self-provision the webhook log table so payloads are captured even before
// any migration is run (same pattern tours.php uses for the products table).
// Non-fatal: a provisioning failure must never abort a webhook request.
function ensureWebhookLogTable() {
    global $conn;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS bokun_webhook_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic VARCHAR(100),
                booking_id VARCHAR(255),
                experience_booking_id VARCHAR(255),
                payload JSON,
                processed TINYINT DEFAULT 0,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Step 1.4: how many distinct dates the event carried and how many were synced (cap 3)
        $col = $conn->query("SHOW COLUMNS FROM bokun_webhook_logs LIKE 'dates_found'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE bokun_webhook_logs ADD COLUMN dates_found INT NULL, ADD COLUMN dates_processed INT NULL");
        }
        // Step 4.11: outcome of the delayed re-check (database/migrations/20261009_webhook_recheck_result.sql)
        $col = $conn->query("SHOW COLUMNS FROM bokun_webhook_logs LIKE 'recheck_result'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE bokun_webhook_logs ADD COLUMN recheck_result VARCHAR(40) NULL");
        }
    } catch (Throwable $e) {
        error_log("bokun_webhook: failed to ensure bokun_webhook_logs table: " . $e->getMessage());
    }
}
ensureWebhookLogTable();

// Log all webhook calls for debugging.
// Non-fatal: any logging failure is swallowed so it can never abort the request.
// Returns the inserted log row id (or null on failure) so the caller can later
// mark it processed. Bokun's X-Bokun-* headers arrive empty, so booking_id falls
// back to the body's bookingId.
function logWebhook($topic, $data, $error = null, $payloadJson = null, $datesFound = null, $datesProcessed = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("INSERT INTO bokun_webhook_logs (topic, booking_id, experience_booking_id, payload, error_message, dates_found, dates_processed) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("bokun_webhook: logWebhook prepare failed: " . $conn->error);
            return null;
        }
        $bookingId = $_SERVER['HTTP_X_BOKUN_BOOKING_ID'] ?? (is_array($data) ? ($data['bookingId'] ?? null) : null);
        $experienceBookingId = $_SERVER['HTTP_X_BOKUN_EXPERIENCEBOOKING_ID'] ?? null;
        // Step 1.4: the caller passes the capped payload text (<= 64 KB raw + marker, always valid JSON)
        $payload = $payloadJson !== null ? $payloadJson : json_encode($data);
        $bookingId = $bookingId !== null ? (string)$bookingId : null;

        $stmt->bind_param("sssssii", $topic, $bookingId, $experienceBookingId, $payload, $error, $datesFound, $datesProcessed);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();
        return $insertId ?: null;
    } catch (Throwable $e) {
        error_log("bokun_webhook: logWebhook failed: " . $e->getMessage());
        return null;
    }
}

// Extract the affected tour date (Europe/Rome 'Y-m-d') from one activityBooking.
// Prefers startDateTime (epoch milliseconds); falls back to the 'date' field
// (epoch ms or ISO string) then to parsing 'dateString' ("Tue, June 23 2026 - 11:00 AM").
function webhookExtractDate($ab) {
    if (!is_array($ab)) {
        return null;
    }
    $tz = new DateTimeZone('Europe/Rome');

    if (isset($ab['startDateTime']) && is_numeric($ab['startDateTime'])) {
        try {
            $dt = new DateTime('@' . intval($ab['startDateTime'] / 1000));
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d');
        } catch (Throwable $e) { /* fall through */ }
    }

    if (isset($ab['date'])) {
        if (is_numeric($ab['date'])) {
            try {
                $dt = new DateTime('@' . intval($ab['date'] / 1000));
                $dt->setTimezone($tz);
                return $dt->format('Y-m-d');
            } catch (Throwable $e) { /* fall through */ }
        } else {
            try {
                $dt = new DateTime((string)$ab['date'], $tz);
                return $dt->format('Y-m-d');
            } catch (Throwable $e) { /* fall through */ }
        }
    }

    if (isset($ab['dateString']) && is_string($ab['dateString'])) {
        // "Tue, June 23 2026 - 11:00 AM" -> strip weekday prefix and time suffix
        $clean = preg_replace('/^[A-Za-z]{3,},\s*/', '', $ab['dateString']);
        $clean = preg_replace('/\s*-\s*\d{1,2}:\d{2}\s*[AP]M.*$/i', '', $clean);
        try {
            $dt = new DateTime(trim($clean), $tz);
            return $dt->format('Y-m-d');
        } catch (Throwable $e) { /* fall through */ }
    }

    return null;
}

// Get request body. Bokun's X-Bokun-* headers arrive empty in practice, so the
// event is driven entirely from the booking object in the body.
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// Step 1.4: what gets stored - the decoded event up to 64 KB, a cut+marker wrapper above.
$storedPayload = webhookStorablePayload($rawBody, $data);

// Collect the affected dates first so the log row carries the counters.
$topic = is_array($data) ? ($data['status'] ?? null) : null;

// Real-time apply: re-sync just the affected day(s) through the proven
// syncBookings() path. That single path already handles new bookings,
// in-place updates/reschedules, cancellations (booking-search includes
// CANCELLED), product registration, and auto-grouping — so we never need to
// upsert from the (shape-divergent) webhook body directly.
$bookingId = is_array($data) ? ($data['bookingId'] ?? null) : null;

// Collect unique affected dates (Europe/Rome) from the booking's activities.
$dates = [];
if (is_array($data) && isset($data['activityBookings']) && is_array($data['activityBookings'])) {
    foreach ($data['activityBookings'] as $ab) {
        $d = webhookExtractDate($ab);
        if ($d) {
            $dates[$d] = true;
        }
    }
}
// Step 1.4: de-duplicate, sort, and process at most WEBHOOK_MAX_DATES (3) dates per event.
$capped = webhookCapDates(array_keys($dates));
$datesFound = $capped['found'];
$uniqueDates = $capped['processed'];
$datesProcessed = count($uniqueDates);

// Step zero: always capture the webhook first (non-fatal). Store the booking
// status in the topic column for at-a-glance debugging.
$logId = logWebhook($topic, $data, null, $storedPayload, $datesFound, $datesProcessed);

$syncError = null;
$skipped = false;
$syncOk = false; // step 4.11: every date sync answered success (false when skipped / disabled / failed)

if (empty($uniqueDates)) {
    // No usable booking date in the body — this is non-booking noise (or an
    // event shape we don't act on). Skip the sync entirely rather than run a
    // slow multi-day fallback that can exceed the gateway timeout (504 -> Bokun
    // retries). The payload is already captured; any real change is also picked
    // up by the in-app 15-min sync. Return 200 fast.
    $skipped = true;
    error_log("bokun_webhook: no usable date in body for booking " . ($bookingId ?? 'unknown') . " — skipping sync");
} else {
    // Load the sync library WITHOUT triggering its auth/routing block.
    define('BOKUN_SYNC_LIB', true);
    require_once __DIR__ . '/bokun_sync.php';

    try {
        $syncOk = true;
        foreach ($uniqueDates as $d) {
            // Targeted 1-day sync per affected date through the proven path.
            $syncResult = syncBookings($d, $d, 'webhook', (string)$bookingId);
            if (!is_array($syncResult) || empty($syncResult['success'])) {
                $syncOk = false;
            }
        }
    } catch (Throwable $e) {
        // Never fatal: record the error and still return 200 so Bokun does not
        // enter a retry storm. The raw payload is already captured above.
        $syncError = $e->getMessage();
        $syncOk = false;
        error_log("bokun_webhook: sync failed for booking " . ($bookingId ?? 'unknown') . ": " . $syncError);
        logWebhook($topic, $data, "sync failed: " . $syncError, $storedPayload, $datesFound, $datesProcessed);
    }
}

// Mark the captured log row processed on success (synced or intentionally skipped).
if ($syncError === null && $logId) {
    try {
        $stmt = $conn->prepare("UPDATE bokun_webhook_logs SET processed = 1 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $logId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("bokun_webhook: failed to mark log $logId processed: " . $e->getMessage());
    }
}

$responseBody = json_encode([
    'status' => $syncError === null ? 'success' : 'received',
    'message' => $skipped
        ? 'Webhook received (no booking date — sync skipped)'
        : ($syncError === null ? 'Webhook processed (real-time sync)' : 'Webhook received (sync deferred)'),
    'synced_dates' => $uniqueDates,
    'dates_found' => $datesFound,
    'dates_processed' => $datesProcessed
]);

// Step 4.11: is the booking's tour row there now? (idx_tours_bokun_booking_id)
function webhookBookingStored($bookingId) {
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT 1 FROM tours WHERE bokun_booking_id = ? LIMIT 1");
        $id = (string) $bookingId;
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    } catch (Throwable $e) {
        error_log("bokun_webhook: stored check failed for booking $bookingId: " . $e->getMessage());
        return true; // unknown -> do not loop
    }
}

// Step 4.11: answer Bokun now and keep running. Returns how the connection was closed.
function webhookRespondAndDetach($body) {
    ignore_user_abort(true);
    @set_time_limit(300);
    http_response_code(200);
    echo $body;
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return 'ls';
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return 'fpm';
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
    return 'flush';
}

// Step 4.11: Bokun calls us before its booking-search returns a new GYG / website booking, so
// the sync above usually missed it (it then waited for the next cron). Re-check after answering.
$needsRecheck = !$skipped
    && webhookNeedsRecheck($topic, $bookingId, $syncOk, webhookBookingStored($bookingId));

if (!$needsRecheck) {
    http_response_code(200);
    echo $responseBody;
    exit();
}

$closedBy = webhookRespondAndDetach($responseBody);
$outcome = ['result' => 'error', 'tries' => 0, 'waited' => 0];
try {
    $outcome = webhookRecheckUntilStored(
        function () use ($bookingId) { return webhookBookingStored($bookingId); },
        function () use ($uniqueDates, $bookingId) {
            foreach ($uniqueDates as $d) {
                syncBookings($d, $d, 'webhook', 'recheck:' . $bookingId);
            }
        },
        function ($seconds) { sleep($seconds); }
    );
} catch (Throwable $e) {
    error_log("bokun_webhook: recheck failed for booking $bookingId: " . $e->getMessage());
}
$label = webhookRecheckLabel($outcome, $closedBy);
error_log("bokun_webhook: booking $bookingId recheck $label");
if ($logId) {
    try {
        $stmt = $conn->prepare("UPDATE bokun_webhook_logs SET recheck_result = ? WHERE id = ?");
        $stmt->bind_param("si", $label, $logId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("bokun_webhook: failed to store recheck result for log $logId: " . $e->getMessage());
    }
}
?>