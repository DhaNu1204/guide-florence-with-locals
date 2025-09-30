const mysql = require('mysql2/promise');

// Create a connection pool
const pool = mysql.createPool({
  host: 'auth-db1171.hstgr.io', // From the phpMyAdmin URL in screenshot
  user: 'u803853690_guideDhanu',
  password: 'GTIUaaN@88*522**267',
  database: 'u803853690_guide',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

// Test database connection
async function testConnection() {
  try {
    const connection = await pool.getConnection();
    console.log('Successfully connected to MySQL database!');
    connection.release();
    return true;
  } catch (error) {
    console.error('Failed to connect to the database:', error.message);
    console.error('Database connection details:', {
      host: pool.config.connectionConfig.host,
      user: pool.config.connectionConfig.user,
      database: pool.config.connectionConfig.database
    });
    return false;
  }
}

// Initialize database tables
async function initDb() {
  try {
    // First test the connection
    const connected = await testConnection();
    if (!connected) {
      console.error('Skipping database initialization due to connection failure');
      return;
    }
    
    const connection = await pool.getConnection();
    
    // Create guides table
    await connection.execute(`
      CREATE TABLE IF NOT EXISTS guides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    
    // Create tours table with paid column
    await connection.execute(`
      CREATE TABLE IF NOT EXISTS tours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(100) NOT NULL,
        duration VARCHAR(50),
        description TEXT,
        date DATE NOT NULL,
        time TIME NOT NULL,
        guide_id INT NOT NULL,
        paid BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guide_id) REFERENCES guides(id) ON DELETE CASCADE
      )
    `);
    
    // Check if paid column exists, if not add it
    try {
      await connection.execute(`
        SELECT paid FROM tours LIMIT 1
      `);
      console.log('Paid column exists in tours table');
    } catch (e) {
      if (e.message.includes('Unknown column')) {
        console.log('Adding paid column to tours table');
        await connection.execute(`
          ALTER TABLE tours ADD COLUMN paid BOOLEAN DEFAULT FALSE
        `);
      } else {
        throw e;
      }
    }
    
    console.log('Database tables initialized successfully');
    connection.release();
  } catch (error) {
    console.error('Error initializing database:', error);
  }
}

module.exports = { pool, initDb, testConnection }; 