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
 * - Includes HMAC verification to detect tampering
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html
 */

class Encryption {

    private const CIPHER = 'aes-256-cbc';
    private const HASH_ALGO = 'sha256';

    private static $key = null;
    private static $initialized = false;

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

        // Get key from environment
        $key = self::getKeyFromEnv();

        if (empty($key)) {
            error_log("Encryption: ENCRYPTION_KEY not set in environment");
            return false;
        }

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

        self::$key = $key;
        self::$initialized = true;

        return true;
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
            error_log("Encryption: Cannot encrypt - not initialized");
            return false;
        }

        if (empty($plaintext)) {
            return '';
        }

        try {
            // Generate random IV
            $ivLength = openssl_cipher_iv_length(self::CIPHER);
            $iv = openssl_random_pseudo_bytes($ivLength);

            if ($iv === false) {
                error_log("Encryption: Failed to generate IV");
                return false;
            }

            // Encrypt the data
            $encrypted = openssl_encrypt(
                $plaintext,
                self::CIPHER,
                self::$key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                error_log("Encryption: openssl_encrypt failed - " . openssl_error_string());
                return false;
            }

            // Create HMAC for integrity verification
            $hmac = hash_hmac(self::HASH_ALGO, $iv . $encrypted, self::$key, true);

            // Combine: HMAC + IV + Encrypted data
            // Format: [32 bytes HMAC][16 bytes IV][encrypted data]
            $combined = $hmac . $iv . $encrypted;

            // Base64 encode for safe storage
            return base64_encode($combined);

        } catch (Exception $e) {
            error_log("Encryption: Exception during encrypt - " . $e->getMessage());
            return false;
        }
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

        if (empty($ciphertext)) {
            return '';
        }

        try {
            // Decode from base64
            $combined = base64_decode($ciphertext, true);

            if ($combined === false) {
                // Not base64 encoded - might be plain text (backward compatibility)
                return false;
            }

            // Extract components
            $hmacLength = 32; // SHA-256 produces 32 bytes
            $ivLength = openssl_cipher_iv_length(self::CIPHER);

            // Minimum length check: HMAC + IV + at least 1 byte of data
            if (strlen($combined) < $hmacLength + $ivLength + 1) {
                // Too short to be encrypted data - might be plain text
                return false;
            }

            $hmac = substr($combined, 0, $hmacLength);
            $iv = substr($combined, $hmacLength, $ivLength);
            $encrypted = substr($combined, $hmacLength + $ivLength);

            // Verify HMAC (prevent tampering)
            $expectedHmac = hash_hmac(self::HASH_ALGO, $iv . $encrypted, self::$key, true);

            if (!hash_equals($expectedHmac, $hmac)) {
                error_log("Encryption: HMAC verification failed - data may be corrupted or tampered");
                return false;
            }

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER,
                self::$key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                error_log("Encryption: openssl_decrypt failed - " . openssl_error_string());
                return false;
            }

            return $decrypted;

        } catch (Exception $e) {
            error_log("Encryption: Exception during decrypt - " . $e->getMessage());
            return false;
        }
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
        if (empty($value) || !is_string($value)) {
            return false;
        }

        // Check if it's valid base64
        if (!self::isBase64($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        // Check minimum length for our format: HMAC(32) + IV(16) + data(1+)
        $hmacLength = 32;
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($decoded) < $hmacLength + $ivLength + 1) {
            return false;
        }

        // Additional heuristic: encrypted data shouldn't contain common plain text patterns
        // API keys typically start with alphanumeric characters
        if (preg_match('/^[a-zA-Z0-9]{8,}$/', $value)) {
            return false; // Looks like a plain API key
        }

        return true;
    }

    /**
     * Encrypt if not already encrypted (for migration)
     *
     * @param string $value The value to potentially encrypt
     * @return string The encrypted value (or original if already encrypted)
     */
    public static function ensureEncrypted($value) {
        if (empty($value)) {
            return $value;
        }

        if (self::isEncrypted($value)) {
            return $value; // Already encrypted
        }

        $encrypted = self::encrypt($value);
        return $encrypted !== false ? $encrypted : $value;
    }

    /**
     * Decrypt if encrypted, return as-is if plain text (backward compatibility)
     *
     * @param string $value The value to potentially decrypt
     * @return string The decrypted value (or original if not encrypted)
     */
    public static function ensureDecrypted($value) {
        if (empty($value)) {
            return $value;
        }

        if (!self::isEncrypted($value)) {
            return $value; // Already plain text
        }

        $decrypted = self::decrypt($value);
        return $decrypted !== false ? $decrypted : $value;
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
        self::$initialized = false;
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
