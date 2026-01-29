# Security Hardener Skill - Florence With Locals

You are a security specialist for the Florence With Locals tour management system. You address vulnerabilities identified in the security audit and implement hardening measures for both the PHP backend and React frontend.

## Audit Findings Summary

| Issue | Risk | Location |
|-------|------|----------|
| Hardcoded DB credentials | Medium | `config.php` |
| Legacy plaintext password support | High | `auth.php` |
| Unencrypted Bokun API credentials | Medium | `bokun_config` table |
| Debug display enabled | Low | Various PHP files |
| Wide CORS origin | Low | `config.php` |
| No security headers | Medium | `config.php` |
| No input length validation | Low | Various endpoints |

---

## PHP Backend Security Fixes

### 1. Move DB Credentials to Environment Variables

**Create `.env` file in `public_html/api/`:**
```env
# Database Configuration
DB_HOST=localhost
DB_NAME=florence_guides
DB_USER=your_db_user
DB_PASS=your_db_password

# Bokun API Configuration
BOKUN_ACCESS_KEY=your_access_key
BOKUN_SECRET_KEY=your_secret_key
BOKUN_VENDOR_ID=96929

# Application Settings
APP_ENV=production
APP_DEBUG=false
ALLOWED_ORIGINS=https://withlocals.deetech.cc,https://localhost:5173

# Encryption Key (generate with: openssl rand -base64 32)
ENCRYPTION_KEY=your_32_char_base64_key_here
```

**Create `EnvLoader.php` (if not exists or update existing):**
```php
<?php
/**
 * Environment Variable Loader
 * Loads variables from .env file securely
 */
class EnvLoader {
    private static $loaded = false;
    private static $vars = [];

    public static function load($path = null) {
        if (self::$loaded) return;

        $envPath = $path ?? __DIR__ . '/.env';

        if (!file_exists($envPath)) {
            // In production, use actual environment variables
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) continue;

            // Parse KEY=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                $value = trim($value, '"\'');

                self::$vars[$key] = $value;
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null) {
        // Try local cache first
        if (isset(self::$vars[$key])) {
            return self::$vars[$key];
        }

        // Try environment
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        // Try $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        return $default;
    }

    public static function require($key) {
        $value = self::get($key);
        if ($value === null) {
            throw new Exception("Required environment variable '$key' is not set");
        }
        return $value;
    }
}
```

**Update `config.php` to use environment variables:**
```php
<?php
/**
 * Configuration - Florence With Locals
 * SECURITY: Uses environment variables for sensitive data
 */

// Load environment variables
require_once __DIR__ . '/EnvLoader.php';
EnvLoader::load();

// Determine environment
$isProduction = EnvLoader::get('APP_ENV', 'production') === 'production';
$isDebug = EnvLoader::get('APP_DEBUG', 'false') === 'true';

// Error reporting based on environment
if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Database configuration from environment
$db_host = EnvLoader::require('DB_HOST');
$db_name = EnvLoader::require('DB_NAME');
$db_user = EnvLoader::require('DB_USER');
$db_pass = EnvLoader::require('DB_PASS');

// Create database connection with error handling
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}
$conn->set_charset("utf8mb4");

// CORS Configuration - Restricted origins
$allowedOrigins = explode(',', EnvLoader::get('ALLOWED_ORIGINS', 'https://withlocals.deetech.cc'));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (!$isProduction) {
    // Allow any origin in development
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if ($isProduction) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    // CSP - Adjust based on your needs
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self' https://api.bokun.is");
}

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

### 2. Remove Legacy Plaintext Password Support

**Update `auth.php` - Remove ALLOW_LEGACY_AUTH:**
```php
<?php
require_once 'config.php';

