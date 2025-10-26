# Troubleshooting Guide

## Common Issues & Solutions

### Frontend Issues

#### Port Conflicts
**Problem**: Application trying to start on different ports

**Solution**:
- **NEVER allow application to start on different ports**
- Always kill existing processes and use port 5173 only
- Commands:
  ```bash
  netstat -ano | findstr :5173
  taskkill //PID [PID_NUMBER] //F
  ```

#### Multiple Development Servers
**Problem**: Multiple Vite servers running simultaneously

**Solution**:
- **NEVER run multiple development servers simultaneously**
- Kill all background Vite servers before starting new one
- Ensure only one instance is running

#### Icon Import Errors
**Problem**: React Icons not displaying or import errors

**Solution**:
- Ensure correct React Icons import names (FiHome, not FiBuilding)
- Verify react-icons package is installed
- Check import statement syntax

#### Build Errors
**Problem**: Vite build failing or cache issues

**Solution**:
- Clear cache and restart: `npm run dev`
- Delete node_modules and reinstall: `npm install`
- Check for syntax errors in components

### Backend Issues

#### Database Connection Failed
**Problem**: Cannot connect to MySQL database

**Solution**:
- Verify MySQL service is running
- Check credentials in `public_html/api/config.php`
- Ensure database exists
- Verify PHP mysqli extension is enabled

#### PHP Extensions Not Loaded
**Problem**: curl, openssl, or mysqli not available

**Solution**:
- Check `C:\php\php.ini`
- Enable required extensions:
  - `extension=curl`
  - `extension=openssl`
  - `extension=mysqli`
- Restart PHP server

#### API CORS Errors
**Problem**: Cross-Origin Resource Sharing errors

**Solution**:
- Check CORS headers in `config.php`
- Verify frontend URL is allowed
- Ensure proper request methods are permitted

#### 500 Internal Server Error
**Problem**: API endpoints returning 500 errors

**Solution**:
- Check PHP error log
- Verify database connection
- Check SQL query syntax
- Ensure all required fields are present in requests

### Integration Issues

#### Bokun API Connection Failed
**Problem**: Cannot connect to Bokun API

**Solution**:
- Verify API credentials in `bokun_config` table
- Check HMAC-SHA1 signature generation
- Confirm API permissions are enabled
- Test with `/api/bokun_sync.php?action=test`

#### Data Not Syncing
**Problem**: Bokun bookings not appearing in system

**Solution**:
- Clear localStorage cache
- Use "Refresh" button to force cache bypass
- Verify Bokun API credentials
- Check date range in sync request
- Confirm booking channel association

#### Language Detection Not Working
**Problem**: Tour language not being detected

**Solution**:
- Verify bokun_data JSON is stored correctly
- Check language extraction methods in sync logic
- Ensure booking contains language information
- Review extraction patterns for booking channel

### Authentication Issues

#### Login Fails
**Problem**: Cannot login with correct credentials

**Solution**:
- Verify sessions table exists
- Check if token column is present
- Verify user exists in users table
- Check PHP session configuration

#### Session Expires Too Quickly
**Problem**: User logged out unexpectedly

**Solution**:
- Check session timeout settings
- Verify session storage configuration
- Ensure cookies are enabled

### Performance Issues

#### Slow Page Load
**Problem**: Pages taking too long to load

**Solution**:
- Clear localStorage cache
- Optimize database queries
- Check network tab for slow API calls
- Verify server resources

#### Dashboard Not Updating
**Problem**: Dashboard showing stale data

**Solution**:
- Use "Refresh" button
- Clear browser cache
- Verify API endpoints are returning current data
- Check caching expiry settings

## Getting Help

If issues persist after trying these solutions:

1. Check browser console for errors
2. Review PHP error logs
3. Verify database schema matches documentation
4. Check API endpoint responses
5. Review recent code changes
6. Consult [CHANGELOG.md](./CHANGELOG.md) for recent fixes
