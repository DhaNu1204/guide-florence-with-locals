# ✅ **HOSTINGER DEPLOYMENT CHECKLIST**
## Florence with Locals - Production Deployment

### **📋 STEP-BY-STEP DEPLOYMENT CHECKLIST**

---

## **PHASE 1: PRE-DEPLOYMENT PREPARATION**

### **Local Environment Verification**
- [ ] ✅ **Application running locally** on both frontend (port 5173) and backend (port 8080)
- [ ] ✅ **All features tested** (Tours, Guides, Payments, Tickets, Bokun Integration)
- [ ] ✅ **Database schema complete** with all required tables
- [ ] ✅ **Production build created** (`npm run build` completed successfully)
- [ ] ✅ **Environment files configured** (.env.production created)

### **Hostinger Account Setup**
- [ ] **Business/Premium hosting plan** active (required for MySQL)
- [ ] **Domain name** configured and pointing to Hostinger servers
- [ ] **SSL Certificate** enabled (free Let's Encrypt available)
- [ ] **PHP 8.2+** enabled in Hostinger control panel
- [ ] **MySQL access** available through control panel

---

## **PHASE 2: DATABASE SETUP**

### **MySQL Database Creation**
- [ ] **Login to Hostinger Control Panel**
- [ ] **Navigate to "MySQL Databases" section**
- [ ] **Create new database:**
  ```
  ✍️ Database Name: u[your_id]_florence_guides
  ✍️ Username: u[your_id]_florence_user
  ✍️ Password: [STRONG PASSWORD - SAVE IT!]
  ✍️ Host: localhost
  ```

### **Database Import**
- [ ] **Export local database:**
  ```bash
  mysqldump -u root -p florence_guides > database_backup.sql
  ```
- [ ] **Open phpMyAdmin** from Hostinger control panel
- [ ] **Select your new database**
- [ ] **Import database_backup.sql** via phpMyAdmin Import tab
- [ ] **Verify all tables imported** (users, guides, tours, tickets, etc.)

### **Database Configuration Update**
- [ ] **Update config.php** with your actual Hostinger database credentials:
  ```php
  $db_host = 'localhost';
  $db_user = 'u123456789_florence_user';    // Your actual username
  $db_pass = 'YOUR_STRONG_PASSWORD';         // Your actual password
  $db_name = 'u123456789_florence_guides';  // Your actual database name
  ```

---

## **PHASE 3: FILE DEPLOYMENT**

### **Frontend Files Upload**
- [ ] **Upload ALL files from `dist/` folder to `public_html/`:**
  - [ ] `dist/index.html` → `public_html/index.html`
  - [ ] `dist/assets/` folder → `public_html/assets/` (entire folder)
  - [ ] Verify assets folder contains: `.js` and `.css` files

### **Backend Files Upload**
- [ ] **Upload ALL files from `public_html/api/` to `public_html/api/`:**
  - [ ] `tours.php`
  - [ ] `guides.php`
  - [ ] `tickets.php`
  - [ ] `payments.php`
  - [ ] `guide-payments.php`
  - [ ] `payment-reports.php`
  - [ ] `auth.php`
  - [ ] `bokun_sync.php`
  - [ ] `database_check.php`
  - [ ] `config.php` (with updated credentials)
  - [ ] `config_production.php`

### **Security Files Upload**
- [ ] **Upload `.htaccess`** to `public_html/.htaccess`
- [ ] **Create `logs` directory:** `public_html/logs/`
- [ ] **Set proper file permissions:**
  - [ ] Directories: 755
  - [ ] PHP files: 644
  - [ ] Logs directory: 755 (writable)

---

## **PHASE 4: CONFIGURATION UPDATE**

### **Frontend Configuration**
- [ ] **Update production API URL** in application
- [ ] **Rebuild with production settings:**
  ```bash
  # Update .env.production with your domain
  VITE_API_URL=https://yourdomain.com/api

  # Rebuild
  npm run build

  # Re-upload dist/ files
  ```

### **Backend Configuration**
- [ ] **Update CORS origins** in `config.php`:
  ```php
  $allowed_origins = [
      'https://yourdomain.com',
      'https://www.yourdomain.com'
  ];
  ```
- [ ] **Verify error logging** configuration
- [ ] **Test database connection** via `database_check.php`

---

## **PHASE 5: TESTING & VERIFICATION**

### **Basic Functionality Tests**
- [ ] **Access production URL:** `https://yourdomain.com`
- [ ] **Application loads correctly** (no 404 or loading errors)
- [ ] **Login works:** Test with `dhanu` / `Kandy@123`
- [ ] **Navigation functional:** All sidebar links work
- [ ] **Mobile responsive:** Test on mobile device

### **API Endpoint Tests**
- [ ] **Database connection:** `https://yourdomain.com/api/database_check.php`
- [ ] **Tours API:** `https://yourdomain.com/api/tours.php`
- [ ] **Guides API:** `https://yourdomain.com/api/guides.php`
- [ ] **Authentication API:** Test login via API
- [ ] **No CORS errors** in browser console

### **Feature Testing**
- [ ] **Tour Management:** Create, edit, view tours
- [ ] **Guide Management:** Create, edit guides with languages
- [ ] **Payment System:** Record payments, view reports
- [ ] **Ticket Management:** Manage museum tickets
- [ ] **Bokun Integration:** Access monitoring dashboard

### **Performance Tests**
- [ ] **Load speed:** < 3 seconds initial load
- [ ] **API response times:** < 500ms average
- [ ] **HTTPS working:** SSL certificate active
- [ ] **No console errors:** Clean browser console

---

## **PHASE 6: SECURITY VERIFICATION**

### **Security Headers**
- [ ] **HTTPS redirect working** (HTTP → HTTPS)
- [ ] **Security headers active** (check browser dev tools)
- [ ] **Sensitive files protected** (.log, .env files blocked)
- [ ] **Database credentials secure** (strong passwords used)

### **Access Control**
- [ ] **Admin login working:** `dhanu` / `Kandy@123`
- [ ] **Viewer login working:** `Sudeshshiwanka25@gmail.com` / `Sudesh@93`
- [ ] **Role-based permissions:** Admin vs Viewer access
- [ ] **API endpoints protected** (authentication required)

---

## **PHASE 7: PRODUCTION MONITORING**

### **Error Monitoring Setup**
- [ ] **Error logs accessible:** Check `public_html/logs/php_errors.log`
- [ ] **Monitoring tools:** Set up uptime monitoring
- [ ] **Backup schedule:** Configure automated backups
- [ ] **Performance tracking:** Monitor response times

### **Documentation Update**
- [ ] **Production URLs documented** for team access
- [ ] **Database credentials stored securely**
- [ ] **Deployment process documented**
- [ ] **Support contact information** available

---

## **🚨 CRITICAL SUCCESS CRITERIA**

Your deployment is successful when ALL of these work:

1. ✅ **Application loads at production URL**
2. ✅ **Admin can log in and access all features**
3. ✅ **All API endpoints respond correctly**
4. ✅ **Database operations work (create/edit/delete)**
5. ✅ **Mobile version functions properly**
6. ✅ **HTTPS/SSL working without errors**
7. ✅ **No console errors or warnings**
8. ✅ **All major features tested and functional**

---

## **📞 TROUBLESHOOTING QUICK FIXES**

### **Common Issues:**

#### **Database Connection Failed**
```
❌ Error: "Database connection failed"
✅ Solution:
1. Double-check database credentials in config.php
2. Verify database exists in Hostinger panel
3. Check database user has proper permissions
```

#### **CORS Policy Blocked**
```
❌ Error: "CORS policy: No 'Access-Control-Allow-Origin'"
✅ Solution:
1. Update allowed_origins in config.php with your domain
2. Verify .htaccess CORS headers
3. Check domain spelling (www vs non-www)
```

#### **404 Page Not Found**
```
❌ Error: Pages return 404 errors
✅ Solution:
1. Check .htaccess React Router rules
2. Verify all dist/ files uploaded correctly
3. Ensure proper file permissions (755/644)
```

#### **Assets Not Loading**
```
❌ Error: CSS/JS files not loading
✅ Solution:
1. Check assets/ folder uploaded completely
2. Verify file paths in index.html
3. Check HTTPS mixed content issues
```

---

## **🎯 POST-DEPLOYMENT TASKS**

### **Immediate (Within 24 hours)**
- [ ] Monitor error logs for any issues
- [ ] Test all critical user workflows
- [ ] Verify backup systems working
- [ ] Update team with production access info

### **First Week**
- [ ] Performance monitoring setup
- [ ] User acceptance testing
- [ ] Monitor for any production issues
- [ ] Optimize based on real usage

### **Ongoing**
- [ ] Regular security updates
- [ ] Database maintenance
- [ ] Performance optimization
- [ ] Feature updates and improvements

---

**🚀 DEPLOYMENT STATUS VERIFICATION**

Mark as complete only when:
- [ ] **All checklist items completed** ✅
- [ ] **Application fully functional in production** ✅
- [ ] **Team has access and training** ✅
- [ ] **Monitoring and backups configured** ✅

**🎉 CONGRATULATIONS! Your Florence with Locals Tour Guide Management System is now LIVE in production!**