<?php
/**
 * BaseAPI.php - Base Class for API Endpoints
 *
 * Provides common functionality for all API endpoints:
 * - Request method routing
 * - JSON response helpers
 * - Error handling
 * - Input parsing
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Logger.php';

abstract class BaseAPI {

    protected $conn;
    protected $method;
    protected $requestData;
    protected $pathSegments;
    protected $resourceId;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->parseRequest();
    }

    /**
     * Parse incoming request
     */
    protected function parseRequest() {
        // Parse URL path
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->pathSegments = explode('/', rtrim($path, '/'));

        // Extract resource ID if present (last segment if numeric)
        $lastSegment = end($this->pathSegments);
        if (is_numeric($lastSegment)) {
            $this->resourceId = intval($lastSegment);
        }

        // Parse request body for POST/PUT
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            $rawData = file_get_contents('php://input');
            $this->requestData = json_decode($rawData, true);

            if ($rawData && !$this->requestData) {
                $this->sendError('Invalid JSON data', 400);
            }
        }

        // Parse query parameters for GET
        $this->queryParams = $_GET;
    }

    /**
     * Main request handler - routes to appropriate method
     */
    public function handleRequest() {
        try {
            switch ($this->method) {
                case 'GET':
                    if ($this->resourceId) {
                        $this->getOne($this->resourceId);
                    } else {
                        $this->getAll();
                    }
                    break;

                case 'POST':
                    $this->create();
                    break;

                case 'PUT':
                case 'PATCH':
                    if (!$this->resourceId) {
                        $this->sendError('Resource ID required for update', 400);
                    }
                    $this->update($this->resourceId);
                    break;

                case 'DELETE':
                    if (!$this->resourceId) {
                        $this->sendError('Resource ID required for delete', 400);
                    }
                    $this->delete($this->resourceId);
                    break;

                case 'OPTIONS':
                    // Handled by config.php
                    http_response_code(200);
                    exit();

                default:
                    $this->sendError('Method not allowed', 405);
            }
        } catch (ValidationException $e) {
            $this->sendError($e->getMessage(), 400);
        } catch (Exception $e) {
            Logger::error('API Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->sendError(
                DEBUG ? $e->getMessage() : 'Internal server error',
                500
            );
        }
    }

    /**
     * Abstract methods - must be implemented by child classes
     */
    abstract protected function getAll();
    abstract protected function getOne($id);
    abstract protected function create();
    abstract protected function update($id);
    abstract protected function delete($id);

    /**
     * Send JSON success response
     */
    protected function sendSuccess($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }

    /**
     * Send JSON error response
     */
    protected function sendError($message, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode(['error' => $message]);
        exit();
    }

    /**
     * Send paginated response
     */
    protected function sendPaginated($data, $total, $page, $perPage) {
        $this->sendSuccess([
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ]);
    }

    /**
     * Get request data field with default
     */
    protected function getField($key, $default = null) {
        return isset($this->requestData[$key]) ? $this->requestData[$key] : $default;
    }

    /**
     * Get query parameter with default
     */
    protected function getQueryParam($key, $default = null) {
        return isset($this->queryParams[$key]) ? $this->queryParams[$key] : $default;
    }

    /**
     * Require specific fields in request
     */
    protected function requireFields($fields) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($this->requestData[$field]) || $this->requestData[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $this->sendError('Missing required fields: ' . implode(', ', $missing), 400);
        }
    }

    /**
     * Validate request data using Validator
     */
    protected function validate($rules) {
        $result = Validator::validateAll($rules);

        if (!$result['valid']) {
            $firstError = reset($result['errors']);
            $this->sendError($firstError, 400);
        }

        return true;
    }

    /**
     * Execute a prepared statement and return result
     */
    protected function executeQuery($sql, $types = '', $params = []) {
        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Query preparation failed: ' . $this->conn->error);
        }

        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception('Query execution failed: ' . $stmt->error);
        }

        return $stmt;
    }

    /**
     * Execute query and fetch all results
     */
    protected function fetchAll($sql, $types = '', $params = []) {
        $stmt = $this->executeQuery($sql, $types, $params);
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Execute query and fetch single result
     */
    protected function fetchOne($sql, $types = '', $params = []) {
        $stmt = $this->executeQuery($sql, $types, $params);
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Log request for debugging
     */
    protected function logRequest() {
        Logger::info('API Request', [
            'method' => $this->method,
            'uri' => $_SERVER['REQUEST_URI'],
            'data' => $this->requestData
        ]);
    }
}
