-- Step 6.14: the Bokun rate each booking was sold on (productBookings[0].rateTitle).
--
-- Product 1130528 (426324P28) sells "Uffizi Gallery Tour" and "Vasari Corridor Access" under one
-- product; the rate title is the only place the two differ. The sync writes this column on insert
-- and update; tools/rate_title_backfill.php fills existing rows from their stored bokun_data.
--
-- Mirrors ensureRateTitleColumn() in public_html/api/rate_helpers.php.

ALTER TABLE tours ADD COLUMN `rate_title` VARCHAR(255) NULL DEFAULT NULL;
