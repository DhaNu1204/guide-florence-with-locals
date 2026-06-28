<?php
/**
 * twilio_reminders.php - Guide WhatsApp tour reminders (Twilio scheduled)
 *
 * Schedules a "1 hour before tour" WhatsApp reminder to the assigned guide via
 * Twilio's Messages API (ScheduleType=fixed) and keeps those scheduled messages
 * reconciled with the live tour data on every Bokun sync and on guide assignment.
 *
 * DESIGN CONTRACT (do not break):
 *   - This file has NO top-level side effects. Including it only defines
 *     functions; it is always safe to require_once.
 *   - It touches NO payment logic whatsoever.
 *   - It is FLAG-GATED. When TWILIO_REMINDERS_ENABLED is false/absent (the
 *     default), reconcileGuideReminders() returns immediately and makes zero
 *     Twilio calls and zero writes - a complete no-op.
 *   - Every Twilio/network call is wrapped so a failure is recorded and
 *     swallowed; it can NEVER throw out of the reconcile entry point and so can
 *     never break booking sync or guide assignment (the callers also wrap it).
 *   - The Twilio Auth Token is read from the environment and used only for the
 *     HTTP Basic auth header. It is never logged or echoed.
 *
 * Config (read via the app's EnvLoader, already populated by config.php):
 *   TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_MESSAGING_SERVICE_SID,
 *   TWILIO_GUIDE_REMINDER_CONTENT_SID, TWILIO_GUIDE_REMINDER_LEAD_MIN,
 *   TWILIO_REMINDERS_ENABLED (default false).
 */

require_once __DIR__ . '/EnvLoader.php';

/**
 * Resolve the guide-reminder configuration from the environment.
 *
 * @return array{enabled:bool,account_sid:string,auth_token:string,
 *               messaging_service_sid:string,content_sid:string,lead_min:int}
 */
