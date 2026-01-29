# Reliability Engineer Skill - Florence With Locals

You are a reliability engineer for the Florence With Locals tour management system. You implement monitoring, error handling, deployment procedures, and ensure 100% uptime for the production system.

## Production Environment

- **URL:** https://withlocals.deetech.cc
- **Host:** Hostinger
- **SSH:** `ssh -p 65002 u803853690@82.25.82.111`
- **Web Root:** `/home/u803853690/domains/deetech.cc/public_html/withlocals`

---

## Backend Monitoring

### 1. Add Sentry to PHP Backend

**Install Sentry SDK via Composer (or manually):**

**Create `SentryHandler.php`:**
```php
<?php
/**
 * Sentry Error Handler for PHP Backend
 */
class SentryHandler {
    private static $initialized = false;
    private static $dsn;

    public static function init($dsn = null) {
        if (self::$initialized) return;

        self::$dsn = $dsn ?? EnvLoader::get('SENTRY_DSN');

        if (empty(self::$dsn)) {
            return; // Sentry not configured
        }

        // Register error handler
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$initialized = true;
    }

    public static function handleError($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        self::captureEvent([
            'level' => self::getSeverityLevel($severity),
            'message' => $message,
            'extra' => [
                'file' => $file,
                'line' => $line,
            ]
        ]);

        return false; // Continue to default handler
    }

    public static function handleException($exception) {
        self::captureException($exception);

        // Return generic error to user
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'An unexpected error occurred']);
    }

    public static function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::captureEvent([
                'level' => 'fatal',
                'message' => $error['message'],
                'extra' => [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ]
            ]);
        }
    }

    public static function captureException($exception, $context = []) {
        self::captureEvent([
            'level' => 'error',
            'message' => $exception->getMessage(),
            'extra' => array_merge([
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ], $context)
        ]);
    }

    public static function captureMessage($message, $level = 'info', $context = []) {
        self::captureEvent([
            'level' => $level,
            'message' => $message,
            'extra' => $context
        ]);
    }

    private static function captureEvent($event) {
        if (empty(self::$dsn)) return;

        // Parse DSN
        $parsed = parse_url(self::$dsn);
        $projectId = trim($parsed['path'], '/');
        $publicKey = $parsed['user'];

        // Build event payload
        $payload = [
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => gmdate('Y-m-d\TH:i:s'),
            'platform' => 'php',
            'level' => $event['level'] ?? 'error',
            'logger' => 'florence-api',
            'message' => $event['message'],
            'extra' => $event['extra'] ?? [],
            'tags' => [
                'environment' => EnvLoader::get('APP_ENV', 'production'),
                'php_version' => PHP_VERSION,
            ],
            'request' => [
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            ]
        ];

        // Send to Sentry (non-blocking)
        $url = "https://sentry.io/api/$projectId/store/";
        $headers = [
            "Content-Type: application/json",
            "X-Sentry-Auth: Sentry sentry_version=7, sentry_key=$publicKey"
        ];

        // Use cURL for async-like behavior
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function getSeverityLevel($severity) {
        $levels = [
            E_ERROR => 'fatal',
            E_WARNING => 'warning',
            E_PARSE => 'fatal',
            E_NOTICE => 'info',
            E_CORE_ERROR => 'fatal',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'fatal',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info',
        ];
        return $levels[$severity] ?? 'error';
    }
}
```

**Integrate into `config.php`:**
```php
<?php
require_once __DIR__ . '/EnvLoader.php';
EnvLoader::load();

require_once __DIR__ . '/SentryHandler.php';
SentryHandler::init();

// Rest of config...
```

### 2. Health Check Endpoint

