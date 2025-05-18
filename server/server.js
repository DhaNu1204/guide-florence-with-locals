const express = require('express');
const cors = require('cors');
const { pool, initDb, testConnection } = require('./db.js');

const app = express();
const PORT = process.env.PORT || 5000;

// Configure CORS with more specific options
app.use(cors({
  origin: ['http://localhost:5173', 'http://127.0.0.1:5173'],
  methods: ['GET', 'POST', 'PUT', 'DELETE'],
  allowedHeaders: ['Content-Type', 'Authorization'],
  credentials: true
}));

app.use(express.json());

// Add response logging middleware
app.use((req, res, next) => {
  console.log(`${new Date().toISOString()} - ${req.method} ${req.url}`);
  
  // Capture the original send method
  const originalSend = res.send;
  
  // Override the send method
  res.send = function(body) {
    console.log(`Response: ${res.statusCode} - ${typeof body === 'object' ? JSON.stringify(body) : body.substring(0, 100)}`);
    return originalSend.call(this, body);
  };
  
  next();
});

// Test database connection on startup
testConnection()
  .then(connected => {
    if (connected) {
      console.log('Database connection successful, initializing tables...');
      // Initialize database
      initDb();
    } else {
      console.error('WARNING: Server starting with database connection issues!');
    }
  })
  .catch(err => {
    console.error('Error testing database connection:', err);
  });

// API Routes

// Health check endpoint
app.get('/api/health', (req, res) => {
  res.json({ status: 'OK', message: 'Server is running' });
});

// Database check endpoint
app.get('/api/dbcheck', async (req, res) => {
  try {
    const connected = await testConnection();
    if (connected) {
      res.json({ status: 'OK', message: 'Database connection successful' });
    } else {
      res.status(500).json({ status: 'ERROR', message: 'Database connection failed' });
    }
  } catch (error) {
    res.status(500).json({ status: 'ERROR', message: error.message });
  }
});

// Guides
app.get('/api/guides', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM guides ORDER BY name ASC');
    res.json(rows);
  } catch (error) {
    console.error('Error fetching guides:', error);
    res.status(500).json({ message: 'Server error' });
  }
});

app.post('/api/guides', async (req, res) => {
  try {
    const { name, phone } = req.body;
    if (!name || !phone) {
      return res.status(400).json({ message: 'Name and phone are required' });
    }
    
    console.log('Attempting to create guide:', { name, phone });
    
    const [result] = await pool.execute(
      'INSERT INTO guides (name, phone) VALUES (?, ?)',
      [name, phone]
    );
    
    console.log('Guide created successfully, ID:', result.insertId);
    
    const [newGuide] = await pool.query('SELECT * FROM guides WHERE id = ?', [result.insertId]);
    res.status(201).json(newGuide[0]);
  } catch (error) {
    console.error('Error creating guide:', error.message);
    console.error('Error details:', error);
    res.status(500).json({ 
      message: 'Server error creating guide', 
      details: error.message 
    });
  }
});

// Tours
app.get('/api/tours', async (req, res) => {
  try {
    const [rows] = await pool.query(`
      SELECT t.*, g.name as guide_name 
      FROM tours t 
      JOIN guides g ON t.guide_id = g.id
      ORDER BY t.date ASC, t.time ASC
    `);
    res.json(rows);
  } catch (error) {
    console.error('Error fetching tours:', error);
    res.status(500).json({ message: 'Server error' });
  }
});

app.post('/api/tours', async (req, res) => {
  try {
    const { title, duration, description, date, time, guideId, paid } = req.body;
    if (!title || !date || !time || !guideId) {
      return res.status(400).json({ message: 'Title, date, time and guide ID are required' });
    }
    
    // Check if guide exists
    const [guides] = await pool.query('SELECT id FROM guides WHERE id = ?', [guideId]);
    if (guides.length === 0) {
      return res.status(404).json({ message: 'Guide not found' });
    }
    
    const [result] = await pool.execute(
      'INSERT INTO tours (title, duration, description, date, time, guide_id, paid) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [title, duration || '2 hours', description || '', date, time, guideId, paid || false]
    );
    
    const [newTour] = await pool.query(`
      SELECT t.*, g.name as guide_name 
      FROM tours t 
      JOIN guides g ON t.guide_id = g.id 
      WHERE t.id = ?
    `, [result.insertId]);
    
    res.status(201).json(newTour[0]);
  } catch (error) {
    console.error('Error creating tour:', error);
    res.status(500).json({ message: 'Server error' });
  }
});

// Add endpoint to update tour paid status
app.put('/api/tours/:id/paid', async (req, res) => {
  try {
    const { id } = req.params;
    const { paid } = req.body;
    
    if (paid === undefined) {
      return res.status(400).json({ message: 'Paid status is required' });
    }
    
    // Update the paid status
    const [result] = await pool.execute(
      'UPDATE tours SET paid = ? WHERE id = ?',
      [paid, id]
    );
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ message: 'Tour not found' });
    }
    
    const [updatedTour] = await pool.query(`
      SELECT t.*, g.name as guide_name 
      FROM tours t 
      JOIN guides g ON t.guide_id = g.id 
      WHERE t.id = ?
    `, [id]);
    
    res.json(updatedTour[0]);
  } catch (error) {
    console.error('Error updating tour paid status:', error);
    res.status(500).json({ message: 'Server error' });
  }
});

// Delete tour endpoint
app.delete('/api/tours/:id', async (req, res) => {
  try {
    const { id } = req.params;
    
    const [result] = await pool.execute('DELETE FROM tours WHERE id = ?', [id]);
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ message: 'Tour not found' });
    }
    
    res.json({ message: 'Tour deleted successfully' });
  } catch (error) {
    console.error('Error deleting tour:', error);
    res.status(500).json({ message: 'Server error' });
  }
});

// Start server
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});

module.exports = app; 