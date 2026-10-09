-- Step 4.11: when the sync first saw a booking switch to cancelled.
--
-- tours.php?action=changes reports cancellations since the app's last check ("Cancelled: 15:00
-- Uffizi Small Group, -2 PAX"). updated_at cannot be used for that: every sync rewrites it on
-- every row. Only the sync writes this column: stamped on the 0 -> 1 switch, NULL again if Bokun
-- un-cancels. Rows cancelled before this step keep NULL.
--
-- Mirrors ensureCancelledAtColumn() in public_html/api/lib/change_token.php.

ALTER TABLE tours ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL;
