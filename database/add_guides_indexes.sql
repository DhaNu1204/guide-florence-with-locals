-- Add indexes to guides table for improved query performance
-- Run this migration on the production database

-- Index on email column for faster lookups when authenticating or searching by email
ALTER TABLE guides ADD INDEX idx_email (email);

-- Index on name column for faster sorting and searching by guide name
ALTER TABLE guides ADD INDEX idx_name (name);
