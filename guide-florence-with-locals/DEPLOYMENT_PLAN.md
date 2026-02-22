# 🚀 Deployment Plan - Florence with Locals Tour Guide Management System

## 📊 Current Status

### Recent Commits Ready to Deploy (6 commits):
```
2adb508 🐛 Fix Priority Tickets sorting - Morning bookings now appear first
7072684 ✨ Add Booking Details Modal to Tours Page
68019d3 🎯 Set Priority Tickets to default to today's date
d3e5279 ✨ Add Booking Details Modal for Priority Tickets Page
9418e5c 📦 Additional Updates: Database Schema, Bokun Integration & UI Components
0608e38 🐛 Critical Bug Fixes & UX Enhancements (Oct 19, 2025)
```

### Working Tree Status: ✅ CLEAN
- All changes committed
- Ready to push to GitHub
- Ready to deploy to production

---

## 📦 STEP 1: PUSH TO GITHUB REPOSITORY

### Prerequisites:
- [ ] GitHub repository URL
- [ ] GitHub authentication (SSH key or Personal Access Token)
- [ ] Git remote configured

### Commands to Execute:

#### Option A: If Remote Already Configured
```bash
cd "D:\florence-with-locals-guide-assign-list\guide-florence-with-locals"
git push origin master
```

#### Option B: If Remote NOT Configured (First Time)
```bash
cd "D:\florence-with-locals-guide-assign-list\guide-florence-with-locals"

# Add GitHub remote (replace with your actual repository URL)
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO_NAME.git
# OR using SSH
git remote add origin git@github.com:YOUR_USERNAME/YOUR_REPO_NAME.git

# Push to GitHub
git push -u origin master
```

### Verify Push Success:
```bash
git log --oneline -1
# Should show: 2adb508 🐛 Fix Priority Tickets sorting - Morning bookings now appear first
```

---

## 🌐 STEP 2: DEPLOY TO PRODUCTION SERVER VIA SSH

### Production Server Details (To Be Filled):

| Parameter | Value | Status |
|-----------|-------|--------|
| **SSH Host** | 82.25.82.111 | ✅ Known from CLAUDE.md |
| **SSH Port** | 65002 | ✅ Known from CLAUDE.md |
| **SSH Username** | u803853690 | ✅ Known from CLAUDE.md |
| **SSH Password** | ❓ *To be provided* | ⏳ Waiting |
| **Server Directory** | /home/u803853690/domains/deetech.cc/public_html/withlocals | ✅ Known |
| **Production URL** | https://withlocals.deetech.cc | ✅ Known |

---

## 📋 DEPLOYMENT CHECKLIST

### Pre-Deployment Checks:
- [x] All changes committed to git
- [x] Git working tree is clean
- [x] Code tested locally (localhost:5173)
- [x] Frontend dev server running without errors
- [x] Backend PHP server tested (localhost:8080)
- [ ] GitHub repository push completed
- [ ] SSH credentials verified

### Files to Deploy:

#### Frontend Files (React Build):
```
/src/
  ├── components/
  │   ├── BookingDetailsModal.jsx ✨ NEW
  │   └── ... (existing files)
  ├── pages/
  │   ├── PriorityTickets.jsx ✅ UPDATED
  │   ├── Tours.jsx ✅ UPDATED
  │   └── ... (existing files)
  └── ... (all other src files)

package.json
vite.config.js
.env.production
```

#### Backend Files (PHP):
```
/public_html/api/
  ├── auth.php ✅ UPDATED
  ├── tours.php ✅ UPDATED
  ├── BokunAPI.php
  ├── bokun_sync.php
  ├── analyze_getyourguide.php ✨ NEW
  ├── migrate_database.php ✨ NEW
  ├── verify_database.php ✨ NEW
  └── ... (all other API files)
```

#### Database Schema Updates:
```
database_schema_updated.sql ✨ NEW
```

---

## 🛠️ DEPLOYMENT METHODS

