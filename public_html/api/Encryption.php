<?php
/**
 * Encryption.php - Secure Encryption Helper
 *
 * Provides AES-256-CBC encryption for sensitive data storage.
 * Used to encrypt API credentials, tokens, and other sensitive information.
 *
 * SECURITY NOTES:
 * - Uses AES-256-CBC with random IV for each encryption
 * - Encryption key must be 32 bytes (256 bits)
 * - Key should be stored in environment variable, never in code
 * - Encrypted values are base64 encoded for safe database storage
 * - Step 1.6: ciphertext is prefixed 'enc:v1:' (unambiguous), cipher and MAC keys are
 *   derived from ENCRYPTION_KEY with HKDF, encrypt() throws without a key (fail closed);
 *   un-prefixed legacy ciphertext is still decrypted during the transition
 * - Includes HMAC verification to detect tampering
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html
 */

class Encryption {

    private const CIPHER = 'aes-256-cbc';
    private const HASH_ALGO = 'sha256';

    const PREFIX = 'enc:v1:';
    private static $key = null;          // legacy: the normalised master key (cipher AND mac before step 1.6)
    private static $encKey = null;       // step 1.6: HKDF-derived cipher key
    private static $macKey = null;       // step 1.6: HKDF-derived MAC key
    private static $initialized = false;
    private static $noKey = false;       // step 1.6: initWithKey('') forces the fail-closed state

    /**
     * Initialize encryption with key from environment
     *
     * @return bool True if initialized successfully
     * @throws Exception If key is missing or invalid
     */
    public static function init() {
        if (self::$initialized) {
            return true;
        }
        if (self::$noKey) {
            return false; // step 1.6: explicitly configured without a key - stay closed
        }

        // Get key from environment
        $key = self::getKeyFromEnv();

        if (empty($key)) {
            error_log("Encryption: ENCRYPTION_KEY not set in environment");
            return false;
        }

        self::setKeys(self::normalizeKey($key));
        self::$initialized = true;

        return true;
    }

    /**
     * Initialize with an explicit key instead of the environment (step 0.3: used by
     * tools/migrate_bokun_credentials.php --action=rekey to decrypt with the OLD key).
     *
     * @param string $key Raw or base64 key, same rules as ENCRYPTION_KEY
     * @return bool
     */
    public static function initWithKey($key) {
        self::reset();
        if (empty($key)) {
            self::$noKey = true; // step 1.6: encrypt() must throw from now on
            return false;
        }
        self::setKeys(self::normalizeKey($key));
        self::$initialized = true;
        return true;
    }

    /**
     * Step 1.6: separate cipher and MAC keys derived from the master key with HKDF;
     * the raw master key is kept only for the legacy (un-prefixed) decrypt path.
     */
    private static function setKeys($masterKey) {
        self::$key = $masterKey;
        self::$encKey = hash_hkdf(self::HASH_ALGO, $masterKey, 32, 'florence-with-locals enc v1');
        self::$macKey = hash_hkdf(self::HASH_ALGO, $masterKey, 32, 'florence-with-locals mac v1');
    }

    /**
     * Turn the configured key into exactly 32 bytes for AES-256
     */
    private static function normalizeKey($key) {
        // Validate key length (must be 32 bytes for AES-256)
        if (strlen($key) < 32) {
            // If key is shorter, derive a proper key using hash
            $key = hash(self::HASH_ALGO, $key, true);
        } elseif (strlen($key) > 32) {
            // If key is longer (e.g., base64 encoded), decode or truncate
            if (self::isBase64($key)) {
                $decoded = base64_decode($key);
                if ($decoded !== false && strlen($decoded) >= 32) {
                    $key = substr($decoded, 0, 32);
                } else {
                    $key = hash(self::HASH_ALGO, $key, true);
                }
            } else {
                $key = substr($key, 0, 32);
            }
        }
        return $key;
    }

    /**
     * Get encryption key from environment
     *
     * @return string|null
     */
    private static function getKeyFromEnv() {
        // Check multiple sources for the key
        if (class_exists('EnvLoader')) {
            $key = EnvLoader::get('ENCRYPTION_KEY');
            if ($key) return $key;
        }

        if (isset($_ENV['ENCRYPTION_KEY'])) {
            return $_ENV['ENCRYPTION_KEY'];
        }

        if (isset($_SERVER['ENCRYPTION_KEY'])) {
            return $_SERVER['ENCRYPTION_KEY'];
        }

        $key = getenv('ENCRYPTION_KEY');
        if ($key !== false) {
            return $key;
        }

        return null;
    }

    /**
     * Check if string is base64 encoded
     *
     * @param string $string
     * @return bool
     */
    private static function isBase64($string) {
        if (!is_string($string) || empty($string)) {
            return false;
        }
        $decoded = base64_decode($string, true);
        return $decoded !== false && base64_encode($decoded) === $string;
    }

