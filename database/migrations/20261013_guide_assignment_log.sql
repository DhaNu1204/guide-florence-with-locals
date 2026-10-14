-- Step 6.19: who set or cleared a guide, when, and through which screen.
-- One row per change of a single booking (tour_id) or a departure group (group_id).
-- Written only by user-driven paths (Tours single edit, hand-entered form, group assign,
-- merge inheriting a member's guide, assistant confirm/undo, auto-assign, guide request accept).
-- The Bokun sync never writes it. Also self-provisioned by api/lib/guide_assignment_log.php.
CREATE TABLE IF NOT EXISTS guide_assignment_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tour_id INT NULL,
    group_id INT NULL,
    old_guide_id INT NULL,
    new_guide_id INT NULL,
    user_id INT NULL,
    username VARCHAR(100) NULL,
    source VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gal_tour (tour_id),
    KEY idx_gal_group (group_id),
    KEY idx_gal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
