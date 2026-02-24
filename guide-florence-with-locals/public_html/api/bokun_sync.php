<?php
require_once 'config.php';
require_once 'BokunAPI.php';

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

// Include Encryption helper for secure credential storage
if (file_exists(__DIR__ . '/Encryption.php')) {
    require_once __DIR__ . '/Encryption.php';
}

// Apply rate limiting for Bokun sync operations (stricter: 10 per minute)
applyRateLimit('bokun_sync');

// Auth check function for API endpoints
function checkAuth() {
    global $conn;
    require_once __DIR__ . '/Middleware.php';
    return Middleware::verifyAuth($conn) !== false;
}

// Get Bokun configuration
function getBokunConfig() {
    global $conn;

    $result = $conn->query("SELECT * FROM bokun_config LIMIT 1");
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

// Save Bokun configuration
function saveBokunConfig($data) {
    global $conn;

    $accessKey = $data['access_key'] ?? '';
    $secretKey = $data['secret_key'] ?? '';
    $vendorId = $data['vendor_id'] ?? '';
    $syncEnabled = isset($data['sync_enabled']) ? 1 : 0;

    // Encrypt sensitive credentials before storing
    if (class_exists('Encryption') && Encryption::init()) {
        if (!empty($accessKey)) {
            $encryptedKey = Encryption::encrypt($accessKey);
            if ($encryptedKey !== false) {
                $accessKey = $encryptedKey;
            } else {
                error_log("saveBokunConfig: Failed to encrypt access_key");
            }
        }
        if (!empty($secretKey)) {
            $encryptedSecret = Encryption::encrypt($secretKey);
            if ($encryptedSecret !== false) {
                $secretKey = $encryptedSecret;
            } else {
                error_log("saveBokunConfig: Failed to encrypt secret_key");
            }
        }
    } else {
        error_log("saveBokunConfig: Encryption not available - storing credentials in plain text");
    }

    // Check if config exists
    $result = $conn->query("SELECT id FROM bokun_config LIMIT 1");

    if ($result && $result->num_rows > 0) {
        // Update existing - use correct column names from production database
        $stmt = $conn->prepare("
            UPDATE bokun_config
            SET api_key = ?, api_secret = ?, vendor_id = ?,
                sync_enabled = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = (SELECT id FROM bokun_config LIMIT 1)
        ");
        $stmt->bind_param("sssi", $accessKey, $secretKey, $vendorId, $syncEnabled);
    } else {
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
define('DEFAULT_SYNC_DAYS', 120);  // 4 months for regular sync
define('FULL_SYNC_DAYS', 365);     // 1 year for full sync
define('PAST_DAYS_BUFFER', 7);     // Always include past 7 days

// Log sync operation to database
function logSyncOperation($syncType, $startDate, $endDate, $status, $stats = [], $errorMessage = null, $triggeredBy = null) {
    global $conn;

    // Check if sync_logs table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sync_logs'");
    if ($tableCheck->num_rows === 0) {
        // Table doesn't exist yet, skip logging
        return null;
    }

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

    // Check if sync_logs table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sync_logs'");
    if ($tableCheck->num_rows === 0) {
        return;
    }

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

// Sync bookings from Bokun
function syncBookings($startDate = null, $endDate = null, $syncType = 'auto', $triggeredBy = null) {
    global $conn;

    $startTime = microtime(true);

    $config = getBokunConfig();
    if (!$config || !$config['sync_enabled']) {
        return ['error' => 'Bokun sync is not configured or disabled'];
    }

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
        error_log("Bokun Sync: Raw API response: " . json_encode($bookingsResponse));

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

                    if (($existing['date'] !== $tourData['date']) || ($existing['time'] !== $tourData['time'])) {
                        $isRescheduled = true;
                        error_log("Rescheduling detected for {$tourData['external_id']}: {$existing['date']} {$existing['time']} → {$tourData['date']} {$tourData['time']}");

                        // If this is the first rescheduling, save the original date/time
                        if (!$existing['rescheduled']) {
                            $originalDate = $existing['date'];
                            $originalTime = $existing['time'];
                        }
                    }

                    // Update existing tour with rescheduling information
                    $stmt = $conn->prepare("
                        UPDATE tours SET
                        title = ?, date = ?, time = ?, duration = ?, language = ?,
                        customer_name = ?, customer_email = ?, customer_phone = ?,
                        participants = ?, participant_names = ?, booking_channel = ?, total_amount_paid = ?,
                        expected_amount = ?, payment_status = ?, paid = ?,
                        cancelled = ?, bokun_data = ?, last_sync = ?,
                        rescheduled = ?, original_date = ?, original_time = ?,
                        rescheduled_at = " . ($isRescheduled ? "NOW()" : "rescheduled_at") . ",
                        updated_at = NOW()
                        WHERE id = ?
                    ");
                    $rescheduledFlag = ($isRescheduled || $existing['rescheduled']) ? 1 : 0;
                    $stmt->bind_param("ssssssssissddsiississi",
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['participant_names'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync'],
                        $rescheduledFlag, $originalDate, $originalTime, $existing['id']
                    );
                } else {
                    // Insert new tour
                    $stmt = $conn->prepare("
                        INSERT INTO tours (
                            external_id, bokun_booking_id, bokun_confirmation_code, title, date, time, duration, language,
                            customer_name, customer_email, customer_phone, participants, participant_names,
                            booking_channel, total_amount_paid, expected_amount, payment_status, paid,
                            external_source, needs_guide_assignment, guide_id, cancelled,
                            bokun_data, last_sync, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->bind_param("sssssssssssissddsisiiiss",
                        $tourData['external_id'], $tourData['bokun_booking_id'], $tourData['bokun_confirmation_code'],
                        $tourData['title'], $tourData['date'], $tourData['time'], $tourData['duration'], $tourData['language'],
                        $tourData['customer_name'], $tourData['customer_email'], $tourData['customer_phone'],
                        $tourData['participants'], $tourData['participant_names'], $tourData['booking_channel'], $tourData['total_amount_paid'],
                        $tourData['expected_amount'], $tourData['payment_status'], $tourData['paid'],
                        $tourData['external_source'], $tourData['needs_guide_assignment'], $tourData['guide_id'],
                        $tourData['cancelled'], $tourData['bokun_data'], $tourData['last_sync']
                    );
                }

                if ($stmt->execute()) {
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $createdCount++;
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
        $conn->query("UPDATE bokun_config SET last_sync = NOW()");

        // Auto-group tours after sync (only if we synced any bookings)
        $groupingResult = null;
        if ($createdCount > 0 || $updatedCount > 0) {
            $groupingResult = autoGroupAfterSync($conn, $startDate, $endDate);
            if ($groupingResult) {
                error_log("Bokun Sync: Auto-grouped " . ($groupingResult['tours_grouped'] ?? 0) . " tours into " . ($groupingResult['groups_created'] ?? 0) . " groups");
            }
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
    return [
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
    // Check if tour_groups table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tour_groups'");
    if ($tableCheck->num_rows === 0) {
        error_log("autoGroupAfterSync: tour_groups table does not exist, skipping");
        return null;
    }

    // Check if group_id column exists in tours
    $colCheck = $conn->query("SHOW COLUMNS FROM tours LIKE 'group_id'");
    if ($colCheck->num_rows === 0) {
        error_log("autoGroupAfterSync: group_id column does not exist in tours, skipping");
        return null;
    }

    // Acquire advisory lock to prevent concurrent auto-grouping
    $lockResult = $conn->query("SELECT GET_LOCK('auto_group', 10) as locked");
    $lockRow = $lockResult->fetch_assoc();
    if (!$lockRow || !$lockRow['locked']) {
        error_log("autoGroupAfterSync: Could not acquire lock, grouping already in progress");
        return ['groups_created' => 0, 'tours_grouped' => 0, 'skipped' => 'lock_unavailable'];
    }

    // Find ungrouped, non-cancelled tours in the sync date range
    // Exclude tours that are already in manually merged groups
    $stmt = $conn->prepare("
        SELECT t.id, t.title, t.date, t.time, t.participants, t.group_id
        FROM tours t
        LEFT JOIN tour_groups tg ON t.group_id = tg.id
        WHERE t.date >= ? AND t.date <= ?
          AND t.cancelled = 0
          AND (t.group_id IS NULL OR tg.is_manual_merge = 0)
        ORDER BY t.title, t.date, t.time
    ");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    $stmt->close();

    if (count($tours) === 0) {
        $conn->query("SELECT RELEASE_LOCK('auto_group')");
        return ['groups_created' => 0, 'tours_grouped' => 0];
    }

    // Group tours by normalized title + date + time
    $buckets = [];
    foreach ($tours as $tour) {
        $normTitle = strtolower(trim(preg_replace('/\s+/', ' ', $tour['title'])));
        $timeParts = explode(':', $tour['time']);
        $normTime = sprintf('%02d:%02d', intval($timeParts[0]), intval($timeParts[1] ?? 0));
        $key = $normTitle . '|' . $tour['date'] . '|' . $normTime;

        if (!isset($buckets[$key])) {
            $buckets[$key] = [];
        }
        $buckets[$key][] = $tour;
    }

    $groupsCreated = 0;
    $toursGrouped = 0;

    $conn->begin_transaction();
    try {

    foreach ($buckets as $key => $bucketTours) {
        // Only create groups for 2+ bookings with the same departure
        if (count($bucketTours) < 2) {
            continue;
        }

        // Split into sub-groups by max PAX (9)
        $subGroups = [];
        $current = [];
        $currentPax = 0;
        foreach ($bucketTours as $tour) {
            $pax = intval($tour['participants']);
            if ($currentPax + $pax > 9 && count($current) > 0) {
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
            $firstTour = $subGroup[0];

            // Check if any tour in this sub-group already belongs to an auto-group
            $existingGroupId = null;
            foreach ($subGroup as $t) {
                if ($t['group_id']) {
                    $existingGroupId = intval($t['group_id']);
                    break;
                }
            }

            if ($existingGroupId) {
                // Update existing group's PAX and reassign tours
                $updateStmt = $conn->prepare("
                    UPDATE tour_groups SET total_pax = ?, updated_at = NOW()
                    WHERE id = ? AND is_manual_merge = 0
                ");
                $updateStmt->bind_param('ii', $totalPax, $existingGroupId);
                $updateStmt->execute();
                $updateStmt->close();

                // Assign all tours in this sub-group to the existing group
                $tourIds = array_column($subGroup, 'id');
                $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
                $types = 'i' . str_repeat('i', count($tourIds));
                $params = array_merge([$existingGroupId], $tourIds);

                $assignStmt = $conn->prepare("UPDATE tours SET group_id = ? WHERE id IN ($placeholders)");
                $assignStmt->bind_param($types, ...$params);
                $assignStmt->execute();
                $assignStmt->close();

                $toursGrouped += count($subGroup);
            } else {
                // Create new group
                $insertStmt = $conn->prepare("
                    INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge)
                    VALUES (?, ?, ?, ?, 0)
                ");
                $insertStmt->bind_param('sssi',
                    $firstTour['date'],
                    $firstTour['time'],
                    $firstTour['title'],
                    $totalPax
                );

                if ($insertStmt->execute()) {
                    $newGroupId = $conn->insert_id;
                    $insertStmt->close();

                    // Assign tours to the new group
                    $tourIds = array_column($subGroup, 'id');
                    $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
                    $types = 'i' . str_repeat('i', count($tourIds));
                    $params = array_merge([$newGroupId], $tourIds);

                    $assignStmt = $conn->prepare("UPDATE tours SET group_id = ? WHERE id IN ($placeholders)");
                    $assignStmt->bind_param($types, ...$params);
                    $assignStmt->execute();
                    $assignStmt->close();

                    // Copy guide assignment from first tour that has one
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

// Require authentication for all sync operations
require_once __DIR__ . '/Middleware.php';
Middleware::requireAuth($conn);

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'config':
                $config = getBokunConfig();
                echo json_encode($config ?: ['configured' => false]);
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
                echo json_encode(saveBokunConfig($data));
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
?>