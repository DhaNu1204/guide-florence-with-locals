<?php
/**
 * Step 7.1: the smallest possible Claude Messages API client (plain cURL, no SDK).
 *
 *   $client = ClaudeClient::fromEnv();              // ANTHROPIC_API_KEY, ASSISTANT_MODEL
 *   $resp   = $client->createMessage($payload, 25); // decoded JSON, or throws ClaudeApiException
 *
 * The key is only ever placed in the x-api-key header. It is never logged, never part of an
 * exception message and never returned to a caller.
 */

class ClaudeApiException extends Exception {
    /** @var int HTTP status (0 = no response: network error or timeout) */
    public $status;
    /** @var string Anthropic error type, e.g. rate_limit_error, overloaded_error */
    public $errorType;

    public function __construct($message, $status = 0, $errorType = '') {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->errorType = (string) $errorType;
    }
}

class ClaudeClient {
    const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';
    const DEFAULT_MODEL = 'claude-sonnet-5';
    const DEFAULT_TIMEOUT = 30;

    private $apiKey;
    private $model;

    public function __construct($apiKey, $model = null) {
        $this->apiKey = (string) $apiKey;
        $this->model = ($model !== null && trim((string) $model) !== '') ? trim((string) $model) : self::DEFAULT_MODEL;
    }

    /** Key and model from the server env. Returns null when no key is configured. */
    public static function fromEnv() {
        $key = self::envKey();
        if ($key === '') {
            return null;
        }
        return new self($key, class_exists('EnvLoader') ? EnvLoader::get('ASSISTANT_MODEL', self::DEFAULT_MODEL) : self::DEFAULT_MODEL);
    }

    public static function envKey() {
        $key = class_exists('EnvLoader') ? EnvLoader::get('ANTHROPIC_API_KEY', '') : getenv('ANTHROPIC_API_KEY');
        return is_string($key) ? trim($key) : '';
    }

    public function model() {
        return $this->model;
    }

    /**
     * POST /v1/messages. `model` is filled in when the payload has none.
     *
     * @param array $payload Messages API request body
     * @param int|null $timeoutSeconds Whole-request timeout (default 30 s)
     * @return array Decoded response
     * @throws ClaudeApiException
     */
    public function createMessage(array $payload, $timeoutSeconds = null) {
        if (!isset($payload['model'])) {
            $payload['model'] = $this->model;
        }
        $timeout = $timeoutSeconds !== null ? max(1, (int) $timeoutSeconds) : self::DEFAULT_TIMEOUT;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new ClaudeApiException('request could not be encoded: ' . json_last_error_msg());
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            $kind = ($errno === CURLE_OPERATION_TIMEDOUT) ? 'timeout' : 'network error';
            throw new ClaudeApiException("Claude API $kind: $err", 0, $kind === 'timeout' ? 'timeout' : 'network_error');
        }

        $data = json_decode($raw, true);
        if ($status !== 200) {
            $msg = (is_array($data) && isset($data['error']['message'])) ? (string) $data['error']['message'] : 'HTTP ' . $status;
            $type = (is_array($data) && isset($data['error']['type'])) ? (string) $data['error']['type'] : '';
            throw new ClaudeApiException('Claude API error ' . $status . ': ' . $msg, $status, $type);
        }
        if (!is_array($data)) {
            throw new ClaudeApiException('Claude API returned invalid JSON', $status, 'invalid_response');
        }
        return $data;
    }
}
