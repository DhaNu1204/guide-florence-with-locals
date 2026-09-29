<?php
/**
 * Step 7.1: the assistant's engine, shared by api/assistant.php (HTTP) and tools/assistant_ask.php
 * (CLI) so both run exactly the same guards, prompt, tool loop and logging.
 *
 * Tables (database/migrations/20260928_assistant_tables.sql, self-provisioned here):
 *   assistant_conversations  one row per chat
 *   assistant_messages       the chat as the user saw it: user question, assistant answer (content_json)
 *   assistant_logs           one row per question: tools offered/called, tokens, ms, error
 * Tool calls stay inside one request; only the question and the final answer are kept as history.
 */

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/assistant_tools.php';

const ASSISTANT_MAX_ROUNDS = 6;          // model calls per question; the last one may not call tools
const ASSISTANT_TIME_BUDGET = 40;        // seconds for the whole request (the host cuts at 60)
const ASSISTANT_MIN_CALL_SECONDS = 6;    // don't start a model call with less time than this left
const ASSISTANT_MAX_MESSAGE = 1000;      // characters per question
const ASSISTANT_HISTORY_MESSAGES = 12;   // earlier question/answer rows sent back as context
const ASSISTANT_RETENTION_DAYS = 30;
const ASSISTANT_RATE_LIMIT = 30;         // requests per user per minute
const ASSISTANT_DEFAULT_TOKEN_CAP = 300000;

function assistantEnabled() {
    return class_exists('EnvLoader') && EnvLoader::getBool('ASSISTANT_ENABLED', false);
}

function assistantDailyTokenCap() {
    $cap = class_exists('EnvLoader') ? EnvLoader::getInt('ASSISTANT_DAILY_TOKEN_CAP', ASSISTANT_DEFAULT_TOKEN_CAP) : ASSISTANT_DEFAULT_TOKEN_CAP;
    return $cap > 0 ? $cap : ASSISTANT_DEFAULT_TOKEN_CAP;
}

function ensureAssistantTables($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS assistant_conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assistant_conv_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS assistant_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            role ENUM('user','assistant') NOT NULL,
            content_json JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assistant_msg_conv (conversation_id, id),
            KEY idx_assistant_msg_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS assistant_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            conversation_id INT UNSIGNED NULL,
            question TEXT NOT NULL,
            model VARCHAR(64) NULL,
            tools_offered JSON NULL,
            tools_called JSON NULL,
            rounds TINYINT UNSIGNED NOT NULL DEFAULT 0,
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cache_read_tokens INT UNSIGNED NULL,
            cache_write_tokens INT UNSIGNED NULL,
            ms INT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_assistant_logs_created (created_at),
            KEY idx_assistant_logs_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // step 7.2: prompt-caching token counts (existing 7.1 tables get the columns here)
    foreach (['cache_read_tokens', 'cache_write_tokens'] as $col) {
        $has = $conn->query("SHOW COLUMNS FROM assistant_logs LIKE '$col'");
        if ($has && $has->num_rows === 0) {
            $conn->query("ALTER TABLE assistant_logs ADD COLUMN `$col` INT UNSIGNED NULL AFTER output_tokens");
        }
    }
}

/** Now, Europe/Rome. The DB session runs in UTC (step 2.1); PHP runs in Europe/Rome. */
function assistantNow() {
    return new DateTime('now', new DateTimeZone('Europe/Rome'));
}

/**
 * Tokens spent since midnight Europe/Rome (created_at is compared in UTC, the DB session zone).
 * Step 7.2: cache writes count in full, cache reads at one tenth - the same ratio as their price
 * (a cache read costs 0.1x an input token). Counting reads in full would spend the cap about ten
 * times faster than the money it stands for (~10k cached tokens are re-read on every question).
 */
function assistantTokensToday($conn, DateTime $now) {
    $midnight = new DateTime($now->format('Y-m-d') . ' 00:00:00', new DateTimeZone('Europe/Rome'));
    $midnight->setTimezone(new DateTimeZone('UTC'));
    $since = $midnight->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT COALESCE(SUM(input_tokens + output_tokens + COALESCE(cache_write_tokens, 0) + CEIL(COALESCE(cache_read_tokens, 0) / 10)), 0) AS used FROM assistant_logs WHERE created_at >= ?");
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $used = (int) $stmt->get_result()->fetch_assoc()['used'];
    $stmt->close();
    return $used;
}

