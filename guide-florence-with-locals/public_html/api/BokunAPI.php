<?php
require_once 'HttpClient.php';

// Include SentryLogger if available (for error tracking)
if (file_exists(__DIR__ . '/SentryLogger.php')) {
    require_once __DIR__ . '/SentryLogger.php';
}

class BokunAPI {
    private $accessKey;
    private $secretKey;
    private $vendorId;
    private $baseUrl;
    private $requestCount = 0;
    private $lastRequestTime = 0;
    private $maxRequestsPerMinute = 400; // Bokun limit
    
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
        
        $url = $this->baseUrl . $endpoint;
        $date = gmdate('Y-m-d H:i:s'); // UTC date
        $signature = $this->generateSignature($date, $method, $endpoint);
        
        $headers = [
            'X-Bokun-Date: ' . $date,
            'X-Bokun-AccessKey: ' . $this->accessKey,
            'X-Bokun-Signature: ' . $signature,
            'Content-Type: application/json;charset=UTF-8'
        ];
        
        error_log("BokunAPI: Making {$method} request to {$url}");
        error_log("BokunAPI: Headers: " . json_encode($headers));
        
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
                    error_log("BokunAPI: Request data: " . $requestData);
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
                    error_log("BokunAPI: Request data: " . $requestData);
                }
                
                $response = $httpClient->request($method, $url, $headers, $requestData);
                $httpCode = $response['http_code'];
                $responseBody = $response['body'];
            }
            
            error_log("BokunAPI: Response code: {$httpCode}");
            error_log("BokunAPI: Response body: " . substr($responseBody, 0, 500));
            
            $decodedResponse = json_decode($responseBody, true);
            
            // Handle rate limiting with retry
            if ($httpCode === 429 && $retryCount < 3) {
                $retryAfter = 60; // Default wait time
                if (isset($decodedResponse['retryAfter'])) {
                    $retryAfter = $decodedResponse['retryAfter'];
                }
                
                error_log("BokunAPI: Rate limited, retrying in {$retryAfter}s");
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

        // Use larger page size to get more bookings per request
        $actualPageSize = max($pageSize, 200);

        // Roles to fetch - SUPPLIER for OTA bookings (Viator, GetYourGuide), SELLER for direct
        $roles = ['SUPPLIER', 'SELLER'];

        foreach ($roles as $role) {
            try {
                error_log("BokunAPI: Fetching bookings with role: $role, pageSize: $actualPageSize");

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

                    if ($result && isset($result['items']) && count($result['items']) > 0) {
                        error_log("BokunAPI: Page $pageNum - Found " . count($result['items']) . " bookings with role $role");
                        foreach ($result['items'] as $booking) {
                            $bookingId = $booking['id'] ?? null;
                            if ($bookingId && !isset($seenIds[$bookingId])) {
                                $seenIds[$bookingId] = true;
                                $allBookings[] = $booking;
                            }
                        }

                        // Check if there are more pages
                        $totalHits = $result['totalHits'] ?? count($result['items']);
                        $hasMore = (($pageNum + 1) * $actualPageSize) < $totalHits;
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
            error_log("BokunAPI: Total unique bookings found: " . count($allBookings));
            return $allBookings;
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
                error_log("BokunAPI: Trying $method $endpoint");
                if ($data) {
                    error_log("BokunAPI: With data: " . json_encode($data));
                }
                $result = $this->makeRequest($method, $endpoint, $data);
                if ($result !== null) {
                    error_log("BokunAPI: Success with $method $endpoint");
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
        if (!$language && isset($productBooking['fields']['rateId']) && isset($productBooking['product']['id'])) {
            $rateId = $productBooking['fields']['rateId'];
            $productId = $productBooking['product']['id'];

            try {
                // Fetch product details to get rate information
                $productDetails = $this->getProduct($productId);

                if (isset($productDetails['rates']) && is_array($productDetails['rates'])) {
                    foreach ($productDetails['rates'] as $rate) {
                        if (isset($rate['id']) && $rate['id'] == $rateId && isset($rate['title'])) {
                            $rateTitle = strtolower($rate['title']);

                            // Check if rate title contains language identifier
                            if (strpos($rateTitle, 'italian') !== false) {
                                $language = 'Italian';
                                break;
                            } elseif (strpos($rateTitle, 'spanish') !== false) {
                                $language = 'Spanish';
                                break;
                            } elseif (strpos($rateTitle, 'french') !== false) {
                                $language = 'French';
                                break;
                            } elseif (strpos($rateTitle, 'german') !== false) {
                                $language = 'German';
                                break;
                            } elseif (strpos($rateTitle, 'english') !== false) {
                                $language = 'English';
                                break;
                            } else {
                                // If rate title doesn't contain language keyword, assume English for default rate
                                $language = 'English';
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Log error but continue processing
                error_log("Failed to fetch product details for language extraction: " . $e->getMessage());
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
            'language' => $language,
            'description' => null, // Can be filled from notes later
            'customer_name' => $this->getCustomerName($booking),
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phoneNumber'] ?? null,
            'participants' => $participants,
            'participant_names' => $this->parseParticipantNames($booking),
            'booking_channel' => $bookingChannel,
            'total_amount_paid' => $totalAmount,
            'expected_amount' => $totalAmount,
            'payment_status' => $paymentStatus,
            'paid' => $totalAmount > 0 ? 1 : 0,
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
            error_log("BokunAPI: Testing connection to " . $this->baseUrl);
            error_log("BokunAPI: Access Key: " . substr($this->accessKey, 0, 8) . "...");
            error_log("BokunAPI: Vendor ID: " . $this->vendorId);
            
            $result = $this->searchActivities(1, 1);
            error_log("BokunAPI: Connection test successful");
            
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