### Method 1: Manual SSH Upload (Recommended for First Deployment)

#### Step 1: Build Production Frontend
```bash
cd "D:\florence-with-locals-guide-assign-list\guide-florence-with-locals"
npm run build
```
This creates an optimized production build in `/dist` folder.

#### Step 2: Connect to Server via SSH
```bash
ssh -p 65002 u803853690@82.25.82.111
```

#### Step 3: Backup Current Production Files
```bash
cd /home/u803853690/domains/deetech.cc/public_html
cp -r withlocals withlocals_backup_$(date +%Y%m%d_%H%M%S)
```

#### Step 4: Upload Files Using SCP
```bash
# From local machine (Windows PowerShell or Git Bash)

# Upload frontend build (dist folder)
scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/

# Upload backend API files
scp -P 65002 -r public_html/api/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/

# Upload src files (if needed for reference)
scp -P 65002 -r src/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/src/
```

---

### Method 2: Git Pull on Server (Faster for Updates)

#### Step 1: SSH into Server
```bash
ssh -p 65002 u803853690@82.25.82.111
```

#### Step 2: Navigate to Project Directory
```bash
cd /home/u803853690/domains/deetech.cc/public_html/withlocals
```

#### Step 3: Pull Latest Changes from GitHub
```bash
# Configure git if first time
git config --global user.name "Your Name"
git config --global user.email "your.email@example.com"

# Pull changes
git pull origin master
```

#### Step 4: Build Frontend on Server
```bash
npm install  # Install any new dependencies
npm run build  # Build production frontend
```

---

### Method 3: Automated Deployment Script (Advanced)

Create `deploy.sh` script:
```bash
#!/bin/bash

# Configuration
SSH_HOST="82.25.82.111"
SSH_PORT="65002"
SSH_USER="u803853690"
REMOTE_DIR="/home/u803853690/domains/deetech.cc/public_html/withlocals"

# Build locally
echo "Building production frontend..."
npm run build

# Create backup on server
echo "Creating backup on server..."
ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "cd /home/u803853690/domains/deetech.cc/public_html && cp -r withlocals withlocals_backup_$(date +%Y%m%d_%H%M%S)"

# Upload files
echo "Uploading files..."
scp -P $SSH_PORT -r dist/* $SSH_USER@$SSH_HOST:$REMOTE_DIR/
scp -P $SSH_PORT -r public_html/api/* $SSH_USER@$SSH_HOST:$REMOTE_DIR/api/

echo "Deployment complete!"
echo "Production URL: https://withlocals.deetech.cc"
```

Make executable and run:
```bash
chmod +x deploy.sh
./deploy.sh
```

---

## 🗄️ DATABASE UPDATES (If Required)

### Check if Database Schema Needs Updates:
```bash
# SSH into server
ssh -p 65002 u803853690@82.25.82.111

# Connect to MySQL
mysql -u u803853690_florence -p u803853690_florence_guides

# Check if new columns exist
DESCRIBE tours;
# Look for: notes, language, rescheduled, original_date, original_time, rescheduled_at
```

### Apply Schema Updates (If Needed):
```sql
-- Add notes column if missing
ALTER TABLE tours ADD COLUMN notes TEXT DEFAULT NULL;

-- Add language column if missing
ALTER TABLE tours ADD COLUMN language VARCHAR(50) DEFAULT NULL;

-- Add rescheduling columns if missing
ALTER TABLE tours ADD COLUMN rescheduled TINYINT(1) DEFAULT 0;
ALTER TABLE tours ADD COLUMN original_date DATE DEFAULT NULL;
ALTER TABLE tours ADD COLUMN original_time TIME DEFAULT NULL;
ALTER TABLE tours ADD COLUMN rescheduled_at DATETIME DEFAULT NULL;
```

---

## ✅ POST-DEPLOYMENT VERIFICATION

### Step 1: Test Production URL
1. Visit: https://withlocals.deetech.cc
2. Login with admin credentials: `dhanu / Kandy@123`

