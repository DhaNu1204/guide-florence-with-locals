<?php
require_once 'config.php';

// Apply rate limiting for webhooks (30 per minute)
applyRateLimit('webhook');

// Self-provision the webhook log table so payloads are captured even before
// any migration is run (same pattern tours.php uses for the products table).
// Non-fatal: a provisioning failure must never abort a webhook request.
function ensureWebhookLogTable() {
    global $conn;
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS bokun_webhook_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic VARCHAR(100),
                booking_id VARCHAR(255),
                experience_booking_id VARCHAR(255),
                payload JSON,
                processed TINYINT DEFAULT 0,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $e) {
        error_log("bokun_webhook: failed to ensure bokun_webhook_logs table: " . $e->getMessage());
    }
}
ensureWebhookLogTable();

// Log all webhook calls for debugging.
// Non-fatal: any logging failure is swallowed so it can never abort the request.
function logWebhook($topic, $data, $error = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("INSERT INTO bokun_webhook_logs (topic, booking_id, experience_booking_id, payload, error_message) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("bokun_webhook: logWebhook prepare failed: " . $conn->error);
            return;
        }
        $bookingId = $_SERVER['HTTP_X_BOKUN_BOOKING_ID'] ?? null;
        $experienceBookingId = $_SERVER['HTTP_X_BOKUN_EXPERIENCEBOOKING_ID'] ?? null;
        $payload = json_encode($data);

        $stmt->bind_param("sssss", $topic, $bookingId, $experienceBookingId, $payload, $error);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("bokun_webhook: logWebhook failed: " . $e->getMessage());
    }
}

// Get webhook topic from headers
$topic = $_SERVER['HTTP_X_BOKUN_TOPIC'] ?? '';
$bookingId = $_SERVER['HTTP_X_BOKUN_BOOKING_ID'] ?? '';

// Get request body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// Log the webhook
logWebhook($topic, $data);

try {
    switch($topic) {
        case 'bookings/create':
            handleBookingCreated($bookingId, $data);
            break;
            
        case 'bookings/update':
            handleBookingUpdated($bookingId, $data);
            break;
            
        case 'bookings/cancel':
            handleBookingCancelled($bookingId, $data);
            break;
            
        case 'experiences/availability_update':
            handleAvailabilityUpdate($data);
            break;
            
        default:
            logWebhook($topic, $data, "Unknown webhook topic: $topic");
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
    
} catch (Throwable $e) {
    // Record the failure but still return 200 so Bokun does not enter a retry
    // storm. The raw payload is already captured by the logWebhook() call above;
    // this second call annotates it with the handler error for later analysis.
    logWebhook($topic, $data, $e->getMessage());
    http_response_code(200);
    echo json_encode(['status' => 'received', 'message' => 'Webhook received (processing deferred)']);
}

function handleBookingCreated($bookingId, $data) {
    global $conn;
    
    // Check if tour already exists
    $stmt = $conn->prepare("SELECT id FROM tours WHERE bokun_booking_id = ?");
    $stmt->bind_param("s", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Tour already exists, skip
        return;
    }
    
    // We need to fetch full booking details from Bokun API
    // For now, create a placeholder tour that will be enriched later
    $stmt = $conn->prepare("
        INSERT INTO tours (
            title, 
            date, 
            time, 
            duration,
            bokun_booking_id,
            external_id,
            external_source,
            needs_guide_assignment,
            guide_id,
            created_at
        ) VALUES (
            'Bokun Booking - Pending Sync',
            CURDATE(),
            '09:00',
            '2 hours',
            ?,
            ?,
            'bokun',
            1,
            1,
            NOW()
        )
    ");
    
    $stmt->bind_param("ss", $bookingId, $bookingId);
    $stmt->execute();
    $stmt->close();
    
    // Mark webhook as processed
    $stmt = $conn->prepare("UPDATE bokun_webhook_logs SET processed = 1 WHERE booking_id = ?");
    $stmt->bind_param("s", $bookingId);
    $stmt->execute();
    $stmt->close();
}

function handleBookingUpdated($bookingId, $data) {
    global $conn;
    
    // Update existing tour if it exists
    $stmt = $conn->prepare("
        UPDATE tours 
        SET last_sync = NOW(),
            needs_guide_assignment = CASE 
                WHEN guide_id IS NULL OR guide_id = 1 THEN 1 
                ELSE 0 
            END
        WHERE bokun_booking_id = ?
    ");
    
    $stmt->bind_param("s", $bookingId);
    $stmt->execute();
    $stmt->close();
}

function handleBookingCancelled($bookingId, $data) {
    global $conn;
    
    // Mark tour as cancelled
    $stmt = $conn->prepare("
        UPDATE tours 
        SET cancelled = 1,
            last_sync = NOW()
        WHERE bokun_booking_id = ?
    ");
    
    $stmt->bind_param("s", $bookingId);
    $stmt->execute();
    $stmt->close();
}

function handleAvailabilityUpdate($data) {
    // Log availability updates for future use
    // This could trigger re-checking of guide assignments
    global $conn;
    
    $experienceId = $data['experienceId'] ?? '';
    $dateFrom = $data['dateFrom'] ?? '';
    $dateTo = $data['dateTo'] ?? '';
    
    // You can implement logic here to update local availability
    // or trigger a full sync for affected dates
}
?>