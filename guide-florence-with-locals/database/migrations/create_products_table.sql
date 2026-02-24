-- Product Classification System
-- Classifies Bokun products as 'tour' or 'ticket' to filter ticket-only products
-- from the Tours page. Auto-applied by tours.php on first request.

-- Step 1: Create products table
CREATE TABLE IF NOT EXISTS `products` (
    `bokun_product_id` int(11) NOT NULL,
    `title` varchar(500) NOT NULL DEFAULT '',
    `product_type` enum('tour','ticket') NOT NULL DEFAULT 'tour',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`bokun_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 2: Add product_id column to tours
ALTER TABLE tours ADD COLUMN `product_id` int(11) DEFAULT NULL AFTER `group_id`;
ALTER TABLE tours ADD KEY `idx_tours_product_id` (`product_id`);

-- Step 3: Backfill product_id from bokun_data JSON
UPDATE tours
SET product_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '$.productBookings[0].product.id')) AS UNSIGNED)
WHERE bokun_data IS NOT NULL AND product_id IS NULL
  AND JSON_EXTRACT(bokun_data, '$.productBookings[0].product.id') IS NOT NULL;

-- Step 4: Populate products table from existing tours
INSERT IGNORE INTO products (bokun_product_id, title)
SELECT DISTINCT product_id, title
FROM tours
WHERE product_id IS NOT NULL;

-- Step 5: Mark known ticket products
UPDATE products SET product_type = 'ticket'
WHERE bokun_product_id IN (809838, 845665, 877713, 961802, 1115497, 1119143, 1162586);
