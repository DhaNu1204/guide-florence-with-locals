<?php
// Include database configuration (handles CORS, security headers, and DB connection)
require_once 'config.php';
require_once 'Middleware.php';

// Require authentication for all tour operations
Middleware::requireAuth($conn);

// Apply rate limiting based on HTTP method
autoRateLimit('tours');

// First, check if the cancelled column exists and add it if it doesn't
$checkColumnQuery = "SHOW COLUMNS FROM tours LIKE 'cancelled'";
$columnResult = $conn->query($checkColumnQuery);

// If the cancelled column doesn't exist, create it
if ($columnResult->num_rows === 0) {
    $addColumnQuery = "ALTER TABLE tours ADD COLUMN cancelled TINYINT(1) DEFAULT 0";
    if (!$conn->query($addColumnQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        error_log("Failed to add cancelled column: " . $conn->error);
        echo json_encode(["error" => "Database migration error"]);
        exit();
    }
}

// Check if the booking_channel column exists and add it if it doesn't
$checkBookingChannelQuery = "SHOW COLUMNS FROM tours LIKE 'booking_channel'";
$bookingChannelResult = $conn->query($checkBookingChannelQuery);

// If the booking_channel column doesn't exist, create it
if ($bookingChannelResult->num_rows === 0) {
    $addBookingChannelQuery = "ALTER TABLE tours ADD COLUMN booking_channel VARCHAR(255) DEFAULT NULL";
    if (!$conn->query($addBookingChannelQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        error_log("Failed to add booking_channel column: " . $conn->error);
        echo json_encode(["error" => "Database migration error"]);
        exit();
    }
}

// Check if the notes column exists and add it if it doesn't
$checkNotesQuery = "SHOW COLUMNS FROM tours LIKE 'notes'";
$notesResult = $conn->query($checkNotesQuery);

// If the notes column doesn't exist, create it
if ($notesResult->num_rows === 0) {
    $addNotesQuery = "ALTER TABLE tours ADD COLUMN notes TEXT DEFAULT NULL";
    if (!$conn->query($addNotesQuery)) {
        header("HTTP/1.1 500 Internal Server Error");
        error_log("Failed to add notes column: " . $conn->error);
        echo json_encode(["error" => "Database migration error"]);
        exit();
    }
}

// Check if tour_groups table exists, create if not (for LEFT JOIN in GET)
$checkTourGroupsTable = $conn->query("SHOW TABLES LIKE 'tour_groups'");
if ($checkTourGroupsTable->num_rows === 0) {
    $conn->query("
        CREATE TABLE `tour_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_date` date NOT NULL,
            `group_time` time NOT NULL,
            `display_name` varchar(200) NOT NULL,
            `guide_id` int(11) DEFAULT NULL,
            `guide_name` varchar(100) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `max_pax` int(11) NOT NULL DEFAULT 9,
            `total_pax` int(11) NOT NULL DEFAULT 0,
            `is_manual_merge` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tour_groups_date` (`group_date`),
            KEY `idx_tour_groups_date_time` (`group_date`, `group_time`),
            KEY `idx_tour_groups_guide` (`guide_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Check if group_id column exists in tours, add if not
$checkGroupIdCol = $conn->query("SHOW COLUMNS FROM tours LIKE 'group_id'");
if ($checkGroupIdCol->num_rows === 0) {
    $conn->query("ALTER TABLE tours ADD COLUMN `group_id` int(11) DEFAULT NULL AFTER `notes`");
    $conn->query("ALTER TABLE tours ADD KEY `idx_tours_group_id` (`group_id`)");
}

// Check if participant_names column exists, add if not
$checkParticipantNamesCol = $conn->query("SHOW COLUMNS FROM tours LIKE 'participant_names'");
if ($checkParticipantNamesCol->num_rows === 0) {
    $conn->query("ALTER TABLE tours ADD COLUMN `participant_names` TEXT DEFAULT NULL AFTER `participants`");
}

// =====================================================
// PRODUCT CLASSIFICATION SYSTEM
// =====================================================

// Ensure products table exists
$checkProductsTable = $conn->query("SHOW TABLES LIKE 'products'");
$productsTableIsNew = ($checkProductsTable->num_rows === 0);
if ($productsTableIsNew) {
    $conn->query("
        CREATE TABLE `products` (
            `bokun_product_id` int(11) NOT NULL,
            `title` varchar(500) NOT NULL DEFAULT '',
            `product_type` enum('tour','ticket') NOT NULL DEFAULT 'tour',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`bokun_product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Ensure product_id column exists on tours
$checkProductIdCol = $conn->query("SHOW COLUMNS FROM tours LIKE 'product_id'");
if ($checkProductIdCol->num_rows === 0) {
    $conn->query("ALTER TABLE tours ADD COLUMN `product_id` int(11) DEFAULT NULL AFTER `group_id`");
    $conn->query("ALTER TABLE tours ADD KEY `idx_tours_product_id` (`product_id`)");

    // One-time backfill: extract product_id from bokun_data JSON
    $conn->query("
        UPDATE tours
        SET product_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(bokun_data, '$.productBookings[0].product.id')) AS UNSIGNED)
        WHERE bokun_data IS NOT NULL AND product_id IS NULL
          AND JSON_EXTRACT(bokun_data, '$.productBookings[0].product.id') IS NOT NULL
    ");

    // Populate products table from backfilled data
    $conn->query("
        INSERT IGNORE INTO products (bokun_product_id, title)
        SELECT DISTINCT product_id, title
        FROM tours
        WHERE product_id IS NOT NULL
    ");

    // Mark known ticket products
    $conn->query("
        UPDATE products SET product_type = 'ticket'
        WHERE bokun_product_id IN (809838, 845665, 877713, 961802, 1115497, 1119143, 1162586)
    ");

    error_log("Product classification: backfilled product_id column, populated products table");
}

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];

// Get tour ID from the URL if provided
$tourId = null;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathSegments = explode('/', $path);
$lastSegment = end($pathSegments);

// If the last segment is numeric, it's probably a tour ID
if (is_numeric($lastSegment)) {
    $tourId = $lastSegment;
}

// Handle requests based on method
switch ($method) {
    case 'GET':
        // Get pagination parameters from query string
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(500, intval($_GET['per_page']))) : 50;
        $offset = ($page - 1) * $perPage;

        // Get optional date filter parameter (YYYY-MM-DD format)
        $filterDate = isset($_GET['date']) ? $_GET['date'] : null;
        $guideId = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
        $upcoming = isset($_GET['upcoming']) && $_GET['upcoming'] === 'true';
        $past = isset($_GET['past']) && $_GET['past'] === 'true';
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;

        // Product type filter: 'tour' (default), 'ticket', or 'all'
        $productType = isset($_GET['product_type']) ? $_GET['product_type'] : 'tour';

        // Build WHERE clause for filtering
        $whereConditions = [];
        $whereParams = [];
        $whereTypes = "";

        if ($startDate && $endDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            // Custom date range filter
            $whereConditions[] = "t.date >= ?";
            $whereConditions[] = "t.date <= ?";
            $whereParams[] = $startDate;
            $whereParams[] = $endDate;
            $whereTypes .= "ss";
        } elseif ($past) {
            // Show tours from past 40 days (for payment verification)
            $startDate = date('Y-m-d', strtotime('-40 days'));
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $whereConditions[] = "t.date >= ?";
            $whereConditions[] = "t.date <= ?";
            $whereParams[] = $startDate;
            $whereParams[] = $yesterday;
            $whereTypes .= "ss";
        } elseif ($upcoming) {
            // Show tours from today onwards for the next 60 days
            $today = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+60 days'));
            $whereConditions[] = "t.date >= ?";
            $whereConditions[] = "t.date <= ?";
            $whereParams[] = $today;
            $whereParams[] = $endDate;
            $whereTypes .= "ss";
        } elseif ($filterDate) {
            // Validate date format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
                $whereConditions[] = "t.date = ?";
                $whereParams[] = $filterDate;
                $whereTypes .= "s";
            }
        }

        if ($guideId) {
            $whereConditions[] = "t.guide_id = ?";
            $whereParams[] = $guideId;
            $whereTypes .= "i";
        }

        // Product type filter via products table
        if ($productType === 'tour') {
            // Exclude tickets; include tours and manual entries (NULL product_id)
            $whereConditions[] = "(pr.product_type = 'tour' OR t.product_id IS NULL)";
        } elseif ($productType === 'ticket') {
            // Only tickets
            $whereConditions[] = "pr.product_type = 'ticket'";
        }
        // 'all' = no filter

        $whereClause = count($whereConditions) > 0
            ? "WHERE " . implode(" AND ", $whereConditions)
            : "";

        // Get total count for pagination metadata (with filters)
        $countSql = "SELECT COUNT(*) as total FROM tours t LEFT JOIN products pr ON t.product_id = pr.bokun_product_id $whereClause";
        if (count($whereParams) > 0) {
            $countStmt = $conn->prepare($countSql);
            $countStmt->bind_param($whereTypes, ...$whereParams);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
        } else {
            $countResult = $conn->query($countSql);
        }
        $totalRecords = 0;

        if ($countResult) {
            $countRow = $countResult->fetch_assoc();
            $totalRecords = intval($countRow['total']);
        }

        // Get all tours with guide names, payment information, and group info (with pagination and filters)
        $sql = "SELECT t.*, g.name as guide_name,
                       tg.display_name as group_display_name,
                       tg.total_pax as group_total_pax,
                       tg.max_pax as group_max_pax,
                       tg.is_manual_merge as group_is_manual_merge,
                       tg.guide_id as group_guide_id,
                       tg.guide_name as group_guide_name,
                       pr.product_type as product_type
                FROM tours t
                LEFT JOIN guides g ON t.guide_id = g.id
                LEFT JOIN tour_groups tg ON t.group_id = tg.id
                LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
                $whereClause
                ORDER BY t.date ASC, t.time ASC
                LIMIT ? OFFSET ?";

        // Add pagination params to the end
        $allParams = array_merge($whereParams, [$perPage, $offset]);
        $allTypes = $whereTypes . "ii";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            $tours = [];
            while ($row = $result->fetch_assoc()) {
                // Ensure ID is an integer
                $row['id'] = intval($row['id']);

                // Convert paid to boolean for frontend (backward compatibility)
                $row['paid'] = isset($row['paid']) && $row['paid'] == 1 ? true : false;

                // Convert cancelled to boolean for frontend
                $row['cancelled'] = isset($row['cancelled']) && $row['cancelled'] == 1 ? true : false;

                // Convert rescheduled to boolean for frontend
                $row['rescheduled'] = isset($row['rescheduled']) && $row['rescheduled'] == 1 ? true : false;

                // Format payment-related fields
                if (isset($row['total_amount_paid'])) {
                    $row['total_amount_paid'] = floatval($row['total_amount_paid']);
                }
                if (isset($row['expected_amount'])) {
                    $row['expected_amount'] = $row['expected_amount'] ? floatval($row['expected_amount']) : null;
                }

                // Ensure payment_status has a default value
                if (!isset($row['payment_status'])) {
                    $row['payment_status'] = 'unpaid';
                }

                // Format group info
                if (isset($row['group_id']) && $row['group_id']) {
                    $row['group_id'] = intval($row['group_id']);
                    $row['group_info'] = [
                        'id' => $row['group_id'],
                        'display_name' => $row['group_display_name'],
                        'total_pax' => intval($row['group_total_pax'] ?? 0),
                        'max_pax' => intval($row['group_max_pax'] ?? 9),
                        'is_manual_merge' => (bool)($row['group_is_manual_merge'] ?? false),
                        'guide_id' => $row['group_guide_id'] ? intval($row['group_guide_id']) : null,
                        'guide_name' => $row['group_guide_name'] ?? null
                    ];
                } else {
                    $row['group_id'] = null;
                    $row['group_info'] = null;
                }
                // Remove redundant group columns from top-level row
                unset($row['group_display_name'], $row['group_total_pax'], $row['group_max_pax'],
                      $row['group_is_manual_merge'], $row['group_guide_id'], $row['group_guide_name']);

                $tours[] = $row;
            }

            // Calculate pagination metadata
            $totalPages = ceil($totalRecords / $perPage);

            // Return paginated response with metadata
            echo json_encode([
                'data' => $tours,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalRecords,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                    'has_prev' => $page > 1
                ]
            ]);
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            error_log("Failed to get tours: " . $conn->error);
            echo json_encode(["error" => "Failed to get tours"]);
        }
        break;
        
    case 'POST':
        // Create a new tour
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Check for required fields
        if (!isset($data['title']) || !isset($data['date']) || !isset($data['time']) || !isset($data['guideId'])) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Missing required fields"]);
            break;
        }
        
        // Set paid status if provided, default to 0 (unpaid)
        $paid = isset($data['paid']) && $data['paid'] ? 1 : 0;
        
        // Set cancelled status if provided, default to 0 (not cancelled)
        $cancelled = isset($data['cancelled']) && $data['cancelled'] ? 1 : 0;
        
        // Set booking channel if provided
        $bookingChannel = isset($data['bookingChannel']) ? $data['bookingChannel'] : null;

        // Set payment-related fields
        $expectedAmount = isset($data['expectedAmount']) ? $data['expectedAmount'] : null;
        $paymentNotes = isset($data['paymentNotes']) ? $data['paymentNotes'] : null;
        $paymentStatus = isset($data['paymentStatus']) ? $data['paymentStatus'] : 'unpaid';

        // Validate payment status
        $validPaymentStatuses = ['unpaid', 'partial', 'paid', 'overpaid'];
        if (!in_array($paymentStatus, $validPaymentStatuses)) {
            $paymentStatus = 'unpaid';
        }

        // Get guide name for the response
        $guideStmt = $conn->prepare("SELECT name FROM guides WHERE id = ?");
        $guideStmt->bind_param("i", $data['guideId']);
        $guideStmt->execute();
        $guideResult = $guideStmt->get_result();
        $guideName = '';

        if ($guideRow = $guideResult->fetch_assoc()) {
            $guideName = $guideRow['name'];
        }

        // Insert the tour with enhanced payment fields
        $stmt = $conn->prepare("INSERT INTO tours (title, duration, description, date, time, guide_id, booking_channel, paid, cancelled, payment_status, expected_amount, payment_notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("sssssisiisds",
            $data['title'],
            $data['duration'],
            $data['description'],
            $data['date'],
            $data['time'],
            $data['guideId'],
            $bookingChannel,
            $paid,
            $cancelled,
            $paymentStatus,
            $expectedAmount,
            $paymentNotes
        );
        
        if ($stmt->execute()) {
            $newTourId = $stmt->insert_id;
            
            // Return the created tour
            $newTour = [
                'id' => $newTourId,
                'title' => $data['title'],
                'duration' => $data['duration'],
                'description' => $data['description'],
                'date' => $data['date'],
                'time' => $data['time'],
                'guide_id' => $data['guideId'],
                'guide_name' => $guideName,
                'booking_channel' => $bookingChannel,
                'paid' => (bool)$paid,
                'cancelled' => (bool)$cancelled,
                'payment_status' => $paymentStatus,
                'total_amount_paid' => 0.00,
                'expected_amount' => $expectedAmount ? floatval($expectedAmount) : null,
                'payment_notes' => $paymentNotes,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode($newTour);
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            error_log("Failed to create tour: " . $stmt->error);
            echo json_encode(["error" => "Failed to create tour"]);
        }
        break;
        
    case 'PUT':
        // Update a tour
        if (!$tourId) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Tour ID is required for update"]);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Build SET clause for the SQL statement based on provided fields
        $setFields = [];
        $bindTypes = "";
        $bindValues = [];
        
        if (isset($data['title'])) {
            $setFields[] = "title = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['title'];
        }
        
        if (isset($data['duration'])) {
            $setFields[] = "duration = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['duration'];
        }
        
        if (isset($data['description'])) {
            $setFields[] = "description = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['description'];
        }
        
        if (isset($data['date'])) {
            $setFields[] = "date = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['date'];
        }
        
        if (isset($data['time'])) {
            $setFields[] = "time = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['time'];
        }
        
        // Handle both guideId and guide_id for backward compatibility
        if (isset($data['guideId']) || isset($data['guide_id'])) {
            $setFields[] = "guide_id = ?";
            $guideValue = isset($data['guide_id']) ? $data['guide_id'] : $data['guideId'];
            // Convert empty string to NULL for database
            if ($guideValue === '' || $guideValue === null) {
                $bindTypes .= "s";
                $bindValues[] = null;
            } else {
                $bindTypes .= "i";
                $bindValues[] = $guideValue;
            }
        }

        // Handle notes field
        if (isset($data['notes'])) {
            $setFields[] = "notes = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['notes'];
        }
        
        if (isset($data['booking_channel'])) {
            $setFields[] = "booking_channel = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['booking_channel'];
        }
        
        if (isset($data['paid'])) {
            $setFields[] = "paid = ?";
            $bindTypes .= "i";
            $bindValues[] = $data['paid'] ? 1 : 0;
        }
        
        if (isset($data['cancelled'])) {
            $setFields[] = "cancelled = ?";
            $bindTypes .= "i";
            $bindValues[] = $data['cancelled'] ? 1 : 0;
        }

        // Enhanced payment fields
        if (isset($data['payment_status'])) {
            $validPaymentStatuses = ['unpaid', 'partial', 'paid', 'overpaid'];
            if (in_array($data['payment_status'], $validPaymentStatuses)) {
                $setFields[] = "payment_status = ?";
                $bindTypes .= "s";
                $bindValues[] = $data['payment_status'];
            }
        }

        if (isset($data['expected_amount'])) {
            $setFields[] = "expected_amount = ?";
            $bindTypes .= "d";
            $bindValues[] = $data['expected_amount'] ? floatval($data['expected_amount']) : null;
        }

        if (isset($data['payment_notes'])) {
            $setFields[] = "payment_notes = ?";
            $bindTypes .= "s";
            $bindValues[] = $data['payment_notes'];
        }

        // Always update the updated_at timestamp
        $setFields[] = "updated_at = NOW()";
        
        // If no fields to update, return error
        if (count($setFields) === 1) { // Only updated_at
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "No fields to update"]);
            break;
        }
        
        // Build the SQL statement
        $sql = "UPDATE tours SET " . implode(", ", $setFields) . " WHERE id = ?";
        $bindTypes .= "i";
        $bindValues[] = $tourId;
        
        $stmt = $conn->prepare($sql);
        
        // Bind parameters dynamically
        $bindParams = array_merge([$bindTypes], $bindValues);
        $stmt->bind_param(...$bindParams);
        
        if ($stmt->execute()) {
            // Check if the tour was found and updated
            if ($stmt->affected_rows > 0) {
                // Fetch the updated tour
                $selectStmt = $conn->prepare("SELECT t.*, g.name as guide_name FROM tours t LEFT JOIN guides g ON t.guide_id = g.id WHERE t.id = ?");
                $selectStmt->bind_param("i", $tourId);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                
                if ($tour = $result->fetch_assoc()) {
                    // Convert paid to boolean for frontend (backward compatibility)
                    if (isset($tour['paid'])) {
                        $tour['paid'] = (bool)$tour['paid'];
                    } else {
                        $tour['paid'] = false;
                    }

                    // Convert cancelled to boolean for frontend
                    if (isset($tour['cancelled'])) {
                        $tour['cancelled'] = (bool)$tour['cancelled'];
                    } else {
                        $tour['cancelled'] = false;
                    }

                    // Convert rescheduled to boolean for frontend
                    if (isset($tour['rescheduled'])) {
                        $tour['rescheduled'] = (bool)$tour['rescheduled'];
                    } else {
                        $tour['rescheduled'] = false;
                    }

                    // Format payment-related fields
                    if (isset($tour['total_amount_paid'])) {
                        $tour['total_amount_paid'] = floatval($tour['total_amount_paid']);
                    }
                    if (isset($tour['expected_amount'])) {
                        $tour['expected_amount'] = $tour['expected_amount'] ? floatval($tour['expected_amount']) : null;
                    }

                    // Ensure payment_status has a default value
                    if (!isset($tour['payment_status'])) {
                        $tour['payment_status'] = 'unpaid';
                    }

                    echo json_encode($tour);
                } else {
                    header("HTTP/1.1 404 Not Found");
                    echo json_encode(["error" => "Tour with ID $tourId not found"]);
                }
            } else {
                header("HTTP/1.1 404 Not Found");
                echo json_encode(["error" => "Tour with ID $tourId not found"]);
            }
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            error_log("Failed to update tour: " . $stmt->error);
            echo json_encode(["error" => "Failed to update tour"]);
        }
        break;
        
    case 'DELETE':
        // Delete a tour
        if (!$tourId) {
            header("HTTP/1.1 400 Bad Request");
            echo json_encode(["error" => "Tour ID is required for deletion"]);
            break;
        }
        
        $stmt = $conn->prepare("DELETE FROM tours WHERE id = ?");
        $stmt->bind_param("i", $tourId);
        
        if ($stmt->execute()) {
            // Check if the tour was found and deleted
            if ($stmt->affected_rows > 0) {
                echo json_encode(["success" => true, "message" => "Tour deleted successfully"]);
            } else {
                header("HTTP/1.1 404 Not Found");
                echo json_encode(["error" => "Tour with ID $tourId not found"]);
            }
        } else {
            header("HTTP/1.1 500 Internal Server Error");
            error_log("Failed to delete tour: " . $stmt->error);
            echo json_encode(["error" => "Failed to delete tour"]);
        }
        break;
        
    default:
        header("HTTP/1.1 405 Method Not Allowed");
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

// Close the database connection
$conn->close();
?>