-- Step 6.22: a product can COUNT AS another ("Counts as" on the Products page).
--
-- The new Viator account sells through new Bokun products (5686765P1..P4) that are copies of the
-- originals (426324P14/P16/P17/P28). groups_as_product_id names the original a product counts as
-- everywhere: auto-grouping key, group cap, tour/ticket type, guide-rate category, ticket cost,
-- gelato, radios, duration, meeting point, guide reports and Daily P&L
-- (public_html/api/lib/effective_product.php). Each booking keeps its own Bokun title; the old/new
-- Viator label keeps reading the booking's own product code. One hop only (products.php refuses
-- a chain). NULL = the product is itself. Never written by the sync - only by an admin.
--
-- Mirrors ensureProductGroupsAsColumn() in public_html/api/lib/effective_product.php.

ALTER TABLE products ADD COLUMN groups_as_product_id INT NULL DEFAULT NULL;
ALTER TABLE products ADD KEY idx_products_groups_as (groups_as_product_id);