/**
 * Validate the request body. Returns [message, conversationId|null] or throws
 * InvalidArgumentException with a short client-safe reason.
 */
function assistantParseBody($body) {
    if (!is_array($body)) {
        throw new InvalidArgumentException('body must be JSON: {"message": "...", "conversation_id": optional}');
    }
    $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';
    if ($message === '') {
        throw new InvalidArgumentException('message is required');
    }
    if (mb_strlen($message, 'UTF-8') > ASSISTANT_MAX_MESSAGE) {
        throw new InvalidArgumentException('message is longer than ' . ASSISTANT_MAX_MESSAGE . ' characters');
    }
    $conv = null;
    if (array_key_exists('conversation_id', $body) && $body['conversation_id'] !== null && $body['conversation_id'] !== '') {
        if (!is_numeric($body['conversation_id']) || (int) $body['conversation_id'] <= 0) {
            throw new InvalidArgumentException('conversation_id must be a positive number');
        }
        $conv = (int) $body['conversation_id'];
    }
    return [$message, $conv];
}

/**
 * Step 7.2: the fixed half of the system prompt. It never changes between requests, so it sits
 * right after the tool definitions and carries the cache breakpoint (tools + rules are cached).
 */
function assistantSystemRules() {
    return "You are the assistant inside the Florence with Locals tour management app (walking tours in Florence, Italy). "
        . "You talk to the company's admins.\n"
        . "Rules:\n"
        . "- Reply in the language of the user's latest message: a question in English gets an English answer, "
        . "a question in Italian an Italian answer - every sentence, including any follow-up question. The company "
        . "being in Italy does not change this. Spelling mistakes are normal.\n"
        . "- Answer briefly.\n"
        . "- Use the tools for every fact about tours, guests, guides or money. Never guess or estimate a number; "
        . "if no tool can answer, say so plainly.\n"
        . "- A departure is one tour run by one guide; a merged group counts as one departure. Guests = PAX.\n"
        . "- Dates: use the ranges given under \"Dates\" below, never work them out yourself. \"this week\" = today to "
        . "Sunday; \"this month\" = today to the last day of the month; \"tomorrow\" and \"weekend\" as listed. "
        . "Always state the range you used, e.g. \"from Mon 28 Sep to Wed 30 Sep\".\n"
        . "- Write dates like \"Mon 28 Sep\" (in an Italian answer with Italian names: \"lun 28 set\") and times as 24-hour HH:MM.\n"
        . "- Guides: resolve a name with find_guide. If it is not confident, do not pick one: ask which guide, "
        . "with a choices block listing the matches.\n"
        . "- When a time matches several departures, list them all (or ask with a choices block); never pick one silently.\n"
        . "- When a tool returns a list (departures, guides), call show_blocks once with it (departure_list for "
        . "departures, table for guides, stat for a single key number) and keep the text to one or two sentences: "
        . "the total and the range, written in the same turn as the show_blocks call. The text must still contain "
        . "the key numbers (it is also read without the blocks). If a list was truncated, say how many there are in total.\n"
        . "- free_guides assumes every tour lasts 2 hours; mention that in the answer.\n"
        // step 7.3: money
        . "- Money: \"income\", \"incasso\", \"guadagno\", \"revenue\", \"earnings\" = the Net Revenue of tours RUNNING in the "
        . "range, from the money tool (the Daily P&L figures); profit, costs and margin come from the same tool. For a money "
        . "question about the current month use the \"month so far\" range unless the whole month is asked for; \"last week\" "
        . "and \"yesterday\" as listed; a month name = that whole month (the most recent one that has started).\n"
        . "- Figures for NEW SALES / bookings made on a date do not exist here: say so in one sentence and offer the money "
        . "for tours running that day instead.\n"
        . "- Write money in euro with 2 decimals: \"€1,234.50\" in English, \"1.234,50 €\" in Italian. Answer money with a "
        . "stat block per figure asked (Net Revenue, Total Costs, Profit, or the one cost line asked) plus a link block "
        . "{route: /daily-pnl, query: {date}} for one day or {start_date, end_date} for a range. Mention it when some "
        . "departures are estimated (estimated_departures > 0).\n"
        . "- Whether this user may see money is stated under \"Access\" below; follow it exactly. Never estimate or work "
        . "out money from any other data.\n"
        . "- Plain text only in the answer: no markdown tables, no headings, no bullet lists of data that is already in a block.";
}

