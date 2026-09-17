<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 2.2): never deployed
/**
 * Prints which .env file EnvLoader chose and the resolved APP_ENV - nothing else.
 *   php tools/env_which.php
 *   FWL_API_DIR=/path/to/api php tools/env_which.php      (running the copy on the server)
 */
$apiDir = getenv('FWL_API_DIR') ?: __DIR__ . '/../public_html/api';
require_once $apiDir . '/EnvLoader.php';
EnvLoader::load();
echo 'env file : ' . (EnvLoader::loadedFrom() ?: 'none') . "\n";
echo 'source   : ' . EnvLoader::source() . "\n";
echo 'APP_ENV  : ' . (string) EnvLoader::get('APP_ENV', '(unset)') . "\n";
