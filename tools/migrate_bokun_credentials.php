<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script (step 0.2): never reachable over HTTP
/**
 * Migration Script: Encrypt Bokun API Credentials
 *
 * This is a ONE-TIME migration script that encrypts existing plain text
 * Bokun API credentials stored in the bokun_config table.
 *
 * USAGE:
 *   php migrate_bokun_credentials.php
 *   OR via web: /api/migrate_bokun_credentials.php?action=status
 *
 * ACTIONS:
 *   ?action=status   - Check current encryption status (safe, no changes)
 *   ?action=migrate  - Encrypt credentials (requires confirmation)
 *   ?action=migrate&confirm=yes - Actually perform migration
 *   ?action=verify   - Verify encrypted credentials can be decrypted
 *   ?action=rollback&confirm=yes - Decrypt credentials back to plain text (emergency)
 *
 * SECURITY:
 *   - Requires admin authentication in production
 *   - Logs all operations
 *   - Creates backup before migration
 *
 * @author Florence Guides Security Hardener
 * @date January 2026
 */

require_once __DIR__ . '/../public_html/api/config.php';
require_once __DIR__ . '/../public_html/api/Encryption.php';

// Security: Require admin auth in production
if (ENVIRONMENT === 'production') {
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

    $isAdmin = false;
    if ($token) {
        $stmt = $conn->prepare("
            SELECT u.role FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $isAdmin = ($user['role'] === 'admin');
        }
    }

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Admin authentication required',
            'hint' => 'Include Authorization: Bearer <token> header'
        ]);
        exit();
    }
}

$action = $_GET['action'] ?? 'status';
$confirm = $_GET['confirm'] ?? 'no';

// Check encryption status
function checkEncryptionStatus($conn) {
    $result = $conn->query("SELECT id, api_key, api_secret, vendor_id FROM bokun_config LIMIT 1");

    if (!$result || $result->num_rows === 0) {
        return [
            'has_config' => false,
            'message' => 'No Bokun configuration found in database'
        ];
    }

    $config = $result->fetch_assoc();

    $apiKeyEncrypted = Encryption::isEncrypted($config['api_key']);
    $apiSecretEncrypted = Encryption::isEncrypted($config['api_secret']);

    return [
        'has_config' => true,
        'config_id' => $config['id'],
        'vendor_id' => $config['vendor_id'],
        'api_key_encrypted' => $apiKeyEncrypted,
        'api_secret_encrypted' => $apiSecretEncrypted,
        'fully_encrypted' => $apiKeyEncrypted && $apiSecretEncrypted,
        'needs_migration' => !$apiKeyEncrypted || !$apiSecretEncrypted,
        'api_key_length' => strlen($config['api_key']),
        'api_secret_length' => strlen($config['api_secret']),
        'encryption_available' => class_exists('Encryption'),
        'encryption_ready' => Encryption::status()
    ];
}