/** Step 7.2: the per-request half - who is asking and today's date ranges (Europe/Rome). */
function assistantDateRanges(DateTime $now) {
    $d = function ($dt) { return $dt->format('D j M') . ' (' . $dt->format('Y-m-d') . ')'; };
    $today = clone $now; $today->setTime(0, 0);
    $tomorrow = (clone $today)->modify('+1 day');
    $dow = (int) $today->format('N'); // 1 = Mon ... 7 = Sun
    $sunday = (clone $today)->modify('+' . (7 - $dow) . ' days');
    if ($dow >= 6) {
        $weekendStart = clone $today;
        $weekendEnd = $sunday;
    } else {
        $weekendStart = (clone $today)->modify('+' . (6 - $dow) . ' days');
        $weekendEnd = $sunday;
    }
    $nextMon = (clone $sunday)->modify('+1 day');
    $nextSun = (clone $nextMon)->modify('+6 days');
    $monthEnd = (clone $today)->modify('last day of this month');
    $nextMonthStart = (clone $today)->modify('first day of next month');
    $nextMonthEnd = (clone $today)->modify('last day of next month');
    // step 7.3: the past ranges money questions use
    $yesterday = (clone $today)->modify('-1 day');
    $lastMon = (clone $today)->modify('-' . ($dow + 6) . ' days');
    $lastSun = (clone $lastMon)->modify('+6 days');
    $monthStart = (clone $today)->modify('first day of this month');
    $lastMonthStart = (clone $today)->modify('first day of last month');
    $lastMonthEnd = (clone $today)->modify('last day of last month');
    return [
        'today' => $d($today),
        'yesterday' => $d($yesterday),
        'tomorrow' => $d($tomorrow),
        'last week' => $d($lastMon) . ' to ' . $d($lastSun),
        'month so far' => $d($monthStart) . ' to ' . $d($today),
        'last month' => $d($lastMonthStart) . ' to ' . $d($lastMonthEnd),
        'this week' => $d($today) . ' to ' . $d($sunday),
        'weekend' => $d($weekendStart) . ' to ' . $d($weekendEnd),
        'next week' => $d($nextMon) . ' to ' . $d($nextSun),
        'this month' => $d($today) . ' to ' . $d($monthEnd),
        'next month' => $d($nextMonthStart) . ' to ' . $d($nextMonthEnd),
    ];
}

function assistantSystemContext(array $user, DateTime $now) {
    $name = isset($user['username']) && $user['username'] !== '' ? $user['username'] : 'the user';
    $lines = "You are talking to {$name}. Now it is " . $now->format('l j F Y, H:i') . " in Florence (Europe/Rome).\nDates:\n";
    foreach (assistantDateRanges($now) as $k => $v) {
        $lines .= "- {$k}: {$v}\n";
    }
    // step 7.3: money access, per user (the same check that decides whether the money tool is offered)
    if (class_exists('Middleware') && Middleware::isPnlOwner($user)) {
        $lines .= "Access: this user is the owner and may see money figures (money tool).";
    } else {
        $lines .= "Access: this user has NO access to money figures and you have no tool for them. For any question about "
            . "income, revenue, costs, profit, margin, sales or prices, reply in one or two polite sentences that money "
            . "figures are only available on the owner's account, then offer tour and guest counts. Do not mention tools, "
            . "do not offer any money figure, total or margin, and give no amount.";
    }
    return $lines;
}

/** The system prompt as two blocks: fixed rules (cache breakpoint) + today's context. */
function assistantSystemPrompt(array $user, DateTime $now) {
    return [
        ['type' => 'text', 'text' => assistantSystemRules(), 'cache_control' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => assistantSystemContext($user, $now)],
    ];
}

