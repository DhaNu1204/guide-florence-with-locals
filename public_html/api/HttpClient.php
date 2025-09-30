<?php
class HttpClient {
    private $timeout = 30;
    
    public function request($method, $url, $headers = [], $data = null) {
        // Create context options
        $contextOptions = [
            'http' => [
                'method' => $method,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
                'user_agent' => 'Florence-Guides/1.0'
            ]
        ];
        
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            $contextOptions['http']['content'] = $data;
        }
        
        // Create context
        $context = stream_context_create($contextOptions);
        
        // Make request
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception('HTTP Request failed: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        // Get HTTP response code
        $httpCode = 200; // Default
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }
        
        return [
            'body' => $response,
            'http_code' => $httpCode,
            'headers' => isset($http_response_header) ? $http_response_header : []
        ];
    }
    
    public function get($url, $headers = []) {
        return $this->request('GET', $url, $headers);
    }
    
    public function post($url, $data = null, $headers = []) {
        return $this->request('POST', $url, $headers, $data);
    }
}
?>