#!/bin/bash
#
# Florence Guides - Production Deployment Script
#
# This script deploys the application to Hostinger production server.
# Works with both manual deployment and GitHub Actions.
#
# Usage:
#   ./scripts/deploy.sh --target staging               # Deploy both frontend and backend to staging
#   ./scripts/deploy.sh --target production            # Deploy to production (master + clean tree only)
#   ./scripts/deploy.sh --target <t> --frontend        # Deploy frontend only
#   ./scripts/deploy.sh --target <t> --backend         # Deploy backend only
#   ./scripts/deploy.sh --target <t> --check           # Health check only (no deployment)
#   ./scripts/deploy.sh --target <t> --no-backup       # Deploy without creating backup
#
# --target is mandatory (step 0.1). There is no default target.
#
# Requirements:
#   - SSH access configured
#   - Node.js and npm installed (for frontend build)
#

set -e  # Exit on error

# ===========================================================================
# Configuration
# ===========================================================================
SSH_HOST="82.25.82.111"
SSH_PORT="65002"
SSH_USER="u803853690"

# Deploy target (step 0.1). Set by --target; resolved below into the three
# variables the rest of the script already uses (names kept unchanged on purpose).
TARGET=""
PRODUCTION_PATH=""
PRODUCTION_URL=""
BACKUP_DIR=""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ===========================================================================
# Helper Functions
# ===========================================================================
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# ===========================================================================
# Parse Arguments
# ===========================================================================
DEPLOY_FRONTEND=true
DEPLOY_BACKEND=true
CHECK_ONLY=false
CREATE_BACKUP=true

while [[ $# -gt 0 ]]; do
    case $1 in
        --target)
            TARGET="$2"
            shift 2
            ;;
        --target=*)
            TARGET="${1#*=}"
            shift
            ;;
        --frontend)
            DEPLOY_BACKEND=false
            shift
            ;;
        --backend)
            DEPLOY_FRONTEND=false
            shift
            ;;
        --check)
            CHECK_ONLY=true
            DEPLOY_FRONTEND=false
            DEPLOY_BACKEND=false
            shift
            ;;
        --no-backup)
            CREATE_BACKUP=false
            shift
            ;;
        --help|-h)
            echo "Florence Guides - Deployment Script"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --target T    REQUIRED: staging | production"
            echo "  --frontend    Deploy frontend only"
            echo "  --backend     Deploy backend only"
            echo "  --check       Run health check only (no deployment)"
            echo "  --no-backup   Skip backup creation"
            echo "  --help, -h    Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0 --target staging                 # Deploy everything to staging with backup"
            echo "  $0 --target production              # Deploy to production (master + clean tree only)"
            echo "  $0 --target staging --frontend      # Deploy frontend only"
            echo "  $0 --target staging --backend --no-backup  # Deploy backend without backup"
            echo "  $0 --target production --check      # Check if production is healthy"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# ===========================================================================
# Resolve deploy target (step 0.1) - an explicit --target is mandatory
# ===========================================================================
case "$TARGET" in
    staging)
        PRODUCTION_PATH="/home/u803853690/domains/deetech.cc/public_html/stagingwithlocals"
        PRODUCTION_URL="https://stagingwithlocals.deetech.cc"
        BACKUP_DIR="/home/u803853690/domains/deetech.cc/backups/staging"
        ;;
    production)
        PRODUCTION_PATH="/home/u803853690/domains/deetech.cc/public_html/withlocals"
        PRODUCTION_URL="https://withlocals.deetech.cc"
        BACKUP_DIR="/home/u803853690/domains/deetech.cc/backups"
        ;;
    "")
        log_error "No deploy target given. Use --target staging or --target production."
        exit 1
        ;;
    *)
        log_error "Unknown target: '$TARGET' (expected: staging | production)"
        exit 1
        ;;
esac

# Production guard: only from branch master (never main) and only with a clean tree
if [ "$TARGET" = "production" ] && [ "$CHECK_ONLY" != true ]; then
    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
    if [ "$CURRENT_BRANCH" != "master" ]; then
        log_error "Production deploys only from branch 'master' (current: $CURRENT_BRANCH)."
        exit 1
    fi
    if [ -n "$(git status --porcelain)" ]; then
        log_error "Production deploys require a clean working tree. Commit or stash first."
        exit 1
    fi
