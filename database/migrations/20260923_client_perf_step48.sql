-- Step 4.8: the field recorder learns how a bad load RESOLVED (Phase 4, Part 2B).
--
-- Step 4.7 could only say that a request was still `pending`. Now that every request has a
-- timeout and reads are retried once, each row also records:
--   verify_error    why the auth check failed: 'network', 'timeout' or 'http:<status>' (NULL = it did not)
--   verify_retry    the automatic re-check after an unknown answer: ok | failed | pending | none
--   timeouts        how many request timers fired during the load
--   auto_retries    how many reads were retried automatically, and how many of those worked
--   auto_retry_ok
--   user_retries    how many times a Retry button was pressed
--   shell_fallback  1 = the service worker started the page from its cached index.html because
--                   the network did not answer the page load in time
--
-- Still nothing identifying: counters and short fixed codes only.
--
-- client_perf.php self-provisions the same columns (clientPerfEnsureColumns) on its first write.
-- Run on: staging 2026-09-23, production 2026-09-23 (via the self-provision).

ALTER TABLE `client_perf`
  ADD COLUMN `verify_error`   VARCHAR(16) NULL DEFAULT NULL AFTER `verify_status`,
  ADD COLUMN `verify_retry`   VARCHAR(8)  NOT NULL DEFAULT 'none' AFTER `verify_error`,
  ADD COLUMN `timeouts`       SMALLINT(6) NOT NULL DEFAULT 0 AFTER `rate_limited`,
  ADD COLUMN `auto_retries`   SMALLINT(6) NOT NULL DEFAULT 0 AFTER `timeouts`,
  ADD COLUMN `auto_retry_ok`  SMALLINT(6) NOT NULL DEFAULT 0 AFTER `auto_retries`,
  ADD COLUMN `user_retries`   SMALLINT(6) NOT NULL DEFAULT 0 AFTER `auto_retry_ok`,
  ADD COLUMN `shell_fallback` TINYINT(1)  NOT NULL DEFAULT 0 AFTER `sw_controlled`;
