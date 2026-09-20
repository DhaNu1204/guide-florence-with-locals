<?php
require_once 'HttpClient.php';
require_once __DIR__ . '/tour_classification.php'; // step 3.1: bokunCustomerPrice()

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

class BokunAPI {
    /**
     * Step 2.4: per-request / per-response chatter goes to the error log only when
     * BOKUN_DEBUG_LOG=true in the server .env (~54 KB per sync otherwise). Errors,
     * warnings and the per-sync summary in bokun_sync.php stay unconditional.
     */
    private static $debugLogEnabled = null;

    private static function debugLog($message) {
        if (self::$debugLogEnabled === null) {
            self::$debugLogEnabled = class_exists('EnvLoader') && EnvLoader::getBool('BOKUN_DEBUG_LOG', false);
        }
        if (self::$debugLogEnabled) {
            error_log($message);
        }
    }

    private $accessKey;
    private $secretKey;
    private $vendorId;
    private $baseUrl;
    private $requestCount = 0;
    private $lastRequestTime = 0;
    private $maxRequestsPerMinute = 400; // Bokun limit

    // Step 3.4 (§2.6): one product lookup per PRODUCT per PHP process instead of one per booking.
    // Static, so a webhook process that syncs three dates in a row reuses it too; a product that
    // could not be fetched is cached as null so it is not retried ~800 times.
    private static $productCache = [];
    // Counters for the per-sync summary line (reset by resetRequestStats() at the start of a sync).
    private static $totalRequests = 0;
    private static $productCacheHits = 0;
    private static $productCacheMisses = 0;
    private static $rateLimitSleeps = 0;
    
    public function __construct($config) {
        $this->accessKey = $config['access_key'];
        $this->secretKey = $config['secret_key'];
        $this->vendorId = $config['vendor_id'];
        // Use production environment as credentials appear to be production
        $this->baseUrl = 'https://api.bokun.is';
    }
    
    /**
     * Generate HMAC-SHA1 signature for Bokun API
     */
    private function generateSignature($date, $method, $path) {
        // Bokun signature format: Date + AccessKey + HTTPMethod + Path
        $stringToSign = $date . $this->accessKey . $method . $path;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        return $signature;
    }
    
    /**
     * Rate limiting check
     */
    private function checkRateLimit() {
        $currentTime = time();
        
        // Reset counter every minute
        if ($currentTime - $this->lastRequestTime >= 60) {
            $this->requestCount = 0;
            $this->lastRequestTime = $currentTime;
        }
        
        // Check if we're at the limit
        if ($this->requestCount >= $this->maxRequestsPerMinute) {
            $waitTime = 60 - ($currentTime - $this->lastRequestTime);
            throw new Exception("Rate limit exceeded. Please wait {$waitTime} seconds.", 429);
        }
        
        $this->requestCount++;
    }
    
