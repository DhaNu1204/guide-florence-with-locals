<?php
require_once 'config.php';
require_once 'BokunAPI.php';
require_once __DIR__ . '/tour_classification.php';
require_once __DIR__ . '/group_helpers.php';
require_once __DIR__ . '/manual_helpers.php';   // step 6.4: manual rows are invisible to the sync // step 3.5: fillMissingGroupGuide()
require_once __DIR__ . '/viator_helpers.php';   // step 6.9: the old/new Viator account label
require_once __DIR__ . '/rate_helpers.php';     // step 6.14: tours.rate_title

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

// Include Encryption helper for secure credential storage
if (file_exists(__DIR__ . '/Encryption.php')) {
    require_once __DIR__ . '/Encryption.php';
}

// Apply rate limiting for Bokun sync operations (stricter: 10 per minute).
// Only for direct HTTP access — skip under CLI cron and when included as a
// library (BOKUN_SYNC_LIB, e.g. bokun_webhook.php) so we don't double-charge
// the webhook's own 'webhook' rate budget and risk a 429 abort on bursts.
if (php_sapi_name() !== 'cli' && !defined('BOKUN_SYNC_LIB')) {
    applyRateLimit('bokun_sync');
}

// Auth check function for API endpoints
function checkAuth() {
    global $conn;
    require_once __DIR__ . '/Middleware.php';
    return Middleware::verifyAuth($conn) !== false;
}

// Self-provision the is_private flag column on the tours table (idempotent).
function ensureIsPrivateColumn($conn) {
    $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'is_private'");
    if ($c && $c->num_rows === 0) {
        $conn->query("ALTER TABLE tours ADD COLUMN `is_private` TINYINT(1) NOT NULL DEFAULT 0 AFTER `product_id`");
        $conn->query("ALTER TABLE tours ADD KEY `idx_tours_is_private` (`is_private`)");
    }
}

