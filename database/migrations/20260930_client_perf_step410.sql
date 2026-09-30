-- Step 4.10 (2026-09-30): the field recorder tells the home-screen app from a browser tab,
-- names the requests that stalled, and accepts rows re-sent by a later load.
-- client_perf.php adds these in place on its first write (clientPerfEnsureColumns410).
ALTER TABLE `client_perf`
  ADD COLUMN `display_mode` VARCHAR(12) NULL DEFAULT NULL AFTER `device`,
  ADD COLUMN `install_id`   VARCHAR(16) NULL DEFAULT NULL AFTER `display_mode`,
  ADD COLUMN `build`        VARCHAR(24) NULL DEFAULT NULL AFTER `install_id`,
  ADD COLUMN `stuck`        VARCHAR(120) NULL DEFAULT NULL AFTER `build`,
  ADD COLUMN `probe_status` VARCHAR(8)  NULL DEFAULT NULL AFTER `stuck`,
  ADD COLUMN `probe_ms`     INT(11)     NULL DEFAULT NULL AFTER `probe_status`,
  ADD COLUMN `load_id`      VARCHAR(20) NULL DEFAULT NULL AFTER `probe_ms`,
  ADD COLUMN `sent_late`    TINYINT(1)  NOT NULL DEFAULT 0 AFTER `load_id`,
  ADD UNIQUE KEY `uniq_client_perf_load_id` (`load_id`);
