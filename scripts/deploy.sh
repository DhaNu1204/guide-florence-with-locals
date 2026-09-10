#!/bin/bash
#
# Florence with Locals - deploy script (rewritten in plan step 0.2)
#
# Usage:
#   scripts/deploy.sh --target staging|production            # full deploy (backup, build, backend + frontend, health check)
#   scripts/deploy.sh --target <t> --frontend|--backend      # one half only
#   scripts/deploy.sh --target <t> --no-backup               # skip the pre-upload backup
#   scripts/deploy.sh --target <t> --check                   # health check only
#   scripts/deploy.sh --target <t> --restore-last-backup     # put the previous release back, then health check
#
# Guarantees: --target is mandatory; a dirty tree is refused; production only from master;
# backend = allowlist from `git ls-files` (never the working tree); frontend upload never
# deletes anything outside assets/ and never touches remote .env*, logs/, backups/, api/;
# public_html/api/VERSION carries the deployed git sha and /api/health.php must echo it back.
#
set -euo pipefail

# --- Fixed server coordinates (host/port/user only - no secrets; auth is by SSH key) ----------------
SSH_HOST="82.25.82.111"
SSH_PORT="65002"
SSH_USER="u803853690"
SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15)

# --- Backend allowlist rules: shipped = git-tracked *.php under public_html/api minus these ----------
DENY_BASENAME_RE='^((debug|test|check|fix|migrate)_.*\.php|.*_test\.php)$'   # same families .htaccess denies
DENY_DIRS=("tests")                                                          # never ship these api sub-folders
# bokun_cron.php IS shipped (it is the live cron entry point); .htaccess blocks it over HTTP.

# --- Output helpers --------------------------------------------------------------------------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }
die()         { log_error "$1"; exit 1; }
rssh()        { ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$@"; }

# --- Arguments -------------------------------------------------------------------------------------
TARGET=""; DEPLOY_FRONTEND=true; DEPLOY_BACKEND=true; CHECK_ONLY=false; CREATE_BACKUP=true; RESTORE=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --target)               TARGET="${2:-}"; shift 2 ;;
        --target=*)             TARGET="${1#*=}"; shift ;;
        --frontend)             DEPLOY_BACKEND=false; shift ;;
        --backend)              DEPLOY_FRONTEND=false; shift ;;
        --check)                CHECK_ONLY=true; shift ;;
        --no-backup)            CREATE_BACKUP=false; shift ;;
        --restore-last-backup)  RESTORE=true; shift ;;
        --help|-h)              sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)                      die "Unknown option: $1 (see --help)" ;;
    esac
done

# --- Resolve target: remote path, public URL, backup folder + prefix --------------------------------
case "$TARGET" in
    staging)
        REMOTE_PATH="/home/u803853690/domains/deetech.cc/public_html/stagingwithlocals"
        SITE_URL="https://stagingwithlocals.deetech.cc"
        BACKUP_DIR="/home/u803853690/domains/deetech.cc/backups/staging"
        BACKUP_PREFIX="stagingwithlocals_backup" ;;
    production)
        REMOTE_PATH="/home/u803853690/domains/deetech.cc/public_html/withlocals"
        SITE_URL="https://withlocals.deetech.cc"
        BACKUP_DIR="/home/u803853690/domains/deetech.cc/backups"
        BACKUP_PREFIX="withlocals_backup" ;;
    "")  die "No deploy target given. Use --target staging or --target production." ;;
    *)   die "Unknown target: '$TARGET' (expected: staging | production)" ;;
esac