**Create `health.php`:**
```php
<?php
/**
 * Health Check Endpoint
 * URL: /api/health.php
 *
 * Returns system health status for monitoring
 */
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'checks' => []
];

// Check 1: Database connection
try {
    $dbStart = microtime(true);
    $result = $conn->query("SELECT 1");
    $dbTime = round((microtime(true) - $dbStart) * 1000, 2);

    $health['checks']['database'] = [
        'status' => $result ? 'healthy' : 'unhealthy',
        'response_time_ms' => $dbTime
    ];
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => 'Connection failed'
    ];
    $health['status'] = 'unhealthy';
}

// Check 2: Bokun API connectivity (lightweight test)
try {
    $bokunStart = microtime(true);
    $ch = curl_init('https://api.bokun.is');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY => true
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $bokunTime = round((microtime(true) - $bokunStart) * 1000, 2);

    $health['checks']['bokun_api'] = [
        'status' => $httpCode > 0 ? 'reachable' : 'unreachable',
        'response_time_ms' => $bokunTime
    ];
} catch (Exception $e) {
    $health['checks']['bokun_api'] = [
        'status' => 'unreachable',
        'error' => 'Connection failed'
    ];
}

// Check 3: Disk space
$freeSpace = disk_free_space('/');
$totalSpace = disk_total_space('/');
$usedPercent = round((1 - $freeSpace / $totalSpace) * 100, 1);

$health['checks']['disk'] = [
    'status' => $usedPercent < 90 ? 'healthy' : 'warning',
    'used_percent' => $usedPercent
];

if ($usedPercent >= 95) {
    $health['status'] = 'unhealthy';
}

// Check 4: Last Bokun sync
try {
    $syncResult = $conn->query("SELECT last_sync FROM bokun_config LIMIT 1");
    $lastSync = $syncResult->fetch_assoc()['last_sync'] ?? null;

    $syncAge = $lastSync ? (time() - strtotime($lastSync)) / 60 : null;

    $health['checks']['bokun_sync'] = [
        'status' => $syncAge && $syncAge < 30 ? 'healthy' : 'stale',
        'last_sync' => $lastSync,
        'minutes_ago' => $syncAge ? round($syncAge) : null
    ];
} catch (Exception $e) {
    $health['checks']['bokun_sync'] = ['status' => 'unknown'];
}

// Set HTTP status based on health
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
}

echo json_encode($health, JSON_PRETTY_PRINT);
```

### 3. Database Connection Retry Logic

**Update `config.php` with retry:**
```php
<?php
function createDatabaseConnection($retries = 3, $delay = 1) {
    global $db_host, $db_name, $db_user, $db_pass;

    $attempt = 0;
    $lastError = null;

    while ($attempt < $retries) {
        try {
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

            if ($conn->connect_error) {
                throw new Exception($conn->connect_error);
            }

            $conn->set_charset("utf8mb4");
            return $conn;

        } catch (Exception $e) {
            $lastError = $e->getMessage();
            $attempt++;

            if ($attempt < $retries) {
                error_log("Database connection attempt $attempt failed: $lastError. Retrying...");
                sleep($delay);
                $delay *= 2; // Exponential backoff
            }
        }
    }

    error_log("Database connection failed after $retries attempts: $lastError");
    SentryHandler::captureMessage("Database connection failed", 'fatal', [
        'attempts' => $retries,
        'error' => $lastError
    ]);

    http_response_code(503);
    die(json_encode(['error' => 'Service temporarily unavailable']));
}

$conn = createDatabaseConnection();
```

---

## Frontend Reliability

### 1. Error Boundaries

**Create `src/components/ErrorBoundary.jsx`:**
```jsx
import React from 'react';
import * as Sentry from '@sentry/react';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    Sentry.captureException(error, {
      extra: {
        componentStack: errorInfo.componentStack,
      },
    });
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50">
          <div className="bg-white p-8 rounded-lg shadow-lg max-w-md text-center">
            <div className="w-16 h-16 mx-auto mb-4 bg-red-100 rounded-full
                          flex items-center justify-center">
              <svg className="w-8 h-8 text-red-600" fill="none" stroke="currentColor"
                   viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667
                         1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34
                         16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h2 className="text-xl font-semibold text-gray-900 mb-2">
              Something went wrong
            </h2>
            <p className="text-gray-600 mb-6">
              We've been notified and are working on it. Please try refreshing the page.
            </p>
            <div className="space-y-3">
              <button
                onClick={() => window.location.reload()}
                className="w-full py-2 px-4 bg-blue-600 text-white rounded-lg
                           hover:bg-blue-700 transition-colors"
              >
                Refresh Page
              </button>
              <button
                onClick={() => window.location.href = '/'}
                className="w-full py-2 px-4 border border-gray-300 text-gray-700
                           rounded-lg hover:bg-gray-50 transition-colors"
              >
                Go to Dashboard
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
```

