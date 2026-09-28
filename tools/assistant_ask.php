<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.1): never deployed
/**
 * Step 7.1 - ask the assistant one question from the command line, through the SAME engine as
 * api/assistant.php (lib/assistant_core.php): same guards, prompt, tools, storage and log row.
 *
 *   FWL_API_DIR=<api dir> php tools/assistant_ask.php --user=<username> "how many tours today"
 *   ... --conversation=<id>   continue an earlier chat
 *
 * Prints the answer, the tools called, tokens and ms. Exit codes mirror the HTTP statuses:
 * 0 ok · 3 assistant_disabled / assistant_not_configured (503) · 4 not an admin (403) ·
 * 5 daily_cap_reached (429) · 6 upstream error (502) · 2 usage.
 * The per-minute rate limit is an HTTP concern and is not applied here.
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/assistant_core.php';

$username = null;
$conversationId = null;
$question = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--user=(.+)$/', $a, $m)) { $username = $m[1]; continue; }
    if (preg_match('/^--conversation=(\d+)$/', $a, $m)) { $conversationId = (int) $m[1]; continue; }
    if ($question === null && strpos($a, '--') !== 0) { $question = $a; }
}
if (!$username || $question === null) {
    fwrite(STDERR, "usage: php tools/assistant_ask.php --user=<username> [--conversation=<id>] \"question\"\n");
    exit(2);
}

function fail($code, $status, $error) {
    echo "HTTP-equivalent $status  $error\n";
    exit($code);
}

if (!assistantEnabled()) {
    fail(3, 503, 'assistant_disabled');
}
$stmt = $conn->prepare("SELECT id, role, username, email FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    fwrite(STDERR, "no such user: $username\n");
    exit(2);
}
if ($user['role'] !== 'admin') {
    fail(4, 403, 'forbidden (not an admin)');
}
$client = ClaudeClient::fromEnv();
if ($client === null) {
    fail(3, 503, 'assistant_not_configured');
}
try {
    list($message, $conversationId) = assistantParseBody(['message' => $question, 'conversation_id' => $conversationId]);
} catch (InvalidArgumentException $e) {
    fail(2, 400, $e->getMessage());
}
ensureAssistantTables($conn);
$used = assistantTokensToday($conn, assistantNow());
if ($used >= assistantDailyTokenCap()) {
    fail(5, 429, "daily_cap_reached ($used of " . assistantDailyTokenCap() . ")");
}

$result = assistantHandle($conn, $client, $user, $message, $conversationId);
$b = $result['body'];
if ($result['status'] !== 200) {
    fail($result['status'] === 404 ? 2 : 6, $result['status'], $b['error']);
}
echo $b['text'] . "\n\n";
echo "---\n";
echo "conversation_id: " . $b['conversation_id'] . "   log_id: " . $b['meta']['log_id'] . "   model: " . $client->model() . "\n";
echo "tools called:    " . (count($b['meta']['tools_called']) ? implode(', ', $b['meta']['tools_called']) : '(none)') . "\n";
echo "tokens:          in " . $b['meta']['input_tokens'] . " / out " . $b['meta']['output_tokens']
    . "   (today so far: " . ($used + $b['meta']['input_tokens'] + $b['meta']['output_tokens']) . " of " . assistantDailyTokenCap() . ")\n";
echo "ms:              " . $b['meta']['ms'] . "\n";
exit(0);
