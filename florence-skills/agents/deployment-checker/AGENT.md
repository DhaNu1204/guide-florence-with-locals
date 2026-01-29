# Deployment Checker Agent

## Purpose
Pre-deployment validation agent for the Florence With Locals project. Ensures all prerequisites are met before deploying to production, verifies configurations, and validates the deployment process.

## Production Environment Reference

```
Production URL: https://withlocals.deetech.cc
SSH Access: ssh -p 65002 u803853690@82.25.82.111
PHP Version: 8.2
MySQL Version: 8.0
Node Version: 20.x (for build)
```

---

## Pre-Deployment Checklist

### 1. Code Quality Checks

```
[ ] All tests pass locally
[ ] No TypeScript/ESLint errors
[ ] No console.log statements in production code
[ ] No hardcoded credentials or API keys
[ ] No debug flags enabled
[ ] Code review completed
[ ] All merge conflicts resolved
```

#### Validation Commands
```bash
# Run linting
npm run lint

# Check for console.log
grep -r "console.log" src/ --include="*.js" --include="*.jsx"

# Check for hardcoded credentials
grep -rE "(password|api_key|secret)\\s*=\\s*['\"][^'\"]+['\"]" src/ public_html/

# Verify no debug flags
grep -r "DEBUG.*=.*true" public_html/api/
```

### 2. Build Verification

```
[ ] Production build completes without errors
[ ] Build output size is reasonable (<5MB)
[ ] All assets are included in build
[ ] Source maps configured correctly
[ ] Environment variables are set
```

#### Validation Commands
```bash
# Create production build
npm run build

# Check build size
du -sh dist/

# Verify critical files exist
ls -la dist/index.html dist/assets/

# Check for source maps (should NOT be in production)
find dist/ -name "*.map" | wc -l
```

### 3. Environment Configuration

```
[ ] .env.production has correct values
[ ] API_BASE_URL points to production
[ ] VITE_SENTRY_DSN is configured
[ ] Database credentials are correct
[ ] Bokun API credentials are valid
```

#### Environment File Checklist
```ini
# Frontend (.env.production)
VITE_API_BASE_URL=https://withlocals.deetech.cc/api
VITE_SENTRY_DSN=https://xxx@sentry.io/xxx

# Backend (.env on server)
DB_HOST=localhost
DB_NAME=u803853690_florenceguides
DB_USER=u803853690_florenceuser
DB_PASS=<secure_password>
BOKUN_ACCESS_KEY=<access_key>
BOKUN_SECRET_KEY=<secret_key>
APP_ENV=production
```

### 4. Database Checks

```
[ ] Database migrations applied
[ ] Schema matches expected structure
[ ] No pending migrations
[ ] Backup taken before deployment
[ ] Connection credentials work
```

#### Validation Commands
```bash
# Test database connection (via PHP)
php -r "
  \$pdo = new PDO('mysql:host=localhost;dbname=u803853690_florenceguides', 'user', 'pass');
  echo 'Connection successful';
"

# Verify table structure
mysql -e "SHOW TABLES;" u803853690_florenceguides

# Create backup
mysqldump u803853690_florenceguides > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 5. API Health Checks

```
[ ] /api/health.php returns 200
[ ] Authentication endpoint works
[ ] Bokun API connection works
[ ] All critical endpoints respond
```

#### Validation Commands
```bash
# Health check
curl -s https://withlocals.deetech.cc/api/health.php

# Auth endpoint
curl -s -X POST https://withlocals.deetech.cc/api/auth.php \
  -H "Content-Type: application/json" \
  -d '{"action":"test_connection"}'

# Bokun API test
curl -s https://withlocals.deetech.cc/api/bokun_sync.php?action=test
```

### 6. Security Checks

```
[ ] HTTPS is enforced
[ ] Security headers are set
[ ] CORS is properly configured
[ ] No sensitive files accessible
[ ] Error display is disabled
```

#### Validation Commands
```bash
# Check HTTPS redirect
curl -I http://withlocals.deetech.cc

