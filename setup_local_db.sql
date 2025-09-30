-- Florence Guides Local Database Setup
USE florence_guides;

-- Create users table for authentication
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create sessions table for authentication
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create guides table
CREATE TABLE IF NOT EXISTS guides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    languages VARCHAR(255),
    bio TEXT,
    photo_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create tours table with Bokun integration fields
CREATE TABLE IF NOT EXISTS tours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    duration VARCHAR(50),
    description TEXT,
    date DATE NOT NULL,
    time VARCHAR(10) NOT NULL,
    guide_id INT NOT NULL,
    booking_channel VARCHAR(100) DEFAULT 'Website',
    paid TINYINT DEFAULT 0,
    cancelled TINYINT DEFAULT 0,
    -- Bokun integration fields
    external_id VARCHAR(255),
    external_source VARCHAR(50),
    bokun_booking_id VARCHAR(255),
    bokun_experience_id VARCHAR(255),
    bokun_confirmation_code VARCHAR(100),
    participants INT,
    customer_name VARCHAR(255),
    customer_email VARCHAR(255),
    customer_phone VARCHAR(50),
    needs_guide_assignment TINYINT DEFAULT 0,
    bokun_data JSON,
    last_synced TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE
);

-- Create tickets table
CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(255) NOT NULL,
    code VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Bokun integration tables
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

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_tours_date ON tours(date);
CREATE INDEX IF NOT EXISTS idx_tours_guide ON tours(guide_id);
CREATE INDEX IF NOT EXISTS idx_bokun_booking_id ON tours(bokun_booking_id);
CREATE INDEX IF NOT EXISTS idx_external_id ON tours(external_id);
CREATE INDEX IF NOT EXISTS idx_needs_guide ON tours(needs_guide_assignment);

-- Insert default users
INSERT IGNORE INTO users (email, password, role) VALUES 
('dhanu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Sudeshshiwanka25@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'viewer');

-- Insert sample guides
INSERT IGNORE INTO guides (name, email, phone, languages, bio) VALUES
('Lucia Bianchi', 'lucia.bianchi@example.com', '+39 123 456 7890', 'Italian, English, French', 'Art historian with 10 years of experience in Florence museums.'),
('Marco Rossi', 'marco.rossi@example.com', '+39 123 456 7891', 'Italian, English, Spanish', 'Expert in Renaissance art and architecture.'),
('Sofia Esposito', 'sofia.esposito@example.com', '+39 123 456 7892', 'Italian, English, German', 'Specialized in Medici family history and cultural impact.');

-- Insert sample tours
INSERT IGNORE INTO tours (title, duration, description, date, time, guide_id, booking_channel) VALUES
('David and Accademia Gallery VIP Tour in Florence', '2 hours', 'Exclusive tour of the Accademia Gallery featuring Michelangelos David.', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00', 1, 'Website'),
('Private Tour-Pitti Palace & Palatina Gallery, Boboli Gardens', '3 hours', 'Explore the magnificent Pitti Palace and beautiful Boboli Gardens.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:30', 2, 'Viator'),
('Private Tour in Bargello Museum', '2 hours', 'Discover the incredible sculpture collection at the Bargello Museum.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '14:00', 1, 'Get your guide'),
('Uffizi Gallery VIP Entrance and Private Guided Tour', '2.5 hours', 'Skip the line and enjoy a private tour of the Uffizi Gallerys masterpieces.', DATE_ADD(CURDATE(), INTERVAL 3 DAY), '11:00', 3, 'Air BNB');

-- Insert sample tickets
INSERT IGNORE INTO tickets (location, code, date, quantity) VALUES
('Accademia', 'ACC001', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 10),
('Uffizi', 'UFF001', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 15),
('Accademia', 'ACC002', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 8),
('Uffizi', 'UFF002', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 12);