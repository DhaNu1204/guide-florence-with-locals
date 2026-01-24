#!/bin/bash
#
# Florence with Locals - Deployment Script
# Usage: ./scripts/deploy.sh [environment]
#   environment: 'production' or 'staging' (default: production)
#

set -e

# Configuration
REMOTE_USER="u803853690"
REMOTE_HOST="82.25.82.111"
REMOTE_PORT="65002"
REMOTE_PATH="/home/u803853690/domains/deetech.cc/public_html/withlocals"
BACKUP_DIR="/home/u803853690/domains/deetech.cc/backups"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "package.json" ]; then
    log_error "Please run this script from the project root directory"
    exit 1
fi

# Get environment
ENVIRONMENT=${1:-production}
log_info "Deploying to: $ENVIRONMENT"

# Step 1: Run tests (if available)
if [ -f "package.json" ] && grep -q "\"test\"" package.json; then
    log_info "Running tests..."
    npm test || {
        log_error "Tests failed! Aborting deployment."
        exit 1
    }
fi

# Step 2: Build frontend
log_info "Building frontend for production..."
npm run build -- --mode production

if [ ! -d "dist" ]; then
    log_error "Build failed! dist directory not found."
    exit 1
fi

log_info "Build completed successfully"

# Step 3: Create backup on remote server
log_info "Creating backup on remote server..."
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="withlocals_backup_${TIMESTAMP}"

ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "
    mkdir -p $BACKUP_DIR
    if [ -d '$REMOTE_PATH' ]; then
        cp -r $REMOTE_PATH $BACKUP_DIR/$BACKUP_NAME
        echo 'Backup created: $BACKUP_NAME'
    fi
"

# Step 4: Deploy frontend files
log_info "Deploying frontend files..."
scp -P $REMOTE_PORT -r dist/* $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/

# Step 5: Deploy backend API files
log_info "Deploying backend API files..."
scp -P $REMOTE_PORT -r public_html/api/* $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/api/

# Step 6: Set permissions
log_info "Setting file permissions..."
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "
    chmod -R 755 $REMOTE_PATH
    chmod 644 $REMOTE_PATH/api/*.php
    chmod 644 $REMOTE_PATH/index.html
"

# Step 7: Clear any PHP opcache (if available)
log_info "Clearing PHP cache..."
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "
    if command -v php &> /dev/null; then
        php -r 'if(function_exists(\"opcache_reset\")) opcache_reset();' 2>/dev/null || true
    fi
"

# Step 8: Verify deployment
log_info "Verifying deployment..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://withlocals.deetech.cc)

if [ "$HTTP_CODE" = "200" ]; then
    log_info "Deployment successful! Site is responding with HTTP $HTTP_CODE"
else
    log_warn "Site returned HTTP $HTTP_CODE - please verify manually"
fi

# Step 9: Test API endpoint
API_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://withlocals.deetech.cc/api/tours.php)

if [ "$API_CODE" = "200" ]; then
    log_info "API is responding correctly (HTTP $API_CODE)"
else
    log_warn "API returned HTTP $API_CODE - please verify manually"
fi

log_info "================================================"
log_info "Deployment completed!"
log_info "Production URL: https://withlocals.deetech.cc"
log_info "Backup created: $BACKUP_NAME"
log_info "================================================"

# Cleanup old backups (keep last 5)
log_info "Cleaning up old backups..."
ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_HOST "
    cd $BACKUP_DIR
    ls -t | tail -n +6 | xargs -r rm -rf
"

log_info "Done!"