### 2. API Retry Logic

**Update `src/services/mysqlDB.js`:**
```javascript
// Retry configuration
const RETRY_CONFIG = {
  maxRetries: 3,
  baseDelay: 1000, // 1 second
  maxDelay: 10000, // 10 seconds
};

// Retry wrapper for API calls
async function withRetry(fn, options = {}) {
  const { maxRetries, baseDelay, maxDelay } = { ...RETRY_CONFIG, ...options };
  let lastError;

  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error;

      // Don't retry on 4xx errors (client errors)
      if (error.response?.status >= 400 && error.response?.status < 500) {
        throw error;
      }

      // Don't retry on last attempt
      if (attempt === maxRetries - 1) {
        throw error;
      }

      // Calculate delay with exponential backoff
      const delay = Math.min(baseDelay * Math.pow(2, attempt), maxDelay);

      console.log(`API call failed, retrying in ${delay}ms (attempt ${attempt + 1}/${maxRetries})`);

      await new Promise(resolve => setTimeout(resolve, delay));
    }
  }

  throw lastError;
}

// Usage in API calls
export const getTours = async (forceRefresh = false, page = 1, perPage = 50, filters = {}) => {
  // ... cache logic ...

  return withRetry(async () => {
    const response = await axios.get(addCacheBuster(url));
    return response.data;
  });
};
```

### 3. Offline Fallback Enhancement

**Update offline handling in `mysqlDB.js`:**
```javascript
// Check if online
const isOnline = () => navigator.onLine;

// Listen for online/offline events
window.addEventListener('online', () => {
  console.log('Back online, syncing data...');
  // Trigger sync
  syncPendingChanges();
});

window.addEventListener('offline', () => {
  console.log('Offline mode activated');
});

// Queue for offline changes
const pendingChanges = {
  add: (operation) => {
    const queue = JSON.parse(localStorage.getItem('pendingChanges') || '[]');
    queue.push({ ...operation, timestamp: Date.now() });
    localStorage.setItem('pendingChanges', JSON.stringify(queue));
  },

  get: () => JSON.parse(localStorage.getItem('pendingChanges') || '[]'),

  clear: () => localStorage.removeItem('pendingChanges'),
};

// Sync pending changes when back online
async function syncPendingChanges() {
  const changes = pendingChanges.get();
  if (changes.length === 0) return;

  for (const change of changes) {
    try {
      switch (change.type) {
        case 'updateTour':
          await updateTour(change.id, change.data);
          break;
        case 'markPaid':
          await updateTourPaidStatus(change.id, change.paid);
          break;
        // Add other operations
      }
    } catch (error) {
      console.error('Failed to sync change:', change);
    }
  }

  pendingChanges.clear();
}
```

---

## Deployment Procedures

### 1. GitHub Actions Workflow

**Create `.github/workflows/deploy.yml`:**
```yaml
name: Deploy to Hostinger

on:
  push:
    branches: [main]
  workflow_dispatch:
    inputs:
      deploy_type:
        description: 'Deploy type (frontend/backend/both)'
        required: true
        default: 'both'

env:
  SSH_HOST: 82.25.82.111
  SSH_PORT: 65002
  SSH_USER: u803853690
  DEPLOY_PATH: /home/u803853690/domains/deetech.cc/public_html/withlocals

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'
          cache-dependency-path: guide-florence-with-locals/package-lock.json

      - name: Install dependencies
        working-directory: guide-florence-with-locals
        run: npm ci

      - name: Build frontend
        working-directory: guide-florence-with-locals
        run: npm run build
        env:
          VITE_API_URL: /api

      - name: Upload build artifact
        uses: actions/upload-artifact@v4
        with:
          name: frontend-build
          path: guide-florence-with-locals/dist/

  deploy-frontend:
    needs: build
    runs-on: ubuntu-latest
    if: github.event.inputs.deploy_type != 'backend'
    steps:
      - name: Download build artifact
        uses: actions/download-artifact@v4
        with:
          name: frontend-build
          path: dist/

      - name: Deploy frontend via FTP
        uses: SamKirkland/FTP-Deploy-Action@v4.3.4
        with:
          server: ${{ env.SSH_HOST }}
          username: ${{ secrets.FTP_USER }}
          password: ${{ secrets.FTP_PASSWORD }}
          local-dir: dist/
          server-dir: ${{ env.DEPLOY_PATH }}/

  deploy-backend:
    runs-on: ubuntu-latest
    if: github.event.inputs.deploy_type != 'frontend'
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Deploy backend via SSH
        uses: appleboy/scp-action@v0.1.7
        with:
          host: ${{ env.SSH_HOST }}
          port: ${{ env.SSH_PORT }}
          username: ${{ env.SSH_USER }}
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          source: "guide-florence-with-locals/public_html/api/*.php"
          target: ${{ env.DEPLOY_PATH }}/api/
          strip_components: 3

  notify:
    needs: [deploy-frontend, deploy-backend]
    runs-on: ubuntu-latest
    if: always()
    steps:
      - name: Notify deployment status
        run: |
          echo "Deployment completed!"
          # Add Slack/Discord notification here
```

