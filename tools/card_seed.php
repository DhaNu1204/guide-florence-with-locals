<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 7.5): never deployed
/**
 * Step 7.5 - put a FRESH confirm card into a staging chat without calling the model (for the
 * on-screen card checks when the daily cap is reached). The card is built by the real
 * propose_assignment tool; the chat is stored like any other and opens from "Recent".
 *
 *   FWL_API_DIR=<api dir> php82 tools/card_seed.php --user=dhanu --dep=g123 --guide=22
 */
$apiDir = getenv('FWL_API_DIR');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/cli';
require $apiDir . '/config.php';
require_once $apiDir . '/Middleware.php';
require_once $apiDir . '/lib/assistant_core.php';
$o = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)=(.+)$/', $a, $m)) $o[$m[1]] = $m[2];
if (strcasecmp((string) EnvLoader::get('APP_ENV', ''), 'staging') !== 0) { fwrite(STDERR, "staging only\n"); exit(2); }
$s = $conn->prepare("SELECT id, role, username FROM users WHERE username = ?");
$s->bind_param('s', $o['user']); $s->execute(); $user = $s->get_result()->fetch_assoc(); $s->close();
ensureAssistantTables($conn);
$blocks = new ArrayObject();
$res = assistantToolProposeAssignment($conn, ['departure_id' => $o['dep'], 'guide_id' => (int) $o['guide']],
    ['user' => $user, 'now' => new DateTime('now', new DateTimeZone('Europe/Rome')), 'blocks' => $blocks]);
if (empty($res['card_shown'])) { echo "no card: " . json_encode($res) . "\n"; exit(1); }
$uid = (int) $user['id'];
$st = $conn->prepare("INSERT INTO assistant_conversations (user_id) VALUES (?)");
$st->bind_param('i', $uid); $st->execute(); $cid = (int) $conn->insert_id; $st->close();
assistantStoreMessage($conn, $cid, 'user', ['text' => '[step 7.5 card check, no model] assign ' . $res['guide'] . ' to ' . $res['departure']]);
assistantStoreMessage($conn, $cid, 'assistant', ['text' => 'Card for the on-screen check - nothing changes until you tap Confirm.', 'blocks' => $blocks->getArrayCopy()]);
echo "conversation $cid: " . json_encode($blocks->getArrayCopy()[0]['whatsapp']) . ' issued ' . $blocks->getArrayCopy()[0]['issued_at'] . "\n";
