-- Step 7.2b: guide active / partner-agency flags and product durations.
--
-- guides.active: 0 = no longer taking new work. Hidden only where a guide is picked for NEW work
--   (assign dropdowns, Ask-a-guide, the assistant's free_guides); kept everywhere history is shown.
-- guides.is_partner_agency: the row stands for a partner agency, listed apart in free_guides.
-- products.duration_minutes: tour length; NULL = unknown (free_guides then assumes 120 minutes and
--   says so). Prefilled from Bokun (tools/product_duration_prefill.php), editable on /products.
-- The same columns are self-provisioned by api/lib/guide_product_fields.php.
-- Fails with "Duplicate column name" when already applied.

ALTER TABLE guides
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN is_partner_agency TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE products
    ADD COLUMN duration_minutes SMALLINT NULL DEFAULT NULL;
