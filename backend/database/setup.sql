-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS florence_guides_tours;

-- Use the database
USE florence_guides_tours;

-- Create guides table
CREATE TABLE IF NOT EXISTS guides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  phone VARCHAR(20),
  languages VARCHAR(255),
  bio TEXT,
  photo_url VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create tours table
CREATE TABLE IF NOT EXISTS tours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  duration VARCHAR(50),
  description TEXT,
  date DATE NOT NULL,
  time VARCHAR(10) NOT NULL,
  guide_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE
);

-- Insert sample guides
INSERT INTO guides (name, email, phone, languages, bio, photo_url) VALUES
('Lucia Bianchi', 'lucia.bianchi@example.com', '+39 123 456 7890', 'Italian, English, French', 'Art historian with 10 years of experience in Florence museums.', '/images/guides/lucia.jpg'),
('Marco Rossi', 'marco.rossi@example.com', '+39 123 456 7891', 'Italian, English, Spanish', 'Expert in Renaissance art and architecture.', '/images/guides/marco.jpg'),
('Sofia Esposito', 'sofia.esposito@example.com', '+39 123 456 7892', 'Italian, English, German', 'Specialized in Medici family history and cultural impact.', '/images/guides/sofia.jpg');

-- Insert sample tours (will only work after guides are inserted with correct IDs)
INSERT INTO tours (title, duration, description, date, time, guide_id) VALUES
('David and Accademia Gallery VIP Tour in Florence', '2 hours', 'Exclusive tour of the Accademia Gallery featuring Michelangelo\'s David.', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00', 1),
('Private Tour-Pitti Palace & Palatina Gallery, Boboli Gardens Tkts', '3 hours', 'Explore the magnificent Pitti Palace and beautiful Boboli Gardens.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:30', 2),
('Private Tour in Bargello Museum', '2 hours', 'Discover the incredible sculpture collection at the Bargello Museum.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '14:00', 1),
('Uffizi Gallery VIP Entrance and Private Guided Tour', '2.5 hours', 'Skip the line and enjoy a private tour of the Uffizi Gallery\'s masterpieces.', DATE_ADD(CURDATE(), INTERVAL 3 DAY), '11:00', 3); 