-- Step 6.17: the product a MIXED manual merge is billed as ("Counts as").
--
-- The owner adds Uffizi-only guests to a Combo departure; that departure is a Combo and the
-- guide is paid the Combo rate. When a manual merge mixes categories (Combo + Uffizi) this
-- column names the member product the group counts as: its title is the group title and its
-- category sets the guide rate (Daily P&L, Guide Tour Report). Revenue, museum tickets,
-- radios and gelato stay per booking. NULL = old behaviour (auto-groups, same-category merges).
-- Only a manual merge and the owner's "Counts as" choice write it; the sync never does.
--
-- Mirrors ensureGroupBillingProductColumn() in public_html/api/lib/billing_product.php.

ALTER TABLE tour_groups ADD COLUMN `billing_product_id` INT(11) NULL DEFAULT NULL AFTER `departure_time`;