fi

# ===========================================================================
# Health Check Function
# ===========================================================================
health_check() {
    log_info "Running health checks..."

    local all_ok=true

    # Check API endpoints
    log_info "Checking API endpoints..."

    # Tours API
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${PRODUCTION_URL}/api/tours.php?upcoming=true&per_page=1" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" -eq 200 ]; then
        log_success "Tours API: OK ($HTTP_CODE)"
    else
        log_error "Tours API: Failed ($HTTP_CODE)"
        all_ok=false
    fi

    # Guides API
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${PRODUCTION_URL}/api/guides.php?per_page=1" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" -eq 200 ]; then
        log_success "Guides API: OK ($HTTP_CODE)"
    else
        log_error "Guides API: Failed ($HTTP_CODE)"
        all_ok=false
    fi

    # Auth API
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${PRODUCTION_URL}/api/auth.php" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" -eq 200 ]; then
        log_success "Auth API: OK ($HTTP_CODE)"
    else
        log_error "Auth API: Failed ($HTTP_CODE)"
        all_ok=false
    fi

    # Frontend
    log_info "Checking frontend..."
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${PRODUCTION_URL}/" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" -eq 200 ]; then
        log_success "Frontend: OK ($HTTP_CODE)"
    else
        log_error "Frontend: Failed ($HTTP_CODE)"
        all_ok=false
    fi

    if [ "$all_ok" = true ]; then
        log_success "All health checks passed!"
        return 0
    else
        log_error "Some health checks failed!"
        return 1
    fi
}

# ===========================================================================
# Pre-flight Checks
# ===========================================================================

# If check only, run health check and exit
if [ "$CHECK_ONLY" = true ]; then
    health_check
    exit $?
fi

log_info "Starting deployment process..."
echo "========================================"
echo "Target: $TARGET -> $PRODUCTION_URL"
echo "Frontend: $([ "$DEPLOY_FRONTEND" = true ] && echo "Yes" || echo "No")"
echo "Backend: $([ "$DEPLOY_BACKEND" = true ] && echo "Yes" || echo "No")"
echo "Backup: $([ "$CREATE_BACKUP" = true ] && echo "Yes" || echo "No")"
echo "========================================"

# Check if we're in the right directory
if [ ! -f "package.json" ]; then
    log_error "package.json not found. Run this script from the project root."
    exit 1
fi

# Check SSH connectivity
log_info "Testing SSH connection..."
if ! ssh -p $SSH_PORT -o ConnectTimeout=10 -o BatchMode=yes $SSH_USER@$SSH_HOST "echo 'SSH OK'" > /dev/null 2>&1; then
    log_error "Cannot connect to server. Check your SSH configuration."
    log_info "Try: ssh -p $SSH_PORT $SSH_USER@$SSH_HOST"
    exit 1
fi
log_success "SSH connection OK"

# ===========================================================================
# Create Backup (Optional)
# ===========================================================================
if [ "$CREATE_BACKUP" = true ]; then
    log_info "Creating backup on remote server..."
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    BACKUP_NAME="withlocals_backup_${TIMESTAMP}"
    [ "$TARGET" = "staging" ] && BACKUP_NAME="stagingwithlocals_backup_${TIMESTAMP}"

    ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "
        mkdir -p $BACKUP_DIR
        if [ -d '$PRODUCTION_PATH' ]; then
            cp -r $PRODUCTION_PATH $BACKUP_DIR/$BACKUP_NAME
            echo 'Backup created: $BACKUP_NAME'
        fi
    "
    log_success "Backup created: $BACKUP_NAME"
fi

