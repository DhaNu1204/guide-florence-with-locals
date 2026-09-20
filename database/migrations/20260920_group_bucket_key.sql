-- Step 3.7 (finding §2.2): give a departure a natural identity that survives a sync.
--
-- `tour_groups.id` used to be thrown away every 15 minutes: autoGroupAfterSync() detached every
-- auto-grouped tour in range, deleted the orphans and inserted a brand-new row for each
-- departure. Production had burned 1,507,124 ids for 1,098 live groups, and every P&L override
-- keyed on `pnl_tour_costs.tour_unit = 'g<id>'` was orphaned within 15 minutes (10 of the 11
-- group overrides on production were already dead when this step started).
--
-- `bucket_key` = "<product_id>|YYYY-MM-DD|HH:MM" - what the departure actually is.
-- NOT unique: the per-product PAX cap legitimately splits one departure into several groups
-- (production has two 14:30 groups of product 961801 on 2026-08-12). It is a lookup key.
-- Manual merges (is_manual_merge = 1) keep NULL: they are not bucketed and auto-grouping
-- never touches them.
--
-- bokun_sync.php self-provisions the same thing (ensureGroupBucketKeyColumn), so this file is
-- for a fresh database or a manual run. Safe to re-run: the ALTERs fail with "Duplicate column
-- name" / "Duplicate key name" and the UPDATE is idempotent.

ALTER TABLE tour_groups ADD COLUMN `bucket_key` VARCHAR(64) NULL DEFAULT NULL AFTER `is_manual_merge`;
ALTER TABLE tour_groups ADD KEY `idx_tour_groups_bucket_key` (`bucket_key`);

UPDATE tour_groups tg
  JOIN (SELECT group_id, MIN(product_id) pid
          FROM tours
         WHERE group_id IS NOT NULL AND product_id IS NOT NULL
         GROUP BY group_id) m ON m.group_id = tg.id
   SET tg.bucket_key = CONCAT(m.pid, '|', DATE_FORMAT(tg.group_date, '%Y-%m-%d'), '|', DATE_FORMAT(tg.group_time, '%H:%i'))
 WHERE tg.is_manual_merge = 0;

-- Step 3.7 item 5: the P&L override stops depending on a surrogate id.
-- `tour_unit` stays the primary handle (backwards compatible with every existing 'g<id>' and
-- 't<id>' row and with payments/reports that use the same string); `bucket_key` is written
-- beside it and is used as a fallback ONLY for group units, and only when the bucket resolves
-- to exactly one group - applying a departure's override to the wrong one would be worse than
-- losing it.
ALTER TABLE pnl_tour_costs ADD COLUMN `bucket_key` VARCHAR(64) NULL DEFAULT NULL AFTER `date`;
ALTER TABLE pnl_tour_costs ADD KEY `idx_pnl_costs_bucket_key` (`bucket_key`);