// SECURITY: Rate limiting for login attempts
function checkRateLimit($conn, $username, $ip) {
    // Clean old attempts (older than 5 minutes)
    $conn->query("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    // Check recent attempts
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                            WHERE (username = ? OR ip_address = ?)
                            AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->bind_param("ss", $username, $ip);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result['attempts'] < 5; // Allow max 5 attempts per 5 minutes
}

function recordLoginAttempt($conn, $username, $ip, $success) {
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, success, created_at)
                            VALUES (?, ?, ?, NOW())");
    $successInt = $success ? 1 : 0;
    $stmt->bind_param("ssi", $username, $ip, $successInt);
    $stmt->execute();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Input validation
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username and password required']);
        exit();
    }

    if (strlen($username) > 100 || strlen($password) > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input length']);
        exit();
    }

    // Rate limit check
    if (!checkRateLimit($conn, $username, $ip)) {
        recordLoginAttempt($conn, $username, $ip, false);
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many login attempts. Please wait 5 minutes.'
        ]);
        exit();
    }

    // Get user from database
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        recordLoginAttempt($conn, $username, $ip, false);
        // Use same message to prevent username enumeration
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit();
    }

    // SECURITY: Only use password_verify - NO LEGACY PLAINTEXT
    if (!password_verify($password, $user['password'])) {
        recordLoginAttempt($conn, $username, $ip, false);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit();
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Store session
    $stmt = $conn->prepare("INSERT INTO sessions (session_id, user_id, token, expires_at, created_at)
                            VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE token = ?, expires_at = ?");
    $sessionId = bin2hex(random_bytes(16));
    $stmt->bind_param("sissss", $sessionId, $user['id'], $token, $expiresAt, $token, $expiresAt);
    $stmt->execute();

    // Record successful login
    recordLoginAttempt($conn, $username, $ip, true);

    echo json_encode([
        'success' => true,
        'token' => $token,
        'username' => $user['username'],
        'role' => $user['role'],
        'expires_at' => $expiresAt
    ]);
    exit();
}

// Handle token verification
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'verify') {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);

    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['valid' => false, 'message' => 'No token provided']);
        exit();
    }

    // Validate token
    $stmt = $conn->prepare("SELECT s.user_id, u.username, u.role
                            FROM sessions s
                            JOIN users u ON s.user_id = u.id
                            WHERE s.token = ? AND s.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();

    if ($session) {
        echo json_encode([
            'valid' => true,
            'username' => $session['username'],
            'role' => $session['role']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['valid' => false, 'message' => 'Invalid or expired token']);
    }
    exit();
}
```

### 3. Encrypt Bokun API Credentials

**Create `Encryption.php`:**
```php
<?php
/**
 * Encryption helper for sensitive data
 */
class Encryption {
    private static $key;

    public static function init() {
        self::$key = base64_decode(EnvLoader::require('ENCRYPTION_KEY'));
        if (strlen(self::$key) < 32) {
            throw new Exception('Encryption key must be at least 32 bytes');
        }
    }

    public static function encrypt($data) {
        if (empty($data)) return '';

        if (!self::$key) self::init();

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $data,
            'AES-256-CBC',
            self::$key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($data) {
        if (empty($data)) return '';

        if (!self::$key) self::init();

        $decoded = base64_decode($data);
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        return openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            self::$key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
}
```

**Update `bokun_sync.php` to encrypt/decrypt credentials:**
```php
<?php
require_once 'config.php';
require_once 'Encryption.php';
require_once 'BokunAPI.php';

function getBokunConfig() {
    global $conn;

    $result = $conn->query("SELECT * FROM bokun_config LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $config = $result->fetch_assoc();

        // Decrypt sensitive fields
        if (!empty($config['api_key'])) {
            $config['access_key'] = Encryption::decrypt($config['api_key']);
        }
        if (!empty($config['api_secret'])) {
            $config['secret_key'] = Encryption::decrypt($config['api_secret']);
        }

        return $config;
    }
    return null;
}

function saveBokunConfig($data) {
    global $conn;

    // Encrypt sensitive data before storing
    $accessKey = Encryption::encrypt($data['access_key'] ?? '');
    $secretKey = Encryption::encrypt($data['secret_key'] ?? '');
    $vendorId = $data['vendor_id'] ?? '';
    $syncEnabled = isset($data['sync_enabled']) ? 1 : 0;

    // ... rest of save logic
}
```

### 4. Add Security Headers to All Endpoints

**Create `Middleware.php`:**
```php
<?php
/**
 * Security middleware for all API endpoints
 */
class Middleware {
    public static function applySecurityHeaders() {
        // Prevent MIME type sniffing
        header("X-Content-Type-Options: nosniff");

        // Prevent clickjacking
        header("X-Frame-Options: DENY");

        // XSS protection
        header("X-XSS-Protection: 1; mode=block");

        // Referrer policy
        header("Referrer-Policy: strict-origin-when-cross-origin");

        // HTTPS only in production
        if (EnvLoader::get('APP_ENV') === 'production') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }
    }

    public static function validateContentType() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') === false) {
                http_response_code(415);
                echo json_encode(['error' => 'Content-Type must be application/json']);
                exit();
            }
        }
    }

    public static function validateRequestSize($maxBytes = 1048576) {
        // Default 1MB limit
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        if ($contentLength > $maxBytes) {
            http_response_code(413);
            echo json_encode(['error' => 'Request body too large']);
            exit();
        }
    }
}
```

### 5. Input Length Validation

**Update `Validator.php`:**
```php
<?php
/**
 * Input validation helper
 */
