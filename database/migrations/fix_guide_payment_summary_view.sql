-- Fix guide_payment_summary VIEW to reference correct table (payments, not payment_transactions)
-- This migration addresses the table mismatch bug where the VIEW was querying an empty
-- payment_transactions table while the API writes to the payments table.
--
-- Issue: guide_payment_summary VIEW showed €0.00 for all totals because it was
-- LEFT JOINing to payment_transactions (which doesn't exist or is empty)
-- instead of the payments table where actual payment records are stored.
--
-- Created: 2026-01-29

DROP VIEW IF EXISTS guide_payment_summary;

CREATE VIEW guide_payment_summary AS
SELECT
    g.id as guide_id,
    g.name as guide_name,
    g.email as guide_email,
    COUNT(DISTINCT t.id) as total_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_tours,
    COALESCE(SUM(p.amount), 0) as total_payments_received,
    COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END), 0) as cash_payments,
    COALESCE(SUM(CASE WHEN p.payment_method = 'bank_transfer' THEN p.amount ELSE 0 END), 0) as bank_payments,
    COUNT(DISTINCT p.id) as total_payment_transactions,
    MIN(p.payment_date) as first_payment_date,
    MAX(p.payment_date) as last_payment_date
FROM guides g
LEFT JOIN tours t ON g.id = t.guide_id
LEFT JOIN payments p ON t.id = p.tour_id  -- FIXED: was payment_transactions
GROUP BY g.id, g.name, g.email;

-- Verification query (run after migration to confirm fix):
-- SHOW CREATE VIEW guide_payment_summary;
-- Expected: Should show "LEFT JOIN payments p ON t.id = p.tour_id"
--
-- Test query:
-- SELECT guide_name, total_payments_received FROM guide_payment_summary;
-- Expected: Should show actual payment amounts (not €0.00)
