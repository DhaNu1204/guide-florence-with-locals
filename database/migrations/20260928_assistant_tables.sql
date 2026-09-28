-- Step 7.1: the AI assistant's three tables (plan Phase 7).
--
-- api/lib/assistant_core.php self-provisions the same tables (ensureAssistantTables) on the first
-- question, so this file is for a fresh or rebuilt database. Safe to re-run (IF NOT EXISTS).
-- created_at is written in the DB session zone, UTC (step 2.1); the daily token cap converts
-- midnight Europe/Rome to UTC before comparing. Rows older than 30 days are pruned by the endpoint.

CREATE TABLE IF NOT EXISTS assistant_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assistant_conv_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The chat as the user saw it: the question and the final answer ({"text": ..., "blocks": [...]}).
-- Tool calls live only inside one request (they are in assistant_logs.tools_called).
CREATE TABLE IF NOT EXISTS assistant_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assistant_msg_conv (conversation_id, id),
    KEY idx_assistant_msg_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per question that reached the model. tools_offered proves which tools the model was given
-- (the money tool is never offered to anyone but the P&L owner, step 7.3).
CREATE TABLE IF NOT EXISTS assistant_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    conversation_id INT UNSIGNED NULL,
    question TEXT NOT NULL,
    model VARCHAR(64) NULL,
    tools_offered JSON NULL,
    tools_called JSON NULL,
    rounds TINYINT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    ms INT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assistant_logs_created (created_at),
    KEY idx_assistant_logs_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