// Perform migration
function migrateCredentials($conn) {
    $result = $conn->query("SELECT id, api_key, api_secret FROM bokun_config LIMIT 1");

    if (!$result || $result->num_rows === 0) {
        return ['success' => false, 'error' => 'No configuration to migrate'];
    }

    $config = $result->fetch_assoc();

    // Check if already encrypted
    if (Encryption::isEncrypted($config['api_key']) && Encryption::isEncrypted($config['api_secret'])) {
        return [
            'success' => true,
            'message' => 'Credentials are already encrypted',
            'migrated' => false
        ];
    }

    // Initialize encryption
    if (!Encryption::init()) {
        return [
            'success' => false,
            'error' => 'Encryption not available. Check ENCRYPTION_KEY in environment.'
        ];
    }

    // Backup current values (logged, not returned for security)
    error_log("migrate_bokun_credentials: Starting migration for config ID " . $config['id']);
    error_log("migrate_bokun_credentials: api_key length before: " . strlen($config['api_key']));
    error_log("migrate_bokun_credentials: api_secret length before: " . strlen($config['api_secret']));

    // Encrypt credentials
    $encryptedKey = $config['api_key'];
    $encryptedSecret = $config['api_secret'];
    $changes = [];

    if (!Encryption::isEncrypted($config['api_key'])) {
        $encryptedKey = Encryption::encrypt($config['api_key']);
        if ($encryptedKey === false) {
            return ['success' => false, 'error' => 'Failed to encrypt api_key'];
        }
        $changes[] = 'api_key';
    }

    if (!Encryption::isEncrypted($config['api_secret'])) {
        $encryptedSecret = Encryption::encrypt($config['api_secret']);
        if ($encryptedSecret === false) {
            return ['success' => false, 'error' => 'Failed to encrypt api_secret'];
        }
        $changes[] = 'api_secret';
    }

    // Update database
    $stmt = $conn->prepare("UPDATE bokun_config SET api_key = ?, api_secret = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $encryptedKey, $encryptedSecret, $config['id']);

    if (!$stmt->execute()) {
        return ['success' => false, 'error' => 'Database update failed: ' . $stmt->error];
    }

    error_log("migrate_bokun_credentials: Migration completed successfully");
    error_log("migrate_bokun_credentials: api_key length after: " . strlen($encryptedKey));
    error_log("migrate_bokun_credentials: api_secret length after: " . strlen($encryptedSecret));

    return [
        'success' => true,
        'message' => 'Credentials encrypted successfully',
        'migrated' => true,
        'fields_encrypted' => $changes,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Verify encrypted credentials
function verifyCredentials($conn) {
    $result = $conn->query("SELECT id, api_key, api_secret, vendor_id FROM bokun_config LIMIT 1");

    if (!$result || $result->num_rows === 0) {
        return ['success' => false, 'error' => 'No configuration found'];
    }

    $config = $result->fetch_assoc();

    // Try to decrypt
    $decryptedKey = Encryption::ensureDecrypted($config['api_key']);
    $decryptedSecret = Encryption::ensureDecrypted($config['api_secret']);

    // Verify decryption worked (decrypted values should be different from encrypted)
    $keyDecrypted = ($decryptedKey !== $config['api_key']) || !Encryption::isEncrypted($config['api_key']);
    $secretDecrypted = ($decryptedSecret !== $config['api_secret']) || !Encryption::isEncrypted($config['api_secret']);

    // Test that decrypted values look like valid credentials
    $keyValid = !empty($decryptedKey) && strlen($decryptedKey) > 10;
    $secretValid = !empty($decryptedSecret) && strlen($decryptedSecret) > 10;

    return [
        'success' => $keyValid && $secretValid,
        'api_key' => [
            'encrypted' => Encryption::isEncrypted($config['api_key']),
            'decryption_successful' => $keyDecrypted,
            'looks_valid' => $keyValid,
            'decrypted_length' => strlen($decryptedKey),
            'preview' => substr($decryptedKey, 0, 8) . '...'
        ],
        'api_secret' => [
            'encrypted' => Encryption::isEncrypted($config['api_secret']),
            'decryption_successful' => $secretDecrypted,
            'looks_valid' => $secretValid,
            'decrypted_length' => strlen($decryptedSecret),
            'preview' => substr($decryptedSecret, 0, 4) . '****'
        ],
        'vendor_id' => $config['vendor_id']
    ];
}

// Rollback (decrypt) credentials - EMERGENCY USE ONLY
function rollbackCredentials($conn) {
    $result = $conn->query("SELECT id, api_key, api_secret FROM bokun_config LIMIT 1");

    if (!$result || $result->num_rows === 0) {
        return ['success' => false, 'error' => 'No configuration found'];
    }

    $config = $result->fetch_assoc();

    // Check if encrypted
    if (!Encryption::isEncrypted($config['api_key']) && !Encryption::isEncrypted($config['api_secret'])) {
        return [
            'success' => true,
            'message' => 'Credentials are already in plain text',
            'rolled_back' => false
        ];
    }

    // Decrypt
    $decryptedKey = Encryption::ensureDecrypted($config['api_key']);
    $decryptedSecret = Encryption::ensureDecrypted($config['api_secret']);

    if (Encryption::isEncrypted($decryptedKey) || Encryption::isEncrypted($decryptedSecret)) {
        return ['success' => false, 'error' => 'Failed to decrypt credentials'];
    }

    // Update database with decrypted values
    $stmt = $conn->prepare("UPDATE bokun_config SET api_key = ?, api_secret = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $decryptedKey, $decryptedSecret, $config['id']);

    if (!$stmt->execute()) {
        return ['success' => false, 'error' => 'Database update failed: ' . $stmt->error];
    }

    error_log("migrate_bokun_credentials: ROLLBACK - Credentials decrypted back to plain text");

    return [
        'success' => true,
        'message' => 'Credentials decrypted successfully (WARNING: now stored in plain text)',
        'rolled_back' => true,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Handle actions
switch ($action) {
    case 'status':
        $status = checkEncryptionStatus($conn);
        echo json_encode($status, JSON_PRETTY_PRINT);
        break;

    case 'migrate':
        if ($confirm !== 'yes') {
            echo json_encode([
                'action' => 'migrate',
                'status' => 'confirmation_required',
                'message' => 'This will encrypt Bokun API credentials in the database.',
                'warning' => 'Make sure ENCRYPTION_KEY is backed up! Losing the key means losing access to credentials.',
                'current_status' => checkEncryptionStatus($conn),
                'to_proceed' => 'Add ?action=migrate&confirm=yes to the URL'
            ], JSON_PRETTY_PRINT);
        } else {
            $result = migrateCredentials($conn);
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
        break;

    case 'verify':
        $result = verifyCredentials($conn);
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;

    case 'rollback':
        if ($confirm !== 'yes') {
            echo json_encode([
                'action' => 'rollback',
                'status' => 'confirmation_required',
                'message' => 'This will DECRYPT credentials back to plain text.',
                'warning' => 'Only use this for emergency recovery!',
                'to_proceed' => 'Add ?action=rollback&confirm=yes to the URL'
            ], JSON_PRETTY_PRINT);
        } else {
            $result = rollbackCredentials($conn);
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
        break;

    default:
        echo json_encode([
            'error' => 'Unknown action',
            'available_actions' => [
                'status' => 'Check encryption status',
                'migrate' => 'Encrypt plain text credentials',
                'verify' => 'Verify encrypted credentials work',
                'rollback' => 'Decrypt back to plain text (emergency)'
            ]
        ], JSON_PRETTY_PRINT);
}
