-- Step 7.5: one row per assignment confirmed (or undone) from an assistant card.
-- The change itself goes through the Tours endpoints (tours.php / tour-groups.php) with the user's
-- own token; this table is the audit of what the card did. whatsapp_result holds "DRY RUN: ..." on
-- staging (TWILIO_DRY_RUN=true), "sent (...)" / "failed: ..." otherwise. An undo row points at the
-- assignment it reversed (undo_of) and that row gets undone_at.
-- api/lib/assistant_assign.php self-provisions the same table. Safe to re-run.

CREATE TABLE IF NOT EXISTS assistant_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action ENUM('assign','undo') NOT NULL,
    departure_id VARCHAR(16) NOT NULL,
    from_guide_id INT NULL,
    to_guide_id INT NULL,
    whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_result VARCHAR(300) NULL,
    undo_of INT UNSIGNED NULL,
    undone_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assistant_actions_departure (departure_id),
    KEY idx_assistant_actions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
