<?php
require_once 'config.php';

// Apply rate limiting for webhooks (30 per minute)
applyRateLimit('webhook');

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
function logWebhook($topic, $data, $error = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("INSERT INTO bokun_webhook_logs (topic, booking_id, experience_booking_id, payload, error_message) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("bokun_webhook: logWebhook prepare failed: " . $conn->error);
            return null;
        }
        $bookingId = $_SERVER['HTTP_X_BOKUN_BOOKING_ID'] ?? (is_array($data) ? ($data['bookingId'] ?? null) : null);
        $experienceBookingId = $_SERVER['HTTP_X_BOKUN_EXPERIENCEBOOKING_ID'] ?? null;
        $payload = json_encode($data);
        $bookingId = $bookingId !== null ? (string)$bookingId : null;

        $stmt->bind_param("sssss", $topic, $bookingId, $experienceBookingId, $payload, $error);
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

// Step zero: always capture the raw webhook first (non-fatal). Store the
// booking status in the topic column for at-a-glance debugging.
$topic = is_array($data) ? ($data['status'] ?? null) : null;
$logId = logWebhook($topic, $data);

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
$uniqueDates = array_keys($dates);

$syncError = null;
$skipped = false;

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
        foreach ($uniqueDates as $d) {
            // Targeted 1-day sync per affected date through the proven path.
            syncBookings($d, $d, 'webhook', (string)$bookingId);
        }
    } catch (Throwable $e) {
        // Never fatal: record the error and still return 200 so Bokun does not
        // enter a retry storm. The raw payload is already captured above.
        $syncError = $e->getMessage();
        error_log("bokun_webhook: sync failed for booking " . ($bookingId ?? 'unknown') . ": " . $syncError);
        logWebhook($topic, $data, "sync failed: " . $syncError);
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

http_response_code(200);
echo json_encode([
    'status' => $syncError === null ? 'success' : 'received',
    'message' => $skipped
        ? 'Webhook received (no booking date — sync skipped)'
        : ($syncError === null ? 'Webhook processed (real-time sync)' : 'Webhook received (sync deferred)'),
    'synced_dates' => $uniqueDates
]);
?>