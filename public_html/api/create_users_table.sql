-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert admin user (password: ***REMOVED***)
INSERT INTO users (email, password, role) VALUES 
('dhanu', '***REMOVED***', 'admin');

-- Insert viewer user (password: ***REMOVED***)
INSERT INTO users (email, password, role) VALUES 
('***REMOVED***', '***REMOVED***', 'viewer'); 