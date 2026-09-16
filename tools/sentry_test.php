<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script (step 0.2): never reachable over HTTP
/**
 * Sentry Test Endpoint
 *
 * Use this endpoint to verify Sentry integration is working correctly.
 * Provides various test scenarios for error tracking.
 *
 * Usage:
 *   GET /api/sentry_test.php?action=status     - Check Sentry configuration status
 *   GET /api/sentry_test.php?action=message    - Send a test info message
 *   GET /api/sentry_test.php?action=warning    - Send a test warning message
 *   GET /api/sentry_test.php?action=error      - Send a test error message
 *   GET /api/sentry_test.php?action=exception  - Trigger and capture a test exception
 *   GET /api/sentry_test.php?action=breadcrumb - Test breadcrumb tracking
 *
 * SECURITY: This endpoint should be disabled or protected in production.
 */

require_once __DIR__ . '/../public_html/api/config.php';

// Include SentryLogger
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/../public_html/api/SentryLogger.php';
}

// Security check - only allow in development or with admin auth
$allowTest = (ENVIRONMENT === 'development');

if (!$allowTest) {
    // In production, require admin authentication
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

    if ($token) {
        // Step 1.5: sessions hold sha256(token); use the shared lookup (hash first, raw fallback)
        require_once __DIR__ . '/../public_html/api/Middleware.php';
        $user = Middleware::findSessionUser($conn, $token);
        if ($user) {
            if ($user['role'] === 'admin') {
                $allowTest = true;
            }
        }
    }
}

if (!$allowTest) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Access denied. Admin authentication required in production.',
        'environment' => ENVIRONMENT
    ]);
    exit();
}

$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'status':
        // Check Sentry configuration status
        $sentryDsn = EnvLoader::get('SENTRY_DSN', '');
        $sentryEnabled = class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled();

        echo json_encode([
            'sentry_configured' => !empty($sentryDsn),
            'sentry_enabled' => $sentryEnabled,
            'sentry_environment' => EnvLoader::get('SENTRY_ENVIRONMENT', 'not set'),
            'sentry_release' => EnvLoader::get('SENTRY_RELEASE', 'not set'),
            'dsn_present' => !empty($sentryDsn) ? 'yes (hidden for security)' : 'no',
            'php_version' => PHP_VERSION,
            'curl_available' => function_exists('curl_init'),
            'app_environment' => ENVIRONMENT,
            'timestamp' => date('Y-m-d H:i:s'),
            'instructions' => [
                'To enable Sentry:' => [
                    '1. Get your DSN from Sentry project settings',
                    '2. Add SENTRY_DSN to your .env file',
                    '3. Set SENTRY_ENVIRONMENT (development, staging, production)',
                    '4. Restart your PHP server'
                ],
                'Available test actions:' => [
                    'status' => 'Check configuration (current)',
                    'message' => 'Send test info message',
                    'warning' => 'Send test warning',
                    'error' => 'Send test error',
                    'exception' => 'Trigger test exception',
                    'breadcrumb' => 'Test breadcrumb tracking'
                ]
            ]
        ], JSON_PRETTY_PRINT);
        break;

    case 'message':
        // Send a test info message
        if (!class_exists('SentryLogger') || !SentryLogger::getInstance()->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Sentry is not enabled. Configure SENTRY_DSN in your .env file.'
            ]);
            exit();
        }

        $eventId = sentry_capture_message(
            'Test message from Florence Guides API',
            'info',
            [
                'test_type' => 'manual_test',
                'triggered_by' => 'sentry_test.php',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Test message sent to Sentry',
            'event_id' => $eventId,
            'level' => 'info'
        ]);
        break;

    case 'warning':
        // Send a test warning message
        if (!class_exists('SentryLogger') || !SentryLogger::getInstance()->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Sentry is not enabled. Configure SENTRY_DSN in your .env file.'
            ]);
            exit();
        }

        $eventId = sentry_capture_message(
            'Test WARNING from Florence Guides API',
            'warning',
            [
                'test_type' => 'warning_test',
                'triggered_by' => 'sentry_test.php',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Test warning sent to Sentry',
            'event_id' => $eventId,
            'level' => 'warning'
        ]);
        break;

    case 'error':
        // Send a test error message
        if (!class_exists('SentryLogger') || !SentryLogger::getInstance()->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Sentry is not enabled. Configure SENTRY_DSN in your .env file.'
            ]);
            exit();
        }

        $eventId = sentry_capture_message(
            'Test ERROR from Florence Guides API',
            'error',
            [
                'test_type' => 'error_test',
                'triggered_by' => 'sentry_test.php',
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Test error sent to Sentry',
            'event_id' => $eventId,
            'level' => 'error'
        ]);
        break;

    case 'exception':
        // Trigger and capture a test exception
        if (!class_exists('SentryLogger') || !SentryLogger::getInstance()->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Sentry is not enabled. Configure SENTRY_DSN in your .env file.'
            ]);
            exit();
        }

        try {
            // Create a nested call stack for a realistic stack trace
            testNestedFunction();
        } catch (Exception $e) {
            $eventId = sentry_capture_exception($e, [
                'test_type' => 'exception_test',
                'triggered_by' => 'sentry_test.php',
                'intentional' => true
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Test exception captured and sent to Sentry',
                'event_id' => $eventId,
                'exception_message' => $e->getMessage(),
                'exception_type' => get_class($e)
            ]);
        }
        break;

    case 'breadcrumb':
        // Test breadcrumb tracking
        if (!class_exists('SentryLogger') || !SentryLogger::getInstance()->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Sentry is not enabled. Configure SENTRY_DSN in your .env file.'
            ]);
            exit();
        }

        // Add several breadcrumbs to simulate a user journey
        sentry_add_breadcrumb('User started sentry test', 'navigation', 'info');
        sentry_add_breadcrumb('Checking database connection', 'query', 'info', ['db' => 'florence_guides']);
        sentry_add_breadcrumb('Database query executed', 'query', 'info', ['rows' => 5]);
        sentry_add_breadcrumb('API request initiated', 'http', 'info', ['url' => '/api/test']);
        sentry_add_breadcrumb('Something went wrong', 'default', 'warning');

        // Now send a message that includes all breadcrumbs
        $eventId = sentry_capture_message(
            'Breadcrumb test completed',
            'info',
            [
                'test_type' => 'breadcrumb_test',
                'breadcrumb_count' => 5
            ]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Breadcrumbs recorded and event sent to Sentry',
            'event_id' => $eventId,
            'breadcrumbs_added' => 5
        ]);
        break;

    default:
        echo json_encode([
            'error' => 'Unknown action: ' . $action,
            'available_actions' => ['status', 'message', 'warning', 'error', 'exception', 'breadcrumb']
        ]);
}

/**
 * Helper function to create a nested call stack for exception testing
 */
function testNestedFunction() {
    testInnerFunction();
}

function testInnerFunction() {
    testDeepFunction();
}

function testDeepFunction() {
    throw new Exception('This is a test exception from Florence Guides API - triggered intentionally for Sentry testing');
}
