-- Step 3.4 (finding §2.6 / plan note 4.0-e): index the per-booking existence lookup.
--
-- The sync ran `SELECT ... FROM tours WHERE bokun_booking_id = ? OR external_id = ?` once per
-- booking. `external_id` is UNIQUE but `bokun_booking_id` had no index, so MySQL could not use
-- an index for the OR at all (EXPLAIN on production: type=ALL, key=NULL, rows=3830) and every
-- one of the ~810 bookings in a sync scanned the whole 65 MB table: 7.9 ms per booking, ~6.4 s
-- per sync. The sync now does two indexed lookups instead, and this is the index the second
-- one needs. Not UNIQUE: nothing guarantees legacy rows hold distinct Bokun ids.
--
-- bokun_sync.php self-provisions the same index (ensureBokunBookingIdIndex), so this file is
-- only for a fresh database or a manual run. Safe to run twice: it fails with "Duplicate key
-- name" and changes nothing.

ALTER TABLE tours ADD KEY `idx_tours_bokun_booking_id` (`bokun_booking_id`);