### Step 2: Verify New Features
- [ ] Priority Tickets page loads correctly
- [ ] Click any ticket row → Booking Details Modal opens
- [ ] Modal shows all booking information
- [ ] Notes are editable and save correctly
- [ ] Tickets sorted by time (morning first: 09:00, 12:00, 14:00)
- [ ] Tours page loads correctly
- [ ] Click any tour row → Booking Details Modal opens
- [ ] Dashboard chronological sorting works (date + time)
- [ ] Guide assignment and notes save correctly in Tours page

### Step 3: Check Backend APIs
```bash
# Test auth endpoint
curl https://withlocals.deetech.cc/api/auth.php

# Test tours endpoint
curl https://withlocals.deetech.cc/api/tours.php
```

### Step 4: Monitor Error Logs
```bash
# SSH into server
ssh -p 65002 u803853690@82.25.82.111

# Check PHP error logs
tail -f /home/u803853690/domains/deetech.cc/logs/error_log

# Check access logs
tail -f /home/u803853690/domains/deetech.cc/logs/access_log
```

---

## 🔄 ROLLBACK PLAN (If Issues Occur)

### Quick Rollback Steps:
```bash
# SSH into server
ssh -p 65002 u803853690@82.25.82.111

# Restore from backup
cd /home/u803853690/domains/deetech.cc/public_html
rm -rf withlocals
cp -r withlocals_backup_YYYYMMDD_HHMMSS withlocals

# Verify rollback
curl https://withlocals.deetech.cc
```

---

## 📝 DEPLOYMENT TIMELINE

### Estimated Time:
- GitHub Push: **2-5 minutes**
- Frontend Build: **3-5 minutes**
- File Upload via SCP: **5-10 minutes**
- Database Updates (if needed): **2-3 minutes**
- Testing & Verification: **10-15 minutes**

**Total Estimated Time: 22-38 minutes**

---

## 🎯 NEW FEATURES BEING DEPLOYED

### 1. Booking Details Modal (Priority Tickets & Tours)
- Click any booking row to view comprehensive details
- Main contact information from Bokun API
- Participant breakdown (adults/children, excluding infants)
- Booking confirmation codes and references
- Special requests from customers
- Editable internal notes

### 2. Priority Tickets Page Enhancements
- Default to today's date on page load
- Morning bookings appear first (chronological sorting)
- Removed Contact column (moved to modal)
- Adults/children breakdown display

### 3. Tours Page Bug Fixes
- Dashboard chronological sorting (date + time combined)
- Guide assignment persistence fix
- Notes field persistence fix
- Authentication session fix

### 4. Database & API Enhancements
- Enhanced bokun_data parsing
- Multi-channel language detection
- Smart payment tracking
- Improved error handling

---

## 🚨 IMPORTANT NOTES

### Security:
- ✅ All API endpoints have CORS configured
- ✅ SQL injection prevention with prepared statements
- ✅ Authentication tokens for session management
- ✅ HTTPS enabled with SSL certificate

### Performance:
- ✅ Frontend caching with localStorage (1-minute expiry)
- ✅ Vite build optimization with code splitting
- ✅ Efficient database queries with JOINs

### Compatibility:
- ✅ 100% mobile responsive
- ✅ Modern browsers supported
- ✅ Backend compatible with PHP 8.2+
- ✅ Database: MySQL 5.7+

---

## 📞 NEXT STEPS

### Please Provide:
1. **GitHub Repository URL** (if not already configured)
2. **SSH Password** for production server (or confirm SSH key is set up)
3. **Confirmation to proceed** with deployment

### I Will:
1. ✅ Push all commits to GitHub repository
2. ✅ Build production frontend
3. ✅ Deploy to production server via SSH
4. ✅ Verify all features are working
5. ✅ Provide deployment success report

---

**Ready to deploy when you provide the required credentials!** 🚀

---

*Generated: October 24, 2025*
*System: Florence with Locals Tour Guide Management System*
*Status: Ready for Production Deployment*
