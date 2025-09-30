# 🚀 **HOSTINGER PRODUCTION DEPLOYMENT GUIDE**
## Florence with Locals Tour Guide Management System

### **📋 INDUSTRY STANDARD DEPLOYMENT PROCESS**

---

## **PHASE 1: PRE-DEPLOYMENT SETUP**

### **1. Hostinger Requirements ✅**
- **Hosting Plan**: Business/Premium (required for MySQL)
- **PHP Version**: 8.2+ (enable in control panel)
- **Domain**: Configured and pointing to Hostinger
- **SSL Certificate**: Enable free Let's Encrypt
- **Database Access**: MySQL with phpMyAdmin

### **2. Local Preparation ✅**
```bash
# 1. Build production bundle (COMPLETED)
npm run build

# 2. Export local database
mysqldump -u root -p florence_guides > database_backup.sql

# 3. Create deployment package
```

---

## **PHASE 2: HOSTINGER DATABASE SETUP**

### **Step 1: Create MySQL Database**
1. **Login to Hostinger Control Panel**
2. **Navigate to "MySQL Databases"**
3. **Create Database:**
   ```
   Database Name: u[userid]_florence_guides
   Username: u[userid]_florence_user
   Password: [GENERATE STRONG PASSWORD - SAVE IT!]
   Host: localhost
   ```

### **Step 2: Import Database Schema**
1. **Open phpMyAdmin** from Hostinger control panel
2. **Select your database**
3. **Click "Import" tab**
4. **Upload** `database_backup.sql`
5. **Execute import**

### **Step 3: Update Production Config**
**File: `public_html/api/config.php`**
```php
<?php
// REPLACE WITH YOUR ACTUAL HOSTINGER CREDENTIALS
$db_host = 'localhost';
$db_user = 'u123456789_florence_user';    // Your actual username
$db_pass = 'YOUR_STRONG_PASSWORD';         // Your actual password
$db_name = 'u123456789_florence_guides';  // Your actual database name

// Production CORS (UPDATE WITH YOUR DOMAIN)
$allowed_origins = [
    'https://yourdomain.com',
    'https://www.yourdomain.com'
];
?>
```

---

## **PHASE 3: FILE DEPLOYMENT**

### **Hostinger Directory Structure:**
```
public_html/
├── index.html                     # From dist/ folder
├── assets/                        # From dist/assets/ folder
│   ├── index-[hash].js           # Built JavaScript
│   └── index-[hash].css          # Built CSS
├── api/                           # PHP backend files
│   ├── config.php                # Database config (UPDATED)
│   ├── tours.php                 # API endpoints
│   ├── guides.php
│   ├── tickets.php
│   ├── payments.php
│   ├── guide-payments.php
│   ├── payment-reports.php
│   ├── auth.php
│   ├── bokun_sync.php
│   └── database_check.php
└── logs/                          # Error logs directory
    └── php_errors.log
```

### **Step 1: Upload Frontend Files**
1. **Upload ALL files from `dist/` folder** to `public_html/`
   - `index.html` → `public_html/index.html`
   - `assets/` folder → `public_html/assets/`

### **Step 2: Upload Backend Files**
1. **Upload ALL files from `public_html/api/`** to `public_html/api/`
2. **Create logs directory**: `public_html/logs/`

### **Step 3: Set File Permissions**
```bash
# Via Hostinger File Manager or FTP
public_html/           → 755
public_html/api/       → 755
public_html/api/*.php  → 644
public_html/logs/      → 755
```

---

## **PHASE 4: CONFIGURATION UPDATES**

### **Step 1: Update Frontend API URL**
**Before building, update `.env.production`:**
```env
VITE_API_URL=https://yourdomain.com/api
VITE_ENVIRONMENT=production
VITE_DEBUG=false
```
**Then rebuild:** `npm run build`

### **Step 2: Update CORS Settings**
**File: `public_html/api/config.php`**
```php
// Replace with your actual domain
$allowed_origins = [
    'https://yourdomain.com',
    'https://www.yourdomain.com'
];
```

### **Step 3: Enable Error Logging**
**Create: `public_html/logs/` directory**
**Ensure proper permissions for PHP to write logs**

---

## **PHASE 5: SECURITY CONFIGURATION**

### **Step 1: Create .htaccess Files**

**Root .htaccess (`public_html/.htaccess`):**
```apache
# Security Headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

# Hide sensitive files
<Files "*.log">
    Deny from all
</Files>

<Files ".env*">
    Deny from all
</Files>

# Redirect HTTP to HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# React Router support
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]
```

**API .htaccess (`public_html/api/.htaccess`):**
```apache
# Enable CORS
Header add Access-Control-Allow-Origin "*"
Header add Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
Header add Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With"

# Security
<Files "config.php">
    <RequireAll>
        Require all granted
    </RequireAll>
</Files>

# Block direct access to sensitive files
<Files "*.log">
    Deny from all
</Files>
```