# --- Health check: /api/health.php must be 200 and (unless told otherwise) echo the expected sha ----
health_check() {                       # $1 = expected sha or "" (skip sha comparison)
    local expected="${1:-}" body code sha ok=true
    log_info "Health check against $SITE_URL ..."
    body=$(curl -s -m 20 -w '\n%{http_code}' "$SITE_URL/api/health.php" || printf '\n000')
    code=${body##*$'\n'}; body=${body%$'\n'*}
    sha=$(echo "$body" | grep -o '"sha":"[^"]*"' | cut -d'"' -f4 || true)
    if [ "$code" = "200" ]; then
        log_success "GET /api/health.php -> 200 (sha ${sha:-?}, $(echo "$body" | grep -o '"db":[a-z]*' || echo 'db:?'))"
    else
        log_error "GET /api/health.php -> $code  body: ${body:0:160}"; ok=false
    fi
    if [ -n "$expected" ] && [ "$sha" != "$expected" ]; then log_error "Deployed sha '$sha' != expected '$expected'"; ok=false; fi
    code=$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$SITE_URL/" || echo 000)
    if [ "$code" = "200" ]; then log_success "GET / -> 200"; else log_error "GET / -> $code"; ok=false; fi
    [ "$ok" = true ]
}

# --- --check: health only ---------------------------------------------------------------------------
if [ "$CHECK_ONLY" = true ]; then health_check ""; exit $?; fi

# --- --restore-last-backup: copy the newest backup of this target back over the release -------------
if [ "$RESTORE" = true ]; then
    log_warning "Restoring the last backup for $TARGET ..."
    rssh "set -e; B=\$(ls -dt $BACKUP_DIR/${BACKUP_PREFIX}_* 2>/dev/null | head -1); [ -n \"\$B\" ] || { echo 'no backup found'; exit 1; }; echo \"restoring from \$B\"; cp -a \"\$B/.\" \"$REMOTE_PATH/\"; echo restored"
    health_check ""; exit $?
fi

# --- Preflight: repo root, clean tree, branch rules, sha ---------------------------------------------
[ -f package.json ] || die "package.json not found - run from the project root."
[ -z "$(git status --porcelain)" ] || die "Working tree is dirty - commit or stash first (deploys ship git-tracked files only)."
BRANCH=$(git rev-parse --abbrev-ref HEAD); SHA=$(git rev-parse HEAD)
if [ "$TARGET" = "production" ] && [ "$BRANCH" != "master" ]; then die "Production deploys only from 'master' (current: $BRANCH)."; fi
echo "========================================"
echo "Target : $TARGET -> $SITE_URL"
echo "Branch : $BRANCH"
echo "Sha    : $SHA"
echo "Parts  : backend=$DEPLOY_BACKEND frontend=$DEPLOY_FRONTEND backup=$CREATE_BACKUP"
echo "========================================"

# --- SSH connectivity --------------------------------------------------------------------------------
rssh "echo ok" >/dev/null 2>&1 || die "Cannot reach $SSH_USER@$SSH_HOST:$SSH_PORT by SSH key."
log_success "SSH OK"
REMOTE_TMP="$REMOTE_PATH/.deploy_tmp_$$"
trap 'rssh "rm -rf $REMOTE_TMP" >/dev/null 2>&1 || true' EXIT

# --- Backup: full copy of the current release, keep the last 5 of this target only --------------------
BACKUP_NAME=""
if [ "$CREATE_BACKUP" = true ]; then
    BACKUP_NAME="${BACKUP_PREFIX}_$(date +%Y%m%d_%H%M%S)"
    rssh "mkdir -p $BACKUP_DIR && if [ -d '$REMOTE_PATH' ]; then cp -a '$REMOTE_PATH' '$BACKUP_DIR/$BACKUP_NAME'; fi"
    log_success "Backup: $BACKUP_DIR/$BACKUP_NAME"
fi

# --- Frontend build: always npm ci, then vite build with the target API URL ---------------------------
if [ "$DEPLOY_FRONTEND" = true ]; then
    log_info "npm ci ..."; npm ci --silent
    log_info "vite build with VITE_API_URL=$SITE_URL/api ..."
    VITE_API_URL="$SITE_URL/api" npm run build --silent
    [ -f dist/index.html ] || die "Build failed - dist/index.html missing"
    [ -f dist/.htaccess ]  || die "dist/.htaccess missing (public/.htaccess must exist)"
    log_success "Frontend built"
fi

# --- Remote sync helper (runs on the server): copy only changed files from a staged tree --------------
# args: SRC DEST [DELETE_SCOPE]. DELETE_SCOPE (e.g. "assets") removes remote files under that folder
# that are not in SRC. Nothing else is ever deleted; unchanged files are left untouched (mtime kept).
read -r -d '' REMOTE_SYNC <<'SYNC' || true
SRC="$1"; DEST="$2"; SCOPE="${3:-}"; changed=0; same=0; removed=0
mkdir -p "$DEST"; cd "$SRC"
while IFS= read -r f; do
    f=${f#./}; mkdir -p "$DEST/$(dirname "$f")"
    if [ -f "$DEST/$f" ] && cmp -s "$f" "$DEST/$f"; then
        same=$((same+1))
    else
        cp -f "$f" "$DEST/$f"; chmod 644 "$DEST/$f"; changed=$((changed+1)); echo "  updated: $f"
    fi
done < <(find . -type f)
if [ -n "$SCOPE" ] && [ -d "$DEST/$SCOPE" ]; then
    while IFS= read -r f; do
        rel=${f#$DEST/}
        if [ ! -f "$SRC/$rel" ]; then rm -f "$f"; removed=$((removed+1)); echo "  removed stale: $rel"; fi
    done < <(find "$DEST/$SCOPE" -type f)
fi
echo "  sync: $changed updated, $same unchanged, $removed removed"
SYNC

# --- Backend: allowlist from git ls-files, print it, tar it up with VERSION and .htaccess, sync -------
if [ "$DEPLOY_BACKEND" = true ]; then
    log_info "Building backend allowlist from git ls-files ..."
    API_FILES=()
    while IFS= read -r path; do
        rel=${path#public_html/api/}; base=$(basename "$rel"); skip=false
        [[ "$base" =~ $DENY_BASENAME_RE ]] && skip=true
        for d in "${DENY_DIRS[@]}"; do [[ "$rel" == "$d/"* ]] && skip=true; done
        if [ "$skip" = true ]; then log_warning "Not shipped: $rel"; continue; fi
        API_FILES+=("$rel")
    done < <(git ls-files public_html/api | grep -E '\.php$')
    API_FILES+=(".htaccess")
    printf '%s\n' "$SHA" > public_html/api/VERSION           # gitignored release marker
    API_FILES+=("VERSION")
    echo "Backend files to upload (${#API_FILES[@]}):"; printf '  %s\n' "${API_FILES[@]}"
    rssh "mkdir -p $REMOTE_TMP/api $REMOTE_PATH/api"
    tar -C public_html/api -czf - "${API_FILES[@]}" | rssh "tar -xzf - -C $REMOTE_TMP/api"
    log_info "Syncing backend into $REMOTE_PATH/api ..."
    rssh "bash -s -- '$REMOTE_TMP/api' '$REMOTE_PATH/api'" <<< "$REMOTE_SYNC"
    log_success "Backend deployed"
fi

# --- Frontend: ship dist/ (incl. .htaccess); stale hashed assets removed, nothing else touched ----------
if [ "$DEPLOY_FRONTEND" = true ]; then
    rssh "mkdir -p $REMOTE_TMP/dist"
    tar -C dist -czf - . | rssh "tar -xzf - -C $REMOTE_TMP/dist"
    log_info "Syncing frontend into $REMOTE_PATH (stale-delete scoped to assets/) ..."
    rssh "bash -s -- '$REMOTE_TMP/dist' '$REMOTE_PATH' 'assets'" <<< "$REMOTE_SYNC"
    log_success "Frontend deployed"
fi

# --- Post-deploy: drop temp, reset opcache if possible, health check with sha, prune old backups ------
rssh "rm -rf $REMOTE_TMP; php -r 'if(function_exists(\"opcache_reset\")) opcache_reset();' 2>/dev/null || true"
sleep 3
set +e
if [ "$DEPLOY_BACKEND" = true ]; then health_check "$SHA"; else health_check ""; fi
HEALTH=$?
set -e
if [ "$CREATE_BACKUP" = true ]; then
    rssh "ls -dt $BACKUP_DIR/${BACKUP_PREFIX}_* 2>/dev/null | tail -n +6 | xargs -r rm -rf" || true
fi

# --- Summary ------------------------------------------------------------------------------------------
echo "========================================"
if [ $HEALTH -eq 0 ]; then
    echo -e "${GREEN}DEPLOYMENT SUCCESSFUL${NC}"
else
    echo -e "${RED}DEPLOYMENT FAILED HEALTH CHECK - consider: $0 --target $TARGET --restore-last-backup${NC}"
fi
echo "Target: $TARGET   URL: $SITE_URL   Sha: $SHA   Time: $(date '+%Y-%m-%d %H:%M:%S')"
[ -n "$BACKUP_NAME" ] && echo "Backup: $BACKUP_NAME"
echo "========================================"
exit $HEALTH
