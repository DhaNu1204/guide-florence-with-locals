# Deployment Guide for Hostinger

This guide covers how to deploy the Florence Guides application to Hostinger and set up a MySQL database.

## 1. Database Setup

### Create MySQL Database on Hostinger

1. Log in to your Hostinger account and go to the hosting control panel.
2. Navigate to the "Databases" section.
3. Create a new MySQL database and user:
   - Note down the database name, username, and password.
   - Set appropriate permissions (typically "All privileges").

### Import Database Schema

1. In the Hostinger control panel, go to phpMyAdmin for your database.
2. Import the `backend/database/setup.sql` script to create tables and initial data.

## 2. Backend Deployment

### Upload Backend Files

1. Create a subdomain for your API (e.g., `api.yourdomain.com`).
2. Upload all files from the `backend` folder to the subdomain's public directory.
3. Rename `.env.example` to `.env` and update with your Hostinger MySQL credentials:
   ```
   DB_HOST=your_hostinger_mysql_host
   DB_USER=your_database_username
   DB_PASSWORD=your_database_password
   DB_NAME=your_database_name
   NODE_ENV=production
   ```

### Configure Node.js on Hostinger

1. In the Hostinger control panel, enable Node.js for your domain or subdomain.
2. Install dependencies:
   ```
   cd /path/to/your/backend
   npm install
   ```
3. Start the server:
   ```
   npm start
   ```
4. Set up a process manager (if available) to keep your app running:
   ```
   npm install -g pm2
   pm2 start server.js
   ```

## 3. Frontend Deployment

### Build the React App

1. Update your `.env.production` file with the correct API URL:
   ```
   REACT_APP_API_URL=https://api.yourdomain.com/api
   ```
2. Build the React app:
   ```
   npm run build
   ```

### Upload Frontend Files

1. Upload all files from the `build` folder to your main domain's public directory.

### Configure Web Server

1. If your app uses React Router, set up URL rewriting to serve `index.html` for all routes:
   - For Apache (`.htaccess`):
     ```
     RewriteEngine On
     RewriteBase /
     RewriteRule ^index\.html$ - [L]
     RewriteCond %{REQUEST_FILENAME} !-f
     RewriteCond %{REQUEST_FILENAME} !-d
     RewriteRule . /index.html [L]
     ```

## 4. Testing

1. Test the API endpoints:
   - `https://api.yourdomain.com/api/test` - Should confirm database connection
   - `https://api.yourdomain.com/api/guides` - Should return guide data
   - `https://api.yourdomain.com/api/tours` - Should return tour data

2. Test the frontend application to ensure it's connecting to the API correctly.

## Troubleshooting

### Common Issues

1. **API Connection Errors**: Ensure CORS is properly configured in your backend.
2. **Database Connection Fails**: Verify database credentials and that your IP is allowed.
3. **404 Errors on Routes**: Make sure URL rewriting is properly set up.

### Logs

1. Check Node.js logs for backend errors:
   ```
   pm2 logs
   ```
2. Check Apache/Nginx logs for frontend errors. 