<?php
require_once 'config.php';

// Log all webhook calls for debugging
function logWebhook($topic, $data, $error = null) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO bokun_webhook_logs (topic, booking_id, experience_booking_id, payload, error_message) VALUES (?, ?, ?, ?, ?)");
    $bookingId = $_SERVER['HTTP_X_BOKUN_BOOKING_ID'] ?? null;
    $experienceBookingId = $_SERVER['HTTP_X_BOKUN_EXPERIENCEBOOKING_ID'] ?? null;
    $payload = json_encode($data);
    
    $stmt->bind_param("sssss", $topic, $bookingId, $experienceBookingId, $payload, $error);
    $stmt->execute();
    $stmt->close();
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
    
} catch (Exception $e) {
    logWebhook($topic, $data, $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
        SET last_synced = NOW(),
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
            last_synced = NOW()
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