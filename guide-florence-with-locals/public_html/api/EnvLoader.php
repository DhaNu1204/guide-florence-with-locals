<?php
/**
 * EnvLoader.php - Environment Variable Loader
 *
 * Provides robust loading of environment variables from .env files.
 * Supports:
 * - Multiple .env file locations
 * - Comments and empty lines
 * - Variable interpolation
 * - Type casting
 */

class EnvLoader {

    private static $loaded = false;
    private static $values = [];

    /**
     * Load environment variables from .env file
     *
     * @param string|array $paths Path(s) to .env file(s)
     * @param bool $override Whether to override existing env vars
     * @return bool Success status
     */
    public static function load($paths = null, $override = false) {
        if (self::$loaded && !$override) {
            return true;
        }

        // Default paths to check
        if ($paths === null) {
            $paths = [
                __DIR__ . '/../../.env.local',    // Project root .env.local (highest priority)
                __DIR__ . '/../../.env',          // Project root .env
                __DIR__ . '/../.env',             // public_html .env
                __DIR__ . '/.env'                 // api folder .env
            ];
        }

        if (!is_array($paths)) {
            $paths = [$paths];
        }

        $loaded = false;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                self::parseFile($path, $override);
                $loaded = true;
            }
        }

        self::$loaded = $loaded;
        return $loaded;
    }

    /**
     * Parse a .env file and load variables
     */
    private static function parseFile($path, $override) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Handle variable interpolation ${VAR_NAME}
            $value = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                return self::get($matches[1], '');
            }, $value);

            // Store value
            if ($override || !isset(self::$values[$key])) {
                self::$values[$key] = self::castValue($value);
                $_ENV[$key] = self::$values[$key];

                // Also set in $_SERVER for compatibility
                $_SERVER[$key] = self::$values[$key];
            }
        }
    }

    /**
     * Cast string values to appropriate types
     */
    private static function castValue($value) {
        // Boolean values
        $lowerValue = strtolower($value);
        if ($lowerValue === 'true' || $lowerValue === '(true)') {
            return true;
        }
        if ($lowerValue === 'false' || $lowerValue === '(false)') {
            return false;
        }
        if ($lowerValue === 'null' || $lowerValue === '(null)') {
            return null;
        }
        if ($lowerValue === 'empty' || $lowerValue === '(empty)') {
            return '';
        }

        // Numeric values
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            }
            return (int) $value;
        }

        return $value;
    }

    /**
     * Get an environment variable
     *
     * @param string $key Variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null) {
        // First check our loaded values
        if (isset(self::$values[$key])) {
            return self::$values[$key];
        }

        // Then check $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Then check $_SERVER
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // Then check getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Get required environment variable (throws exception if not found)
     */
    public static function getRequired($key) {
        $value = self::get($key);

        if ($value === null) {
            throw new Exception("Required environment variable '$key' is not set");
        }

        return $value;
    }

    /**
     * Check if environment variable exists
     */
    public static function has($key) {
        return self::get($key) !== null;
    }

    /**
     * Get all loaded environment variables
     */
    public static function all() {
        return self::$values;
    }

    /**
     * Get environment variable as boolean
     */
    public static function getBool($key, $default = false) {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $trueValues = ['true', '1', 'yes', 'on'];
        return in_array(strtolower($value), $trueValues);
    }

    /**
     * Get environment variable as integer
     */
    public static function getInt($key, $default = 0) {
        $value = self::get($key, $default);
        return (int) $value;
    }

    /**
     * Get environment variable as array (comma-separated values)
     */
    public static function getArray($key, $default = []) {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Check if running in production
     */
    public static function isProduction() {
        $env = self::get('APP_ENV', self::get('ENVIRONMENT', 'development'));
        return in_array(strtolower($env), ['production', 'prod']);
    }

    /**
     * Check if running in development
     */
    public static function isDevelopment() {
        return !self::isProduction();
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isDebug() {
        return self::getBool('DEBUG', self::isDevelopment());
    }
}
