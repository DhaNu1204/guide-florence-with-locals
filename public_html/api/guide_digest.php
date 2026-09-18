<?php
/**
 * guide_digest.php - step 3.10: ONE WhatsApp per guide per evening, listing that
 * guide's departures for the NEXT day.
 *
 * Replaces the per-tour "~60 minutes before" reminder (twilio_reminders.php), which
 * sent one message per departure - up to 5 in a day for one guide - and always too
 * late to organise the day.
 *
 *   collectDigestDepartures()  DB   -> one row per DEPARTURE (tour unit), per guide
 *   buildDigestLines()         pure -> the message lines, sorted, disambiguated
 *   digestItalianDate()        pure -> "mar 17 set"
 *   sendGuideDigests()         DB + Twilio, one guide_digests row per (guide, date)
 *
 * Safety: nothing reaches a real guide unless DIGEST_LIVE=true in the server env.
 * While that flag is false the sender refuses every destination except the test number.
 */

require_once __DIR__ . '/twilio_reminders.php'; // normalizeWhatsapp(), twilioPost(), twilioDryRun()

if (!function_exists('digestConfig')) {

/**
 * Digest configuration from the environment.
 * DIGEST_LIVE is the hard gate: false (the default) = test number only.
 */
function digestConfig() {
    return [
        'enabled'               => EnvLoader::getBool('TWILIO_DIGEST_ENABLED', true),
        'live'                  => EnvLoader::getBool('DIGEST_LIVE', false),
        'account_sid'           => (string) EnvLoader::get('TWILIO_ACCOUNT_SID', ''),
        'auth_token'            => (string) EnvLoader::get('TWILIO_AUTH_TOKEN', ''),
        'messaging_service_sid' => (string) EnvLoader::get('TWILIO_MESSAGING_SERVICE_SID', ''),
        'content_sid'           => (string) EnvLoader::get('TWILIO_DIGEST_CONTENT_SID', ''),
        'test_number'           => (string) EnvLoader::get('DIGEST_TEST_NUMBER', ''),
        'dry_run'               => EnvLoader::getBool('TWILIO_DRY_RUN', false),
        // A WhatsApp template parameter may NOT contain a newline - Twilio rejects the send
        // with error 21656 (proved by the approval test on 2026-09-18). The approved
        // guide_daily_digest_it sample joins the tour lines with ", ", so that is the default.
        'line_separator'        => (string) EnvLoader::get('DIGEST_LINE_SEPARATOR', ', '),
    ];
}

/** Italian short date for {{2}}, e.g. "mar 17 set". Pure. */
function digestItalianDate($date) {
    $d = ($date instanceof DateTimeImmutable || $date instanceof DateTime)
        ? $date : new DateTimeImmutable((string) $date, new DateTimeZone('Europe/Rome'));
    $days   = ['Mon' => 'lun', 'Tue' => 'mar', 'Wed' => 'mer', 'Thu' => 'gio', 'Fri' => 'ven', 'Sat' => 'sab', 'Sun' => 'dom'];
    $months = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    return $days[$d->format('D')] . ' ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n')];
}

/**
 * Is this cron run the 21:30 Europe/Rome slot? Pure.
 *
 * The server (and so hPanel cron) runs in UTC, but the digest must go out at 21:30 Rome all
 * year - and Rome is UTC+2 in summer, UTC+1 in winter. The cron therefore fires TWICE,
 * 19:30 and 20:30 UTC, and this guard lets exactly one of them through:
 *   summer: 19:30 UTC = 21:30 Rome -> send;  20:30 UTC = 22:30 Rome -> outside
 *   winter: 19:30 UTC = 20:30 Rome -> outside; 20:30 UTC = 21:30 Rome -> send
 * (Even if both ever passed, the job is idempotent per (guide, date) and would not re-send.)
 */
function digestIsSendWindow($romeNow) {
    $minutes = ((int) $romeNow->format('G')) * 60 + (int) $romeNow->format('i');
    return $minutes >= 21 * 60 && $minutes < 22 * 60 + 30; // [21:00, 22:30)
}