# Check security headers
curl -I https://withlocals.deetech.cc | grep -E "X-Frame|X-Content|Strict-Transport"

# Check sensitive file access
curl -s https://withlocals.deetech.cc/.env
curl -s https://withlocals.deetech.cc/api/config.php

# Check PHP error display
curl -s https://withlocals.deetech.cc/api/error_test.php
```

---

## Deployment Process

### Step 1: Pre-Deployment
```bash
# 1. Pull latest changes
git pull origin main

# 2. Install dependencies
npm ci

# 3. Run checks
npm run lint
npm run build

# 4. Verify build
ls -la dist/
```

### Step 2: Backup
```bash
# SSH to server
ssh -p 65002 u803853690@82.25.82.111

# Backup current deployment
cd /home/u803853690/domains/withlocals.deetech.cc
cp -r public_html public_html_backup_$(date +%Y%m%d)

# Backup database
mysqldump u803853690_florenceguides > ~/backups/db_$(date +%Y%m%d_%H%M%S).sql
```

### Step 3: Deploy Frontend
```bash
# From local machine
# Upload built files
scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/withlocals.deetech.cc/public_html/

# Or use rsync for efficiency
rsync -avz -e "ssh -p 65002" dist/ u803853690@82.25.82.111:/home/u803853690/domains/withlocals.deetech.cc/public_html/ --exclude='api'
```

### Step 4: Deploy Backend
```bash
# Upload API files
scp -P 65002 -r public_html/api/* u803853690@82.25.82.111:/home/u803853690/domains/withlocals.deetech.cc/public_html/api/

# Set permissions
ssh -p 65002 u803853690@82.25.82.111 "chmod 644 /home/u803853690/domains/withlocals.deetech.cc/public_html/api/*.php"
```

### Step 5: Post-Deployment Verification
```bash
# Health check
curl -s https://withlocals.deetech.cc/api/health.php

# Test frontend loads
curl -s https://withlocals.deetech.cc/ | head -20

# Test login
curl -s -X POST https://withlocals.deetech.cc/api/auth.php \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"test"}'

# Check for errors
ssh -p 65002 u803853690@82.25.82.111 "tail -20 /home/u803853690/logs/error.log"
```

---

## Rollback Procedure

### Quick Rollback (Frontend)
```bash
ssh -p 65002 u803853690@82.25.82.111

# Restore from backup
cd /home/u803853690/domains/withlocals.deetech.cc
rm -rf public_html
mv public_html_backup_YYYYMMDD public_html
```

### Database Rollback
```bash
# Restore database from backup
mysql u803853690_florenceguides < ~/backups/db_YYYYMMDD_HHMMSS.sql
```

### Partial Rollback (Specific Files)
```bash
# Restore specific file from backup
cp public_html_backup_YYYYMMDD/api/specific_file.php public_html/api/
```

---

## Deployment Report Template

```markdown
# Deployment Report

## Deployment Info
- **Date:** YYYY-MM-DD HH:MM
- **Deployer:** [Name]
- **Version/Commit:** [Git SHA]
- **Environment:** Production

## Pre-Deployment Checklist
- [x] Code quality checks passed
- [x] Build verification completed
- [x] Environment configuration verified
- [x] Database checks completed
- [x] Backup created

## Changes Deployed
- Feature: [Description]
- Fix: [Description]
- Update: [Description]

## Post-Deployment Verification
- [x] Health check passed
- [x] Frontend loads correctly
- [x] Login works
- [x] Bokun sync works
- [x] No errors in logs

## Issues Encountered
- None / [Description of issues and resolution]

## Rollback Status
- Not required / Performed at HH:MM - [Reason]

## Sign-off
- Deployed by: [Name]
- Verified by: [Name]
```

---

## Automated Deployment Script

```bash
#!/bin/bash
# deploy.sh - Automated deployment script

set -e  # Exit on error

echo "=== Florence With Locals Deployment ==="
echo "Started at: $(date)"

# Configuration
SERVER="u803853690@82.25.82.111"
PORT="65002"
REMOTE_PATH="/home/u803853690/domains/withlocals.deetech.cc"
BACKUP_DATE=$(date +%Y%m%d_%H%M%S)

# Pre-deployment checks
echo "Running pre-deployment checks..."

# Check for uncommitted changes
if [[ -n $(git status -s) ]]; then
    echo "ERROR: Uncommitted changes detected. Commit or stash before deploying."
    exit 1
fi

# Run lint
echo "Running linter..."
npm run lint || { echo "Lint failed"; exit 1; }

# Build
echo "Building production bundle..."
npm run build || { echo "Build failed"; exit 1; }

# Verify build
if [[ ! -f "dist/index.html" ]]; then
    echo "ERROR: Build output missing"
    exit 1
fi

# Create backup on server
echo "Creating backup on server..."
ssh -p $PORT $SERVER "cd $REMOTE_PATH && cp -r public_html public_html_backup_$BACKUP_DATE"

# Deploy frontend
echo "Deploying frontend..."
rsync -avz -e "ssh -p $PORT" dist/ $SERVER:$REMOTE_PATH/public_html/ --exclude='api'

# Deploy backend
echo "Deploying backend..."
rsync -avz -e "ssh -p $PORT" public_html/api/ $SERVER:$REMOTE_PATH/public_html/api/

# Set permissions
echo "Setting permissions..."
ssh -p $PORT $SERVER "chmod 644 $REMOTE_PATH/public_html/api/*.php"

# Post-deployment verification
echo "Running post-deployment checks..."

# Health check
HEALTH=$(curl -s https://withlocals.deetech.cc/api/health.php)
if [[ $HEALTH != *"healthy"* ]]; then
    echo "WARNING: Health check failed"
    echo "Response: $HEALTH"
fi

# Check for PHP errors
ERRORS=$(ssh -p $PORT $SERVER "tail -5 /home/u803853690/logs/error.log 2>/dev/null || echo 'No errors'")
echo "Recent errors: $ERRORS"

echo "=== Deployment Complete ==="
echo "Finished at: $(date)"
echo ""
echo "Verify at: https://withlocals.deetech.cc"
echo "Backup created: public_html_backup_$BACKUP_DATE"
```

---

## CI/CD Integration (GitHub Actions)

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production

    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Run lint
        run: npm run lint

      - name: Build
        run: npm run build
        env:
          VITE_API_BASE_URL: ${{ secrets.VITE_API_BASE_URL }}
          VITE_SENTRY_DSN: ${{ secrets.VITE_SENTRY_DSN }}

      - name: Deploy via SSH
        uses: appleboy/ssh-action@v1.0.0
        with:
          host: 82.25.82.111
          username: u803853690
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          port: 65002
          script: |
            cd /home/u803853690/domains/withlocals.deetech.cc
            # Backup
            cp -r public_html public_html_backup_$(date +%Y%m%d)

      - name: Upload frontend
        uses: appleboy/scp-action@v0.1.4
        with:
          host: 82.25.82.111
          username: u803853690
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          port: 65002
          source: "dist/*"
          target: "/home/u803853690/domains/withlocals.deetech.cc/public_html"
          strip_components: 1

      - name: Health check
        run: |
          sleep 5
          curl -f https://withlocals.deetech.cc/api/health.php || exit 1
```

---

## Monitoring Post-Deployment

### Key Metrics to Watch
- Error rate in Sentry
- API response times
- Database connection errors
- Bokun sync success rate
- User login success rate

### Log Locations
```
PHP Errors: /home/u803853690/logs/error.log
Access Logs: /home/u803853690/logs/access.log
Application Logs: /home/u803853690/domains/withlocals.deetech.cc/logs/
```

### Alert Conditions
- Error rate > 1% in 5 minutes
- API response time > 2 seconds
- Health check fails 3 consecutive times
- Bokun sync fails for > 1 hour