    /**
     * Encrypt plaintext data
     *
     * @param string $plaintext The data to encrypt
     * @return string|false Base64-encoded encrypted data, or false on failure
     */
    public static function encrypt($plaintext) {
        if (!self::init()) {
            // Step 1.6: fail closed. Never hand plaintext back to a caller that wanted ciphertext.
            throw new RuntimeException('encryption_unavailable');
        }
        if ($plaintext === '' || $plaintext === null) {
            return '';
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt((string) $plaintext, self::CIPHER, self::$encKey, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            error_log("Encryption: openssl_encrypt failed - " . openssl_error_string());
            throw new RuntimeException('encryption_failed');
        }
        $hmac = hash_hmac(self::HASH_ALGO, $iv . $encrypted, self::$macKey, true);
        // Format: enc:v1: + base64([32 bytes HMAC][16 bytes IV][ciphertext])
        return self::PREFIX . base64_encode($hmac . $iv . $encrypted);
    }

    /**
     * Legacy format (before step 1.6): base64([HMAC][IV][ciphertext]) with the single
     * master key for cipher and MAC, no prefix. Kept for the transition and for tests.
     */
    public static function encryptLegacy($plaintext) {
        if (!self::init()) {
            throw new RuntimeException('encryption_unavailable');
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt((string) $plaintext, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac(self::HASH_ALGO, $iv . $encrypted, self::$key, true);
        return base64_encode($hmac . $iv . $encrypted);
    }

    /**
     * Decrypt encrypted data
     *
     * @param string $ciphertext Base64-encoded encrypted data
     * @return string|false Decrypted plaintext, or false on failure
     */
    public static function decrypt($ciphertext) {
        if (!self::init()) {
            error_log("Encryption: Cannot decrypt - not initialized");
            return false;
        }
        if ($ciphertext === '' || $ciphertext === null) {
            return '';
        }
        if (!self::isEncrypted($ciphertext)) {
            return self::decryptLegacy($ciphertext);
        }
        return self::unpackAndDecrypt(substr($ciphertext, strlen(self::PREFIX)), self::$encKey, self::$macKey);
    }

    /**
     * Legacy (un-prefixed) ciphertext: single master key for cipher and MAC.
     * Returns false when the value is not legacy ciphertext (e.g. old plaintext rows).
     */
    public static function decryptLegacy($ciphertext) {
        if (!self::init()) {
            return false;
        }
        if (!is_string($ciphertext) || $ciphertext === '') {
            return false;
        }
        return self::unpackAndDecrypt($ciphertext, self::$key, self::$key, true);
    }

    private static function unpackAndDecrypt($b64, $cipherKey, $macKey, $quiet = false) {
        $combined = base64_decode($b64, true);
        if ($combined === false) {
            return false;
        }
        $hmacLength = 32; // SHA-256 produces 32 bytes
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($combined) < $hmacLength + $ivLength + 1) {
            return false;
        }
        $hmac = substr($combined, 0, $hmacLength);
        $iv = substr($combined, $hmacLength, $ivLength);
        $encrypted = substr($combined, $hmacLength + $ivLength);
        $expectedHmac = hash_hmac(self::HASH_ALGO, $iv . $encrypted, $macKey, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            if (!$quiet) {
                error_log("Encryption: HMAC verification failed - data may be corrupted or tampered");
            }
            return false;
        }
        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $cipherKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            if (!$quiet) {
                error_log("Encryption: openssl_decrypt failed - " . openssl_error_string());
            }
            return false;
        }
        return $decrypted;
    }

    /**
     * Check if a string appears to be encrypted
     *
     * Useful for backward compatibility when migrating from plain text.
     *
     * @param string $value The value to check
     * @return bool True if the value appears to be encrypted
     */
    public static function isEncrypted($value) {
        // Step 1.6: the prefix is the only test. No base64 guessing, no regex heuristics.
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Encrypt if not already encrypted (for migration)
     *
     * @param string $value The value to potentially encrypt
     * @return string The encrypted value (or original if already encrypted)
     */
    public static function ensureEncrypted($value) {
        if ($value === '' || $value === null) {
            return $value;
        }
        if (self::isEncrypted($value)) {
            return $value; // Already in the current format
        }
        return self::encrypt($value); // throws when no key is configured
    }

    /**
     * Step 1.6: prefixed -> decrypt (false when the key does not match);
     * un-prefixed -> try the legacy format, and if that fails the value is an old
     * plaintext row and is returned as-is.
     */
    public static function ensureDecrypted($value) {
        if ($value === '' || $value === null) {
            return $value;
        }
        if (self::isEncrypted($value)) {
            return self::decrypt($value);
        }
        $legacy = self::decryptLegacy($value);
        return $legacy !== false ? $legacy : $value;
    }

    /**
     * Generate a secure random encryption key
     *
     * Use this to generate a key for ENCRYPTION_KEY environment variable.
     *
     * @param bool $base64 Whether to return base64 encoded (default: true)
     * @return string A secure random key
     */
    public static function generateKey($base64 = true) {
        $key = openssl_random_pseudo_bytes(32);

        if ($base64) {
            return base64_encode($key);
        }

        return bin2hex($key);
    }

    /**
     * Check if encryption is available and properly configured
     *
     * @return array Status information
     */
    public static function status() {
        $status = [
            'openssl_available' => extension_loaded('openssl'),
            'cipher_available' => in_array(self::CIPHER, openssl_get_cipher_methods()),
            'key_configured' => !empty(self::getKeyFromEnv()),
            'initialized' => self::$initialized,
            'ready' => false
        ];

        $status['ready'] = $status['openssl_available']
            && $status['cipher_available']
            && $status['key_configured'];

        return $status;
    }

    /**
     * Reset initialization (mainly for testing)
     */
    public static function reset() {
        self::$key = null;
        self::$encKey = null;
        self::$macKey = null;
        self::$initialized = false;
        self::$noKey = false;
    }
}

// Convenience functions

/**
 * Encrypt a value
 *
 * @param string $plaintext
 * @return string|false
 */
function secure_encrypt($plaintext) {
    return Encryption::encrypt($plaintext);
}

/**
 * Decrypt a value
 *
 * @param string $ciphertext
 * @return string|false
 */
function secure_decrypt($ciphertext) {
    return Encryption::decrypt($ciphertext);
}

/**
 * Decrypt if encrypted, otherwise return as-is (backward compatible)
 *
 * @param string $value
 * @return string
 */
function secure_decrypt_safe($value) {
    return Encryption::ensureDecrypted($value);
}