// Step 3.1: Bokun's customer price gets its own columns (tours.paid / payment_status /
// total_amount_paid / expected_amount are local state and are no longer written by the sync
// UPDATE). Adds the columns when missing and backfills them once from the stored bokun_data.
// Returns the number of rows backfilled, or null when the columns were already there.
// Also in database/migrations/20260918_tours_bokun_total_price.sql.
function ensureBokunPriceColumns($conn) {
    $c = $conn->query("SHOW COLUMNS FROM tours LIKE 'bokun_total_price'");
    if (!$c || $c->num_rows > 0) {
        return null;
    }
    try {
        $conn->query("ALTER TABLE tours
                        ADD COLUMN `bokun_total_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `expected_amount`,
                        ADD COLUMN `bokun_currency` VARCHAR(3) NULL DEFAULT NULL AFTER `bokun_total_price`");
        $conn->query(bokunPriceBackfillSql());
        $rows = $conn->affected_rows;
        error_log("Bokun Sync: added tours.bokun_total_price / bokun_currency, backfilled $rows rows (step 3.1)");
        return $rows;
    } catch (mysqli_sql_exception $e) {
        // e.g. a concurrent sync added them a moment ago ("Duplicate column name")
        error_log("Bokun Sync: ensureBokunPriceColumns: " . $e->getMessage());
        return null;
    }
}

// One UPDATE: customer price + currency from bokun_data, for rows that have no price yet.
// Same JSON paths in the same order as bokunCustomerPrice() in tour_classification.php.
function bokunPriceBackfillSql() {
    $bases = ['$', '$.productBookings[0]', '$.activityBookings[0]'];
    $rootCurrency = "JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '$.currency'))";
    $price = [];
    $currency = [];
    foreach (['resellerInvoice', 'customerInvoice'] as $inv) {
        foreach ($bases as $base) {
            $total = "JSON_EXTRACT(bokun_data, '" . $base . "." . $inv . ".total')";
            $price[] = "JSON_UNQUOTE($total)";
            $currency[] = "IF($total IS NULL, NULL, COALESCE("
                . "JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '" . $base . "." . $inv . ".currency')), "
                . "JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '" . $base . ".currency')), $rootCurrency))";
        }
    }
    foreach ($bases as $base) {
        $total = "(JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '" . $base . ".totalPrice')) + 0)";
        $price[] = "NULLIF($total, 0)";
        $currency[] = "IF(COALESCE($total, 0) = 0, NULL, COALESCE("
            . "JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '" . $base . ".currency')), $rootCurrency))";
    }
    $priceExpr = "COALESCE(" . implode(", ", $price) . ")";
    $currencyExpr = "COALESCE(" . implode(", ", $currency) . ")";
    return "UPDATE tours
               SET bokun_total_price = ROUND($priceExpr, 2),
                   bokun_currency = UPPER(LEFT($currencyExpr, 3))
             WHERE bokun_total_price IS NULL
               AND (source IS NULL OR source <> 'manual')
               AND bokun_data IS NOT NULL AND JSON_VALID(bokun_data)
               AND $priceExpr IS NOT NULL";
}

// Self-provision the unique index that makes the booking upsert race-safe.
// NULLs are allowed (manual tours have no external_id). The ALTER fails if legacy
// duplicate rows still exist — those must be cleaned up manually first; the sync
// must keep working either way (mysqli strict mode throws, hence the try/catch).
function ensureExternalIdUniqueIndex($conn) {
    $c = $conn->query("SHOW INDEX FROM tours WHERE Key_name = 'uniq_tours_external_id'");
    if ($c && $c->num_rows === 0) {
        try {
            if (!@$conn->query("ALTER TABLE tours ADD UNIQUE KEY `uniq_tours_external_id` (`external_id`)")) {
                error_log("Bokun Sync: could not add uniq_tours_external_id (duplicate external_id rows present?)");
            }
        } catch (mysqli_sql_exception $e) {
            error_log("Bokun Sync: could not add uniq_tours_external_id: " . $e->getMessage());
        }
    }
}

// Step 3.4: self-provision the index behind the per-booking existence lookup.
// Without it `WHERE bokun_booking_id = ? OR external_id = ?` cannot use an index at all
// (EXPLAIN type=ALL, key=NULL) and every one of the ~810 bookings in a sync scans the whole
// 65 MB table. Not unique: nothing guarantees Bokun ids are unique across legacy rows.
function ensureBokunBookingIdIndex($conn) {
    $c = $conn->query("SHOW INDEX FROM tours WHERE Key_name = 'idx_tours_bokun_booking_id'");
    if ($c && $c->num_rows === 0) {
        try {
            if (!@$conn->query("ALTER TABLE tours ADD KEY `idx_tours_bokun_booking_id` (`bokun_booking_id`)")) {
                error_log("Bokun Sync: could not add idx_tours_bokun_booking_id");
            }
        } catch (mysqli_sql_exception $e) {
            error_log("Bokun Sync: could not add idx_tours_bokun_booking_id: " . $e->getMessage());
        }
    }
}

// Step 3.7: self-provision tour_groups.bucket_key - the NATURAL identity of a departure
// ("<product_id>|YYYY-MM-DD|HH:MM"), so a group can be found without its surrogate id and an
// override keyed on the departure survives even if the id ever changes.
// Deliberately NOT unique: the per-product PAX cap legitimately splits one departure into
// several groups (production has two 14:30 groups of product 961801 on 2026-08-12).
// Manual merges keep NULL - they are not bucketed and are never touched by auto-grouping.
// Same statements in database/migrations/20260920_group_bucket_key.sql.
// Returns true when the column is usable.
function ensureGroupBucketKeyColumn($conn) {
    $c = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'bucket_key'");
    if ($c && $c->num_rows > 0) {
        return true;
    }
    try {
        $conn->query("ALTER TABLE tour_groups ADD COLUMN `bucket_key` VARCHAR(64) NULL DEFAULT NULL AFTER `is_manual_merge`");
        $conn->query("ALTER TABLE tour_groups ADD KEY `idx_tour_groups_bucket_key` (`bucket_key`)");
        // Backfill from the members the groups already have (MIN(product_id): every member of a
        // bucket shares the product by construction).
        $conn->query("UPDATE tour_groups tg
                         JOIN (SELECT group_id, MIN(product_id) pid FROM tours
                                WHERE group_id IS NOT NULL AND product_id IS NOT NULL
                                GROUP BY group_id) m ON m.group_id = tg.id
                        SET tg.bucket_key = CONCAT(m.pid, '|', DATE_FORMAT(tg.group_date, '%Y-%m-%d'), '|', DATE_FORMAT(tg.group_time, '%H:%i'))
                      WHERE tg.is_manual_merge = 0");
        error_log("Bokun Sync: added tour_groups.bucket_key, backfilled " . $conn->affected_rows . " rows (step 3.7)");
        return true;
    } catch (mysqli_sql_exception $e) {
        error_log("Bokun Sync: ensureGroupBucketKeyColumn: " . $e->getMessage());
        $c = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'bucket_key'");
        return ($c && $c->num_rows > 0);
    }
}

/**
 * Step 3.9: validate a date parameter that reaches the sync from a request.
 * Must be a real calendar date in Y-m-d (so "2026-02-31" is refused, not silently shifted) and
 * inside a sane window - the sync window drives Bokun paging and the grouping range, so a typo
 * like "0001-01-01" would ask Bokun for two thousand years of bookings.
 * Returns null when acceptable, or the message to send with HTTP 400.
 */
function bokunDateParamError($value, $label) {
    if ($value === null || $value === '') {
        return null; // omitted: the caller's default window applies
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return "$label must be a date in YYYY-MM-DD format";
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        return "$label is not a real date";
    }
    if ($value < '2015-01-01' || $value > date('Y-m-d', strtotime('+5 years'))) {
        return "$label is outside the supported range (2015-01-01 .. +5 years)";
    }
    return null;
}

/**
 * Step 3.9: both ends of a sync window at once, including start <= end.
 */
function bokunDateRangeError($startDate, $endDate) {
    foreach ([['start_date', $startDate], ['end_date', $endDate]] as $pair) {
        $err = bokunDateParamError($pair[1], $pair[0]);
        if ($err !== null) {
            return $err;
        }
    }
    if ($startDate && $endDate && $startDate > $endDate) {
        return 'start_date must not be after end_date';
    }
    return null;
}

// Self-provision the sync_logs table (idempotent, same pattern as bokun_webhook_logs).
function ensureSyncLogsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS sync_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sync_type VARCHAR(50) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        status VARCHAR(20) NOT NULL,
        bookings_found INT NOT NULL DEFAULT 0,
        bookings_synced INT NOT NULL DEFAULT 0,
        bookings_created INT NOT NULL DEFAULT 0,
        bookings_updated INT NOT NULL DEFAULT 0,
        bookings_failed INT NOT NULL DEFAULT 0,
        error_message TEXT NULL,
        triggered_by VARCHAR(100) NULL,
        duration_seconds DECIMAL(10,2) NULL,
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        KEY idx_sync_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Get Bokun configuration
function getBokunConfig() {
    global $conn;

    $result = $conn->query("SELECT * FROM bokun_config ORDER BY id ASC LIMIT 1"); // step 1.2: always the single (lowest-id) row
    if ($result && $result->num_rows > 0) {
        $config = $result->fetch_assoc();

        // Decrypt credentials if encryption is available
        // Uses backward-compatible decryption (handles both encrypted and plain text)
        if (class_exists('Encryption')) {
            if (isset($config['api_key'])) {
                $config['api_key'] = Encryption::ensureDecrypted($config['api_key']);
            }
            if (isset($config['api_secret'])) {
                $config['api_secret'] = Encryption::ensureDecrypted($config['api_secret']);
            }
        }

        // Map production column names to expected names for backward compatibility
        if (isset($config['api_key'])) {
            $config['access_key'] = $config['api_key'];
        }
        if (isset($config['api_secret'])) {
            $config['secret_key'] = $config['api_secret'];
        }
        return $config;
    }
    return null;
}

// Step 1.2: the only config shape that may leave the server. Never the row, never a key.
function maskedBokunConfig() {
    global $conn;
    $empty = ['configured' => false, 'sync_enabled' => false, 'vendor_id' => null,
              'last_sync' => null, 'api_key_masked' => null, 'updated_at' => null];
    $result = $conn->query("SELECT vendor_id, sync_enabled, last_sync, updated_at, api_key FROM bokun_config ORDER BY id ASC LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        return $empty;
    }
    $row = $result->fetch_assoc();
    $key = (string) $row['api_key'];
    if (class_exists('Encryption') && $key !== '') {
        $key = (string) Encryption::ensureDecrypted($key);
    }
    return [
        'configured' => $key !== '',
        'sync_enabled' => bokunSyncEnabledByEnv() && (bool) $row['sync_enabled'],
        'vendor_id' => $row['vendor_id'],
        'last_sync' => $row['last_sync'],
        'api_key_masked' => $key !== '' ? substr($key, 0, 4) . "\xE2\x80\xA6" : null,
        'updated_at' => $row['updated_at'],
    ];
}

// Save Bokun configuration
function saveBokunConfig($data) {
    global $conn;

    $accessKey = $data['access_key'] ?? '';
    $secretKey = $data['secret_key'] ?? '';
    $vendorId = $data['vendor_id'] ?? '';
    $syncEnabled = !empty($data['sync_enabled']) && $data['sync_enabled'] !== 'false' ? 1 : 0; // step 1.2: JSON false really disables

    // Step 1.6: fail closed. Without a usable ENCRYPTION_KEY nothing is written - the
    // caller gets 500 {success:false, error:'encryption_unavailable'} and the row is untouched.
    try {
        if (!class_exists('Encryption') || !Encryption::init()) {
            throw new RuntimeException('encryption_unavailable');
        }
        if ($accessKey !== '') {
            $accessKey = Encryption::encrypt($accessKey);
        }
        if ($secretKey !== '') {
            $secretKey = Encryption::encrypt($secretKey);
        }
    } catch (Throwable $e) {
        error_log('saveBokunConfig: ' . $e->getMessage() . ' - nothing stored');
        http_response_code(500);
        return ['success' => false, 'error' => 'encryption_unavailable'];
    }

    // Step 1.2 guard: there is exactly one config row (lowest id). It is updated in place;
    // a second row is never inserted, and empty key fields keep the stored (encrypted) values
    // so saving vendor/flags alone cannot wipe the credentials.
    $result = $conn->query("SELECT id, api_key, api_secret FROM bokun_config ORDER BY id ASC LIMIT 1");

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $rowId = (int) $row['id'];
        if ($accessKey === '') { $accessKey = $row['api_key']; }
        if ($secretKey === '') { $secretKey = $row['api_secret']; }
        $stmt = $conn->prepare("
            UPDATE bokun_config
            SET api_key = ?, api_secret = ?, vendor_id = ?,
                sync_enabled = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bind_param("sssii", $accessKey, $secretKey, $vendorId, $syncEnabled, $rowId);
    } else {
        if ($accessKey === '' || $secretKey === '') {
            return ['success' => false, 'error' => 'access_key and secret_key are required for the first configuration'];
        }
        // Insert new - use correct column names from production database
        $stmt = $conn->prepare("
            INSERT INTO bokun_config (api_key, api_secret, vendor_id, sync_enabled, api_base_url, booking_channel)
            VALUES (?, ?, ?, ?, 'https://api.bokun.is', 'www.florencewithlocals.com')
        ");
        $stmt->bind_param("sssi", $accessKey, $secretKey, $vendorId, $syncEnabled);
    }

    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'encrypted' => class_exists('Encryption')];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => $error];
    }
}

// Constants for sync ranges
define('DEFAULT_SYNC_DAYS', 60);   // 2 months for regular/auto/cron sync — keeps each run under the 2000-result page cap so it finishes in seconds (120 returned too many bookings and never completed under rate limits)
define('FULL_SYNC_DAYS', 365);     // 1 year for full sync
define('PAST_DAYS_BUFFER', 7);     // Always include past 7 days

// Log sync operation to database
function logSyncOperation($syncType, $startDate, $endDate, $status, $stats = [], $errorMessage = null, $triggeredBy = null) {
    global $conn;

    ensureSyncLogsTable($conn);

    $stmt = $conn->prepare("
        INSERT INTO sync_logs (
            sync_type, start_date, end_date, status,
            bookings_found, bookings_synced, bookings_created, bookings_updated, bookings_failed,
            error_message, triggered_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $found = $stats['found'] ?? 0;
    $synced = $stats['synced'] ?? 0;
    $created = $stats['created'] ?? 0;
    $updated = $stats['updated'] ?? 0;
    $failed = $stats['failed'] ?? 0;

    $stmt->bind_param("ssssiiiisss",
        $syncType, $startDate, $endDate, $status,
        $found, $synced, $created, $updated, $failed,
        $errorMessage, $triggeredBy
    );

    $stmt->execute();
    $logId = $conn->insert_id;
    $stmt->close();

    return $logId;
}

// Update sync log when completed
function updateSyncLog($logId, $status, $stats = [], $errorMessage = null, $duration = null) {
    global $conn;

    if (!$logId) return;

    ensureSyncLogsTable($conn);

    $stmt = $conn->prepare("
        UPDATE sync_logs SET
            status = ?,
            bookings_found = ?,
            bookings_synced = ?,
            bookings_created = ?,
            bookings_updated = ?,
            bookings_failed = ?,
            error_message = ?,
            duration_seconds = ?,
            completed_at = NOW()
        WHERE id = ?
    ");

    $found = $stats['found'] ?? 0;
    $synced = $stats['synced'] ?? 0;
    $created = $stats['created'] ?? 0;
    $updated = $stats['updated'] ?? 0;
    $failed = $stats['failed'] ?? 0;

    $stmt->bind_param("siiiissdi",
        $status, $found, $synced, $created, $updated, $failed,
        $errorMessage, $duration, $logId
    );

    $stmt->execute();
    $stmt->close();
}

/**
 * Step 0.1: BOKUN_SYNC_ENABLED in the server .env. When it is set to false every
 * sync path (in-app timer, manual, webhook, cron) is refused regardless of the
 * bokun_config.sync_enabled flag (which staging inherits from the production
 * dump). Absent (production today) -> unchanged behaviour.
 */
function bokunSyncEnabledByEnv() {
    if (!EnvLoader::has('BOKUN_SYNC_ENABLED')) {
        return true;
    }
    return EnvLoader::getBool('BOKUN_SYNC_ENABLED', true);
}

// Sync bookings from Bokun
function syncBookings($startDate = null, $endDate = null, $syncType = 'auto', $triggeredBy = null) {
    global $conn;

    $startTime = microtime(true);

    if (!bokunSyncEnabledByEnv()) {
        return ['success' => false, 'error' => 'sync_disabled'];
    }

    $config = getBokunConfig();
    if (!$config || !$config['sync_enabled']) {
        return ['success' => false, 'error' => 'sync_disabled']; // step 1.2: client treats this as skip
    }

    // Make sure the private-tour flag column exists before we write to it.
    ensureIsPrivateColumn($conn);

    // Race-safety for the booking upsert: concurrent syncs (webhook + in-app,
    // or two clients' 15-min timers firing together) must not double-insert.
    ensureExternalIdUniqueIndex($conn);

    // Step 3.4: the index the per-booking existence lookup needs.
    ensureBokunBookingIdIndex($conn);

    // Step 3.1: columns for Bokun's customer price (the UPDATE/INSERT below write them).
    ensureBokunPriceColumns($conn);

    // Step 6.4: tours.source, which every write path below tests so a hand-entered
    // departure is never matched, updated, regrouped or backfilled by a sync.
    ensureManualColumns($conn);

    // Step 6.9: tours.viator_account - the INSERT below writes it, the UPDATE must not.
    ensureViatorAccountColumn($conn);
    // Step 6.14: tours.rate_title - written after every insert/update, next to is_private.
    ensureRateTitleColumn($conn);
    // Read once per run, not once per booking. Null until he connects the new Viator account,
    // which is what keeps a booking arriving on the OLD account today labelled 'legacy'.
    $viatorCutoverAt = viatorCutoverAt($conn);

    // Default to past 7 days and next 4 MONTHS (120 days) to catch advance bookings
    // This allows guide assignment for tours booked months in advance
    if (!$startDate) {
        $startDate = date('Y-m-d', strtotime('-' . PAST_DAYS_BUFFER . ' days'));
    }
    if (!$endDate) {
        $endDate = date('Y-m-d', strtotime('+' . DEFAULT_SYNC_DAYS . ' days'));
    }

    // Log sync start
    $logId = logSyncOperation($syncType, $startDate, $endDate, 'started', [], null, $triggeredBy);

    try {
        // Initialize Bokun API
        $bokunAPI = new BokunAPI($config);
        BokunAPI::resetRequestStats(); // step 3.4: count this sync's Bokun calls, not the process's

        // Get bookings from Bokun
        error_log("Bokun Sync [$syncType]: Requesting bookings from $startDate to $endDate");
        $bookingsResponse = $bokunAPI->getBookings($startDate, $endDate);

        // getBookings() now returns the items array directly after our fix
        $bookings = $bookingsResponse;
        $totalHits = count($bookings);

        error_log("Bokun Sync: Found " . count($bookings) . " bookings to process");

        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];
        $apiBookingsCount = count($bookings);

        foreach ($bookings as $booking) {
            try {
                // Transform booking to our tour format
                $tourData = $bokunAPI->transformBookingToTour($booking);

                // Auto-register product in products table if new
                if ($tourData['product_id']) {
                    $prodStmt = $conn->prepare("INSERT IGNORE INTO products (bokun_product_id, title) VALUES (?, ?)");
                    $prodStmt->bind_param("is", $tourData['product_id'], $tourData['title']);
                    $prodStmt->execute();
                    $prodStmt->close();
                }

                // Check if tour already exists and get current date/time for rescheduling detection.
                // Step 3.4: this used to be one `bokun_booking_id = ? OR external_id = ?`, which MySQL
                // cannot serve from an index (EXPLAIN type=ALL) - a full scan of the 65 MB table per
                // booking. Split into two indexed lookups: external_id first (UNIQUE, so at most one
                // row and virtually every booking is found here), bokun_booking_id only as a fallback.
                // Proved equivalent on production: 1,000 real bookings, 0 rows where the OR form and
                // the split form picked a different row (no duplicated bokun_booking_id exists).
                $existing = null;
                if ($tourData['external_id'] !== null && $tourData['external_id'] !== '') {
                    // Step 6.4: `source <> 'manual'` on BOTH lookups. A hand-entered departure has no
                    // external_id and no bokun_booking_id, so it cannot match today - the guard is
                    // here so it still cannot match if one is ever typed in by mistake. Without it a
                    // manual row could be adopted as "existing" and rewritten by the UPDATE below.
                    $stmt = $conn->prepare("SELECT id, date, time, rescheduled, original_date, original_time
                                              FROM tours
                                             WHERE external_id = ?
                                               AND (source IS NULL OR source <> 'manual')");
                    $stmt->bind_param("s", $tourData['external_id']);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
                if (!$existing && $tourData['bokun_booking_id'] !== '') {
                    $stmt = $conn->prepare("SELECT id, date, time, rescheduled, original_date, original_time
                                              FROM tours
                                             WHERE bokun_booking_id = ?
                                               AND (source IS NULL OR source <> 'manual')
                                             ORDER BY id ASC LIMIT 1");
                    $stmt->bind_param("s", $tourData['bokun_booking_id']);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                $isUpdate = false;

                if ($existing) {
                    $isUpdate = true;
                    // Check if this is a rescheduling (date or time changed)
                    $isRescheduled = false;
                    $originalDate = $existing['original_date'] ?: $existing['date'];
                    $originalTime = $existing['original_time'] ?: $existing['time'];

                    // Step 3.2: compare normalised values. MySQL TIME is '10:00:00', Bokun's startTimeStr
                    // is '10:00' - the old `!==` was therefore always true and every booking was flagged
                    // rescheduled (and rescheduled_at rewritten) on every sync.
                    if (bokunIsRescheduled($existing['date'], $existing['time'], $tourData['date'], $tourData['time'])) {
                        $isRescheduled = true;
                        // Unconditional again (it was gated in step 2.4 only because of that bug): it now
                        // fires for real reschedules only.
                        error_log("Rescheduling detected for {$tourData['external_id']}: {$existing['date']} {$existing['time']} → {$tourData['date']} {$tourData['time']}");

                        // If this is the first rescheduling, save the original date/time
                        if (!$existing['rescheduled']) {
                            $originalDate = $existing['date'];
                            $originalTime = $existing['time'];
                        }
                    }

                    // Update existing tour with rescheduling information.
                    // Step 3.1: paid, payment_status, total_amount_paid and expected_amount are LOCAL
                    // state - they are deliberately NOT in this column list (INSERT only, below).
                    // Step 6.9: viator_account joins them. A legacy row must be incapable of being
                    // relabelled or adopted by the new Viator channel, not merely unlikely to be.
                    $stmt = $conn->prepare("
                        UPDATE tours SET
                        title = ?, date = ?, time = ?, duration = ?, language = ?,
                        customer_name = ?, customer_email = ?, customer_phone = ?,
                        participants = ?, participant_names = ?, booking_channel = ?,
                        bokun_total_price = ?, bokun_currency = ?,
                        cancelled = ?, bokun_data = ?, last_sync = ?,
                        rescheduled = ?, original_date = ?, original_time = ?,
                        product_id = ?,
                        rescheduled_at = " . ($isRescheduled ? "NOW()" : "rescheduled_at") . ",
                        updated_at = NOW()
                        WHERE id = ?
                    ");
                    $rescheduledFlag = ($isRescheduled || $existing['rescheduled']) ? 1 : 0;
                    $stmt->bind_param("ssssssssissdsississii",
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['participant_names'], $tourData['booking_channel'],
                        $tourData['bokun_total_price'], $tourData['bokun_currency'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync'],
                        $rescheduledFlag, $originalDate, $originalTime, $tourData['product_id'], $existing['id']
                    );
                } else {
                    // Step 6.9: the old/new Viator account label, written on INSERT ONLY. It is
                    // absent from the UPDATE above and from this statement's ON DUPLICATE KEY
                    // UPDATE clause for the same reason paid/payment_status are (step 3.1): once
                    // a booking has been stamped 'legacy' nothing must be able to relabel it,
                    // because after he disconnects the old account the information needed to
                    // work the label out again does not exist anywhere.
                    $viatorAccount = viatorAccountForInsert(
                        $tourData['booking_channel'], $tourData['bokun_data'], $viatorCutoverAt
                    );

                    // Insert new tour. ON DUPLICATE KEY UPDATE (backed by uniq_tours_external_id)
                    // makes this race-safe: if a concurrent sync inserted the same booking after
                    // our SELECT above, this becomes a light update instead of a duplicate row.
                    // id = LAST_INSERT_ID(id) keeps $conn->insert_id valid on that path.
                    $stmt = $conn->prepare("
                        INSERT INTO tours (
                            external_id, bokun_booking_id, bokun_confirmation_code, title, date, time, duration, language,
                            customer_name, customer_email, customer_phone, participants, participant_names,
                            booking_channel, total_amount_paid, expected_amount, payment_status, paid,
                            bokun_total_price, bokun_currency,
                            external_source, needs_guide_assignment, guide_id, cancelled,
                            bokun_data, last_sync, product_id, viator_account, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            id = LAST_INSERT_ID(id),
                            bokun_total_price = VALUES(bokun_total_price),
                            bokun_currency = VALUES(bokun_currency),
                            bokun_data = VALUES(bokun_data),
                            last_sync = VALUES(last_sync),
                            updated_at = NOW()
                    ");
                    $stmt->bind_param("sssssssssssissddsidssiiissis",
                        $tourData['external_id'], $tourData['bokun_booking_id'], $tourData['bokun_confirmation_code'],
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['participant_names'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['bokun_total_price'], $tourData['bokun_currency'],
                        $tourData['external_source'], $tourData['needs_guide_assignment'], $tourData['guide_id'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync'], $tourData['product_id'],
                        $viatorAccount
                    );
                }

                if ($stmt->execute()) {
                    // Capture before any further statement resets them.
                    $upsertAffected = $conn->affected_rows;
                    // Classify private (single source of truth) and persist on every write.
                    // Step 6.14: the rate title rides in the same statement (insert and update
                    // alike); it is the only column this step adds to the sync's writes.
                    $rowId = $isUpdate ? (int) $existing['id'] : (int) $conn->insert_id;
                    if ($rowId > 0) {
                        list($rateId, $rateTitle) = bokunRateInfo($booking);
                        $isPriv = isPrivateBooking($tourData['product_id'], $rateId, $rateTitle) ? 1 : 0;
                        $storedRateTitle = rateTitleFromBokun($booking);
                        $ipStmt = $conn->prepare("UPDATE tours SET is_private = ?, rate_title = ? WHERE id = ?");
                        $ipStmt->bind_param("isi", $isPriv, $storedRateTitle, $rowId);
                        $ipStmt->execute();
                        $ipStmt->close();
                    }

                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        // affected_rows: 1 = fresh insert, 2 = duplicate-key update (lost the race)
                        if ($upsertAffected === 2) {
                            $updatedCount++;
                        } else {
                            $createdCount++;
                        }
                    }
                } else {
                    $failedCount++;
                    $errors[] = "Failed to save booking: " . $tourData['external_id'];
                }
                $stmt->close();

            } catch (Exception $e) {
                $failedCount++;
                $errors[] = "Error processing booking: " . $e->getMessage();

                // Send individual booking errors to Sentry
                if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
                    $bookingId = $booking['id'] ?? $booking['confirmationCode'] ?? 'unknown';
                    sentry_capture_exception($e, [
                        'context' => 'bokun_booking_processing',
                        'booking_id' => $bookingId,
                        'sync_type' => $syncType
                    ]);
                }
            }
        }

        // Update last sync timestamp
        $conn->query("UPDATE bokun_config SET last_sync = NOW() ORDER BY id ASC LIMIT 1");

        // Auto-group tours after sync: when bookings were synced, and (step 3.3) also when Bokun
        // legitimately has NO bookings in the window - the only booking of a date may have moved
        // away, and its now-empty auto group must be cleaned up. Not run when every booking failed.
        $groupingResult = null;
        if ($createdCount > 0 || $updatedCount > 0 || $apiBookingsCount === 0) {
            $groupingResult = autoGroupAfterSync($conn, $startDate, $endDate);
            if ($groupingResult) {
                error_log("Bokun Sync: Auto-grouped " . ($groupingResult['tours_grouped'] ?? 0) . " tours into " . ($groupingResult['groups_created'] ?? 0) . " groups");
            }
        }

        // Step 3.9: the per-tour "~60 minutes before" reminder reconcile used to run here.
        // Step 3.10 retired it (the evening digest replaced it, sent by its own cron), and the
        // machinery is now deleted - there is nothing left to call.

        // Calculate duration and update sync log
        $duration = round(microtime(true) - $startTime, 2);
        $syncedCount = $createdCount + $updatedCount;
        $stats = [
            'found' => $apiBookingsCount,
            'synced' => $syncedCount,
            'created' => $createdCount,
            'updated' => $updatedCount,
            'failed' => $failedCount
        ];
        $status = $failedCount > 0 ? ($syncedCount > 0 ? 'partial' : 'failed') : 'completed';
        $errorMsg = count($errors) > 0 ? implode('; ', array_slice($errors, 0, 5)) : null;
        updateSyncLog($logId, $status, $stats, $errorMsg, $duration);

        // Step 6.4: the sync never writes to a hand-entered row, but it does say whether any of
        // them now look like a departure Bokun has started sending as well (same date, time and
        // PAX). Counting only - the flag itself is computed on read, in tours.php.
        $manualDupes = manualCountDuplicateCandidates($conn, $startDate, $endDate);
        if ($manualDupes['manual'] > 0) {
            error_log("Bokun Sync [$syncType]: {$manualDupes['manual']} manual departure(s) in range, "
                . "{$manualDupes['flagged']} now look like a possible duplicate of a synced booking");
        }

        // Step 6.9: once a day, count the bookings on the retiring Viator account that he still
        // has to honour, and say so loudly if the number has fallen further than the departures
        // that simply ran. Read-only apart from its own record; never allowed to fail a sync.
        try {
            viatorWatchdogRun($conn);
        } catch (Throwable $e) {
            error_log("Step 6.9: viator watchdog failed (sync unaffected): " . $e->getMessage());
        }

        // Step 3.4: one line per sync saying what it cost Bokun - this is how "Bokun request count
        // per sync" is measured before/after. Never gated by BOKUN_DEBUG_LOG (it is one line).
        $apiStats = BokunAPI::requestStats();
        error_log("Bokun Sync [$syncType]: done in {$duration}s - {$apiBookingsCount} bookings, "
            . "bokun_requests={$apiStats['bokun_requests']} product_calls={$apiStats['product_calls']} "
            . "product_cache_hits={$apiStats['product_cache_hits']} rate_limit_sleeps={$apiStats['rate_limit_sleeps']}");

        return [
            'success' => true,
            'manual_departures' => $manualDupes['manual'],
            'manual_possible_duplicates' => $manualDupes['flagged'],
            'synced_count' => $syncedCount,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'total_bookings' => $apiBookingsCount,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sync_type' => $syncType,
            'duration_seconds' => $duration,
            'errors' => $errors,
            'grouping' => $groupingResult
        ];

    } catch (Exception $e) {
        // Log failure
        $duration = round(microtime(true) - $startTime, 2);
        updateSyncLog($logId, 'failed', ['found' => 0, 'synced' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0], $e->getMessage(), $duration);

        // Send to Sentry if available
        if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
            sentry_add_breadcrumb("Bokun sync failed", 'sync', 'error', [
                'sync_type' => $syncType,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            sentry_capture_exception($e, [
                'context' => 'bokun_sync',
                'sync_type' => $syncType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'triggered_by' => $triggeredBy,
                'duration_seconds' => $duration
            ]);
        }

        return [
            'error' => 'Bokun sync failed',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sync_type' => $syncType
        ];
    }
}

// Get unassigned Bokun tours
function getUnassignedTours() {
    global $conn;
    
    $result = $conn->query("
        SELECT t.*, g.name as guide_name 
        FROM tours t
        LEFT JOIN guides g ON t.guide_id = g.id
        LEFT JOIN tour_groups tg ON t.group_id = tg.id
        WHERE t.external_source = 'bokun' 
        -- step 3.5: unassigned = no EFFECTIVE guide (tour's own or its group's). The old test,
        -- needs_guide_assignment = 1, is never cleared by a single-tour assignment.
        AND t.guide_id IS NULL AND tg.guide_id IS NULL
        AND t.cancelled = 0
        AND t.date >= CURDATE()
        ORDER BY t.date, t.time
    ");
    
    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    
    return $tours;
}

// Auto-assign guide based on rules
function autoAssignGuide($tourId) {
    global $conn;
    
    // Get tour details
    $stmt = $conn->prepare("SELECT * FROM tours WHERE id = ?");
    $stmt->bind_param("i", $tourId);
    $stmt->execute();
    $tour = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tour) {
        return ['error' => 'Tour not found'];
    }
    
    // Find available guide for this date/time
    $stmt = $conn->prepare("
        SELECT g.id, g.name, 
               COALESCE(ga.assigned_tours, 0) as assigned_tours,
               COALESCE(ga.max_tours, 2) as max_tours
        FROM guides g
        LEFT JOIN guide_availability ga ON g.id = ga.guide_id 
            AND ga.date = ? 
            AND (ga.time_slot = ? OR ga.time_slot IS NULL)
        WHERE (ga.available = 1 OR ga.available IS NULL)
        AND (ga.assigned_tours < ga.max_tours OR ga.assigned_tours IS NULL)
        ORDER BY assigned_tours ASC
        LIMIT 1
    ");
    
    $stmt->bind_param("ss", $tour['date'], $tour['time']);
    $stmt->execute();
    $guide = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($guide) {
        // Assign guide to tour
        $stmt = $conn->prepare("UPDATE tours SET guide_id = ?, needs_guide_assignment = 0 WHERE id = ?");
        $stmt->bind_param("ii", $guide['id'], $tourId);
        $stmt->execute();
        $stmt->close();
        
        // Update guide availability
        $stmt = $conn->prepare("
            INSERT INTO guide_availability (guide_id, date, time_slot, assigned_tours)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE assigned_tours = assigned_tours + 1
        ");
        $stmt->bind_param("iss", $guide['id'], $tour['date'], $tour['time']);
        $stmt->execute();
        $stmt->close();
        
        return ['success' => true, 'guide' => $guide];
    }
    
    return ['error' => 'No available guide found'];
}

// Test Bokun API connection
function testBokunConnection() {
    // First check PHP capabilities
    if (!function_exists('curl_init') && !function_exists('file_get_contents')) {
        return [
            'success' => false,
            'error' => 'Neither cURL nor file_get_contents is available for HTTP requests',
            'solution' => 'Please enable cURL extension in PHP or contact your system administrator'
        ];
    }
    
    // Test basic HTTP functionality first
    if (!function_exists('curl_init')) {
        // Test if HTTPS wrapper is enabled
        $wrappers = stream_get_wrappers();
        if (!in_array('https', $wrappers)) {
            return [
                'success' => false,
                'error' => 'HTTPS wrapper is not enabled in PHP',
                'solution' => 'Please enable allow_url_fopen and openssl extension in php.ini',
                'current_wrappers' => $wrappers
            ];
        }
        
        // Test basic connectivity
        try {
            $testContext = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            $testResult = @file_get_contents('https://www.google.com', false, $testContext);
            if ($testResult === false) {
                return [
                    'success' => false,
                    'error' => 'HTTPS requests are blocked or not working',
                    'solution' => 'Please check your firewall, proxy settings, or enable cURL extension'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Network connectivity test failed',
                'solution' => 'Please check your internet connection and firewall settings'
            ];
        }
    }
    
    $config = getBokunConfig();
    if (!$config) {
        return ['success' => false, 'error' => 'Bokun configuration not found in database'];
    }
    
    // Validate required configuration
    if (empty($config['access_key'])) {
        return ['success' => false, 'error' => 'Access Key is required'];
    }
    if (empty($config['secret_key'])) {
        return ['success' => false, 'error' => 'Secret Key is required'];
    }
    if (empty($config['vendor_id'])) {
        return ['success' => false, 'error' => 'Vendor ID is required'];
    }
    
    try {
        error_log("Testing Bokun connection with config: " . json_encode([
            'access_key' => substr($config['access_key'], 0, 8) . '...',
            'vendor_id' => $config['vendor_id'],
            'sync_enabled' => $config['sync_enabled']
        ]));
        
        $bokunAPI = new BokunAPI($config);
        $result = $bokunAPI->testConnection();
        
        error_log("Bokun test result: " . json_encode($result));
        return $result;
    } catch (Exception $e) {
        error_log("Bokun test exception: " . $e->getMessage());
        
        $errorMsg = $e->getMessage();
        $solution = '';
        
        if (strpos($errorMsg, 'operation failed') !== false) {
            $solution = 'Network request failed. Please check: 1) Internet connection, 2) Firewall settings, 3) Enable cURL extension in PHP';
        } elseif (strpos($errorMsg, 'SSL') !== false) {
            $solution = 'SSL/TLS issue. Please ensure OpenSSL extension is enabled and up to date';
        }
        
        return [
            'success' => false,
            'error' => 'Connection test failed',
            'solution' => $solution
        ];
    }
}

// Get sync history logs
function getSyncHistory($limit = 20) {
    global $conn;

    // Check if sync_logs table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sync_logs'");
    if ($tableCheck->num_rows === 0) {
        return ['logs' => [], 'table_exists' => false];
    }

    $stmt = $conn->prepare("
        SELECT * FROM sync_logs
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();

    return ['logs' => $logs, 'table_exists' => true];
}

// Get sync configuration info
function getSyncInfo() {
    global $conn;

    // Step 4.0: the app's "last sync" label follows the server (cron / webhook / manual),
    // not the browser. Latest completed run; timestamps are UTC (DB session is +00:00).
    $lastSync = null;
    try {
        ensureSyncLogsTable($conn);
        $res = $conn->query("SELECT sync_type, triggered_by, DATE_FORMAT(COALESCE(completed_at, created_at), '%Y-%m-%dT%H:%i:%sZ') AS completed_at
                             FROM sync_logs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $lastSync = [
                'completed_at' => $row['completed_at'],
                'sync_type' => $row['sync_type'],
                // webhook rows carry a booking id here - not needed by the label
                'triggered_by' => $row['sync_type'] === 'webhook' ? 'webhook' : $row['triggered_by']
            ];
        }
    } catch (Throwable $e) {
        error_log("getSyncInfo: last_sync lookup failed: " . $e->getMessage());
    }

    // Step 6.9: the latest watchdog reading, so "are the old Viator bookings still there?"
    // is answerable from one endpoint without opening the database.
    $viatorWatchdog = null;
    try { $viatorWatchdog = viatorWatchdogLatest($conn); }
    catch (Throwable $e) { error_log("getSyncInfo: viator watchdog lookup failed: " . $e->getMessage()); }

    return [
        'last_sync' => $lastSync,
        'viator_watchdog' => $viatorWatchdog,
        'environment' => ENVIRONMENT,
        'sync_enabled_env' => bokunSyncEnabledByEnv(),
        'default_sync_days' => DEFAULT_SYNC_DAYS,
        'full_sync_days' => FULL_SYNC_DAYS,
        'past_days_buffer' => PAST_DAYS_BUFFER,
        'default_date_range' => [
            'start' => date('Y-m-d', strtotime('-' . PAST_DAYS_BUFFER . ' days')),
            'end' => date('Y-m-d', strtotime('+' . DEFAULT_SYNC_DAYS . ' days'))
        ],
        'full_sync_date_range' => [
            'start' => date('Y-m-d', strtotime('-' . PAST_DAYS_BUFFER . ' days')),
            'end' => date('Y-m-d', strtotime('+' . FULL_SYNC_DAYS . ' days'))
        ]
    ];
}

/**
 * Auto-group tours after a Bokun sync.
 * Groups ungrouped, non-cancelled tours by PRODUCT identity: product_id + date + HH:MM.
 * Respects manually merged groups (is_manual_merge=1) - never touches them.
 * Splits a departure whose PAX exceeds the per-product cap.
 *
 * Step 3.7: INCREMENTAL. It used to detach every auto-grouped tour in range, delete the
 * orphans and insert a brand-new group row for every departure, so a departure got a new
 * `id` every 15 minutes (production had burned 1,507,124 ids for 1,098 live groups) and
 * everything keyed on that id - P&L overrides `pnl_tour_costs.tour_unit = 'g<id>'` above all -
 * was silently orphaned. Now each departure keeps its row: a group is matched to the members
 * it already has, only the tours that actually moved are written, a group row is updated only
 * when one of its values really changed, and a group is deleted only when its departure is
 * gone. Manual merges are untouched, as before.
 */
function autoGroupAfterSync($conn, $startDate, $endDate) {
    // Make sure the is_private flag exists (we filter on it below).
    ensureIsPrivateColumn($conn);

    // Check if tour_groups table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tour_groups'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        error_log("autoGroupAfterSync: tour_groups table does not exist, skipping");
        return null;
    }

    // Check if group_id column exists in tours
    $colCheck = $conn->query("SHOW COLUMNS FROM tours LIKE 'group_id'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        error_log("autoGroupAfterSync: group_id column does not exist in tours, skipping");
        return null;
    }

    // Older installs may lack tour_groups.max_pax - only write it when present.
    $maxPaxColCheck = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'max_pax'");
    $hasMaxPax = ($maxPaxColCheck && $maxPaxColCheck->num_rows > 0);

    // Step 3.7: the natural key of a departure, so a group can be found without its id.
    $hasBucketKey = ensureGroupBucketKeyColumn($conn);
    ensureGroupNotesColumn($conn); // step 6.13: read (never written) when a group is deleted

    // Acquire advisory lock to prevent concurrent auto-grouping
    $lockResult = $conn->query("SELECT GET_LOCK('auto_group', 10) as locked");
    $lockRow = $lockResult->fetch_assoc();
    if (!$lockRow || !$lockRow['locked']) {
        error_log("autoGroupAfterSync: Could not acquire lock, grouping already in progress");
        return ['groups_created' => 0, 'tours_grouped' => 0, 'skipped' => 'lock_unavailable'];
    }

    $groupsCreated = 0;
    $groupsReused = 0;
    $groupsUpdated = 0;
    $groupsDeleted = 0;
    $toursGrouped = 0;
    $toursMoved = 0;
    $toursDetached = 0;
    $guidesFilled = 0; // step 3.5
    $rowsWritten = 0;  // step 3.7: the number this step exists to drive to ~0

    $conn->begin_transaction();
    try {

    // (A) Every groupable booking in range. Unlike before nothing is detached first, so tours
    //     that are ALREADY in an auto group are included - that is what lets us recognise the
    //     departure. Members of a manual merge are excluded and never touched; a tour whose
    //     group row has vanished (dangling id) counts as ungrouped.
    $stmt = $conn->prepare("
        SELECT t.id, t.title, t.date, t.time, t.participants, t.product_id, t.guide_id, t.group_id, t.language
        FROM tours t
        LEFT JOIN tour_groups tg ON tg.id = t.group_id
        WHERE t.date >= ? AND t.date <= ?
          AND t.cancelled = 0
          AND t.is_private = 0
          AND t.product_id IS NOT NULL
          AND (t.source IS NULL OR t.source <> 'manual')
          AND (t.group_id IS NULL OR tg.id IS NULL OR tg.is_manual_merge = 0)
        ORDER BY t.product_id, t.date, t.time, t.id
    ");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    $stmt->close();

    // (B) Current AUTO membership in range: tourId => groupId. Cancelled and now-private tours
    //     are included here on purpose - they must be detached from their group below.
    $memStmt = $conn->prepare("
        SELECT t.id, t.group_id, t.date, t.language, t.cancelled, (tg.id IS NOT NULL) AS group_exists
        FROM tours t
        LEFT JOIN tour_groups tg ON tg.id = t.group_id
        WHERE t.date >= ? AND t.date <= ?
          AND t.group_id IS NOT NULL
          AND (tg.id IS NULL OR tg.is_manual_merge = 0)
    ");
    $memStmt->bind_param('ss', $startDate, $endDate);
    $memStmt->execute();
    $memRes = $memStmt->get_result();
    $currentMembership = [];
    $memberRows = [];
    while ($row = $memRes->fetch_assoc()) {
        $currentMembership[(int) $row['id']] = (int) $row['group_id'];
        if ($row['group_exists']) { $memberRows[] = $row; }
    }
    $memStmt->close();

    // Step 6.12: an auto group that already mixes languages is left exactly as it is - its
    // members are neither regrouped nor detached. Only the one-off migration splits such a group
    // (it knows which half keeps the guide); whatever it refused is the owner's to decide.
    $frozenGroups = mixedLanguageAutoGroupIds($memberRows);
    if ($frozenGroups) {
        $tours = array_values(array_filter($tours, function ($t) use ($frozenGroups) {
            return !($t['group_id'] && isset($frozenGroups[(int) $t['group_id']]));
        }));
        foreach ($currentMembership as $tid => $gid) {
            if (isset($frozenGroups[$gid])) { unset($currentMembership[$tid]); }
        }
    }

    // (C) The auto group rows themselves, so we can tell whether anything really changed.
    $grpStmt = $conn->prepare("
        SELECT id, group_date, group_time, display_name, total_pax, guide_id"
        . ($hasMaxPax ? ", max_pax" : "") . ($hasBucketKey ? ", bucket_key" : "") . "
        FROM tour_groups
        WHERE is_manual_merge = 0 AND group_date >= ? AND group_date <= ?
    ");
    $grpStmt->bind_param('ss', $startDate, $endDate);
    $grpStmt->execute();
    $grpRes = $grpStmt->get_result();
    $existingGroups = [];
    while ($row = $grpRes->fetch_assoc()) {
        $existingGroups[(int) $row['id']] = $row;
    }
    $grpStmt->close();

    // (D) What the departures SHOULD look like. Same bucketing and the same PAX split as before.
    $desired = [];
    foreach (buildGroupBuckets($tours) as $bucketKey => $bucketTours) {
        if (count($bucketTours) < 2) {
            continue; // a departure with one booking is not a group
        }
        $maxPax = getMaxPaxForTitle(pickDisplayTitle($bucketTours));
        foreach (splitBucketByPax($bucketTours, $maxPax) as $subGroup) {
            if (count($subGroup) < 2) {
                continue;
            }
            $displayTitle = pickDisplayTitle($subGroup);
            $desired[] = [
                'bucket_key' => $bucketKey,
                'tours'      => $subGroup,
                'tour_ids'   => array_map('intval', array_column($subGroup, 'id')),
                'date'       => $subGroup[0]['date'],
                'time'       => $subGroup[0]['time'],
                'title'      => $displayTitle,
                'total_pax'  => (int) array_sum(array_column($subGroup, 'participants')),
                'max_pax'    => (int) getMaxPaxForTitle($displayTitle),
            ];
        }
    }

    // (E) Which existing row does each desired departure keep? Most shared members wins.
    $matched = matchDesiredToExistingGroups(array_column($desired, 'tour_ids'), $currentMembership);

    // (F) Create what is new, update only what differs, and remember who ends up where.
    $desiredTourGroup = [];   // tourId => groupId after this run
    $touchedGroups = [];      // groups that were created or whose membership changed -> guide pass
    foreach ($desired as $i => $d) {
        $groupId = $matched[$i];

        if ($groupId === null) {
            $cols = ['group_date', 'group_time', 'display_name', 'total_pax', 'is_manual_merge'];
            $vals = [$d['date'], $d['time'], $d['title'], $d['total_pax'], 0];
            $types = 'sssii';
            if ($hasMaxPax)    { $cols[] = 'max_pax';    $vals[] = $d['max_pax'];    $types .= 'i'; }
            if ($hasBucketKey) { $cols[] = 'bucket_key'; $vals[] = $d['bucket_key']; $types .= 's'; }
            $insertStmt = $conn->prepare("INSERT INTO tour_groups (" . implode(', ', $cols) . ")
                                          VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
            $insertStmt->bind_param($types, ...$vals);
            if (!$insertStmt->execute()) {
                error_log("autoGroupAfterSync: Failed to create group: " . $conn->error);
                $insertStmt->close();
                continue;
            }
            $groupId = $conn->insert_id;
            $insertStmt->close();
            $groupsCreated++;
            $rowsWritten++;
            $touchedGroups[$groupId] = true;
        } else {
            $groupsReused++;
            $row = $existingGroups[$groupId] ?? null;
            $changes = [];
            $params = [];
            $types = '';
            if ($row === null || substr((string) $row['group_date'], 0, 10) !== substr((string) $d['date'], 0, 10)) {
                $changes[] = 'group_date = ?'; $params[] = $d['date']; $types .= 's';
            }
            if ($row === null || normalizeGroupTime($row['group_time']) !== normalizeGroupTime($d['time'])) {
                $changes[] = 'group_time = ?'; $params[] = $d['time']; $types .= 's';
            }
            if ($row === null || (string) $row['display_name'] !== (string) $d['title']) {
                $changes[] = 'display_name = ?'; $params[] = $d['title']; $types .= 's';
            }
            if ($row === null || (int) $row['total_pax'] !== $d['total_pax']) {
                $changes[] = 'total_pax = ?'; $params[] = $d['total_pax']; $types .= 'i';
            }
            if ($hasMaxPax && ($row === null || (int) $row['max_pax'] !== $d['max_pax'])) {
                $changes[] = 'max_pax = ?'; $params[] = $d['max_pax']; $types .= 'i';
            }
            if ($hasBucketKey && ($row === null || (string) $row['bucket_key'] !== (string) $d['bucket_key'])) {
                $changes[] = 'bucket_key = ?'; $params[] = $d['bucket_key']; $types .= 's';
            }
            if ($changes) {
                $changes[] = 'updated_at = NOW()';
                $upd = $conn->prepare("UPDATE tour_groups SET " . implode(', ', $changes) . " WHERE id = ?");
                $params[] = $groupId; $types .= 'i';
                $upd->bind_param($types, ...$params);
                $upd->execute();
                $upd->close();
                $groupsUpdated++;
                $rowsWritten++;
            }
        }

        foreach ($d['tour_ids'] as $tid) {
            $desiredTourGroup[$tid] = $groupId;
        }
        $toursGrouped += count($d['tour_ids']);
    }

    // (G) Membership: write ONLY the tours whose group actually changes.
    $byGroup = [];
    foreach ($desiredTourGroup as $tid => $gid) {
        if (!isset($currentMembership[$tid]) || $currentMembership[$tid] !== $gid) {
            $byGroup[$gid][] = $tid;
            $touchedGroups[$gid] = true;
        }
    }
    foreach ($byGroup as $gid => $tourIds) {
        $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
        $assign = $conn->prepare("UPDATE tours SET group_id = ? WHERE id IN ($placeholders)");
        $assign->bind_param(str_repeat('i', count($tourIds) + 1), ...array_merge([$gid], $tourIds));
        $assign->execute();
        $toursMoved += $assign->affected_rows > 0 ? $assign->affected_rows : 0;
        $rowsWritten += $assign->affected_rows > 0 ? $assign->affected_rows : 0;
        $assign->close();
    }

    // (H) Anything still sitting in an auto group that no departure wants (cancelled since,
    //     turned private, lost its product, moved to another date/time, or left alone in its
    //     bucket) is detached.
    $toDetach = [];
    foreach ($currentMembership as $tid => $gid) {
        if (!isset($desiredTourGroup[$tid])) {
            $toDetach[] = $tid;
        }
    }
    if ($toDetach) {
        $placeholders = implode(',', array_fill(0, count($toDetach), '?'));
        $det = $conn->prepare("UPDATE tours SET group_id = NULL WHERE id IN ($placeholders)");
        $det->bind_param(str_repeat('i', count($toDetach)), ...$toDetach);
        $det->execute();
        $toursDetached = $det->affected_rows > 0 ? $det->affected_rows : 0;
        $rowsWritten += $toursDetached;
        $det->close();
    }

    // (I) A group row dies only when its departure has no members left. Step 6.13: a group note
    //     is handed to the bookings that were in it first (appended, marked, never twice).
    preserveNotesOfGroupsAboutToBeDeleted($conn, $currentMembership);
    $conn->query("DELETE FROM tour_groups WHERE id NOT IN (SELECT DISTINCT group_id FROM tours WHERE group_id IS NOT NULL)");
    $groupsDeleted = $conn->affected_rows > 0 ? $conn->affected_rows : 0;
    $rowsWritten += $groupsDeleted;

    // (J) Guides (steps 3.5 / 3.6a), only for departures that were created or gained members -
    //     an untouched group already has its guide on every member and needs no write at all.
    foreach (array_keys($touchedGroups) as $gid) {
        $guideId = null;
        if (isset($existingGroups[$gid]) && $existingGroups[$gid]['guide_id']) {
            $guideId = (int) $existingGroups[$gid]['guide_id']; // the group's own guide wins
        }
        if ($guideId === null) {
            // A brand-new (or guide-less) departure inherits from the first member that has one.
            $guideStmt = $conn->prepare("
                SELECT t.guide_id, g.name AS guide_name
                FROM tours t
                LEFT JOIN guides g ON t.guide_id = g.id
                WHERE t.group_id = ? AND t.guide_id IS NOT NULL AND t.cancelled = 0
                ORDER BY t.id LIMIT 1
            ");
            $guideStmt->bind_param('i', $gid);
            $guideStmt->execute();
            $guideRow = $guideStmt->get_result()->fetch_assoc();
            $guideStmt->close();
            if ($guideRow) {
                $guideId = (int) $guideRow['guide_id'];
                $setGuide = $conn->prepare("UPDATE tour_groups SET guide_id = ?, guide_name = ?, updated_at = NOW() WHERE id = ?");
                $setGuide->bind_param('isi', $guideId, $guideRow['guide_name'], $gid);
                $setGuide->execute();
                $setGuide->close();
                $rowsWritten++;
            }
        }
        if ($guideId) {
            $filled = fillMissingGroupGuide($conn, $gid, $guideId);
            $guidesFilled += $filled;
            $rowsWritten += $filled;
        }
    }

    $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('auto_group')");
        error_log("autoGroupAfterSync: Transaction failed: " . $e->getMessage());
        return ['groups_created' => 0, 'tours_grouped' => 0, 'error' => 'Auto-grouping failed'];
    }

    $conn->query("SELECT RELEASE_LOCK('auto_group')");

    return [
        'guides_filled'   => $guidesFilled, // step 3.5
        'groups_created'  => $groupsCreated,
        'groups_reused'   => $groupsReused,   // step 3.7: departures that kept their id
        'groups_updated'  => $groupsUpdated,
        'groups_deleted'  => $groupsDeleted,
        'tours_grouped'   => $toursGrouped,
        'tours_moved'     => $toursMoved,
        'tours_detached'  => $toursDetached,
        'groups_frozen_mixed_language' => count($frozenGroups), // step 6.12: left for the owner
        'rows_written'    => $rowsWritten,
        'date_range'      => ['start' => $startDate, 'end' => $endDate]
    ];
}

/**
 * Pick a group's display title = the MOST FREQUENT booking title in the set.
 * Ties are broken by the title carrying the most PAX.
 */
function pickDisplayTitle($groupTours) {
    $byTitle = [];
    foreach ($groupTours as $t) {
        $tt = $t['title'];
        if (!isset($byTitle[$tt])) {
            $byTitle[$tt] = ['count' => 0, 'pax' => 0];
        }
        $byTitle[$tt]['count']++;
        $byTitle[$tt]['pax'] += intval($t['participants']);
    }
    $best = null; $bestCount = -1; $bestPax = -1;
    foreach ($byTitle as $tt => $info) {
        if ($info['count'] > $bestCount || ($info['count'] === $bestCount && $info['pax'] > $bestPax)) {
            $best = $tt; $bestCount = $info['count']; $bestPax = $info['pax'];
        }
    }
    return $best;
}

/**
 * Backfill participant_names from existing bokun_data.
 * Parses specialRequests for GYG bookings.
 */
function backfillParticipantNames() {
    global $conn;

    // Ensure column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM tours LIKE 'participant_names'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE tours ADD COLUMN `participant_names` TEXT DEFAULT NULL AFTER `participants`");
    }

    $bokunAPI = new BokunAPI(getBokunConfig() ?: []);

    $result = $conn->query("
        SELECT id, bokun_data FROM tours
        WHERE participant_names IS NULL
          AND (source IS NULL OR source <> 'manual')
          AND bokun_data IS NOT NULL
          AND bokun_data != ''
        ORDER BY id ASC
    ");

    $updated = 0;
    $skipped = 0;
    $total = $result->num_rows;

    while ($row = $result->fetch_assoc()) {
        $booking = json_decode($row['bokun_data'], true);
        if (!is_array($booking)) {
            $skipped++;
            continue;
        }

        $names = $bokunAPI->parseParticipantNames($booking);
        if ($names) {
            $stmt = $conn->prepare("UPDATE tours SET participant_names = ? WHERE id = ?");
            $stmt->bind_param("si", $names, $row['id']);
            $stmt->execute();
            $stmt->close();
            $updated++;
        } else {
            $skipped++;
        }
    }

    return [
        'success' => true,
        'total_checked' => $total,
        'updated' => $updated,
        'skipped' => $skipped
    ];
}

// Only run the web request handler when accessed over HTTP.
// When this file is included from the CLI cron entry point (bokun_cron.php),
// php_sapi_name() === 'cli', so we skip auth + routing and let the cron
// script call syncBookings() directly. HTTP access stays fully authenticated.
// When included as a library (BOKUN_SYNC_LIB defined, e.g. by bokun_webhook.php),
// we also skip the endpoint so the caller can use syncBookings() directly while
// direct HTTP access to this file remains fully authenticated.
if (php_sapi_name() !== 'cli' && !defined('BOKUN_SYNC_LIB')) {

// Require authentication for all sync operations
require_once __DIR__ . '/Middleware.php';
$authUser = Middleware::requireAuth($conn);

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Step 1.1: everything here is admin-only except the two read-only views a viewer
// needs (GET sync-info, GET unassigned). Covers sync, full-sync, backfill-names, test
// and config (GET and POST). Viewers get the uniform 403 from Middleware::forbidden().
$viewerAllowed = ($method === 'GET' && in_array($action, ['sync-info', 'unassigned'], true));
if (!$viewerAllowed && $authUser['role'] !== 'admin') {
    Middleware::forbidden();
}

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'config':
                // step 1.2: masked shape only, never the row
                echo json_encode(maskedBokunConfig());
                break;

            case 'unassigned':
                echo json_encode(getUnassignedTours());
                break;

            case 'test':
                echo json_encode(testBokunConnection());
                break;

            // Step 3.9: a sync changes data, so it is a POST. Answering 405 rather than
            // quietly running it makes a stale caller obvious instead of invisible.
            case 'sync':
            case 'full-sync':
            case 'backfill-names':
                http_response_code(405);
                header('Allow: POST');
                echo json_encode(['error' => "Use POST for action=$action (it changes data)"]);
                break;

            case 'sync-history':
                $limit = intval($_GET['limit'] ?? 20);
                echo json_encode(getSyncHistory($limit));
                break;

            case 'sync-info':
                echo json_encode(getSyncInfo());
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        switch ($action) {
            case 'config':
                $saved = saveBokunConfig($data);
                // step 1.2: same masked shape as GET on success; step 1.6: the bare error on failure
                echo json_encode(empty($saved['success']) ? $saved : array_merge($saved, maskedBokunConfig()));
                break;

            case 'sync':
                $startDate = $data['start_date'] ?? null;
                $endDate = $data['end_date'] ?? null;
                // Step 3.9: a bad window is a 400, not a Bokun request for two thousand years.
                $dateError = bokunDateRangeError($startDate, $endDate);
                if ($dateError !== null) {
                    http_response_code(400);
                    echo json_encode(['error' => $dateError]);
                    break;
                }
                $syncType = $data['type'] ?? 'manual';
                $triggeredBy = $data['triggered_by'] ?? 'user';
                echo json_encode(syncBookings($startDate, $endDate, $syncType, $triggeredBy));
                break;

            case 'backfill-names':
                echo json_encode(backfillParticipantNames());
                break;

            case 'full-sync':
                // Full sync: 1 year ahead for comprehensive guide assignment
                $startDate = date('Y-m-d', strtotime('-' . PAST_DAYS_BUFFER . ' days'));
                $endDate = date('Y-m-d', strtotime('+' . FULL_SYNC_DAYS . ' days'));
                $triggeredBy = $data['triggered_by'] ?? 'user';
                echo json_encode(syncBookings($startDate, $endDate, 'full', $triggeredBy));
                break;

            case 'auto-assign':
                $tourId = $data['tour_id'] ?? 0;
                echo json_encode(autoAssignGuide($tourId));
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

} // end: web-only request handler (skipped under CLI / cron)
?>