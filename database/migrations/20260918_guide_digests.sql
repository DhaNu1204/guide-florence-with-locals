-- Step 3.10 (2026-09-18): the evening guide digest.
-- One row per guide per digest date, so the job is idempotent: a guide already marked
-- 'sent' for a date is never messaged again, whatever re-runs happen.
-- Self-provisioned by ensureGuideDigestsTable() in public_html/api/guide_digest.php
-- (identical statement), so neither environment needs this file to be run by hand.
--
-- status: pending | sent | failed | refused_not_live
--   refused_not_live = DIGEST_LIVE was false and the destination was not the test number;
--                      no Twilio call was made.
-- attempts: a rejected number is retried at most once (attempts >= 2 is never tried again).

CREATE TABLE IF NOT EXISTS guide_digests (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