### 2. Pre-Deployment Checklist Script

**Create `scripts/pre-deploy-check.sh`:**
```bash
#!/bin/bash
# Pre-deployment checklist for Florence With Locals

echo "Running pre-deployment checks..."
ERRORS=0

# Check 1: No console.log in production code
echo -n "Checking for console.log... "
if grep -r "console.log" src/ --include="*.jsx" --include="*.js" | grep -v "// DEBUG" > /dev/null; then
    echo "WARN: Found console.log statements"
    grep -r "console.log" src/ --include="*.jsx" --include="*.js" | head -5
else
    echo "OK"
fi

# Check 2: No error_log in PHP (except Logger.php)
echo -n "Checking for PHP error_log... "
if grep -r "error_log" public_html/api/ --include="*.php" | grep -v "Logger.php" > /dev/null; then
    echo "WARN: Found error_log statements"
else
    echo "OK"
fi

# Check 3: No hardcoded credentials
echo -n "Checking for hardcoded credentials... "
if grep -rE "(password|secret|api_key)\s*=\s*['\"][^'\"]+['\"]" public_html/api/ --include="*.php" | grep -v "\.env" > /dev/null; then
    echo "FAIL: Possible hardcoded credentials found"
    ERRORS=$((ERRORS + 1))
else
    echo "OK"
fi

# Check 4: Build succeeds
echo -n "Checking build... "
cd guide-florence-with-locals
if npm run build > /dev/null 2>&1; then
    echo "OK"
else
    echo "FAIL: Build failed"
    ERRORS=$((ERRORS + 1))
fi
cd ..

# Check 5: Environment file exists
echo -n "Checking .env file... "
if [ -f "public_html/api/.env" ] || [ -f "guide-florence-with-locals/public_html/api/.env" ]; then
    echo "OK"
else
    echo "WARN: .env file not found (may use server env vars)"
fi

# Summary
echo ""
if [ $ERRORS -gt 0 ]; then
    echo "FAILED: $ERRORS critical errors found"
    exit 1
else
    echo "PASSED: All checks passed"
    exit 0
fi
```

### 3. Database Backup Script

**Create `scripts/backup-db.sh`:**
```bash
#!/bin/bash
# Database backup script for Florence With Locals

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/home/u803853690/backups/database"
DB_NAME="u803853690_florence_guides"

# Create backup directory if not exists
mkdir -p $BACKUP_DIR

# Create backup
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME > "$BACKUP_DIR/backup_$DATE.sql"

# Compress backup
gzip "$BACKUP_DIR/backup_$DATE.sql"

# Keep only last 7 days of backups
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +7 -delete

echo "Backup completed: backup_$DATE.sql.gz"
```

### 4. Rollback Procedure

**Create `scripts/rollback.sh`:**
```bash
#!/bin/bash
# Rollback script for Florence With Locals

DEPLOY_PATH="/home/u803853690/domains/deetech.cc/public_html/withlocals"
BACKUP_PATH="/home/u803853690/backups/releases"

# List available backups
echo "Available backups:"
ls -la $BACKUP_PATH

# Get backup to restore
read -p "Enter backup folder name to restore: " BACKUP_NAME

if [ -d "$BACKUP_PATH/$BACKUP_NAME" ]; then
    # Create backup of current version
    CURRENT_BACKUP="pre_rollback_$(date +%Y%m%d_%H%M%S)"
    cp -r $DEPLOY_PATH "$BACKUP_PATH/$CURRENT_BACKUP"

    # Restore from backup
    rm -rf $DEPLOY_PATH/*
    cp -r "$BACKUP_PATH/$BACKUP_NAME/"* $DEPLOY_PATH/

    echo "Rollback completed to $BACKUP_NAME"
    echo "Previous version backed up to $CURRENT_BACKUP"
else
    echo "Backup not found: $BACKUP_NAME"
    exit 1
fi
```