/** The earlier question/answer rows of this conversation, oldest first, as API messages. */
function assistantHistory($conn, $conversationId) {
    $limit = ASSISTANT_HISTORY_MESSAGES;
    $stmt = $conn->prepare("SELECT role, content_json FROM assistant_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?");
    $stmt->bind_param('ii', $conversationId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    $rows = array_reverse($rows);
    $messages = [];
    foreach ($rows as $r) {
        $c = json_decode($r['content_json'], true);
        $text = is_array($c) && isset($c['text']) ? (string) $c['text'] : '';
        if ($text === '') {
            continue;
        }
        // keep strict user/assistant alternation, starting with a user turn
        if (count($messages) === 0 && $r['role'] !== 'user') {
            continue;
        }
        if (count($messages) > 0 && $messages[count($messages) - 1]['role'] === $r['role']) {
            $messages[count($messages) - 1]['content'] .= "\n" . $text;
            continue;
        }
        $messages[] = ['role' => $r['role'], 'content' => $text];
    }
    if (count($messages) > 0 && $messages[count($messages) - 1]['role'] === 'user') {
        array_pop($messages); // a question that never got an answer
    }
    return $messages;
}

/** Owner check for a conversation id. Returns true when it exists and belongs to the user. */
function assistantOwnsConversation($conn, $conversationId, $userId) {
    $stmt = $conn->prepare("SELECT 1 FROM assistant_conversations WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $conversationId, $userId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function assistantStoreMessage($conn, $conversationId, $role, array $content) {
    $json = json_encode($content, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO assistant_messages (conversation_id, role, content_json) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $conversationId, $role, $json);
    $stmt->execute();
    $stmt->close();
}

/** Roughly 1 request in 100 removes chats and logs older than the retention window. */
function assistantPrune($conn) {
    if (mt_rand(1, 100) !== 1) {
        return;
    }
    $days = ASSISTANT_RETENTION_DAYS;
    $conn->query("DELETE FROM assistant_messages WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
    $conn->query("DELETE c FROM assistant_conversations c LEFT JOIN assistant_messages m ON m.conversation_id = c.id
                  WHERE m.id IS NULL AND c.created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
    $conn->query("DELETE FROM assistant_logs WHERE created_at < UTC_TIMESTAMP() - INTERVAL $days DAY");
}

/**
 * The tool loop. Pure of HTTP and of the database except through $conn (tools, nothing else):
 * tools/assistant_check.php drives it with a fake client.
 *
 * @param object $client  anything with createMessage(array $payload, int $timeout): array
 * @param array  $history earlier API messages (user/assistant text)
 * @return array {text, blocks[], tools_called[], rounds, input_tokens, output_tokens, cache_read_tokens,
 *                cache_write_tokens, error|null, stop}
 */
function assistantRunLoop($client, $conn, array $user, array $tools, array $history, $message, DateTime $now, $startedAt = null, $timeBudget = ASSISTANT_TIME_BUDGET) {
    $startedAt = $startedAt !== null ? $startedAt : microtime(true);
    $byName = [];
    foreach ($tools as $t) {
        $byName[$t['name']] = $t;
    }
    $messages = $history;
    $messages[] = ['role' => 'user', 'content' => $message];
    $payloadBase = [
        'max_tokens' => 2048,
        'system' => assistantSystemPrompt($user, $now),
        // step 7.2: automatic breakpoint on the growing tail (tool rounds re-read it); the fixed
        // tools + rules prefix has its own explicit breakpoint in the system prompt.
        'cache_control' => ['type' => 'ephemeral'],
    ];
    $model = method_exists($client, 'model') ? (string) $client->model() : '';
    if ($model === '' || strpos($model, 'claude-haiku') !== 0) {
        // Short lookups: low effort keeps a round well inside the time budget (Haiku has no effort).
        $payloadBase['output_config'] = ['effort' => 'low'];
    }
    if (count($tools) > 0) {
        $payloadBase['tools'] = assistantToolDefinitions($tools);
    }

    $out = ['text' => '', 'blocks' => [], 'tools_called' => [], 'rounds' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'error' => null, 'stop' => null];
    $blocks = new ArrayObject(); // filled by show_blocks, validated there
    $lastText = '';
    $answerParts = []; // step 7.2: the text written together with show_blocks, if any
    for ($round = 1; ; $round++) {
        $left = $timeBudget - (microtime(true) - $startedAt);
        if ($left < ASSISTANT_MIN_CALL_SECONDS) {
            $out['stop'] = 'time_budget';
            break;
        }
        $payload = $payloadBase;
        $payload['messages'] = $messages;
        if ($round >= ASSISTANT_MAX_ROUNDS) {
            $payload['tool_choice'] = ['type' => 'none']; // last call: answer with what it has
        }
        $resp = $client->createMessage($payload, (int) min(ClaudeClient::DEFAULT_TIMEOUT, floor($left)));
        $out['rounds'] = $round;
        $u = isset($resp['usage']) && is_array($resp['usage']) ? $resp['usage'] : [];
        // step 7.2: input_tokens = uncached input only; cache writes and reads are counted apart
        $out['input_tokens'] += (int) (isset($u['input_tokens']) ? $u['input_tokens'] : 0);
        $out['cache_write_tokens'] += (int) (isset($u['cache_creation_input_tokens']) ? $u['cache_creation_input_tokens'] : 0);
        $out['cache_read_tokens'] += (int) (isset($u['cache_read_input_tokens']) ? $u['cache_read_input_tokens'] : 0);
        $out['output_tokens'] += (int) (isset($u['output_tokens']) ? $u['output_tokens'] : 0);

        $content = isset($resp['content']) && is_array($resp['content']) ? $resp['content'] : [];
        $texts = [];
        $uses = [];
        foreach ($content as $block) {
            $type = isset($block['type']) ? $block['type'] : '';
            if ($type === 'text' && isset($block['text'])) {
                $texts[] = $block['text'];
            } elseif ($type === 'tool_use') {
                $uses[] = $block;
            }
        }
        if (count($texts) > 0) {
            $lastText = trim(implode("\n", $texts));
        }
        $stop = isset($resp['stop_reason']) ? $resp['stop_reason'] : null;
        $out['stop'] = $stop;
        $final = ($stop !== 'tool_use' || count($uses) === 0);
        // Step 7.2: the model writes the real answer in the same turn as its show_blocks call; what it
        // adds after the tool result is a repeat or a follow-up offer (and on staging drifted into the
        // other language), so the show_blocks text wins. Without it, the final text is the answer.
        // Text next to data-tool calls ("let me check") is never part of the answer.
        $withBlocks = count(array_filter($uses, function ($u) { return isset($u['name']) && $u['name'] === 'show_blocks'; })) > 0;
        if ($withBlocks && count($texts) > 0 && $lastText !== '') {
            $answerParts = [$lastText];
        }
        if ($final) {
            break;
        }

        // Run every requested tool; all results go back in ONE user message.
        $messages[] = ['role' => 'assistant', 'content' => $content];
        $results = [];
        foreach ($uses as $use) {
            $name = isset($use['name']) ? (string) $use['name'] : '';
            $input = isset($use['input']) && is_array($use['input']) ? $use['input'] : [];
            $t0 = microtime(true);
            $ok = true;
            try {
                if (!isset($byName[$name])) {
                    throw new AssistantToolError('unknown tool: ' . $name);
                }
                $data = call_user_func($byName[$name]['handler'], $conn, $input, ['user' => $user, 'now' => $now, 'blocks' => $blocks]);
                $resultText = json_encode($data, JSON_UNESCAPED_UNICODE);
            } catch (AssistantToolError $e) {
                $ok = false;
                $resultText = $e->getMessage();
            } catch (Throwable $e) {
                $ok = false;
                error_log('assistant tool ' . $name . ' failed: ' . $e->getMessage());
                $resultText = 'the tool failed with an internal error';
            }
            $logged = ($name === 'show_blocks') ? ['blocks' => isset($input['blocks']) && is_array($input['blocks']) ? count($input['blocks']) : 0] : $input;
            $out['tools_called'][] = ['name' => $name, 'input' => $logged, 'ok' => $ok, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
            $r = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $resultText];
            if (!$ok) {
                $r['is_error'] = true;
            }
            $results[] = $r;
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }

    $out['text'] = count($answerParts) > 0 ? implode("\n", $answerParts) : $lastText;
    $out['blocks'] = $blocks->getArrayCopy();
    if ($out['text'] === '') {
        $out['text'] = ($out['stop'] === 'time_budget')
            ? 'Sorry, that took too long to work out. Please ask again, or ask something narrower.'
            : 'Sorry, I could not produce an answer to that.';
    }
    return $out;
}

function assistantWriteLog($conn, array $row) {
    $offered = json_encode(isset($row['tools_offered']) ? $row['tools_offered'] : []);
    $called = json_encode(isset($row['tools_called']) ? $row['tools_called'] : [], JSON_UNESCAPED_UNICODE);
    $error = isset($row['error']) && $row['error'] !== null ? mb_substr((string) $row['error'], 0, 500, 'UTF-8') : null;
    $conv = isset($row['conversation_id']) ? $row['conversation_id'] : null;
    $model = isset($row['model']) ? $row['model'] : null;
    $rounds = (int) (isset($row['rounds']) ? $row['rounds'] : 0);
    $in = (int) (isset($row['input_tokens']) ? $row['input_tokens'] : 0);
    $outT = (int) (isset($row['output_tokens']) ? $row['output_tokens'] : 0);
    $cRead = isset($row['cache_read_tokens']) ? (int) $row['cache_read_tokens'] : null;
    $cWrite = isset($row['cache_write_tokens']) ? (int) $row['cache_write_tokens'] : null;
    $ms = (int) (isset($row['ms']) ? $row['ms'] : 0);
    $stmt = $conn->prepare("INSERT INTO assistant_logs
        (user_id, conversation_id, question, model, tools_offered, tools_called, rounds, input_tokens, output_tokens,
         cache_read_tokens, cache_write_tokens, ms, error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissssiiiiiis', $row['user_id'], $conv, $row['question'], $model, $offered, $called, $rounds, $in, $outT,
        $cRead, $cWrite, $ms, $error);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * One question end to end: conversation, history, loop, storage, log row.
 * The caller has already checked enabled / key / role / rate limit / daily cap.
 *
 * @return array {status, body} - body is what the client gets
 */
function assistantHandle($conn, $client, array $user, $message, $conversationId) {
    $started = microtime(true);
    $now = assistantNow();
    $userId = (int) $user['id'];

    if ($conversationId !== null && !assistantOwnsConversation($conn, $conversationId, $userId)) {
        return ['status' => 404, 'body' => ['success' => false, 'error' => 'conversation_not_found']];
    }
    if ($conversationId === null) {
        $stmt = $conn->prepare("INSERT INTO assistant_conversations (user_id) VALUES (?)");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $conversationId = (int) $conn->insert_id;
        $stmt->close();
        $history = [];
    } else {
        $history = assistantHistory($conn, $conversationId);
    }
    assistantStoreMessage($conn, $conversationId, 'user', ['text' => $message]);

    $tools = assistantToolsForUser($user);
    $offered = array_map(function ($t) { return $t['name']; }, $tools);
    $log = [
        'user_id' => $userId, 'conversation_id' => $conversationId, 'question' => $message,
        'model' => $client->model(), 'tools_offered' => $offered, 'tools_called' => [],
    ];

    try {
        $result = assistantRunLoop($client, $conn, $user, $tools, $history, $message, $now, $started);
    } catch (ClaudeApiException $e) {
        $log['error'] = $e->getMessage();
        $log['ms'] = (int) round((microtime(true) - $started) * 1000);
        assistantWriteLog($conn, $log);
        error_log('assistant: ' . $e->getMessage());
        $busy = in_array($e->status, [429, 529], true) || $e->errorType === 'overloaded_error' || $e->errorType === 'timeout';
        return ['status' => 502, 'body' => ['success' => false, 'error' => $busy ? 'assistant_busy' : 'assistant_upstream_error', 'conversation_id' => $conversationId]];
    }

    assistantStoreMessage($conn, $conversationId, 'assistant', ['text' => $result['text'], 'blocks' => $result['blocks']]);
    $log['tools_called'] = $result['tools_called'];
    $log['rounds'] = $result['rounds'];
    $log['input_tokens'] = $result['input_tokens'];
    $log['output_tokens'] = $result['output_tokens'];
    $log['cache_read_tokens'] = $result['cache_read_tokens'];
    $log['cache_write_tokens'] = $result['cache_write_tokens'];
    $log['error'] = ($result['stop'] === 'time_budget') ? 'time_budget' : null;
    $log['ms'] = (int) round((microtime(true) - $started) * 1000);
    $logId = assistantWriteLog($conn, $log);
    assistantPrune($conn);

    return ['status' => 200, 'body' => [
        'success' => true,
        'conversation_id' => $conversationId,
        'text' => $result['text'],
        'blocks' => $result['blocks'],
        // for the CLI and the logs; the UI (7.4) ignores these
        'meta' => [
            'log_id' => $logId,
            'tools_called' => array_map(function ($c) { return $c['name']; }, $result['tools_called']),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cache_read_tokens' => $result['cache_read_tokens'],
            'cache_write_tokens' => $result['cache_write_tokens'],
            'ms' => $log['ms'],
        ],
    ]];
}
