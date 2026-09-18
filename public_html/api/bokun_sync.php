<?php
require_once 'config.php';
require_once 'BokunAPI.php';
require_once __DIR__ . '/tour_classification.php';

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

    // Step 3.1: columns for Bokun's customer price (the UPDATE/INSERT below write them).
    ensureBokunPriceColumns($conn);

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

                // Check if tour already exists and get current date/time for rescheduling detection
                $stmt = $conn->prepare("SELECT id, date, time, rescheduled, original_date, original_time FROM tours WHERE bokun_booking_id = ? OR external_id = ?");
                $stmt->bind_param("ss", $tourData['bokun_booking_id'], $tourData['external_id']);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

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
                            bokun_data, last_sync, product_id, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            id = LAST_INSERT_ID(id),
                            bokun_total_price = VALUES(bokun_total_price),
                            bokun_currency = VALUES(bokun_currency),
                            bokun_data = VALUES(bokun_data),
                            last_sync = VALUES(last_sync),
                            updated_at = NOW()
                    ");
                    $stmt->bind_param("sssssssssssissddsidssiiissi",
                        $tourData['external_id'], $tourData['bokun_booking_id'], $tourData['bokun_confirmation_code'],
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['participant_names'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['bokun_total_price'], $tourData['bokun_currency'],
                        $tourData['external_source'], $tourData['needs_guide_assignment'], $tourData['guide_id'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync'], $tourData['product_id']
                    );
                }

                if ($stmt->execute()) {
                    // Capture before any further statement resets them.
                    $upsertAffected = $conn->affected_rows;
                    // Classify private (single source of truth) and persist on every write.
                    $rowId = $isUpdate ? (int) $existing['id'] : (int) $conn->insert_id;
                    if ($rowId > 0) {
                        list($rateId, $rateTitle) = bokunRateInfo($booking);
                        $isPriv = isPrivateBooking($tourData['product_id'], $rateId, $rateTitle) ? 1 : 0;
                        $ipStmt = $conn->prepare("UPDATE tours SET is_private = ? WHERE id = ?");
                        $ipStmt->bind_param("ii", $isPriv, $rowId);
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

        // Auto-group tours after sync (only if we synced any bookings)
        $groupingResult = null;
        if ($createdCount > 0 || $updatedCount > 0) {
            $groupingResult = autoGroupAfterSync($conn, $startDate, $endDate);
            if ($groupingResult) {
                error_log("Bokun Sync: Auto-grouped " . ($groupingResult['tours_grouped'] ?? 0) . " tours into " . ($groupingResult['groups_created'] ?? 0) . " groups");
            }
        }

        // Reconcile guide WhatsApp reminders (flag-gated; no-op when disabled).
        // Fully isolated: any failure here must never affect booking sync.
        try {
            require_once __DIR__ . '/twilio_reminders.php';
            reconcileGuideReminders($conn);
        } catch (\Throwable $reminderErr) {
            error_log('Bokun Sync: guide reminder reconcile failed (non-fatal): ' . $reminderErr->getMessage());
        }

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

        return [
            'success' => true,
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
        WHERE t.external_source = 'bokun' 
        AND t.needs_guide_assignment = 1
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

    return [
        'last_sync' => $lastSync,
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
 * Groups ungrouped, non-cancelled tours by normalized title + date + time.
 * Respects manually merged groups (is_manual_merge=1) — never touches them.
 * Splits groups that exceed 9 PAX (Uffizi rule).
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

    // Older installs may lack tour_groups.max_pax — only write it when present.
    $maxPaxColCheck = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'max_pax'");
    $hasMaxPax = ($maxPaxColCheck && $maxPaxColCheck->num_rows > 0);

    // Acquire advisory lock to prevent concurrent auto-grouping
    $lockResult = $conn->query("SELECT GET_LOCK('auto_group', 10) as locked");
    $lockRow = $lockResult->fetch_assoc();
    if (!$lockRow || !$lockRow['locked']) {
        error_log("autoGroupAfterSync: Could not acquire lock, grouping already in progress");
        return ['groups_created' => 0, 'tours_grouped' => 0, 'skipped' => 'lock_unavailable'];
    }

    $groupsCreated = 0;
    $toursGrouped = 0;

    $conn->begin_transaction();
    try {

    // (A) Rebuild auto groups in range: detach every AUTO-group tour (manual merges
    //     untouched) plus any tour whose group is dangling. This also strips
    //     now-cancelled/now-private tours out of auto groups (they won't be re-added).
    $detach = $conn->prepare("
        UPDATE tours t
        LEFT JOIN tour_groups tg ON t.group_id = tg.id
        SET t.group_id = NULL
        WHERE t.date >= ? AND t.date <= ?
          AND t.group_id IS NOT NULL
          AND (tg.id IS NULL OR tg.is_manual_merge = 0)
    ");
    $detach->bind_param('ss', $startDate, $endDate);
    $detach->execute();
    $detach->close();

    // Drop auto groups that no longer have any member tours.
    $conn->query("DELETE FROM tour_groups WHERE is_manual_merge = 0 AND id NOT IN (SELECT DISTINCT group_id FROM tours WHERE group_id IS NOT NULL)");

    // (B) Groupable candidates: non-cancelled, NON-PRIVATE, have a product_id, and
    //     not held by a manual merge (manual-group tours still carry their group_id).
    $stmt = $conn->prepare("
        SELECT t.id, t.title, t.date, t.time, t.participants, t.product_id, t.guide_id
        FROM tours t
        WHERE t.date >= ? AND t.date <= ?
          AND t.cancelled = 0
          AND t.is_private = 0
          AND t.product_id IS NOT NULL
          AND t.group_id IS NULL
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

    // (C) Bucket by PRODUCT identity: product_id | date | HH:MM (not title).
    $buckets = [];
    foreach ($tours as $tour) {
        $timeParts = explode(':', $tour['time']);
        $normTime = sprintf('%02d:%02d', intval($timeParts[0]), intval($timeParts[1] ?? 0));
        $key = $tour['product_id'] . '|' . $tour['date'] . '|' . $normTime;
        if (!isset($buckets[$key])) {
            $buckets[$key] = [];
        }
        $buckets[$key][] = $tour;
    }

    foreach ($buckets as $bucketTours) {
        // Only create groups for 2+ bookings of the same product departure.
        if (count($bucketTours) < 2) {
            continue;
        }

        // Per-product capacity from the bucket's display (most-frequent) title.
        $maxPax = getMaxPaxForTitle(pickDisplayTitle($bucketTours));

        // Split into sub-groups so PAX never exceeds the per-product max.
        $subGroups = [];
        $current = [];
        $currentPax = 0;
        foreach ($bucketTours as $tour) {
            $pax = intval($tour['participants']);
            if ($currentPax + $pax > $maxPax && count($current) > 0) {
                $subGroups[] = $current;
                $current = [];
                $currentPax = 0;
            }
            $current[] = $tour;
            $currentPax += $pax;
        }
        if (count($current) > 0) {
            $subGroups[] = $current;
        }

        foreach ($subGroups as $subGroup) {
            if (count($subGroup) < 2) {
                continue;
            }

            $totalPax = array_sum(array_column($subGroup, 'participants'));
            $displayTitle = pickDisplayTitle($subGroup);   // most frequent title (tie -> most PAX)
            $groupMax = getMaxPaxForTitle($displayTitle);
            $firstTour = $subGroup[0];

            // Always create a fresh group (we detached everything above).
            if ($hasMaxPax) {
                $insertStmt = $conn->prepare("
                    INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, max_pax, is_manual_merge)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $insertStmt->bind_param('sssii', $firstTour['date'], $firstTour['time'], $displayTitle, $totalPax, $groupMax);
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge)
                    VALUES (?, ?, ?, ?, 0)
                ");
                $insertStmt->bind_param('sssi', $firstTour['date'], $firstTour['time'], $displayTitle, $totalPax);
            }

            if ($insertStmt->execute()) {
                $newGroupId = $conn->insert_id;
                $insertStmt->close();

                // Assign tours to the new group
                $tourIds = array_column($subGroup, 'id');
                $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
                $types = str_repeat('i', count($tourIds) + 1);
                $params = array_merge([$newGroupId], $tourIds);

                $assignStmt = $conn->prepare("UPDATE tours SET group_id = ? WHERE id IN ($placeholders)");
                $assignStmt->bind_param($types, ...$params);
                $assignStmt->execute();
                $assignStmt->close();

                // Propagate a guide from the first member that already has one.
                $guideStmt = $conn->prepare("
                    SELECT t.guide_id, g.name as guide_name
                    FROM tours t
                    LEFT JOIN guides g ON t.guide_id = g.id
                    WHERE t.group_id = ? AND t.guide_id IS NOT NULL
                    LIMIT 1
                ");
                $guideStmt->bind_param('i', $newGroupId);
                $guideStmt->execute();
                $guideRow = $guideStmt->get_result()->fetch_assoc();
                $guideStmt->close();

                if ($guideRow) {
                    $updateGuideStmt = $conn->prepare("
                        UPDATE tour_groups SET guide_id = ?, guide_name = ?, updated_at = NOW() WHERE id = ?
                    ");
                    $updateGuideStmt->bind_param('isi', $guideRow['guide_id'], $guideRow['guide_name'], $newGroupId);
                    $updateGuideStmt->execute();
                    $updateGuideStmt->close();
                }

                $groupsCreated++;
                $toursGrouped += count($subGroup);
            } else {
                error_log("autoGroupAfterSync: Failed to create group: " . $conn->error);
                $insertStmt->close();
            }
        }
    }

    // Clean up orphaned groups (no tours reference them)
    $conn->query("DELETE FROM tour_groups WHERE id NOT IN (SELECT DISTINCT group_id FROM tours WHERE group_id IS NOT NULL)");

    $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('auto_group')");
        error_log("autoGroupAfterSync: Transaction failed: " . $e->getMessage());
        return ['groups_created' => 0, 'tours_grouped' => 0, 'error' => 'Auto-grouping failed'];
    }

    $conn->query("SELECT RELEASE_LOCK('auto_group')");

    return [
        'groups_created' => $groupsCreated,
        'tours_grouped' => $toursGrouped,
        'date_range' => ['start' => $startDate, 'end' => $endDate]
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

            case 'sync':
                $startDate = $_GET['start_date'] ?? null;
                $endDate = $_GET['end_date'] ?? null;
                $syncType = $_GET['type'] ?? 'manual';
                $triggeredBy = $_GET['triggered_by'] ?? 'user';
                echo json_encode(syncBookings($startDate, $endDate, $syncType, $triggeredBy));
                break;

            case 'sync-history':
                $limit = intval($_GET['limit'] ?? 20);
                echo json_encode(getSyncHistory($limit));
                break;

            case 'sync-info':
                echo json_encode(getSyncInfo());
                break;

            case 'backfill-names':
                echo json_encode(backfillParticipantNames());
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
                $syncType = $data['type'] ?? 'manual';
                $triggeredBy = $data['triggered_by'] ?? 'user';
                echo json_encode(syncBookings($startDate, $endDate, $syncType, $triggeredBy));
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