<?php
/**
 * Step 4.11d: ONE shared re-check run for all webhooks.
 *
 * 4.11a let every webhook whose booking was not stored yet sleep up to 150 s in its own PHP
 * process. A busy morning brings up to 7 webhooks inside 150 s (17 Sep, 8 Oct), i.e. up to 7
 * sleeping processes - and the account process limit is what produced the host-wide 504s on
 * 29 Sep. Now a webhook only queues its booking + date in bokun_webhook_recheck and tries the
 * named lock `fwl_webhook_recheck:<db>` without waiting: the one process that gets it runs the
 * re-checks for every queued booking (one 1-day sync per due date, shared), the others exit
 * at once. Before giving up the lock the runner looks again, so a booking queued at that
 * moment is never left without a runner.
 *
 * Needs syncBookings() (bokun_sync.php loaded as a library) and webhook_helpers.php.
 */

const WEBHOOK_RECHECK_MAX_RUN_SECONDS = 240;   // one runner never outlives set_time_limit(300)
const WEBHOOK_RECHECK_GRACE_SECONDS = 10;      // bookings due within 10 s share one sync

function ensureWebhookRecheckTable($conn) {
    // Also database/migrations/20261009_webhook_recheck_queue.sql
    $conn->query("CREATE TABLE IF NOT EXISTS bokun_webhook_recheck (
        booking_id VARCHAR(64) NOT NULL,
        sync_date DATE NOT NULL,
        log_id INT NULL,
        tries INT NOT NULL DEFAULT 0,
        added_at DATETIME NOT NULL,
        PRIMARY KEY (booking_id, sync_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Queue a booking's dates (a repeated webhook keeps the first time). */
function webhookRecheckQueue($conn, $bookingId, array $dates, $logId) {
    $stmt = $conn->prepare("INSERT IGNORE INTO bokun_webhook_recheck (booking_id, sync_date, log_id, tries, added_at)
                            VALUES (?, ?, ?, 0, NOW())");
    $id = (string) $bookingId;
    foreach ($dates as $d) {
        $stmt->bind_param("ssi", $id, $d, $logId);
        $stmt->execute();
    }
    $stmt->close();
}

function webhookRecheckLockName($conn) {
    $r = $conn->query("SELECT DATABASE() AS db");
    $db = $r ? (string) ($r->fetch_assoc()['db'] ?? '') : '';
    return substr('fwl_webhook_recheck:' . $db, 0, 64);
}

function webhookRecheckTryLock($conn, $name) {
    $stmt = $conn->prepare("SELECT GET_LOCK(?, 0) AS got");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $got = (int) ($stmt->get_result()->fetch_assoc()['got'] ?? 0);
    $stmt->close();
    return $got === 1;
}

function webhookRecheckRelease($conn, $name) {
    $stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $stmt->close();
}

function webhookRecheckPending($conn) {
    $rows = [];
    $res = $conn->query("SELECT booking_id, sync_date, log_id, tries, UNIX_TIMESTAMP(added_at) AS added,
                                UNIX_TIMESTAMP() AS now FROM bokun_webhook_recheck");
    while ($res && ($r = $res->fetch_assoc())) {
        $r['key'] = $r['booking_id'] . '|' . $r['sync_date'];
        $rows[$r['key']] = $r;
    }
    return $rows;
}

/** Done with one booking (all its dates): write the outcome on its webhook log row, unqueue it. */
function webhookRecheckFinish($conn, array $row, $label) {
    if (!empty($row['log_id'])) {
        $stmt = $conn->prepare("UPDATE bokun_webhook_logs SET recheck_result = ? WHERE id = ?");
        $logId = (int) $row['log_id'];
        $stmt->bind_param("si", $label, $logId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare("DELETE FROM bokun_webhook_recheck WHERE booking_id = ?");
    $id = (string) $row['booking_id'];
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $stmt->close();
    error_log("bokun_webhook: booking {$row['booking_id']} recheck $label");
}

/**
 * Run the re-checks if no other process is doing it. Returns 'runner' or 'queued'.
 *
 * @param callable $isStored fn(string $bookingId): bool
 * @param callable $resync fn(string $date, string[] $bookingIds): void
 * @param callable $sleep fn(int $seconds): void
 */
function webhookRecheckRun($conn, $closedBy, callable $isStored, callable $resync, callable $sleep) {
    $lock = webhookRecheckLockName($conn);
    $deadline = time() + WEBHOOK_RECHECK_MAX_RUN_SECONDS;
    $role = 'queued';
    while (time() < $deadline && webhookRecheckTryLock($conn, $lock)) {
        $role = 'runner';
        try {
            while (time() < $deadline) {
                $rows = webhookRecheckPending($conn);
                if (!$rows) { break; }
                $now = (int) reset($rows)['now'];
                $plan = webhookRecheckPlan(array_values($rows), $now, WEBHOOK_RECHECK_DELAYS, WEBHOOK_RECHECK_GRACE_SECONDS);
                foreach ($plan['expired'] as $k) {
                    if (isset($rows[$k])) {
                        webhookRecheckFinish($conn, $rows[$k], webhookRecheckLabel(
                            ['result' => 'not_found', 'waited' => $now - (int) $rows[$k]['added']], $closedBy));
                    }
                }
                if (!$plan['due']) {
                    if ($plan['next'] === null) { break; } // nothing left waiting
                    $sleep(max(1, min($plan['next'] - $now, $deadline - time())));
                    continue;
                }
                // one 1-day sync per due date, shared by every booking waiting on that date
                $byDate = [];
                foreach ($plan['due'] as $k) { $byDate[$rows[$k]['sync_date']][] = $rows[$k]['booking_id']; }
                foreach ($byDate as $date => $ids) { $resync($date, $ids); }
                $tryStmt = $conn->prepare("UPDATE bokun_webhook_recheck SET tries = tries + 1 WHERE booking_id = ? AND sync_date = ?");
                foreach ($plan['due'] as $k) {
                    $row = $rows[$k];
                    if ($isStored($row['booking_id'])) {
                        webhookRecheckFinish($conn, $row, webhookRecheckLabel(
                            ['result' => 'found', 'waited' => time() - (int) $row['added']], $closedBy));
                        continue;
                    }
                    $tryStmt->bind_param("ss", $row['booking_id'], $row['sync_date']);
                    $tryStmt->execute();
                }
                $tryStmt->close();
            }
        } finally {
            webhookRecheckRelease($conn, $lock);
        }
        // a webhook may have queued a booking after our last look but before the release: it saw
        // the lock taken and left, so look once more and carry on if something is waiting
        if (!webhookRecheckPending($conn)) { break; }
    }
    return $role;
}
