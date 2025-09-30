# Environment Configuration Guide

## Overview
This application uses **automatic environment detection** to switch between development and production configurations without manual changes. The same codebase works on both your local machine and production server.

## How It Works

### Automatic Detection
The `config.php` file automatically detects which environment it's running in by checking:

**Production Indicators:**
- Domain contains `withlocals.deetech.cc`
- Server path contains `u803853690` (Hostinger user)
- File system contains `/home/u803853690/`

**Development Indicators:**
- Host is `localhost` or `127.0.0.1`
- Path contains `xampp`, `wamp`, or `florence-with-locals`
- Server address is local IP

## Configuration Files

### 1. `config.php` (Auto-Switching)
- **Location**: `/public_html/api/config.php`
- **Purpose**: Main configuration file that auto-detects environment
- **DO NOT MODIFY** database credentials directly in this file

### 2. `.env.local` (Optional for Development)
- **Location**: `/guide-florence-with-locals/.env.local`
- **Purpose**: Override local development database settings
- **Example**: Copy `.env.local.example` to `.env.local`

```bash
# Example .env.local content
DB_HOST=localhost
DB_USER=root
DB_PASS=your_password
DB_NAME=florence_guides
```

### 3. Frontend Environment Files
- **Development**: `.env` or `.env.development`
- **Production**: `.env.production`

## Default Settings

### Development (Local)
```
Database Host: localhost
Database User: root
Database Pass: (empty)
Database Name: florence_guides
API URL: http://localhost:8080/api
Frontend URL: http://localhost:5173
```

### Production (Hostinger)
```
Database Host: localhost
Database User: u803853690_withlocals
Database Pass: [secured]
Database Name: u803853690_withlocals
API URL: https://withlocals.deetech.cc/api
Frontend URL: https://withlocals.deetech.cc
```

## Testing Environment Detection

Visit this endpoint to check which environment is detected:
- **Development**: http://localhost:8080/api/check_environment.php
- **Production**: https://withlocals.deetech.cc/api/check_environment.php

## Setting Up Local Development

### Using XAMPP/WAMP (Windows)
1. Install XAMPP/WAMP
2. Start Apache and MySQL services
3. No additional configuration needed (uses defaults)

### Using Custom MySQL Setup
1. Copy `.env.local.example` to `.env.local`
2. Edit `.env.local` with your database credentials:
   ```
   DB_HOST=localhost
   DB_USER=your_username
   DB_PASS=your_password
   DB_NAME=your_database
   ```

### Creating Local Database
```sql
-- Run in phpMyAdmin or MySQL console
CREATE DATABASE IF NOT EXISTS florence_guides;
USE florence_guides;

-- Import the schema
SOURCE database_schema.sql;

-- Or use the setup script
SOURCE setup_local_db.sql;
```

## Deployment Process

### Development → Production
1. Make changes in local development
2. Test thoroughly on `http://localhost:5173`
3. Commit changes to Git
4. Deploy to production server
5. **No config changes needed!** Auto-detection handles environment switching

### File Deployment
```bash
# The same config.php works in both environments
scp -P 65002 config.php u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/
```

## Troubleshooting

### Check Current Environment
```php
// In any PHP file
require_once 'config.php';
echo "Environment: " . ENVIRONMENT;
echo "Debug Mode: " . (DEBUG ? 'ON' : 'OFF');
```

### Database Connection Issues

**Development:**
1. Check MySQL service is running
2. Verify `.env.local` credentials (if exists)
3. Check firewall/antivirus not blocking port 3306

**Production:**
1. Verify Hostinger database is active
2. Check database user permissions
3. Ensure SSL certificate is valid

### CORS Issues
The config automatically sets appropriate CORS headers for each environment:
- **Development**: Allows localhost:5173 and other common ports
- **Production**: Allows withlocals.deetech.cc domain

## Benefits of This Setup

✅ **No Manual Switching**: Deploy without changing configs
✅ **Team Friendly**: Each developer can have different local settings
✅ **Error Prevention**: No risk of pushing production credentials
✅ **Easy Debugging**: Environment info available in error messages
✅ **Flexible**: Easy to add staging or other environments

## Security Notes

⚠️ **Never commit `.env.local`** - It's in .gitignore
⚠️ **Production credentials** are only in production config section
⚠️ **Development shows detailed errors**, production hides them
⚠️ **Each environment has appropriate CORS restrictions**

## Quick Commands

```bash
# Start development servers
cd guide-florence-with-locals
npm run dev                          # Frontend (port 5173)
php -S localhost:8080 -t public_html # Backend (port 8080)

# Check environment
curl http://localhost:8080/api/check_environment.php

# Deploy to production (example)
git add .
git commit -m "Feature update"
git push origin main
# Then SSH to server and pull changes
```

## Support

If environment detection fails:
1. Check `/api/check_environment.php` endpoint
2. Review server variables in response
3. Add additional detection rules in `detectEnvironment()` function
4. Contact team for assistance

---

This setup follows **industry best practices** for environment management, similar to frameworks like Laravel, Symfony, and Node.js applications.