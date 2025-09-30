const express = require('express');
const mysql = require('mysql2/promise');
const cors = require('cors');
const dotenv = require('dotenv');
const bodyParser = require('body-parser');

// Load environment variables
dotenv.config();

const app = express();

// Middleware
app.use(cors());
app.use(bodyParser.json());

// Database connection configuration
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'florence_guides_tours',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
};

// Create database connection pool
const pool = mysql.createPool(dbConfig);

// Test database connection
app.get('/api/test', async (req, res) => {
  try {
    const connection = await pool.getConnection();
    connection.release();
    res.json({ message: 'Database connection successful' });
  } catch (error) {
    console.error('Database connection error:', error);
    res.status(500).json({ error: 'Database connection failed' });
  }
});

// GUIDE ENDPOINTS

// Get all guides
app.get('/api/guides', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM guides');
    res.json(rows);
  } catch (error) {
    console.error('Error fetching guides:', error);
    res.status(500).json({ error: 'Failed to fetch guides' });
  }
});

// Add a new guide
app.post('/api/guides', async (req, res) => {
  try {
    const { name, email, phone, languages, bio, photo_url } = req.body;
    
    const [result] = await pool.query(
      'INSERT INTO guides (name, email, phone, languages, bio, photo_url) VALUES (?, ?, ?, ?, ?, ?)',
      [name, email, phone, languages, bio, photo_url]
    );
    
    const newGuide = {
      id: result.insertId,
      name,
      email,
      phone,
      languages,
      bio,
      photo_url
    };
    
    res.status(201).json(newGuide);
  } catch (error) {
    console.error('Error adding guide:', error);
    res.status(500).json({ error: 'Failed to add guide' });
  }
});

// Delete a guide
app.delete('/api/guides/:id', async (req, res) => {
  try {
    const { id } = req.params;
    
    const [result] = await pool.query('DELETE FROM guides WHERE id = ?', [id]);
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Guide not found' });
    }
    
    res.json({ message: 'Guide deleted successfully' });
  } catch (error) {
    console.error('Error deleting guide:', error);
    res.status(500).json({ error: 'Failed to delete guide' });
  }
});

// TOUR ENDPOINTS

// Get all tours
app.get('/api/tours', async (req, res) => {
  try {
    const [rows] = await pool.query(`
      SELECT t.*, g.name as guide_name 
      FROM tours t 
      JOIN guides g ON t.guide_id = g.id
    `);
    res.json(rows);
  } catch (error) {
    console.error('Error fetching tours:', error);
    res.status(500).json({ error: 'Failed to fetch tours' });
  }
});

// Add a new tour
app.post('/api/tours', async (req, res) => {
  try {
    const { title, duration, description, date, time, guideId } = req.body;
    
    const [result] = await pool.query(
      'INSERT INTO tours (title, duration, description, date, time, guide_id) VALUES (?, ?, ?, ?, ?, ?)',
      [title, duration, description, date, time, guideId]
    );
    
    // Get the guide name for the response
    const [guides] = await pool.query('SELECT name FROM guides WHERE id = ?', [guideId]);
    
    const newTour = {
      id: result.insertId,
      title,
      duration,
      description,
      date,
      time,
      guide_id: guideId,
      guide_name: guides[0].name
    };
    
    res.status(201).json(newTour);
  } catch (error) {
    console.error('Error adding tour:', error);
    res.status(500).json({ error: 'Failed to add tour' });
  }
});

// Delete a tour
app.delete('/api/tours/:id', async (req, res) => {
  try {
    const { id } = req.params;
    
    const [result] = await pool.query('DELETE FROM tours WHERE id = ?', [id]);
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Tour not found' });
    }
    
    res.json({ message: 'Tour deleted successfully' });
  } catch (error) {
    console.error('Error deleting tour:', error);
    res.status(500).json({ error: 'Failed to delete tour' });
  }
});

// Update a tour
app.put('/api/tours/:id', async (req, res) => {
  try {
    const { id } = req.params;
    const { title, duration, description, date, time, guide_id } = req.body;
    
    const [result] = await pool.query(
      'UPDATE tours SET title = ?, duration = ?, description = ?, date = ?, time = ?, guide_id = ? WHERE id = ?',
      [title, duration, description, date, time, guide_id, id]
    );
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Tour not found' });
    }
    
    // Get the updated tour with guide name
    const [tours] = await pool.query(`
      SELECT t.*, g.name as guide_name 
      FROM tours t 
      JOIN guides g ON t.guide_id = g.id
      WHERE t.id = ?
    `, [id]);
    
    res.json(tours[0]);
  } catch (error) {
    console.error('Error updating tour:', error);
    res.status(500).json({ error: 'Failed to update tour' });
  }
});

// Update tour paid status
app.put('/api/tours/:id/paid', async (req, res) => {
  try {
    const { id } = req.params;
    const { paid } = req.body;
    
    const [result] = await pool.query(
      'UPDATE tours SET paid = ? WHERE id = ?',
      [paid ? 1 : 0, id]
    );
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Tour not found' });
    }
    
    res.json({ message: 'Tour paid status updated successfully' });
  } catch (error) {
    console.error('Error updating tour paid status:', error);
    res.status(500).json({ error: 'Failed to update tour paid status' });
  }
});

// Update tour cancelled status
app.put('/api/tours/:id/cancelled', async (req, res) => {
  try {
    const { id } = req.params;
    const { cancelled } = req.body;
    
    const [result] = await pool.query(
      'UPDATE tours SET cancelled = ? WHERE id = ?',
      [cancelled ? 1 : 0, id]
    );
    
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Tour not found' });
    }
    
    res.json({ message: 'Tour cancelled status updated successfully' });
  } catch (error) {
    console.error('Error updating tour cancelled status:', error);
    res.status(500).json({ error: 'Failed to update tour cancelled status' });
  }
});

// Start the server
const PORT = process.env.PORT || 3001;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
}); 