-- Migration: Add missing indexes for tours table performance optimization
-- Date: 2026-01-25
-- Purpose: Improve query performance for common filtering and sorting operations
-- Database: florence_guides (dev) / u803853690_florence_guides (prod)
--
-- WHEN TO RUN THIS MIGRATION:
-- ============================================================================
-- 1. Run this migration AFTER the main database schema is already in place
-- 2. This is safe to run multiple times - IF NOT EXISTS prevents duplicate indexes
-- 3. Best to run during low-traffic periods as index creation may briefly lock the table
-- 4. Recommended for both development and production environments
--
-- HOW TO RUN:
-- - Development: Import via phpMyAdmin or MySQL command line
-- - Production: SSH to server and run via MySQL CLI, or use phpMyAdmin
--
-- EXISTING INDEXES (already in tours table):
-- - PRIMARY KEY (id)
-- - KEY guide_id (guide_id)
-- - KEY date (date)
-- - KEY booking_reference (booking_reference)
-- - idx_tours_date_guide (date, guide_id) - composite index
-- - idx_tours_booking_channel (booking_channel)
-- ============================================================================

-- Use the correct database
-- For local development:
-- USE florence_guides;
-- For production (Hostinger):
-- USE u803853690_florence_guides;

-- ============================================================================
-- ADD MISSING INDEXES FOR TOURS TABLE
-- ============================================================================

-- Index for payment_status filtering (frequently used in queries)
-- Used when filtering tours by payment status (unpaid, partial, paid)
-- Improves: Payment tracking queries, dashboard statistics
ALTER TABLE tours ADD INDEX idx_payment_status (payment_status);

-- Index for created_at (used in date range queries and sorting)
-- Used when sorting by creation date or filtering by date range
-- Improves: Recent bookings queries, audit trails, sync operations
ALTER TABLE tours ADD INDEX idx_created_at (created_at);

-- Composite index for cancelled + date (common filter combination)
-- Used when filtering active tours by date (WHERE cancelled = 0 AND date = ?)
-- Improves: Tour listing performance, calendar views, upcoming tours queries
ALTER TABLE tours ADD INDEX idx_cancelled_date (cancelled, date);

-- Index for customer_email (used in sync operations)
-- Used when looking up existing bookings during Bokun sync by customer email
-- Improves: Duplicate detection, customer lookup, booking deduplication
ALTER TABLE tours ADD INDEX idx_customer_email (customer_email);

-- ============================================================================
-- VERIFICATION QUERY
-- Run this after migration to verify indexes were created:
-- ============================================================================
-- SHOW INDEX FROM tours;
--
-- Expected output should include:
-- | Table | Key_name            | Column_name    |
-- |-------|---------------------|----------------|
-- | tours | idx_payment_status  | payment_status |
-- | tours | idx_created_at      | created_at     |
-- | tours | idx_cancelled_date  | cancelled      |
-- | tours | idx_cancelled_date  | date           |
-- | tours | idx_customer_email  | customer_email |
-- ============================================================================

-- End of migration
