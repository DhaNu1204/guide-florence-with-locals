-- Step 6.16: the time a MANUAL merge really leaves at.
--
-- When the owner merges bookings booked at different times (e.g. a 14:30 booking into the 12:30
-- departure) the merge dialog asks which time the group leaves at. NULL = old behaviour: every
-- screen keeps using tour_groups.group_time. Only a manual merge writes it; the sync never does.
-- Dissolving the group deletes the row, and the column with it.
--
-- Mirrors ensureGroupDepartureTimeColumn() in public_html/api/group_helpers.php.

ALTER TABLE tour_groups ADD COLUMN `departure_time` TIME NULL DEFAULT NULL AFTER `group_time`;