/** First name for {{1}} ("Ciao {{1}}, ..."). Pure. */
function digestFirstName($fullName) {
    $n = trim((string) $fullName);
    if ($n === '') {
        return 'Guida';
    }
    $parts = preg_split('/\s+/', $n);
    return $parts[0];
}

/**
 * The lines of one guide's digest. Pure - no DB, no Twilio.
 *
 * Format (the owner's wording): "HH:MM Tour name MIDDOT N pax", plus " MIDDOT privato"
 * for a private departure. When two of THIS guide's lines start at the same time they
 * would otherwise be indistinguishable, so each gets a human departure marker
 * " MIDDOT gruppo A" / " MIDDOT gruppo B" (letters in departure order - the guide only
 * needs to tell them apart, and a numeric group id would mean nothing to them).
 *
 * Long titles are trimmed and the list is capped, because a WhatsApp body has a limit:
 * the overflow becomes "... e altri N tour".
 *
 * @param array $departures rows of ['start_time','title','pax','is_private','unit']
 * @return string[]
 */
function buildDigestLines(array $departures, $maxChars = 900, $maxTitle = 70) {
    $dot = "\xC2\xB7";      // MIDDLE DOT
    $ell = "\xE2\x80\xA6";  // HORIZONTAL ELLIPSIS

    usort($departures, function ($a, $b) {
        $t = strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
        return $t !== 0 ? $t : strcmp((string) ($a['unit'] ?? ''), (string) ($b['unit'] ?? ''));
    });

    // Which start times occur more than once for this guide?
    $seen = [];
    foreach ($departures as $d) {
        $t = (string) ($d['start_time'] ?? '');
        $seen[$t] = ($seen[$t] ?? 0) + 1;
    }
    $letterFor = [];

    $lines = [];
    $used = 0;
    $skipped = 0;
    foreach ($departures as $d) {
        $slot  = (string) ($d['start_time'] ?? '');
        $time  = substr($slot, 0, 5);
        $title = trim(preg_replace('/\s+/', ' ', (string) ($d['title'] ?? '')));
        if (function_exists('mb_strlen') && mb_strlen($title, 'UTF-8') > $maxTitle) {
            $title = rtrim(mb_substr($title, 0, $maxTitle - 1, 'UTF-8')) . $ell;
        }
        $pax = (int) ($d['pax'] ?? 0);

        $line = $time . ' ' . $title . ' ' . $dot . ' ' . $pax . ' pax';
        if (!empty($d['is_private'])) {
            $line .= ' ' . $dot . ' privato';
        }
        if (($seen[$slot] ?? 0) > 1) {
            $letterFor[$slot] = ($letterFor[$slot] ?? -1) + 1;
            $line .= ' ' . $dot . ' gruppo ' . chr(65 + min($letterFor[$slot], 25));
        }

        $len = strlen($line) + 1;
        if ($used + $len > $maxChars && count($lines) > 0) {
            $skipped++;
            continue;
        }
        $used += $len;
        $lines[] = $line;
    }
    if ($skipped > 0) {
        $lines[] = $ell . ' e altri ' . $skipped . ' tour';
    }
    return $lines;
}

/** The three template variables for one guide. Pure. */
function buildDigestVariables($guideName, $date, array $departures, $separator = ', ') {
    $lines = buildDigestLines($departures);
    return [
        '1' => digestFirstName($guideName),
        '2' => digestItalianDate($date),
        '3' => implode($separator, $lines),
    ];
}

/** The message as the guide will read it (preview only - Twilio renders the real one). */
function renderDigestBody(array $vars) {
    return 'Ciao ' . $vars['1'] . ', ecco i tuoi tour di domani ' . $vars['2'] . ":\n"
         . $vars['3'] . "\nBuona serata!";
}

/** Phone masked to the last 4 digits, for previews and logs. */
function maskPhone($phone) {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === null || $digits === '') {
        return '(no number)';
    }
    return '***' . substr($digits, -4);
}