class Validator {
    private static $errors = [];

    // Field length limits
    const LIMITS = [
        'username' => 50,
        'password' => 255,
        'email' => 100,
        'name' => 100,
        'title' => 200,
        'description' => 5000,
        'phone' => 20,
        'notes' => 10000,
        'default' => 1000
    ];

    public static function sanitizeString($value, $field = 'default') {
        $value = trim($value);
        $maxLength = self::LIMITS[$field] ?? self::LIMITS['default'];

        if (strlen($value) > $maxLength) {
            self::$errors[] = "Field '$field' exceeds maximum length of $maxLength characters";
            $value = substr($value, 0, $maxLength);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail($email) {
        $email = self::sanitizeString($email, 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::$errors[] = "Invalid email format";
            return false;
        }
        return $email;
    }

    public static function validateInt($value, $min = null, $max = null) {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            self::$errors[] = "Invalid integer value";
            return false;
        }
        if ($min !== null && $value < $min) {
            self::$errors[] = "Value must be at least $min";
            return false;
        }
        if ($max !== null && $value > $max) {
            self::$errors[] = "Value must not exceed $max";
            return false;
        }
        return $value;
    }

    public static function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            self::$errors[] = "Invalid date format (expected YYYY-MM-DD)";
            return false;
        }
        return $date;
    }

    public static function validateTime($time) {
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $time)) {
            self::$errors[] = "Invalid time format";
            return false;
        }
        return $time;
    }

    public static function getErrors() {
        return self::$errors;
    }

    public static function hasErrors() {
        return !empty(self::$errors);
    }

    public static function clearErrors() {
        self::$errors = [];
    }
}
```

---

## React Frontend Security Fixes

### 1. Secure Token Storage

**Update `AuthContext.jsx`:**
```jsx
import { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext(null);

// Secure token storage with expiry check
const secureStorage = {
  setToken: (token, expiresAt) => {
    try {
      const data = JSON.stringify({ token, expiresAt });
      sessionStorage.setItem('auth_session', data);
      // Also store in memory for current session
      window.__authToken = token;
    } catch (e) {
      console.error('Failed to store token');
    }
  },

  getToken: () => {
    try {
      // Prefer memory storage
      if (window.__authToken) return window.__authToken;

      const data = sessionStorage.getItem('auth_session');
      if (!data) return null;

      const { token, expiresAt } = JSON.parse(data);

      // Check if expired
      if (new Date(expiresAt) < new Date()) {
        secureStorage.clearToken();
        return null;
      }

      window.__authToken = token;
      return token;
    } catch (e) {
      return null;
    }
  },

  clearToken: () => {
    sessionStorage.removeItem('auth_session');
    localStorage.removeItem('token'); // Clear legacy storage
    localStorage.removeItem('userRole');
    localStorage.removeItem('userName');
    delete window.__authToken;
  }
};

export const AuthProvider = ({ children }) => {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [userRole, setUserRole] = useState(null);
  const [userName, setUserName] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const verifyToken = async () => {
      const token = secureStorage.getToken();
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        const API_BASE = import.meta.env.VITE_API_URL || '/api';
        const response = await fetch(`${API_BASE}/auth.php?action=verify`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });

        if (response.ok) {
          const data = await response.json();
          if (data.valid) {
            setIsAuthenticated(true);
            setUserRole(data.role);
            setUserName(data.username);
          } else {
            secureStorage.clearToken();
          }
        } else {
          secureStorage.clearToken();
        }
      } catch (error) {
        console.error('Token verification failed');
        secureStorage.clearToken();
      }
      setLoading(false);
    };

    verifyToken();
  }, []);

  const login = async (username, password) => {
    // Input validation
    if (!username || !password) {
      return { success: false, message: 'Username and password required' };
    }

    if (username.length > 100 || password.length > 255) {
      return { success: false, message: 'Invalid input' };
    }

    try {
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      const response = await fetch(`${API_BASE}/auth.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username, password }),
      });

      const data = await response.json();

      if (data.success) {
        secureStorage.setToken(data.token, data.expires_at);
        setUserRole(data.role);
        setUserName(data.username);
        setIsAuthenticated(true);
        return { success: true };
      } else {
        return { success: false, message: data.message };
      }
    } catch (error) {
      return { success: false, message: 'Login failed. Please try again.' };
    }
  };

  const logout = () => {
    secureStorage.clearToken();
    setIsAuthenticated(false);
    setUserRole(null);
    setUserName(null);
  };

  // ... rest of context
};
```

### 2. XSS Prevention Patterns

**Create `src/utils/sanitize.js`:**
```javascript
/**
 * Input sanitization utilities
 */

// Sanitize string for display (React already escapes, but for extra safety)
export const sanitizeString = (str) => {
  if (typeof str !== 'string') return '';

  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#x27;');
};

// Sanitize URL (prevent javascript: protocol)
export const sanitizeUrl = (url) => {
  if (typeof url !== 'string') return '';

  const sanitized = url.trim().toLowerCase();

  // Block dangerous protocols
  const dangerousProtocols = ['javascript:', 'data:', 'vbscript:'];
  if (dangerousProtocols.some(p => sanitized.startsWith(p))) {
    return '';
  }

  return url;
};

// Validate and sanitize email
export const validateEmail = (email) => {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(email)) {
    return null;
  }
  return email.toLowerCase().trim();
};

// Validate phone number (basic)
export const validatePhone = (phone) => {
  // Allow digits, spaces, dashes, plus, parentheses
  const cleaned = phone.replace(/[^\d\s\-+()]/g, '');
  if (cleaned.length < 6 || cleaned.length > 20) {
    return null;
  }
  return cleaned;
};
```

### 3. Secure API Calls

**Update `src/services/mysqlDB.js`:**
```javascript
import axios from 'axios';
import * as Sentry from '@sentry/react';

const API_BASE_URL = import.meta.env.VITE_API_URL || '/api';

// Create axios instance with security defaults
const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Request interceptor - Add auth token
api.interceptors.request.use(
  (config) => {
    // Get token from secure storage
    const authData = sessionStorage.getItem('auth_session');
    if (authData) {
      try {
        const { token } = JSON.parse(authData);
        config.headers.Authorization = `Bearer ${token}`;
      } catch (e) {
        // Invalid auth data
      }
    }

    // Add CSRF protection header (if server implements it)
    config.headers['X-Requested-With'] = 'XMLHttpRequest';

    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor - Handle errors
api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Log to Sentry (without sensitive data)
    Sentry.captureException(error, {
      tags: {
        api_url: error.config?.url,
        status: error.response?.status,
      },
    });

    // Handle 401 - Unauthorized
    if (error.response?.status === 401) {
      sessionStorage.removeItem('auth_session');
      window.location.href = '/login';
    }

    // Handle 429 - Rate limited
    if (error.response?.status === 429) {
      const retryAfter = error.response.headers['retry-after'] || 60;
      console.warn(`Rate limited. Retry after ${retryAfter} seconds`);
    }

    return Promise.reject(error);
  }
);

export default api;
```

---

## Security Checklist

### Backend
- [ ] Environment variables for all credentials
- [ ] No `display_errors` in production
- [ ] HTTPS enforced (HSTS header)
- [ ] Security headers on all responses
- [ ] Rate limiting on auth endpoints
- [ ] Password hashing only (no plaintext)
- [ ] Encrypted API credentials in database
- [ ] Input validation on all endpoints
- [ ] Prepared statements for all SQL

### Frontend
- [ ] Secure token storage (sessionStorage, not localStorage)
- [ ] Token expiry handling
- [ ] XSS prevention (sanitize user input)
- [ ] HTTPS-only API calls
- [ ] No sensitive data in console.log
- [ ] Error boundaries don't leak info

### Infrastructure
- [ ] `.env` file in `.gitignore`
- [ ] Production logs don't contain credentials
- [ ] Database user has minimal permissions
- [ ] Regular security updates for dependencies
