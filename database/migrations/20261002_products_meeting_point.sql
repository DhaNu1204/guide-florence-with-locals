-- Step 4.6 (/today): where each product's guests meet the guide. Also self-provisioned by
-- ensureProductMeetingPointColumn() in public_html/api/lib/guide_product_fields.php.
ALTER TABLE products ADD COLUMN meeting_point VARCHAR(255) NULL DEFAULT NULL;
