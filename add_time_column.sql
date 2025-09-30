-- Add time column to tickets table
-- Run this first in your database

ALTER TABLE tickets ADD COLUMN time TIME DEFAULT NULL AFTER date;

-- Check the table structure
DESCRIBE tickets; 