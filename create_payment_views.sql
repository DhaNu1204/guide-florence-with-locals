-- ================================================================
-- Create Payment System Views for Production
-- Date: October 26, 2025
-- Purpose: Fix Payments page error by creating missing database views
-- ================================================================

-- Drop existing views if they exist (to allow re-running script)
DROP VIEW IF EXISTS guide_payment_summary;
DROP VIEW IF EXISTS monthly_payment_summary;

-- ================================================================
-- Create guide_payment_summary view
-- Provides quick access to guide payment totals and statistics
-- ================================================================
CREATE VIEW guide_payment_summary AS
SELECT
    g.id as guide_id,
    g.name as guide_name,
    g.email as guide_email,
    COUNT(DISTINCT t.id) as total_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_tours,
    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_tours,
    COALESCE(SUM(pt.amount), 0) as total_payments_received,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END), 0) as cash_payments,
    COALESCE(SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END), 0) as bank_payments,
    COUNT(DISTINCT pt.id) as total_payment_transactions,
    MIN(pt.payment_date) as first_payment_date,
    MAX(pt.payment_date) as last_payment_date
FROM guides g
LEFT JOIN tours t ON g.id = t.guide_id
LEFT JOIN payments pt ON t.id = pt.tour_id
GROUP BY g.id, g.name, g.email;

-- ================================================================
-- Create monthly_payment_summary view
-- Provides monthly breakdown of payments for reporting
-- ================================================================
CREATE VIEW monthly_payment_summary AS
SELECT
    YEAR(pt.payment_date) as payment_year,
    MONTH(pt.payment_date) as payment_month,
    MONTHNAME(pt.payment_date) as month_name,
    g.id as guide_id,
    g.name as guide_name,
    COUNT(DISTINCT pt.tour_id) as tours_paid,
    COUNT(pt.id) as payment_transactions,
    SUM(pt.amount) as total_amount,
    SUM(CASE WHEN pt.payment_method = 'cash' THEN pt.amount ELSE 0 END) as cash_amount,
    SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN pt.amount ELSE 0 END) as bank_amount,
    pt.payment_method,
    AVG(pt.amount) as avg_payment_amount
FROM payments pt
JOIN guides g ON pt.guide_id = g.id
GROUP BY YEAR(pt.payment_date), MONTH(pt.payment_date), g.id, pt.payment_method
ORDER BY payment_year DESC, payment_month DESC, g.name;

-- ================================================================
-- Verification Queries
-- ================================================================

SELECT 'Views created successfully!' as status;

-- Show sample data from guide_payment_summary
SELECT 'Sample guide_payment_summary data:' as info;
SELECT * FROM guide_payment_summary LIMIT 5;

-- Verify view structure
SELECT 'Verifying guide_payment_summary structure:' as info;
DESCRIBE guide_payment_summary;
