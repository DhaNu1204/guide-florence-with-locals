-- Step 4.11: outcome of the webhook's delayed re-check.
--
-- Bokun calls bokun_webhook.php before its booking-search returns a new GYG / website booking,
-- so the webhook's own 1-day sync usually did not store it. The webhook now answers Bokun, then
-- re-syncs the date at +15/+45/+90/+150 s until the booking is stored, and writes the outcome
-- here, e.g. 'found_after_15s/ls' or 'not_found_after_150s/ls'. NULL = no re-check was needed.
--
-- Mirrors ensureWebhookLogTable() in public_html/api/bokun_webhook.php.

ALTER TABLE bokun_webhook_logs ADD COLUMN `recheck_result` VARCHAR(40) NULL DEFAULT NULL;