/**
 * One row per DEPARTURE for $date, grouped by the guide who is actually on it.
 *
 * A departure is a tour unit (a group, or a single ungrouped tour) - never a booking, so
 * a departure with 3 bookings is ONE line. The guide is the EFFECTIVE guide (the group's,
 * else the tour's - the rule from step 3.5), so a date with two groups at the same time
 * and two guides produces two digests, each holding only its own departure. Cancelled
 * bookings and ticket products are excluded.
 *
 * @return array guide_id => ['guide_id','guide_name','guide_phone','departures'=>[...]]
 */
function collectDigestDepartures($conn, $date) {
    $sql = "SELECT
                IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS unit,
                MAX(COALESCE(tg.guide_id, t.guide_id)) AS guide_id,
                LEFT(COALESCE(MAX(tg.group_time), MIN(t.time)), 5) AS start_time,
                COALESCE(MAX(tg.display_name), MIN(t.title)) AS title,
                SUM(COALESCE(t.participants, 0)) AS pax,
                MAX(COALESCE(t.is_private, 0)) AS is_private,
                COUNT(*) AS bookings
            FROM tours t
            LEFT JOIN tour_groups tg ON tg.id = t.group_id
            LEFT JOIN products pr ON pr.bokun_product_id = t.product_id
            WHERE t.date = ?
              AND t.cancelled = 0
              AND (pr.product_type = 'tour' OR t.product_id IS NULL)
            GROUP BY unit
            HAVING guide_id IS NOT NULL
            ORDER BY start_time ASC, unit ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();

    $byGuide = [];
    while ($row = $res->fetch_assoc()) {
        $gid = (int) $row['guide_id'];
        if (!isset($byGuide[$gid])) {
            $byGuide[$gid] = ['guide_id' => $gid, 'guide_name' => '', 'guide_phone' => '', 'departures' => []];
        }
        $byGuide[$gid]['departures'][] = [
            'unit'       => (string) $row['unit'],
            'start_time' => (string) $row['start_time'],
            'title'      => (string) $row['title'],
            'pax'        => (int) $row['pax'],
            'is_private' => ((int) $row['is_private']) === 1,
            'bookings'   => (int) $row['bookings'],
        ];
    }
    $stmt->close();
    if (count($byGuide) === 0) {
        return [];
    }

    $ids = implode(',', array_map('intval', array_keys($byGuide)));
    $gres = $conn->query("SELECT id, name, phone FROM guides WHERE id IN ($ids)");
    while ($g = $gres->fetch_assoc()) {
        $gid = (int) $g['id'];
        $byGuide[$gid]['guide_name']  = (string) $g['name'];
        $byGuide[$gid]['guide_phone'] = (string) $g['phone'];
    }
    return $byGuide;
}

