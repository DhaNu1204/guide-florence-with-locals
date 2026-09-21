-- Step 6.4: hand-entered departures.
--
-- A GetYourGuide listing whose Connectivity Settings say "Not connected" never reaches
-- Bokun, so no sync can produce it. These columns let the owner record the departure by
-- hand and let every sync write path recognise such a row and leave it alone.
--
--   source          NULL for everything that came from Bokun (all existing rows),
--                   'manual' for a row typed into the Add tour form.
--   manual_revenue  what the owner actually receives for the departure, NET of the
--                   channel's commission - he reads it off the GYG payout, so the P&L
--                   must not subtract a commission from it a second time.
--   manual_currency three-letter code, EUR unless he says otherwise.
--
-- Run on: staging 2026-09-21, production 2026-09-21.

ALTER TABLE tours
    ADD COLUMN `source` VARCHAR(16) NULL DEFAULT NULL AFTER `external_source`,
    ADD COLUMN `manual_revenue` DECIMAL(10,2) NULL DEFAULT NULL AFTER `source`,
    ADD COLUMN `manual_currency` CHAR(3) NULL DEFAULT NULL AFTER `manual_revenue`;

ALTER TABLE tours
    ADD KEY `idx_tours_source` (`source`);
