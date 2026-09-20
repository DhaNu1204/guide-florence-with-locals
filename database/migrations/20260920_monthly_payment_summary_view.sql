-- Step 3.8: the second payment view gets the same safety net as guide_payment_summary.
--
-- In step 4.1a both payment views had vanished from production (most likely an hPanel database
-- operation between 2026-09-10 and 2026-09-17, never proven) and the Payments page answered 500
-- for days. guide-payments.php got a self-healing guard then; payment-reports.php did not, and
-- that was written down as outstanding. This file is that view's definition, and
-- payment-reports.php recreates it automatically on MySQL error 1146.
--
-- Definition captured from the view running on production on 2026-09-20 (SHOW CREATE VIEW),
-- with the DEFINER clause dropped so it is recreated as whoever runs it.
-- A view only: no table and no row is touched. Safe to re-run.

CREATE OR REPLACE VIEW monthly_payment_summary AS
SELECT
    YEAR(p.payment_date)  AS payment_year,
    MONTH(p.payment_date) AS payment_month,
    MONTHNAME(p.payment_date) AS month_name,
    g.id   AS guide_id,
    g.name AS guide_name,
    COUNT(DISTINCT p.tour_id) AS tours_paid,
    COUNT(p.id) AS payment_transactions,
    SUM(p.amount) AS total_amount,
    SUM(CASE WHEN p.payment_method = 'cash' OR p.payment_method = 'Cash' THEN p.amount ELSE 0 END) AS cash_amount,
    SUM(CASE WHEN p.payment_method = 'bank_transfer' OR p.payment_method = 'Bank Transfer' THEN p.amount ELSE 0 END) AS bank_amount,
    p.payment_method AS payment_method,
    AVG(p.amount) AS avg_payment_amount
FROM payments p
JOIN guides g ON p.guide_id = g.id
GROUP BY YEAR(p.payment_date), MONTH(p.payment_date), g.id, p.payment_method
ORDER BY YEAR(p.payment_date) DESC, MONTH(p.payment_date) DESC, g.name;
