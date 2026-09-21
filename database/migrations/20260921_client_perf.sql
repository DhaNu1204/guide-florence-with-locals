-- Step 4.7: field instrumentation for the mobile investigation (Phase 4, Part 2A).
--
-- One row per page load, posted by navigator.sendBeacon at the end of the load. Timings are
-- milliseconds from navigation start. Statuses are ok | failed | pending | none, where
-- `pending` means the request had started and had still not finished when the row was sent -
-- that is the case the whole step exists to catch.
--
-- Nothing identifying is stored: no tour data, no customer data, no names, no URLs beyond a
-- route name with its ids stripped, no free text. `user_id` only, so a row can be attributed
-- to the owner or to a guide without holding anything about the person.
--
-- Retention: rows older than 60 days are deleted opportunistically by client_perf.php.
--
-- Run on: staging 2026-09-21, production 2026-09-21.

CREATE TABLE IF NOT EXISTS `client_perf` (
  `id`                  INT(11) NOT NULL AUTO_INCREMENT,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`             INT(11) NOT NULL,
  `release_tag`         VARCHAR(32) NULL DEFAULT NULL,
  `route`               VARCHAR(40) NULL DEFAULT NULL,
  `reason`              VARCHAR(12) NOT NULL DEFAULT 'complete',
  `entry_at`            INT(11) NULL DEFAULT NULL,
  `verify_start`        INT(11) NULL DEFAULT NULL,
  `verify_end`          INT(11) NULL DEFAULT NULL,
  `verify_status`       VARCHAR(8) NOT NULL DEFAULT 'none',
  `chunk_start`         INT(11) NULL DEFAULT NULL,
  `chunk_end`           INT(11) NULL DEFAULT NULL,
  `chunk_status`        VARCHAR(8) NOT NULL DEFAULT 'none',
  `list_start`          INT(11) NULL DEFAULT NULL,
  `list_end`            INT(11) NULL DEFAULT NULL,
  `list_status`         VARCHAR(8) NOT NULL DEFAULT 'none',
  `rate_limited`        SMALLINT(6) NOT NULL DEFAULT 0,
  `online`              TINYINT(1) NOT NULL DEFAULT 1,
  `sw_controlled`       TINYINT(1) NOT NULL DEFAULT 0,
  `first_after_release` TINYINT(1) NOT NULL DEFAULT 0,
  `effective_type`      VARCHAR(12) NULL DEFAULT NULL,
  `conn_rtt`            INT(11) NULL DEFAULT NULL,
  `conn_downlink`       DECIMAL(6,2) NULL DEFAULT NULL,
  `device`              VARCHAR(40) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_client_perf_created_at` (`created_at`),
  KEY `idx_client_perf_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
