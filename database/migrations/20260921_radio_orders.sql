-- Step 6.3: a record of the radio order actually sent to Vox Firenze.
--
-- Every afternoon the owner sends the supplier a WhatsApp listing tomorrow's departures grouped
-- by museum. The app now writes that message for him; this table keeps what was sent, so he can
-- see later what he ordered for a given day and what changed since - and so the accounting side
-- he asked about has something to stand on.
--
-- message_text is stored exactly as sent, including any last-minute line he typed himself.
-- receivers_total = sum of guests, transmitters_total = departures that had a guide.
--
-- radios.php self-provisions the same table (radioEnsureTables), so this file is for a fresh
-- database or a manual run. Safe to re-run.

CREATE TABLE IF NOT EXISTS radio_orders (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    order_date         DATE NOT NULL,
    message_text       TEXT NOT NULL,
    receivers_total    INT NOT NULL DEFAULT 0,
    transmitters_total INT NOT NULL DEFAULT 0,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by         INT NULL,
    KEY idx_radio_orders_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