# ===========================================================================
# Build Frontend
# ===========================================================================
if [ "$DEPLOY_FRONTEND" = true ]; then
    log_info "Building frontend..."

    # Install dependencies if needed
    if [ ! -d "node_modules" ]; then
        log_info "Installing dependencies..."
        npm ci
    fi

    # Build
    log_info "Running production build..."
    VITE_API_URL="${PRODUCTION_URL}/api" npm run build

    if [ ! -d "dist" ]; then
        log_error "Build failed - dist directory not found"
        exit 1
    fi

    log_success "Frontend build complete"
fi

# ===========================================================================
# Deploy Backend
# ===========================================================================
if [ "$DEPLOY_BACKEND" = true ]; then
    log_info "Deploying backend PHP files..."

    # List of files to exclude from deployment
    EXCLUDE_FILES="sentry_test.php migrate_bokun_credentials.php database_check.php bokun_debug.php"

    # Make sure the target api folder exists (first staging deploy)
    ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "mkdir -p $PRODUCTION_PATH/api"

    # Deploy PHP files
    for file in public_html/api/*.php; do
        filename=$(basename "$file")

        # Skip excluded files
        if echo "$EXCLUDE_FILES" | grep -q "$filename"; then
            log_warning "Skipping: $filename (dev file)"
            continue
        fi

        scp -P $SSH_PORT "$file" "$SSH_USER@$SSH_HOST:$PRODUCTION_PATH/api/$filename" > /dev/null
        echo -n "."
    done
    echo ""

    # Set permissions
    ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "chmod 644 $PRODUCTION_PATH/api/*.php && chmod 755 $PRODUCTION_PATH/api"

    log_success "Backend deployment complete"
fi

# ===========================================================================
# Deploy Frontend
# ===========================================================================
if [ "$DEPLOY_FRONTEND" = true ]; then
    log_info "Deploying frontend assets..."

    # Check if rsync is available
    if command -v rsync &> /dev/null; then
        # Use rsync for efficient sync
        rsync -avz --delete \
            -e "ssh -p $SSH_PORT" \
            --exclude='.htaccess' \
            --exclude='api/' \
            --exclude='.env' \
            dist/ \
            "$SSH_USER@$SSH_HOST:$PRODUCTION_PATH/"
    else
        # Fallback to scp
        log_warning "rsync not found, using scp (slower)"
        scp -P $SSH_PORT -r dist/* "$SSH_USER@$SSH_HOST:$PRODUCTION_PATH/"
    fi

    log_success "Frontend deployment complete"
fi

# ===========================================================================
# Post-Deployment
# ===========================================================================

# Clear PHP cache (if available)
log_info "Clearing PHP cache..."
ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "
    if command -v php &> /dev/null; then
        php -r 'if(function_exists(\"opcache_reset\")) opcache_reset();' 2>/dev/null || true
    fi
" 2>/dev/null || true

# Wait for deployment to propagate
log_info "Waiting for deployment to propagate..."
sleep 5

# Run health check
health_check
HEALTH_STATUS=$?

# Cleanup old backups (keep last 5)
if [ "$CREATE_BACKUP" = true ]; then
    log_info "Cleaning up old backups (keeping last 5)..."
    ssh -p $SSH_PORT $SSH_USER@$SSH_HOST "
        cd $BACKUP_DIR 2>/dev/null && ls -t | tail -n +6 | xargs -r rm -rf
    " 2>/dev/null || true
fi

# ===========================================================================
# Summary
# ===========================================================================
echo ""
echo "========================================"
if [ $HEALTH_STATUS -eq 0 ]; then
    echo -e "${GREEN}🚀 DEPLOYMENT SUCCESSFUL${NC}"
else
    echo -e "${YELLOW}⚠️  DEPLOYMENT COMPLETE (with warnings)${NC}"
fi
echo "========================================"
echo "Target: $TARGET"
echo "URL: $PRODUCTION_URL"
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo "Frontend: $([ "$DEPLOY_FRONTEND" = true ] && echo "✅ Deployed" || echo "⏭️ Skipped")"
echo "Backend: $([ "$DEPLOY_BACKEND" = true ] && echo "✅ Deployed" || echo "⏭️ Skipped")"
[ "$CREATE_BACKUP" = true ] && echo "Backup: $BACKUP_NAME"
echo "========================================"

exit $HEALTH_STATUS
