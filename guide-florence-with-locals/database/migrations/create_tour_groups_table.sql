-- Tour Groups Migration
-- Groups individual Bokun bookings that belong to the same tour departure
-- (same product name + date + time). Max group size: 9 PAX (Uffizi rule).

-- 1. Create tour_groups table
CREATE TABLE IF NOT EXISTS `tour_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_date` date NOT NULL,
  `group_time` time NOT NULL,
  `display_name` varchar(200) NOT NULL,
  `guide_id` int(11) DEFAULT NULL,
  `guide_name` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `max_pax` int(11) NOT NULL DEFAULT 9,
  `total_pax` int(11) NOT NULL DEFAULT 0,
  `is_manual_merge` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tour_groups_date` (`group_date`),
  KEY `idx_tour_groups_date_time` (`group_date`, `group_time`),
  KEY `idx_tour_groups_guide` (`guide_id`),
  CONSTRAINT `tour_groups_guide_fk` FOREIGN KEY (`guide_id`) REFERENCES `guides` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Groups individual bookings into tour departures';

-- 2. Add group_id column to tours table
ALTER TABLE `tours`
  ADD COLUMN IF NOT EXISTS `group_id` int(11) DEFAULT NULL COMMENT 'Tour group this booking belongs to' AFTER `notes`;

-- 3. Add index and foreign key for group_id
ALTER TABLE `tours`
  ADD KEY IF NOT EXISTS `idx_tours_group_id` (`group_id`);

-- Add FK constraint (wrapped in procedure to avoid duplicate key errors)
DELIMITER //
DROP PROCEDURE IF EXISTS add_tour_group_fk//
CREATE PROCEDURE add_tour_group_fk()
BEGIN
  DECLARE fk_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO fk_exists
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tours'
    AND CONSTRAINT_NAME = 'tours_group_fk';
  IF fk_exists = 0 THEN
    ALTER TABLE `tours`
      ADD CONSTRAINT `tours_group_fk` FOREIGN KEY (`group_id`) REFERENCES `tour_groups` (`id`) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL add_tour_group_fk();
DROP PROCEDURE IF EXISTS add_tour_group_fk;