### **Step 2: Secure Database Credentials**
- **Use strong passwords** (minimum 16 characters)
- **Limit database user permissions** to only required operations
- **Enable SSL** for database connections if available

---

## **PHASE 6: TESTING & VERIFICATION**

### **Step 1: Basic Functionality Test**
1. **Access your domain**: `https://yourdomain.com`
2. **Verify loading**: Application loads correctly
3. **Test login**: Use admin credentials
4. **Check navigation**: All pages accessible

### **Step 2: API Endpoint Testing**
```bash
# Test database connection
curl https://yourdomain.com/api/database_check.php

# Test tours API
curl https://yourdomain.com/api/tours.php

# Test authentication
curl -X POST https://yourdomain.com/api/auth.php \
  -H "Content-Type: application/json" \
  -d '{"username":"dhanu","password":"Kandy@123"}'
```

### **Step 3: Performance Verification**
- **Load Speed**: < 3 seconds initial load
- **API Response**: < 500ms average
- **Mobile Performance**: Test on mobile devices
- **SSL Certificate**: Verify HTTPS working

---

## **PHASE 7: PRODUCTION MONITORING**

### **Step 1: Error Monitoring**
- **Check logs**: `public_html/logs/php_errors.log`
- **Set up monitoring**: Use Hostinger's built-in tools
- **Regular backups**: Automated database backups

### **Step 2: Performance Monitoring**
- **Uptime monitoring**: Use services like UptimeRobot
- **Database performance**: Monitor query response times
- **Traffic analytics**: Implement Google Analytics

---

## **🔧 TROUBLESHOOTING GUIDE**

### **Common Issues & Solutions**

#### **Database Connection Errors**
```
Error: "Database connection failed"
Solution:
1. Verify database credentials in config.php
2. Check database user permissions
3. Ensure database exists and is accessible
```

#### **API CORS Errors**
```
Error: "CORS policy blocked"
Solution:
1. Update allowed_origins in config.php
2. Verify .htaccess CORS headers
3. Check domain spelling and HTTPS
```

#### **File Permission Errors**
```
Error: "Permission denied"
Solution:
1. Set directories to 755
2. Set PHP files to 644
3. Ensure logs directory is writable
```

#### **Frontend Not Loading**
```
Error: "Cannot load assets"
Solution:
1. Verify all dist/ files uploaded correctly
2. Check .htaccess rewrite rules
3. Ensure HTTPS redirect working
```

---

## **📋 DEPLOYMENT CHECKLIST**

### **Pre-Deployment**
- [ ] Local application tested and working
- [ ] Production build created (`npm run build`)
- [ ] Database exported from local environment
- [ ] Domain and SSL certificate configured
- [ ] Hostinger hosting plan supports MySQL

### **Database Setup**
- [ ] MySQL database created on Hostinger
- [ ] Database user created with strong password
- [ ] Local database imported to production
- [ ] Database connection tested
- [ ] Production config.php updated with credentials

### **File Deployment**
- [ ] All `dist/` files uploaded to `public_html/`
- [ ] All `public_html/api/` files uploaded
- [ ] File permissions set correctly (755/644)
- [ ] Logs directory created and writable
- [ ] .htaccess files configured

### **Configuration**
- [ ] CORS origins updated with production domain
- [ ] API URLs updated in frontend
- [ ] Environment variables configured
- [ ] Error logging enabled
- [ ] Security headers configured

### **Testing**
- [ ] Application loads at production URL
- [ ] Login/authentication working
- [ ] All pages accessible and functional
- [ ] API endpoints responding correctly
- [ ] Mobile responsiveness verified
- [ ] HTTPS/SSL working properly

### **Production Ready**
- [ ] Performance optimized (< 3s load time)
- [ ] Error monitoring set up
- [ ] Backup system configured
- [ ] Documentation updated
- [ ] Team trained on production environment

---

## **🚨 CRITICAL SECURITY NOTES**

1. **NEVER commit production credentials to version control**
2. **Use environment-specific config files**
3. **Enable HTTPS/SSL on all production sites**
4. **Regularly update PHP and dependencies**
5. **Monitor error logs for security issues**
6. **Implement proper backup and recovery procedures**

---

## **🎯 POST-DEPLOYMENT TASKS**

### **Immediate (Within 24 hours)**
- Monitor error logs for any issues
- Test all critical functionality
- Verify backup systems working
- Update team on production URLs and access

### **Weekly**
- Review performance metrics
- Check security logs
- Update any dependencies
- Test backup/restore procedures

### **Monthly**
- Security audit and updates
- Performance optimization review
- Database maintenance and optimization
- Documentation updates

---

**🚀 DEPLOYMENT STATUS: READY FOR PRODUCTION**

Your Florence with Locals Tour Guide Management System is now configured and ready for Hostinger deployment following industry standards and best practices.

For support during deployment, refer to this guide and test each phase thoroughly before proceeding to the next step.