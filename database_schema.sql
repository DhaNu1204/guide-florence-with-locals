-- Florence with Locals - Complete Database Schema
-- Run this SQL script in your Hostinger phpMyAdmin to create all tables

-- Use the database
USE u803853690_withlocals;

-- 1. Users table for authentication
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','viewer') NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Guides table for tour guide information
CREATE TABLE IF NOT EXISTS `guides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `languages` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tours table for tour bookings
CREATE TABLE IF NOT EXISTS `tours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `guide_id` int(11) DEFAULT NULL,
  `guide_name` varchar(100) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `participants` int(11) DEFAULT 1,
  `price` decimal(10,2) DEFAULT NULL,
  `paid` tinyint(1) DEFAULT 0,
  `payment_status` enum('unpaid','partial','paid') DEFAULT 'unpaid',
  `payment_method` varchar(50) DEFAULT NULL,
  `cancelled` tinyint(1) DEFAULT 0,
  `cancellation_reason` text DEFAULT NULL,
  `booking_channel` varchar(100) DEFAULT NULL,
  `booking_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `guide_id` (`guide_id`),
  KEY `date` (`date`),
  KEY `booking_reference` (`booking_reference`),
  CONSTRAINT `tours_ibfk_1` FOREIGN KEY (`guide_id`) REFERENCES `guides` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tickets table for museum ticket inventory
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `museum` varchar(100) NOT NULL,
  `ticket_type` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('available','reserved','used','expired') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `museum` (`museum`),
  KEY `date` (`date`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Payment transactions table
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tour_id` int(11) DEFAULT NULL,
  `guide_id` int(11) DEFAULT NULL,
  `guide_name` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `payment_time` time DEFAULT NULL,
  `description` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tour_id` (`tour_id`),
  KEY `guide_id` (`guide_id`),
  KEY `payment_date` (`payment_date`),
  KEY `status` (`status`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`guide_id`) REFERENCES `guides` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Sessions table for user session management
CREATE TABLE IF NOT EXISTS `sessions` (
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_data` text DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Bokun configuration table
CREATE TABLE IF NOT EXISTS `bokun_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` varchar(50) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `api_base_url` varchar(255) NOT NULL DEFAULT 'https://api.bokun.is',
  `booking_channel` varchar(100) DEFAULT NULL,
  `last_sync` timestamp NULL DEFAULT NULL,
  `sync_enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('dhanu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@florencewithlocals.com', 'admin'),
('Sudeshshiwanka25@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sudeshshiwanka25@gmail.com', 'viewer')
ON DUPLICATE KEY UPDATE username=username;

-- Insert sample guides
INSERT INTO `guides` (`name`, `email`, `phone`, `languages`) VALUES
('Sofia Romano', 'sofia@florenceguides.com', '+39 123 456 7890', 'Italian, English, French'),
('Marco Benedetti', 'marco@florenceguides.com', '+39 123 456 7891', 'Italian, English, Spanish'),
('Elena Rossi', 'elena@florenceguides.com', '+39 123 456 7892', 'Italian, English, German')
ON DUPLICATE KEY UPDATE name=name;

-- Insert sample museum tickets
INSERT INTO `tickets` (`museum`, `ticket_type`, `date`, `time`, `quantity`, `price`, `status`) VALUES
('Uffizi Gallery', 'Priority Entrance Tickets', '2025-10-15', '09:00:00', 20, 25.00, 'available'),
('Accademia Gallery', 'Skip the Line Entry Ticket', '2025-10-15', '10:30:00', 15, 18.00, 'available'),
('Uffizi Gallery', 'Priority Entrance Tickets', '2025-10-16', '14:00:00', 25, 25.00, 'available')
ON DUPLICATE KEY UPDATE museum=museum;

-- Insert Bokun configuration
INSERT INTO `bokun_config` (`vendor_id`, `api_key`, `api_base_url`, `booking_channel`, `sync_enabled`) VALUES
('96929', '2c413c402bd9402092b4a3f5157c899e', 'https://api.bokun.is', 'www.florencewithlocals.com', 1)
ON DUPLICATE KEY UPDATE vendor_id=vendor_id;

-- Create indexes for better performance
CREATE INDEX idx_tours_date_guide ON tours(date, guide_id);
CREATE INDEX idx_payments_date_guide ON payments(payment_date, guide_id);
CREATE INDEX idx_tickets_museum_date ON tickets(museum, date);
CREATE INDEX idx_tours_booking_channel ON tours(booking_channel);

-- Update table AUTO_INCREMENT values to start from a higher number
ALTER TABLE users AUTO_INCREMENT = 1;
ALTER TABLE guides AUTO_INCREMENT = 1;
ALTER TABLE tours AUTO_INCREMENT = 1;
ALTER TABLE tickets AUTO_INCREMENT = 1;
ALTER TABLE payments AUTO_INCREMENT = 1;
ALTER TABLE bokun_config AUTO_INCREMENT = 1;