function guideReminderConfig() {
    $lead = EnvLoader::getInt('TWILIO_GUIDE_REMINDER_LEAD_MIN', 60);
    if ($lead < 1) {
        $lead = 60;
    }

    return [
        'enabled'               => EnvLoader::getBool('TWILIO_REMINDERS_ENABLED', false),
        'account_sid'           => (string) EnvLoader::get('TWILIO_ACCOUNT_SID', ''),
        'auth_token'            => (string) EnvLoader::get('TWILIO_AUTH_TOKEN', ''),
        'messaging_service_sid' => (string) EnvLoader::get('TWILIO_MESSAGING_SERVICE_SID', ''),
        'content_sid'           => (string) EnvLoader::get('TWILIO_GUIDE_REMINDER_CONTENT_SID', ''),
        'lead_min'              => $lead,
    ];
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

/**
 * Schedule a single reminder for a tour via Twilio.
 *
 * Expects $tour to contain: guide_phone, guide_name, title, send_at_iso (UTC
 * ISO8601), time_hhmm. Throws on any non-success so the caller's try/catch can
 * record last_error and continue.
 *
 * @return array{sid:string,status:string}
 * @throws Exception
 */
function twScheduleReminder($conn, $tour) {
    $cfg = guideReminderConfig();

    $to = normalizeWhatsapp($tour['guide_phone'] ?? null);
    if ($to === null) {
        throw new Exception('No usable WhatsApp number for guide');
    }

    // First name only for the {{1}} variable (template: "Ciao {{1}}, ...").
    $firstName = trim((string) ($tour['guide_name'] ?? ''));
    if ($firstName !== '') {
        $parts = preg_split('/\s+/', $firstName);
        $firstName = $parts[0];
    }
    if ($firstName === '') {
        $firstName = 'Guida';
    }

    $contentVariables = json_encode([
        '1' => $firstName,
        '2' => (string) ($tour['title'] ?? ''),
        '3' => (string) ($tour['time_hhmm'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);

    $fields = [
        'MessagingServiceSid' => $cfg['messaging_service_sid'],
        'To'                  => $to,
        'ContentSid'          => $cfg['content_sid'],
        'ContentVariables'    => $contentVariables,
        'ScheduleType'        => 'fixed',
        'SendAt'              => $tour['send_at_iso'],
    ];

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['account_sid']) . '/Messages.json';
    $res = twilioPost($cfg, $url, $fields);

    if ($res['http_code'] < 200 || $res['http_code'] >= 300 || !$res['json']) {
        $detail = '';
        if ($res['json'] && isset($res['json']['message'])) {
            $detail = ' ' . $res['json']['message'];
        } elseif ($res['error']) {
            $detail = ' ' . $res['error'];
        }
        throw new Exception('Twilio schedule failed (HTTP ' . $res['http_code'] . ')' . $detail);
    }

    return [
        'sid'    => (string) ($res['json']['sid'] ?? ''),
        'status' => (string) ($res['json']['status'] ?? 'scheduled'),
    ];
}

/**
 * Cancel a previously scheduled Twilio message. Best-effort: never throws.
 * (A message already sent/canceled simply returns an error we ignore.)
 */
function twCancelReminder($sid) {
    $sid = trim((string) $sid);
    if ($sid === '') {
        return;
    }

    $cfg = guideReminderConfig();
    if ($cfg['account_sid'] === '' || $cfg['auth_token'] === '') {
        return;
    }

    try {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['account_sid'])
             . '/Messages/' . rawurlencode($sid) . '.json';
        twilioPost($cfg, $url, ['Status' => 'canceled']);
    } catch (\Throwable $e) {
        // Ignore - cancellation is best-effort.
    }
}

/**
 * Ensure the guide_reminders table exists (self-provision, like products /
 * availability_requests). Safe to call repeatedly.
 */
function ensureGuideRemindersTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS guide_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tour_id INT NOT NULL UNIQUE,
            guide_id INT NULL,
            twilio_sid VARCHAR(64) NULL,
            send_at_utc DATETIME NULL,
            status ENUM('scheduled','canceled','sent','failed') NOT NULL DEFAULT 'scheduled',
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tour (tour_id),
            INDEX idx_guide (guide_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Core reconciliation. Brings Twilio scheduled reminders in line with the live
 * tour/guide data. Idempotent and safe to run on every sync.
 *
 * Returns a small stats array (for optional logging by callers). NEVER throws.
 *
 * @return array
 */
function reconcileGuideReminders($conn) {
    $cfg = guideReminderConfig();

    // FLAG GATE - complete no-op when disabled. No Twilio calls, no writes.
    if (!$cfg['enabled']) {
        return ['skipped' => 'disabled'];
    }

    // Missing essential config - treat as disabled (still a no-op).
    if ($cfg['account_sid'] === '' || $cfg['auth_token'] === ''
        || $cfg['messaging_service_sid'] === '' || $cfg['content_sid'] === '') {
        return ['skipped' => 'unconfigured'];
    }

    $stats = ['scheduled' => 0, 'rescheduled' => 0, 'canceled' => 0, 'failed' => 0, 'skipped' => 0, 'matched' => 0];

    try {
        ensureGuideRemindersTable($conn);

        $utc  = new DateTimeZone('UTC');
        $rome = new DateTimeZone('Europe/Rome');
        $now  = new DateTimeImmutable('now', $utc);

        // Twilio requires SendAt to be >= 15 min and <= 7 days in the future.
        $minSendAt = $now->add(new DateInterval('PT15M'));
        $maxStart  = $now->add(new DateInterval('P7D'));
        $leadSpec  = new DateInterval('PT' . $cfg['lead_min'] . 'M');

        // Candidate tours: assigned, not cancelled, not a ticket product, within
        // a generous date band (tz slack around the 7-day window). Precise window
        // membership is decided in PHP using Europe/Rome local start times.
        $today    = (new DateTimeImmutable('now', $rome))->format('Y-m-d');
        $bandEnd  = (new DateTimeImmutable('now', $rome))->add(new DateInterval('P8D'))->format('Y-m-d');

        $sql = "SELECT t.id, t.guide_id, t.title, t.date, t.time,
                       g.name AS guide_name, g.phone AS guide_phone
                FROM tours t
                JOIN guides g ON g.id = t.guide_id
                WHERE t.guide_id IS NOT NULL
                  AND t.cancelled = 0
                  AND t.date BETWEEN ? AND ?
                  AND NOT EXISTS (
                        SELECT 1 FROM products pr
                        WHERE pr.bokun_product_id = t.product_id
                          AND pr.product_type = 'ticket'
                  )";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['skipped' => 'prepare_failed'];
        }
        $stmt->bind_param('ss', $today, $bandEnd);
        $stmt->execute();
        $res = $stmt->get_result();

        // tour_ids that should have an active scheduled reminder after this pass.
        $keptTourIds = [];

        while ($row = $res->fetch_assoc()) {
            $tourId  = (int) $row['id'];
            $guideId = (int) $row['guide_id'];

            // Build the tour start in Europe/Rome local time.
            $dateStr = (string) $row['date'];
            $timeStr = substr((string) $row['time'], 0, 5); // HH:MM
            if ($dateStr === '' || strlen($timeStr) < 4) {
                $stats['skipped']++;
                continue;
            }
            try {
                $start = new DateTimeImmutable($dateStr . ' ' . $timeStr . ':00', $rome);
            } catch (\Throwable $e) {
                $stats['skipped']++;
                continue;
            }

            $sendAt    = $start->sub($leadSpec)->setTimezone($utc);
            $startUtc  = $start->setTimezone($utc);

            // Eligibility: schedulable now (>=15m out) and tour starts within 7 days.
            $eligible = ($sendAt >= $minSendAt) && ($startUtc <= $maxStart);

            // A usable WhatsApp number is required to schedule.
            $to = normalizeWhatsapp($row['guide_phone']);
            if (!$eligible || $to === null) {
                // Not schedulable this round - leave any existing row for the
                // removal pass to cancel if needed.
                continue;
            }

            $desiredSendDb = $sendAt->format('Y-m-d H:i:s');

            // Existing reminder for this tour?
            $look = $conn->prepare("SELECT id, guide_id, twilio_sid, send_at_utc, status
                                    FROM guide_reminders WHERE tour_id = ? LIMIT 1");
            $look->bind_param('i', $tourId);
            $look->execute();
            $existing = $look->get_result()->fetch_assoc();

            $tourForSchedule = [
                'guide_phone' => $row['guide_phone'],
                'guide_name'  => $row['guide_name'],
                'title'       => $row['title'],
                'time_hhmm'   => $timeStr,
                'send_at_iso' => $sendAt->format('Y-m-d\TH:i:s\Z'),
            ];

            if (!$existing) {
                // No row -> schedule + insert.
                try {
                    $r = twScheduleReminder($conn, $tourForSchedule);
                    $ins = $conn->prepare("INSERT INTO guide_reminders
                        (tour_id, guide_id, twilio_sid, send_at_utc, status, last_error)
                        VALUES (?, ?, ?, ?, 'scheduled', NULL)");
                    $ins->bind_param('iiss', $tourId, $guideId, $r['sid'], $desiredSendDb);
                    $ins->execute();
                    $keptTourIds[] = $tourId;
                    $stats['scheduled']++;
                } catch (\Throwable $e) {
                    recordReminderFailure($conn, $tourId, $guideId, $desiredSendDb, $e->getMessage());
                    $stats['failed']++;
                }
                continue;
            }

            $exStatus  = (string) $existing['status'];
            $exGuide   = ($existing['guide_id'] === null) ? null : (int) $existing['guide_id'];
            $exSendAt  = (string) $existing['send_at_utc'];
            $exSid     = (string) $existing['twilio_sid'];

            if ($exStatus === 'scheduled') {
                $matches = ($exGuide === $guideId) && ($exSendAt === $desiredSendDb);
                if ($matches) {
                    $keptTourIds[] = $tourId;
                    $stats['matched']++;
                    continue;
                }
                // Tour moved or guide changed -> cancel old, schedule new, update row.
                try {
                    $r = twScheduleReminder($conn, $tourForSchedule);
                    twCancelReminder($exSid); // best-effort, after the new one is booked
                    $upd = $conn->prepare("UPDATE guide_reminders
                        SET guide_id = ?, twilio_sid = ?, send_at_utc = ?, status = 'scheduled', last_error = NULL
                        WHERE tour_id = ?");
                    $upd->bind_param('issi', $guideId, $r['sid'], $desiredSendDb, $tourId);
                    $upd->execute();
                    $keptTourIds[] = $tourId;
                    $stats['rescheduled']++;
                } catch (\Throwable $e) {
                    recordReminderFailure($conn, $tourId, $guideId, $desiredSendDb, $e->getMessage());
                    $stats['failed']++;
                }
                continue;
            }

            if ($exStatus === 'canceled' || $exStatus === 'failed') {
                // Became eligible again (e.g. re-assigned) -> schedule fresh.
                try {
                    $r = twScheduleReminder($conn, $tourForSchedule);
                    $upd = $conn->prepare("UPDATE guide_reminders
                        SET guide_id = ?, twilio_sid = ?, send_at_utc = ?, status = 'scheduled', last_error = NULL
                        WHERE tour_id = ?");
                    $upd->bind_param('issi', $guideId, $r['sid'], $desiredSendDb, $tourId);
                    $upd->execute();
                    $keptTourIds[] = $tourId;
                    $stats['scheduled']++;
                } catch (\Throwable $e) {
                    recordReminderFailure($conn, $tourId, $guideId, $desiredSendDb, $e->getMessage());
                    $stats['failed']++;
                }
                continue;
            }

            // status 'sent' -> already delivered; leave it alone, but keep it so
            // the removal pass doesn't try to act on it.
            $keptTourIds[] = $tourId;
        }

        // ---- Removal pass: cancel scheduled reminders that are no longer wanted.
        // Any 'scheduled' row whose tour is no longer eligible (cancelled,
        // unassigned, past, ticket, or outside the window) won't be in
        // $keptTourIds -> cancel it and mark canceled.
        $rmRes = $conn->query("SELECT id, tour_id, twilio_sid FROM guide_reminders WHERE status = 'scheduled'");
        if ($rmRes) {
            $keptLookup = array_flip($keptTourIds);
            while ($rm = $rmRes->fetch_assoc()) {
                $rmTourId = (int) $rm['tour_id'];
                if (isset($keptLookup[$rmTourId])) {
                    continue; // still wanted
                }
                try {
                    twCancelReminder($rm['twilio_sid']);
                } catch (\Throwable $e) {
                    // ignore - best effort
                }
                $rmId = (int) $rm['id'];
                $mark = $conn->prepare("UPDATE guide_reminders SET status = 'canceled' WHERE id = ?");
                $mark->bind_param('i', $rmId);
                $mark->execute();
                $stats['canceled']++;
            }
        }

        return $stats;
    } catch (\Throwable $e) {
        // Absolute backstop - reconcile must never throw to its callers.
        error_log('reconcileGuideReminders error: ' . $e->getMessage());
        return ['error' => 'reconcile_failed'];
    }
}

/**
 * Upsert a failure record for a tour's reminder without throwing.
 * Inserts a 'failed' row if none exists, else updates last_error in place.
 */
function recordReminderFailure($conn, $tourId, $guideId, $sendAtDb, $errorMsg) {
    try {
        $errorMsg = (string) $errorMsg;
        if (strlen($errorMsg) > 1000) {
            $errorMsg = substr($errorMsg, 0, 1000);
        }

        $look = $conn->prepare("SELECT id FROM guide_reminders WHERE tour_id = ? LIMIT 1");
        $look->bind_param('i', $tourId);
        $look->execute();
        $exists = $look->get_result()->fetch_assoc();

        if ($exists) {
            $upd = $conn->prepare("UPDATE guide_reminders
                SET guide_id = ?, send_at_utc = ?, status = 'failed', last_error = ?
                WHERE tour_id = ?");
            $upd->bind_param('issi', $guideId, $sendAtDb, $errorMsg, $tourId);
            $upd->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO guide_reminders
                (tour_id, guide_id, twilio_sid, send_at_utc, status, last_error)
                VALUES (?, ?, NULL, ?, 'failed', ?)");
            $ins->bind_param('iiss', $tourId, $guideId, $sendAtDb, $errorMsg);
            $ins->execute();
        }
    } catch (\Throwable $e) {
        // Never let failure-recording itself break the caller.
        error_log('recordReminderFailure error: ' . $e->getMessage());
    }
}
