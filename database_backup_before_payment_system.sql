-- Database Backup Script - Before Payment System Implementation
-- Date: 2025-09-19
-- Purpose: Backup current database structure before implementing payment tracking system

-- Backup existing tours table structure
CREATE TABLE IF NOT EXISTS tours_backup_20250919 AS SELECT * FROM tours;

-- Document current schema for tours table
-- Current tours table has these relevant fields:
-- - id (Primary Key)
-- - title, duration, description, date, time
-- - guide_id (Foreign Key to guides table)
-- - paid (TINYINT DEFAULT 0) - existing basic payment status
-- - cancelled (TINYINT DEFAULT 0)
-- - booking_channel, customer details, etc.

-- This backup preserves all current tour data before we enhance the payment system
-- The enhanced payment system will add:
-- 1. payment_transactions table for detailed payment tracking
-- 2. Enhanced payment_status field to replace simple 'paid' field
-- 3. Support for multiple payment methods and amounts per tour

SELECT 'Database backup completed successfully' as status;