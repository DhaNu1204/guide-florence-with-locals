-- Step 4.11d: bookings waiting for the webhook's delayed re-check.
--
-- One row per booking and date. Every webhook whose booking is not stored yet queues itself here;
-- only the process holding the named lock fwl_webhook_recheck:<db> runs the re-checks for all of
-- them (one shared 1-day sync per due date), so at most one PHP process sleeps for re-checks.
-- A row is deleted when the booking is stored or after its 4th check (+150 s).
--
-- Mirrors ensureWebhookRecheckTable() in public_html/api/lib/webhook_recheck.php.

CREATE TABLE IF NOT EXISTS bokun_webhook_recheck (
    booking_id VARCHAR(64) NOT NULL,
    sync_date DATE NOT NULL,
    log_id INT NULL,
    tries INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (booking_id, sync_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
