<?php
/**
 * Step 7.1: the AI assistant endpoint (plan Phase 7).
 *
 *   POST api/assistant.php  {"message": "...", "conversation_id": optional}
 *   → 200 {success, conversation_id, text, blocks[], meta}  (step 7.2: blocks = validated show_blocks output:
 *     stat | departure_list | table | choices | link - see assistantValidateBlock in lib/assistant_tools.php)
 *
 * Step 7.4 reads (GET ?action=status | conversations | conversation&id=) are documented below.
 * Order of checks for a question (each one a real HTTP status, JSON body):
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

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

// Step 7.4: the chat UI's reads.
//   GET ?action=status                -> {enabled, can_see_money}  (admin-only; answers even when the
//                                        assistant is switched off, so the app can hide the button)
//   GET ?action=conversations         -> the user's last 5 conversations (last 30 days)
//   GET ?action=conversation&id=N     -> that conversation's messages, only if it is the user's
if ($method === 'GET' && $action === 'status') {
    $user = Middleware::requireRole($conn, 'admin');
    assistantRespond(200, ['success' => true, 'enabled' => assistantEnabled(),
                           'can_see_money' => Middleware::isPnlOwner($user)]);
}
if ($method === 'GET' && ($action === 'conversations' || $action === 'conversation')) {
    if (!assistantEnabled()) {
        assistantRespond(503, ['success' => false, 'error' => 'assistant_disabled']);
    }
    $user = Middleware::requireRole($conn, 'admin');
    try {
        ensureAssistantTables($conn);
        assistantPrune($conn);
        if ($action === 'conversations') {
            assistantRespond(200, ['success' => true, 'conversations' => assistantRecentConversations($conn, (int) $user['id'])]);
        }
        $id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !assistantOwnsConversation($conn, $id, (int) $user['id'])) {
            assistantRespond(404, ['success' => false, 'error' => 'conversation_not_found']);
        }
        assistantRespond(200, ['success' => true, 'conversation_id' => $id, 'messages' => assistantConversationMessages($conn, $id)]);
    } catch (Throwable $e) {
        error_log('assistant.php history: ' . $e->getMessage());
        assistantRespond(500, ['success' => false, 'error' => 'internal_error']);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
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

$rawBody = json_decode(file_get_contents('php://input'), true);

// Step 7.5: the confirm card reports back AFTER the Tours endpoint saved (or undid) the change.
if ($action === 'assign_done' || $action === 'undo_done') {
    try {
        ensureAssistantTables($conn);
        $res = $action === 'assign_done' ? assistantAssignDone($conn, $user, $rawBody) : assistantUndoDone($conn, $user, $rawBody);
    } catch (Throwable $e) {
        error_log('assistant.php ' . $action . ': ' . $e->getMessage());
        assistantRespond(500, ['success' => false, 'error' => 'internal_error']);
    }
    assistantRespond($res['status'], $res['body']);
}

try {
    list($message, $conversationId) = assistantParseBody($rawBody);
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
