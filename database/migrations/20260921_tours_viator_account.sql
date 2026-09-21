-- Step 6.9: tell the owner's OLD Viator account from the new one he is switching to.
--
-- Nothing in Bokun's stored payload distinguishes the two accounts (same channel id 12296,
-- same seller id 5194, same reference shapes, same vendor), so the only reliable marker is
-- the one we write here, BEFORE he disconnects - afterwards the information is gone.
--
-- Mirrors ensureViatorAccountColumn() / ensureViatorSwitchTable() in public_html/api/viator_helpers.php.

ALTER TABLE tours ADD COLUMN `viator_account` VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE tours ADD KEY `idx_tours_viator_account` (`viator_account`);

-- Every Viator booking that exists at this moment belongs to the old account by definition.
UPDATE tours
   SET viator_account = 'legacy'
 WHERE viator_account IS NULL
   AND (booking_channel LIKE '%Viator%' OR booking_channel LIKE '%Tripadvisor%');

-- One row recording when the new account was connected. Until cutover_at is set, every Viator
-- booking the sync inserts is still 'legacy' - he has not switched yet.
CREATE TABLE IF NOT EXISTS viator_switch (
    id          TINYINT NOT NULL PRIMARY KEY,
    cutover_at  DATETIME NULL,
    note        VARCHAR(255) NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO viator_switch (id, cutover_at, note)
VALUES (1, NULL, 'no cutover recorded - the old account is still the only one');

-- One row per watchdog run: the count of live future legacy-Viator bookings he still has to
-- honour. Written by viatorWatchdogRun() (viator_helpers.php), at most once a day from the sync.
CREATE TABLE IF NOT EXISTS viator_watchdog (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    checked_at         DATETIME NOT NULL,
    horizon            DATE NOT NULL,
    future_bookings    INT NOT NULL,
    future_departures  INT NOT NULL,
    future_pax         INT NOT NULL,
    latest_date        DATE NULL,
    expected_bookings  INT NULL,
    passed_since_last  INT NOT NULL DEFAULT 0,
    cancelled_future   INT NOT NULL DEFAULT 0,
    status             VARCHAR(16) NOT NULL,
    note               VARCHAR(255) NULL,
    KEY idx_viator_watchdog_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