/** Self-provision guide_digests (also database/migrations/20260918_guide_digests.sql). */
function ensureGuideDigestsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS guide_digests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guide_id INT NOT NULL,
        digest_date DATE NOT NULL,
        body_hash CHAR(40) NOT NULL,
        twilio_sid VARCHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        sent_at DATETIME NULL,
        error TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_guide_digest (guide_id, digest_date),
        KEY idx_guide_digests_date (digest_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Send one digest through Twilio (no scheduling - the cron decides the moment).
 * Throws on anything that is not a 2xx, so the caller records the reason.
 *
 * @return array{sid:string,status:string,dry_run?:string}
 */
function twSendDigest($cfg, $to, array $vars) {
    if (twilioDryRun()) {
        return [
            'sid'     => 'DRYRUN-' . substr(sha1($to . '|' . $vars['2'] . '|' . $vars['3']), 0, 24),
            'status'  => 'dry_run',
            'dry_run' => 'send to ' . maskPhone($to),
        ];
    }
    if ($cfg['content_sid'] === '') {
        throw new Exception('TWILIO_DIGEST_CONTENT_SID is not set');
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['account_sid']) . '/Messages.json';
    $res = twilioPost($cfg, $url, [
        'MessagingServiceSid' => $cfg['messaging_service_sid'],
        'To'                  => $to,
        'ContentSid'          => $cfg['content_sid'],
        'ContentVariables'    => json_encode($vars, JSON_UNESCAPED_UNICODE),
    ]);
    if ($res['http_code'] < 200 || $res['http_code'] >= 300 || !$res['json']) {
        $detail = '';
        if ($res['json'] && isset($res['json']['message'])) {
            $detail = ' ' . $res['json']['message'];
        } elseif ($res['error']) {
            $detail = ' ' . $res['error'];
        }
        throw new Exception('Twilio digest send failed (HTTP ' . $res['http_code'] . ')' . $detail);
    }
    return ['sid' => (string) ($res['json']['sid'] ?? ''), 'status' => (string) ($res['json']['status'] ?? 'queued')];
}

/**
 * The evening job: one digest per guide for $date.
 *
 * - a guide with no departures that day gets nothing at all (no row, no message)
 * - one row per (guide, date): a re-run never sends twice ('sent' is final)
 * - a rejected number is recorded 'failed' with the reason; at most ONE retry
 *   (attempts >= 2 is never tried again) - no 15-minute retry loop
 * - DIGEST_LIVE=false (default): every destination except the test number is refused
 *   before any Twilio call is made
 *
 * @param array $opts ['force_to' => '+39...'] send to that number only,
 *                    ['only_guide_id' => int]
 */
function sendGuideDigests($conn, $date, array $opts = []) {
    $cfg = digestConfig();
    $stats = ['guides' => 0, 'sent' => 0, 'failed' => 0, 'already_sent' => 0, 'refused_not_live' => 0,
              'no_number' => 0, 'exhausted' => 0, 'dry_run' => 0];

    if (!$cfg['enabled']) {
        return ['skipped' => 'disabled'] + $stats;
    }
    if ($cfg['account_sid'] === '' || $cfg['auth_token'] === '' || $cfg['messaging_service_sid'] === '') {
        return ['skipped' => 'unconfigured'] + $stats;
    }

    ensureGuideDigestsTable($conn);

    $forceTo = isset($opts['force_to']) ? normalizeWhatsapp($opts['force_to']) : null;
    $testTo  = normalizeWhatsapp($cfg['test_number']);
    $byGuide = collectDigestDepartures($conn, $date);

    foreach ($byGuide as $gid => $g) {
        if (isset($opts['only_guide_id']) && (int) $opts['only_guide_id'] !== (int) $gid) {
            continue;
        }
        $stats['guides']++;
        $vars = buildDigestVariables($g['guide_name'], $date, $g['departures'], $cfg['line_separator']);
        $hash = sha1($vars['1'] . '|' . $vars['2'] . '|' . $vars['3']);

        $look = $conn->prepare("SELECT id, status, attempts FROM guide_digests WHERE guide_id = ? AND digest_date = ? LIMIT 1");
        $look->bind_param('is', $gid, $date);
        $look->execute();
        $row = $look->get_result()->fetch_assoc();
        $look->close();

        if ($row && $row['status'] === 'sent') {
            $stats['already_sent']++;
            continue;
        }
        if ($row && $row['status'] === 'failed' && (int) $row['attempts'] >= 2) {
            $stats['exhausted']++;
            continue;
        }

        // Destination + the hard live gate.
        $to = $forceTo ? $forceTo : normalizeWhatsapp($g['guide_phone']);
        if ($to === null) {
            digestRecord($conn, $gid, $date, $hash, null, 'failed', 'No usable WhatsApp number for guide');
            $stats['no_number']++;
            continue;
        }
        if (!$cfg['live'] && $to !== $testTo && $to !== $forceTo) {
            // HARD GATE: not live -> only the test number may ever be contacted.
            digestRecord($conn, $gid, $date, $hash, null, 'refused_not_live',
                'DIGEST_LIVE=false: refused (only the test number may be contacted)');
            $stats['refused_not_live']++;
            continue;
        }

        try {
            $r = twSendDigest($cfg, $to, $vars);
            if (!empty($r['dry_run'])) {
                digestRecord($conn, $gid, $date, $hash, $r['sid'], 'sent', 'DRY RUN: ' . $r['dry_run']);
                $stats['dry_run']++;
                $stats['sent']++;
            } else {
                digestRecord($conn, $gid, $date, $hash, $r['sid'], 'sent', null);
                $stats['sent']++;
            }
        } catch (\Throwable $e) {
            digestRecord($conn, $gid, $date, $hash, null, 'failed', $e->getMessage());
            $stats['failed']++;
        }
    }

    // What Twilio actually did with the messages we just sent (see digestVerifySent).
    if (!twilioDryRun() && $stats['sent'] > 0) {
        $check = digestVerifySent($conn, $date, $cfg);
        $stats['verified'] = $check['checked'];
        if ($check['downgraded'] > 0) {
            $stats['sent'] -= $check['downgraded'];
            $stats['failed'] += $check['downgraded'];
            $stats['failed_after_send'] = $check['downgraded'];
        }
    }
    return $stats;
}

/**
 * Twilio answers the create call with 'accepted'/'queued' and decides later, so a row marked
 * 'sent' can still be a message that never arrived (exactly what error 21656 looked like on
 * 2026-09-18: HTTP 201, then status 'failed'). Right after a run we re-read every message we
 * just sent and downgrade the ones Twilio reports as failed/undelivered, so guide_digests
 * tells the truth. One GET per message (a handful per evening), best effort, never throws.
 *
 * @return array{checked:int,downgraded:int}
 */
function digestVerifySent($conn, $date, $cfg) {
    $out = ['checked' => 0, 'downgraded' => 0];
    try {
        $stmt = $conn->prepare("SELECT id, twilio_sid FROM guide_digests
                                 WHERE digest_date = ? AND status = 'sent' AND twilio_sid IS NOT NULL
                                   AND twilio_sid NOT LIKE 'DRYRUN-%' AND sent_at >= NOW() - INTERVAL 15 MINUTE");
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) === 0) {
            return $out;
        }
        sleep(5); // give Twilio a moment to move past 'accepted'
        foreach ($rows as $r) {
            $out['checked']++;
            $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['account_sid'])
                 . '/Messages/' . rawurlencode($r['twilio_sid']) . '.json';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => $cfg['account_sid'] . ':' . $cfg['auth_token'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) {
                continue;
            }
            $j = json_decode((string) $body, true);
            $st = (string) ($j['status'] ?? '');
            if ($st === 'failed' || $st === 'undelivered') {
                $err = 'Twilio ' . $st . ' (error_code ' . var_export($j['error_code'] ?? null, true) . ')';
                $upd = $conn->prepare("UPDATE guide_digests SET status = 'failed', error = ?, updated_at = NOW() WHERE id = ?");
                $upd->bind_param('si', $err, $r['id']);
                $upd->execute();
                $upd->close();
                $out['downgraded']++;
                error_log('guide digest: message ' . $r['twilio_sid'] . ' ' . $err);
            }
        }
    } catch (\Throwable $e) {
        error_log('digestVerifySent error: ' . $e->getMessage());
    }
    return $out;
}

/** Upsert one (guide, date) row; 'sent' also stamps sent_at. Never throws. */
function digestRecord($conn, $guideId, $date, $hash, $sid, $status, $error) {
    try {
        $error = $error === null ? null : substr((string) $error, 0, 1000);
        $sentAt = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare("INSERT INTO guide_digests
                (guide_id, digest_date, body_hash, twilio_sid, status, attempts, sent_at, error, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                body_hash = VALUES(body_hash), twilio_sid = VALUES(twilio_sid), status = VALUES(status),
                attempts = attempts + 1, sent_at = VALUES(sent_at), error = VALUES(error), updated_at = NOW()");
        $stmt->bind_param('issssss', $guideId, $date, $hash, $sid, $status, $sentAt, $error);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('digestRecord error: ' . $e->getMessage());
    }
}

} // function_exists guard
