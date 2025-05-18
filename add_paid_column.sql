-- Add paid column to tours table
ALTER TABLE `tours` 
ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Flag indicating if the guide has been paid (1=paid, 0=unpaid)' 
AFTER `guide_id`;

-- Update any existing tours to unpaid by default
UPDATE `tours` SET `paid` = 0 WHERE `paid` IS NULL; 