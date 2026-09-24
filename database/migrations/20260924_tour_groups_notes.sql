-- Step 6.13: a note on a merged group (one note per departure, internal only).
--
-- `tour_groups.notes` was already part of create_tour_groups_table.sql and exists on staging and
-- production (0 of 1,293 production groups carried a note on 2026-09-24: nothing wrote it). This
-- file makes the column explicit for a fresh or older database. tour-groups.php and bokun_sync.php
-- self-provision the same column (ensureGroupNotesColumn in group_helpers.php).
-- Safe to re-run: fails with "Duplicate column name" when the column is already there.
--
-- Rules (enforced in code, not here): the sync never writes it; when a group is dissolved or loses
-- a booking the note is appended to the bookings' own notes as "[Group note] ...", never twice.

ALTER TABLE tour_groups ADD COLUMN `notes` TEXT NULL DEFAULT NULL AFTER `guide_name`;
