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
    private static $loadedFrom = null;   // step 2.2: the file that won
    private static $source = 'none';     // step 2.2: outside_webroot | inside_webroot | none

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

        if ($paths === null) {
            // Step 2.2 search order - the FIRST file found wins, nothing is merged:
            //  (a) FWL_ENV_FILE from the process environment
            //  (b) the per-site file outside the web root (see outsideWebrootPath())
            //  (c) legacy locations inside the tree (local dev, and hosts not migrated yet):
            //      these keep their old merging behaviour so nothing breaks in transition.
            $forced = getenv('FWL_ENV_FILE');
            if ($forced && is_file($forced)) {
                self::parseFile($forced, $override);
                self::$loadedFrom = realpath($forced) ?: $forced;
                self::$source = self::isInsideWebroot($forced) ? 'inside_webroot' : 'outside_webroot';
                self::$loaded = true;
                return true;
            }
            $outside = self::outsideWebrootPath();
            if ($outside && is_file($outside)) {
                self::parseFile($outside, $override);
                self::$loadedFrom = $outside;
                self::$source = 'outside_webroot';
                self::$loaded = true;
                return true;
            }
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
                if (self::$loadedFrom === null) {
                    self::$loadedFrom = realpath($path) ?: $path;
                    self::$source = self::isInsideWebroot(self::$loadedFrom) ? 'inside_webroot' : 'outside_webroot';
                }
                $loaded = true;
            }
        }

        self::$loaded = $loaded;
        return $loaded;
    }

    /**
     * Step 2.2: where the server .env should live - outside every web root.
     * Hostinger layout <home>/domains/<domain>/public_html/<site>/api: the parent of the
     * site's document root is the domain's own web root and is shared by all sites, so
     * the file goes to <home>/env/<site>/.env instead. On a host with the classic
     * <site>/public_html layout the parent of the document root is used.
     * Returns null when no sensible location can be derived (local dev).
     */
    public static function outsideWebrootPath() {
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/')
            : str_replace('\\', '/', dirname(__DIR__)); // CLI: the api folder's parent
        if ($docroot === '' || $docroot === '/') {
            return null;
        }
        $site = basename($docroot);
        $pos = strpos($docroot, '/domains/');
        if ($pos !== false && strpos($docroot, '/public_html/') !== false) {
            $home = substr($docroot, 0, $pos);
            return $home . '/env/' . $site . '/.env';
        }
        // Classic <site>/public_html layout: only for real web requests (DOCUMENT_ROOT set).
        // From the CLI on a dev machine this would point at the repo root and shadow .env.local.
        if (!empty($_SERVER['DOCUMENT_ROOT']) && basename(dirname($docroot)) !== 'public_html' && $site === 'public_html') {
            return dirname($docroot) . '/.env';
        }
        return null;
    }

    private static function isInsideWebroot($path) {
        $p = str_replace('\\', '/', (string) $path);
        return strpos($p, '/public_html/') !== false || strpos($p, '/public_html') === strlen($p) - strlen('/public_html');
    }

    /** Step 2.2: which file was loaded (path) - for logs and the CLI tool only, never for clients. */
    public static function loadedFrom() {
        return self::$loadedFrom;
    }

    /** Step 2.2: outside_webroot | inside_webroot | none */
    public static function source() {
        return self::$source;
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