---

## Bokun Sync Reliability

### 1. Webhook Signature Verification

**Update `bokun_webhook.php`:**
```php
<?php
require_once 'config.php';

// Verify webhook signature (if Bokun provides one)
function verifyWebhookSignature($payload, $signature) {
    $secret = EnvLoader::get('BOKUN_WEBHOOK_SECRET');
    if (empty($secret)) {
        return true; // Skip if not configured
    }

    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}

// Get raw payload
$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_BOKUN_SIGNATURE'] ?? '';

// Verify signature
if (!verifyWebhookSignature($rawPayload, $signature)) {
    error_log("Webhook signature verification failed");
    http_response_code(401);
    die(json_encode(['error' => 'Invalid signature']));
}

// Process webhook...
```

### 2. Sync Failure Recovery

**Create retry queue in `bokun_sync.php`:**
```php
<?php
// Failed sync queue table
function createFailedSyncQueue($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS sync_retry_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id VARCHAR(100) NOT NULL,
            payload JSON,
            retry_count INT DEFAULT 0,
            last_error TEXT,
            next_retry_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY booking_id (booking_id)
        )
    ");
}

// Queue failed booking for retry
function queueFailedBooking($bookingId, $payload, $error) {
    global $conn;

    $retryMinutes = 5; // Retry in 5 minutes
    $nextRetry = date('Y-m-d H:i:s', strtotime("+$retryMinutes minutes"));

    $stmt = $conn->prepare("
        INSERT INTO sync_retry_queue (booking_id, payload, last_error, next_retry_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            retry_count = retry_count + 1,
            last_error = VALUES(last_error),
            next_retry_at = DATE_ADD(NOW(), INTERVAL POW(2, retry_count) * 5 MINUTE)
    ");

    $payloadJson = json_encode($payload);
    $stmt->bind_param("ssss", $bookingId, $payloadJson, $error, $nextRetry);
    $stmt->execute();
}

// Process retry queue (call via cron)
function processRetryQueue() {
    global $conn;

    $result = $conn->query("
        SELECT * FROM sync_retry_queue
        WHERE next_retry_at <= NOW()
        AND retry_count < 5
        ORDER BY next_retry_at
        LIMIT 10
    ");

    while ($row = $result->fetch_assoc()) {
        $payload = json_decode($row['payload'], true);

        try {
            // Attempt to sync
            $tourData = transformBookingToTour($payload);
            saveTourToDatabase($tourData);

            // Success - remove from queue
            $conn->query("DELETE FROM sync_retry_queue WHERE id = {$row['id']}");

        } catch (Exception $e) {
            // Failed again - update retry count
            queueFailedBooking($row['booking_id'], $payload, $e->getMessage());
        }
    }
}
```

---

## Monitoring Dashboard Metrics

### Key Metrics to Track

```javascript
// Frontend metrics to capture
const metrics = {
  // Page load time
  pageLoadTime: performance.timing.loadEventEnd - performance.timing.navigationStart,

  // API response times
  apiLatency: {},

  // Error counts
  errorCount: 0,

  // User actions
  syncTriggered: 0,
  toursViewed: 0,
  paymentsRecorded: 0,
};

// Track API latency
axios.interceptors.response.use(
  (response) => {
    const duration = Date.now() - response.config.metadata?.startTime;
    metrics.apiLatency[response.config.url] = duration;
    return response;
  }
);

axios.interceptors.request.use(
  (config) => {
    config.metadata = { startTime: Date.now() };
    return config;
  }
);
```

### Health Check Monitoring (External)

Set up uptime monitoring (e.g., UptimeRobot, Pingdom):

```
URL: https://withlocals.deetech.cc/api/health.php
Check interval: 5 minutes
Alert on: HTTP status != 200 or response time > 5s
Alert channels: Email, SMS
```
