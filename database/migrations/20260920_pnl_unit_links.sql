-- Step 6.2: two departures that physically run together under ONE guide, linked by hand so the
-- Daily P&L costs the guide once.
--
-- The owner sometimes takes an Uffizi tour and an Uffizi+Accademia tour out at the same time with
-- a single guide. Everything else stays per person: tickets follow each product, radios follow
-- each person, revenue stays with the booking that produced it.
--
-- This is a P&L-ONLY concept. Auto-grouping still refuses to mix products (that rule stands), and
-- nothing here touches tours, tour_groups, payments, guide_reminders or the evening digest.
-- Links are never created automatically.
--
-- Shape: one row per member departure. Rows sharing link_key are one costing unit, and that
-- link_key ('m<id>') doubles as the merged unit's key in pnl_tour_costs, so an override can be
-- set on the merged row itself. tour_unit is UNIQUE: a departure belongs to at most one link.
-- bucket_key is carried beside tour_unit for the same reason pnl_tour_costs carries it since
-- step 3.7 - the natural key of a departure survives even if a surrogate id ever moves.
--
-- pnl.php self-provisions the same table (pnlEnsureTables), so this file is for a fresh database
-- or a manual run. Safe to re-run.

CREATE TABLE IF NOT EXISTS pnl_unit_links (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    link_key    VARCHAR(40) NOT NULL,
    link_date   DATE NOT NULL,
    tour_unit   VARCHAR(24) NOT NULL UNIQUE,
    bucket_key  VARCHAR(64) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by  INT NULL,
    KEY idx_pnl_links_key (link_key),
    KEY idx_pnl_links_date (link_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
