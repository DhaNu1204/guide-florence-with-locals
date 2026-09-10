<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script (step 0.2): never reachable over HTTP
/**
 * Compression Test Endpoint
 *
 * Tests if gzip compression is working for API responses.
 *
 * Usage:
 *   curl -H "Accept-Encoding: gzip" -I https://withlocals.deetech.cc/api/compression_test.php
 *   curl -H "Accept-Encoding: gzip" -s https://withlocals.deetech.cc/api/compression_test.php | gunzip
 */

require_once __DIR__ . '/../public_html/api/config.php';

// Generate test data (large enough to show compression benefit)
$testData = [
    'status' => 'ok',
    'compression_test' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => [
        'php_version' => PHP_VERSION,
        'zlib_available' => function_exists('ob_gzhandler'),
        'mod_deflate' => function_exists('apache_get_modules') ? in_array('mod_deflate', apache_get_modules()) : 'unknown',
        'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'none',
        'gzip_enabled_php' => isset($gzipEnabled) ? $gzipEnabled : false,
    ],
    'headers' => [
        'content_encoding' => 'check response headers for Content-Encoding: gzip',
        'vary' => 'Accept-Encoding'
    ],
    // Add some bulk data to make compression worthwhile
    'sample_tours' => []
];

// Generate sample data to demonstrate compression
for ($i = 1; $i <= 20; $i++) {
    $testData['sample_tours'][] = [
        'id' => $i,
        'title' => "Sample Tour $i - David and Accademia Gallery VIP Guided Tour",
        'description' => "Experience the magnificence of Michelangelo's David and explore the Accademia Gallery with an expert guide. Skip the long lines and enjoy an intimate tour of one of Florence's most famous museums.",
        'date' => date('Y-m-d', strtotime("+$i days")),
        'time' => sprintf('%02d:00', 9 + ($i % 8)),
        'duration' => '2 hours',
        'price' => 89.00 + ($i * 10),
        'participants' => rand(1, 8),
        'guide' => "Guide $i",
        'language' => ['English', 'Italian', 'Spanish', 'French'][($i - 1) % 4],
        'meeting_point' => 'Accademia Gallery entrance, Via Ricasoli 58/60, Florence',
        'includes' => [
            'Skip-the-line tickets',
            'Expert licensed guide',
            'Small group (max 8 people)',
            'Audio headsets for clear hearing'
        ]
    ];
}

// Calculate uncompressed size
$jsonOutput = json_encode($testData, JSON_PRETTY_PRINT);
$uncompressedSize = strlen($jsonOutput);

// Add size info to response
$testData['size_info'] = [
    'uncompressed_bytes' => $uncompressedSize,
    'uncompressed_kb' => round($uncompressedSize / 1024, 2),
    'note' => 'Compare with Content-Length header to see compression ratio'
];

// Output JSON
echo json_encode($testData, JSON_PRETTY_PRINT);
