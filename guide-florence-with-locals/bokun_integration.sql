-- Add Bokun integration fields to tours table
ALTER TABLE tours 
ADD COLUMN IF NOT EXISTS external_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS external_source VARCHAR(50),
ADD COLUMN IF NOT EXISTS bokun_booking_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS bokun_experience_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS bokun_confirmation_code VARCHAR(100),
ADD COLUMN IF NOT EXISTS participants INT,
ADD COLUMN IF NOT EXISTS customer_name VARCHAR(255),
ADD COLUMN IF NOT EXISTS customer_email VARCHAR(255),
ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(50),
ADD COLUMN IF NOT EXISTS needs_guide_assignment TINYINT DEFAULT 0,
ADD COLUMN IF NOT EXISTS bokun_data JSON,
ADD COLUMN IF NOT EXISTS last_synced TIMESTAMP NULL;

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_bokun_booking_id ON tours(bokun_booking_id);
CREATE INDEX IF NOT EXISTS idx_external_id ON tours(external_id);
CREATE INDEX IF NOT EXISTS idx_needs_guide ON tours(needs_guide_assignment);

-- Create Bokun sync configuration table
CREATE TABLE IF NOT EXISTS bokun_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  access_key VARCHAR(255),
  secret_key VARCHAR(255),
  vendor_id VARCHAR(100),
  webhook_secret VARCHAR(255),
  sync_enabled TINYINT DEFAULT 1,
  auto_assign_guides TINYINT DEFAULT 0,
  last_sync_date TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create Bokun webhook logs table
CREATE TABLE IF NOT EXISTS bokun_webhook_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic VARCHAR(100),
  booking_id VARCHAR(255),
  experience_booking_id VARCHAR(255),
  payload JSON,
  processed TINYINT DEFAULT 0,
  error_message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create tour-to-bokun mapping table
CREATE TABLE IF NOT EXISTS bokun_tour_mapping (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bokun_product_id VARCHAR(255),
  bokun_product_name VARCHAR(500),
  local_tour_title VARCHAR(500),
  default_guide_id INT,
  auto_assign_rule JSON,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (default_guide_id) REFERENCES guides(id) ON DELETE SET NULL
);

-- Create guide availability table for auto-assignment
CREATE TABLE IF NOT EXISTS guide_availability (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guide_id INT NOT NULL,
  date DATE NOT NULL,
  time_slot VARCHAR(20),
  available TINYINT DEFAULT 1,
  max_tours INT DEFAULT 2,
  assigned_tours INT DEFAULT 0,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE,
  UNIQUE KEY unique_guide_date_time (guide_id, date, time_slot)
);