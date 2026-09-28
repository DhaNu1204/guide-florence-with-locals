<?php
/**
 * Step 7.1: the AI assistant endpoint (plan Phase 7).
 *
 *   POST api/assistant.php  {"message": "...", "conversation_id": optional}
 *   → 200 {success, conversation_id, text, blocks: [], meta}
 *
 * Order of checks (each one a real HTTP status, JSON body):
 *   405 not POST · 503 assistant_disabled (ASSISTANT_ENABLED not true - checked before auth, so a
 *   switched-off assistant never even reads a session) · 401 no/expired token · 403 not admin ·
 *   503 assistant_not_configured (no ANTHROPIC_API_KEY) · 429 rate_limited (30/min per user) ·
 *   400 bad body · 429 daily_cap_reached (ASSISTANT_DAILY_TOKEN_CAP, since midnight Europe/Rome) ·
 *   404 conversation_not_found · 502 assistant_busy / assistant_upstream_error.
 * The engine (prompt, tool loop, storage, log row) is lib/assistant_core.php, shared with the CLI.
 */
require_once 'config.php';
require_once 'Middleware.php';
require_once __DIR__ . '/lib/assistant_core.php';

function assistantRespond($status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    assistantRespond(405, ['success' => false, 'error' => 'method_not_allowed']);
}

if (!assistantEnabled()) {
    assistantRespond(503, ['success' => false, 'error' => 'assistant_disabled']);
}

$user = Middleware::requireRole($conn, 'admin');

$client = ClaudeClient::fromEnv();
if ($client === null) {
    assistantRespond(503, ['success' => false, 'error' => 'assistant_not_configured']);
}

// Per USER, not per IP: the limiter's key column holds "user:<id>" for this endpoint.
$limiter = new RateLimiter($conn, 'user:' . (int) $user['id']);
if (!$limiter->check('assistant', ASSISTANT_RATE_LIMIT, 60)) {
    header('Retry-After: ' . $limiter->getResetTime());
    assistantRespond(429, ['success' => false, 'error' => 'rate_limited', 'retry_after' => $limiter->getResetTime()]);
}

try {
    list($message, $conversationId) = assistantParseBody(json_decode(file_get_contents('php://input'), true));
} catch (InvalidArgumentException $e) {
    assistantRespond(400, ['success' => false, 'error' => $e->getMessage()]);
}

try {
    ensureAssistantTables($conn);
    if (assistantTokensToday($conn, assistantNow()) >= assistantDailyTokenCap()) {
        assistantRespond(429, ['success' => false, 'error' => 'daily_cap_reached']);
    }
    $result = assistantHandle($conn, $client, $user, $message, $conversationId);
} catch (Throwable $e) {
    error_log('assistant.php: ' . $e->getMessage());
    assistantRespond(500, ['success' => false, 'error' => 'internal_error']);
}
assistantRespond($result['status'], $result['body']);