    /**
     * Make authenticated request to Bokun API
     */
    private function makeRequest($method, $endpoint, $data = null, $retryCount = 0) {
        // Check rate limiting
        $this->checkRateLimit();
        self::$totalRequests++; // step 3.4: every real HTTP call, retries included

        $url = $this->baseUrl . $endpoint;
        $date = gmdate('Y-m-d H:i:s'); // UTC date
        $signature = $this->generateSignature($date, $method, $endpoint);
        
        $headers = [
            'X-Bokun-Date: ' . $date,
            'X-Bokun-AccessKey: ' . $this->accessKey,
            'X-Bokun-Signature: ' . $signature,
            'Content-Type: application/json;charset=UTF-8'
        ];
        
        // Step 0.2: log method + path only - never the headers (they carry the access key + signature).
        self::debugLog("BokunAPI: {$method} {$endpoint}");
        
        try {
            // Use cURL if available, fallback to HttpClient
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Disable redirects to debug
                curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Florence-Guides/1.0');
                
                if ($data && ($method === 'POST' || $method === 'PUT')) {
                    $requestData = json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestData);
                    self::debugLog("BokunAPI: Request data: " . $requestData);
                }
                
                $responseBody = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new Exception('cURL Error: ' . $curlError);
                }
            } else {
                // Fallback to HttpClient
                $httpClient = new HttpClient();
                $requestData = null;
                
                if ($data && ($method === 'POST' || $method === 'PUT')) {
                    $requestData = json_encode($data);
                    self::debugLog("BokunAPI: Request data: " . $requestData);
                }
                
                $response = $httpClient->request($method, $url, $headers, $requestData);
                $httpCode = $response['http_code'];
                $responseBody = $response['body'];
            }
            
            self::debugLog("BokunAPI: {$method} {$endpoint} -> HTTP {$httpCode}");
            
            $decodedResponse = json_decode($responseBody, true);
            
            // Handle rate limiting with retry
            if ($httpCode === 429 && $retryCount < 3) {
                $retryAfter = 60; // Default wait time
                if (isset($decodedResponse['retryAfter'])) {
                    $retryAfter = $decodedResponse['retryAfter'];
                }
                
                error_log("BokunAPI: Rate limited, retrying in {$retryAfter}s");
                self::$rateLimitSleeps++; // step 3.4 (cause C): counted, so a sync reports its own stalls
                sleep($retryAfter);
                return $this->makeRequest($method, $endpoint, $data, $retryCount + 1);
            }
            
            // Handle other errors
            if ($httpCode >= 400) {
                $errorMsg = 'HTTP Error ' . $httpCode;
                if (isset($decodedResponse['message'])) {
                    $errorMsg .= ': ' . $decodedResponse['message'];
                } elseif (isset($decodedResponse['error'])) {
                    $errorMsg .= ': ' . $decodedResponse['error'];
                } elseif ($responseBody) {
                    $errorMsg .= ': ' . substr($responseBody, 0, 200);
                }
                throw new Exception($errorMsg, $httpCode);
            }
            
            return $decodedResponse;
            
        } catch (Exception $e) {
            error_log("BokunAPI: Request failed: " . $e->getMessage());

            // Send to Sentry if available
            if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
                sentry_add_breadcrumb("Bokun API request to $endpoint", 'http', 'error', [
                    'method' => $method,
                    'url' => $url
                ]);
                sentry_capture_exception($e, [
                    'bokun_endpoint' => $endpoint,
                    'bokun_method' => $method,
                    'http_code' => $httpCode ?? null
                ]);
            }

            throw $e;
        }
    }
    
    /**
     * Search for activities/products
     */
    public function searchActivities($page = 1, $pageSize = 20) {
        $data = [
            'page' => $page,
            'pageSize' => $pageSize
        ];
        
        return $this->makeRequest('POST', '/activity.json/search', $data);
    }
    
    /**
     * Get bookings for date range
     * Note: Use SUPPLIER role for OTA bookings (Viator, GetYourGuide)
     * Use SELLER role for direct bookings
     */
    public function getBookings($startDate, $endDate, $page = 1, $pageSize = 200) {
        // Collect all bookings from both SUPPLIER (OTA) and SELLER (direct) roles
        $allBookings = [];
        $seenIds = [];
        // Step 3.3: roles whose search ANSWERED properly (a well-formed response, even with no items).
        $rolesAnswered = 0;

        // Use larger page size to get more bookings per request
        $actualPageSize = max($pageSize, 200);

        // Roles to fetch - SUPPLIER for OTA bookings (Viator, GetYourGuide), SELLER for direct
        $roles = ['SUPPLIER', 'SELLER'];

        foreach ($roles as $role) {
            try {
                self::debugLog("BokunAPI: Fetching bookings with role: $role, pageSize: $actualPageSize");

                // Fetch with pagination to get all bookings
                $pageNum = 0;
                $hasMore = true;

                while ($hasMore && $pageNum < 10) { // Max 10 pages (2000 bookings) as safety limit
                    $result = $this->makeRequest('POST', '/booking.json/booking-search', [
                        'bookingRole' => $role,
                        'bookingStatuses' => ['CONFIRMED', 'PENDING', 'CANCELLED'],
                        'pageSize' => $actualPageSize,
                        'page' => $pageNum,
                        'startDateRange' => [
                            'from' => $startDate . 'T00:00:00.000Z',
                            'to' => $endDate . 'T23:59:59.999Z',
                            'includeLower' => true,
                            'includeUpper' => true
                        ]
                    ]);

                    // Step 3.3: a well-formed answer has an `items` array - possibly empty. Anything else
                    // (null, no `items`, not an array) is a malformed response = a failure for this role.
                    if (!is_array($result) || !array_key_exists('items', $result) || !is_array($result['items'])) {
                        throw new Exception("malformed booking-search response for role $role (no items array)");
                    }
                    if ($pageNum === 0) {
                        $rolesAnswered++;
                    }

                    if ($result && isset($result['items']) && count($result['items']) > 0) {
                        self::debugLog("BokunAPI: Page $pageNum - Found " . count($result['items']) . " bookings with role $role");
                        foreach ($result['items'] as $booking) {
                            $bookingId = $booking['id'] ?? null;
                            if ($bookingId && !isset($seenIds[$bookingId])) {
                                $seenIds[$bookingId] = true;
                                $allBookings[] = $booking;
                            }
                        }

                        // Paginate by PAGE FULLNESS, not by totalHits: Bokun's
                        // totalHits under-reports the real count (e.g. says 787 when
                        // there are 987), which made the old `((page+1)*pageSize) < totalHits`
                        // check stop one page early and silently drop page 4+ bookings
                        // (incl. cancelled/rescheduled ones that then never update).
                        // A full page means there's likely more; a short page is the last one.
                        $hasMore = (count($result['items']) === $actualPageSize);
                        $pageNum++;
                    } else {
                        $hasMore = false;
                    }
                }
            } catch (Exception $e) {
                error_log("BokunAPI: Failed to fetch with role $role: " . $e->getMessage());
                // Continue to next role
            }
        }

        if (count($allBookings) > 0) {
            self::debugLog("BokunAPI: Total unique bookings found: " . count($allBookings));
            return $allBookings;
        }

        // Step 3.3: BOTH roles answered and neither has a booking in this window - that is a
        // successful, empty search (a quiet day, or the only booking moved away), not an error.
        // Only when a role threw (HTTP error, auth failure, malformed response) do we fall through
        // to the legacy endpoints below, which throw if they fail too - so a real failure is
        // still reported as 'failed' by syncBookings().
        if ($rolesAnswered === count($roles)) {
            self::debugLog("BokunAPI: no bookings between $startDate and $endDate (both roles answered)");
            return [];
        }

        // Fallback to legacy endpoints if new method fails
        $requests = [
            // Legacy endpoints as fallback
            ['GET', '/booking.json/search?start=' . $startDate . '&end=' . $endDate . '&page=' . $page . '&pageSize=' . $pageSize, null],
            ['POST', '/booking.json/search', [
                'start' => $startDate,
                'end' => $endDate,
                'page' => $page,
                'pageSize' => $pageSize
            ]]
        ];
        
        $lastError = null;
        foreach ($requests as list($method, $endpoint, $data)) {
            try {
                self::debugLog("BokunAPI: Trying $method $endpoint");
                if ($data) {
                    self::debugLog("BokunAPI: With data: " . json_encode($data));
                }
                $result = $this->makeRequest($method, $endpoint, $data);
                if ($result !== null) {
                    self::debugLog("BokunAPI: Success with $method $endpoint");
                    // Extract items array from the response for booking-search endpoint
                    if (strpos($endpoint, 'booking-search') !== false && isset($result['items'])) {
                        return $result['items'];
                    }
                    // For other endpoints, check if the result is an array of bookings
                    if (isset($result['items'])) {
                        return $result['items'];
                    }
                    // Legacy endpoints might return the bookings directly
                    return is_array($result) ? $result : [];
                }
            } catch (Exception $e) {
                error_log("BokunAPI: Failed $method $endpoint: " . $e->getMessage());
                $lastError = $e;
                continue;
            }
        }
        
        // If all endpoints failed, throw the last error
        if ($lastError) {
            throw $lastError;
        }
        
        return [];
    }
    
    /**
     * Get specific booking details
     */
    public function getBooking($bookingId) {
        return $this->makeRequest('GET', '/booking.json/' . $bookingId);
    }
    
    /**
     * Get activity availability
     */
    public function getActivityAvailability($activityId, $startDate, $endDate, $currency = 'EUR') {
        $endpoint = '/activity.json/' . $activityId . '/availabilities?start=' . $startDate . '&end=' . $endDate . '&currency=' . $currency;
        return $this->makeRequest('GET', $endpoint);
    }

    /**
     * Get activity/product details
     */
    public function getProduct($productId) {
        return $this->makeRequest('GET', '/activity.json/' . $productId);
    }

    /**
     * Step 3.4 (§2.6): getProduct() behind a per-process cache keyed by product id.
     * Returns null (and never throws) when the product cannot be fetched - the caller treats that
     * as "no rate information", exactly as the old per-booking try/catch did, but the failure is
     * remembered so one broken product cannot cost ~800 failed HTTP calls in a single sync.
     */
    public function getProductCached($productId) {
        $key = (string) $productId;
        if (array_key_exists($key, self::$productCache)) {
            self::$productCacheHits++;
            return self::$productCache[$key];
        }
        self::$productCacheMisses++;
        try {
            self::$productCache[$key] = $this->getProduct($key);
        } catch (Exception $e) {
            self::$productCache[$key] = null;
            error_log("BokunAPI: product {$key} unavailable, cached as such for this run: " . $e->getMessage());
        }
        return self::$productCache[$key];
    }

    /**
     * Step 3.4: the keyword ladder the sync has always applied to a rate title, unchanged and in
     * the same order. A rate title that names no language is the product's default rate = English.
     */
    public static function languageFromRateTitle($rateTitle) {
        $title = strtolower((string) $rateTitle);
        foreach (['italian' => 'Italian', 'spanish' => 'Spanish', 'french' => 'French',
                  'german' => 'German', 'english' => 'English'] as $needle => $language) {
            if (strpos($title, $needle) !== false) {
                return $language;
            }
        }
        return 'English';
    }

    /**
     * Step 3.4: how much this process asked of Bokun. Logged in the per-sync summary line.
     */
    public static function requestStats() {
        return [
            'bokun_requests'     => self::$totalRequests,
            'product_calls'      => self::$productCacheMisses,
            'product_cache_hits' => self::$productCacheHits,
            'rate_limit_sleeps'  => self::$rateLimitSleeps,
        ];
    }

    /**
     * Reset the counters (not the product cache - reusing it across syncs in one process is the
     * whole point) so each sync reports its own numbers.
     */
    public static function resetRequestStats() {
        self::$totalRequests = 0;
        self::$productCacheHits = 0;
        self::$productCacheMisses = 0;
        self::$rateLimitSleeps = 0;
    }

    /**
     * Make a public API request (for testing/exploration)
     */
    public function makePublicRequest($method, $endpoint, $data = null) {
        return $this->makeRequest($method, $endpoint, $data);
    }

    /**
     * Transform Bokun booking to our tour format
     */
    public function transformBookingToTour($booking) {
        // Handle the actual booking structure from booking-search endpoint
        $productBooking = isset($booking['productBookings']) && !empty($booking['productBookings'])
            ? $booking['productBookings'][0]
            : [];

        // Extract product title and details
        $productTitle = $productBooking['product']['title'] ??
                       $booking['productTitle'] ??
                       'Bokun Tour';

        // Extract customer details
        $customer = $booking['customer'] ?? [];

        // Calculate participants from productBookings if available
        $participants = 1;
        if (isset($productBooking['fields']['totalParticipants'])) {
            $participants = $productBooking['fields']['totalParticipants'];
        } elseif (isset($productBooking['totalParticipants'])) {
            $participants = $productBooking['totalParticipants'];
        } elseif (isset($booking['totalParticipants'])) {
            $participants = $booking['totalParticipants'];
        } elseif (isset($productBooking['fields']['priceCategoryBookings'])) {
            // Calculate from priceCategoryBookings
            $participants = array_sum(array_column($productBooking['fields']['priceCategoryBookings'], 'quantity'));
        }

        // Extract date and time from available fields
        $date = null;
        $time = '09:00'; // Default time if not provided

        // PRIORITY: Use startTimeStr if available - this is the LOCAL time (already in tour timezone)
        // This is more accurate than converting UTC timestamps which can have timezone issues
        if (isset($productBooking['fields']['startTimeStr'])) {
            $time = $productBooking['fields']['startTimeStr'];
        }

        // Extract date from startDateTime or startDate
        // Note: For date extraction, we use UTC conversion but for TIME we use startTimeStr above
        $romeTimezone = new DateTimeZone('Europe/Rome');
        $utcTimezone = new DateTimeZone('UTC');

        if (isset($productBooking['startDateTime'])) {
            if (is_numeric($productBooking['startDateTime'])) {
                // Numeric timestamp (milliseconds from epoch)
                $utcDateTime = new DateTime('@' . intval($productBooking['startDateTime'] / 1000), $utcTimezone);
            } else {
                // ISO string format - parse as UTC
                $utcDateTime = new DateTime($productBooking['startDateTime'], $utcTimezone);
            }
            $utcDateTime->setTimezone($romeTimezone);
            $date = $utcDateTime->format('Y-m-d');
            // Only use timestamp-derived time if startTimeStr wasn't available
            if (!isset($productBooking['fields']['startTimeStr'])) {
                $time = $utcDateTime->format('H:i');
            }
        } elseif (isset($productBooking['startTime'])) {
            if (is_numeric($productBooking['startTime'])) {
                $utcDateTime = new DateTime('@' . intval($productBooking['startTime'] / 1000), $utcTimezone);
            } else {
                $utcDateTime = new DateTime($productBooking['startTime'], $utcTimezone);
            }
            $utcDateTime->setTimezone($romeTimezone);
            $date = $utcDateTime->format('Y-m-d');
            if (!isset($productBooking['fields']['startTimeStr'])) {
                $time = $utcDateTime->format('H:i');
            }
        } elseif (isset($productBooking['startDate'])) {
            if (is_numeric($productBooking['startDate'])) {
                $utcDateTime = new DateTime('@' . intval($productBooking['startDate'] / 1000), $utcTimezone);
            } else {
                $utcDateTime = new DateTime($productBooking['startDate'], $utcTimezone);
            }
            $utcDateTime->setTimezone($romeTimezone);
            $date = $utcDateTime->format('Y-m-d');
        } elseif (isset($booking['startTime'])) {
            if (is_numeric($booking['startTime'])) {
                $utcDateTime = new DateTime('@' . intval($booking['startTime'] / 1000), $utcTimezone);
            } else {
                $utcDateTime = new DateTime($booking['startTime'], $utcTimezone);
            }
            $utcDateTime->setTimezone($romeTimezone);
            $date = $utcDateTime->format('Y-m-d');
            if (!isset($productBooking['fields']['startTimeStr'])) {
                $time = $utcDateTime->format('H:i');
            }
        }

        // CRITICAL: Do NOT use creationDate as tour date!
        // creationDate is when the booking was MADE, not when the tour happens.
        // If no tour date found, log error and skip this booking.
        if (!$date) {
            error_log("BokunAPI WARNING: No tour date found for booking " . ($booking['confirmationCode'] ?? 'unknown'));
            error_log("BokunAPI: Available fields: " . json_encode(array_keys($booking)));
            if (isset($productBooking)) {
                error_log("BokunAPI: ProductBooking fields: " . json_encode(array_keys($productBooking)));
            }
            // Set a flag or throw exception - don't import bookings without tour dates
            throw new Exception("No tour date found for booking " . ($booking['confirmationCode'] ?? 'unknown'));
        }

        // Extract duration - convert to string format for database
        $duration = null;
        if (isset($productBooking['duration'])) {
            $duration = $productBooking['duration'] . ' minutes';
        } elseif (isset($booking['duration'])) {
            $duration = $booking['duration'] . ' minutes';
        }

        // Get channel/seller information
        $bookingChannel = $booking['channel']['title'] ??
                         $booking['seller']['title'] ??
                         'Bokun';

        // Calculate total amount
        $totalAmount = 0;
        if (isset($booking['totalPrice'])) {
            $totalAmount = floatval($booking['totalPrice']);
        } elseif (isset($booking['paidAmount'])) {
            $totalAmount = floatval($booking['paidAmount']);
        }

        // Step 3.1: what the customer paid (retail) -> tours.bokun_total_price / bokun_currency
        $customerPrice = bokunCustomerPrice($booking);

        // Map payment status - IMPORTANT: This is for GUIDE payment, not customer payment
        // All Bokun bookings should start as 'unpaid' for guide payment tracking
        // The Bokun paymentStatus (INVOICED/PAID) refers to customer payment to the booking platform,
        // NOT payment to the tour guide. Guide payment must be recorded separately.
        $paymentStatus = 'unpaid';

        // Extract language information from notes
        $language = null;

        // Check in booking notes for "Booking languages" or "GUIDE" language
        if (isset($productBooking['notes']) && is_array($productBooking['notes'])) {
            foreach ($productBooking['notes'] as $note) {
                if (isset($note['body'])) {
                    $noteBody = $note['body'];

                    // Look for "GUIDE : English" or similar patterns
                    if (preg_match('/GUIDE\s*:\s*([A-Za-z]+)/i', $noteBody, $matches)) {
                        $language = ucfirst(strtolower($matches[1]));
                        break;
                    }

                    // Look for "Booking languages:" section
                    if (preg_match('/Booking languages.*?:\s*([A-Za-z]+)/is', $noteBody, $matches)) {
                        $language = ucfirst(strtolower($matches[1]));
                        break;
                    }
                }
            }
        }

        // Method 2: Check rate title for language (especially for GetYourGuide bookings)
        //
        // Step 3.4 (§2.6): the rate title is already in the booking Bokun just sent us, so ask for
        // it there first and only fall back to GET /activity.json/{productId} when it is missing.
        // Measured on production before the change: 4,000 of 4,000 stored payloads carry
        // productBookings[0].rateTitle, and over a whole sync window (815 bookings, 19 products)
        // the payload title agreed with the product endpoint's title for that rateId on 812 - the
        // 3 that differ are reseller titles that derive the SAME language. The fallback call is
        // cached per product for the run, so a sync can never make more calls than it has products.
        if (!$language && isset($productBooking['fields']['rateId']) && isset($productBooking['product']['id'])) {
            $rateId = $productBooking['fields']['rateId'];
            $productId = $productBooking['product']['id'];

            $payloadRateTitle = $productBooking['rateTitle'] ?? ($productBooking['fields']['rateTitle'] ?? null);

            if (is_string($payloadRateTitle) && trim($payloadRateTitle) !== '') {
                $language = self::languageFromRateTitle($payloadRateTitle);
            } else {
                $productDetails = $this->getProductCached($productId);
                if (isset($productDetails['rates']) && is_array($productDetails['rates'])) {
                    foreach ($productDetails['rates'] as $rate) {
                        if (isset($rate['id'], $rate['title']) && $rate['id'] == $rateId) {
                            $language = self::languageFromRateTitle($rate['title']);
                            break;
                        }
                    }
                }
            }
        }

        // Method 3: Check in other booking field locations
        if (!$language) {
            if (isset($productBooking['fields']['language'])) {
                $language = $productBooking['fields']['language'];
            } elseif (isset($productBooking['product']['language'])) {
                $language = $productBooking['product']['language'];
            } elseif (isset($booking['language'])) {
                $language = $booking['language'];
            }
        }

        // Method 4: Extract from product title as last resort
        if (!$language) {
            $titleLower = strtolower($productTitle);
            if (strpos($titleLower, 'italian') !== false) {
                $language = 'Italian';
            } elseif (strpos($titleLower, 'spanish') !== false) {
                $language = 'Spanish';
            } elseif (strpos($titleLower, 'french') !== false) {
                $language = 'French';
            } elseif (strpos($titleLower, 'german') !== false) {
                $language = 'German';
            } elseif (strpos($titleLower, 'english') !== false) {
                $language = 'English';
            }
        }

        // Extract Bokun product ID for product classification
        $bokunProductId = isset($productBooking['product']['id']) ? intval($productBooking['product']['id']) : null;

        // Store complete Bokun data as JSON for reference
        $bokunData = json_encode($booking);

        return [
            'external_id' => $booking['confirmationCode'] ?? null,
            'bokun_booking_id' => (string)($booking['id'] ?? ''),
            'bokun_confirmation_code' => $booking['confirmationCode'] ?? null,
            'product_id' => $bokunProductId,
            'title' => $productTitle,
            'date' => $date,
            'time' => $time,
            'duration' => $duration,
            // Step 6.1: one spelling per language, so the filter can match exactly.
            'language' => function_exists('tourLanguageCanonical') ? tourLanguageCanonical($language) : $language,
            'description' => null, // Can be filled from notes later
            'customer_name' => $this->getCustomerName($booking),
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phoneNumber'] ?? null,
            'participants' => $participants,
            'participant_names' => $this->parseParticipantNames($booking),
            'booking_channel' => $bookingChannel,
            // Step 3.1: these four are LOCAL state (guide payment tracking). The sync writes them
            // only when it INSERTs a brand-new booking and never touches them again; `paid` always
            // starts at 0 - what the customer paid Bokun says nothing about paying the guide.
            'total_amount_paid' => $totalAmount,
            'expected_amount' => $totalAmount,
            'payment_status' => $paymentStatus,
            'paid' => 0,
            // Step 3.1: Bokun's customer price lives in its own columns (INSERT and UPDATE).
            'bokun_total_price' => $customerPrice['price'],
            'bokun_currency' => $customerPrice['currency'],
            'external_source' => 'bokun',
            'needs_guide_assignment' => 1,
            'guide_id' => null, // Will be assigned later
            'cancelled' => (($productBooking['status'] ?? $booking['status'] ?? '') === 'CANCELLED') ? 1 : 0,
            'bokun_data' => $bokunData,
            'last_sync' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getCustomerName($booking) {
        if (isset($booking['customer'])) {
            $firstName = $booking['customer']['firstName'] ?? '';
            $lastName = $booking['customer']['lastName'] ?? '';
            return trim($firstName . ' ' . $lastName);
        }
        return null;
    }

    /**
     * Parse participant names from booking data.
     * GYG: names in productBookings[0].specialRequests ("Traveler N: First Name: X\nLast Name: Y")
     * Viator: no individual names available in search results
     * Returns JSON string or null.
     */
    public function parseParticipantNames($booking) {
        $productBooking = isset($booking['productBookings']) && !empty($booking['productBookings'])
            ? $booking['productBookings'][0]
            : [];

        $names = [];

        // Method 1: GYG special requests format
        $specialRequests = $productBooking['specialRequests'] ?? null;
        if ($specialRequests && is_string($specialRequests) && strlen(trim($specialRequests)) > 1) {
            if (preg_match_all(
                '/Traveler\s+(\d+):\s*\n?First Name:\s*(.+?)\s*\n?Last Name:\s*(.+?)(?:\n|$)/i',
                $specialRequests,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $m) {
                    $first = trim($m[2]);
                    $last = trim($m[3]);
                    if ($first || $last) {
                        $names[] = [
                            'first' => $this->titleCase($first),
                            'last' => $this->titleCase($last)
                        ];
                    }
                }
            }
        }

        // Return null if no names found (don't store empty arrays)
        if (empty($names)) {
            return null;
        }

        return json_encode($names);
    }

    /**
     * Title-case a name: "ETSUKO" → "Etsuko", "mARIA" → "Maria"
     */
    private function titleCase($name) {
        if (!$name) return '';
        return mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE, 'UTF-8');
    }

    private function mapBookingStatus($bokunStatus) {
        $statusMap = [
            'CONFIRMED' => 'confirmed',
            'PENDING' => 'pending',
            'CANCELLED' => 'cancelled',
            'COMPLETED' => 'completed'
        ];
        
        return $statusMap[$bokunStatus] ?? 'pending';
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        try {
            // Log the test attempt
            self::debugLog("BokunAPI: Testing connection to " . $this->baseUrl);
            self::debugLog("BokunAPI: Access Key: " . substr($this->accessKey, 0, 8) . "...");
            self::debugLog("BokunAPI: Vendor ID: " . $this->vendorId);
            
            $result = $this->searchActivities(1, 1);
            self::debugLog("BokunAPI: Connection test successful");
            
            return [
                'success' => true, 
                'message' => 'Connection successful',
                'base_url' => $this->baseUrl,
                'access_key_preview' => substr($this->accessKey, 0, 8) . '...'
            ];
        } catch (Exception $e) {
            error_log("BokunAPI: Connection test failed - " . $e->getMessage());
            error_log("BokunAPI: Error code - " . $e->getCode());

            // Send to Sentry if available
            if (class_exists('SentryLogger') && SentryLogger::getInstance()->isEnabled()) {
                sentry_capture_exception($e, [
                    'context' => 'bokun_connection_test',
                    'base_url' => $this->baseUrl,
                    'vendor_id' => $this->vendorId
                ]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'base_url' => $this->baseUrl,
                'debug_info' => [
                    'access_key_length' => strlen($this->accessKey),
                    'secret_key_length' => strlen($this->secretKey),
                    'vendor_id' => $this->vendorId
                ]
            ];
        }
    }
}
?>